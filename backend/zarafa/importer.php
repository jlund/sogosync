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


// This is our local importer. IE it receives data from the PDA. It must therefore receive Sync
// objects and convert them into MAPI objects, and then send them to the ICS importer to do the actual
// writing of the object.
class ImportContentsChangesICS {
    function ImportContentsChangesICS($session, $store, $folderid) {
        $this->_session = $session;
        $this->_store = $store;
        $this->_folderid = $folderid;

        $entryid = mapi_msgstore_entryidfromsourcekey($store, $folderid);
        if(!$entryid) {
            // Folder not found
            // TODO: status
            writeLog(LOGLEVEL_wARN, "Folder not found: " . bin2hex($folderid));
            $this->importer = false;
            return;
        }

        $folder = mapi_msgstore_openentry($store, $entryid);
        if(!$folder) {
            writeLog(LOGLEVEL_WARN, "Unable to open folder: " . sprintf("%x", mapi_last_hresult()));
            $this->importer = false;
            return;
        }

        $this->importer = mapi_openproperty($folder, PR_COLLECTOR, IID_IExchangeImportContentsChanges, 0 , 0);
    }

    function Config($state, $flags = 0) {
        $stream = mapi_stream_create();
        if(strlen($state) == 0) {
            $state = hex2bin("0000000000000000");
        }

        mapi_stream_write($stream, $state);
        $this->statestream = $stream;

        mapi_importcontentschanges_config($this->importer, $stream, $flags);
        $this->_flags = $flags;

        // conflicting messages can be cached here
        $this->_memChanges = new ImportContentsChangesMem();
    }

    function LoadConflicts($mclass, $filtertype, $state) {
        if (!isset($this->_session) || !isset($this->_store) || !isset($this->_folderid)) {
            // TODO: trigger resync? data could be lost!! TEST!
            writeLog(LOGLEVEL_ERROR, "Warning: can not load changes for conflict detections. Session, store or folder information not available");
            return false;
        }

        // configure an exporter so we can detect conflicts
        $exporter = new ExportChangesICS($this->_session, $this->_store, $this->_folderid);
        $exporter->Config(&$this->_memChanges, $mclass, $filtertype, $state, 0, 0);
        while(is_array($exporter->Synchronize()));
        return true;
    }

    function ImportMessageChange($id, $message) {
        $parentsourcekey = $this->_folderid;
        if($id)
            $sourcekey = hex2bin($id);

        $flags = 0;
        $props = array();
        $props[PR_PARENT_SOURCE_KEY] = $parentsourcekey;

        // set the PR_SOURCE_KEY if available or mark it as new message
        if($id) {
            $props[PR_SOURCE_KEY] = $sourcekey;

            // check for conflicts
            if($this->_memChanges->isChanged($id)) {
                if ($this->_flags & SYNC_CONFLICT_OVERWRITE_PIM) {
                    writeLog(LOGLEVEL_INFO, "Conflict detected. Data from PIM will be dropped! Server overwrites PIM.");
                    return false;
                }
                else
                   writeLog(LOGLEVEL_INFO, "Conflict detected. Data from Server will be dropped! PIM overwrites server.");
            }
            if($this->_memChanges->isDeleted($id)) {
                writeLog(LOGLEVEL_INFO, "Conflict detected. Data from PIM will be dropped! Object was deleted on server.");
                return false;
            }
        }
        else
            $flags = SYNC_NEW_MESSAGE;

        if(mapi_importcontentschanges_importmessagechange($this->importer, $props, $flags, $mapimessage)) {
            // TODO: MAPIProvider could be static
            $mapiprovider = new MAPIProvider($this->_session, $this->_store);
            $mapiprovider->_setMessage($mapimessage, $message);
            mapi_message_savechanges($mapimessage);

            $sourcekeyprops = mapi_getprops($mapimessage, array (PR_SOURCE_KEY));
        } else {
            writeLog(LOGLEVEL_WARN, "Unable to update object $id:" . sprintf("%x", mapi_last_hresult()));
            return false;
        }

        return bin2hex($sourcekeyprops[PR_SOURCE_KEY]);
    }

    // Import a deletion. This may conflict if the local object has been modified.
    function ImportMessageDeletion($objid) {
        // check for conflicts
        if($this->_memChanges->isChanged($objid)) {
           writeLog(LOGLEVEL_INFO, "Conflict detected. Data from Server will be dropped! PIM deleted object.");
        }
        // do a 'soft' delete so people can un-delete if necessary
        mapi_importcontentschanges_importmessagedeletion($this->importer, 1, array(hex2bin($objid)));
    }

    // Import a change in 'read' flags .. This can never conflict
    function ImportMessageReadFlag($id, $flags) {
        $readstate = array ( "sourcekey" => hex2bin($id), "flags" => $flags);
        $ret = mapi_importcontentschanges_importperuserreadstatechange($this->importer, array ($readstate) );
        if($ret == false)
            writeLog(LOGLEVEL_WARN, "Unable to set read state: " . sprintf("%x", mapi_last_hresult()));
    }

    // Import a move of a message. This occurs when a user moves an item to another folder. Normally,
    // we would implement this via the 'offical' importmessagemove() function on the ICS importer, but the
    // Zarafa importer does not support this. Therefore we currently implement it via a standard mapi
    // call. This causes a mirror 'add/delete' to be sent to the PDA at the next sync.
    // Manfred, 2010-10-21. For some mobiles import was causing duplicate messages in the destination folder
    // (Mantis #202). Therefore we will create a new message in the destination folder, copy properties
    // of the source message to the new one and then delete the source message.
    function ImportMessageMove($id, $newfolder) {
        if (strtolower($newfolder) == strtolower(bin2hex($this->_folderid)) ) {
            //TODO: status value 4
            writeLog(LOGLEVEL_WARN, "Source and destination are equal");
            return false;
        }
        // Get the entryid of the message we're moving
        $entryid = mapi_msgstore_entryidfromsourcekey($this->_store, $this->_folderid, hex2bin($id));
        if(!$entryid) {
            writeLog(LOGLEVEL_WARN, "Unable to resolve source message id");
            return false;
        }

        //open the source message
        $srcmessage = mapi_msgstore_openentry($this->_store, $entryid);
        if (!$srcmessage) {
            writeLog(LOGLEVEL_WARN, "Unable to open source message:".sprintf("%x", mapi_last_hresult()));
            return false;
        }
        $dstentryid = mapi_msgstore_entryidfromsourcekey($this->_store, hex2bin($newfolder));
        if(!$dstentryid) {
            writeLog(LOGLEVEL_WARN, "Unable to resolve destination folder");
            return false;
        }

        $dstfolder = mapi_msgstore_openentry($this->_store, $dstentryid);
        if(!$dstfolder) {
            writeLog(LOGLEVEL_WARN, "Unable to open destination folder");
            return false;
        }

        $newmessage = mapi_folder_createmessage($dstfolder);
        if (!$newmessage) {
            writeLog(LOGLEVEL_WARN, "Unable to create message in destination folder:".sprintf("%x", mapi_last_hresult()));
            return false;
        }
        // Copy message
        mapi_copyto($srcmessage, array(), array(), $newmessage);
        if (mapi_last_hresult()){
            writeLog(LOGLEVEL_WARN, "copy to failed:".sprintf("%x", mapi_last_hresult()));
            return false;
        }

        $srcfolderentryid = mapi_msgstore_entryidfromsourcekey($this->_store, $this->_folderid);
        if(!$srcfolderentryid) {
            writeLog(LOGLEVEL_WARN, "Unable to resolve source folder");
            return false;
        }

        $srcfolder = mapi_msgstore_openentry($this->_store, $srcfolderentryid);
        if (!$srcfolder) {
            writeLog(LOGLEVEL_WARN, "Unable to open source folder:".sprintf("%x", mapi_last_hresult()));
            return false;
        }

        // Save changes
        mapi_savechanges($newmessage);
        if (mapi_last_hresult()){
            writeLog(LOGLEVEL_WARN, "mapi_savechanges failed:".sprintf("%x", mapi_last_hresult()));
            return false;
        }

        // Delete the old message
        if (!mapi_folder_deletemessages($srcfolder, array($entryid))) {
            writeLog(LOGLEVEL_WARN, "Failed to delete source message. Possible duplicates");
        }

        $sourcekeyprops = mapi_getprops($newmessage, array (PR_SOURCE_KEY));
        if (isset($sourcekeyprops[PR_SOURCE_KEY]) && $sourcekeyprops[PR_SOURCE_KEY]) return  bin2hex($sourcekeyprops[PR_SOURCE_KEY]);

        return false;
    }

    function GetState() {
        if(!isset($this->statestream))
            return false;

        if (function_exists("mapi_importcontentschanges_updatestate")) {
            writeLog(LOGLEVEL_DEBUG, "using mapi_importcontentschanges_updatestate");
            if(mapi_importcontentschanges_updatestate($this->importer, $this->statestream) != true) {
                writeLog(LOGLEVEL_WARN, "Unable to update state: " . sprintf("%X", mapi_last_hresult()));
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

    // ----------------------------------------------------------------------------------------------------------

};

// This is our local hierarchy changes importer. It receives folder change
// data from the PDA and must therefore convert to calls into MAPI ICS
// import calls. It is fairly trivial because folders that are created on
// the PDA are always e-mail folders.

class ImportHierarchyChangesICS  {
    var $_user;

    function ImportHierarchyChangesICS($store) {
        $storeprops = mapi_getprops($store, array(PR_IPM_SUBTREE_ENTRYID));

        $folder = mapi_msgstore_openentry($store, $storeprops[PR_IPM_SUBTREE_ENTRYID]);
        if(!$folder) {
            $this->importer = false;
            return;
        }

        $this->importer = mapi_openproperty($folder, PR_COLLECTOR, IID_IExchangeImportHierarchyChanges, 0 , 0);
        $this->store = $store;
    }

    function Config($state, $flags = 0) {
        // Put the state information in a stream that can be used by ICS

        $stream = mapi_stream_create();
        if(strlen($state) == 0) {
            $state = hex2bin("0000000000000000");
        }

        mapi_stream_write($stream, $state);
        $this->statestream = $stream;

        return mapi_importhierarchychanges_config($this->importer, $stream, $flags);
    }

    function ImportFolderChange($id, $parent, $displayname, $type) {
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
        mapi_importhierarchychanges_importfolderchange($this->importer, array ( PR_SOURCE_KEY => hex2bin($id), PR_PARENT_SOURCE_KEY => hex2bin($parent), PR_DISPLAY_NAME => $displayname) );
        writeLog(LOGLEVEL_DEBUG, "Imported changes for folder:$id");
        return $id;
    }

    function ImportFolderDeletion($id, $parent) {
        return mapi_importhierarchychanges_importfolderdeletion ($this->importer, 0, array (PR_SOURCE_KEY => hex2bin($id)) );
    }

    function GetState() {
        mapi_stream_seek($this->statestream, 0, STREAM_SEEK_SET);
        $data = mapi_stream_read($this->statestream, 4096);

        return $data;
    }
};

?>