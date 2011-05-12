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

/**----------------------------------------------------------------------------------------------------------
 * IStateMachine interface
 *
 * Interface called from the DeviceManager to save states for a user/device/folder
 * Z-Push implements the FileStateMachine which saves states to disk
 * Backends can implement their own StateMachine implementing this interface and returning
 * an IStateMachine instance with Backend->GetStateMachine().
 *
 * Old sync states are not deleted until a sync state is requested.
 * At that moment, the PIM is apparently requesting an update
 * since sync key X, so any sync states before X are already on
 * the PIM, and can therefore be removed. This algorithm should be
 * automatically enforced by the IStateMachine implementation.
 *
 * The key could be IStateMachine::DEVICEDATA or IStateMachine::PINGDATA
 * indicating that these are general device or ping data
 */

interface IStateMachine {
    const DEVICEDATA = "devicedata";
    const PINGDATA = "ping";

    // TODO IStateMachine could offer a mechanisms to realize interprocess mutexes

    /**
     * Gets a state for a specified key and counter.
     * This method sould call IStateMachine->CleanStates()
     * to remove older states (same key, previous counters)
     *
     * @param string    $devid              the device id
     * @param string    $key
     * @param string    $counter            (opt)
     *
     * @access public
     * @return string
     */
    public function GetState($devid, $key, $counter = false);

    /**
     * Writes ta state to for a key and counter
     *
     * @param string    $state
     * @param string    $devid              the device id
     * @param string    $key
     * @param int       $counter    (opt)
     *
     * @access public
     * @return boolean
     */
    public function SetState($state, $devid, $key, $counter = false);

    /**
     * Cleans up all older states
     * If called with a $counter, all states previous state counter can be removed
     * If called without $counter, all keys (independently from the counter) can be removed
     *
     * @param string    $devid              the device id
     * @param string    $key
     * @param string    $counter            (opt)
     *
     * @access public
     * @return
     */
    public function CleanStates($devid, $key, $counter = false);

    /**
     * Links a user to a device
     *
     * @access public
     * @return array
     */
    public function LinkUserDevice($username, $devid);

    /**
     * Unlinks a device from a user
     *
     * @access public
     * @return array
     */
    public function UnLinkUserDevice($username, $devid);

    /**
     * Returns an array with all device ids for a user.
     * If no user is set, all device ids should be returned
     *
     * @access public
     * @return array
     */
    public function GetAllDevices($username = false);
}


/**----------------------------------------------------------------------------------------------------------
 * IBackend interface
 *
 * All Z-Push backends must implement this interface
 */
interface IBackend {
    /**
     * Returns a IStateMachine implementation used to save states
     *
     * @access public
     * @return boolean/object       if false is returned, the default Statemachine is
     *                              used else the implementation of IStateMachine
     */
    public function GetStateMachine();

    /**
     * Returns a ISearchProvider implementation used for searches
     *
     * @access public
     * @return object       Implementation of ISearchProvider
     */
    public function GetSearchProvider();

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
    public function Setup($store, $checkACLonly = false, $folderid = false);

    /**
     * Logs off
     * non critical operations closing the session should be done here
     *
     * @access public
     * @return boolean
     */
    public function Logoff();

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
     * If no $folderid is given, hierarchy data will be imported
     * With a $folderid a content data will be imported
     *
     * @param string        $folderid (opt)
     *
     * @access public
     * @return object       implements IImportChanges
     */
    public function GetImporter($folderid = false);

    /**
     * Returns the exporter to send changes to the mobile
     * If no $folderid is given, hierarchy data should be exported
     * With a $folderid a content data is expected
     *
     * @param string        $folderid (opt)
     *
     * @access public
     * @return object       implements IExportChanges
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
     * @param boolean       $saveInSent indicates if the mail should be saved in the Sent folder
     *
     * @access public
     * @return boolean
     */
    public function SendMail($rfc822, $forward = false, $reply = false, $parent = false, $saveInSent = true);

    /**
     * Returns all available data of a single message
     *
     * @param string        $folderid
     * @param string        $id
     * @param string        (opt)$mimesupport flag
     *
     *  //TODO $mimesupport should be refactored as more options will be necessary
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
     * If it returns FALSE, then deletes are handled as real deletes
     *
     * @access public
     * @return string
     */
    public function GetWasteBasket();

    /**
     * Returns the content of the named attachment. The passed attachment identifier is
     * the exact string that is returned in the 'AttName' property of an SyncAttachment.
     * Any information necessary to locate the attachment must be encoded in that 'attname' property.
     * Data is written directly - 'print $data;'
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


/**----------------------------------------------------------------------------------------------------------
 * ISearchProvider interface
 *
 * Searches can be executed with this interface
 */
interface ISearchProvider {
    /**
     * Indicates if a search type is supported by this SearchProvider
     * Currently only the type "GAL" (Global Address List) is implemented
     *
     * @param string        $searchtype
     *
     * @access public
     * @return boolean
     */
    public function SupportsType($searchtype);

    /**
     * Searches the GAL
     *
     * @param string        $searchquery
     * @param string        $searchrange
     *
     * @access public
     * @return array
     */
    public function GetGALSearchResults($searchquery, $searchrange);

    /**
     * Disconnects from the current search provider
     *
     * @access public
     * @return boolean
     */
    public function Disconnect();
}


/**----------------------------------------------------------------------------------------------------------
 * IChanges interface
 *
 * Can not only be implemented.
 * IImportChanges and IExportChanges inherit from this interface
 */
interface IChanges {
    /**
     * Initializes the state and flags
     *
     * @param string        $state
     * @param int           $flags
     *
     * @access public
     * @return boolean      status flag
     */
    public function Config($state, $flags = 0);

    /**
     * Reads and returns the current state
     *
     * @access public
     * @return string
     */
    public function GetState();
}


/**----------------------------------------------------------------------------------------------------------
 * IImportChanges interface
 *
 * Imports data (content and hierarchy)
 *
 */
interface IImportChanges extends IChanges {

    /**----------------------------------------------------------------------------------------------------------
     * Methods for to import contents
     */

    /**
     * Loads objects which are expected to be exported with the state
     * Before importing/saving the actual message from the mobile, a conflict detection should be done
     *
     * @param string        $mclass         class of objects
     * @param int           $restrict       FilterType
     * @param string        $state
     *
     * @access public
     * @return boolean
     */
    public function LoadConflicts($mclass, $filtertype, $state);

    /**
     * Imports a single message
     *
     * @param string        $id
     * @param SyncObject    $message
     *
     * @access public
     * @return boolean/string               failure / id of message
     */
    public function ImportMessageChange($id, $message);

    /**
     * Imports a deletion. This may conflict if the local object has been modified
     *
     * @param string        $id
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
     * @param int           $flags
     *
     * @access public
     * @return boolean
     */
    public function ImportMessageReadFlag($id, $flags);

    /**
     * Imports a move of a message. This occurs when a user moves an item to another folder
     *
     * @param string        $id
     * @param string        $newfolder
     *
     * @access public
     * @return boolean
     */
    public function ImportMessageMove($id, $newfolder);


    /**----------------------------------------------------------------------------------------------------------
     * Methods to import hierarchy
     */

    /**
     * Imports a change on a folder
     *
     * @param object        $folder         SyncFolder
     *
     * @access public
     * @return boolean/string               status/id of the folder
     */
    public function ImportFolderChange($folder);

    /**
     * Imports a folder deletion
     *
     * @param string        $id
     * @param string        $parent id
     *
     * @access public
     * @return boolean/int  success/SYNC_FOLDERHIERARCHY_STATUS
     */
    public function ImportFolderDeletion($id, $parent = false);

}


/**----------------------------------------------------------------------------------------------------------
 * IExportChanges interface
 *
 * Exports data (content and hierarchy)
 *
 */
interface IExportChanges extends IChanges {
    /**
     * Configures additional parameters used for content synchronization
     *
     * // TODO this might be refactored into an own class, as more options will be necessary
     * @param string        $mclass
     * @param int           $restrict       FilterType
     * @param int           $truncation     bytes
     *
     * @access public
     * @return boolean
     */
    public function ConfigContentParameters($mclass, $restrict, $truncation);

    /**
     * Sets the importer where the exporter will sent its changes to
     * This exporter should also be ready to accept calls after this
     *
     * @param object        &$importer      Implementation of IImportChanges
     *
     * @access public
     * @return boolean
     */
    public function InitializeExporter(&$importer);

    /**
     * Returns the amount of changes to be exported
     *
     * @access public
     * @return int
     */
    public function GetChangeCount();

    /**
     * Synchronizes a change to the configured importer
     *
     * @access public
     * @return array        with status information
     */
    public function Synchronize();
}
?>