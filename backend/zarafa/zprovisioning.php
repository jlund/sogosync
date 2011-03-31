<?php
/***********************************************
* File      :   zprovisioning.php
* Project   :   Z-Push
* Descr     :   Implements methods of the abstract Provisiong class
*
* Created   :   14.02.2011
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

class ZProvisioning extends Provisioning {
    protected $_session;
    protected $_defaultstore;

    /**
     * Initialize the provisioning
     *
     * @access public
     * @param object        $backend
     */
    public function initialize($backend, $devid) {
        parent::initialize($backend, $devid);
        $this->_session = $this->_backend->_getSession();
        $this->_defaultstore = $this->_backend->_getDefaultstore();
    }

    /**
     * Attributes a provisioned policykey to a device
     *
     * @param string        $policykey
     * @param string        $devid
     *
     * @access public
     * @return boolean status
     */
    public function setPolicyKey($policykey, $devid) {
        global $devtype, $useragent;
        if ($this->_defaultstore !== false) {
            //get devices properties
            $devicesprops = mapi_getprops($this->_backend->_defaultstore, array(0x6880101E, 0x6881101E, 0x6882101E, 0x6883101E, 0x68841003, 0x6885101E, 0x6886101E, 0x6887101E, 0x68881040, 0x68891040));

            if (!$policykey) {
                $policykey = $this->generatePolicyKey();
            }

            //check if devid is known
            if (isset($devicesprops[0x6881101E]) && is_array($devicesprops[0x6881101E])) {
                $ak = array_search($devid, $devicesprops[0x6881101E]);
                if ($ak !== false) {
                    //update policykey
                    $devicesprops[0x6880101E][$ak] = $policykey;
                    $devicesprops[0x6883101E][$ak] = $useragent;
                }
                else {
                    //Unknown device. Store its information.
                    $devicesprops[0x6880101E][] = $policykey;
                    $devicesprops[0x6881101E][] = $devid;
                    $devicesprops[0x6882101E][] = ($devtype) ? $devtype : "unknown";
                    $devicesprops[0x6883101E][] = $useragent;
                    $devicesprops[0x68841003][] = SYNC_PROVISION_RWSTATUS_OK;
                    $devicesprops[0x6885101E][] = "undefined"; //wipe requested (date)
                    $devicesprops[0x6886101E][] = "undefined"; //wipe requested by
                    $devicesprops[0x6887101E][] = "undefined"; //wipe executed
                    $devicesprops[0x68881040][] = time(); //first sync
                    $devicesprops[0x68891040][] = 0; //last sync
                }
            }
            else {
                //First device. Store its information.
                $devicesprops[0x6880101E][] = $policykey;
                $devicesprops[0x6881101E][] = $devid;
                $devicesprops[0x6882101E][] = ($devtype) ? $devtype : "unknown";
                $devicesprops[0x6883101E][] = $useragent;
                $devicesprops[0x68841003][] = SYNC_PROVISION_RWSTATUS_OK;
                $devicesprops[0x6885101E][] = "undefined"; //wipe requested (date)
                $devicesprops[0x6886101E][] = "undefined"; //wipe requested by
                $devicesprops[0x6887101E][] = "undefined"; //wipe executed
                $devicesprops[0x68881040][] = time(); //first sync
                $devicesprops[0x68891040][] = 0; //last sync
            }
            mapi_setprops($this->_backend->_defaultstore, $devicesprops);

            return $policykey;
        }
        else
            writeLog(LOGLEVEL_ERROR, "ERROR: user store not available for policykey update");

        return false;
    }

    /**
     * Returns the current policykey for a user & device
     *
     * @param string        $user
     * @param string        $pass
     * @param string        $devid
     *
     * @access public
     * @return string
     */
    public function getPolicyKey ($user, $pass, $devid) {
        if($this->_session === false) {
            writeLog(LOGLEVEL_WARN, "logon failed for user $user");
            return false;
        }

        //user is logged in or can login, get the policy key and device id
        if ($this->_defaultstore !== false) {
            $devicesprops = mapi_getprops($this->_backend->_defaultstore, array(0x6880101E, 0x6881101E));
            if (isset($devicesprops[0x6881101E]) && is_array($devicesprops[0x6881101E])) {
                $ak = array_search($devid, $devicesprops[0x6881101E]);
                if ($ak !== false) {
                    //return policykey
                    return $devicesprops[0x6880101E][$ak];
                }
                else {
                    //new device is. generate new policy for it.
                    return $this->setPolicyKey(0, $devid);
                }

            }
            //user's first device, generate a new key
            //and set firstsync, deviceid, devicetype and useragent
            else {
                return $this->setPolicyKey(0, $devid);
            }
        }
        //get policy key without logging in somehow
        else {
            return false;
        }
        return false;
    }

    // TODO refactor
    function getDeviceRWStatus($user, $pass, $devid) {

        if($this->_session === false) {
            writeLog(LOGLEVEL_WARN, "logon failed for user $user");
            return false;
        }

        // Get/open default store - we have to do this because otherwise it returns old values :(
        $defaultstore = $this->_backend->_getDefaultstore();

        //user is logged in or can login, get the remote wipe status
        if ($defaultstore !== false) {
            $devicesprops = mapi_getprops($defaultstore, array(0x68841003, 0x6881101E));
            if (isset($devicesprops[0x6881101E]) && is_array($devicesprops[0x6881101E])) {
                $ak = array_search($devid, $devicesprops[0x6881101E]);
                if ($ak !== false) {
                    //return remote wipe status
                    return $devicesprops[0x68841003][$ak];
                }
            }
            return SYNC_PROVISION_RWSTATUS_NA;
        }
        //TODO: get policy key without logging in somehow
        else {
            return false;
        }
        return false;
    }

    // TODO refactor
    function setDeviceRWStatus($user, $pass, $devid, $status) {
        if($this->_session === false) {
            writeLog(LOGLEVEL_WARN, "Set rw status: logon failed for user $user");
            return false;
        }

        // Get/open default store - we have to do this because otherwise it returns old values :(
        $defaultstore = $this->_backend->_openDefaultMessageStore($this->_session);

        //user is logged in or can login, get the remote wipe status
        if ($defaultstore !== false) {
            $devicesprops = mapi_getprops($defaultstore, array(0x68841003, 0x6881101E, 0x6887101E, 0x6885101E, 0x6886101E));
            if (isset($devicesprops[0x6881101E]) && is_array($devicesprops[0x6881101E])) {
                $ak = array_search($devid, $devicesprops[0x6881101E]);
                if ($ak !== false) {
                    //set new status remote wipe status
                    $devicesprops[0x68841003][$ak] = $status;
                    if ($status == SYNC_PROVISION_RWSTATUS_WIPED)
                        $devicesprops[0x6887101E][$ak] = time();

                    writeLog(LOGLEVEL_INFO, "RemoteWipe ".(($status == SYNC_PROVISION_RWSTATUS_WIPED)?'executed':'sent').": Device '". $devid ."' of '". $user ."' requested by '". $devicesprops[0x6886101E][$ak] ."' at ". strftime("%Y-%m-%d %H:%M", $devicesprops[0x6885101E][$ak]));
                    mapi_setprops($defaultstore, array(0x68841003 => $devicesprops[0x68841003], 0x6887101E =>$devicesprops[0x6887101E]));
                    return true;
                }
            }
            return true;
        }
        //TODO: get policy key without logging in somehow
        else {
            return false;
        }
        return false;
    }

    // TODO refactor
    function setLastSyncTime () {
        if ($this->_backend->_defaultstore !== false) {
            $devicesprops = mapi_getprops($this->_defaultstore,
            array(0x6881101E, 0x68891040));
            if (isset($devicesprops[0x6881101E]) && is_array($devicesprops[0x6881101E])) {
                $ak = array_search($this->_devid, $devicesprops[0x6881101E]);
                if ($ak !== false) {
                    //set new sync time
                    $devicesprops[0x68891040][$ak] = time();
                    mapi_setprops($this->_defaultstore, array(0x68891040=>$devicesprops[0x68891040]));
                }
                else {
                    // TODO: what should happen?
                    writeLog(LOGLEVEL_WARN, "setLastSyncTime: No device found.");
                }
            }
            else {
                // TODO: what should happen?
                writeLog(LOGLEVEL_WARN, "setLastSyncTime: No devices found");
            }
        }
    }


}
?>