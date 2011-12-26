<?php
/***********************************************
* File      :   device.php
* Project   :   Z-Push
* Descr     :   The ASDevice holds basic data about a device,
*               its users and the linked states
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


class ASDevice {
    const UNDEFINED = -1;
    const DEVICETYPE = 1;
    const USER = 2;
    const DOMAIN = 3;
    const HIERARCHYUUID = 4;
    const CONTENTDATA = 5;
    const FIRSTSYNCTIME = 6;
    const LASTUPDATEDTIME = 7;
    const DEPLOYEDPOLICYKEY = 8;
    const DEPLOYEDPOLICIES = 9;
    const USERAGENT = 10;
    const USERAGENTHISTORY = 11;
    const SUPPORTEDFIELDS = 12;
    const WIPESTATUS = 13;
    const WIPEREQBY = 14;
    const WIPEREQON = 15;
    const WIPEACTIONON = 16;

    const FOLDERUUID = 1;
    const FOLDERTYPE = 2;
    const FOLDERSUPPORTEDFIELDS = 3;

    private $changed = false;
    private $loadedData;
    private $devid;
    private $devicetype;
    private $user;
    private $domain;
    private $policykey = self::UNDEFINED;
    private $policies = self::UNDEFINED;
    private $hierarchyUuid = self::UNDEFINED;
    private $contentData;
    private $hierarchyCache;
    private $firstsynctime;
    private $lastupdatetime;
    private $useragent;
    private $useragentHistory;

    private $wipeStatus;
    private $wipeReqBy;
    private $wipeReqOn;
    private $wipeActionOn;

    // used to track changes in the WIPESTATUS even if user is not authenticated
    private $forceSave;

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
        $this->contentData = array();
        $this->firstsynctime = time();
        $this->lastupdatetime = 0;
        $this->policies = array();
        $this->changed = true;
        $this->wipeStatus = SYNC_PROVISION_RWSTATUS_NA;
        $this->wipeReqBy = false;
        $this->wipeReqOn = false;
        $this->wipeActionOn = false;

        $this->forceSave = false;

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ASDevice initialized for DeviceID '%s'", $devid));
    }

    /**
     * initializes the AS Device with it's data
     *
     * @access public
     * @return
     */
    public function SetData($data) {
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
            $this->contentData          = $data[$this->user][self::CONTENTDATA];
            $this->useragent            = $data[$this->user][self::USERAGENT];
            $this->useragentHistory     = $data[$this->user][self::USERAGENTHISTORY];
            $this->wipeStatus           = $data[$this->user][self::WIPESTATUS];
            $this->wipeReqBy            = $data[$this->user][self::WIPEREQBY];
            $this->wipeReqOn            = $data[$this->user][self::WIPEREQON];
            $this->wipeActionOn         = $data[$this->user][self::WIPEACTIONON];

            ZLog::Write(LOGLEVEL_DEBUG, sprintf("ASDevice data loaded for user: '%s'", $this->user));
        }

        // check if RWStatus from another user on same device may require action
        if ($data > SYNC_PROVISION_RWSTATUS_OK) {
            foreach ($data as $user=>$userdata) {
                if ($user == $this->user) continue;

                // another user has a required action on this device
                if (isset($userdata[self::WIPESTATUS]) && $userdata[self::WIPESTATUS] > SYNC_PROVISION_RWSTATUS_OK) {
                    ZLog::Write(LOGLEVEL_INFO, sprintf("User '%s' has requested a remote wipe for this device on %s. Request is still active and will be executed now!", $userdata[self::WIPEREQBY], strftime("%Y-%m-%d %H:%M", $userdata[self::WIPEREQON])));

                    // reset status to PENDING if wipe was executed before
                    $this->wipeStatus =  ($userdata[self::WIPESTATUS] & SYNC_PROVISION_RWSTATUS_WIPED)?SYNC_PROVISION_RWSTATUS_PENDING:$userdata[self::WIPESTATUS];
                    $this->wipeReqBy =  $userdata[self::WIPEREQBY];
                    $this->wipeReqOn =  $userdata[self::WIPEREQON];
                    $this->wipeActionOn = $userdata[self::WIPEACTIONON];

                    $this->changed = true;
                    break;
                }
            }
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
    public function GetData() {
        if ($this->changed) {
            $userdata = array();
            if (isset($this->devicetype))       $userdata[self::DEVICETYPE]         = $this->devicetype;
            if (isset($this->domain))           $userdata[self::DOMAIN]             = $this->domain;
            if (isset($this->firstsynctime))    $userdata[self::FIRSTSYNCTIME]      = $this->firstsynctime;
            if (isset($this->lastupdatetime))   $userdata[self::LASTUPDATEDTIME]    = time();
            if (isset($this->policykey))        $userdata[self::DEPLOYEDPOLICYKEY]  = $this->policykey;
            if (isset($this->policies))         $userdata[self::DEPLOYEDPOLICIES]   = $this->policies;
            if (isset($this->hierarchyUuid))    $userdata[self::HIERARCHYUUID]      = $this->hierarchyUuid;
            if (isset($this->contentData))      $userdata[self::CONTENTDATA]        = $this->contentData;
            if (isset($this->useragent))        $userdata[self::USERAGENT]          = $this->useragent;
            if (isset($this->useragentHistory)) $userdata[self::USERAGENTHISTORY]   = $this->useragentHistory;
            if (isset($this->wipeStatus))       $userdata[self::WIPESTATUS]         = $this->wipeStatus;
            if (isset($this->wipeReqBy))        $userdata[self::WIPEREQBY]          = $this->wipeReqBy;
            if (isset($this->wipeReqOn))        $userdata[self::WIPEREQON]          = $this->wipeReqOn;
            if (isset($this->wipeActionOn))     $userdata[self::WIPEACTIONON]       = $this->wipeActionOn;

            if (!isset($this->loadedData))
                $this->loadedData = array();

            $this->loadedData[$this->user] = $userdata;

            // check if RWStatus has to be updated for other users on same device
            if (isset($this->wipeStatus) && $this->wipeStatus > SYNC_PROVISION_RWSTATUS_OK) {
                foreach ($this->loadedData as $user=>$userdata) {
                    if ($user == $this->user) continue;
                    if (isset($this->wipeStatus))       $userdata[self::WIPESTATUS]         = $this->wipeStatus;
                    if (isset($this->wipeReqBy))        $userdata[self::WIPEREQBY]          = $this->wipeReqBy;
                    if (isset($this->wipeReqOn))        $userdata[self::WIPEREQON]          = $this->wipeReqOn;
                    if (isset($this->wipeActionOn))     $userdata[self::WIPEACTIONON]       = $this->wipeActionOn;
                    $this->loadedData[$user] = $userdata;
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("Updated remote wipe status for user '%s' on the same device", $user));
                }
            }

            return $this->loadedData;
        }
        else
            return false;
    }

   /**
     * Indicates if changed device should be saved even if user is not authenticated
     *
     * @access public
     * @return boolean
     */
    public function ForceSave() {
        return $this->forceSave;
    }

    /**
     * Returns the timestamp of the first synchronization
     *
     * @access public
     * @return long
     */
    public function GetFirstSyncTime() {
        return $this->firstsynctime;
    }

    /**
     * Returns the id of this device
     *
     * @access public
     * @return string
     */
    public function GetDeviceId() {
        return $this->devid;
    }

    /**
     * Returns the user of this device
     *
     * @access public
     * @return string
     */
    public function GetDeviceUser() {
        return $this->user;
    }

    /**
     * Returns the type of this device
     *
     * @access public
     * @return string
     */
    public function GetDeviceType() {
        return $this->devicetype;
    }

    /**
     * Returns the user agent of this device
     *
     * @access public
     * @return string
     */
    public function GetDeviceUserAgent() {
        return $this->useragent;
    }

    /**
     * Returns the user agent history of this device
     *
     * @access public
     * @return string
     */
    public function GetDeviceUserAgentHistory() {
        return $this->useragentHistory;
    }

    /**
     * Returns the timestamp of the last device information update
     *
     * @access public
     * @return long
     */
    public function GetLastUpdateTime() {
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
    public function SetUserAgent($useragent) {
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
     * Returns the current remote wipe status
     *
     * @access public
     * @return int
     */
    public function GetWipeStatus() {
        if (isset($this->wipeStatus))
            return $this->wipeStatus;
        else
            return SYNC_PROVISION_RWSTATUS_NA;
    }


   /**
     * Sets the current remote wipe status
     *
     * @param int       $status
     * @param string    $requestedBy
     * @access public
     * @return int
     */
    public function SetWipeStatus($status, $requestedBy = false) {
        // force saving the updated information if there was a transition between the wiping status
        if ($this->wipeStatus > SYNC_PROVISION_RWSTATUS_OK && $status > SYNC_PROVISION_RWSTATUS_OK)
            $this->forceSave = true;

        if ($requestedBy != false) {
            $this->wipeReqBy = $requestedBy;
            $this->wipeReqOn = time();
        }
        else {
            $this->wipeActionOn = time();
        }

        $this->wipeStatus = $status;
        $this->changed = true;

        if ($this->wipeStatus > SYNC_PROVISION_RWSTATUS_PENDING)
            ZLog::Write(LOGLEVEL_INFO, sprintf("ASDevice id '%s' was %s remote wiped on %s. Action requested by user '%s' on %s",
                                        $this->devid, ($this->wipeStatus == SYNC_PROVISION_RWSTATUS_REQUESTED ? "requested to be": "sucessfully"),
                                        strftime("%Y-%m-%d %H:%M", $this->wipeActionOn), $this->wipeReqBy, strftime("%Y-%m-%d %H:%M", $this->wipeReqOn)));
    }


   /**
     * Returns the when the remote wipe was executed
     *
     * @access public
     * @return int
     */
    public function GetWipedOn() {
        if (isset($this->wipeActionOn))
            return $this->wipeActionOn;
        else
            return false;
    }


   /**
     * Returns by whom the remote wipe was requested
     *
     * @access public
     * @return int
     */
    public function GetWipeRequestedBy() {
        if (isset($this->wipeReqBy))
            return $this->wipeReqBy;
        else
            return false;
    }


   /**
     * Returns by whom the remote wipe was requested
     *
     * @access public
     * @return int
     */
    public function GetWipeRequestedOn() {
        if (isset($this->wipeReqOn))
            return $this->wipeReqOn;
        else
            return false;
    }


   /**
     * Returns the deployed policy key
     * if none is deployed, it returns 0
     *
     * @access public
     * @return int
     */
    public function GetPolicyKey() {
        if (isset($this->policykey))
            return $this->policykey;
        else
            return 0;
    }

   /**
     * Sets the deployed policy key
     *
     * @param int       $policykey
     *
     * @access public
     * @return
     */
    public function SetPolicyKey($policykey) {
        $this->policykey = $policykey;
        $this->changed = true;
    }

   /**
     * Gets policies
     *
     * @access public
     * @return array
     */
    public function GetPolicies() {
        return $this->policies;
    }

    /**----------------------------------------------------------------------------------------------------------
     * HierarchyCache and ContentData operations
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
    public function SetHierarchyCache($hierarchydata = false) {
        if (!is_array($hierarchydata) && $hierarchydata !== false) {
            $this->hierarchyCache = unserialize($hierarchydata);
            $this->hierarchyCache->CopyOldState();
        }
        else
            $this->hierarchyCache = new ChangesMemoryWrapper();

        if (is_array($hierarchydata))
            return $this->hierarchyCache->ImportFolders($hierarchydata);
        return true;
    }

    /**
     * Returns serialized data of the HierarchyCache
     *
     * @access public
     * @return string
     */
    public function GetHierarchyCacheData() {
        if (isset($this->hierarchyCache))
            return serialize($this->hierarchyCache);

        ZLog::Write(LOGLEVEL_WARN, "ASDevice->GetHierarchyCacheData() has no data! HierarchyCache probably never initialized.");
        return false;
    }

   /**
     * Returns the HierarchyCache Object
     *
     * @access public
     * @return object   HierarchyCache
     */
    public function GetHierarchyCache() {
        if (!isset($this->hierarchyCache))
            $this->SetHierarchyCache();

        ZLog::Write(LOGLEVEL_DEBUG, "ASDevice->GetHierarchyCache(): ". $this->hierarchyCache->GetStat());
        return $this->hierarchyCache;
    }

   /**
     * Returns all known folderids
     *
     * @access public
     * @return array
     */
    public function GetAllFolderIds() {
        if (isset($this->contentData) && is_array($this->contentData))
            return array_keys($this->contentData);
        return false;
    }

   /**
     * Returns a linked UUID for a folder id
     *
     * @param string        $folderid       (opt) if not set, Hierarchy UUID is returned
     *
     * @access public
     * @return string
     */
    public function GetFolderUUID($folderid = false) {
        if ($folderid === false)
            return ($this->hierarchyUuid !== self::UNDEFINED)?$this->hierarchyUuid : false;
        else if (isset($this->contentData) && isset($this->contentData[$folderid]) && isset($this->contentData[$folderid][self::FOLDERUUID]))
            return $this->contentData[$folderid][self::FOLDERUUID];
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
     * @return
     */
    public function SetFolderUUID($uuid, $folderid = false) {
        if ($folderid === false)
            $this->hierarchyUuid = $uuid;
        else {
            if ($uuid) {
                // TODO check if the foldertype is set. This has to be available at this point, as generated during the first HierarchySync. Else full resync should be triggered.
                if (!isset($this->contentData[$folderid]) || !is_array($this->contentData[$folderid]))
                    $this->contentData[$folderid] = array();
                $this->contentData[$folderid][self::FOLDERUUID] = $uuid;
            }
            else
                $this->contentData[$folderid][self::FOLDERUUID] = false;
        }

        $this->changed = true;
    }

   /**
     * Returns a foldertype for a folder already known to the mobile
     *
     * @param string        $folderid
     *
     * @access public
     * @return int/boolean  returns false if the type is not set
     */
    public function GetFolderType($folderid) {
        if (isset($this->contentData) && isset($this->contentData[$folderid]) &&
            isset($this->contentData[$folderid][self::FOLDERTYPE]) )

            return $this->contentData[$folderid][self::FOLDERTYPE];
        return false;
    }

   /**
     * Sets the foldertype of a folder id
     *
     * @param string        $uuid
     * @param string        $folderid       (opt) if not set Hierarchy UUID is linked
     *
     * @access public
     * @return boolean      true if the type was set or updated
     */
    public function SetFolderType($folderid, $foldertype) {
        if (!isset($this->contentData[$folderid]) || !is_array($this->contentData[$folderid]))
            $this->contentData[$folderid] = array();
        if (!isset($this->contentData[$folderid][self::FOLDERTYPE]) || $this->contentData[$folderid][self::FOLDERTYPE] != $foldertype ) {
            $this->contentData[$folderid][self::FOLDERTYPE] = $foldertype;
            $this->changed = true;
            return true;
        }
        return false;
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
    public function GetSupportedFields($folderid) {
        if (isset($this->contentData) && isset($this->contentData[$folderid]) &&
            isset($this->contentData[$folderid][self::FOLDERUUID]) && $this->contentData[$folderid][self::FOLDERUUID] !== false &&
            isset($this->contentData[$folderid][self::FOLDERSUPPORTEDFIELDS]) )

            return $this->contentData[$folderid][self::FOLDERSUPPORTEDFIELDS];

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
    public function SetSupportedFields($folderid, $fieldlist) {
        if (!isset($this->contentData[$folderid]) || !is_array($this->contentData[$folderid]))
            $this->contentData[$folderid] = array();

        $this->contentData[$folderid][self::FOLDERSUPPORTEDFIELDS] = $fieldlist;
        $this->changed = true;
        return true;
    }
}

?>