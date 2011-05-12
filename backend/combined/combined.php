<?php
/***********************************************
* File      :   backend/combined/combined.php
* Project   :   Z-Push
* Descr     :   Combines several backends. Each type of message
*               (Emails, Contacts, Calendar, Tasks) can be handled by
*               a separate backend.
*
* Created   :   29.11.2010
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

//include the CombinedBackend's own config file
require_once("config.php");
require_once("importer.php");
require_once("exporter.php");

class BackendCombined extends Backend {
    public $config;
    public $backends;

    /**
     * Constructor of the combined backend
     *
     * @access public
     */
    public function BackendCombined() {
        parent::Backend();
        $this->config = BackendCombinedConfig::GetBackendCombinedConfig();

        foreach ($this->config['backends'] as $i => $b){
            $this->backends[$i] = new $b['name']($b['config']);
        }
        ZLog::Write(LOGLEVEL_INFO, sprintf("Combined %d backends loaded.", count($this->backends)));
    }

    /**
     * Authenticates the user on each backend
     *
     * @param string        $username
     * @param string        $domain
     * @param string        $password
     *
     * @access public
     * @return boolean
     */
    public function Logon($username, $domain, $password) {
        // TODO check if status exceptions have to be catched
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("Combined->Logon('%s', '%s',***))", $username, $domain));
        if(!is_array($this->backends)){
            return false;
        }
        foreach ($this->backends as $i => $b){
            $u = $username;
            $d = $domain;
            $p = $password;
            if(isset($this->config['backends'][$i]['users'])){
                if(!isset($this->config['backends'][$i]['users'][$username])){
                    unset($this->backends[$i]);
                    continue;
                }
                if(isset($this->config['backends'][$i]['users'][$username]['username']))
                    $u = $this->config['backends'][$i]['users'][$username]['username'];
                if(isset($this->config['backends'][$i]['users'][$username]['password']))
                    $p = $this->config['backends'][$i]['users'][$username]['password'];
                if(isset($this->config['backends'][$i]['users'][$username]['domain']))
                    $d = $this->config['backends'][$i]['users'][$username]['domain'];
            }
            if($this->backends[$i]->Logon($u, $d, $p) == false){
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("Combined->Logon() failed on %s ", $this->config['backends'][$i]['name']));
                return false;
            }
        }
        ZLog::Write(LOGLEVEL_INFO, "Combined->Logon() success");
        return true;
    }

    /**
     * Setup the backend to work on a specific store or checks ACLs there.
     * If only the $store is submitted, all Import/Export/Fetch/Etc operations should be
     * performed on this store (switch operations store).
     * If the ACL check is enabled, this operation should just indicate the ACL status on
     * the submitted store, without changing the store for operations.
     * For the ACL status, the currently logged on user MUST have access rights on
     *  - the entire store - admin access if no folderid is sent, or
     *  - on a specific folderid in the store (secretary/full access rights)
     *
     * The ACLcheck MUST fail if a folder of the authenticated user is checked!
     *
     * @param string        $store              target store, could contain a "domain\user" value
     * @param boolean       $checkACLonly       if set to true, Setup() should just check ACLs
     * @param string        $folderid           if set, only ACLs on this folderid are relevant
     *
     * @access public
     * @return boolean
     */
    public function Setup($store, $checkACLonly = false, $folderid = false) {
        // TODO check if status exceptions have to be catched
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("Combined->Setup('%s', '%s', '%s')", $store, Utils::PrintAsString($checkACLonly), $folderid));
        if(!is_array($this->backends)){
            return false;
        }
        foreach ($this->backends as $i => $b){
            $u = $store;
            if(isset($this->config['backends'][$i]['users']) && isset($this->config['backends'][$i]['users'][$store]['username'])){
                $u = $this->config['backends'][$i]['users'][$store]['username'];
            }
            if($this->backends[$i]->Setup($u, $checkACLonly, $folderid) == false){
                ZLog::Write(LOGLEVEL_WARN, "Combined->Setup() failed");
                return false;
            }
        }
        ZLog::Write(LOGLEVEL_INFO, "Combined->Setup() success");
        return true;
    }

    /**
     * Logs off each backend
     *
     * @access public
     * @return boolean
     */
    public function Logoff() {
        ZLog::Write(LOGLEVEL_DEBUG, "Combined->Logoff()");
        foreach ($this->backends as $i => $b){
            $this->backends[$i]->Logoff();
        }
        ZLog::Write(LOGLEVEL_DEBUG, "Combined->Logoff() success");
        return true;
    }

    /**
     * Returns an array of SyncFolder types with the entire folder hierarchy
     * from all backends combined
     *
     * provides AS 1.0 compatibility
     *
     * @access public
     * @return array SYNC_FOLDER
     */
    public function GetHierarchy(){
        ZLog::Write(LOGLEVEL_DEBUG, "Combined->GetHierarchy()");
        $ha = array();
        foreach ($this->backends as $i => $b){
            if(!empty($this->config['backends'][$i]['subfolder'])){
                $f = new SyncFolder();
                $f->serverid = $i.$this->config['delimiter'].'0';
                $f->parentid = '0';
                $f->displayname = $this->config['backends'][$i]['subfolder'];
                $f->type = SYNC_FOLDER_TYPE_OTHER;
                $ha[] = $f;
            }
            $h = $this->backends[$i]->GetHierarchy();
            if(is_array($h)){
                foreach($h as $j => $f){
                    $h[$j]->serverid = $i.$this->config['delimiter'].$h[$j]->serverid;
                    if($h[$j]->parentid != '0' || !empty($this->config['backends'][$i]['subfolder'])){
                        $h[$j]->parentid = $i.$this->config['delimiter'].$h[$j]->parentid;
                    }
                    if(isset($this->config['folderbackend'][$h[$j]->type]) && $this->config['folderbackend'][$h[$j]->type] != $i){
                        $h[$j]->type = SYNC_FOLDER_TYPE_OTHER;
                    }
                }
                $ha = array_merge($ha, $h);
            }
        }
        ZLog::Write(LOGLEVEL_DEBUG, "Combined->GetHierarchy() success");
        return $ha;
    }

    /**
     * Returns the importer to process changes from the mobile
     *
     * @param string        $folderid (opt)
     *
     * @access public
     * @return object(ImportChanges)
     */
    public function GetImporter($folderid = false) {
        if($folderid !== false) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("Combined->GetImporter() Content: ImportChangesCombined:('%s')", $folderid));

            // get the contents importer from the folder in a backend
            // the importer is wrapped to check foldernames in the ImportMessageMove function
            $backend = $this->GetBackend($folderid);
            if($backend === false)
                return false;
            $importer = $backend->GetImporter($this->GetBackendFolder($folderid));
            if($importer){
                return new ImportChangesCombined($this, $folderid, $importer);
            }
            return false;
        }
        else {
            ZLog::Write(LOGLEVEL_DEBUG, "Combined->GetImporter() -> Hierarchy: ImportChangesCombined()");
            //return our own hierarchy importer which send each change to the right backend
            return new ImportChangesCombined($this);
        }
    }

    /**
     * Returns the exporter to send changes to the mobile
     * the exporter from right backend for contents exporter and our own exporter for hierarchy exporter
     *
     * @param string        $folderid (opt)
     *
     * @access public
     * @return object(ExportChanges)
     */
    public function GetExporter($folderid = false){
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("Combined->GetExporter('%s')", $folderid));
        if($folderid){
            $backend = $this->GetBackend($folderid);
            if($backend == false)
                return false;
            return $backend->GetExporter($this->GetBackendFolder($folderid));
        }
        return new ExportChangesCombined($this);
    }

    /**
     * Sends an e-mail with the first backend returning true
     *
     * @param string        $rfc822     raw mail submitted by the mobile
     * @param string        $forward    id of the message to be attached below $rfc822
     * @param string        $reply      id of the message to be attached below $rfc822
     * @param string        $parent     id of the folder containing $forward or $reply
     * @param boolean       $saveInSent indicates if the mail should be saved in the Sent folder
     *
     * @access public
     * @return boolean
     */
    public function SendMail($rfc822, $forward = false, $reply = false, $parent = false, $saveInSent = true) {
        ZLog::Write(LOGLEVEL_DEBUG, "Combined->SendMail()");
        if (isset($parent)) $parent = $this->GetBackendFolder($parent);
        foreach ($this->backends as $i => $b){
            if($this->backends[$i]->SendMail($rfc822, $forward, $reply, $parent, $saveInSent) == true){
                return true;
            }
        }
        return false;
    }

    /**
     * Returns all available data of a single message from the right backend
     *
     * @param string        $folderid
     * @param string        $id
     * @param string        $mimesupport flag
     *
     * @access public
     * @return object(SyncObject)
     */
    public function Fetch($folderid, $id, $mimesupport = 0){
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("Combined->Fetch('%s', '%s', '%s')", $folderid, $id, $mimesupport));
        $backend = $this->GetBackend($folderid);
        if($backend == false)
            return false;
        return $backend->Fetch($this->GetBackendFolder($folderid), $id, $mimesupport);
    }

    /**
     * Returns the waste basket
     * If the wastebasket is set to one backend, return the wastebasket of that backend
     * else return the first waste basket we can find
     *
     * @access public
     * @return string
     */
    function GetWasteBasket(){
        ZLog::Write(LOGLEVEL_DEBUG, "Combined->GetWasteBasket()");
        if(isset($this->config['folderbackend'][SYNC_FOLDER_TYPE_WASTEBASKET])){
            $wb = $this->backends[$this->config['folderbackend'][SYNC_FOLDER_TYPE_WASTEBASKET]]->GetWasteBasket();
            if($wb){
                return $this->config['folderbackend'][SYNC_FOLDER_TYPE_WASTEBASKET].$this->config['delimiter'].$wb;
            }
            return false;
        }
        foreach($this->backends as $i => $b){
            $w = $this->backends[$i]->GetWasteBasket();
            if($w){
                return $i.$this->config['delimiter'].$w;
            }
        }
        return false;
    }

    /**
     * Returns the content of the named attachment.
     * There is no way to tell which backend the attachment is from, so we try them all
     *
     * @param string        $attname
     *
     * @access public
     * @return boolean
     */
    public function GetAttachmentData($attname){
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("Combined->GetAttachmentData('%s')", $attname));
        foreach ($this->backends as $i => $b){
            if($this->backends[$i]->GetAttachmentData($attname) == true){
                return true;
            }
        }
        return false;
    }

    /**
     * Processes a response to a meeting request.
     *
     * @param string        $requestid      id of the object containing the request
     * @param string        $folderid       id of the parent folder of $requestid
     * @param string        $response
     * @param string        &$calendarid    reference of the created/updated calendar obj
     *
     * @access public
     * @return boolean
     */
    public function MeetingResponse($requestid, $folderid, $error, &$calendarid) {
        $backend = $this->GetBackend($folderid);
        if($backend === false)
            return false;
        return $backend->MeetingResponse($requestid, $this->GetBackendFolder($folderid), $error, $calendarid);
    }

    /**
     * Finds the correct backend for a folder
     *
     * @param string        $folderid       combinedid of the folder
     *
     * @access public
     * @return object
     */
    public function GetBackend($folderid){
        $pos = strpos($folderid, $this->config['delimiter']);
        if($pos === false)
            return false;
        $id = substr($folderid, 0, $pos);
        if(!isset($this->backends[$id]))
            return false;
        return $this->backends[$id];
    }

    /**
     * Returns an understandable folderid for the backend
     *
     * @param string        $folderid       combinedid of the folder
     *
     * @access public
     * @return string
     */
    public function GetBackendFolder($folderid){
        $pos = strpos($folderid, $this->config['delimiter']);
        if($pos === false)
            return false;
        return substr($folderid,$pos + strlen($this->config['delimiter']));
    }

    /**
     * Returns backend id for a folder
     *
     * @param string        $folderid       combinedid of the folder
     *
     * @access public
     * @return object
     */
    public function GetBackendId($folderid){
        $pos = strpos($folderid, $this->config['delimiter']);
        if($pos === false)
            return false;
        return substr($folderid,0,$pos);
    }
}
?>