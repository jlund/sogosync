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
require_once(BASE_PATH."backend/combined/config.php");


/**
 * the ExportHierarchyChangesCombined class is returned from GetExporter for hierarchy changes.
 * It combines the hierarchy changes from all backends and prepends all folderids with the backendid
 */

class ExportHierarchyChangesCombined{
    private $backend;
    private $syncstates;
    private $exporters;
    private $importer;
    private $importwraps;

    public function ExportHierarchyChangesCombined(&$backend) {
        ZLog::Write(LOGLEVEL_DEBUG, 'ExportHierarchyChangesCombined constructed');
        $this->backend =& $backend;
    }

    public function Config(&$importer, $folderid, $restrict, $syncstate, $flags, $truncation) {
        ZLog::Write(LOGLEVEL_DEBUG, 'ExportHierarchyChangesCombined::Config(...)');
        if($folderid){
            return false;
        }
        $this->importer =& $importer;
        $this->syncstates = unserialize($syncstate);
        if(!is_array($this->syncstates)){
            $this->syncstates = array();
        }
        foreach($this->backend->backends as $i => $b){
            if(isset($this->syncstates[$i])){
                $state = $this->syncstates[$i];
            } else {
                $state = '';
            }

            if(!isset($this->importwraps[$i])){
                $this->importwraps[$i] = new ImportHierarchyChangesCombinedWrap($i, $this->backend, $importer);
            }

            $this->exporters[$i] = $this->backend->backends[$i]->GetExporter();
            // TODO config of combined backend are broken
            //$this->exporters[$i]->Config(&$this->importwraps[$i], $folderid, $restrict, $state, $flags, $truncation);
            $this->exporters[$i]->Config($state, $flags);
            $this->exporters[$i]->ConfigContentParameters();
        }
        ZLog::Write(LOGLEVEL_DEBUG, 'ExportHierarchyChangesCombined::Config complete');
    }

    public function GetChangeCount() {
        ZLog::Write(LOGLEVEL_DEBUG, 'ExportHierarchyChangesCombined::GetChangeCount()');
        $c = 0;
        foreach($this->exporters as $i => $e){
            $c += $this->exporters[$i]->GetChangeCount();
        }
        return $c;
    }

    public function Synchronize() {
        ZLog::Write(LOGLEVEL_DEBUG, 'ExportHierarchyChangesCombined::Synchronize()');
        foreach($this->exporters as $i => $e){
            if(!empty($this->backend->config['backends'][$i]['subfolder']) && !isset($this->syncstates[$i])){
                // first sync and subfolder backend
                $f = new SyncFolder();
                $f->serverid = $i.$this->backend->config['delimiter'].'0';
                $f->parentid = '0';
                $f->displayname = $this->backend->config['backends'][$i]['subfolder'];
                $f->type = SYNC_FOLDER_TYPE_OTHER;
                $this->importer->ImportFolderChange($f);
            }
            while(is_array($this->exporters[$i]->Synchronize()));
        }
        return true;
    }

    public function GetState() {
        ZLog::Write(LOGLEVEL_DEBUG, 'ExportHierarchyChangesCombined::GetState()');
        foreach($this->exporters as $i => $e){
            $this->syncstates[$i] = $this->exporters[$i]->GetState();
        }
        return serialize($this->syncstates);
    }
}


// TODO this is deprecated - GetHierarchyImporter
/**
 * The ImportHierarchyChangesCombined class is returned from GetHierarchyImporter.
 * It forwards all hierarchy changes to the right backend
 */

class ImportHierarchyChangesCombined{
    private $backend;
    private $syncstates = array();

    public function ImportHierarchyChangesCombined(&$backend) {
        $this->backend =& $backend;
    }

    public function Config($state) {
        ZLog::Write(LOGLEVEL_DEBUG, 'ImportHierarchyChangesCombined::Config(...)');
        $this->syncstates = unserialize($state);
        if(!is_array($this->syncstates))
            $this->syncstates = array();
    }

    public function ImportFolderChange($folder) {
        $id = $folder->serverid;
        $parent = $folder->parentid;
        ZLog::Write(LOGLEVEL_DEBUG, 'ImportHierarchyChangesCombined::ImportFolderChange('.$id.', '.$parent.', '.$folder->displayname.', '.$folder->type.')');
        if($parent == '0'){
            if($id){
                $backendid = $this->backend->GetBackendId($id);
            }else{
                $backendid = $this->backend->config['rootcreatefolderbackend'];
            }
        }else{
            $backendid = $this->backend->GetBackendId($parent);
            $parent = $this->backend->GetBackendFolder($parent);
        }
        if(!empty($this->backend->config['backends'][$backendid]['subfolder']) && $id == $backendid.$this->backend->config['delimiter'].'0'){
            return false; //we can not change a static subfolder
        }
        if($id != false){
            if($backendid != $this->backend->GetBackendId($id))
                return false;//we can not move a folder from 1 backend to an other backend
            $id = $this->backend->GetBackendFolder($id);

        }
        // TODO this is deprecated
        $importer = $this->backend->backends[$backendid]->GetHierarchyImporter();

        if(isset($this->syncstates[$backendid])){
            $state = $this->syncstates[$backendid];
        }else{
            $state = '';
        }
        $importer->Config($state);
        $res = $importer->ImportFolderChange($folder);
        $this->syncstates[$backendid] = $importer->GetState();
        return $backendid.$this->backend->config['delimiter'].$res;
    }

    public function ImportFolderDeletion($id, $parent) {
        ZLog::Write(LOGLEVEL_DEBUG, 'ImportHierarchyChangesCombined::ImportFolderDeletion('.$id.', '.$parent.')');
        $backendid = $this->backend->GetBackendId($id);
        if(!empty($this->backend->config['backends'][$backendid]['subfolder']) && $id == $backendid.$this->backend->config['delimiter'].'0'){
            return false; //we can not change a static subfolder
        }
        $backend = $this->backend->GetBackend($id);
        $id = $this->backend->GetBackendFolder($id);
        if($parent != '0')
            $parent = $this->backend->GetBackendFolder($parent);
        // TODO this is deprecated
        $importer = $backend->GetHierarchyImporter();
        if(isset($this->syncstates[$backendid])){
            $state = $this->syncstates[$backendid];
        }else{
            $state = '';
        }
        $importer->Config($state);
        $res = $importer->ImportFolderDeletion($id, $parent);
        $this->syncstates[$backendid] = $importer->GetState();
        return $res;
    }

    public function GetState(){
        return serialize($this->syncstates);
    }
}


/**
 * The ImportHierarchyChangesCombinedWrap class wraps the importer given in ExportHierarchyChangesCombined::Config.
 * It prepends the backendid to all folderids and checks foldertypes.
 */

class ImportHierarchyChangesCombinedWrap {
    private $ihc;
    private $backend;
    private $backendid;

    public function ImportHierarchyChangesCombinedWrap($backendid, &$backend, &$ihc) {
        ZLog::Write(LOGLEVEL_DEBUG, 'ImportHierarchyChangesCombinedWrap::ImportHierarchyChangesCombinedWrap('.$backendid.',...)');
        $this->backendid = $backendid;
        $this->backend =& $backend;
        $this->ihc = &$ihc;
    }

    public function ImportFolderChange($folder) {
        $folder->serverid = $this->backendid.$this->backend->config['delimiter'].$folder->serverid;
        if($folder->parentid != '0' || !empty($this->backend->config['backends'][$this->backendid]['subfolder'])){
            $folder->parentid = $this->backendid.$this->backend->config['delimiter'].$folder->parentid;
        }
        if(isset($this->backend->config['folderbackend'][$folder->type]) && $this->backend->config['folderbackend'][$folder->type] != $this->backendid){
            if(in_array($folder->type, array(SYNC_FOLDER_TYPE_INBOX, SYNC_FOLDER_TYPE_DRAFTS, SYNC_FOLDER_TYPE_WASTEBASKET, SYNC_FOLDER_TYPE_SENTMAIL, SYNC_FOLDER_TYPE_OUTBOX))){
                ZLog::Write(LOGLEVEL_DEBUG, 'converting folder type to other: '.$folder->displayname.' ('.$folder->serverid.')');
                $folder->type = SYNC_FOLDER_TYPE_OTHER;
            }else{
                ZLog::Write(LOGLEVEL_DEBUG, 'not ussing folder: '.$folder->displayname.' ('.$folder->serverid.')');
                return true;
            }
        }
        ZLog::Write(LOGLEVEL_DEBUG, 'ImportHierarchyChangesCombinedWrap::ImportFolderChange('.$folder->serverid.')');
        return $this->ihc->ImportFolderChange($folder);
    }

    public function ImportFolderDeletion($id) {
        ZLog::Write(LOGLEVEL_DEBUG, 'ImportHierarchyChangesCombinedWrap::ImportFolderDeletion('.$id.')');
        //TODO $this->delimiter
        return $this->ihc->ImportFolderDeletion($this->backendid.$this->delimiter.$id);
    }
}

// TODO this is deprecated - GetContentsImporter
/**
 * The ImportContentsChangesCombinedWrap class wraps the importer given in GetContentsImporter.
 * It allows to check and change the folderid on ImportMessageMove.
 */

class ImportContentsChangesCombinedWrap{
    var $icc;
    var $backend;
    var $folderid;

    function ImportContentsChangesCombinedWrap($folderid, &$backend, &$icc){
        ZLog::Write(LOGLEVEL_DEBUG, 'ImportContentsChangesCombinedWrap::ImportContentsChangesCombinedWrap('.$folderid.',...)');
        $this->folderid = $folderid;
        $this->backend = &$backend;
        $this->icc = &$icc;
    }

    function Config($state, $flags = 0) {
        return $this->icc->Config($state, $flags);
    }
    function ImportMessageChange($id, $message){
        return $this->icc->ImportMessageChange($id, $message);
    }
    function ImportMessageDeletion($id) {
        return $this->icc->ImportMessageDeletion($id);
    }
    function ImportMessageReadFlag($id, $flags){
        return $this->icc->ImportMessageReadFlag($id, $flags);
    }

    function ImportMessageMove($id, $newfolder) {
        if($this->backend->GetBackendId($this->folderid) != $this->backend->GetBackendId($newfolder)){
            //can not move messages between backends
            return false;
        }
        return $this->icc->ImportMessageMove($id, $this->backend->GetBackendFolder($newfolder));
    }

    function getState(){
        return $this->icc->getState();
    }

    function LoadConflicts($mclass, $filtertype, $state) {
        $this->icc->LoadConflicts($mclass, $filtertype, $state);
    }
}



class BackendCombined extends Backend{
    public $config;
    public $backends;

    public function BackendCombined() {
        parent::Backend();
        $this->config = BackendCombinedConfig::GetBackendCombinedConfig();

        foreach ($this->config['backends'] as $i => $b){
            $this->backends[$i] = new $b['name']($b['config']);
        }
        ZLog::Write(LOGLEVEL_INFO, 'Combined '.count($this->backends). ' backends loaded.');
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
        ZLog::Write(LOGLEVEL_DEBUG, 'Combined::Logon('.$username.', '.$domain.',***)');
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
                ZLog::Write(LOGLEVEL_DEBUG, 'Combined login failed on'. $this->config['backends'][$i]['name']);
                return false;
            }
        }
        ZLog::Write(LOGLEVEL_INFO, 'Combined login success');
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
        // TODO CombinedBackend::Setup is completely broken by now
        $user = $store;
        // TODO check if devid and Protocolversion are really used by the backends
        $devid = Request::getDeviceID();
        $protocolversion = Request::getProtocolVersion();

        // TODO check if status exceptions have to be catched
        ZLog::Write(LOGLEVEL_DEBUG, 'Combined::Setup('.$user.', '.$devid.', '.$protocolversion.')');
        if(!is_array($this->backends)){
            return false;
        }
        foreach ($this->backends as $i => $b){
            $u = $user;
            if(isset($this->config['backends'][$i]['users']) && isset($this->config['backends'][$i]['users'][$user]['username'])){
                    $u = $this->config['backends'][$i]['users'][$user]['username'];
            }
            if($this->backends[$i]->Setup($u, $devid, $protocolversion) == false){
                ZLog::Write(LOGLEVEL_WARN, 'Combined::Setup failed');
                return false;
            }
        }
        ZLog::Write(LOGLEVEL_INFO, 'Combined::Setup success');
        return true;
    }

    /**
     * Logs off each backend
     *
     * @access public
     * @return boolean
     */
    public function Logoff() {
        foreach ($this->backends as $i => $b){
            $this->backends[$i]->Logoff();
        }
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
        ZLog::Write(LOGLEVEL_DEBUG, 'Combined::GetHierarchy()');
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
            ZLog::Write(LOGLEVEL_DEBUG, 'Combined::GetImporter() -> ImportContentChangesCombined:('.$folderid.')');

            // get the contents importer from the folder in a backend
            // the importer is wrapped to check foldernames in the ImportMessageMove function
            $backend = $this->GetBackend($folderid);
            if($backend === false)
                return false;
//          TODO this is deprecated - GetContentsImporter
            $importer = $backend->GetContentsImporter($this->GetBackendFolder($folderid));
            if($importer){
                return new ImportContentsChangesCombinedWrap($folderid, $this, $importer);
            }
            return false;
        }
        else {
            ZLog::Write(LOGLEVEL_DEBUG, 'Combined::GetImporter() -> ImportHierarchyChangesCombined()');
            //return our own hierarchy importer which send each change to the right backend
            return new ImportHierarchyChangesCombined($this);
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
        ZLog::Write(LOGLEVEL_DEBUG, 'Combined::GetExporter('.$folderid.')');
        if($folderid){
            $backend = $this->GetBackend($folderid);
            if($backend == false)
                return false;
            return $backend->GetExporter($this->GetBackendFolder($folderid));
        }
        return new ExportHierarchyChangesCombined($this);
    }

    //
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
        ZLog::Write(LOGLEVEL_DEBUG, 'Combined::Fetch('.$folderid.', '.$id.')');
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
        ZLog::Write(LOGLEVEL_DEBUG, 'Combined::GetWasteBasket()');
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
        ZLog::Write(LOGLEVEL_DEBUG, 'Combined::GetAttachmentData('.$attname.')');
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