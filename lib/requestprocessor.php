<?php
/***********************************************
* File      :   requestprocessor.php
* Project   :   Z-Push
* Descr     :   This file contains the handlers for
*               the different commands.
*               The request handlers are optimised
*               so that as little as possible
*               data is kept-in-memory, and all
*               output data is directly streamed
*               to the client, while also streaming
*               input data from the client.
*
* Created   :   12.08.2011
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

class RequestProcessor {
    static private $backend;
    static private $deviceManager;
    static private $decoder;
    static private $encoder;
    static private $userIsAuthenticated;

    /**
     * Authenticates the remote user
     * The sent HTTP authentication information is used to on Backend->Logon().
     * As second stept the GET-User verified by Backend->Setup() for permission check
     * Request::getGETUser() is usually the same as the Request::getAuthUser().
     * If the GETUser is different from the AuthUser, the AuthUser MUST HAVE admin
     * permissions on GETUsers data store. Only then the Setup() will be sucessfull.
     * This allows the user 'john' to do operations as user 'joe' if he has sufficient privileges.
     *
     * @access public
     * @return
     * @throws AuthenticationRequiredException
     */
    static public function Authenticate() {
        self::$userIsAuthenticated = false;

        $backend = ZPush::GetBackend();
        if($backend->Logon(Request::getAuthUser(), Request::getAuthDomain(), Request::getAuthPassword()) == false)
            throw new AuthenticationRequiredException("Access denied. Username or password incorrect");

        // mark this request as "authenticated"
        self::$userIsAuthenticated = true;

        // check Auth-User's permissions on GETUser's store
        if($backend->Setup(Request::getGETUser(), true) == false)
            throw new AuthenticationRequiredException(sprintf("Not enough privileges of '%s' to setup for user '%s': Permission denied", Request::getAuthUser(), Request::getGETUser()));
    }

    /**
     * Indicates if the user was "authenticated"
     *
     * @access public
     * @return boolean
     */
    static public function isUserAuthenticated() {
        if (!isset(self::$userIsAuthenticated))
            return false;
        return self::$userIsAuthenticated;
    }


    /**
     * Initialize the RequestProcessor
     *
     * @access public
     * @return
     */
    static public function Initialize() {
        self::$backend = ZPush::GetBackend();
        self::$deviceManager = ZPush::GetDeviceManager();

        if (!ZPush::CommandNeedsPlainInput(Request::getCommand()))
            self::$decoder = new WBXMLDecoder(Request::getInputStream());

        self::$encoder = new WBXMLEncoder(Request::getOutputStream());
    }

    /**
     * Processes a command sent from the mobile
     *
     * @access public
     * @return boolean
     */
    static public function HandleRequest() {
        switch(Request::getCommand()) {
            case 'Sync':
                $status = self::HandleSync();
                break;
            case 'SendMail':
                $status = self::HandleSendMail();
                break;
            case 'SmartForward':
                $status = self::HandleSmartForward();
                break;
            case 'SmartReply':
                $status = self::HandleSmartReply();
                break;
            case 'GetAttachment':
                $status = self::HandleGetAttachment();
                break;
            case 'GetHierarchy':
                $status = self::HandleGetHierarchy();
                break;
            case 'CreateCollection':
                $status = self::HandleCreateCollection();
                break;
            case 'DeleteCollection':
                $status = self::HandleDeleteCollection();
                break;
            case 'MoveCollection':
                $status = self::HandleMoveCollection();
                break;
            case 'FolderSync':
                $status = self::HandleFolderSync();
                break;
            case 'FolderCreate':
            case 'FolderUpdate':
            case 'FolderDelete':
                $status = self::HandleFolderChange();
                break;
            case 'MoveItems':
                $status = self::HandleMoveItems();
                break;
            case 'GetItemEstimate':
                $status = self::HandleGetItemEstimate();
                break;
            case 'MeetingResponse':
                $status = self::HandleMeetingResponse();
                break;
            case 'Notify': // Used for sms-based notifications (pushmail)
                $status = self::HandleNotify();
                break;
            case 'Ping': // Used for http-based notifications (pushmail)
                $status = self::HandlePing();
                break;
            case 'Provision':
                $status = (PROVISIONING === true) ? self::HandleProvision() : false;
                break;
            case 'Search':
                $status = self::HandleSearch();
                break;

            // TODO implement ResolveRecipients and ValidateCert
            case 'ResolveRecipients':
            case 'ValidateCert':
            default:
                throw new FatalNotImplementedException(sprintf("RequestProcessor::HandleRequest(): Command '%s' is not implemented", Request::getCommand()));
                break;
        }

        return $status;
    }


    /**----------------------------------------------------------------------------------------------------------
     * Command implementations
     */

    /**
     * Moves a message
     *
     * @access private
     * @return boolean
     */
    static private function HandleMoveItems() {
        if(!self::$decoder->getElementStartTag(SYNC_MOVE_MOVES))
            return false;

        $moves = array();
        while(self::$decoder->getElementStartTag(SYNC_MOVE_MOVE)) {
            $move = array();
            if(self::$decoder->getElementStartTag(SYNC_MOVE_SRCMSGID)) {
                $move["srcmsgid"] = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag())
                    break;
            }
            if(self::$decoder->getElementStartTag(SYNC_MOVE_SRCFLDID)) {
                $move["srcfldid"] = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag())
                    break;
            }
            if(self::$decoder->getElementStartTag(SYNC_MOVE_DSTFLDID)) {
                $move["dstfldid"] = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag())
                    break;
            }
            array_push($moves, $move);

            if(!self::$decoder->getElementEndTag())
                return false;
        }

        if(!self::$decoder->getElementEndTag())
            return false;

        self::$encoder->StartWBXML();

        self::$encoder->startTag(SYNC_MOVE_MOVES);

        foreach($moves as $move) {
            self::$encoder->startTag(SYNC_MOVE_RESPONSE);
            self::$encoder->startTag(SYNC_MOVE_SRCMSGID);
            self::$encoder->content($move["srcmsgid"]);
            self::$encoder->endTag();

            $status = SYNC_MOVEITEMSSTATUS_SUCCESS;
            try {
                $importer = self::$backend->GetImporter($move["srcfldid"]);
                if ($importer === false)
                    throw new StatusException(sprintf("HandleMoveItems() could not get an importer for folder id '%s'", $move["srcfldid"]), SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID);

                $result = $importer->ImportMessageMove($move["srcmsgid"], $move["dstfldid"]);
                // We discard the importer state for now.
            }
            catch (StatusException $stex) {
                if ($stex->getCode() == SYNC_STATUS_FOLDERHIERARCHYCHANGED) // same as SYNC_FSSTATUS_CODEUNKNOWN
                    $status = SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID;
                else
                    $status = $stex->getCode();
            }

            self::$encoder->startTag(SYNC_MOVE_STATUS);
            self::$encoder->content($status);
            self::$encoder->endTag();

            self::$encoder->startTag(SYNC_MOVE_DSTMSGID);
            self::$encoder->content(is_string($result)?$result:$move["srcmsgid"]);
            self::$encoder->endTag();
            self::$encoder->endTag();
        }

        self::$encoder->endTag();
        return true;
    }

    /**
     * Handles a 'HandleNotify' command used to inform about changes via SMS
     * no functionality implemented
     *
     * @access private
     * @return boolean
     */
    static private function HandleNotify() {
        if(!self::$decoder->getElementStartTag(SYNC_AIRNOTIFY_NOTIFY))
            return false;

        if(!self::$decoder->getElementStartTag(SYNC_AIRNOTIFY_DEVICEINFO))
            return false;

        if(!self::$decoder->getElementEndTag())
            return false;

        if(!self::$decoder->getElementEndTag())
            return false;

        self::$encoder->StartWBXML();

        self::$encoder->startTag(SYNC_AIRNOTIFY_NOTIFY);
        {
            self::$encoder->startTag(SYNC_AIRNOTIFY_STATUS);
            self::$encoder->content(1);
            self::$encoder->endTag();

            self::$encoder->startTag(SYNC_AIRNOTIFY_VALIDCARRIERPROFILES);
            self::$encoder->endTag();
        }
        self::$encoder->endTag();

        return true;
    }

    /**
     * Handles a 'GetHierarchy' command
     * simply returns current hierarchy of all folders
     *
     * @access private
     * @return boolean
     */
    static private function HandleGetHierarchy() {
        try {
            $folders = self::$backend->GetHierarchy();
            if (!$folders || empty($folders))
                throw new StatusException("GetHierarchy() did not return any data.");
        }
        catch (StatusException $ex) {
            return false;
        }

        self::$encoder->StartWBXML();
        self::$encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERS);
        foreach ($folders as $folder) {
            self::$encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDER);
            $folder->encode(self::$encoder);
            self::$encoder->endTag();
        }
        self::$encoder->endTag();

        // save hierarchy for upcoming syncing
        return self::$deviceManager->InitializeFolderCache($folders);

    }

    /**
     * Handles a 'FolderSync' command
     * receives folder updates, and sends reply with hierarchy changes on the server
     *
     * @access private
     * @return boolean
     */
    static private function HandleFolderSync() {
        // Maps serverid -> clientid for items that are received from the PIM
        $map = array();

        // Parse input
        if(!self::$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_FOLDERSYNC))
            return false;

        if(!self::$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_SYNCKEY))
            return false;

        $synckey = self::$decoder->getElementContent();

        if(!self::$decoder->getElementEndTag())
            return false;

        $status = SYNC_FSSTATUS_SUCCESS;
        try {
            $syncstate = self::$deviceManager->GetSyncState($synckey);
        }
        catch (StateNotFoundException $snfex) {
                $status = SYNC_FSSTATUS_SYNCKEYERROR;
        }

        // The ChangesWrapper caches all imports in-memory, so we can send a change count
        // before sending the actual data.
        // the HierarchyCache is notified and the changes from the PIM are transmitted to the actual backend
        $changesMem = self::$deviceManager->GetHierarchyChangesWrapper();

        // We will be saving the sync state under 'newsynckey'
        $newsynckey = self::$deviceManager->GetNewSyncKey($synckey);

        // the hierarchyCache should now fully be initialized - check for changes in the additional folders
        $changesMem->Config(ZPush::GetAdditionalSyncFolders());

        // process incoming changes
        if(self::$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_CHANGES)) {
            // Ignore <Count> if present
            if(self::$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_COUNT)) {
                self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag())
                    return false;
            }

            // Process the changes (either <Add>, <Modify>, or <Remove>)
            $element = self::$decoder->getElement();

            if($element[EN_TYPE] != EN_TYPE_STARTTAG)
                return false;

            $importer = false;
            while(1) {
                $folder = new SyncFolder();
                if(!$folder->decode(self::$decoder))
                    break;

                try {
                    if ($status == SYNC_FSSTATUS_SUCCESS && !$importer) {
                        // Configure the backends importer with last state
                        $importer = self::$backend->GetImporter();
                        $importer->Config($syncstate);
                        // the messages from the PIM will be forwarded to the backend
                        $changesMem->forwardImporter($importer);
                    }

                    if ($status == SYNC_FSSTATUS_SUCCESS) {
                        switch($element[EN_TAG]) {
                            case SYNC_ADD:
                            case SYNC_MODIFY:
                                $serverid = $changesMem->ImportFolderChange($folder);
                                break;
                            case SYNC_REMOVE:
                                $serverid = $changesMem->ImportFolderDeletion($folder);
                                break;
                        }

                        // TODO what does $map??
                        if($serverid)
                            $map[$serverid] = $folder->clientid;
                    }
                    else
                        ZLog::Write(LOGLEVEL_WARN, sprintf("Request->HandleFolderSync(): ignoring incoming folderchange for folder '%s' as status indicates problem.", $folder->displayname));
                }
                catch (StatusException $stex) {
                   $status = $stex->getCode();
                }
            }

            if(!self::$decoder->getElementEndTag())
                return false;
        }

        if(!self::$decoder->getElementEndTag())
            return false;

        // We have processed incoming foldersync requests, now send the PIM
        // our changes

        // Output our WBXML reply now
        self::$encoder->StartWBXML();

        self::$encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERSYNC);
        {
            if ($status == SYNC_FSSTATUS_SUCCESS) {
                try {
                    // do nothing if this is an invalid device id (like the 'validate' Androids internal client sends)
                    if (!Request::isValidDeviceID())
                        throw new StatusException(sprintf("Request::isValidDeviceID() indicated that '%s' is not a valid device id", Request::getDeviceID()), SYNC_FSSTATUS_SERVERERROR);

                    // Changes from backend are sent to the MemImporter and processed for the HierarchyCache.
                    // The state which is saved is from the backend, as the MemImporter is only a proxy.
                    $exporter = self::$backend->GetExporter();

                    $exporter->Config($syncstate);
                    $exporter->InitializeExporter($changesMem);

                    // Stream all changes to the ImportExportChangesMem
                    while(is_array($exporter->Synchronize()));

                    // get the new state from the backend
                    $newsyncstate = (isset($exporter))?$exporter->GetState():"";
                }
                catch (StatusException $stex) {
                   $status = $stex->getCode();
                }
            }

            self::$encoder->startTag(SYNC_FOLDERHIERARCHY_STATUS);
            self::$encoder->content($status);
            self::$encoder->endTag();

            if ($status == SYNC_FSSTATUS_SUCCESS) {
                self::$encoder->startTag(SYNC_FOLDERHIERARCHY_SYNCKEY);
                $synckey = ($changesMem->isStateChanged()) ? $newsynckey : $synckey;
                self::$encoder->content($synckey);
                self::$encoder->endTag();

                // Stream folders directly to the PDA
                $streamimporter = new ImportChangesStream(self::$encoder, false);
                $changesMem->InitializeExporter($streamimporter);
                $changeCount = $changesMem->GetChangeCount();

                self::$encoder->startTag(SYNC_FOLDERHIERARCHY_CHANGES);
                {
                    self::$encoder->startTag(SYNC_FOLDERHIERARCHY_COUNT);
                    self::$encoder->content($changeCount);
                    self::$encoder->endTag();
                    while($changesMem->Synchronize());
                }
                self::$encoder->endTag();

                // everything fine, save the sync state for the next time
                if ($synckey == $newsynckey)
                    self::$deviceManager->SetSyncState($newsynckey, $newsyncstate);
            }
        }
        self::$encoder->endTag();

        return true;
    }

    /**
     * Performs the synchronization of messages
     *
     * @access private
     * @return boolean
     */
    static private function HandleSync() {
        // Contains all containers requested
        $collections = array();

        $status = SYNC_STATUS_SUCCESS;

        // Start decode
        if(!self::$decoder->getElementStartTag(SYNC_SYNCHRONIZE))
            return false;

        // AS 1.0 sends version information in WBXML
        if(self::$decoder->getElementStartTag(SYNC_VERSION)) {
            $sync_version = self::$decoder->getElementContent();
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("WBXML sync version: '%s'", $sync_version));
            if(!self::$decoder->getElementEndTag())
                return false;
        }

        if(!self::$decoder->getElementStartTag(SYNC_FOLDERS))
            return false;

        while(self::$decoder->getElementStartTag(SYNC_FOLDER)) {
            $collection = array();
            $collection["clientids"] = array();
            $collection["modifyids"] = array();
            $collection["removeids"] = array();
            $collection["fetchids"] = array();
            $collection["statusids"] = array();

            //for AS versions < 2.5
            if(self::$decoder->getElementStartTag(SYNC_FOLDERTYPE)) {
                $collection["class"] = self::$decoder->getElementContent();
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("Sync folder: '%s'", $collection["class"]));

                if(!self::$decoder->getElementEndTag())
                    return false;
            }

            if(!self::$decoder->getElementStartTag(SYNC_SYNCKEY))
                return false;

            $collection["synckey"] = self::$decoder->getElementContent();

            if(!self::$decoder->getElementEndTag())
                return false;

            if(self::$decoder->getElementStartTag(SYNC_FOLDERID)) {
                $collection["collectionid"] = self::$decoder->getElementContent();

                if(!self::$decoder->getElementEndTag())
                    return false;
            }

            // Get class for as versions >= 12.0
            if (!isset($collection["class"])) {
                try {
                    $collection["class"] = self::$deviceManager->GetFolderClassFromCacheByID($collection["collectionid"]);
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("GetFolderClassFromCacheByID from Device Manager: '%s' for id:'%s'", $collection["class"], $collection["collectionid"]));
                }
                catch (NoHierarchyCacheAvailableException $nhca) {
                    $status = SYNC_STATUS_FOLDERHIERARCHYCHANGED;
                    self::$deviceManager->ForceFullResync();
                }
            }

            // SUPPORTED properties
            if(self::$decoder->getElementStartTag(SYNC_SUPPORTED)) {
                $supfields = array();
                while(1) {
                    $el = self::$decoder->getElement();

                    if($el[EN_TYPE] == EN_TYPE_ENDTAG)
                        break;
                    else
                        $supfields[] = $el[EN_TAG];
                }
                self::$deviceManager->SetSupportedFields($collection["collectionid"], $supfields);
            }

            if(self::$decoder->getElementStartTag(SYNC_DELETESASMOVES))
                $collection["deletesasmoves"] = true;

            // Get changes can be an empty tag as well as have value
            // dw2412 contribution start
            if(self::$decoder->getElementStartTag(SYNC_GETCHANGES)) {
                //TODO do not send server changes if $collection["getchanges"] is false
                if (($collection["getchanges"] = self::$decoder->getElementContent()) !== false) {
                    if(!self::$decoder->getElementEndTag()) {
                        return false;
                    }
                }
                else {
                    $collection["getchanges"] = true;
                }
            }
            // dw2412 contribution end

            if(self::$decoder->getElementStartTag(SYNC_WINDOWSIZE)) {
                $collection["windowsize"] = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag())
                    return false;
            }

            // Save all OPTIONS into a ContentParameters object
            $collection["cpo"] = new ContentParameters();
            $collection["cpo"]->SetContentClass($collection["class"]);
            $collection["cpo"]->SetTruncation(SYNC_TRUNCATION_ALL);

            if(self::$decoder->getElementStartTag(SYNC_OPTIONS)) {
                while(1) {
                    if(self::$decoder->getElementStartTag(SYNC_FILTERTYPE)) {
                        $collection["cpo"]->SetFilterType(self::$decoder->getElementContent());
                        if(!self::$decoder->getElementEndTag())
                            return false;
                    }
                    if(self::$decoder->getElementStartTag(SYNC_TRUNCATION)) {
                        $collection["cpo"]->SetTruncation(self::$decoder->getElementContent());
                        if(!self::$decoder->getElementEndTag())
                            return false;
                    }
                    if(self::$decoder->getElementStartTag(SYNC_RTFTRUNCATION)) {
                        $collection["cpo"]->SetRTFTruncation(self::$decoder->getElementContent());
                        if(!self::$decoder->getElementEndTag())
                            return false;
                    }

                    if(self::$decoder->getElementStartTag(SYNC_MIMESUPPORT)) {
                        $collection["cpo"]->SetMimeSupport(self::$decoder->getElementContent());
                        if(!self::$decoder->getElementEndTag())
                            return false;
                    }

                    if(self::$decoder->getElementStartTag(SYNC_MIMETRUNCATION)) {
                        $collection["cpo"]->SetMimeTruncation(self::$decoder->getElementContent());
                        if(!self::$decoder->getElementEndTag())
                            return false;
                    }

                    if(self::$decoder->getElementStartTag(SYNC_CONFLICT)) {
                        $collection["conflict"] = self::$decoder->getElementContent();
                        if(!self::$decoder->getElementEndTag())
                            return false;
                    }

                    while (self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_BODYPREFERENCE)) {
                        if(self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_TYPE)) {
                            $bptype = self::$decoder->getElementContent();
                            $collection["cpo"]->BodyPreference($bptype);
                            if(!self::$decoder->getElementEndTag()) {
                                return false;
                            }
                        }

                        if(self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_TRUNCATIONSIZE)) {
                            $collection["cpo"]->BodyPreference($bptype)->SetTruncationSize(self::$decoder->getElementContent());
                            if(!self::$decoder->getElementEndTag())
                                return false;
                        }

                        if(self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_ALLORNONE)) {
                            $collection["cpo"]->BodyPreference($bptype)->SetAllOrNone(self::$decoder->getElementContent());
                            if(!self::$decoder->getElementEndTag())
                                return false;
                        }

                        if(self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_PREVIEW)) {
                            $collection["cpo"]->BodyPreference($bptype)->SetPreview(self::$decoder->getElementContent());
                            if(!self::$decoder->getElementEndTag())
                                return false;
                        }

                        if(!self::$decoder->getElementEndTag())
                            return false;
                    }

                    $e = self::$decoder->peek();
                    if($e[EN_TYPE] == EN_TYPE_ENDTAG) {
                        self::$decoder->getElementEndTag();
                        break;
                    }
                }
            }

            // limit items to be synchronized to the mobiles if configured
            if (defined('SYNC_FILTERTIME_MAX') && SYNC_FILTERTIME_MAX > SYNC_FILTERTYPE_ALL &&
                ($collection["cpo"]->GetFilterType() === false || $collection["cpo"]->GetFilterType() > SYNC_FILTERTIME_MAX)) {
                    $collection["cpo"]->SetFilterType(SYNC_FILTERTIME_MAX);
            }

            // compatibility mode AS 1.0 - get folderid which was sent during GetHierarchy()
            if (!isset($collection["collectionid"])) {
                $collection["collectionid"] = self::$deviceManager->GetFolderIdFromCacheByClass($collection["class"]);
            }

            // set default conflict behavior from config if the device doesn't send a conflict resolution parameter
            if (!isset($collection["conflict"])) {
                $collection["conflict"] = SYNC_CONFLICT_DEFAULT;
            }

            // compatibility mode - set windowsize if the client doesn't send it
            if (!isset($collection["windowsize"])) {
                $collection["windowsize"] = self::$deviceManager->GetWindowSize();
            }

            // Get our sync state for this collection
            try {
                $collection["syncstate"] = self::$deviceManager->GetSyncState($collection["synckey"]);

                // if this is an additional folder the backend has to be setup correctly
                if (!self::$backend->Setup(ZPush::GetAdditionalSyncFolderStore($collection["collectionid"])))
                    throw new StatusException(sprintf("HandleSync() could not Setup() the backend for folder id '%s'", $collection["collectionid"]), SYNC_STATUS_FOLDERHIERARCHYCHANGED);
            }
            catch (StateNotFoundException $snfex) {
                $status = SYNC_STATUS_INVALIDSYNCKEY;
            }
            catch (StatusException $stex) {
               $status = $stex->getCode();
            }

            if(self::$decoder->getElementStartTag(SYNC_PERFORM)) {
                if ($status == SYNC_STATUS_SUCCESS) {
                    try {
                        // Configure importer with last state
                        $importer = self::$backend->GetImporter($collection["collectionid"]);

                        // if something goes wrong, ask the mobile to resync the hierarchy
                        if ($importer === false)
                            throw new StatusException(sprintf("HandleSync() could not get an importer for folder id '%s'", $collection["collectionid"]), SYNC_STATUS_FOLDERHIERARCHYCHANGED);

                        $importer->Config($collection["syncstate"], $collection["conflict"]);
                    }
                    catch (StatusException $stex) {
                       $status = $stex->getCode();
                    }
                }

                $nchanges = 0;
                while(1) {
                    // ADD, MODIFY, REMOVE or FETCH
                    $element = self::$decoder->getElement();

                    if($element[EN_TYPE] != EN_TYPE_STARTTAG) {
                        self::$decoder->ungetElement($element);
                        break;
                    }

                    // before importing the first change, load potential conflicts
                    // for the current state
                    if ($status == SYNC_STATUS_SUCCESS && $nchanges == 0)
                        $importer->LoadConflicts($collection["cpo"], $collection["syncstate"]);

                    if ($status == SYNC_STATUS_SUCCESS)
                        $nchanges++;

                    if(self::$decoder->getElementStartTag(SYNC_SERVERENTRYID)) {
                        $serverid = self::$decoder->getElementContent();

                        if(!self::$decoder->getElementEndTag()) // end serverid
                            return false;
                    }
                    else
                        $serverid = false;

                    if(self::$decoder->getElementStartTag(SYNC_CLIENTENTRYID)) {
                        $clientid = self::$decoder->getElementContent();

                        if(!self::$decoder->getElementEndTag()) // end clientid
                            return false;
                    }
                    else
                        $clientid = false;

                    // Get the SyncMessage if sent
                    if(self::$decoder->getElementStartTag(SYNC_DATA)) {
                        $message = ZPush::getSyncObjectFromFolderClass($collection["class"]);
                        $message->decode(self::$decoder);
                        // set Ghosted fields
                        $message->emptySupported(self::$deviceManager->GetSupportedFields($collection["collectionid"]));
                        if(!self::$decoder->getElementEndTag()) // end applicationdata
                            return false;
                    }

                    if ($status != SYNC_STATUS_SUCCESS) {
                        ZLog::Write(LOGLEVEL_WARN, "Ignored incoming change, global status indicates problem.");
                        continue;
                    }

                    switch($element[EN_TAG]) {
                        case SYNC_MODIFY:
                            try {
                                $collection["modifyids"][] = $serverid;

                                if(isset($message->read)) // Currently, 'read' is only sent by the PDA when it is ONLY setting the read flag.
                                    $importer->ImportMessageReadFlag($serverid, $message->read);
                                else
                                    $importer->ImportMessageChange($serverid, $message);

                                $collection["statusids"][$serverid] = SYNC_STATUS_SUCCESS;
                            }
                            catch (StatusException $stex) {
                                $collection["statusids"][$serverid] = $stex->getCode();
                            }

                            break;
                        case SYNC_ADD:
                            try {
                                $collection["clientids"][$clientid] = false;
                                $collection["clientids"][$clientid] = $importer->ImportMessageChange(false, $message);
                                $collection["statusids"][$clientid] = SYNC_STATUS_SUCCESS;
                            }
                            catch (StatusException $stex) {
                               $collection["statusids"][$clientid] = $stex->getCode();
                            }
                            break;
                        case SYNC_REMOVE:
                            try {
                                $collection["removeids"][] = $serverid;
                                // if message deletions are to be moved, move them
                                if(isset($collection["deletesasmoves"])) {
                                    $folderid = self::$backend->GetWasteBasket();

                                    if($folderid) {
                                        $importer->ImportMessageMove($serverid, $folderid);
                                        $collection["statusids"][$serverid] = SYNC_STATUS_SUCCESS;
                                        break;
                                    }
                                    else
                                        ZLog::Write(LOGLEVEL_WARN, "Message should be moved to WasteBasket, but the Backend did not return a destination ID. Message is hard deleted now!");
                                }

                                $importer->ImportMessageDeletion($serverid);
                                $collection["statusids"][$serverid] = SYNC_STATUS_SUCCESS;
                            }
                            catch (StatusException $stex) {
                               $collection["statusids"][$serverid] = $stex->getCode();
                            }
                            break;
                        case SYNC_FETCH:
                            array_push($collection["fetchids"], $serverid);
                            break;
                    }

                    if(!self::$decoder->getElementEndTag()) // end add/change/delete/move
                        return false;
                }

                if ($status == SYNC_STATUS_SUCCESS) {
                    ZLog::Write(LOGLEVEL_INFO, sprintf("Processed '%d' incoming changes", $nchanges));
                    try {
                        // Save the updated state, which is used for the exporter later
                        $collection["syncstate"] = $importer->GetState();
                    }
                    catch (StatusException $stex) {
                       $status = $stex->getCode();
                    }
                }

                if(!self::$decoder->getElementEndTag()) // end commands
                    return false;
            }

            if(!self::$decoder->getElementEndTag()) // end collections
                return false;

            array_push($collections, $collection);
        }

        if(!self::$decoder->getElementEndTag()) // end collections
            return false;

        if(!self::$decoder->getElementEndTag()) // end sync
            return false;

        // Start the output
        self::$encoder->startWBXML();
        self::$encoder->startTag(SYNC_SYNCHRONIZE);
        {
            self::$encoder->startTag(SYNC_FOLDERS);
            {
                foreach($collections as $collection) {
                    // initialize exporter to get changecount
                    $changecount = 0;
                    if($status == SYNC_STATUS_SUCCESS && isset($collection["getchanges"])) {
                        try {
                            // Use the state from the importer, as changes may have already happened
                            $exporter = self::$backend->GetExporter($collection["collectionid"]);

                            if ($exporter === false)
                                throw new StatusException(sprintf("HandleSync() could not get an exporter for folder id '%s'", $collection["collectionid"]), SYNC_STATUS_FOLDERHIERARCHYCHANGED);

                            // Stream the messages directly to the PDA
                            $streamimporter = new ImportChangesStream(self::$encoder, ZPush::getSyncObjectFromFolderClass($collection["class"]));

                            $exporter->Config($collection["syncstate"]);
                            $exporter->ConfigContentParameters($collection["cpo"]);
                            $exporter->InitializeExporter($streamimporter);

                            $changecount = $exporter->GetChangeCount();
                        }
                        catch (StatusException $stex) {
                           $status = $stex->getCode();
                        }
                    }

                    // Get a new sync key to output to the client if any changes have been send or will are available
                    if (!empty($collection["modifyids"]) ||
                        !empty($collection["clientids"]) ||
                        !empty($collection["removeids"]) ||
                        $changecount > 0 || $collection["synckey"] == "0")
                            $collection["newsynckey"] = self::$deviceManager->GetNewSyncKey($collection["synckey"]);

                    self::$encoder->startTag(SYNC_FOLDER);

                    if(isset($collection["class"])) {
                        self::$encoder->startTag(SYNC_FOLDERTYPE);
                            self::$encoder->content($collection["class"]);
                        self::$encoder->endTag();
                    }

                    self::$encoder->startTag(SYNC_SYNCKEY);
                    if(isset($collection["newsynckey"]))
                        self::$encoder->content($collection["newsynckey"]);
                    else
                        self::$encoder->content($collection["synckey"]);
                    self::$encoder->endTag();

                    self::$encoder->startTag(SYNC_FOLDERID);
                        self::$encoder->content($collection["collectionid"]);
                    self::$encoder->endTag();

                    self::$encoder->startTag(SYNC_STATUS);
                        self::$encoder->content($status);
                    self::$encoder->endTag();

                    // Output IDs and status for incoming items & requests
                    if($status == SYNC_STATUS_SUCCESS &&
                        !empty($collection["clientids"]) ||
                        !empty($collection["modifyids"]) ||
                        !empty($collection["removeids"]) ||
                        !empty($collection["fetchids"]) ) {

                        self::$encoder->startTag(SYNC_REPLIES);
                        // output result of all new incoming items
                        foreach($collection["clientids"] as $clientid => $serverid) {
                            self::$encoder->startTag(SYNC_ADD);
                                self::$encoder->startTag(SYNC_CLIENTENTRYID);
                                    self::$encoder->content($clientid);
                                self::$encoder->endTag();
                                if ($serverid) {
                                    self::$encoder->startTag(SYNC_SERVERENTRYID);
                                        self::$encoder->content($serverid);
                                    self::$encoder->endTag();
                                }
                                self::$encoder->startTag(SYNC_STATUS);
                                    self::$encoder->content((isset($collection["statusids"][$clientid])?$collection["statusids"][$clientid]:SYNC_STATUS_CLIENTSERVERCONVERSATIONERROR));
                                self::$encoder->endTag();
                            self::$encoder->endTag();
                        }

                        // loop through modify operations which were not a success, send status
                        foreach($collection["modifyids"] as $serverid) {
                            if (isset($collection["statusids"][$serverid]) && $collection["statusids"][$serverid] !== SYNC_STATUS_SUCCESS) {
                                self::$encoder->startTag(SYNC_MODIFY);
                                    self::$encoder->startTag(SYNC_SERVERENTRYID);
                                        self::$encoder->content($serverid);
                                    self::$encoder->endTag();
                                    self::$encoder->startTag(SYNC_STATUS);
                                        self::$encoder->content($collection["statusids"][$serverid]);
                                    self::$encoder->endTag();
                                self::$encoder->endTag();
                            }
                        }

                        // loop through remove operations which were not a success, send status
                        foreach($collection["removeids"] as $serverid) {
                            if (isset($collection["statusids"][$serverid]) && $collection["statusids"][$serverid] !== SYNC_STATUS_SUCCESS) {
                                self::$encoder->startTag(SYNC_REMOVE);
                                    self::$encoder->startTag(SYNC_SERVERENTRYID);
                                        self::$encoder->content($serverid);
                                    self::$encoder->endTag();
                                    self::$encoder->startTag(SYNC_STATUS);
                                        self::$encoder->content($collection["statusids"][$clientid]);
                                    self::$encoder->endTag();
                                self::$encoder->endTag();
                            }
                        }

                        foreach($collection["fetchids"] as $id) {
                            try {
                                $data = self::$backend->Fetch($collection["collectionid"], $id, $collection["cpo"]);
                                $fetchstatus = SYNC_STATUS_SUCCESS;
                            }
                            catch (StatusException $stex) {
                               $fetchstatus = $stex->getCode();
                            }

                            self::$encoder->startTag(SYNC_FETCH);
                                self::$encoder->startTag(SYNC_SERVERENTRYID);
                                    self::$encoder->content($id);
                                self::$encoder->endTag();

                                self::$encoder->startTag(SYNC_STATUS);
                                    self::$encoder->content($fetchstatus);
                                self::$encoder->endTag();

                                if($data !== false && $status == SYNC_STATUS_SUCCESS) {
                                    self::$encoder->startTag(SYNC_DATA);
                                        $data->encode(self::$encoder);
                                    self::$encoder->endTag();
                                }
                                else
                                    ZLog::Write(LOGLEVEL_WARN, sprintf("Unable to Fetch '%s'", $id));
                            self::$encoder->endTag();

                        }
                        self::$encoder->endTag();
                    }

                    if($status == SYNC_STATUS_SUCCESS && isset($collection["getchanges"])) {
                        // exporter already intialized

                        if($changecount > $collection["windowsize"]) {
                            self::$encoder->startTag(SYNC_MOREAVAILABLE, false, true);
                        }

                        // Output message changes per folder
                        self::$encoder->startTag(SYNC_PERFORM);

                        $n = 0;
                        while(1) {
                            $progress = $exporter->Synchronize();
                            if(!is_array($progress))
                                break;
                            $n++;

                            if($n >= $collection["windowsize"]) {
                                ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync():Exported maxItems of messages: %d / %d", $collection["windowsize"], $changecount));
                                break;
                            }

                        }
                        self::$encoder->endTag();
                    }

                    self::$encoder->endTag();

                    // Save the sync state for the next time
                    if(isset($collection["newsynckey"])) {
                        if (isset($exporter) && $exporter)
                            $state = $exporter->GetState();

                        // nothing exported, but possible imported
                        else if (isset($importer) && $importer)
                            $state = $importer->GetState();

                        // if a new request without state information (hierarchy) save an empty state
                        else if ($collection["synckey"] == "0")
                            $state = "";

                        if (isset($state) && $status == SYNC_STATUS_SUCCESS)
                            self::$deviceManager->SetSyncState($collection["newsynckey"], $state, $collection["collectionid"]);
                        else
                            ZLog::Write(LOGLEVEL_ERROR, sprintf("HandleSync(): error saving '%s' - no state information available", $collection["newsynckey"]));
                    }
                }
            }
            self::$encoder->endTag();
        }
        self::$encoder->endTag();

        return true;
    }

    /**
     * Returns an estimation of how many items will be synchronized during the next sync
     * This is used to show something in the progress bar
     *
     * @access private
     * @return boolean
     */
    static private function HandleGetItemEstimate() {
        $collections = array();

        if(!self::$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_GETITEMESTIMATE))
            return false;

        if(!self::$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERS))
            return false;

        while(self::$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDER)) {
            $collection = array();

            if(!self::$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERTYPE))
                return false;

            $class = self::$decoder->getElementContent();

            if(!self::$decoder->getElementEndTag())
                return false;

            if(self::$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERID)) {
                $collectionid = self::$decoder->getElementContent();

                if(!self::$decoder->getElementEndTag())
                    return false;
            }

            if(!self::$decoder->getElementStartTag(SYNC_FILTERTYPE))
                return false;

            $filtertype = self::$decoder->getElementContent();

            if(!self::$decoder->getElementEndTag())
                return false;

            if(!self::$decoder->getElementStartTag(SYNC_SYNCKEY))
                return false;

            $synckey = self::$decoder->getElementContent();

            if(!self::$decoder->getElementEndTag())
                return false;
            if(!self::$decoder->getElementEndTag())
                return false;

            // compatibility mode AS 1.0 - get folderid which was sent during GetHierarchy()
            if (!isset($collectionid)) {
                $collectionid = self::$deviceManager->GetFolderIdFromCacheByClass($class);
            }

            $collection = array();
            $collection["synckey"] = $synckey;
            $collection["class"] = $class;
            $collection["cpo"] = new ContentParameters();
            $collection["cpo"]->SetContentClass($class);
            $collection["cpo"]->SetFilterType($filtertype);
            $collection["collectionid"] = $collectionid;

            array_push($collections, $collection);
        }

        self::$encoder->startWBXML();

        self::$encoder->startTag(SYNC_GETITEMESTIMATE_GETITEMESTIMATE);
        {
            foreach($collections as $collection) {
                self::$encoder->startTag(SYNC_GETITEMESTIMATE_RESPONSE);
                {

                    $changecount = 0;
                    $status = SYNC_GETITEMESTSTATUS_SUCCESS;
                    try {
                        $exporter = self::$backend->GetExporter($collection["collectionid"]);

                        if ($exporter === false)
                            throw new StatusException(sprintf("HandleGetItemEstimate(): no exporter available, for id '%s'", $collection["collectionid"]), SYNC_STATUS_FOLDERHIERARCHYCHANGED);

                        $importer = new ChangesMemoryWrapper();
                        $syncstate = self::$deviceManager->GetSyncState($collection["synckey"]);

                        $exporter->Config($syncstate);
                        $exporter->ConfigContentParameters($collection["cpo"]);
                        $exporter->InitializeExporter($importer);
                        $changecount = $exporter->GetChangeCount();
                    }
                    catch (StateNotFoundException $snf) {
                        $status = SYNC_GETITEMESTSTATUS_SYNCKKEYINVALID;
                    }
                    catch (StateInvalidException $snf) {
                        $status = SYNC_GETITEMESTSTATUS_SYNCKKEYINVALID;
                    }
                    catch (StatusException $stex) {
                        // this status is thrown if the exporter can not be initialized, also in the exporters constructor
                        if ($stex->getCode() == SYNC_STATUS_FOLDERHIERARCHYCHANGED)
                            $status = SYNC_GETITEMESTSTATUS_SYNCSTATENOTPRIMED;
                        else
                            $status = SYNC_GETITEMESTSTATUS_COLLECTIONINVALID;
                    }

                    self::$encoder->startTag(SYNC_GETITEMESTIMATE_STATUS);
                    self::$encoder->content($status);
                    self::$encoder->endTag();

                    self::$encoder->startTag(SYNC_GETITEMESTIMATE_FOLDER);
                    {
                        self::$encoder->startTag(SYNC_GETITEMESTIMATE_FOLDERTYPE);
                        self::$encoder->content($collection["class"]);
                        self::$encoder->endTag();

                        self::$encoder->startTag(SYNC_GETITEMESTIMATE_FOLDERID);
                        self::$encoder->content($collection["collectionid"]);
                        self::$encoder->endTag();

                        self::$encoder->startTag(SYNC_GETITEMESTIMATE_ESTIMATE);


                        self::$encoder->content($changecount);

                        self::$encoder->endTag();
                    }
                    self::$encoder->endTag();
                }
                self::$encoder->endTag();
            }
        }
        self::$encoder->endTag();

        return true;
    }

    /**
     * Retrieves an attachment
     *
     * @access private
     * @return boolean
     */
    static private function HandleGetAttachment() {
        $attname = Request::getGETAttachmentName();
        if(!$attname)
            return false;

        header("Content-Type: application/octet-stream");
        self::$backend->GetAttachmentData($attname);

        return true;
    }

    /**
     * Handles the ping request
     * This blocks until a changed is available or the timeout occurs
     *
     * @access private
     * @return boolean
     */
    static private function HandlePing() {

        $timeout = 5;
        $pingstatus = false;

        $collections = array();
        $lifetime = 0;
        $policykey = 0;

        // TODO all active PING requests should be logged and terminate themselfs (e.g. volatile devicedata!!)

        // Get previous pingdata, if available
        list($collections, $lifetime, $policykey) = self::$deviceManager->GetPingState();

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandlePing(): reference PolicyKey for PING: %s", $policykey));

        if(self::$decoder->getElementStartTag(SYNC_PING_PING)) {
            ZLog::Write(LOGLEVEL_DEBUG, "HandlePing(): initialization data received");
            if(self::$decoder->getElementStartTag(SYNC_PING_LIFETIME)) {
                $lifetime = self::$decoder->getElementContent();
                self::$decoder->getElementEndTag();
            }

            if(self::$decoder->getElementStartTag(SYNC_PING_FOLDERS)) {
                // avoid ping init if not necessary
                $saved_collections = $collections;

                $collections = array();

                try {
                    while(self::$decoder->getElementStartTag(SYNC_PING_FOLDER)) {
                        $collection = array();

                        if(self::$decoder->getElementStartTag(SYNC_PING_SERVERENTRYID)) {
                            $collection["serverid"] = self::$decoder->getElementContent();
                            self::$decoder->getElementEndTag();
                        }
                        if(self::$decoder->getElementStartTag(SYNC_PING_FOLDERTYPE)) {
                            $collection["class"] = self::$decoder->getElementContent();
                            self::$decoder->getElementEndTag();
                        }

                        self::$decoder->getElementEndTag();

                        // initialize empty state
                        $collection["state"] = "";

                        // try to find old state in saved states
                        if (is_array($saved_collections))
                            foreach ($saved_collections as $saved_col) {
                                if ($saved_col["serverid"] == $collection["serverid"] && $saved_col["class"] == $collection["class"]) {
                                    $collection["state"] = $saved_col["state"];
                                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandlePing(): reusing saved state for '%s' id '%s'", $collection["class"], $collection["serverid"]));
                                    break;
                                }
                            }

                        if ($collection["state"] == "")
                            ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandlePing(): no saved state found for empty state for '%s' id '%s'. Using empty state. ", $collection["class"],  $collection["serverid"]));

                        // switch user store if this is a additional folder
                        self::$backend->Setup(ZPush::GetAdditionalSyncFolderStore($collection["serverid"]));

                        // Create start state for this collection
                        $exporter = self::$backend->GetExporter($collection["serverid"]);
                        $importer = false;

                        $exporter->Config($collection["state"], BACKEND_DISCARD_DATA);
                        $cpo = new ContentParameters();
                        $cpo->SetContentClass($collection["class"]);
                        $cpo->SetFilterType(SYNC_FILTERTYPE_1DAY);
                        $exporter->ConfigContentParameters($cpo);
                        $exporter->InitializeExporter($importer);
                        while(is_array($exporter->Synchronize()));
                        $collection["state"] = $exporter->GetState();
                        array_push($collections, $collection);
                    }
                }
                // if something goes wrong, we ask the device to do a foldersync
                catch (StatusException $stex) {
                    $pingstatus = SYNC_PINGSTATUS_FOLDERHIERSYNCREQUIRED;
                }

                if(!self::$decoder->getElementEndTag())
                    return false;
            }

            if(!self::$decoder->getElementEndTag())
                return false;
        }

        $changes = array();
        $dataavailable = false;

        // enter the waiting loop
        if (!$pingstatus) {
            ZLog::Write(LOGLEVEL_INFO, sprintf("HandlePing(): Waiting for changes... (lifetime %d seconds)", $lifetime));
            // Wait for something to happen
            for($n=0;$n<$lifetime / $timeout; $n++ ) {
                // Check if provisioning is necessary
                // if a PolicyKey was sent use it. If not, compare with the PolicyKey from the last PING request
                if (PROVISIONING === true && self::$deviceManager->ProvisioningRequired((Request::wasPolicyKeySent() ? Request::getPolicyKey(): $policykey), true)) {
                    // the hierarchysync forces provisioning
                    $pingstatus = SYNC_PINGSTATUS_FOLDERHIERSYNCREQUIRED;
                    break;
                }

                // TODO we could also check if a hierarchy sync is necessary, as the state is saved in the ASDevice
                if(count($collections) == 0) {
                    $pingstatus = SYNC_PINGSTATUS_FAILINGPARAMS;
                    break;
                }

                try {
                    for($i=0;$i<count($collections);$i++) {
                        $collection = $collections[$i];

                        // switch user store if this is a additional folder (true -> do not debug)
                        self::$backend->Setup(ZPush::GetAdditionalSyncFolderStore($collection["serverid"], true));

                        $exporter = self::$backend->GetExporter($collection["serverid"]);
                        // during the ping, permissions could be revoked
                        if ($exporter === false) {
                            $pingstatus = SYNC_PINGSTATUS_FOLDERHIERSYNCREQUIRED;
                            // reset current collections
                            $collections = array();
                            break 2;
                        }
                        $importer = false;

                        $exporter->Config($collection["state"], BACKEND_DISCARD_DATA);
                        $cpo = new ContentParameters();
                        $cpo->SetContentClass($collection["class"]);
                        $cpo->SetFilterType(SYNC_FILTERTYPE_1DAY);
                        $exporter->ConfigContentParameters($cpo);
                        $ret = $exporter->InitializeExporter($importer);

                        // start over if exporter can not be configured atm
                        if ($ret === false )
                            throw new StatusException("HandlePing(): during ping exporter can not be re-configured.", SYNC_PINGSTATUS_FOLDERHIERSYNCREQUIRED, null, LOGLEVEL_WARN);

                        $changecount = $exporter->GetChangeCount();

                        if($changecount > 0) {
                            $dataavailable = true;
                            $changes[$collection["serverid"]] = $changecount;
                        }

                        // Discard any data
                        while(is_array($exporter->Synchronize()));

                        // Record state for next Ping
                        $collections[$i]["state"] = $exporter->GetState();
                    }
                }
                // if the exporter fails on any folder, then force a HierarchySync and reset saved data
                catch (StatusException $stex) {
                    $pingstatus = SYNC_PINGSTATUS_FOLDERHIERSYNCREQUIRED;
                    $collections = array();
                    break;
                }

                if($dataavailable) {
                    ZLog::Write(LOGLEVEL_INFO, "HandlePing(): Found change");
                    break;
                }

                sleep($timeout);
            }
        }

        self::$encoder->StartWBXML();

        self::$encoder->startTag(SYNC_PING_PING);
        {
            self::$encoder->startTag(SYNC_PING_STATUS);
            if (isset($pingstatus) && $pingstatus)
                self::$encoder->content($pingstatus);
            else
                self::$encoder->content(count($changes) > 0 ? SYNC_PINGSTATUS_CHANGES : SYNC_PINGSTATUS_HBEXPIRED);
            self::$encoder->endTag();

            self::$encoder->startTag(SYNC_PING_FOLDERS);
            foreach($collections as $collection) {
                if(isset($changes[$collection["serverid"]])) {
                    self::$encoder->startTag(SYNC_PING_FOLDER);
                    self::$encoder->content($collection["serverid"]);
                    self::$encoder->endTag();
                }
            }
            self::$encoder->endTag();
        }
        self::$encoder->endTag();

        // Save the ping state
        self::$deviceManager->SetPingState($collections, $lifetime);

        return true;
    }

    /**
     * Handles the sending of an e-mail
     * Can be called with parent, reply and forward information
     *
     * @param string        $forward    id of the message to be attached below the email
     * @param string        $reply      id of the message to be attached below the email
     * @param string        $parent     id of the folder containing $forward or $reply
     *
     * @access private
     * @return boolean
     */
    static private function HandleSendMail($forward = false, $reply = false, $parent = false) {
        // read rfc822 message from stdin
        $rfc822 = "";
        while($data = fread(Request::getInputStream(), 4096))
            $rfc822 .= $data;

        // no wbxml output is provided, only a http OK
        return self::$backend->SendMail($rfc822, $forward, $reply, $parent, Request::getGETSaveInSent());
    }

    /**
     * Forwards and sends an e-mail
     * SmartForward is a normal 'send' except that you should attach the
     * original message which is specified in the URL
     *
     * @access private
     * @return boolean
     */
    static private function HandleSmartForward() {
        return self::HandleSendMail(Request::getGETItemId(), false, Request::getGETCollectionId());
    }

    /**
     * Reply and sends an e-mail
     * SmartReply should add the original message to the end of the message body
     *
     * @access private
     * @return boolean
     */
    static private function HandleSmartReply() {
        return self::HandleSendMail(false, Request::getGETItemId(), Request::getGETCollectionId());
    }

    /**
     * Handles creates, updates or deletes of a folder
     * issued by the commands FolderCreate, FolderUpdate and FolderDelete
     *
     * @access private
     * @return boolean
     */
    static private function HandleFolderChange() {

        $el = self::$decoder->getElement();

        if($el[EN_TYPE] != EN_TYPE_STARTTAG)
            return false;

        $create = $update = $delete = false;
        if($el[EN_TAG] == SYNC_FOLDERHIERARCHY_FOLDERCREATE)
            $create = true;
        else if($el[EN_TAG] == SYNC_FOLDERHIERARCHY_FOLDERUPDATE)
            $update = true;
        else if($el[EN_TAG] == SYNC_FOLDERHIERARCHY_FOLDERDELETE)
            $delete = true;

        if(!$create && !$update && !$delete)
            return false;

        // SyncKey
        if(!self::$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_SYNCKEY))
            return false;
        $synckey = self::$decoder->getElementContent();
        if(!self::$decoder->getElementEndTag())
            return false;

        // ServerID
        $serverid = false;
        if(self::$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_SERVERENTRYID)) {
            $serverid = self::$decoder->getElementContent();
            if(!self::$decoder->getElementEndTag())
                return false;
        }

        // when creating or updating more information is necessary
        if (!$delete) {
            // Parent
            $parentid = false;
            if(self::$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_PARENTID)) {
                $parentid = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag())
                    return false;
            }

            // Displayname
            if(!self::$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_DISPLAYNAME))
                return false;
            $displayname = self::$decoder->getElementContent();
            if(!self::$decoder->getElementEndTag())
                return false;

            // Type
            $type = false;
            if(self::$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_TYPE)) {
                $type = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag())
                    return false;
            }
        }

        if(!self::$decoder->getElementEndTag())
            return false;

        $status = SYNC_FSSTATUS_SUCCESS;
        // Get state of hierarchy
        try {
            $syncstate = self::$deviceManager->GetSyncState($synckey);
            $newsynckey = self::$deviceManager->GetNewSyncKey($synckey);

            // Over the ChangesWrapper the HierarchyCache is notified about all changes
            $changesMem = self::$deviceManager->GetHierarchyChangesWrapper();

            // the hierarchyCache should now fully be initialized - check for changes in the additional folders
            $changesMem->Config(ZPush::GetAdditionalSyncFolders());

            // there are unprocessed changes in the hierarchy, trigger resync
            if ($changesMem->GetChangeCount() > 0)
                throw new StatusException("HandleFolderChange() can not proceed as there are unprocessed hierarchy changes", SYNC_FSSTATUS_SERVERERROR);

            // any additional folders can not be modified!
            if ($serverid !== false && ZPush::GetAdditionalSyncFolderStore($serverid))
                throw new StatusException("HandleFolderChange() can not change additional folders which are configured", SYNC_FSSTATUS_UNKNOWNERROR);

            // switch user store if this this happens inside an additional folder
            // if this is an additional folder the backend has to be setup correctly
            if (!self::$backend->Setup(ZPush::GetAdditionalSyncFolderStore((($parentid != false)?$parentid:$serverid))))
                throw new StatusException(sprintf("HandleFolderChange() could not Setup() the backend for folder id '%s'", (($parentid != false)?$parentid:$serverid)), SYNC_FSSTATUS_SERVERERROR);
        }
        catch (StateNotFoundException $snfex) {
            $status = SYNC_FSSTATUS_SYNCKEYERROR;
        }
        catch (StatusException $stex) {
           $status = $stex->getCode();
        }

        if ($status == SYNC_FSSTATUS_SUCCESS) {
            try {
                // Configure importer with last state
                $importer = self::$backend->GetImporter();
                $importer->Config($syncstate);

                // the messages from the PIM will be forwarded to the real importer
                $changesMem->setDestinationImporter($importer);

                // process incoming change
                if (!$delete) {
                    // Send change
                    $folder = new SyncFolder();
                    $folder->serverid = $serverid;
                    $folder->parentid = $parentid;
                    $folder->displayname = $displayname;
                    $folder->type = $type;

                    $serverid = $changesMem->ImportFolderChange($folder);
                }
                else {
                    // delete folder
                    $changesMem->ImportFolderDeletion($serverid, 0);
                }
            }
            catch (StatusException $stex) {
                $status = $stex->getCode();
            }
        }

        self::$encoder->startWBXML();
        if ($create) {

            self::$encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERCREATE);
            {
                {
                    self::$encoder->startTag(SYNC_FOLDERHIERARCHY_STATUS);
                    self::$encoder->content($status);
                    self::$encoder->endTag();

                    self::$encoder->startTag(SYNC_FOLDERHIERARCHY_SYNCKEY);
                    self::$encoder->content($newsynckey);
                    self::$encoder->endTag();

                    self::$encoder->startTag(SYNC_FOLDERHIERARCHY_SERVERENTRYID);
                    self::$encoder->content($serverid);
                    self::$encoder->endTag();
                }
                self::$encoder->endTag();
            }
            self::$encoder->endTag();
        }

        elseif ($update) {
            self::$encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERUPDATE);
            {
                {
                    self::$encoder->startTag(SYNC_FOLDERHIERARCHY_STATUS);
                    self::$encoder->content($status);
                    self::$encoder->endTag();

                    self::$encoder->startTag(SYNC_FOLDERHIERARCHY_SYNCKEY);
                    self::$encoder->content($newsynckey);
                    self::$encoder->endTag();
                }
                self::$encoder->endTag();
            }
        }

        elseif ($delete) {
            self::$encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERDELETE);
            {
                {
                    self::$encoder->startTag(SYNC_FOLDERHIERARCHY_STATUS);
                    self::$encoder->content($status);
                    self::$encoder->endTag();

                    self::$encoder->startTag(SYNC_FOLDERHIERARCHY_SYNCKEY);
                    self::$encoder->content($newsynckey);
                    self::$encoder->endTag();
                }
                self::$encoder->endTag();
            }
        }

        self::$encoder->endTag();

        // Save the sync state for the next time
        self::$deviceManager->SetSyncState($newsynckey, $importer->GetState());

        return true;
    }

    /**
     * Handles a response of a meeting request
     *
     * @access private
     * @return boolean
     */
    static private function HandleMeetingResponse() {
        $requests = Array();

        if(!self::$decoder->getElementStartTag(SYNC_MEETINGRESPONSE_MEETINGRESPONSE))
            return false;

        while(self::$decoder->getElementStartTag(SYNC_MEETINGRESPONSE_REQUEST)) {
            $req = Array();

            if(self::$decoder->getElementStartTag(SYNC_MEETINGRESPONSE_USERRESPONSE)) {
                $req["response"] = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag())
                    return false;
            }

            if(self::$decoder->getElementStartTag(SYNC_MEETINGRESPONSE_FOLDERID)) {
                $req["folderid"] = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag())
                    return false;
            }

            if(self::$decoder->getElementStartTag(SYNC_MEETINGRESPONSE_REQUESTID)) {
                $req["requestid"] = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag())
                    return false;
            }

            if(!self::$decoder->getElementEndTag())
                return false;

            array_push($requests, $req);
        }

        if(!self::$decoder->getElementEndTag())
            return false;

        // output the error code, plus the ID of the calendar item that was generated by the
        // accept of the meeting response
        self::$encoder->StartWBXML();
        self::$encoder->startTag(SYNC_MEETINGRESPONSE_MEETINGRESPONSE);

        foreach($requests as $req) {
            $status = SYNC_MEETRESPSTATUS_SUCCESS;

            try {
                $calendarid = self::$backend->MeetingResponse($req["requestid"], $req["folderid"], $req["response"]);
                if ($calendarid === false)
                    throw new StatusException("HandleMeetingResponse() not possible", SYNC_MEETRESPSTATUS_SERVERERROR);
            }
            catch (StatusException $stex) {
                $status = $stex->getCode();
            }

            self::$encoder->startTag(SYNC_MEETINGRESPONSE_RESULT);
                self::$encoder->startTag(SYNC_MEETINGRESPONSE_REQUESTID);
                    self::$encoder->content($req["requestid"]);
                self::$encoder->endTag();

                self::$encoder->startTag(SYNC_MEETINGRESPONSE_STATUS);
                    self::$encoder->content($status);
                self::$encoder->endTag();

                if($status == SYNC_MEETRESPSTATUS_SUCCESS) {
                    self::$encoder->startTag(SYNC_MEETINGRESPONSE_CALENDARID);
                        self::$encoder->content($calendarid);
                    self::$encoder->endTag();
                }
            self::$encoder->endTag();
        }
        self::$encoder->endTag();

        return true;
    }

    /**
     * Handles a the Provisioning of a device
     *
     * @access private
     * @return boolean
     */
    static private function HandleProvision() {
        $status = SYNC_PROVISION_STATUS_SUCCESS;

        $rwstatus = self::$deviceManager->GetProvisioningWipeStatus();
        $rwstatusWiped = false;

        // if this is a regular provisioning require that an authenticated remote user
        if ($rwstatus < SYNC_PROVISION_RWSTATUS_PENDING) {
            ZLog::Write(LOGLEVEL_DEBUG, "RequestProcessor::HandleProvision(): Forcing delayed Authentication");
            self::Authenticate();
        }

        $phase2 = true;

        if(!self::$decoder->getElementStartTag(SYNC_PROVISION_PROVISION))
            return false;

        //handle android remote wipe.
        if (self::$decoder->getElementStartTag(SYNC_PROVISION_REMOTEWIPE)) {
            if(!self::$decoder->getElementStartTag(SYNC_PROVISION_STATUS))
                return false;

            $status = self::$decoder->getElementContent();

            if(!self::$decoder->getElementEndTag())
                return false;

            if(!self::$decoder->getElementEndTag())
                return false;

            $phase2 = false;
            $rwstatusWiped = true;
        }
        else {

            if(!self::$decoder->getElementStartTag(SYNC_PROVISION_POLICIES))
                return false;

            if(!self::$decoder->getElementStartTag(SYNC_PROVISION_POLICY))
                return false;

            if(!self::$decoder->getElementStartTag(SYNC_PROVISION_POLICYTYPE))
                return false;

            $policytype = self::$decoder->getElementContent();
            if ($policytype != 'MS-WAP-Provisioning-XML' && $policytype != 'MS-EAS-Provisioning-WBXML') {
                $status = SYNC_PROVISION_STATUS_SERVERERROR;
            }
            if(!self::$decoder->getElementEndTag()) //policytype
                return false;

            if (self::$decoder->getElementStartTag(SYNC_PROVISION_POLICYKEY)) {
                $devpolicykey = self::$decoder->getElementContent();

                if(!self::$decoder->getElementEndTag())
                    return false;

                if(!self::$decoder->getElementStartTag(SYNC_PROVISION_STATUS))
                    return false;

                $status = self::$decoder->getElementContent();
                //do status handling
                $status = SYNC_PROVISION_STATUS_SUCCESS;

                if(!self::$decoder->getElementEndTag())
                    return false;

                $phase2 = false;
            }

            if(!self::$decoder->getElementEndTag()) //policy
                return false;

            if(!self::$decoder->getElementEndTag()) //policies
                return false;

            if (self::$decoder->getElementStartTag(SYNC_PROVISION_REMOTEWIPE)) {
                if(!self::$decoder->getElementStartTag(SYNC_PROVISION_STATUS))
                    return false;

                $status = self::$decoder->getElementContent();

                if(!self::$decoder->getElementEndTag())
                    return false;

                if(!self::$decoder->getElementEndTag())
                    return false;

                $rwstatusWiped = true;
            }
        }
        if(!self::$decoder->getElementEndTag()) //provision
            return false;

        self::$encoder->StartWBXML();

        //set the new final policy key in the device manager
        // START ADDED dw2412 Android provisioning fix
        if (!$phase2) {
            $policykey = self::$deviceManager->GenerateProvisioningPolicyKey();
            self::$deviceManager->SetProvisioningPolicyKey($policykey);
        }
        else {
            // just create a temporary key (i.e. iPhone OS4 Beta does not like policykey 0 in response)
            $policykey = self::$deviceManager->GenerateProvisioningPolicyKey();
        }
        // END ADDED dw2412 Android provisioning fix

        self::$encoder->startTag(SYNC_PROVISION_PROVISION);
        {
            self::$encoder->startTag(SYNC_PROVISION_STATUS);
                self::$encoder->content($status);
            self::$encoder->endTag();

            self::$encoder->startTag(SYNC_PROVISION_POLICIES);
                self::$encoder->startTag(SYNC_PROVISION_POLICY);

                if(isset($policytype)) {
                    self::$encoder->startTag(SYNC_PROVISION_POLICYTYPE);
                        self::$encoder->content($policytype);
                    self::$encoder->endTag();
                }

                self::$encoder->startTag(SYNC_PROVISION_STATUS);
                    self::$encoder->content($status);
                self::$encoder->endTag();

                self::$encoder->startTag(SYNC_PROVISION_POLICYKEY);
                       self::$encoder->content($policykey);
                self::$encoder->endTag();

                if ($phase2) {
                    self::$encoder->startTag(SYNC_PROVISION_DATA);
                    if ($policytype == 'MS-WAP-Provisioning-XML') {
                        self::$encoder->content('<wap-provisioningdoc><characteristic type="SecurityPolicy"><parm name="4131" value="1"/><parm name="4133" value="1"/></characteristic></wap-provisioningdoc>');
                    }
                    elseif ($policytype == 'MS-EAS-Provisioning-WBXML') {
                        self::$encoder->startTag(SYNC_PROVISION_EASPROVISIONDOC);
                            self::$deviceManager->GetProvisioningObject()->encode(self::$encoder);
                        self::$encoder->endTag();
                    }
                    else {
                        ZLog::Write(LOGLEVEL_WARN, "Wrong policy type");
                        return false;
                    }

                    self::$encoder->endTag();//data
                }
                self::$encoder->endTag();//policy
            self::$encoder->endTag(); //policies
        }

        //wipe data if a higher RWSTATUS is requested
        if ($rwstatus > SYNC_PROVISION_RWSTATUS_OK) {
            self::$encoder->startTag(SYNC_PROVISION_REMOTEWIPE, false, true);
            self::$deviceManager->SetProvisioningWipeStatus(($rwstatusWiped)?SYNC_PROVISION_RWSTATUS_WIPED:SYNC_PROVISION_RWSTATUS_REQUESTED);
        }

        self::$encoder->endTag();//provision

        return true;
    }

    /**
     * Handles search request from a device
     *
     * @access private
     * @return boolean
     */
    static private function HandleSearch() {
        $searchrange = '0';

        if(!self::$decoder->getElementStartTag(SYNC_SEARCH_SEARCH))
            return false;

        // TODO check: possible to search in other stores?
        if(!self::$decoder->getElementStartTag(SYNC_SEARCH_STORE))
            return false;

        if(!self::$decoder->getElementStartTag(SYNC_SEARCH_NAME))
            return false;
        $searchname = strtoupper(self::$decoder->getElementContent());
        if(!self::$decoder->getElementEndTag())
            return false;

        if(!self::$decoder->getElementStartTag(SYNC_SEARCH_QUERY))
            return false;
        $searchquery = self::$decoder->getElementContent();
        if(!self::$decoder->getElementEndTag())
            return false;

        if(self::$decoder->getElementStartTag(SYNC_SEARCH_OPTIONS)) {
            while(1) {
                if(self::$decoder->getElementStartTag(SYNC_SEARCH_RANGE)) {
                    $searchrange = self::$decoder->getElementContent();
                    if(!self::$decoder->getElementEndTag())
                        return false;
                    }
                    $e = self::$decoder->peek();
                    if($e[EN_TYPE] == EN_TYPE_ENDTAG) {
                        self::$decoder->getElementEndTag();
                        break;
                    }
                }
        }
        if(!self::$decoder->getElementEndTag()) //store
            return false;

        if(!self::$decoder->getElementEndTag()) //search
            return false;

        // get SearchProvider
        $searchprovider = ZPush::GetSearchProvider();
        $status = SYNC_SEARCHSTATUS_SUCCESS;
        $rows = array();

        // TODO support other searches
        if ($searchprovider->SupportsType($searchname)) {
            $storestatus = SYNC_SEARCHSTATUS_STORE_SUCCESS;
            try {
                if ($searchname == "GAL") {
                    //get search results from the searchprovider
                    $rows = $searchprovider->GetGALSearchResults($searchquery, $searchrange);
                }
            }
            catch (StatusException $stex) {
                $storestatus = $stex->getCode();
            }
        }
        else {
            $rows = array();
            $status = SYNC_SEARCHSTATUS_SERVERERROR;
            ZLog::Write(LOGLEVEL_WARN, sprintf("Searchtype '%s' is not supported.", $searchname));
        }


        $searchprovider->Disconnect();

        self::$encoder->startWBXML();

        self::$encoder->startTag(SYNC_SEARCH_SEARCH);

            self::$encoder->startTag(SYNC_SEARCH_STATUS);
            self::$encoder->content($status);
            self::$encoder->endTag();

            if ($status == SYNC_SEARCHSTATUS_SUCCESS) {
                self::$encoder->startTag(SYNC_SEARCH_RESPONSE);
                self::$encoder->startTag(SYNC_SEARCH_STORE);

                    self::$encoder->startTag(SYNC_SEARCH_STATUS);
                    self::$encoder->content($storestatus);
                    self::$encoder->endTag();

                    if (is_array($rows) && !empty($rows)) {
                        $searchrange = $rows['range'];
                        unset($rows['range']);
                        $searchtotal = $rows['searchtotal'];
                        unset($rows['searchtotal']);
                        foreach ($rows as $u) {
                            self::$encoder->startTag(SYNC_SEARCH_RESULT);
                                self::$encoder->startTag(SYNC_SEARCH_PROPERTIES);

                                    self::$encoder->startTag(SYNC_GAL_DISPLAYNAME);
                                    self::$encoder->content((isset($u[SYNC_GAL_DISPLAYNAME]))?$u[SYNC_GAL_DISPLAYNAME]:"No name");
                                    self::$encoder->endTag();

                                    if (isset($u[SYNC_GAL_PHONE])) {
                                        self::$encoder->startTag(SYNC_GAL_PHONE);
                                        self::$encoder->content($u[SYNC_GAL_PHONE]);
                                        self::$encoder->endTag();
                                    }

                                    if (isset($u[SYNC_GAL_ALIAS])) {
                                        self::$encoder->startTag(SYNC_GAL_ALIAS);
                                        self::$encoder->content($u[SYNC_GAL_ALIAS]);
                                        self::$encoder->endTag();
                                    }

                                    if (isset($u[SYNC_GAL_OFFICE])) {
                                        self::$encoder->startTag(SYNC_GAL_OFFICE);
                                        self::$encoder->content($u[SYNC_GAL_OFFICE]);
                                        self::$encoder->endTag();
                                    }

                                    if (isset($u[SYNC_GAL_TITLE])) {
                                        self::$encoder->startTag(SYNC_GAL_TITLE);
                                        self::$encoder->content($u[SYNC_GAL_TITLE]);
                                        self::$encoder->endTag();
                                    }

                                    if (isset($u[SYNC_GAL_COMPANY])) {
                                        self::$encoder->startTag(SYNC_GAL_COMPANY);
                                        self::$encoder->content($u[SYNC_GAL_COMPANY]);
                                        self::$encoder->endTag();
                                    }

                                    if (isset($u[SYNC_GAL_HOMEPHONE])) {
                                        self::$encoder->startTag(SYNC_GAL_HOMEPHONE);
                                        self::$encoder->content($u[SYNC_GAL_HOMEPHONE]);
                                        self::$encoder->endTag();
                                    }

                                    if (isset($u[SYNC_GAL_MOBILEPHONE])) {
                                        self::$encoder->startTag(SYNC_GAL_MOBILEPHONE);
                                        self::$encoder->content($u[SYNC_GAL_MOBILEPHONE]);
                                        self::$encoder->endTag();
                                    }

                                    // Always send the firstname, even empty. Nokia needs this to display the entry
                                    self::$encoder->startTag(SYNC_GAL_FIRSTNAME);
                                    self::$encoder->content((isset($u[SYNC_GAL_FIRSTNAME]))?$u[SYNC_GAL_FIRSTNAME]:"");
                                    self::$encoder->endTag();

                                    self::$encoder->startTag(SYNC_GAL_LASTNAME);
                                    self::$encoder->content((isset($u[SYNC_GAL_LASTNAME]))?$u[SYNC_GAL_LASTNAME]:"No name");
                                    self::$encoder->endTag();

                                    self::$encoder->startTag(SYNC_GAL_EMAILADDRESS);
                                    self::$encoder->content((isset($u[SYNC_GAL_EMAILADDRESS]))?$u[SYNC_GAL_EMAILADDRESS]:"");
                                    self::$encoder->endTag();

                                self::$encoder->endTag();//result
                            self::$encoder->endTag();//properties
                        }

                        if ($searchtotal > 0) {
                            self::$encoder->startTag(SYNC_SEARCH_RANGE);
                            self::$encoder->content($searchrange);
                            self::$encoder->endTag();

                            self::$encoder->startTag(SYNC_SEARCH_TOTAL);
                            self::$encoder->content($searchtotal);
                            self::$encoder->endTag();
                        }
                    }

                self::$encoder->endTag();//store
                self::$encoder->endTag();//response
            }
        self::$encoder->endTag();//search

        return true;
    }

}
?>