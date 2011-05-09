<?php
/***********************************************
* File      :   mapiphpwrapper.php
* Project   :   Z-Push
* Descr     :   The ICS importer is very MAPI specific
*               and needs to be wrapped, because we
*               want all MAPI code to be separate from
*               the rest of z-push. To do so all
*               MAPI dependency are removed in this class.
*               All the other importer are based on
*               SyncObjects, not MAPI.
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


// This is our outgoing wrapper; it receives message changes from ICS and
// sends them on to the wrapped importer (which in turn will turn it into
// XML and send it to the PDA)

// TODO implement IImportChanges ?
class PHPContentsWrapper extends MAPIMapping {
    var $_session;
    var $store;
    var $importer;

    function PHPContentsWrapper($session, $store, $folder, &$importer, $truncation) {
        $this->_session = $session;
        $this->_store = $store;
        $this->_folderid = $folder;
        $this->importer = &$importer;
        $this->_truncation = $truncation;
    }

    function Config($stream, $flags = 0) {
    }

    function GetLastError($hresult, $ulflags, &$lpmapierror) {}

    function UpdateState($stream) {
    }

    function ImportMessageChange ($props, $flags, &$retmapimessage) {
        $sourcekey = $props[PR_SOURCE_KEY];
        $parentsourcekey = $props[PR_PARENT_SOURCE_KEY];
        $entryid = mapi_msgstore_entryidfromsourcekey($this->_store, $parentsourcekey, $sourcekey);

        if(!$entryid)
            return SYNC_E_IGNORE;

        $mapimessage = mapi_msgstore_openentry($this->_store, $entryid);
        $message = $this->GetMessage($mapimessage, $this->_truncation);

        // substitute the MAPI SYNC_NEW_MESSAGE flag by a z-push proprietary flag
        if ($flags == SYNC_NEW_MESSAGE) $message->flags = SYNC_NEWMESSAGE;
        else $message->flags = $flags;

        $this->importer->ImportMessageChange(bin2hex($sourcekey), $message);

        // Tell MAPI it doesn't need to do anything itself, as we've done all the work already.
        return SYNC_E_IGNORE;
    }

    function ImportMessageDeletion ($flags, $sourcekeys) {
        foreach($sourcekeys as $sourcekey) {
            $this->importer->ImportMessageDeletion(bin2hex($sourcekey));
        }
    }

    function ImportPerUserReadStateChange($readstates) {
        foreach($readstates as $readstate) {
            $this->importer->ImportMessageReadFlag(bin2hex($readstate["sourcekey"]), $readstate["flags"] & MSGFLAG_READ);
        }
    }

    function ImportMessageMove ($sourcekeysrcfolder, $sourcekeysrcmessage, $message, $sourcekeydestmessage, $changenumdestmessage) {
        // Never called
    }

	// TODO check if refactoring possible as not part of IImportChanges
	// directly called by fetch in request
    function GetMessage($mapimessage, $truncation) {
        $mapiprovider = new MAPIProvider($this->_session, $this->_store);
        return $mapiprovider->GetMessage($mapimessage, $truncation);
    }
};


// This is our PHP hierarchy wrapper which strips MAPI information from
// the import interface. We get all the information we need from MAPI here
// and then pass it to the generic importer. It receives folder change
// information from ICS and sends it on to the next importer, which in turn
// will convert it into XML which is sent to the PDA

class PHPHierarchyWrapper {
    function PHPHierarchyWrapper($store, &$importer) {
        $this->importer = &$importer;
        $this->_store = $store;
    }

    function Config($stream, $flags = 0) {}

    function GetLastError($hresult, $ulflags, &$lpmapierror) {}

    function UpdateState($stream) {
        if(is_resource($stream)) {
            $data = mapi_stream_read($stream, 4096);
        }
    }

    function ImportFolderChange ($props) {
        $sourcekey = $props[PR_SOURCE_KEY];
        $entryid = mapi_msgstore_entryidfromsourcekey($this->_store, $sourcekey);
        $mapifolder = mapi_msgstore_openentry($this->_store, $entryid);
        $folder = $this->_getFolder($mapifolder);
        $this->importer->ImportFolderChange($folder);
        return 0;
    }

    function ImportFolderDeletion ($flags, $sourcekeys) {
        foreach ($sourcekeys as $sourcekey) {
            $this->importer->ImportFolderDeletion(bin2hex($sourcekey));
        }

        return 0;
    }

    // --------------------------------------------------------------------------------------------

    // TODO: refactor into mapiprovider
    function _getFolder($mapifolder) {
        $folder = new SyncFolder();

        $folderprops = mapi_getprops($mapifolder, array(PR_DISPLAY_NAME, PR_PARENT_ENTRYID, PR_SOURCE_KEY, PR_PARENT_SOURCE_KEY, PR_ENTRYID, PR_CONTAINER_CLASS));
        $storeprops = mapi_getprops($this->_store, array(PR_IPM_SUBTREE_ENTRYID));

        if(!isset($folderprops[PR_DISPLAY_NAME]) ||
           !isset($folderprops[PR_PARENT_ENTRYID]) ||
           !isset($folderprops[PR_SOURCE_KEY]) ||
           !isset($folderprops[PR_ENTRYID]) ||
           !isset($folderprops[PR_PARENT_SOURCE_KEY]) ||
           !isset($storeprops[PR_IPM_SUBTREE_ENTRYID])) {
            ZLog::Write(LOGLEVEL_ERROR, "Missing properties on folder");
            return false;
        }

        $folder->serverid = bin2hex($folderprops[PR_SOURCE_KEY]);
        if($folderprops[PR_PARENT_ENTRYID] == $storeprops[PR_IPM_SUBTREE_ENTRYID])
            $folder->parentid = "0";
        else
            $folder->parentid = bin2hex($folderprops[PR_PARENT_SOURCE_KEY]);
        $folder->displayname = w2u($folderprops[PR_DISPLAY_NAME]);
        $folder->type = $this->_getFolderType($folderprops[PR_ENTRYID], isset($folderprops[PR_CONTAINER_CLASS])?$folderprops[PR_CONTAINER_CLASS]:false);

        return $folder;
    }

    // Gets the folder type by checking the default folders in MAPI
    function _getFolderType($entryid, $class = false) {
        $storeprops = mapi_getprops($this->_store, array(PR_IPM_OUTBOX_ENTRYID, PR_IPM_WASTEBASKET_ENTRYID, PR_IPM_SENTMAIL_ENTRYID));
        $inbox = mapi_msgstore_getreceivefolder($this->_store);
        $inboxprops = mapi_getprops($inbox, array(PR_ENTRYID, PR_IPM_DRAFTS_ENTRYID, PR_IPM_TASK_ENTRYID, PR_IPM_APPOINTMENT_ENTRYID, PR_IPM_CONTACT_ENTRYID, PR_IPM_NOTE_ENTRYID, PR_IPM_JOURNAL_ENTRYID));

        if($entryid == $inboxprops[PR_ENTRYID])
            return SYNC_FOLDER_TYPE_INBOX;
        if($entryid == $inboxprops[PR_IPM_DRAFTS_ENTRYID])
            return SYNC_FOLDER_TYPE_DRAFTS;
        if($entryid == $storeprops[PR_IPM_WASTEBASKET_ENTRYID])
            return SYNC_FOLDER_TYPE_WASTEBASKET;
        if($entryid == $storeprops[PR_IPM_SENTMAIL_ENTRYID])
            return SYNC_FOLDER_TYPE_SENTMAIL;
        if($entryid == $storeprops[PR_IPM_OUTBOX_ENTRYID])
            return SYNC_FOLDER_TYPE_OUTBOX;
        if($entryid == $inboxprops[PR_IPM_TASK_ENTRYID])
            return SYNC_FOLDER_TYPE_TASK;
        if($entryid == $inboxprops[PR_IPM_APPOINTMENT_ENTRYID])
            return SYNC_FOLDER_TYPE_APPOINTMENT;
        if($entryid == $inboxprops[PR_IPM_CONTACT_ENTRYID])
            return SYNC_FOLDER_TYPE_CONTACT;
        if($entryid == $inboxprops[PR_IPM_NOTE_ENTRYID])
            return SYNC_FOLDER_TYPE_NOTE;
        if($entryid == $inboxprops[PR_IPM_JOURNAL_ENTRYID])
            return SYNC_FOLDER_TYPE_JOURNAL;

        // user created folders
        if ($class == "IPF.Note")
            return SYNC_FOLDER_TYPE_USER_MAIL;
        if ($class == "IPF.Task")
            return SYNC_FOLDER_TYPE_USER_TASK;
        if ($class == "IPF.Appointment")
            return SYNC_FOLDER_TYPE_USER_APPOINTMENT;
        if ($class == "IPF.Contact")
            return SYNC_FOLDER_TYPE_USER_CONTACT;
        if ($class == "IPF.StickyNote")
            return SYNC_FOLDER_TYPE_USER_NOTE;
        if ($class == "IPF.Journal")
            return  SYNC_FOLDER_TYPE_USER_JOURNAL;

        return SYNC_FOLDER_TYPE_OTHER;
    }
}

?>