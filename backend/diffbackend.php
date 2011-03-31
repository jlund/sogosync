<?php
/***********************************************
* File      :   diffbackend.php
* Project   :   Z-Push
* Descr     :   We do a standard differential
*               change detection by sorting both
*               lists of items by their unique id,
*               and then traversing both arrays
*               of items at once. Changes can be
*               detected by comparing items at
*               the same position in both arrays.
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

/**----------------------------------------------------------------------------------------------------------
 * DIFF ENGINE
 */

/**
 * Differential mechanism
 *
 * @param array        $old
 * @param array        $new
 *
 * @return array
 */
function GetDiff($old, $new) {
    $changes = array();

    // Sort both arrays in the same way by ID
    usort($old, "RowCmp");
    usort($new, "RowCmp");

    $inew = 0;
    $iold = 0;

    // Get changes by comparing our list of messages with
    // our previous state
    while(1) {
        $change = array();

        if($iold >= count($old) || $inew >= count($new))
            break;

        if($old[$iold]["id"] == $new[$inew]["id"]) {
            // Both messages are still available, compare flags and mod
            if(isset($old[$iold]["flags"]) && isset($new[$inew]["flags"]) && $old[$iold]["flags"] != $new[$inew]["flags"]) {
                // Flags changed
                $change["type"] = "flags";
                $change["id"] = $new[$inew]["id"];
                $change["flags"] = $new[$inew]["flags"];
                $changes[] = $change;
            }

            if($old[$iold]["mod"] != $new[$inew]["mod"]) {
                $change["type"] = "change";
                $change["id"] = $new[$inew]["id"];
                $changes[] = $change;
            }

            $inew++;
            $iold++;
        } else {
            if($old[$iold]["id"] > $new[$inew]["id"]) {
                // Message in state seems to have disappeared (delete)
                $change["type"] = "delete";
                $change["id"] = $old[$iold]["id"];
                $changes[] = $change;
                $iold++;
            } else {
                // Message in new seems to be new (add)
                $change["type"] = "change";
                $change["flags"] = SYNC_NEWMESSAGE;
                $change["id"] = $new[$inew]["id"];
                $changes[] = $change;
                $inew++;
            }
        }
    }

    while($iold < count($old)) {
        // All data left in _syncstate have been deleted
        $change["type"] = "delete";
        $change["id"] = $old[$iold]["id"];
        $changes[] = $change;
        $iold++;
    }

    while($inew < count($new)) {
        // All data left in new have been added
        $change["type"] = "change";
        $change["flags"] = SYNC_NEWMESSAGE;
        $change["id"] = $new[$inew]["id"];
        $changes[] = $change;
        $inew++;
    }

    return $changes;
}

/**
 * Comparing function for differential engine
 *
 * @param array        $a
 * @param array        $b
 *
 * @return boolean
 */
function RowCmp($a, $b) {
    return $a["id"] < $b["id"] ? 1 : -1;
}


/**
 * Differential engine
 */
class DiffState {
    protected $_syncstate;

    // Update the state to reflect changes
    protected function updateState($type, $change) {
        // Change can be a change or an add
        if($type == "change") {
            for($i=0; $i < count($this->_syncstate); $i++) {
                if($this->_syncstate[$i]["id"] == $change["id"]) {
                    $this->_syncstate[$i] = $change;
                    return;
                }
            }
            // Not found, add as new
            $this->_syncstate[] = $change;
        } else {
            for($i=0; $i < count($this->_syncstate); $i++) {
                // Search for the entry for this item
                if($this->_syncstate[$i]["id"] == $change["id"]) {
                    if($type == "flags") {
                        // Update flags
                        $this->_syncstate[$i]["flags"] = $change["flags"];
                    } else if($type == "delete") {
                        // Delete item
                        array_splice($this->_syncstate, $i, 1);
                    }
                    return;
                }
            }
        }
    }

    // Returns TRUE if the given ID conflicts with the given operation. This is only true in the following situations:
    //
    // - Changed here and changed there
    // - Changed here and deleted there
    // - Deleted here and changed there
    //
    // Any other combination of operations can be done (e.g. change flags & move or move & delete)
    protected function isConflict($type, $folderid, $id) {
        $stat = $this->_backend->StatMessage($folderid, $id);

        if(!$stat) {
            // Message is gone
            if($type == "change")
                return true; // deleted here, but changed there
            else
                return false; // all other remote changes still result in a delete (no conflict)
        }

        foreach($this->_syncstate as $state) {
            if($state["id"] == $id) {
                $oldstat = $state;
                break;
            }
        }

        if(!isset($oldstat)) {
            // New message, can never conflict
            return false;
        }

        if($state["mod"] != $oldstat["mod"]) {
            // Changed here
            if($type == "delete" || $type == "change")
                return true; // changed here, but deleted there -> conflict, or changed here and changed there -> conflict
            else
                return false; // changed here, and other remote changes (move or flags)
        }
    }

    public function GetState() {
        return serialize($this->_syncstate);
    }

}



/**----------------------------------------------------------------------------------------------------------
 * IMPORTER & EXPORTER
 */

class ImportChangesDiff extends DiffState implements IImportChanges {
    private $_user;
    private $_folderid;

    /**
     * Constructor
     *
     * @param object        $backend
     * @param string        $folderid
     *
     * @access public
     */
    public function ImportChangesDiff($backend, $folderid = false) {
        $this->_backend = $backend;
        $this->_folderid = $folderid;
    }

    /**
     * Initializes the importer
     *
     * @param string        $state
     * @param int           $flags
     *
     * @access public
     * @return boolean status flag
     */
    public function Config($state, $flags = 0) {
        $this->_syncstate = unserialize($state);
        $this->_flags = $flags;
        return true;
    }

    /**
     * Would load objects which are expected to be exported with this state
     * The DiffBackend implements conflict detection on the fly
     *
     * @param string    $mclass         class of objects
     * @param int       $restrict       FilterType
     * @param string    $state
     *
     * @access public
     * @return string
     */
    public function LoadConflicts($mclass, $filtertype, $state) {
        // changes are detected on the fly
        return true;
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
        //do nothing if it is in a dummy folder
        if ($this->_folderid == SYNC_FOLDER_TYPE_DUMMY)
            return false;

        if($id) {
            // See if there's a conflict
            $conflict = $this->isConflict("change", $this->_folderid, $id);

            // Update client state if this is an update
            $change = array();
            $change["id"] = $id;
            $change["mod"] = 0; // dummy, will be updated later if the change succeeds
            $change["parent"] = $this->_folderid;
            $change["flags"] = (isset($message->read)) ? $message->read : 0;
            $this->updateState("change", $change);

            if($conflict && $this->_flags == SYNC_CONFLICT_OVERWRITE_PIM)
                return true;
        }

        $stat = $this->_backend->ChangeMessage($this->_folderid, $id, $message);

        if(!is_array($stat))
            return $stat;

        // Record the state of the message
        $this->updateState("change", $stat);

        return $stat["id"];
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
    public function ImportMessageDeletion($id) {
        //do nothing if it is in a dummy folder
        if ($this->_folderid == SYNC_FOLDER_TYPE_DUMMY)
            return true;

        // See if there's a conflict
        $conflict = $this->isConflict("delete", $this->_folderid, $id);

        // Update client state
        $change = array();
        $change["id"] = $id;
        $this->updateState("delete", $change);

        // If there is a conflict, and the server 'wins', then return OK without performing the change
        // this will cause the exporter to 'see' the overriding item as a change, and send it back to the PIM
        if($conflict && $this->_flags == SYNC_CONFLICT_OVERWRITE_PIM)
            return true;

        $this->_backend->DeleteMessage($this->_folderid, $id);

        return true;
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
        //do nothing if it is a dummy folder
        if ($this->_folderid == SYNC_FOLDER_TYPE_DUMMY)
            return true;

        // Update client state
        $change = array();
        $change["id"] = $id;
        $change["flags"] = $flags;
        $this->updateState("flags", $change);

        $this->_backend->SetReadFlag($this->_folderid, $id, $flags);

        return true;
    }

    /**
     * Imports a move of a message. This occurs when a user moves an item to another folder
     *
     * @param string        $id
     * @param int           $flags - read/unread
     *
     * @access public
     * @return boolean
     */
    public function ImportMessageMove($id, $newfolder) {
        // don't move messages from or to a dummy folder (GetHierarchy compatibility)
        if ($this->_folderid == SYNC_FOLDER_TYPE_DUMMY || $newfolder == SYNC_FOLDER_TYPE_DUMMY)
            return true;
        return $this->_backend->MoveMessage($this->_folderid, $id, $newfolder);
    }


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

        //do nothing if it is a dummy folder
        if ($parent == SYNC_FOLDER_TYPE_DUMMY)
            return false;

        if($id) {
            $change = array();
            $change["id"] = $id;
            $change["mod"] = $displayname;
            $change["parent"] = $parent;
            $change["flags"] = 0;
            $this->updateState("change", $change);
        }

        $stat = $this->_backend->ChangeFolder($parent, $id, $displayname, $type);

        if($stat)
            $this->updateState("change", $stat);

        return $stat["id"];
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
    public function ImportFolderDeletion($id, $parent) {
        //do nothing if it is a dummy folder
        if ($parent == SYNC_FOLDER_TYPE_DUMMY)
            return false;

        $change = array();
        $change["id"] = $id;

        $this->updateState("delete", $change);

        $this->_backend->DeleteFolder($parent, $id);

        return true;
    }
};


class ExportChangesDiff extends DiffState implements IExportChanges{
    private $_importer;
    private $_folderid;
    private $_restrict;
    private $_flags;
    private $_user;

    /**
     * Constructor
     *
     * @param object        $backend
     * @param string        $folderid
     *
     * @access public
     */
    public function ExportChangesDiff($backend, $folderid) {
        $this->_backend = $backend;
        $this->_folderid = $folderid;
    }

    /**
     * Configures the exporter
     *
     * @param object        $importer
     * @param string        $mclass
     * @param int           $restrict       FilterType
     * @param string        $syncstate
     * @param int           $flags
     * @param int           $truncation     bytes
     *
     * @access public
     * @return boolean
     */
    // TODO: test alterping, as footprint changed .. mclass was $folderid (which doesn't make sense here)
    public function Config(&$importer, $mclass, $restrict, $syncstate, $flags, $truncation) {
        $this->_importer = &$importer;
        $this->_restrict = $restrict;
        $this->_syncstate = unserialize($syncstate);
        $this->_flags = $flags;
        $this->_truncation = $truncation;

        $this->_changes = array();
        $this->_step = 0;

        $cutoffdate = getCutOffDate($restrict);

        if($this->_folderid) {
            // Get the changes since the last sync
            writeLog(LOGLEVEL_DEBUG, "Initializing message diff engine");

            if(!isset($this->_syncstate) || !$this->_syncstate)
                $this->_syncstate = array();

            writeLog(LOGLEVEL_DEBUG, count($this->_syncstate) . " messages in state");

            //do nothing if it is a dummy folder
            if ($this->_folderid != SYNC_FOLDER_TYPE_DUMMY) {

                // on ping: check if backend supports alternative PING mechanism & use it
                if ($mclass === false && $this->_flags == BACKEND_DISCARD_DATA && $this->_backend->AlterPing()) {
                    $this->_changes = $this->_backend->AlterPingChanges($this->_folderid, $this->_syncstate);
                }
                else {
                    // Get our lists - syncstate (old)  and msglist (new)
                    $msglist = $this->_backend->GetMessageList($this->_folderid, $cutoffdate);
                    if($msglist === false)
                        return false;

                    $this->_changes = GetDiff($this->_syncstate, $msglist);
                }
            }

            writeLog(LOGLEVEL_INFO, "Found " . count($this->_changes) . " message changes");
        } else {
            writeLog(LOGLEVEL_DEBUG, "Initializing folder diff engine");

            $folderlist = $this->_backend->GetFolderList();
            if($folderlist === false)
                return false;

            if(!isset($this->_syncstate) || !$this->_syncstate)
                $this->_syncstate = array();

            $this->_changes = GetDiff($this->_syncstate, $folderlist);

            writeLog(LOGLEVEL_INFO, "Found " . count($this->_changes) . " folder changes");
        }
    }

    /**
     * Returns the amount of changes to be exported
     *
     * @access public
     * @return int
     */
    public function GetChangeCount() {
        return count($this->_changes);
    }

    /**
     * Synchronizes a change
     *
     * @access public
     * @return array
     */
    public function Synchronize() {
        $progress = array();

        // Get one of our stored changes and send it to the importer, store the new state if
        // it succeeds
        if($this->_folderid == false) {
            if($this->_step < count($this->_changes)) {
                $change = $this->_changes[$this->_step];

                switch($change["type"]) {
                    case "change":
                        $folder = $this->_backend->GetFolder($change["id"]);
                        $stat = $this->_backend->StatFolder($change["id"]);

                        if(!$folder)
                            return;

                        if($this->_flags & BACKEND_DISCARD_DATA || $this->_importer->ImportFolderChange($folder))
                            $this->updateState("change", $stat);
                        break;
                    case "delete":
                        if($this->_flags & BACKEND_DISCARD_DATA || $this->_importer->ImportFolderDeletion($change["id"]))
                            $this->updateState("delete", $change);
                        break;
                }

                $this->_step++;

                $progress = array();
                $progress["steps"] = count($this->_changes);
                $progress["progress"] = $this->_step;

                return $progress;
            } else {
                return false;
            }
        }
        else {
            if($this->_step < count($this->_changes)) {
                $change = $this->_changes[$this->_step];

                switch($change["type"]) {
                    case "change":
                        $truncsize = getTruncSize($this->_truncation);

                        // Note: because 'parseMessage' and 'statMessage' are two seperate
                        // calls, we have a chance that the message has changed between both
                        // calls. This may cause our algorithm to 'double see' changes.

                        $stat = $this->_backend->StatMessage($this->_folderid, $change["id"]);
                        $message = $this->_backend->GetMessage($this->_folderid, $change["id"], $truncsize);

                        // copy the flag to the message
                        $message->flags = (isset($change["flags"])) ? $change["flags"] : 0;

                        if($stat && $message) {
                            if($this->_flags & BACKEND_DISCARD_DATA || $this->_importer->ImportMessageChange($change["id"], $message) == true)
                                $this->updateState("change", $stat);
                        }
                        break;
                    case "delete":
                        if($this->_flags & BACKEND_DISCARD_DATA || $this->_importer->ImportMessageDeletion($change["id"]) == true)
                            $this->updateState("delete", $change);
                        break;
                    case "flags":
                        if($this->_flags & BACKEND_DISCARD_DATA || $this->_importer->ImportMessageReadFlag($change["id"], $change["flags"]) == true)
                            $this->updateState("flags", $change);
                        break;
                    case "move":
                        if($this->_flags & BACKEND_DISCARD_DATA || $this->_importer->ImportMessageMove($change["id"], $change["parent"]) == true)
                            $this->updateState("move", $change);
                        break;
                }

                $this->_step++;

                $progress = array();
                $progress["steps"] = count($this->_changes);
                $progress["progress"] = $this->_step;

                return $progress;
            } else {
                return false;
            }
        }
    }

};



/**----------------------------------------------------------------------------------------------------------
 * DIFFBACKEND
 */

abstract class BackendDiff extends Backend {
    protected $_user;
    protected $_devid;
    protected $_protocolversion;

    /**
     * Constructor
     *
     * @access public
     */
    public function DiffBackend() {
        parent::Backend();
    }

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
    public function Setup($user, $devid, $protocolversion) {
        $this->_user = $user;
        $this->_devid = $devid;
        $this->_protocolversion = $protocolversion;

        return true;
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
    function GetHierarchy() {
        $folders = array();

        $fl = $this->getFolderList();
        foreach($fl as $f){
            $folders[] = $this->GetFolder($f['id']);
        }

        return $folders;
    }

    /**
     * Returns the importer to process changes from the mobile
     * If no $folderid is given, hierarchy importer is expected
     *
     * @param string        $folderid (opt)
     *
     * @access public
     * @return object(ImportChanges)
     */
    public function GetImporter($folderid = false) {
        return new ImportChangesDiff($this, $folderid);
    }

    /**
     * Returns the exporter to send changes to the mobile
     * If no $folderid is given, hierarchy exporter is expected
     *
     * @param string        $folderid (opt)
     *
     * @access public
     * @return object(ExportChanges)
     */
    public function GetExporter($folderid = false) {
        return new ExportChangesDiff($this, $folderid);
    }

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
    public function Fetch($folderid, $id, $mimesupport = 0) {
        return $this->GetMessage($folderid, $id, 1024*1024, $mimesupport); // Forces entire message (up to 1Mb)
    }

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
     * @return boolean      status
     */
    public function MeetingResponse($requestid, $folderid, $error, &$calendarid) {
        return false;
    }

    /**----------------------------------------------------------------------------------------------------------
     * protected DiffBackend methods
     *
     * Need to be implemented in the actual diff backend
     */

    /**
     * Returns a list (array) of folders, each entry being an associative array
     * with the same entries as StatFolder(). This method should return stable information; ie
     * if nothing has changed, the items in the array must be exactly the same. The order of
     * the items within the array is not important though.
     *
     * @access protected
     * @return array
     */
    public abstract function GetFolderList();

    /**
     * Returns an actual SyncFolder object with all the properties set. Folders
     * are pretty simple, having only a type, a name, a parent and a server ID.
     *
     * @param string        $id           id of the folder
     *
     * @access public
     * @return object   SyncFolder with information
     */
    public abstract function GetFolder($id);

    /**
     * Returns folder stats. An associative array with properties is expected.
     *
     * @param string        $id             id of the folder
     *
     * @access public
     * @return array
     *          Associative array(
     *              string  "id"            The server ID that will be used to identify the folder. It must be unique, and not too long
     *                                      How long exactly is not known, but try keeping it under 20 chars or so. It must be a string.
     *              string  "parent"        The server ID of the parent of the folder. Same restrictions as 'id' apply.
     *              long    "mod"           This is the modification signature. It is any arbitrary string which is constant as long as
     *                                      the folder has not changed. In practice this means that 'mod' can be equal to the folder name
     *                                      as this is the only thing that ever changes in folders. (the type is normally constant)
     *          )
     */
    public abstract function StatFolder($id);

    /**
     * Creates or modifies a folder
     *
     * @param string        $folderid       id of the parent folder
     * @param string        $oldid          if empty -> new folder created, else folder is to be renamed
     * @param string        $displayname    new folder name (to be created, or to be renamed to)
     * @param int           $type           folder type
     *
     * @access public
     * @return boolean      status
     *
     */
    public abstract function ChangeFolder($folderid, $oldid, $displayname, $type);

    /**
     * Returns a list (array) of messages, each entry being an associative array
     * with the same entries as StatMessage(). This method should return stable information; ie
     * if nothing has changed, the items in the array must be exactly the same. The order of
     * the items within the array is not important though.
     *
     * The $cutoffdate is a date in the past, representing the date since which items should be shown.
     * This cutoffdate is determined by the user's setting of getting 'Last 3 days' of e-mail, etc. If
     * the cutoffdate is ignored, the user will not be able to select their own cutoffdate, but all
     * will work OK apart from that.
     *
     * @param string        $folderid       id of the parent folder
     * @param long          $cutoffdate     timestamp in the past from which on messages should be returned
     *
     * @access public
     * @return array        of messages
     */
    public abstract function GetMessageList($folderid, $cutoffdate);

    /**
     * Returns the actual SyncXXX object type. The '$folderid' of parent folder can be used.
     * Mixing item types returned is illegal and will be blocked by the engine; ie returning an Email object in a
     * Tasks folder will not do anything. The SyncXXX objects should be filled with as much information as possible,
     * but at least the subject, body, to, from, etc.
     *
     * @param string        $folderid       id of the parent folder
     * @param string        $id             id of the message
     * @param int           $truncsize      truncation size in bytes
     * @param int           $mimesupport    output the mime message
     *
     * @access public
     * @return object
     */
    public abstract function GetMessage($folderid, $id, $truncsize, $mimesupport = 0);

    /**
     * Returns message stats, analogous to the folder stats from StatFolder().
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     *
     * @access public
     * @return array
     *          Associative array(
     *              string  "id"            Server unique identifier for the message. Again, try to keep this short (under 20 chars)
     *              int     "flags"         simply '0' for unread, '1' for read
     *              long    "mod"           This is the modification signature. It is any arbitrary string which is constant as long as
     *                                      the message has not changed. As soon as this signature changes, the item is assumed to be completely
     *                                      changed, and will be sent to the PDA as a whole. Normally you can use something like the modification
     *                                      time for this field, which will change as soon as the contents have changed.
     *          )
     */
    public abstract function StatMessage($folderid, $id);

    /**
     * Called when a message has been changed on the mobile. The new message must be saved to disk.
     * The return value must be whatever would be returned from StatMessage() after the message has been saved.
     * This way, the 'flags' and the 'mod' properties of the StatMessage() item may change via ChangeMessage().
     * This method will never be called on E-mail items as it's not 'possible' to change e-mail items. It's only
     * possible to set them as 'read' or 'unread'.
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     * @param SyncXXX       $message        the SyncObject containing a message
     *
     * @access public
     * @return array        same return value as StatMessage()
     */
    public abstract function ChangeMessage($folderid, $id, $message);

    /**
     * Changes the 'read' flag of a message on disk. The $flags
     * parameter can only be '1' (read) or '0' (unread). After a call to
     * SetReadFlag(), GetMessageList() should return the message with the
     * new 'flags' but should not modify the 'mod' parameter. If you do
     * change 'mod', simply setting the message to 'read' on the mobile will trigger
     * a full resync of the item from the server.
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     * @param int           $flags          read flag of the message
     *
     * @access public
     * @return boolean      status of the operation
     */
    public abstract function SetReadFlag($folderid, $id, $flags);

    /**
     * Called when the user has requested to delete (really delete) a message. Usually
     * this means just unlinking the file its in or somesuch. After this call has succeeded, a call to
     * GetMessageList() should no longer list the message. If it does, the message will be re-sent to the mobile
     * as it will be seen as a 'new' item. This means that if this method is not implemented, it's possible to
     * delete messages on the PDA, but as soon as a sync is done, the item will be resynched to the mobile
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     *
     * @access public
     * @return boolean      status of the operation
     */
    public abstract function DeleteMessage($folderid, $id);

    /**
     * Called when the user moves an item on the PDA from one folder to another. Whatever is needed
     * to move the message on disk has to be done here. After this call, StatMessage() and GetMessageList()
     * should show the items to have a new parent. This means that it will disappear from GetMessageList()
     * of the sourcefolder and the destination folder will show the new message
     *
     * @param string        $folderid       id of the source folder
     * @param string        $id             id of the message
     * @param string        $newfolderid    id of the destination folder
     *
     * @access public
     * @return boolean      status of the operation
     */
    public abstract function MoveMessage($folderid, $id, $newfolderid);


    /**
     * DEPRECATED legacy methods
     */
    public function GetHierarchyImporter() {
        return new ImportChangesDiff($this);
    }

    public function GetContentsImporter($folderid) {
        return new ImportChangesDiff($this, $folderid);
    }

}
?>