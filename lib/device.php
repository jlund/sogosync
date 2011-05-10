<?php
/***********************************************
* File      :   device.php
* Project   :   Z-Push
* Descr     :   Manages all device relevant data
*               Information about the users, provisiong
*               and related sync states are saved.
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

/**----------------------------------------------------------------------------------------------------------
 * AS Device
 *
 * Holds basic data about a device, its users and the linked states
 * This data is saved and retrieved by setData() and getData()
 */
class ASDevice {
    const UNDEFINED = -1;
    const DEVICETYPE = 1;
    const USER = 2;
    const DOMAIN = 3;
    const HIERARCHYUUID = 4;
    const CONTENTUUIDS = 5;
    const FIRSTSYNCTIME = 6;
    const LASTUPDATEDTIME = 7;
    const DEPLOYEDPOLICYKEY = 8;
    const DEPLOYEDPOLICIES = 9;
    const USERAGENT = 10;
    const USERAGENTHISTORY = 11;
    const SUPPORTEDFIELDS = 12;

    private $changed = false;
    private $loadedData;
    private $devid;
    private $devicetype;
    private $user;
    private $domain;
    private $policykey = self::UNDEFINED;
    private $policies = self::UNDEFINED;
    private $hierarchyUuid = self::UNDEFINED;
    private $contentUuids;
    private $hierarchyCache;
    private $firstsynctime;
    private $lastupdatetime;
    private $useragent;
    private $useragentHistory;
    private $supportedFields;

    /**
     * AS Device constructor
     *
     * @param string        $devid
     * @param string        $devicetype
     * @param string        $getuser
     * @param string        $useragent
     *
     * @access public
     * @return boolean
     */
    public function ASDevice($devid, $devicetype, $getuser, $useragent) {
        $this->devid = $devid;
        $this->devicetype = $devicetype;
        list ($this->user, $this->domain) =  Utils::SplitDomainUser($getuser);
        $this->useragent = $useragent;
        $this->useragentHistory = array();
        $this->contentUuids = array();
        $this->firstsynctime = time();
        $this->lastupdatetime = 0;
        $this->policies = array();
        $this->changed = true;
        $this->supportedFields = array();
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ASDevice initialized for DeviceID '%s'", $devid));
    }

    /**----------------------------------------------------------------------------------------------------------
     * ASData save and initialize
     */

    /**
     * initializes the AS Device with it's data
     *
     * @access public
     * @return
     */
    public function setData($data) {
        // TODO trigger a full resync should be done if the device data is invalid ?!
        if (!is_array($data)) return;

        // is information about this device & user available?
        if (isset($data[$this->user])) {
            $this->devicetype           = $data[$this->user][self::DEVICETYPE];
            $this->domain               = $data[$this->user][self::DOMAIN];
            $this->firstsynctime        = $data[$this->user][self::FIRSTSYNCTIME];
            $this->lastupdatetime       = $data[$this->user][self::LASTUPDATEDTIME];
            $this->policykey            = $data[$this->user][self::DEPLOYEDPOLICYKEY];
            $this->policies             = $data[$this->user][self::DEPLOYEDPOLICIES];
            $this->hierarchyUuid        = $data[$this->user][self::HIERARCHYUUID];
            $this->contentUuids         = $data[$this->user][self::CONTENTUUIDS];
            $this->useragent            = $data[$this->user][self::USERAGENT];
            $this->useragentHistory     = $data[$this->user][self::USERAGENTHISTORY];
            $this->supportedFields      = $data[$this->user][self::SUPPORTEDFIELDS];

            ZLog::Write(LOGLEVEL_DEBUG, sprintf("ASDevice data loaded for user: '%s'", $this->user));
        }

        $this->loadedData = $data;
        $this->changed = false;
    }

    /**
     * Returns the current AS Device data
     * If the data was not changed, it returns false (no need to update any data)
     *
     * @access public
     * @return array/boolean
     */
    public function getData() {
        if ($this->changed) {
            $userdata = array();
            if (isset($this->devicetype))       $userdata[self::DEVICETYPE]         = $this->devicetype;
            if (isset($this->domain))           $userdata[self::DOMAIN]             = $this->domain;
            if (isset($this->firstsynctime))    $userdata[self::FIRSTSYNCTIME]      = $this->firstsynctime;
            if (isset($this->lastupdatetime))   $userdata[self::LASTUPDATEDTIME]    = time();
            if (isset($this->policykey))        $userdata[self::DEPLOYEDPOLICYKEY]  = $this->policykey;
            if (isset($this->policies))         $userdata[self::DEPLOYEDPOLICIES]   = $this->policies;
            if (isset($this->hierarchyUuid))    $userdata[self::HIERARCHYUUID]      = $this->hierarchyUuid;
            if (isset($this->contentUuids))     $userdata[self::CONTENTUUIDS]       = $this->contentUuids;
            if (isset($this->useragent))        $userdata[self::USERAGENT]          = $this->useragent;
            if (isset($this->useragentHistory)) $userdata[self::USERAGENTHISTORY]   = $this->useragentHistory;
            if (isset($this->supportedFields))  $userdata[self::SUPPORTEDFIELDS]    = $this->supportedFields;

            if (!isset($this->loadedData))
                $this->loadedData = array();

            $this->loadedData[$this->user] = $userdata;
            return $this->loadedData;
        }
        else
            return false;
    }

    /**
     * Returns the timestamp of the first synchronization
     *
     * @access public
     * @return long
     */
    public function getFirstSyncTime() {
        return $this->firstsynctime;
    }

    /**
     * Returns the user of this device
     *
     * @access public
     * @return string
     */
    public function getDeviceUser() {
        return $this->user;
    }

    /**
     * Returns the timestamp of the last device information update
     *
     * @access public
     * @return long
     */
    public function getLastUpdateTime() {
        return $this->lastupdatetime;
    }

    /**
     * Sets the useragent of the current request
     * If this value is alreay available, no update is done
     *
     * @param string    $useragent
     *
     * @access public
     * @return boolean
     */
    public function setUserAgent($useragent) {
        if ($useragent == $this->useragent)
            return true;

        // save the old user agent, if available
        if ($this->useragent != "") {
            // [] = changedate, previous user agent
            $this->useragentHistory[] = array(time(), $this->useragent);
        }
        $this->useragent = $useragent;
        $this->changed = true;
        return true;
    }

   /**
     * Returns the deployed policy key
     * if none is deployed, it returns 0
     *
     * @access public
     * @return int
     */
    public function getPolicyKey() {
        if (isset($this->policykey))
            return $this->policykey;
        else
            return 0;
    }

   /**
     * Sets the deployed policy key
     *
     * @access public
     * @return int
     */
    public function setPolicyKey($policykey) {
        $this->policykey = $policykey;
        $this->changed = true;
    }


    /**----------------------------------------------------------------------------------------------------------
     * HierarchyCache operations
     */

    /**
     * Sets the HierarchyCache
     * The hierarchydata, can be:
     *  - false     a new HierarchyCache is initialized
     *  - array()   new HierarchyCache is initialized and data from GetHierarchy is loaded
     *  - string    previousely serialized data is loaded
     *
     * @param string    $hierarchydata      (opt)
     *
     * @access public
     * @return boolean
     */
    public function setHierarchyCache($hierarchydata = false) {
        if (!is_array($hierarchydata) && $hierarchydata !== false) {
            $this->hierarchyCache = unserialize($hierarchydata);
            $this->hierarchyCache->copyOldState();
        }
        else
            $this->hierarchyCache = new ChangesMemoryWrapper();

        if (is_array($hierarchydata))
            return $this->hierarchyCache->importFolders($hierarchydata);
        return true;
    }

    /**
     * Returns serialized data of the HierarchyCache
     *
     * @access public
     * @return string
     */
    public function getHierarchyCacheData() {
        if (isset($this->hierarchyCache))
            return serialize($this->hierarchyCache);

        ZLog::Write(LOGLEVEL_WARN, "ASDevice->getHierarchyCacheData() has no data! HierarchyCache probably never initialized.");
        return false;
    }

   /**
     * Returns the HierarchyCache Object
     *
     * @access public
     * @return object   HierarchyCache
     */
    public function getHierarchyCache() {
        if (!isset($this->hierarchyCache)) {
            ZLog::Write(LOGLEVEL_WARN, "The HierarchyCache should have been initialized by now. Getting empty cache").
            // TODO this should also trigger a full hierarchy resync???
            $this->setHierarchyCache();
        }

        ZLog::Write(LOGLEVEL_DEBUG, "ASDevice->getHierarchyCache(): ". $this->hierarchyCache->getStat());
        return $this->hierarchyCache;
    }

   /**
     * Returns a linked UUID for a folder id
     *
     * @param string        $folderid       (opt) if not set, Hierarchy UUID is returned
     *
     * @access public
     * @return string
     */
    public function getUUID($folderid = false) {
        if ($folderid === false)
            return ($this->hierarchyUuid !== self::UNDEFINED)?$this->hierarchyUuid : false;
        else if (isset($this->contentUuids) && isset($this->contentUuids[$folderid]))
            return $this->contentUuids[$folderid];
        return false;
    }

   /**
     * Link a UUID to a folder id
     * If a boolean false UUID is sent, the relation is removed
     *
     * @param string        $uuid
     * @param string        $folderid       (opt) if not set Hierarchy UUID is linked
     *
     * @access public
     * @return string
     */
    public function setUUID($uuid, $folderid = false) {
        if ($folderid === false)
            $this->hierarchyUuid = $uuid;
        else {
            if ($uuid)
                $this->contentUuids[$folderid] = $uuid;
            else
                unset($this->contentUuids[$folderid]);
        }

        $this->changed = true;
    }


    /**
     * Gets the supported fields transmitted previousely by the device
     * for a certain folder
     *
     * @param string    $folderid
     *
     * @access public
     * @return array/boolean        false means no supportedFields are available
     */
    public function getSupportedFields($folderid) {
        if (isset($this->supportedFields) && isset($this->supportedFields[$folderid]))
            return $this->supportedFields[$folderid];
        return false;
    }

    /**
     * Sets the set of supported fields transmitted by the device for a certain folder
     *
     * @param string    $folderid
     * @param array     $fieldlist          supported fields
     *
     * @access public
     * @return boolean
     */
    public function setSupportedFields($folderid, $fieldlist) {
        $this->supportedFields[$folderid] = $fieldlist;
        return true;
    }
}


/**----------------------------------------------------------------------------------------------------------
 * DeviceManager
 *
 * Manages all operations for a device
 */
class DeviceManager {
    const FIXEDHIERARCHYCOUNTER = 99999;

    private $device;
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
        $this->devid = Request::getDeviceID();

        // only continue if deviceid is set
        if ($this->devid) {
            $this->device = new ASDevice($this->devid, Request::getDeviceType(), Request::getGETUser(), Request::getUserAgent());

            try {
                $data = unserialize($this->statemachine->GetState($this->devid, IStateMachine::DEVICEDATA));
                $this->device->setData($data);
            }
            catch (StateNotFoundException $snfex) {}
        }
        else
            throw new FatalNotImplementedException("Can not proceed without a device id.");

        $this->hierarchyOperation = ZPush::HierarchyCommand(Request::getCommand());
    }


    /**----------------------------------------------------------------------------------------------------------
     * Device Stuff
     */

    /**
     * Announces amount of transmitted data to the DeviceManager
     *
     * @param int           $datacounter
     *
     * @access public
     * @return boolean
     */
    public function sentData($datacounter) {
        // TODO: save this somewhere
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
        // TODO: save this somewhere
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

        if (Request::isUserAuthenticated()) {
            try {
                // check if this is the first time the device data is saved. If so, link the user to the device id
                if ($this->device->getLastUpdateTime() == 0) {
                    ZLog::Write(LOGLEVEL_INFO, sprintf("Linking device ID '%s' to user '%s'", $this->devid, $this->device->getDeviceUser()));
                    $this->statemachine->LinkUserDevice($this->device->getDeviceUser(), $this->devid);
                }

                $data = $this->device->getData();
                if ($data) {
                    ZLog::Write(LOGLEVEL_DEBUG, "DeviceManager->save(): DeviceData changed and to be saved!");
                    $this->statemachine->SetState(serialize($data), $this->devid, IStateMachine::DEVICEDATA);
                }
                else
                    ZLog::Write(LOGLEVEL_DEBUG, "DeviceManager->save(): NO data/updates to be saved!");

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
     *
     * @access public
     * @return boolean
     */
    public function ProvisioningRequired($policykey) {
        return false;
        // TODO implement PROVISIONING command before commenting this in!
        return ($policykey == 0 || $policykey != $this->device->getPolicyKey());
    }

    /**
     * Generates a new Policykey
     *
     * @access public
     * @return int
     */
    public function GeneratePolicyKey() {
        return mt_rand(1000000000, 9999999999);
    }

    /**
     * Attributes a provisioned policykey to a device
     *
     * @param string        $policykey
     *
     * @access public
     * @return boolean      status
     */
    public function SetPolicyKey($policykey) {
        return $this->device->setPolicyKey($policykey);
    }


    // TODO Refactor/remove! <<<END
    function getDeviceRWStatus($user, $pass, $devid) {
        return true;
    }
    function setDeviceRWStatus($user, $pass, $devid, $status) {
        return true;
    }
    //END

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
     * @return array        array keys: "lifetime", "collections"
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
            }
        }
        catch (StateNotFoundException $ex) {}

        return array($collections, $lifetime);
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
        return $this->statemachine->SetState(serialize(array("lifetime" => $lifetime, "collections" => $collections)), $this->devid, IStateMachine::PINGDATA, $this->device->getFirstSyncTime());
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
     * In some cases Exceptions can be tolerated
     *
     * @param Exception    $ex
     *
     * @access public
     * @return boolean
     */
    public function TolerateException(Exception $ex) {
        ZLog::Write(LOGLEVEL_DEBUG, "DeviceManager->TolerateException()");
        // Android devices send erroneous deviceid which can cause exceptions during the first sync
        // As the state1 is empty by default, we can tolerate this exception
        if ($ex instanceof StateNotFoundException)
            if ($this->oldStateCounter == 1) {
                ZLog::Write(LOGLEVEL_DEBUG,"Exception tolerated!!!");
                return true;
            }
        return false;
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
     * @throws StateNotFoundException
     */
    function GetFolderIdFromCacheByClass($class) {
        // look at the default foldertype for this class
        $type = ZPush::getDefaultFolderTypeFromFolderClass($class);

        // load the hierarchycache, we will need it
        try {
            // as there is no hierarchy uuid from the associated state, we have to read it from the device data
            $this->uuid = $this->device->getUUID();
            $this->oldStateCounter = self::FIXEDHIERARCHYCOUNTER;

            ZLog::Write(LOGLEVEL_DEBUG, "DeviceManager->getFolderIdFromCacheByClass() is about to load saved HierarchyCache with UUID:". $this->uuid);

            // force loading the saved HierarchyCache
            $this->loadHierarchyCache(true);
        }
        catch (Exception $ex) {
            throw new StateNotFoundException($ex->getMessage());
        }

        $folderid = $device->getHierarchyCache->getFolderIdByType($type);
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("DeviceManager->getFolderIdFromCacheByClass('%s'): '%s' => '%s'", $class, $type, $folderid));
        return $folderid;
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
        if (($this->uuid != $this->device->getUUID() || $forceSaving) )
            $this->linkState();

        // If folders were removed from the device, let's delete the old states
        foreach ($this->device->getHierarchyCache()->getDeletedFolders() as $delfolder)
            $this->unLinkState($delfolder->serverid);

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
        $savedUuid = $this->device->getUUID($folderid);
        // delete 'old' states!
        if ($savedUuid != $this->uuid) {
            if ($savedUuid) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("DeviceManager->linkState('%s'): saved state '%s' does not match current state '%s'. Old state files will be deleted.", (($folderid === false)?'HierarchyCache':$folderid), $savedUuid, $this->uuid));
                $this->statemachine->CleanStates($this->devid, $savedUuid . (($folderid === false)?'-hc':''), self::FIXEDHIERARCHYCOUNTER *2);
            }
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("DeviceManager->linkState('%s'): linked to uuid '%s'.", (($folderid === false)?'HierarchyCache':$folderid), $this->uuid));
            return $this->device->setUUID($this->uuid, $folderid);
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
        $savedUuid = $this->device->getUUID($folderid);
        if ($savedUuid) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("DeviceManager->unLinkState('%s'): saved state '%s' is obsolete as folder was deleted on device. Old state files will be deleted.", $folderid, $savedUuid));
            $this->statemachine->CleanStates($this->devid, $savedUuid, self::FIXEDHIERARCHYCOUNTER *2);
        }
        // delete this id from the uuid cache
        return $this->device->setUUID(false, $folderid);
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