<?php
/***********************************************
* File      :   importer.php
* Project   :   Z-Push
* Descr     :   This is a generic class that is
*               used by both the proxy importer
*               (for outgoing messages) and our
*               local importer (for incoming
*               messages). Basically all shared
*               conversion data for converting
*               to and from MAPI objects is in here.
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



/**
 * This is our local importer. Tt receives data from the PDA, for contents and hierarchy changes.
 * It must therefore receive the incoming data and convert it into MAPI objects, and then send
 * them to the ICS importer to do the actual writing of the object.
 * The creation of folders is fairly trivial, because folders that are created on
 * the PDA are always e-mail folders.
 */

class ImportChangesICS implements IImportChanges {
    private $folderid;
    private $store;
    private $session;
    private $flags;
    private $statestream;
    private $importer;
    private $memChanges;
    private $mapiprovider;
    private $conflictsLoaded;
    private $conflictsMclass;
    private $conflictsFiltertype;
    private $conflictsState;

    /**
     * Constructor
     *
     * @param mapisession       $session
     * @param mapistore         $store
     * @param string            $folderid (opt)
     *
     * @access public
     */
    public function ImportChangesICS($session, $store, $folderid = false) {
        $this->session = $session;
        $this->store = $store;
        $this->folderid = $folderid;
        $this->conflictsLoaded = false;

        if ($folderid) {
            $entryid = mapi_msgstore_entryidfromsourcekey($store, $folderid);
        }
        else {
            $storeprops = mapi_getprops($store, array(PR_IPM_SUBTREE_ENTRYID));
            $entryid = $storeprops[PR_IPM_SUBTREE_ENTRYID];
        }

        // Folder available ?
        if(!$entryid) {
            // TODO: throw exception with status
            if ($folderid)
                ZLog::Write(LOGLEVEL_WARN, "Folder not found: " . bin2hex($folderid));
            else
                ZLog::Write(LOGLEVEL_WARN, "Store root not found");

            $this->importer = false;
            return;
        }

        $folder = mapi_msgstore_openentry($store, $entryid);
        if(!$folder) {
            // TODO: throw exception with status
            ZLog::Write(LOGLEVEL_WARN, "Unable to open folder: " . sprintf("%x", mapi_last_hresult()));
            $this->importer = false;
            return;
        }

        if ($folderid) {
            $this->importer = mapi_openproperty($folder, PR_COLLECTOR, IID_IExchangeImportContentsChanges, 0 , 0);
            $this->mapiprovider = new MAPIProvider($this->session, $this->store);
        }
        else
            $this->importer = mapi_openproperty($folder, PR_COLLECTOR, IID_IExchangeImportHierarchyChanges, 0 , 0);
    }

    /**
     * Initializes the importer
     *
     * @param string        $state
     * @param int           $flags
     *
     * @access public
     * @return boolean
     */
    public function Config($state, $flags = 0) {
        $this->flags = $flags;

        // Put the state information in a stream that can be used by ICS
        $stream = mapi_stream_create();
        if(strlen($state) == 0) {
            $state = hex2bin("0000000000000000");
        }

        mapi_stream_write($stream, $state);
        $this->statestream = $stream;

        if ($this->folderid !== false) {
            // possible conflicting messages will be cached here
            $this->memChanges = new ChangesMemoryWrapper();
            return mapi_importcontentschanges_config($this->importer, $stream, $flags);
        }
        else
            return mapi_importhierarchychanges_config($this->importer, $stream, $flags);
    }

    /**
     * Reads state from the Importer
     *
     * @access public
     * @return string
     */
    public function GetState() {
        if(!isset($this->statestream)) {
            ZLog::Write(LOGLEVEL_WARN, "Error getting state from Importer. Not initialized.");
            return false;
        }

        if ($this->folderid !== false && function_exists("mapi_importcontentschanges_updatestate")) {
            ZLog::Write(LOGLEVEL_DEBUG, "before getting state, using 'mapi_importcontentschanges_updatestate()'");
            if(mapi_importcontentschanges_updatestate($this->importer, $this->statestream) != true) {
                ZLog::Write(LOGLEVEL_WARN, "Unable to update state: " . sprintf("%X", mapi_last_hresult()));
                return false;
            }
        }

        mapi_stream_seek($this->statestream, 0, STREAM_SEEK_SET);

        $state = "";
        while(true) {
            $data = mapi_stream_read($this->statestream, 4096);
            if(strlen($data))
                $state .= $data;
            else
                break;
        }

        return $state;
    }

    /**----------------------------------------------------------------------------------------------------------
     * Methods for ContentsExporter
     */

    /**
     * Loads objects which are expected to be exported with the current state
     * Before importing/saving the actual message from the mobile, a conflict detection is done
     *
     * @param string    $mclass         class of objects
     * @param int       $restrict       FilterType
     * @param string    $state
     *
     * @access public
     * @return boolean
     */
    public function LoadConflicts($mclass, $filtertype, $state) {
        if (!isset($this->session) || !isset($this->store) || !isset($this->folderid)) {
            // TODO: trigger resync? data could be lost!! TEST!
            ZLog::Write(LOGLEVEL_ERROR, "Warning: can not load changes for conflict detection. Session, store or folder information not available");
            return false;
        }

        // save data to load changes later if necessary
        $this->conflictsLoaded = false;
        $this->conflictsMclass = $mclass;
        $this->conflictsFiltertype = $filtertype;
        $this->conflictsState = $state;

        ZLog::Write(LOGLEVEL_DEBUG, "LoadConflicts: will be loaded later, if necessary");
        return true;
    }

    /**
     * Potential conflicts are only loaded when really necessary,
     * e.g. on ADD or MODIFY
     *
     * @access private
     * @return
     */
    private function lazyLoadConflicts() {
        if (!isset($this->session) || !isset($this->store) || !isset($this->folderid) ||
            !isset($this->conflictsMclass) || !isset($this->conflictsFiltertype) || !isset($this->conflictsState)) {
            ZLog::Write(LOGLEVEL_WARN, "Can not load potential conflicting changes in lazymode for conflict detection. Missing information");
            return false;
        }

        if (!$this->conflictsLoaded) {
            ZLog::Write(LOGLEVEL_DEBUG, "LazyLoadConflicts: loading..");

            // configure an exporter so we can detect conflicts
            $exporter = new ExportChangesICS($this->session, $this->store, $this->folderid);
            $exporter->Config($this->conflictsState);
            $exporter->ConfigContentParameters($this->conflictsMclass, $this->conflictsFiltertype, 0);
            $exporter->InitializeExporter($this->memChanges);
            while(is_array($exporter->Synchronize()));
            $this->conflictsLoaded = true;
        }
    }

    /**
     * Imports a single message
     *
     * @param string        $id
     * @param SyncObject    $message
     *
     * @access public
     * @return boolean/string - failure / id of message
     */
    public function ImportMessageChange($id, $message) {
        $parentsourcekey = $this->folderid;
        if($id)
            $sourcekey = hex2bin($id);

        $flags = 0;
        $props = array();
        $props[PR_PARENT_SOURCE_KEY] = $parentsourcekey;

        // set the PR_SOURCE_KEY if available or mark it as new message
        if($id) {
            $props[PR_SOURCE_KEY] = $sourcekey;

            // check for conflicts
            $this->lazyLoadConflicts();
            if($this->memChanges->isChanged($id)) {
                if ($this->flags & SYNC_CONFLICT_OVERWRITE_PIM) {
                    // TODO: in these cases the status 7 should be returned, so the client can inform the user (ASCMD 2.2.1.19.1.22)
                    ZLog::Write(LOGLEVEL_INFO, "Conflict detected. Data from PIM will be dropped! Server overwrites PIM.");
                    return false;
                }
                else
                    ZLog::Write(LOGLEVEL_INFO, "Conflict detected. Data from Server will be dropped! PIM overwrites server.");
            }
            if($this->memChanges->isDeleted($id)) {
                ZLog::Write(LOGLEVEL_INFO, "Conflict detected. Data from PIM will be dropped! Object was deleted on server.");
                return false;
            }
        }
        else
            $flags = SYNC_NEW_MESSAGE;

        if(mapi_importcontentschanges_importmessagechange($this->importer, $props, $flags, $mapimessage)) {
            $this->mapiprovider->SetMessage($mapimessage, $message);
            mapi_message_savechanges($mapimessage);

            $sourcekeyprops = mapi_getprops($mapimessage, array (PR_SOURCE_KEY));
        } else {
            // TODO: throw feasible status, if available
            ZLog::Write(LOGLEVEL_WARN, "Unable to update object $id:" . sprintf("%x", mapi_last_hresult()));
            return false;
        }

        return bin2hex($sourcekeyprops[PR_SOURCE_KEY]);
    }

    /**
     * Imports a deletion. This may conflict if the local object has been modified
     *
     * @param string        $id
     * @param SyncObject    $message
     *
     * @access public
     * @return boolean
     */
    public function ImportMessageDeletion($objid) {
        // check for conflicts
        $this->lazyLoadConflicts();
        if($this->memChanges->isChanged($objid)) {
            ZLog::Write(LOGLEVEL_INFO, "Conflict detected. Data from Server will be dropped! PIM deleted object.");
        }
        // do a 'soft' delete so people can un-delete if necessary
        mapi_importcontentschanges_importmessagedeletion($this->importer, 1, array(hex2bin($objid)));
    }

    /**
     * Imports a change in 'read' flag
     * This can never conflict
     *
     * @param string        $id
     * @param int           $flags - read/unread
     *
     * @access public
     * @return boolean
     */
    public function ImportMessageReadFlag($id, $flags) {
        $readstate = array ( "sourcekey" => hex2bin($id), "flags" => $flags);
        $ret = mapi_importcontentschanges_importperuserreadstatechange($this->importer, array ($readstate) );
        if($ret == false)
            // TODO throw feasible status, if available
            ZLog::Write(LOGLEVEL_WARN, "Unable to set read state: " . sprintf("%x", mapi_last_hresult()));
    }

    /**
     * Imports a move of a message. This occurs when a user moves an item to another folder
     *
     * Normally, we would implement this via the 'offical' importmessagemove() function on the ICS importer,
     * but the Zarafa importer does not support this. Therefore we currently implement it via a standard mapi
     * call. This causes a mirror 'add/delete' to be sent to the PDA at the next sync.
     * Manfred, 2010-10-21. For some mobiles import was causing duplicate messages in the destination folder
     * (Mantis #202). Therefore we will create a new message in the destination folder, copy properties
     * of the source message to the new one and then delete the source message.
     *
     * @param string        $id
     * @param int           $flags - read/unread
     *
     * @access public
     * @return boolean
     */
    public function ImportMessageMove($id, $newfolder) {
        if (strtolower($newfolder) == strtolower(bin2hex($this->folderid)) ) {
            //TODO: status value 4
            ZLog::Write(LOGLEVEL_WARN, "Source and destination are equal");
            return false;
        }
        // Get the entryid of the message we're moving
        $entryid = mapi_msgstore_entryidfromsourcekey($this->store, $this->folderid, hex2bin($id));
        if(!$entryid) {
            ZLog::Write(LOGLEVEL_WARN, "Unable to resolve source message id");
            return false;
        }

        //open the source message
        $srcmessage = mapi_msgstore_openentry($this->store, $entryid);
        if (!$srcmessage) {
            ZLog::Write(LOGLEVEL_WARN, "Unable to open source message:".sprintf("%x", mapi_last_hresult()));
            return false;
        }
        $dstentryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin($newfolder));
        if(!$dstentryid) {
            ZLog::Write(LOGLEVEL_WARN, "Unable to resolve destination folder");
            return false;
        }

        $dstfolder = mapi_msgstore_openentry($this->store, $dstentryid);
        if(!$dstfolder) {
            ZLog::Write(LOGLEVEL_WARN, "Unable to open destination folder");
            return false;
        }

        $newmessage = mapi_folder_createmessage($dstfolder);
        if (!$newmessage) {
            ZLog::Write(LOGLEVEL_WARN, "Unable to create message in destination folder:".sprintf("%x", mapi_last_hresult()));
            return false;
        }
        // Copy message
        mapi_copyto($srcmessage, array(), array(), $newmessage);
        if (mapi_last_hresult()){
            ZLog::Write(LOGLEVEL_WARN, "copy to failed:".sprintf("%x", mapi_last_hresult()));
            return false;
        }

        $srcfolderentryid = mapi_msgstore_entryidfromsourcekey($this->store, $this->folderid);
        if(!$srcfolderentryid) {
            ZLog::Write(LOGLEVEL_WARN, "Unable to resolve source folder");
            return false;
        }

        $srcfolder = mapi_msgstore_openentry($this->store, $srcfolderentryid);
        if (!$srcfolder) {
            ZLog::Write(LOGLEVEL_WARN, "Unable to open source folder:".sprintf("%x", mapi_last_hresult()));
            return false;
        }

        // Save changes
        mapi_savechanges($newmessage);
        if (mapi_last_hresult()){
            ZLog::Write(LOGLEVEL_WARN, "mapi_savechanges failed:".sprintf("%x", mapi_last_hresult()));
            return false;
        }

        // Delete the old message
        if (!mapi_folder_deletemessages($srcfolder, array($entryid))) {
            ZLog::Write(LOGLEVEL_WARN, "Failed to delete source message. Possible duplicates");
        }

        $sourcekeyprops = mapi_getprops($newmessage, array (PR_SOURCE_KEY));
        if (isset($sourcekeyprops[PR_SOURCE_KEY]) && $sourcekeyprops[PR_SOURCE_KEY]) return  bin2hex($sourcekeyprops[PR_SOURCE_KEY]);

        return false;
    }


    /**----------------------------------------------------------------------------------------------------------
     * Methods for HierarchyExporter
     */

    /**
     * Imports a change on a folder
     *
     * @param object        $folder     SyncFolder
     *
     * @access public
     * @return string       id of the folder
     */
    public function ImportFolderChange($folder) {
        $id = $folder->serverid;
        $parent = $folder->parentid;
        $displayname = $folder->displayname;
        $type = $folder->type;

        //create a new folder if $id is not set
        if (!$id) {
            $parentfentryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin($parent));
            $parentfolder = mapi_msgstore_openentry($this->store, $parentfentryid);
            $parentpros = mapi_getprops($parentfolder, array(PR_DISPLAY_NAME));
            $newfolder = mapi_folder_createfolder($parentfolder, $displayname, "");
            $props =  mapi_getprops($newfolder, array(PR_SOURCE_KEY));
            $id = bin2hex($props[PR_SOURCE_KEY]);
        }

        // 'type' is ignored because you can only create email (standard) folders
        mapi_importhierarchychanges_importfolderchange($this->importer, array(PR_SOURCE_KEY => hex2bin($id), PR_PARENT_SOURCE_KEY => hex2bin($parent), PR_DISPLAY_NAME => $displayname));
        ZLog::Write(LOGLEVEL_DEBUG, "Imported changes for folder: $id");
        return $id;
    }

    /**
     * Imports a folder deletion
     *
     * @param string        $id
     * @param string        $parent id
     *
     * @access public
     * @return int          SYNC_FOLDERHIERARCHY_STATUS
     */
    public function ImportFolderDeletion($id, $parent = false) {
        ZLog::Write(LOGLEVEL_DEBUG, "Imported folder deletetion: $id");
        return mapi_importhierarchychanges_importfolderdeletion ($this->importer, 0, array(PR_SOURCE_KEY => hex2bin($id)) );
    }
}
?>