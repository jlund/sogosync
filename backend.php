<?php
/***********************************************
* File      :   backend.php
* Project   :   Z-Push
* Descr     :   This is what C++ people
*               (and PHP5) would call an
*               abstract class. All backend
*               modules should adhere to this
*               specification. All communication
*               with this module is done via
*               the Sync* object types, the
*               backend module itself is
*               responsible for converting any
*               necessary types and formats.
*
*               If you wish to implement a new
*               backend, all you need to do is
*               to subclass the following class,
*               and place the subclassed file in
*               the backend/ directory. You can
*               then use your backend by
*               specifying it in the config.php file
*
*
* Created   :   01.10.2007
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


class Provisioning {
    protected $_backend;
    protected $_devid;

    /**
     * Constructor
     *
     * @access public
     */
    public function Provisioning() {
        // TODO: initialize logger?
        // TODO: how to do provisioning (WIPE!) withoug log in????
    }

    /**
     * Initialize the provisioning
     *
     * @param object        $backend
     * @param string        $devid          DeviceID
     *
     * @access public
     * @return
     */
    public function initialize($backend, $devid) {
        $this->_backend = $backend;
        $this->_devid = $devid;
    }

    /**
     * Checks if the sent policykey matches the latest policykey on the server
     *
     * @param string        $policykey
     * @param string        $devid
     *
     * @access public
     * @return status flag
     */
    public function CheckPolicy($policykey, $devid) {
        global $user, $auth_pw;

        $status = SYNC_PROVISION_STATUS_SUCCESS;

        //generate some devid if it is not set,
        //in order to be able to remove it later via mdm
        if (!isset($devid) || !$devid) $devid = $this->generatePolicyKey();

        $user_policykey = $this->getPolicyKey($user, $auth_pw, $devid);

        if ($user_policykey != $policykey) {
            $status = SYNC_PROVISION_STATUS_POLKEYMISM;
        }

        if (!$policykey) $policykey = $user_policykey;
        return $status;
    }

    /**
     * Generates a new Policykey
     *
     * @access public
     * @return int
     */
    public function generatePolicyKey() {
        return mt_rand(1000000000, 9999999999);
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
        return false;
    }

    // TODO Refactor!
    function getDeviceRWStatus($user, $pass, $devid) {
    }

    // TODO Refactor!
    function setDeviceRWStatus($user, $pass, $devid, $status) {
    }

    // TODO Refactor into Stats Class
    function setLastSyncTime () {
    }
}



abstract class Backend implements IBackend {
    protected $_provisioning;

    /**
     * Constructor
     *
     * @access public
     */
    public function Backend() {
        $this->_provisioning = new Provisioning();
    }

    /**
     * Authenticates the user
     *
     * @param string        $username
     * @param string        $domain
     * @param string        $password
     *
     * @access public
     * @return boolean
     */
//    abstract public function Logon($username, $domain, $password);

    /**
     * Initializes the backend
     *
     * @param string        $user
     * @param string        $devid
     * @param string        $protocolversion
     *
     * @access public
     * @return boolean
     */
//    public abstract function Setup($user, $devid, $protocolversion);

    /**
     * Logs off
     * non critical operations closing the session should be done here
     *
     * @access public
     * @return boolean
     */
//    public abstract function Logoff();

    /**
     * Searches the global address list supported by the backend
     * Can be overwitten globally by configuring a SearchBackend
     *
     * @param string        $searchquery
     * @param string        $searchrange
     *
     * @access public
     * @return array
     */
    public function getSearchResults($searchquery, $searchrange) {
        return array();
    }

    /**
     * Returns an array of SyncFolder types with the entire folder hierarchy
     * on the server (the array itself is flat, but refers to parents via the 'parent' property
     *
     * provides AS 1.0 compatibility
     *
     * @access public
     * @return array SYNC_FOLDER
     */
//    public abstract function GetHierarchy();

    /**
     * Returns the importer to process changes from the mobile
     * If no $folderid is given, hierarchy importer is expected
     *
     * @param string        $folderid (opt)
     *
     * @access public
     * @return object(ImportChanges)
     */
/*    /public function GetImporter($folderid = false) {
        return new ImportChanges($folderid);
    }
*/
    /**
     * Returns the exporter to send changes to the mobile
     * If no $folderid is given, hierarchy exporter is expected
     *
     * @param string        $folderid (opt)
     *
     * @access public
     * @return object(ExportChanges)
     */
/*    public function GetExporter($folderid = false) {
        return new ExportChanges($folderid);
    }
*/
    /**
     * Sends an e-mail
     *
     * @param string        $rfc822     raw mail submitted by the mobile
     * @param string        $forward    id of the message to be attached below $rfc822
     * @param string        $reply      id of the message to be attached below $rfc822
     * @param string        $parent     id of the folder containing $forward or $reply
     *
     * @access public
     * @return boolean
     */
//    public abstract function SendMail($rfc822, $forward = false, $reply = false, $parent = false);

    /**
     * Returns all available data of a single message
     *
     * @param string        $folderid
     * @param string        $id
     * @param string        $mimesupport flag
     *
     * @access public
     * @return object(SyncObject)
     */
//    public abstract function Fetch($folderid, $id, $mimesupport = 0);

    /**
     * Returns the waste basket
     *
     * @access public
     * @return string
     */
//    public abstract function GetWasteBasket();

    /**
     * Returns the content of the named attachment.
     *
     * @param string        $attname
     *
     * @access public
     * @return boolean
     */
//    public abstract function GetAttachmentData($attname);

    /**
     * Processes a response to a meeting request.
     * CalendarID is a reference and has to be set if a new calendar item is created
     *
     * @param string        $requestid      id of the object containing the request
     * @param string        $folderid       id of the parent folder of $requestid
     * @param string        $response
     * @param string        &$calendarid    reference of the created/updated calendar obj
     *
     * @access public
     * @return boolean
     */
//    public abstract function MeetingResponse($requestid, $folderid, $response, &$calendarid);

    /**
     * Returns true if the Backend implementation supports an alternative PING mechanism
     *
     * @access public
     * @return boolean
     */
    public function AlterPing() {
        return false;
    }

    /**
     * Requests an indication if changes happened in a folder since the syncstate
     *
     * @param string        $folderid       id of the folder
     * @param string        &$syncstate     reference of the syncstate
     *
     * @access public
     * @return boolean
     */
    public function AlterPingChanges($folderid, &$syncstate) {
        return array();
    }


    // TODO: refactor request for Provisioning into interface
    // TODO: implement general policies
    // TODO: refactor get/setDeviceStatus (last sync, remotewipe etc) -> Provisioning class/interface
    function CheckPolicy($policykey, $devid) {
        return $this->_provisioning->CheckPolicy($policykey, $devid);
    }
    function generatePolicyKey() {
        return $this->_provisioning->generatePolicyKey();
    }
    function setPolicyKey($policykey, $devid) {
        return $this->_provisioning->setPolicyKey($policykey, $devid);
    }
    function getPolicyKey ($user, $pass, $devid) {
        return $this->_provisioning->getPolicyKey ($user, $pass, $devid);
    }
    function getDeviceRWStatus($user, $pass, $devid) {
        return $this->_provisioning->getDeviceRWStatus($user, $pass, $devid);
    }
    function setDeviceRWStatus($user, $pass, $devid, $status) {
        return $this->_provisioning->setDeviceRWStatus($user, $pass, $devid, $status);
    }
    function setLastSyncTime() {
        return $this->_provisioning->setLastSyncTime();
    }



    /**
     * DEPRECATED legacy methods
     */
    public function GetHierarchyImporter() {
        return $this->GetImporter();
    }

    public function GetContentsImporter($folderid) {
        return $this->GetImporter($folderid);
    }
};

?>