<?php
/***********************************************
* File      :   interfaces.php
* Project   :   Z-Push
* Descr     :   This describes the generic interfaces
*               to import and export changes from mobiles.
*               Backends must implement these interfaces
*
* Created   :   30.03.2011
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

interface IBackend {

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
    public function Logon($username, $domain, $password);

    /**
     * Initializes the backend
     *
     * Called directly after the logon. This specifies the client's protocol version
     * and device id. The device ID can be used for various things, including saving
     * per-device state information.
     * The $user parameter here is normally equal to the $username parameter from the
     * Logon() call. In theory though, you could log on a 'foo', and then sync the emails
     * of user 'bar'. The $user here is the username specified in the request URL, while the
     * $username in the Logon() call is the username which was sent as a part of the HTTP
     * authentication.
     *
     * @param string        $user
     * @param string        $devid
     * @param string        $protocolversion
     *
     * @access public
     * @return boolean
     */
    public function Setup($user, $devid, $protocolversion);

    /**
     * Logs off
     * non critical operations closing the session should be done here
     *
     * @access public
     * @return boolean
     */
    public function Logoff();

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
    public function getSearchResults($searchquery, $searchrange);

    /**
     * Returns an array of SyncFolder types with the entire folder hierarchy
     * on the server (the array itself is flat, but refers to parents via the 'parent' property
     *
     * provides AS 1.0 compatibility
     *
     * @access public
     * @return array SYNC_FOLDER
     */
    public function GetHierarchy();

    /**
     * Returns the importer to process changes from the mobile
     * If no $folderid is given, hierarchy importer is expected
     *
     * @param string        $folderid (opt)
     *
     * @access public
     * @return object(ImportChanges)
     */
    public function GetImporter($folderid = false);

    /**
     * Returns the exporter to send changes to the mobile
     * If no $folderid is given, hierarchy exporter is expected
     *
     * @param string        $folderid (opt)
     *
     * @access public
     * @return object(ExportChanges)
     */
    public function GetExporter($folderid = false);

    /**
     * Sends an e-mail
     * This messages needs to be saved into the 'sent items' folder
     *
     * Basically two things can be done
     *      1) Send the message to an SMTP server as-is
     *      2) Parse the message, and send it some other way
     *
     * @param string        $rfc822     raw mail submitted by the mobile
     * @param string        $forward    id of the message to be attached below $rfc822
     * @param string        $reply      id of the message to be attached below $rfc822
     * @param string        $parent     id of the folder containing $forward or $reply
     *
     * @access public
     * @return boolean
     */
    public function SendMail($rfc822, $forward = false, $reply = false, $parent = false);

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
    public function Fetch($folderid, $id, $mimesupport = 0);

    /**
     * Returns the waste basket
     *
     * The waste basked is used when deleting items; if this function returns a valid folder ID,
     * then all deletes are handled as moves and are sent to the backend as a move.
     * If it returns FALSE, then deletes are handled as real deletes and will be sent to the importer as a DELETE
     *
     * @access public
     * @return string
     */
    public function GetWasteBasket();

    /**
     * Returns the content of the named attachment. The passed attachment identifier is
     * the exact string that is returned in the 'AttName' property of an SyncAttachment.
     * Any information necessary to find the attachment must be encoded in that 'attname' property.
     * Data is written directly (with print $data;)
     *
     * @param string        $attname
     *
     * @access public
     * @return boolean
     */
    public function GetAttachmentData($attname);

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
    public function MeetingResponse($requestid, $folderid, $response, &$calendarid);

    /**
     * Returns true if the Backend implementation supports an alternative PING mechanism
     *
     * @access public
     * @return boolean
     */
    public function AlterPing();

    /**
     * Requests an indication if changes happened in a folder since the syncstate
     *
     * @param string        $folderid       id of the folder
     * @param string        &$syncstate     reference of the syncstate
     *
     * @access public
     * @return boolean
     */
    public function AlterPingChanges($folderid, &$syncstate);

}
// TODO implement StateMachine interface
interface IStateMachine {

}

interface IImportChanges {

    /**
     * Initializes the importer
     *
     * @param string        $state
     * @param int           $flags
     *
     * @access public
     * @return boolean status flag
     */
    public function Config($state, $flags = 0);

    /**
     * Reads state from the Importer
     *
     * @access public
     * @return string
     */
    public function GetState();

    /**----------------------------------------------------------------------------------------------------------
     * Methods for ContentsExporter
     */

    /**
     * Loads objects which are expected to be exported with this state
     * Before importing/saving the actual message from the mobile, a conflict detection can be made
     *
     * @param string    $mclass         class of objects
     * @param int       $restrict       FilterType
     * @param string    $state
     *
     * @access public
     * @return string
     */
    public function LoadConflicts($mclass, $filtertype, $state);

    /**
     * Imports a single message
     *
     * @param string        $id
     * @param SyncObject    $message
     *
     * @access public
     * @return boolean/string - failure / id of message
     */
    public function ImportMessageChange($id, $message);

    /**
     * Imports a deletion. This may conflict if the local object has been modified
     *
     * @param string        $id
     * @param SyncObject    $message
     *
     * @access public
     * @return boolean
     */
    public function ImportMessageDeletion($id);

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
    public function ImportMessageReadFlag($id, $flags);

    /**
     * Imports a move of a message. This occurs when a user moves an item to another folder
     *
     * @param string        $id
     * @param int           $flags - read/unread
     *
     * @access public
     * @return boolean
     */
    public function ImportMessageMove($id, $newfolder);


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
    public function ImportFolderChange($folder);

    /**
     * Imports a folder deletion
     *
     * @param string        $id
     * @param string        $parent id
     *
     * @access public
     * @return int          SYNC_FOLDERHIERARCHY_STATUS
     */
    public function ImportFolderDeletion($id, $parent);

}


interface IExportChanges {

    /**
     * Configures the exporter
     *
     * @param object        &$importer
     * @param string        $mclass
     * @param int           $restrict       FilterType
     * @param string        $syncstate
     * @param int           $flags
     * @param int           $truncation     bytes
     *
     * @access public
     * @return boolean
     */
    public function Config(&$importer, $mclass, $restrict, $syncstate, $flags, $truncation);

    /**
     * Reads the current state from the Exporter
     *
     * @access public
     * @return string
     */
    public function GetState();

    /**
     * Returns the amount of changes to be exported
     *
     * @access public
     * @return int
     */
    public function GetChangeCount();

    /**
     * Synchronizes a change
     *
     * @access public
     * @return array
     */
    public function Synchronize();

}

?>