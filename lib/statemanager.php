<?php
/***********************************************
* File      :   statemanager.php
* Project   :   Z-Push
* Descr     :   The StateManager uses a IStateMachine
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
* Created   :   26.12.2011
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

class StateManager {
    const FIXEDHIERARCHYCOUNTER = 99999;

    // backend storage types
    const BACKENDSTORAGE_PERMANENT = 1;
    const BACKENDSTORAGE_STATE = 2;

    private $statemachine;
    private $device;
    private $hierarchyOperation = false;

    private $foldertype;
    private $uuid;
    private $oldStateCounter;
    private $newStateCounter;


    /**
     * Constructor
     *
     * @access public
     */
    public function StateManager() {
        $this->statemachine = ZPush::GetStateMachine();
        $this->hierarchyOperation = ZPush::HierarchyCommand(Request::GetCommandCode());
    }

    /**
     * Sets an ASDevice for the Statemanager to work with
     *
     * @param ASDevice  $device
     *
     * @access public
     * @return boolean
     */
    public function SetDevice(&$device) {
        $this->device = $device;
        return true;
    }

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
            $this->uuid = $this->getNewUuid();
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
                    throw new StateInvalidException("StateManager->GetNewSyncKey() for a different UUID requested");
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
        $policykey = $this->device->GetPolicyKey();

        try {
            $data = $this->statemachine->GetState($this->device->GetDeviceId(), IStateMachine::PINGDATA, false, $this->device->GetFirstSyncTime());
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
        // TODO: PINGdata should be un/serialized in the state machine

        // if a HierarchySync is required something major happened
        // we should remove this current ping state because it's potentially obsolete
        if (ZPush::GetDeviceManager()->IsHierarchySyncRequired()) {
            ZPush::GetStateMachine()->CleanStates($this->device->GetDeviceId(), IStateMachine::PINGDATA, false, 99999999999);
            return false;
        }
        else
            return $this->statemachine->SetState(serialize(array("lifetime" => $lifetime, "collections" => $collections, "policykey" => $this->device->GetPolicyKey())),
                                                 $this->device->GetDeviceId(), IStateMachine::PINGDATA, false, $this->device->GetFirstSyncTime());
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

        // the state machine will discard any sync states before this one, as they are no longer required
        return $this->statemachine->GetState($this->device->GetDeviceId(), IStateMachine::DEFTYPE, $this->uuid, $this->oldStateCounter);
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

        // announce this uuid to the device, while old uuid/states should be deleted
        self::LinkState($this->device, $this->uuid, $folderid);

        return $this->statemachine->SetState($syncstate, $this->device->GetDeviceId(), IStateMachine::DEFTYPE, $this->uuid, $this->newStateCounter);
    }

    /**
     * Gets the failsave sync state for the current synckey
     *
     * @access public
     * @return array/boolean    false if not available
     */
    public function GetSyncFailState() {
        if (!$this->uuid)
            return false;

        try {
            return unserialize($this->statemachine->GetState($this->device->GetDeviceId(), IStateMachine::FAILSAVE, $this->uuid, $this->oldStateCounter));
        }
        catch (StateNotFoundException $snfex) {
            return false;
        }
    }

    /**
     * Writes the failsave sync state for the current (old) synckey
     *
     * @param mixed     $syncstate
     *
     * @access public
     * @return boolean
     */
    public function SetSyncFailState($syncstate) {
        if ($this->oldStateCounter == 0)
            return false;

        return $this->statemachine->SetState(serialize($syncstate), $this->device->GetDeviceId(), IStateMachine::FAILSAVE, $this->uuid, $this->oldStateCounter);
    }

    /**
     * Gets the backendstorage data
     *
     * @param int   $type       permanent or state related storage
     *
     * @access public
     * @return mixed
     * @throws StateNotYetAvailableException, StateNotFoundException
     */
    public function GetBackendStorage($type = self::BACKENDSTORAGE_PERMANENT) {
        if ($type == self::BACKENDSTORAGE_STATE) {
            if (!$this->uuid)
                throw new StateNotYetAvailableException();

            return unserialize($this->statemachine->GetState($this->device->GetDeviceId(), IStateMachine::BACKENDSTORAGE, $this->uuid, $this->oldStateCounter));
        }
        else {
            return unserialize($this->statemachine->GetState($this->device->GetDeviceId(), IStateMachine::BACKENDSTORAGE, false, $this->device->GetFirstSyncTime()));
        }
    }

   /**
     * Writes the backendstorage data
     *
     * @param mixed $data
     * @param int   $type       permanent or state related storage
     *
     * @access public
     * @return int              amount of bytes saved
     * @throws StateNotYetAvailableException, StateNotFoundException
     */
    public function SetBackendStorage($data, $type = self::BACKENDSTORAGE_PERMANENT) {
        if ($type == self::BACKENDSTORAGE_STATE) {
        if (!$this->uuid)
            throw new StateNotYetAvailableException();

            return $this->statemachine->SetState(serialize($data), $this->device->GetDeviceId(), IStateMachine::BACKENDSTORAGE, $this->uuid, $this->newStateCounter);
        }
        else {
            return $this->statemachine->SetState(serialize($data), $this->device->GetDeviceId(), IStateMachine::BACKENDSTORAGE, false, $this->device->GetFirstSyncTime());
        }
    }

    /**
     * Returns the current UUID used by the state
     *
     * @access public
     * @return string
     */
    public function GetUUID() {
        return $this->uuid;
    }

    /**
     * Returns the current (last) counter of the current UUID used by the state
     *
     * @access public
     * @return int
     */
    public function GetOldStateCounter() {
        return $this->oldStateCounter;
    }

    /**
     * Initializes the HierarchyCache for legacy syncs
     * this is for AS 1.0 compatibility:
     * save folder information synched with GetHierarchy()
     * handled by StateManager
     *
     * @param string    $folders            Array with folder information
     *
     * @access public
     * @return boolean
     */
    public function InitializeFolderCache($folders) {
        if (!is_array($folders))
            return false;

        if (!isset($this->device))
            throw new FatalException("ASDevice not initialized");

        // redeclare this operation as hierarchyOperation
        $this->hierarchyOperation = true;

        // as there is no hierarchy uuid, we have to create one
        $this->uuid = $this->getNewUuid();
        $this->newStateCounter = self::FIXEDHIERARCHYCOUNTER;

        // initialize legacy HierarchCache
        $this->device->SetHierarchyCache($folders);

        // force saving the hierarchy cache!
        return $this->saveHierarchyCache(true);
    }


    /**----------------------------------------------------------------------------------------------------------
     * static StateManager methods
     */

    /**
     * Links a folderid to the a UUID
     * Old states are removed if an folderid is linked to a new UUID
     * assisting the StateMachine to get rid of old data.
     *
     * @param ASDevice  $device
     * @param string    $uuid               the uuid to link to
     * @param string    $folderid           (opt) if not set, hierarchy state is linked
     *
     * @access public
     * @return boolean
     */
    static public function LinkState(&$device, $newUuid, $folderid = false) {
        $savedUuid = $device->GetFolderUUID($folderid);
        // delete 'old' states!
        if ($savedUuid != $newUuid) {
            // remove states but no need to notify device
            self::UnLinkState($device, $folderid, false);

            ZLog::Write(LOGLEVEL_DEBUG, sprintf("StateManager::linkState(#ASDevice, '%s','%s'): linked to uuid '%s'.", $newUuid, (($folderid === false)?'HierarchyCache':$folderid), $newUuid));
            return $device->SetFolderUUID($newUuid, $folderid);
        }
        return true;
    }

    /**
     * UnLinks all states from a folder id
     * Old states are removed assisting the StateMachine to get rid of old data.
     * The UUID is then removed from the device
     *
     * @param ASDevice  $device
     * @param string    $folderid
     * @param boolean   $removeFromDevice       indicates if the device should be
     *                                          notified that the state was removed
     *
     * @access public
     * @return boolean
     */
    static public function UnLinkState(&$device, $folderid, $removeFromDevice = true) {
        $savedUuid = $device->GetFolderUUID($folderid);
        if ($savedUuid) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("StateManager::UnLinkState('%s'): saved state '%s' will be deleted.", $folderid, $savedUuid));
            ZPush::GetStateMachine()->CleanStates($device->GetDeviceId(), IStateMachine::DEFTYPE, $savedUuid, self::FIXEDHIERARCHYCOUNTER *2);
            ZPush::GetStateMachine()->CleanStates($device->GetDeviceId(), IStateMachine::FAILSAVE, $savedUuid, self::FIXEDHIERARCHYCOUNTER *2);
            ZPush::GetStateMachine()->CleanStates($device->GetDeviceId(), IStateMachine::BACKENDSTORAGE, $savedUuid, self::FIXEDHIERARCHYCOUNTER *2);

            if ($folderid === false && $savedUuid !== false)
                ZPush::GetStateMachine()->CleanStates($device->GetDeviceId(), IStateMachine::HIERARCHY, $savedUuid, self::FIXEDHIERARCHYCOUNTER *2);
        }
        // delete this id from the uuid cache
        if ($removeFromDevice)
            return $device->SetFolderUUID(false, $folderid);
        else
            return true;
    }


    /**----------------------------------------------------------------------------------------------------------
     * private StateManager methods
     */

    /**
     * Loads the HierarchyCacheState and initializes the HierarchyChache
     * if this is an hierarchy operation
     *
     * @access private
     * @return boolean
     * @throws StateNotFoundException
     */
    private function loadHierarchyCache() {
        if (!$this->hierarchyOperation)
            return false;

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("StateManager->loadHierarchyCache(): '%s-%s-%s-%d'", $this->device->GetDeviceId(), $this->uuid, IStateMachine::HIERARCHY, $this->oldStateCounter));
        $hierarchydata = $this->statemachine->GetState($this->device->GetDeviceId(), IStateMachine::HIERARCHY, $this->uuid , $this->oldStateCounter);
        $this->device->SetHierarchyCache($hierarchydata);
        return true;
    }

    /**
     * Saves the HierarchyCacheState of the HierarchyChache
     * if this is an hierarchy operation
     *
     * @param boolean   $forceLoad      indicates if the cache should be saved also if not a hierary operation
     *
     * @access private
     * @return boolean
     * @throws StateInvalidException
     */
    private function saveHierarchyCache($forceSaving = false) {
        if (!$this->hierarchyOperation && !$forceSaving)
            return false;

        // link the hierarchy cache again, if the UUID does not match the UUID saved in the devicedata
        if (($this->uuid != $this->device->GetFolderUUID() || $forceSaving) )
            self::LinkState($this->device, $this->uuid);

        // check all folders and deleted folders to update data of ASDevice and delete old states
        $hc = $this->device->getHierarchyCache();
        foreach ($hc->GetDeletedFolders() as $delfolder)
            self::UnLinkState($this->device, $delfolder->serverid);

        foreach ($hc->ExportFolders() as $folder)
            $this->device->SetFolderType($folder->serverid, $folder->type);

        $hierarchydata = $this->device->GetHierarchyCacheData();
        return $this->statemachine->SetState($hierarchydata, $this->device->GetDeviceId(), IStateMachine::HIERARCHY, $this->uuid, $this->newStateCounter);
    }

    /**
     * Generates a new UUID
     *
     * @access private
     * @return string
     */
    private function getNewUuid() {
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