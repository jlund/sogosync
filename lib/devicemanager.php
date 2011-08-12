<?php
/***********************************************
* File      :   device.php
* Project   :   Z-Push
* Descr     :   Manages device relevant data, provisiong
*               and manages sync states.
*               The DeviceManager uses a IStateMachine
*               implementation to save data.
*               SyncKey's are of the form {UUID}N, in
*               which UUID is allocated during the
*               first sync, and N is incremented
*               for each request to 'GetNewSyncKey()'.
*               A sync state is simple an opaque
*               string value that can differ
*               for each backend used - normally
*               a list of items as the backend has
*               sent them to the PIM. The backend
*               can then use this backend
*               information to compute the increments
*               with current data.
*               See FileStateMachine and IStateMachine
*               for additional information.
*
* Created   :   11.04.2011
*
* Copyright 2007 - 2011 Zarafa Deutschland GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation with the following additional
* term according to sec. 7:
*
* According to sec. 7 of the GNU Affero General Public License, version 3,
* the terms of the AGPL are supplemented with the following terms:
*
* "Zarafa" is a registered trademark of Zarafa B.V.
* "Z-Push" is a registered trademark of Zarafa Deutschland GmbH
* The licensing of the Program under the AGPL does not imply a trademark license.
* Therefore any rights, title and interest in our trademarks remain entirely with us.
*
* However, if you propagate an unmodified version of the Program you are
* allowed to use the term "Z-Push" to indicate that you distribute the Program.
* Furthermore you may use our trademarks where it is necessary to indicate
* the intended purpose of a product or service provided you use it in accordance
* with honest practices in industrial or commercial matters.
* If you want to propagate modified versions of the Program under the name "Z-Push",
* you may only do so if you have a written permission by Zarafa Deutschland GmbH
* (to acquire a permission please contact Zarafa at trademark@zarafa.com).
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* Consult LICENSE file for details
************************************************/


class DeviceManager {
    const FIXEDHIERARCHYCOUNTER = 99999;

    private $device;
    private $deviceHash;
    private $statemachine;
    private $hierarchyOperation = false;
    private $exceptions;
    private $incomingData = 0;
    private $outgoingData = 0;

    // state stuff
    private $foldertype;
    private $uuid;
    private $oldStateCounter;
    private $newStateCounter;

    /**
     * Constructor
     *
     * @access public
     */
    public function DeviceManager() {
        $this->statemachine = ZPush::GetStateMachine();
        $this->exceptions = array();
        $this->deviceHash = false;
        $this->devid = Request::getDeviceID();

        // only continue if deviceid is set
        if ($this->devid) {
            $this->device = new ASDevice($this->devid, Request::getDeviceType(), Request::getGETUser(), Request::getUserAgent());
            $this->loadDeviceData();
        }
        else
            throw new FatalNotImplementedException("Can not proceed without a device id.");

        $this->hierarchyOperation = ZPush::HierarchyCommand(Request::getCommand());
    }


    /**----------------------------------------------------------------------------------------------------------
     * Device Stuff
     */

    /**
     * Loads devicedata from the StateMachine and loads it into the device
     *
     * @access public
     * @return boolean
     */
    private function loadDeviceData() {
        try {
            $deviceHash = $this->statemachine->GetStateHash($this->devid, IStateMachine::DEVICEDATA);
            if ($deviceHash != $this->deviceHash) {
                if ($this->deviceHash)
                    ZLog::Write(LOGLEVEL_DEBUG, "DeviceManager->loadDeviceData(): Device data was changed, reloading");
                $this->device->setData($this->statemachine->GetState($this->devid, IStateMachine::DEVICEDATA));
                $this->deviceHash = $deviceHash;
            }
        }
        // TODO might be necessary to catch this and process some StateExceptions later. E.g. -> sync folders -> delete all states -> sync a single folder --> this works, but a full hierarchysync should be triggered!
        catch (StateNotFoundException $snfex) {
            $this->device->SetPolicyKey(0);
        }
    }

    /**
     * Announces amount of transmitted data to the DeviceManager
     *
     * @param int           $datacounter
     *
     * @access public
     * @return boolean
     */
    public function sentData($datacounter) {
        // TODO save this somewhere
        $this->incomingData = Request::getContentLength();
        $this->outgoingData = $datacounter;
    }

    /**
     * Announces an occured exception to the DeviceManager
     *
     * @param Exception     $exception
     *
     * @access public
     * @return boolean
     */
    public function setException($exception) {
        // TODO save this somewhere
        $this->exceptions[] = $exception;
    }

    /**
     * Called at the end of the request
     * Statistics about received/sent data, Exceptions etc. is saved here
     *
     * @access public
     * @return boolean
     */
    public function save() {
        // TODO save other stuff

        // update the user agent to the device
        $this->device->setUserAgent(Request::getUserAgent());

        // data to be saved
        $data = $this->device->getData();
        if ($data && Request::isValidDeviceID()) {
            ZLog::Write(LOGLEVEL_DEBUG, "DeviceManager->save(): Device data changed");

            try {
                // check if this is the first time the device data is saved and it is authenticated. If so, link the user to the device id
                if ($this->device->getLastUpdateTime() == 0 && RequestProcessor::isUserAuthenticated()) {
                    ZLog::Write(LOGLEVEL_INFO, sprintf("Linking device ID '%s' to user '%s'", $this->devid, $this->device->getDeviceUser()));
                    $this->statemachine->LinkUserDevice($this->device->getDeviceUser(), $this->devid);
                }

                if (RequestProcessor::isUserAuthenticated() || $this->device->forceSave() ) {
                    $this->statemachine->SetState($data, $this->devid, IStateMachine::DEVICEDATA);
                    ZLog::Write(LOGLEVEL_DEBUG, "DeviceManager->save(): Device data saved");
                }
            }
            catch (StateNotFoundException $snfex) {
                ZLog::Write(LOGLEVEL_ERROR, "DeviceManager->save(): Exception: ". $snfex->getMessage());
            }
        }
        return true;
    }

    /**----------------------------------------------------------------------------------------------------------
     * Provisioning Stuff
     */

    /**
     * Checks if the sent policykey matches the latest policykey
     * saved for the device
     *
     * @param string        $policykey
     * @param boolean       $noDebug        (opt) by default, debug message is shown
     *
     * @access public
     * @return boolean
     */
    public function ProvisioningRequired($policykey, $noDebug = false) {
        $this->loadDeviceData();

        // check if a remote wipe is required
        if ($this->device->getWipeStatus() > SYNC_PROVISION_RWSTATUS_OK) {
            ZLog::Write(LOGLEVEL_INFO, sprintf("DeviceManager->ProvisioningRequired('%s'): YES, remote wipe requested", $policykey));
            return true;
        }

        $p = ($policykey == 0 || $policykey != $this->device->getPolicyKey());
        if (!$noDebug || $p)
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("DeviceManager->ProvisioningRequired('%s') saved device key '%s': %s", $policykey, $this->device->getPolicyKey(), Utils::PrintAsString($p)));
        return $p;
    }

    /**
     * Generates a new Policykey
     *
     * @access public
     * @return int
     */
    public function GenerateProvisioningPolicyKey() {
        return mt_rand(1000000000, 9999999999);
    }

    /**
     * Attributes a provisioned policykey to a device
     *
     * @param int           $policykey
     *
     * @access public
     * @return boolean      status
     */
    public function SetProvisioningPolicyKey($policykey) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("DeviceManager->SetPolicyKey('%s')", $policykey));
        return $this->device->setPolicyKey($policykey);
    }

    /**
     * Builds a Provisioning SyncObject with policies
     *
     * @access public
     * @return SyncProvisioning
     */
    public function GetProvisioningObject() {
        $p = new SyncProvisioning();
        // TODO load systemwide Policies
        $p->Load($this->device->getPolicies());
        return $p;
    }

    /**
     * Returns the status of the remote wipe policy
     *
     * @access public
     * @return int          returns the current status of the device - SYNC_PROVISION_RWSTATUS_*
     */
    public function GetProvisioningWipeStatus() {
        return $this->device->getWipeStatus();
    }

    /**
     * Updates the status of the remote wipe
     *
     * @param int           $status - SYNC_PROVISION_RWSTATUS_*
     *
     * @access public
     * @return boolean      could fail if trying to update status to a wipe status which was not requested before
     */
    public function SetProvisioningWipeStatus($status) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ASDevice->setWipeStatus() change from '%d' to '%d'",$this->device->getWipeStatus(), $status));

        if ($status > SYNC_PROVISION_RWSTATUS_OK && !($this->device->getWipeStatus() > SYNC_PROVISION_RWSTATUS_OK)) {
            ZLog::Write(LOGLEVEL_ERROR, "Not permitted to update remote wipe status to a higher value as remote wipe was not initiated!");
            return false;
        }
        $this->device->setWipeStatus($status);
        return true;
    }

    /**----------------------------------------------------------------------------------------------------------
     * State Stuff
     */

    /**
     * Gets the new sync key for a specified sync key. The new sync state must be
     * associated to this sync key when calling SetSyncState()
     *
     * @param string    $synckey
     *
     * @access public
     * @return string
     */
    function GetNewSyncKey($synckey) {
        if(!isset($synckey) || $synckey == "0") {
            $this->uuid = $this->getUuid();
            $this->newStateCounter = 1;
            return "{" . $this->uuid . "}" . $this->newStateCounter;
        }
        else {
            if(preg_match('/^{([a-fA-F0-9-]+)\}([0-9]+)$/', $synckey, $matches)) {
                $n = $matches[2];
                $n++;
                if (isset($this->uuid) && $this->uuid == $matches[1])
                    $this->newStateCounter = $n;
                else
                    throw new StateInvalidException("DeviceManger->GetNewSyncKey() for a different UUID requested");
                return "{" . $matches[1] . "}" . $n;
            }
            else
                return false;
        }
    }

    /**
     * Returns the pingstate for a device
     *
     * @access public
     * @return array        array keys: "lifetime", "collections", "policykey"
     */
    public function GetPingState() {
        $collections = array();
        $lifetime = 60;

        try {
            $data = $this->statemachine->GetState($this->devid, IStateMachine::PINGDATA, $this->device->getFirstSyncTime());
            if ($data !== false) {
                $ping = unserialize($data);
                $lifetime = $ping["lifetime"];
                $collections = $ping["collections"];
                $policykey = $ping["policykey"];
            }
        }
        catch (StateNotFoundException $ex) {}

        return array($collections, $lifetime, $policykey);
    }

    /**
     * Saves the pingstate data for a device
     *
     * @param array     $collections        Information about the ping'ed folders
     * @param int       $lifetime           Lifetime of the ping transmitted by the device
     *
     * @access public
     * @return boolean
     */
    public function SetPingState($collections, $lifetime) {
        return $this->statemachine->SetState(serialize(array("lifetime" => $lifetime, "collections" => $collections, "policykey" => $this->device->getPolicyKey())),
                                             $this->devid, IStateMachine::PINGDATA, $this->device->getFirstSyncTime());
    }

    /**
     * Gets the state for a specified synckey (uuid + counter)
     *
     * @param string    $synckey
     *
     * @access public
     * @return string
     * @throws StateInvalidException, StateNotFoundException
     */
    public function GetSyncState($synckey) {
        // No sync state for sync key '0'
        if($synckey == "0") {
            $this->oldStateCounter = 0;
            return "";
        }

        // Check if synckey is allowed --> throws exception
        $this->parseStateKey($synckey);

        // make sure the hierarchy cache is in place
        if ($this->hierarchyOperation)
            $this->loadHierarchyCache();

        // The state machine will discard any sync states before this one, as they are no longer required
        return $this->statemachine->GetState($this->devid, $this->uuid, $this->oldStateCounter);
    }

    /**
     * Writes the sync state to a new synckey
     *
     * @param string    $synckey
     * @param string    $syncstate
     * @param string    $folderid       (opt) the synckey is associated with the folder - should always be set when performing CONTENT operations
     *
     * @access public
     * @return boolean
     * @throws StateInvalidException
     */
    public function SetSyncState($synckey, $syncstate, $folderid = false) {
        $internalkey = $this->buildStateKey($this->uuid, $this->newStateCounter);
        if ($this->oldStateCounter != 0 && $synckey != $internalkey)
            throw new StateInvalidException(sprintf("Unexpected synckey value oldcounter: '%s' synckey: '%s' internal key: '%s'", $this->oldStateCounter, $synckey, $internalkey));

        // make sure the hierarchy cache is also saved
        if ($this->hierarchyOperation)
            $this->saveHierarchyCache();

        // announce this uuid to the device, so old uuid/states could be deleted
        $this->linkState($folderid);

        return $this->statemachine->SetState($syncstate, $this->devid, $this->uuid, $this->newStateCounter);
    }

    /**
     * Returns a wrapped Importer & Exporter to use the
     * HierarchyChache
     *
     * @see ChangesMemoryWrapper
     * @access public
     * @return object           HierarchyCache
     */
    public function GetHierarchyChangesWrapper() {
        return $this->device->getHierarchyCache();
    }

    /**
     * Initializes the HierarchyCache for legacy syncs
     * this is for AS 1.0 compatibility:
     * save folder information synched with GetHierarchy()
     *
     * @param string    $folders            Array with folder information
     *
     * @access public
     * @return boolean
     */
    public function InitializeFolderCache($folders) {
        if (!is_array($folders))
            return false;

        // redeclare this operation as hierarchyOperation
        $this->hierarchyOperation = true;

        // as there is no hierarchy uuid, we have to create one
        $this->uuid = $this->getUuid();
        $this->newStateCounter = self::FIXEDHIERARCHYCOUNTER;

        // initialize legacy HierarchCache
        $this->device->setHierarchyCache($folders);

        // force saving the hierarchy cache!
        return $this->saveHierarchyCache(true);
    }

    /**
     * Returns a FolderID from the HierarchyCache
     * this is for AS 1.0 compatibility:
     * this information is saved on executing GetHierarchy()
     *
     * @param string    $class              The class requested
     * @access public
     * @return string
     * @throws NoHierarchyCacheAvailableException
     */
    function GetFolderIdFromCacheByClass($class) {
        // look at the default foldertype for this class
        $type = ZPush::getDefaultFolderTypeFromFolderClass($class);

        // TODO this should be refactored as the foldertypes are saved by default in the device data now - loading the hierarchycache should not be necessary
        // load the hierarchycache, we will need it
        try {
            // as there is no hierarchy uuid from the associated state, we have to read it from the device data
            $this->uuid = $this->device->getFolderUUID();
            $this->oldStateCounter = self::FIXEDHIERARCHYCOUNTER;

            ZLog::Write(LOGLEVEL_DEBUG, "DeviceManager->getFolderIdFromCacheByClass() is about to load saved HierarchyCache with UUID:". $this->uuid);

            // force loading the saved HierarchyCache
            $this->loadHierarchyCache(true);
        }
        catch (Exception $ex) {
            throw new NoHierarchyCacheAvailableException($ex->getMessage());
        }

        $folderid = $device->getHierarchyCache->getFolderIdByType($type);
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("DeviceManager->getFolderIdFromCacheByClass('%s'): '%s' => '%s'", $class, $type, $folderid));
        return $folderid;
    }

    /**
     * Returns a FolderClass for a FolderID which is known to the mobile
     *
     * @param string    $folderid
     *
     * @access public
     * @return int
     * @throws NoHierarchyCacheAvailableException, NotImplementedException
     */
    function GetFolderClassFromCacheByID($folderid) {
        //TODO check if the parent folder exists and is also beeing synchronized
        $typeFromChange = $this->device->GetFolderType($folderid);
        if ($typeFromChange === false)
            throw new NoHierarchyCacheAvailableException(sprintf("Folderid '%s' is not fully synchronized on the device", $folderid));

        $class = ZPush::GetFolderClassFromFolderType($typeFromChange);
        if ($typeFromChange === false)
            throw new NotImplementedException(sprintf("Folderid '%s' is saved to be of type '%d' but this type is not implemented", $folderid, $typeFromChange));

        return $class;
    }

    /**
     * Amount of items to me synchronized
     * Currently called when the device does not announce this value.
     *
     * @access public
     * @return int
     */
    public function GetWindowSize() {
        // TODO implement volatile device state for loop detection
        return 100;
    }

    /**
     * Sets the supported fields transmitted by the device for a certain folder
     *
     * @param string    $folderid
     * @param array     $fieldlist          supported fields
     *
     * @access public
     * @return boolean
     */
    public function SetSupportedFields($folderid, $fieldlist) {
        return $this->device->setSupportedFields($folderid, $fieldlist);
    }

    /**
     * Gets the supported fields transmitted previousely by the device
     * for a certain folder
     *
     * @param string    $folderid
     *
     * @access public
     * @return array/boolean
     */
    public function GetSupportedFields($folderid) {
        return $this->device->getSupportedFields($folderid);
    }

    /**----------------------------------------------------------------------------------------------------------
     * private DeviceManager methods
     */

    /**
     * Loads the HierarchyCacheState and initializes the HierarchyChache
     * if this is an hierarchy operation
     *
     * @access private
     * @return boolean
     * @throws StateNotFoundException
     */
    private function loadHierarchyCache($forceLoad = false) {
        if (!$this->hierarchyOperation && !$forceLoad)
            return false;

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("DeviceManager->loadHierarchyCache(): '%s' - '%s' - %d",$this->devid, $this->uuid . "-hc", $this->oldStateCounter));
        $hierarchydata = $this->statemachine->GetState($this->devid, $this->uuid . "-hc", $this->oldStateCounter);
        $this->device->setHierarchyCache($hierarchydata);
        return true;
    }

    /**
     * Saves the HierarchyCacheState of the HierarchyChache
     * if this is an hierarchy operation
     *
     * @access private
     * @return boolean
     * @throws StateInvalidException
     */
    private function saveHierarchyCache($forceSaving = false) {
        if (!$this->hierarchyOperation && !$forceSaving)
            return false;

        // link the hierarchy cache again, if the UUID does not match the UUID saved in the devicedata
        if (($this->uuid != $this->device->getFolderUUID() || $forceSaving) )
            $this->linkState();

        // check all folders and deleted folders to update data of ASDevice and delete old states
        $hc = $this->device->getHierarchyCache();
        foreach ($hc->getDeletedFolders() as $delfolder)
            $this->unLinkState($delfolder->serverid);

        foreach ($hc->exportFolders() as $folder)
            $this->device->SetFolderType($folder->serverid, $folder->type);

        $hierarchydata = $this->device->getHierarchyCacheData();
        return $this->statemachine->SetState($hierarchydata, $this->devid, $this->uuid . "-hc", $this->newStateCounter);
    }

    /**
     * Links a folderid to the current UUID
     * Old states are removed if an folderid is linked to a new UUID
     * assisting the StateMachine to get rid of old data.
     *
     * @param string    $folderid           (opt) if not set, hierarchy state is linked
     *
     * @access private
     * @return boolean
     */
    private function linkState($folderid = false) {
        $savedUuid = $this->device->getFolderUUID($folderid);
        // delete 'old' states!
        if ($savedUuid != $this->uuid) {
            if ($savedUuid) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("DeviceManager->linkState('%s'): saved state '%s' does not match current state '%s'. Old state files will be deleted.", (($folderid === false)?'HierarchyCache':$folderid), $savedUuid, $this->uuid));
                $this->statemachine->CleanStates($this->devid, $savedUuid, self::FIXEDHIERARCHYCOUNTER *2);
                if ($folderid === false)
                    $this->statemachine->CleanStates($this->devid, $savedUuid.'-hc', self::FIXEDHIERARCHYCOUNTER *2);

            }
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("DeviceManager->linkState('%s'): linked to uuid '%s'.", (($folderid === false)?'HierarchyCache':$folderid), $this->uuid));
            return $this->device->setFolderUUID($this->uuid, $folderid);
        }
        return true;
    }

    /**
     * UnLinks a folderid with the UUID
     * Old states are removed assisting the StateMachine to get rid of old data.
     * The UUID is then removed from the device
     *
     * @param string    $folderid
     *
     * @access private
     * @return boolean
     */
    private function unLinkState($folderid) {
        $savedUuid = $this->device->getFolderUUID($folderid);
        if ($savedUuid) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("DeviceManager->unLinkState('%s'): saved state '%s' is obsolete as folder was deleted on device. Old state files will be deleted.", $folderid, $savedUuid));
            $this->statemachine->CleanStates($this->devid, $savedUuid, self::FIXEDHIERARCHYCOUNTER *2);
        }
        // delete this id from the uuid cache
        return $this->device->setFolderUUID(false, $folderid);
    }

    /**
     * Generates a new UUID
     *
     * @access private
     * @return string
     */
    private function getUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
                    mt_rand( 0, 0x0fff ) | 0x4000,
                    mt_rand( 0, 0x3fff ) | 0x8000,
                    mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) );
    }

    /**
     * Parses an incoming SyncKey from the device
     *
     * @param string    $synckey
     *
     * @access private
     * @return boolean
     * @throws StateInvalidException
     */
    private function parseStateKey($synckey) {
        $matches = array();
        if(!preg_match('/^\{([0-9A-Za-z-]+)\}([0-9]+)$/', $synckey, $matches))
            throw new StateInvalidException(sprintf("SyncKey '%s' is invalid", $synckey));

        // Remember synckey UUID and ID
        $this->uuid = $matches[1];
        $this->oldStateCounter = (int)$matches[2];
        return true;
    }

    /**
     * Builds a valid SyncKey from the key and counter
     *
     * @param string    $key
     * @param int       $counter
     *
     * @access private
     * @return string
     * @throws StateInvalidException
     */
    private function buildStateKey($key, $counter) {
        if(!preg_match('/^([0-9A-Za-z-]+)$/', $key, $matches))
            throw new StateInvalidException(sprintf("SyncKey '%s' is invalid", $key));

        return "{". $key ."}". ((is_int($counter))?$counter:"");
    }
}

?>