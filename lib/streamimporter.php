<?php
/***********************************************
* File      :   streamimporter.php
* Project   :   Z-Push
* Descr     :   sends changes directly to the wbxml stream
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

class ImportChangesStream implements IImportChanges {
    private $encoder;
    private $objclass;
    private $seenObjects;

    /**
     * Constructor of the StreamImporter
     *
     * @param WBXMLEncoder  $encoder        Objects are streamed to this encoder
     * @param SyncObject    $class          SyncObject class (only these are accepted when streaming content messages)
     *
     * @access public
     */
    public function ImportChangesStream(&$encoder, $class) {
        $this->encoder = &$encoder;
        $this->objclass = $class;
        $this->classAsString = (is_object($class))?get_class($class):'';
        $this->seenObjects = array();
    }

    /**
     * Implement interface - never used
     */
    public function Config($state, $flags = 0) { return true; }
    public function GetState() { return false;}
    public function LoadConflicts($mclass, $filtertype, $state) { return true; }

    /**
     * Imports a single message
     *
     * @param string        $id
     * @param SyncObject    $message
     *
     * @access public
     * @return boolean
     */
    public function ImportMessageChange($id, $message) {
        // ignore other SyncObjects
        if(!($message instanceof $this->classAsString))
            return false;

        // prevent sending the same object twice in one request
        if (in_array($id, $this->seenObjects)) {
            ZLog::Write(LOGLEVEL_DEBUG, "Object $id discarded! Object already sent in this request.");
            return true;
        }

        $this->seenObjects[] = $id;

        if ($message->flags === false || $message->flags === SYNC_NEWMESSAGE)
            $this->encoder->startTag(SYNC_ADD);
        else
            $this->encoder->startTag(SYNC_MODIFY);

            $this->encoder->startTag(SYNC_SERVERENTRYID);
                $this->encoder->content($id);
            $this->encoder->endTag();
            $this->encoder->startTag(SYNC_DATA);
                $message->encode($this->encoder);
            $this->encoder->endTag();
        $this->encoder->endTag();

        return true;
    }

    /**
     * Imports a deletion
     *
     * @param string        $id
     *
     * @access public
     * @return boolean
     */
    public function ImportMessageDeletion($id) {
        $this->encoder->startTag(SYNC_REMOVE);
            $this->encoder->startTag(SYNC_SERVERENTRYID);
                $this->encoder->content($id);
            $this->encoder->endTag();
        $this->encoder->endTag();

        return true;
    }

    /**
     * Imports a change in 'read' flag
     * Can only be applied to SyncMail (Email) requests
     *
     * @param string        $id
     * @param int           $flags - read/unread
     *
     * @access public
     * @return boolean
     */
    public function ImportMessageReadFlag($id, $flags) {
        if(!($this->objclass instanceof SyncMail))
            return false;

        $this->encoder->startTag(SYNC_MODIFY);
            $this->encoder->startTag(SYNC_SERVERENTRYID);
                $this->encoder->content($id);
            $this->encoder->endTag();
            $this->encoder->startTag(SYNC_DATA);
                $this->encoder->startTag(SYNC_POOMMAIL_READ);
                    $this->encoder->content($flags);
                $this->encoder->endTag();
            $this->encoder->endTag();
        $this->encoder->endTag();

        return true;
    }

    /**
     * ImportMessageMove is not implemented, as this operation can not be streamed to a WBXMLEncoder
     *
     * @param string        $id
     * @param int           $flags      read/unread
     *
     * @access public
     * @return boolean
     */
    public function ImportMessageMove($id, $newfolder) {
        return true;
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
        // send a modify flag if the folder is already known on the device
        if (isset($folder->flags) && $folder->flags === SYNC_NEWMESSAGE)
            $this->encoder->startTag(SYNC_FOLDERHIERARCHY_ADD);
        else
            $this->encoder->startTag(SYNC_FOLDERHIERARCHY_UPDATE);

        $folder->encode($this->encoder);
        $this->encoder->endTag();

        return true;
    }

    /**
     * Imports a folder deletion
     *
     * @param string        $id
     * @param string        $parent id
     *
     * @access public
     * @return boolean
     */
    public function ImportFolderDeletion($id, $parent = false) {
        $this->encoder->startTag(SYNC_FOLDERHIERARCHY_REMOVE);
            $this->encoder->startTag(SYNC_FOLDERHIERARCHY_SERVERENTRYID);
                $this->encoder->content($id);
            $this->encoder->endTag();
        $this->encoder->endTag();

        return true;
    }
}
?>