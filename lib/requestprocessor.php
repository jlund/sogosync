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
    static private $topCollector;
    static private $decoder;
    static private $encoder;
    static private $userIsAuthenticated;

    /**
     * Authenticates the remote user
     * The sent HTTP authentication information is used to on Backend->Logon().
     * As second step the GET-User verified by Backend->Setup() for permission check
     * Request::GetGETUser() is usually the same as the Request::GetAuthUser().
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
        if($backend->Logon(Request::GetAuthUser(), Request::GetAuthDomain(), Request::GetAuthPassword()) == false)
            throw new AuthenticationRequiredException("Access denied. Username or password incorrect");

        // mark this request as "authenticated"
        self::$userIsAuthenticated = true;

        // check Auth-User's permissions on GETUser's store
        if($backend->Setup(Request::GetGETUser(), true) == false)
            throw new AuthenticationRequiredException(sprintf("Not enough privileges of '%s' to setup for user '%s': Permission denied", Request::GetAuthUser(), Request::GetGETUser()));
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
        self::$topCollector = ZPush::GetTopCollector();

        if (!ZPush::CommandNeedsPlainInput(Request::GetCommandCode()))
            self::$decoder = new WBXMLDecoder(Request::GetInputStream());

        self::$encoder = new WBXMLEncoder(Request::GetOutputStream());
    }

    /**
     * Processes a command sent from the mobile
     *
     * @access public
     * @return boolean
     */
    static public function HandleRequest() {
        switch(Request::GetCommandCode()) {
            case ZPush::COMMAND_SYNC:
                $status = self::HandleSync();
                break;
            case ZPush::COMMAND_SENDMAIL:
                $status = self::HandleSendMail();
                break;
            case ZPush::COMMAND_SMARTFORWARD:
                $status = self::HandleSmartForward();
                break;
            case ZPush::COMMAND_SMARTREPLY:
                $status = self::HandleSmartReply();
                break;
            case ZPush::COMMAND_GETATTACHMENT:
                $status = self::HandleGetAttachment();
                break;
            case ZPush::COMMAND_GETHIERARCHY:
                $status = self::HandleGetHierarchy();
                break;
            case ZPush::COMMAND_FOLDERSYNC:
                $status = self::HandleFolderSync();
                break;
            case ZPush::COMMAND_FOLDERCREATE:
            case ZPush::COMMAND_FOLDERUPDATE:
            case ZPush::COMMAND_FOLDERDELETE:
                $status = self::HandleFolderChange();
                break;
            case ZPush::COMMAND_MOVEITEMS:
                $status = self::HandleMoveItems();
                break;
            case ZPush::COMMAND_GETITEMESTIMATE:
                $status = self::HandleGetItemEstimate();
                break;
            case ZPush::COMMAND_MEETINGRESPONSE:
                $status = self::HandleMeetingResponse();
                break;
            case ZPush::COMMAND_NOTIFY:                     // Used for sms-based notifications (pushmail)
                $status = self::HandleNotify();
                break;
            case ZPush::COMMAND_PING:                       // Used for http-based notifications (pushmail)
                $status = self::HandlePing();
                break;
            case ZPush::COMMAND_PROVISION:
                $status = (PROVISIONING === true) ? self::HandleProvision() : false;
                break;
            case ZPush::COMMAND_SEARCH:
                $status = self::HandleSearch();
                break;
            case ZPush::COMMAND_ITEMOPERATIONS:
                $status = self::HandleItemOperations();
                break;
            case ZPush::COMMAND_SETTINGS:
                $status = self::HandleSettings();
                break;

            // TODO implement ResolveRecipients and ValidateCert
            case ZPush::COMMAND_RESOLVERECIPIENTS:
            case ZPush::COMMAND_VALIDATECERT:

            // webservice commands
            case ZPush::COMMAND_WEBSERVICE_DEVICE:
                include("lib/webservice/webservice.php");

                $status = Webservice::Handle(ZPush::COMMAND_WEBSERVICE_DEVICE);
                break;

            // deprecated commands
            case ZPush::COMMAND_CREATECOLLECTION:
            case ZPush::COMMAND_DELETECOLLECTION:
            case ZPush::COMMAND_MOVECOLLECTION:
            default:
                throw new FatalNotImplementedException(sprintf("RequestProcessor::HandleRequest(): Command '%s' is not implemented", Request::GetCommand()));
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
            $result = false;
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

            self::$topCollector->AnnounceInformation(sprintf("Operation status: %s", $status), true);

            self::$encoder->startTag(SYNC_MOVE_STATUS);
            self::$encoder->content($status);
            self::$encoder->endTag();

            self::$encoder->startTag(SYNC_MOVE_DSTMSGID);
            self::$encoder->content( (($result !== false ) ? $result : $move["srcmsgid"]));
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

            // TODO execute $data->Check() to see if SyncObject is valid

        }
        catch (StatusException $ex) {
            return false;
        }

        self::$encoder->StartWBXML();
        self::$encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERS);
        foreach ($folders as $folder) {
            self::$encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDER);
            $folder->Encode(self::$encoder);
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
            $syncstate = self::$deviceManager->GetStateManager()->GetSyncState($synckey);
        }
        catch (StateNotFoundException $snfex) {
                $status = SYNC_FSSTATUS_SYNCKEYERROR;
        }

        // The ChangesWrapper caches all imports in-memory, so we can send a change count
        // before sending the actual data.
        // the HierarchyCache is notified and the changes from the PIM are transmitted to the actual backend
        $changesMem = self::$deviceManager->GetHierarchyChangesWrapper();

        // We will be saving the sync state under 'newsynckey'
        $newsynckey = self::$deviceManager->GetStateManager()->GetNewSyncKey($synckey);

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
                if(!$folder->Decode(self::$decoder))
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
                    else {
                        ZLog::Write(LOGLEVEL_WARN, sprintf("Request->HandleFolderSync(): ignoring incoming folderchange for folder '%s' as status indicates problem.", $folder->displayname));
                        self::$topCollector->AnnounceInformation("Incoming change ignored", true);
                    }
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
                    if (!Request::IsValidDeviceID())
                        throw new StatusException(sprintf("Request::IsValidDeviceID() indicated that '%s' is not a valid device id", Request::GetDeviceID()), SYNC_FSSTATUS_SERVERERROR);

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
                $synckey = ($changesMem->IsStateChanged()) ? $newsynckey : $synckey;
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
                self::$topCollector->AnnounceInformation(sprintf("Outgoing %d folders",$changeCount), true);

                // everything fine, save the sync state for the next time
                if ($synckey == $newsynckey)
                    self::$deviceManager->GetStateManager()->SetSyncState($newsynckey, $newsyncstate);
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
        // Contains all requested folders (containers)
        $sc = new SyncCollections();
        $status = SYNC_STATUS_SUCCESS;
        $foldersync = false;
        $emtpysync = false;

        // Start Synchronize
        if(self::$decoder->getElementStartTag(SYNC_SYNCHRONIZE)) {

            // AS 1.0 sends version information in WBXML
            if(self::$decoder->getElementStartTag(SYNC_VERSION)) {
                $sync_version = self::$decoder->getElementContent();
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("WBXML sync version: '%s'", $sync_version));
                if(!self::$decoder->getElementEndTag())
                    return false;
            }

            // Synching specified folders
            if(self::$decoder->getElementStartTag(SYNC_FOLDERS)) {
                $foldersync = true;

                while(self::$decoder->getElementStartTag(SYNC_FOLDER)) {
                    $actiondata = array();
                    $actiondata["requested"] = true;
                    $actiondata["clientids"] = array();
                    $actiondata["modifyids"] = array();
                    $actiondata["removeids"] = array();
                    $actiondata["fetchids"] = array();
                    $actiondata["statusids"] = array();

                    // read class, synckey and folderid without CPO for now
                    $class = $synckey = $folderid = false;

                    //for AS versions < 2.5
                    if(self::$decoder->getElementStartTag(SYNC_FOLDERTYPE)) {
                        $class = self::$decoder->getElementContent();
                        ZLog::Write(LOGLEVEL_DEBUG, sprintf("Sync folder: '%s'", $class));

                        if(!self::$decoder->getElementEndTag())
                            return false;
                    }

                    // SyncKey
                    if(!self::$decoder->getElementStartTag(SYNC_SYNCKEY))
                        return false;
                    $synckey = self::$decoder->getElementContent();
                    if(!self::$decoder->getElementEndTag())
                        return false;

                    // FolderId
                    if(self::$decoder->getElementStartTag(SYNC_FOLDERID)) {
                        $folderid = self::$decoder->getElementContent();

                        if(!self::$decoder->getElementEndTag())
                            return false;
                    }

                    // compatibility mode AS 1.0 - get folderid which was sent during GetHierarchy()
                    if (! $folderid && $class) {
                        $folderid = self::$deviceManager->GetFolderIdFromCacheByClass($class);
                    }

                    // folderid HAS TO BE known by now, so we retrieve the correct CPO for an update
                    $cpo = self::$deviceManager->GetStateManager()->GetSynchedFolderState($folderid);

                    // update folderid.. this might be a new object
                    $cpo->SetFolderId($folderid);

                    if ($class !== false)
                        $cpo->SetContentClass($class);

                    if ($synckey !== false && $synckey !== "0")
                        $cpo->SetSyncKey($synckey);

                    // Get class for as versions >= 12.0
                    if (! $cpo->HasContentClass()) {
                        try {
                            $cpo->SetContentClass(self::$deviceManager->GetFolderClassFromCacheByID($cpo->GetFolderId()));
                            ZLog::Write(LOGLEVEL_DEBUG, sprintf("GetFolderClassFromCacheByID from Device Manager: '%s' for id:'%s'", $cpo->GetContentClass(), $cpo->GetFolderId()));
                        }
                        catch (NoHierarchyCacheAvailableException $nhca) {
                            $status = SYNC_STATUS_FOLDERHIERARCHYCHANGED;
                            self::$deviceManager->ForceFullResync();
                        }
                    }

                    // done basic CPO initialization/loading -> add to SyncCollection
                    $sc->AddCollection($cpo);
                    $sc->AddParameter($cpo, "requested", true);

                    if ($cpo->HasContentClass())
                        self::$topCollector->AnnounceInformation(sprintf("%s request", $cpo->GetContentClass()), true);
                    else
                        ZLog::Write(LOGLEVEL_WARN, "Not possible to determine class of request. Request did not contain class and apparently there is an issue with the HierarchyCache.");

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
                        // TODO supported fields could be saved in CPO
                        self::$deviceManager->SetSupportedFields($cpo->GetFolderId(), $supfields);
                    }

                    // Deletes as moves can be an empty tag as well as have value
                    if(self::$decoder->getElementStartTag(SYNC_DELETESASMOVES)) {
                        $cpo->SetDeletesAsMoves(true);
                        if (($dam = self::$decoder->getElementContent()) !== false) {
                            $cpo->SetDeletesAsMoves((boolean)$dam);
                            if(!self::$decoder->getElementEndTag()) {
                                return false;
                            }
                        }
                    }

                    // Get changes can be an empty tag as well as have value
                    // code block partly contributed by dw2412
                    if(self::$decoder->getElementStartTag(SYNC_GETCHANGES)) {
                        $sc->AddParameter($cpo, "getchanges", true);
                        if (($gc = self::$decoder->getElementContent()) !== false) {
                            $sc->AddParameter($cpo, "getchanges", $gc);
                            if(!self::$decoder->getElementEndTag()) {
                                return false;
                            }
                        }
                    }

                    if(self::$decoder->getElementStartTag(SYNC_WINDOWSIZE)) {
                        $cpo->SetWindowSize(self::$decoder->getElementContent());

                        // also announce the currently requested window size to the DeviceManager
                        self::$deviceManager->SetWindowSize($cpo->GetFolderId(), $cpo->GetWindowSize());

                        if(!self::$decoder->getElementEndTag())
                            return false;
                    }

                    // conversation mode requested
                    if(self::$decoder->getElementStartTag(SYNC_CONVERSATIONMODE)) {
                        $cpo->SetConversationMode(true);
                        if(($conversationmode = self::$decoder->getElementContent()) !== false) {
                            $cpo->SetConversationMode((boolean)$conversationmode);
                            if(!self::$decoder->getElementEndTag())
                            return false;
                        }
                    }

                    // Do not truncate by default
                    $cpo->SetTruncation(SYNC_TRUNCATION_ALL);

                    if(self::$decoder->getElementStartTag(SYNC_OPTIONS)) {
                        while(1) {
                            if(self::$decoder->getElementStartTag(SYNC_FILTERTYPE)) {
                                $cpo->SetFilterType(self::$decoder->getElementContent());
                                if(!self::$decoder->getElementEndTag())
                                    return false;
                            }
                            if(self::$decoder->getElementStartTag(SYNC_TRUNCATION)) {
                                $cpo->SetTruncation(self::$decoder->getElementContent());
                                if(!self::$decoder->getElementEndTag())
                                    return false;
                            }
                            if(self::$decoder->getElementStartTag(SYNC_RTFTRUNCATION)) {
                                $cpo->SetRTFTruncation(self::$decoder->getElementContent());
                                if(!self::$decoder->getElementEndTag())
                                    return false;
                            }

                            if(self::$decoder->getElementStartTag(SYNC_MIMESUPPORT)) {
                                $cpo->SetMimeSupport(self::$decoder->getElementContent());
                                if(!self::$decoder->getElementEndTag())
                                    return false;
                            }

                            if(self::$decoder->getElementStartTag(SYNC_MIMETRUNCATION)) {
                                $cpo->SetMimeTruncation(self::$decoder->getElementContent());
                                if(!self::$decoder->getElementEndTag())
                                    return false;
                            }

                            if(self::$decoder->getElementStartTag(SYNC_CONFLICT)) {
                                $cpo->SetConflict(self::$decoder->getElementContent());
                                if(!self::$decoder->getElementEndTag())
                                    return false;
                            }

                            while (self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_BODYPREFERENCE)) {
                                if(self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_TYPE)) {
                                    $bptype = self::$decoder->getElementContent();
                                    $cpo->BodyPreference($bptype);
                                    if(!self::$decoder->getElementEndTag()) {
                                        return false;
                                    }
                                }

                                if(self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_TRUNCATIONSIZE)) {
                                    $cpo->BodyPreference($bptype)->SetTruncationSize(self::$decoder->getElementContent());
                                    if(!self::$decoder->getElementEndTag())
                                        return false;
                                }

                                if(self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_ALLORNONE)) {
                                    $cpo->BodyPreference($bptype)->SetAllOrNone(self::$decoder->getElementContent());
                                    if(!self::$decoder->getElementEndTag())
                                        return false;
                                }

                                if(self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_PREVIEW)) {
                                    $cpo->BodyPreference($bptype)->SetPreview(self::$decoder->getElementContent());
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
                        (!$cpo->HasFilterType() || $cpo->GetFilterType() > SYNC_FILTERTIME_MAX)) {
                            $cpo->SetFilterType(SYNC_FILTERTIME_MAX);
                    }

                    // set default conflict behavior from config if the device doesn't send a conflict resolution parameter
                    if (! $cpo->HasConflict()) {
                        $cpo->SetConflict(SYNC_CONFLICT_DEFAULT);
                    }

                    // Get our syncstate
                    if ($status == SYNC_STATUS_SUCCESS) {
                        try {
                            $sc->AddParameter($cpo, "state", self::$deviceManager->GetStateManager()->GetSyncState($cpo->GetSyncKey()));

                            // if this request was made before, there will be a failstate available
                            $actiondata["failstate"] = self::$deviceManager->GetStateManager()->GetSyncFailState();

                            // if this is an additional folder the backend has to be setup correctly
                            if (!self::$backend->Setup(ZPush::GetAdditionalSyncFolderStore($cpo->GetFolderId())))
                                throw new StatusException(sprintf("HandleSync() could not Setup() the backend for folder id '%s'", $cpo->GetFolderId()), SYNC_STATUS_FOLDERHIERARCHYCHANGED);
                        }
                        catch (StateNotFoundException $snfex) {
                            $status = SYNC_STATUS_INVALIDSYNCKEY;
                            self::$topCollector->AnnounceInformation("StateNotFoundException", true);
                        }
                        catch (StatusException $stex) {
                           $status = $stex->getCode();
                           self::$topCollector->AnnounceInformation(sprintf("StatusException code: %d", $status), true);
                        }

                        // Check if the hierarchycache is available. If not, trigger a HierarchySync
                        if (self::$deviceManager->IsHierarchySyncRequired()) {
                            $status = SYNC_STATUS_FOLDERHIERARCHYCHANGED;
                            ZLog::Write(LOGLEVEL_DEBUG, "HierarchyCache is also not available. Triggering HierarchySync to device");
                        }
                    }

                    if(self::$decoder->getElementStartTag(SYNC_PERFORM)) {
                        $performaction = true;

                        if ($status == SYNC_STATUS_SUCCESS) {
                            try {
                                // Configure importer with last state
                                $importer = self::$backend->GetImporter($cpo->GetFolderId());

                                // if something goes wrong, ask the mobile to resync the hierarchy
                                if ($importer === false)
                                    throw new StatusException(sprintf("HandleSync() could not get an importer for folder id '%s'", $cpo->GetFolderId()), SYNC_STATUS_FOLDERHIERARCHYCHANGED);

                                // if there is a valid state obtained after importing changes in a previous loop, we use that state
                                if ($actiondata["failstate"] && isset($actiondata["failstate"]["failedsyncstate"])) {
                                    $importer->Config($actiondata["failstate"]["failedsyncstate"], $cpo->GetConflict());
                                }
                                else
                                    $importer->Config($sc->GetParameter($cpo, "state"), $cpo->GetConflict());
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

                            // TODO check if the failsyncstate applies for conflict detection as well
                            if ($status == SYNC_STATUS_SUCCESS && $nchanges == 0)
                                $importer->LoadConflicts($cpo, $sc->GetParameter($cpo, "state"));

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
                                $message = ZPush::getSyncObjectFromFolderClass($cpo->GetContentClass());
                                $message->Decode(self::$decoder);

                                // set Ghosted fields
                                $message->emptySupported(self::$deviceManager->GetSupportedFields($cpo->GetFolderId()));
                                if(!self::$decoder->getElementEndTag()) // end applicationdata
                                    return false;
                            }

                            if ($status != SYNC_STATUS_SUCCESS) {
                                ZLog::Write(LOGLEVEL_WARN, "Ignored incoming change, global status indicates problem.");
                                continue;
                            }

                            // Detect incoming loop
                            // messages which were created/removed before will not have the same action executed again
                            // if a message is edited we perform this action "again", as the message could have been changed on the mobile in the meantime
                            $ignoreMessage = false;
                            if ($actiondata["failstate"]) {
                                // message was ADDED before, do NOT add it again
                                if ($element[EN_TAG] == SYNC_ADD && $actiondata["failstate"]["clientids"][$clientid]) {
                                    $ignoreMessage = true;

                                    // make sure no messages are sent back
                                    self::$deviceManager->SetWindowSize($cpo->GetFolderId(), 0);

                                    $actiondata["clientids"][$clientid] = $actiondata["failstate"]["clientids"][$clientid];
                                    $actiondata["statusids"][$clientid] = $actiondata["failstate"]["statusids"][$clientid];

                                    ZLog::Write(LOGLEVEL_WARN, sprintf("Mobile loop detected! Incoming new message '%s' was created on the server before. Replying with known new server id: %s", $clientid, $actiondata["clientids"][$clientid]));
                                }

                                // message was REMOVED before, do NOT attemp to remove it again
                                if ($element[EN_TAG] == SYNC_REMOVE && $actiondata["failstate"]["removeids"][$serverid]) {
                                    $ignoreMessage = true;

                                    // make sure no messages are sent back
                                    self::$deviceManager->SetWindowSize($cpo->GetFolderId(), 0);

                                    $actiondata["removeids"][$serverid] = $actiondata["failstate"]["removeids"][$serverid];
                                    $actiondata["statusids"][$serverid] = $actiondata["failstate"]["statusids"][$serverid];

                                    ZLog::Write(LOGLEVEL_WARN, sprintf("Mobile loop detected! Message '%s' was deleted by the mobile before. Replying with known status: %s", $clientid, $actiondata["statusids"][$serverid]));
                                }
                            }

                            if (!$ignoreMessage) {
                                switch($element[EN_TAG]) {
                                    case SYNC_MODIFY:
                                        try {
                                            $actiondata["modifyids"][] = $serverid;

                                            if (!$message->Check()) {
                                                $actiondata["statusids"][$serverid] = SYNC_STATUS_CLIENTSERVERCONVERSATIONERROR;
                                            }
                                            else {
                                                if(isset($message->read)) // Currently, 'read' is only sent by the PDA when it is ONLY setting the read flag.
                                                    $importer->ImportMessageReadFlag($serverid, $message->read);
                                                elseif (!isset($message->flag))
                                                    $importer->ImportMessageChange($serverid, $message);

                                                // email todoflags - some devices send todos flags together with read flags,
                                                // so they have to be handled separately
                                                if (isset($message->flag)){
                                                    $importer->ImportMessageChange($serverid, $message);
                                                }

                                                $actiondata["statusids"][$serverid] = SYNC_STATUS_SUCCESS;
                                            }
                                        }
                                        catch (StatusException $stex) {
                                            $actiondata["statusids"][$serverid] = $stex->getCode();
                                        }

                                        break;
                                    case SYNC_ADD:
                                        try {
                                            if (!$message->Check()) {
                                                $actiondata["statusids"][$clientid] = SYNC_STATUS_CLIENTSERVERCONVERSATIONERROR;
                                            }
                                            else {
                                                $actiondata["clientids"][$clientid] = false;
                                                $actiondata["clientids"][$clientid] = $importer->ImportMessageChange(false, $message);
                                                $actiondata["statusids"][$clientid] = SYNC_STATUS_SUCCESS;
                                            }
                                        }
                                        catch (StatusException $stex) {
                                           $actiondata["statusids"][$clientid] = $stex->getCode();
                                        }
                                        break;
                                    case SYNC_REMOVE:
                                        try {
                                            $actiondata["removeids"][] = $serverid;
                                            // if message deletions are to be moved, move them
                                            if($cpo->GetDeletesAsMoves()) {
                                                $folderid = self::$backend->GetWasteBasket();

                                                if($folderid) {
                                                    $importer->ImportMessageMove($serverid, $folderid);
                                                    $actiondata["statusids"][$serverid] = SYNC_STATUS_SUCCESS;
                                                    break;
                                                }
                                                else
                                                    ZLog::Write(LOGLEVEL_WARN, "Message should be moved to WasteBasket, but the Backend did not return a destination ID. Message is hard deleted now!");
                                            }

                                            $importer->ImportMessageDeletion($serverid);
                                            $actiondata["statusids"][$serverid] = SYNC_STATUS_SUCCESS;
                                        }
                                        catch (StatusException $stex) {
                                           $actiondata["statusids"][$serverid] = $stex->getCode();
                                        }
                                        break;
                                    case SYNC_FETCH:
                                        array_push($actiondata["fetchids"], $serverid);
                                        break;
                                }
                                self::$topCollector->AnnounceInformation(sprintf("Incoming %d", $nchanges),($nchanges>0)?true:false);
                            }

                            if(!self::$decoder->getElementEndTag()) // end add/change/delete/move
                                return false;
                        }

                        if ($status == SYNC_STATUS_SUCCESS) {
                            ZLog::Write(LOGLEVEL_INFO, sprintf("Processed '%d' incoming changes", $nchanges));
                            try {
                                // Save the updated state, which is used for the exporter later
                                $sc->AddParameter($cpo, "state", $importer->GetState());
                            }
                            catch (StatusException $stex) {
                               $status = $stex->getCode();
                            }
                        }

                        if(!self::$decoder->getElementEndTag()) // end PERFORM
                            return false;
                    }

                    // save the failsave state
                    if (!empty($actiondata["statusids"])) {
                        unset($actiondata["failstate"]);
                        $actiondata["failedsyncstate"] = $sc->GetParameter($cpo, "state");
                        self::$deviceManager->GetStateManager()->SetSyncFailState($actiondata);
                    }

                    // save actiondata
                    $sc->AddParameter($cpo, "actiondata", $actiondata);

                    if(!self::$decoder->getElementEndTag()) // end collection
                        return false;

                    // AS14 does not send GetChanges anymore. We should do it if there were no incoming changes
                    if (!isset($performaction) && !$sc->GetParameter($cpo, "getchanges") && $cpo->HasSyncKey())
                        $sc->AddParameter($cpo, "getchanges", true);
                } // END FOLDER

                if(!self::$decoder->getElementEndTag()) // end collections
                    return false;
            } // end FOLDERS

            if (self::$decoder->getElementStartTag(SYNC_HEARTBEATINTERVAL)) {
                $hbinterval = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag()) // SYNC_HEARTBEATINTERVAL
                    return false;
            }

            if (self::$decoder->getElementStartTag(SYNC_WAIT)) {
                $wait = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag()) // SYNC_WAIT
                    return false;

                // internally the heartbeat interval and the wait time are the same
                // heartbeat is in seconds, wait in minutes
                $hbinterval = $wait * 60;
            }

            if (self::$decoder->getElementStartTag(SYNC_WINDOWSIZE)) {
                $sc->SetGlobalWindowSize(self::$decoder->getElementContent());
                if(!self::$decoder->getElementEndTag()) // SYNC_WINDOWSIZE
                    return false;
            }

            if(self::$decoder->getElementStartTag(SYNC_PARTIAL))
                $partial = true;
            else
                $partial = false;

            if(!self::$decoder->getElementEndTag()) // end sync
                return false;
        }
        // we did not receive a SYNCHRONIZE block - assume empty sync
        else {
            $emtpysync = true;
        }
        // END SYNCHRONIZE

        // check heartbeat/wait time
        if (isset($hbinterval)) {
            if ($hbinterval < 60 || $hbinterval > 3540) {
                $status = SYNC_STATUS_INVALIDWAITORHBVALUE;
                ZLog::Write(LOGLEVEL_WARN, sprintf("HandleSync(): Invalid heartbeat or wait value '%s'", $hbinterval));
            }
        }

        // Partial & Empty Syncs need saved data to proceed with synchronization
        if ($status == SYNC_STATUS_SUCCESS && (! $sc->HasCollections() || $partial === true )) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): Partial or Empty sync requested. Retrieving data of synchronized folders."));

            // Load all collections - do not overwrite existing (received!), laod states and check permissions
            try {
                $sc->LoadAllCollections(false, true, true);
            }
            catch (StateNotFoundException $snfex) {
                $status = SYNC_STATUS_INVALIDSYNCKEY;
                self::$topCollector->AnnounceInformation("StateNotFoundException", true);
            }
            catch (StatusException $stex) {
               $status = SYNC_STATUS_FOLDERHIERARCHYCHANGED;
               self::$topCollector->AnnounceInformation(sprintf("StatusException code: %d", $status), true);
            }

            // update a few values
            foreach($sc as $folderid => $cpo) {
                // manually set getchanges parameter for this collection
                $sc->AddParameter($cpo, "getchanges", true);

                // set new global windowsize without marking the CPO as changed
                if ($sc->GetGlobalWindowSize())
                    $cpo->SetWindowSize($sc->GetGlobalWindowSize(), false);

                // announce WindowSize to DeviceManager
                self::$deviceManager->SetWindowSize($folderid, $cpo->GetWindowSize());
            }
        }

        // HEARTBEAT
        if ($status == SYNC_STATUS_SUCCESS && (isset($hbinterval) || $emtpysync == true)) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): Entering Heartbeat mode"));
            $interval = (defined('PING_INTERVAL') && PING_INTERVAL > 0) ? PING_INTERVAL : 30;

            if (isset($hbinterval))
                $sc->SetLifetime($hbinterval);

            // wait for changes
            try {
                $foundchanges = $sc->CheckForChanges($sc->GetLifetime(), $interval);
            }
            catch (StatusException $stex) {
               $status = SYNC_STATUS_FOLDERHIERARCHYCHANGED;
               self::$topCollector->AnnounceInformation(sprintf("StatusException code: %d", $status), true);
            }

            if ($foundchanges) {
                foreach ($sc->GetChangedFolderIds() as $folderid => $changecount) {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): heartbeat: found %d changes in '%s'", $changecount, $folderid));
                }
            }
        }

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): Start Output"));

        // Start the output
        self::$encoder->startWBXML();
        self::$encoder->startTag(SYNC_SYNCHRONIZE);
        {
            // TODO check alternatives -- global status?
            if (true) { //isset($foldersync)) {
                self::$encoder->startTag(SYNC_FOLDERS);
                {
                    foreach($sc as $folderid => $cpo) {
                        // get actiondata
                        $actiondata = $sc->GetParameter($cpo, "actiondata");

                        if (! $sc->GetParameter($cpo, "requested"))
                            ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): partial sync for folder class '%s' with id '%s'", $cpo->GetContentClass(), $cpo->GetFolderId()));

                        // TODO do not get Exporter / Changes if this is a fetch operation

                        // initialize exporter to get changecount
                        $changecount = 0;
                        // TODO observe if it works correct after merge of rev 716
                        // TODO we could check against $sc->GetChangedFolderIds() on heartbeat so we do not need to configure all exporter again
                        if($status == SYNC_STATUS_SUCCESS && ($sc->GetParameter($cpo, "getchanges") || ! $cpo->HasSyncKey())) {
                            try {
                                // Use the state from the importer, as changes may have already happened
                                $exporter = self::$backend->GetExporter($cpo->GetFolderId());

                                if ($exporter === false)
                                    throw new StatusException(sprintf("HandleSync() could not get an exporter for folder id '%s'", $cpo->GetFolderId()), SYNC_STATUS_FOLDERHIERARCHYCHANGED);

                                // Stream the messages directly to the PDA
                                $streamimporter = new ImportChangesStream(self::$encoder, ZPush::getSyncObjectFromFolderClass($cpo->GetContentClass()));

                                $exporter->Config($sc->GetParameter($cpo, "state"));
                                $exporter->ConfigContentParameters($cpo);
                                $exporter->InitializeExporter($streamimporter);

                                $changecount = $exporter->GetChangeCount();
                            }
                            catch (StatusException $stex) {
                               $status = $stex->getCode();
                            }
                            if (! $cpo->HasSyncKey())
                                self::$topCollector->AnnounceInformation(sprintf("Exporter registered. %d objects queued.", $changecount), true);
                            else if ($status != SYNC_STATUS_SUCCESS)
                                self::$topCollector->AnnounceInformation(sprintf("StatusException code: %d", $status), true);
                        }

                        if (! $sc->GetParameter($cpo, "requested") && $cpo->HasSyncKey() && $changecount == 0)
                            continue;

                        // Get a new sync key to output to the client if any changes have been send or will are available
                        if (!empty($actiondata["modifyids"]) ||
                            !empty($actiondata["clientids"]) ||
                            !empty($actiondata["removeids"]) ||
                            $changecount > 0 || ! $cpo->HasSyncKey())
                                $cpo->SetNewSyncKey(self::$deviceManager->GetStateManager()->GetNewSyncKey($cpo->GetSyncKey()));

                        self::$encoder->startTag(SYNC_FOLDER);

                        if($cpo->HasContentClass()) {
                            self::$encoder->startTag(SYNC_FOLDERTYPE);
                                self::$encoder->content($cpo->GetContentClass());
                            self::$encoder->endTag();
                        }

                        self::$encoder->startTag(SYNC_SYNCKEY);
                        if($status == SYNC_STATUS_SUCCESS && $cpo->HasNewSyncKey())
                            self::$encoder->content($cpo->GetNewSyncKey());
                        else
                            self::$encoder->content($cpo->GetSyncKey());
                        self::$encoder->endTag();

                        self::$encoder->startTag(SYNC_FOLDERID);
                            self::$encoder->content($cpo->GetFolderId());
                        self::$encoder->endTag();

                        self::$encoder->startTag(SYNC_STATUS);
                            self::$encoder->content($status);
                        self::$encoder->endTag();

                        // Output IDs and status for incoming items & requests
                        if($status == SYNC_STATUS_SUCCESS && (
                            !empty($actiondata["clientids"]) ||
                            !empty($actiondata["modifyids"]) ||
                            !empty($actiondata["removeids"]) ||
                            !empty($actiondata["fetchids"]) )) {

                            self::$encoder->startTag(SYNC_REPLIES);
                            // output result of all new incoming items
                            foreach($actiondata["clientids"] as $clientid => $serverid) {
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
                                        self::$encoder->content((isset($actiondata["statusids"][$clientid])?$actiondata["statusids"][$clientid]:SYNC_STATUS_CLIENTSERVERCONVERSATIONERROR));
                                    self::$encoder->endTag();
                                self::$encoder->endTag();
                            }

                            // loop through modify operations which were not a success, send status
                            foreach($actiondata["modifyids"] as $serverid) {
                                if (isset($actiondata["statusids"][$serverid]) && $actiondata["statusids"][$serverid] !== SYNC_STATUS_SUCCESS) {
                                    self::$encoder->startTag(SYNC_MODIFY);
                                        self::$encoder->startTag(SYNC_SERVERENTRYID);
                                            self::$encoder->content($serverid);
                                        self::$encoder->endTag();
                                        self::$encoder->startTag(SYNC_STATUS);
                                            self::$encoder->content($actiondata["statusids"][$serverid]);
                                        self::$encoder->endTag();
                                    self::$encoder->endTag();
                                }
                            }

                            // loop through remove operations which were not a success, send status
                            foreach($actiondata["removeids"] as $serverid) {
                                if (isset($actiondata["statusids"][$serverid]) && $actiondata["statusids"][$serverid] !== SYNC_STATUS_SUCCESS) {
                                    self::$encoder->startTag(SYNC_REMOVE);
                                        self::$encoder->startTag(SYNC_SERVERENTRYID);
                                            self::$encoder->content($serverid);
                                        self::$encoder->endTag();
                                        self::$encoder->startTag(SYNC_STATUS);
                                            self::$encoder->content($actiondata["statusids"][$serverid]);
                                        self::$encoder->endTag();
                                    self::$encoder->endTag();
                                }
                            }

                            if (!empty($actiondata["fetchids"]))
                                self::$topCollector->AnnounceInformation(sprintf("Fetching %d objects ", count($actiondata["fetchids"])), true);

                            foreach($actiondata["fetchids"] as $id) {
                                try {
                                    $fetchstatus = SYNC_STATUS_SUCCESS;
                                    $data = self::$backend->Fetch($cpo->GetFolderId(), $id, $cpo);

                                    // check if the message is broken
                                    if (ZPush::GetDeviceManager(false) && ZPush::GetDeviceManager()->DoNotStreamMessage($id, $data)) {
                                        ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): message not to be streamed as requested by DeviceManager.", $id));
                                        $fetchstatus = SYNC_STATUS_CLIENTSERVERCONVERSATIONERROR;
                                    }
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
                                            $data->Encode(self::$encoder);
                                        self::$encoder->endTag();
                                    }
                                    else
                                        ZLog::Write(LOGLEVEL_WARN, sprintf("Unable to Fetch '%s'", $id));
                                self::$encoder->endTag();

                            }
                            self::$encoder->endTag();
                        }

                        if($sc->GetParameter($cpo, "getchanges") && $cpo->HasFolderId() && $cpo->HasContentClass() && $cpo->HasSyncKey()) {
                            $windowSize = self::$deviceManager->GetWindowSize($cpo->GetFolderId(), $cpo->GetContentClass(), $cpo->GetUuid(), $cpo->GetUuidCounter(), $changecount);

                            if($changecount > $windowSize) {
                                self::$encoder->startTag(SYNC_MOREAVAILABLE, false, true);
                            }
                        }

                        // Stream outgoing changes
                        if($status == SYNC_STATUS_SUCCESS && $sc->GetParameter($cpo, "getchanges") === true && $windowSize > 0) {
                            self::$topCollector->AnnounceInformation(sprintf("Streaming data of %d objects", (($changecount > $windowSize)?$windowSize:$changecount)));

                            // Output message changes per folder
                            self::$encoder->startTag(SYNC_PERFORM);

                            $n = 0;
                            while(1) {
                                $progress = $exporter->Synchronize();
                                if(!is_array($progress))
                                    break;
                                $n++;

                                if($n >= $windowSize) {
                                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): Exported maxItems of messages: %d / %d", $n, $changecount));
                                    break;
                                }

                            }
                            self::$encoder->endTag();
                            self::$topCollector->AnnounceInformation(sprintf("Outgoing %d objects%s", $n, ($n >= $windowSize)?" of ".$changecount:""), true);
                        }

                        self::$encoder->endTag();

                        // Save the sync state for the next time
                        if($cpo->HasNewSyncKey()) {
                            self::$topCollector->AnnounceInformation("Saving state");

                            try {
                                if (isset($exporter) && $exporter)
                                    $state = $exporter->GetState();

                                // nothing exported, but possibly imported
                                else if (isset($importer) && $importer)
                                    $state = $importer->GetState();

                                // if a new request without state information (hierarchy) save an empty state
                                else if (! $cpo->HasSyncKey())
                                    $state = "";
                            }
                            catch (StatusException $stex) {
                               $status = $stex->getCode();
                            }


                            if (isset($state) && $status == SYNC_STATUS_SUCCESS)
                                self::$deviceManager->GetStateManager()->SetSyncState($cpo->GetNewSyncKey(), $state, $cpo->GetFolderId());
                            else
                                ZLog::Write(LOGLEVEL_ERROR, sprintf("HandleSync(): error saving '%s' - no state information available", $cpo->GetNewSyncKey()));
                        }

                        // save CPO
                        // TODO check if we need changed data in case of a StatusException
                        if ($status == SYNC_STATUS_SUCCESS)
                            $sc->SaveCollection($cpo);

                    } // END foreach collection
                }
                self::$encoder->endTag(); //SYNC_FOLDERS
            }
        }
        self::$encoder->endTag(); //SYNC_SYNCHRONIZE

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
        $sc = new SyncCollections();

        if(!self::$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_GETITEMESTIMATE))
            return false;

        if(!self::$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERS))
            return false;

        while(self::$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDER)) {
            $cpo = new ContentParameters();

            if (Request::GetProtocolVersion() >= 14.0) {
                if(self::$decoder->getElementStartTag(SYNC_SYNCKEY)) {
                    $cpo->SetSyncKey(self::$decoder->getElementContent());

                    if(!self::$decoder->getElementEndTag())
                        return false;
                }

                if(self::$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERID)) {
                    $cpo->SetFolderId( self::$decoder->getElementContent());

                    if(!self::$decoder->getElementEndTag())
                        return false;
                }

                // conversation mode requested
                if(self::$decoder->getElementStartTag(SYNC_CONVERSATIONMODE)) {
                    $cpo->SetConversationMode(true);
                    if(($conversationmode = self::$decoder->getElementContent()) !== false) {
                        $cpo->SetConversationMode((boolean)$conversationmode);
                        if(!self::$decoder->getElementEndTag())
                            return false;
                    }
                }

                if(self::$decoder->getElementStartTag(SYNC_OPTIONS)) {
                    while(1) {
                        if(self::$decoder->getElementStartTag(SYNC_FILTERTYPE)) {
                            $cpo->SetFilterType(self::$decoder->getElementContent());
                            if(!self::$decoder->getElementEndTag())
                                return false;
                        }

                        if(self::$decoder->getElementStartTag(SYNC_FOLDERTYPE)) {
                            $cpo->SetContentClass(self::$decoder->getElementContent());
                            if(!self::$decoder->getElementEndTag())
                                return false;
                        }

                        if(self::$decoder->getElementStartTag(SYNC_MAXITEMS)) {
                            $cpo->SetWindowSize($maxitems = self::$decoder->getElementContent());
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
            }
            else {
                //get items estimate does not necessarily send the folder type
                if(self::$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERTYPE)) {
                    $cpo->SetContentClass(self::$decoder->getElementContent());

                    if(!self::$decoder->getElementEndTag())
                        return false;
                }

                if(self::$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERID)) {
                    $cpo->SetFolderId(self::$decoder->getElementContent());

                    if(!self::$decoder->getElementEndTag())
                        return false;
                }

                if(!self::$decoder->getElementStartTag(SYNC_FILTERTYPE))
                    return false;

                $cpo->SetFilterType(self::$decoder->getElementContent());

                if(!self::$decoder->getElementEndTag())
                    return false;

                if(!self::$decoder->getElementStartTag(SYNC_SYNCKEY))
                    return false;

                $cpo->SetSyncKey(self::$decoder->getElementContent());

                if(!self::$decoder->getElementEndTag())
                    return false;
            }

            if(!self::$decoder->getElementEndTag())
                return false; //SYNC_GETITEMESTIMATE_FOLDER

            // Process folder data

            //In AS 14 request only collectionid is sent, without class
            if (! $cpo->HasContentClass() && $cpo->HasFolderId())
                $cpo->SetContentClass(self::$deviceManager->GetFolderClassFromCacheByID($cpo->GetFolderId()));

            // compatibility mode AS 1.0 - get folderid which was sent during GetHierarchy()
            if (! $cpo->HasFolderId() && $cpo->HasContentClass()) {
                $cpo->SetFolderId(self::$deviceManager->GetFolderIdFromCacheByClass($cpo->GetContentClass()));
            }

            // Add collection to SC and load state
            $sc->AddCollection($cpo);
            try {
                $sc->AddParameter($cpo, "state", self::$deviceManager->GetStateManager()->GetSyncState($cpo->GetSyncKey()));

                // if this is an additional folder the backend has to be setup correctly
                if (!self::$backend->Setup(ZPush::GetAdditionalSyncFolderStore($cpo->GetFolderId())))
                    throw new StatusException(sprintf("HandleSync() could not Setup() the backend for folder id '%s'", $cpo->GetFolderId()), SYNC_STATUS_FOLDERHIERARCHYCHANGED);
            }
            catch (StateNotFoundException $snfex) {
                $sc->AddParameter($cpo, "status", SYNC_GETITEMESTSTATUS_SYNCKKEYINVALID);
                self::$topCollector->AnnounceInformation("StateNotFoundException", true);
            }
            catch (StatusException $stex) {
                $sc->AddParameter($cpo, "status", SYNC_GETITEMESTSTATUS_SYNCSTATENOTPRIMED);
                self::$topCollector->AnnounceInformation("StatusException SYNCSTATENOTPRIMED", true);
            }
        }
        if(!self::$decoder->getElementEndTag())
            return false; //SYNC_GETITEMESTIMATE_FOLDERS

        if(!self::$decoder->getElementEndTag())
            return false; //SYNC_GETITEMESTIMATE_GETITEMESTIMATE

        self::$encoder->startWBXML();
        self::$encoder->startTag(SYNC_GETITEMESTIMATE_GETITEMESTIMATE);
        {
            $status = SYNC_GETITEMESTSTATUS_SUCCESS;
            // look for changes in all collections

            try {
                $sc->CountChanges();
            }
            catch (StatusException $ste) {
                $status = SYNC_GETITEMESTSTATUS_COLLECTIONINVALID;
            }
            $changes = $sc->GetChangedFolderIds();

            foreach($sc as $folderid => $cpo) {
                self::$encoder->startTag(SYNC_GETITEMESTIMATE_RESPONSE);
                {
                    $changecount = (isset($changes[$folderid]) && $changes[$folderid] !== false)? $changes[$folderid] : 0;
                    if ($sc->GetParameter($cpo, "status"))
                        $status = $sc->GetParameter($cpo, "status");

                    self::$encoder->startTag(SYNC_GETITEMESTIMATE_STATUS);
                    self::$encoder->content($status);
                    self::$encoder->endTag();

                    self::$encoder->startTag(SYNC_GETITEMESTIMATE_FOLDER);
                    {
                        self::$encoder->startTag(SYNC_GETITEMESTIMATE_FOLDERTYPE);
                        self::$encoder->content($cpo->GetContentClass());
                        self::$encoder->endTag();

                        self::$encoder->startTag(SYNC_GETITEMESTIMATE_FOLDERID);
                        self::$encoder->content($cpo->GetFolderId());
                        self::$encoder->endTag();

                        self::$encoder->startTag(SYNC_GETITEMESTIMATE_ESTIMATE);
                        self::$encoder->content($changecount);
                        self::$encoder->endTag();
                    }
                    self::$encoder->endTag();
                    if ($changecount > 0)
                        self::$topCollector->AnnounceInformation(sprintf("%s %d changes", $cpo->GetContentClass(), $changecount), true);
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
        $attname = Request::GetGETAttachmentName();
        if(!$attname)
            return false;

        try {
            $attachment = self::$backend->GetAttachmentData($attname);
            $stream = $attachment->data;
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleGetAttachment(): attachment stream from backend: %s", $stream));

            header("Content-Type: application/octet-stream");
            $l = 0;
            while (!feof($stream)) {
                $d = fgets($stream, 4096);
                $l += strlen($d);
                echo $d;

                // announce an update every 100K
                if (($l/1024) % 100 == 0)
                    self::$topCollector->AnnounceInformation(sprintf("Streaming attachment: %d KB sent", round($l/1024)));
            }
            fclose($stream);
            self::$topCollector->AnnounceInformation(sprintf("Streamed %d KB attachment", $l/1024), true);
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleGetAttachment(): attachment with %d KB sent to mobile", $l/1024));

        }
        catch (StatusException $s) {
            // StatusException already logged so we just need to pass it upwards to send a HTTP error
            throw new HTTPReturnCodeException($s->getMessage(), HTTP_CODE_500, null, LOGLEVEL_DEBUG);
        }

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
        $interval = (defined('PING_INTERVAL') && PING_INTERVAL > 0) ? PING_INTERVAL : 30;
        $pingstatus = false;

        // Contains all requested folders (containers)
        $sc = new SyncCollections();

        // Load all collections - do load states and check permissions
        try {
            $sc->LoadAllCollections(true, true, true);
        }
        catch (StateNotFoundException $snfex) {
            $pingstatus = SYNC_PINGSTATUS_FOLDERHIERSYNCREQUIRED;
            self::$topCollector->AnnounceInformation("StateNotFoundException: require HierarchySync", true);
        }
        catch (StatusException $stex) {
            $pingstatus = SYNC_PINGSTATUS_FOLDERHIERSYNCREQUIRED;
            self::$topCollector->AnnounceInformation("StatusException: require HierarchySync", true);
        }

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandlePing(): reference PolicyKey for PING: %s", $sc->GetReferencePolicyKey()));

        // receive PING initialization data
        if(self::$decoder->getElementStartTag(SYNC_PING_PING)) {
            self::$topCollector->AnnounceInformation("Processing PING data");
            ZLog::Write(LOGLEVEL_DEBUG, "HandlePing(): initialization data received");

            if(self::$decoder->getElementStartTag(SYNC_PING_LIFETIME)) {
                $sc->SetLifetime(self::$decoder->getElementContent());
                self::$decoder->getElementEndTag();
            }

            if(self::$decoder->getElementStartTag(SYNC_PING_FOLDERS)) {
                // remove PingableFlag from all collections
                foreach ($sc as $folderid => $cpo)
                    $cpo->DelPingableFlag();

                while(self::$decoder->getElementStartTag(SYNC_PING_FOLDER)) {
                    while(1) {
                        if(self::$decoder->getElementStartTag(SYNC_PING_SERVERENTRYID)) {
                            $folderid = self::$decoder->getElementContent();
                            self::$decoder->getElementEndTag();
                        }
                        if(self::$decoder->getElementStartTag(SYNC_PING_FOLDERTYPE)) {
                            $class = self::$decoder->getElementContent();
                            self::$decoder->getElementEndTag();
                        }

                        $e = self::$decoder->peek();
                        if($e[EN_TYPE] == EN_TYPE_ENDTAG) {
                            self::$decoder->getElementEndTag();
                            break;
                        }
                    }

                    $cpo = $sc->GetCollection($folderid);
                    if (! $cpo)
                        $pingstatus = SYNC_PINGSTATUS_FOLDERHIERSYNCREQUIRED;

                    if ($class == $cpo->GetContentClass()) {
                        $cpo->SetPingableFlag(true);
                        ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandlePing(): using saved sync state for '%s' id '%s'", $cpo->GetContentClass(), $folderid));
                    }

                }
                if(!self::$decoder->getElementEndTag())
                    return false;
            }
            if(!self::$decoder->getElementEndTag())
                return false;

            // save changed data
            foreach ($sc as $folderid => $cpo)
                $sc->SaveCollection($cpo);
        } // END SYNC_PING_PING

        // Check for changes on the default LifeTime, set interval and ONLY on pingable collections
        try {
            $foundchanges = $sc->CheckForChanges($sc->GetLifetime(), $interval, true);
        }
        catch (StatusException $ste) {
            switch($ste->getCode()) {
                case SyncCollections::ERROR_NO_COLLECTIONS:
                    $pingstatus = SYNC_PINGSTATUS_FAILINGPARAMS;
                    break;
                case SyncCollections::ERROR_WRONG_HIERARCHY:
                    $pingstatus = SYNC_PINGSTATUS_FOLDERHIERSYNCREQUIRED;
                    break;

            }
        }

        self::$encoder->StartWBXML();
        self::$encoder->startTag(SYNC_PING_PING);
        {
            self::$encoder->startTag(SYNC_PING_STATUS);
            if (isset($pingstatus) && $pingstatus)
                self::$encoder->content($pingstatus);
            else
                self::$encoder->content($foundchanges ? SYNC_PINGSTATUS_CHANGES : SYNC_PINGSTATUS_HBEXPIRED);
            self::$encoder->endTag();

            self::$encoder->startTag(SYNC_PING_FOLDERS);
            foreach ($sc->GetChangedFolderIds() as $folderid => $changecount) {
                if ($changecount > 0) {
                    self::$encoder->startTag(SYNC_PING_FOLDER);
                    self::$encoder->content($folderid);
                    self::$encoder->endTag();
                    self::$topCollector->AnnounceInformation(sprintf("Found change in %s", $sc->GetCollection($folderid)->GetContentClass()), true);
                }
            }
            self::$encoder->endTag();
        }
        self::$encoder->endTag();

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
        $status = SYNC_COMMONSTATUS_SUCCESS;

        if (self::$decoder->IsWBXML()) {
            $el = self::$decoder->getElement();

            if($el[EN_TYPE] != EN_TYPE_STARTTAG)
                return false;

            $sendmail = $smartreply = $smartforward = false;
            if($el[EN_TAG] == SYNC_COMPOSEMAIL_SENDMAIL)
                $sendmail = true;
            else if($el[EN_TAG] == SYNC_COMPOSEMAIL_SMARTREPLY)
                $smartreply = true;
            else if($el[EN_TAG] == SYNC_COMPOSEMAIL_SMARTFORWARD)
                $smartforward = true;

            if(!$sendmail && !$smartreply && !$smartforward)
                return false;

            $saveInSent = false;
            if(self::$decoder->getElementStartTag(SYNC_COMPOSEMAIL_CLIENTID)) {
                $clientid = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag()) //SYNC_COMPOSEMAIL_CLIENTID
                    return false;
            }

            if(self::$decoder->getElementStartTag(SYNC_COMPOSEMAIL_SAVEINSENTITEMS)) {
                $saveInSent = true;
            }

            //TODO replaceMime
            //the client modified the contents of the original message and will attach it itself
            $replaceMime = false;
            if(self::$decoder->getElementStartTag(SYNC_COMPOSEMAIL_REPLACEMIME)) {
                $replaceMime = true;
            }

            //TODO longid
            if(self::$decoder->getElementStartTag(SYNC_COMPOSEMAIL_LONGID)) {
                $longid = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag()) //SYNC_COMPOSEMAIL_LONGID
                    return false;
            }

            //The format of the InstanceId element is a dateTime value that includes the punctuation separators. For example, 2010-03-20T22:40:00.000Z.
            //TODO instanceid
            if(self::$decoder->getElementStartTag(SYNC_COMPOSEMAIL_INSTANCEID)) {
                $instanceid = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag()) //SYNC_COMPOSEMAIL_INSTANCEID
                    return false;
            }

            //TODO accountid
            if(self::$decoder->getElementStartTag(SYNC_COMPOSEMAIL_ACCOUNTID)) {
                $accountid = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag()) //SYNC_COMPOSEMAIL_ACCOUNTID
                return false;
            }

            if(self::$decoder->getElementStartTag(SYNC_COMPOSEMAIL_SOURCE)) {
                if(self::$decoder->getElementStartTag(SYNC_COMPOSEMAIL_FOLDERID)) {
                    $parent = self::$decoder->getElementContent();
                    if(!self::$decoder->getElementEndTag()) //SYNC_COMPOSEMAIL_FOLDERID
                        return false;
                }

                if(self::$decoder->getElementStartTag(SYNC_COMPOSEMAIL_ITEMID)) {
                    $replyid = self::$decoder->getElementContent();
                    if(!self::$decoder->getElementEndTag()) //SYNC_COMPOSEMAIL_ITEMID
                        return false;
                }

                if(!self::$decoder->getElementEndTag()) //SYNC_COMPOSEMAIL_SOURCE
                    return false;
            }

            if(self::$decoder->getElementStartTag(SYNC_COMPOSEMAIL_MIME)) {
                $rfc822 = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag()) //SYNC_COMPOSEMAIL_MIME
                    return false;
            }

            if(!self::$decoder->getElementEndTag()) //SYNC_COMPOSEMAIL_SENDMAIL, SYNC_COMPOSEMAIL_SMARTREPLY or SYNC_COMPOSEMAIL_SMARTFORWARD
                return false;
        }
        else {
            $rfc822 = self::$decoder->GetPlainInputStream();
            // no wbxml output is provided, only a http OK
            $saveInSent = Request::GetGETSaveInSent();
        }
        self::$topCollector->AnnounceInformation(sprintf("Sending email with %d bytes", strlen($rfc822)), true);
        try {
            // if replyid is set then it's a smartreply or smartforward. Find out which is it and set forward or reply
            // if replaceMime is set and true, then do not get the original message from the server
            if (isset($replyid)) {
                $forward = (isset($smartforward) && $smartforward && (!isset($replaceMime) || (isset($replaceMime) && !$replaceMime))) ? $replyid : false;
                $reply = (isset($smartreply) && $smartreply && (!isset($replaceMime) || (isset($replaceMime) && !$replaceMime))) ? $replyid : false;
            }
            $status = self::$backend->SendMail($rfc822, $forward, $reply, $parent, $saveInSent);
        }
        catch (StatusException $se) {
            $status = $se->getCode();
            $statusMessage = $se->getMessage();
        }

        if (self::$decoder->IsWBXML()) {
            self::$encoder->StartWBXML();
            self::$encoder->startTag(SYNC_COMPOSEMAIL_SENDMAIL);
                self::$encoder->startTag(SYNC_COMPOSEMAIL_STATUS);
                self::$encoder->content($status); //TODO return the correct status
                self::$encoder->endTag();
            self::$encoder->endTag();
        }
        elseif ($status != SYNC_COMMONSTATUS_SUCCESS) {
            throw new HTTPReturnCodeException($statusMessage, HTTP_CODE_500, null, LOGLEVEL_WARN);
        }

        return $status;
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
        return self::HandleSendMail(Request::GetGETItemId(), false, Request::GetGETCollectionId());
    }

    /**
     * Reply and sends an e-mail
     * SmartReply should add the original message to the end of the message body
     *
     * @access private
     * @return boolean
     */
    static private function HandleSmartReply() {
        return self::HandleSendMail(false, Request::GetGETItemId(), Request::GetGETCollectionId());
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
            $syncstate = self::$deviceManager->GetStateManager()->GetSyncState($synckey);
            $newsynckey = self::$deviceManager->GetStateManager()->GetNewSyncKey($synckey);

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
                $changesMem->SetDestinationImporter($importer);

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

        self::$topCollector->AnnounceInformation(sprintf("Operation status %d", $status), true);

        // Save the sync state for the next time
        self::$deviceManager->GetStateManager()->SetSyncState($newsynckey, $importer->GetState());

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
            self::$topCollector->AnnounceInformation(sprintf("Operation status %d", $status), true);
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
            self::$topCollector->AnnounceInformation("Policies deployed", true);
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

                            $prov = self::$deviceManager->GetProvisioningObject();
                            if (!$prov->Check())
                                throw new FatalException("Invalid policies!");

                            $prov->Encode(self::$encoder);
                        self::$encoder->endTag();
                    }
                    else {
                        ZLog::Write(LOGLEVEL_WARN, "Wrong policy type");
                        self::$topCollector->AnnounceInformation("Policytype not supported", true);
                        return false;
                    }
                    self::$topCollector->AnnounceInformation("Updated provisiong", true);

                    self::$encoder->endTag();//data
                }
                self::$encoder->endTag();//policy
            self::$encoder->endTag(); //policies
        }

        //wipe data if a higher RWSTATUS is requested
        if ($rwstatus > SYNC_PROVISION_RWSTATUS_OK) {
            self::$encoder->startTag(SYNC_PROVISION_REMOTEWIPE, false, true);
            self::$deviceManager->SetProvisioningWipeStatus(($rwstatusWiped)?SYNC_PROVISION_RWSTATUS_WIPED:SYNC_PROVISION_RWSTATUS_REQUESTED);
            self::$topCollector->AnnounceInformation(sprintf("Remote wipe %s", ($rwstatusWiped)?"executed":"requested"), true);
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
                if ($searchname == ISearchProvider::SEARCH_GAL) {
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
            self::$topCollector->AnnounceInformation(sprintf("Unsupported type '%s''", $searchname), true);
        }
        $searchprovider->Disconnect();

        self::$topCollector->AnnounceInformation(sprintf("'%s' search found %d results", $searchname, $rows['searchtotal']), true);

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

                                    if (isset($u[SYNC_GAL_ALIAS])) {
                                        self::$encoder->startTag(SYNC_GAL_ALIAS);
                                        self::$encoder->content($u[SYNC_GAL_ALIAS]);
                                        self::$encoder->endTag();
                                    }

                                    // Always send the firstname, even empty. Nokia needs this to display the entry
                                    self::$encoder->startTag(SYNC_GAL_FIRSTNAME);
                                    self::$encoder->content((isset($u[SYNC_GAL_FIRSTNAME]))?$u[SYNC_GAL_FIRSTNAME]:"");
                                    self::$encoder->endTag();

                                    self::$encoder->startTag(SYNC_GAL_LASTNAME);
                                    self::$encoder->content((isset($u[SYNC_GAL_LASTNAME]))?$u[SYNC_GAL_LASTNAME]:"No name");
                                    self::$encoder->endTag();

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

    /**
     * Provides batched online handling for Fetch, EmptyFolderContents and Move
     *
     * @access private
     * @return boolean
     */
    static private function HandleItemOperations() {
        // Parse input
        if(!self::$decoder->getElementStartTag(SYNC_ITEMOPERATIONS_ITEMOPERATIONS))
            return false;

        //TODO check if multiple item operations are possible in one request
        $el = self::$decoder->getElement();

        if($el[EN_TYPE] != EN_TYPE_STARTTAG)
            return false;
        //ItemOperations can either be Fetch, EmptyFolderContents or Move
        $fetch = $efc = $move = false;
        if($el[EN_TAG] == SYNC_ITEMOPERATIONS_FETCH)
            $fetch = true;
        else if($el[EN_TAG] == SYNC_ITEMOPERATIONS_EMPTYFOLDERCONTENTS)
            $efc = true;
        else if($el[EN_TAG] == SYNC_ITEMOPERATIONS_MOVE)
            $move = true;

        if(!$fetch && !$efc && !$move) {
            ZLog::Write(LOGLEVEL_DEBUG, "Unknown item operation:".print_r($el, 1));
            return false;
        }

        if ($fetch) {
            if(!self::$decoder->getElementStartTag(SYNC_ITEMOPERATIONS_STORE))
                return false;
            $store = self::$decoder->getElementContent();
            if(!self::$decoder->getElementEndTag())
                return false;//SYNC_ITEMOPERATIONS_STORE

            if(self::$decoder->getElementStartTag(SYNC_FOLDERID)) {
                $folderid = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag())
                    return false;//SYNC_FOLDERID
            }

            if(self::$decoder->getElementStartTag(SYNC_SERVERENTRYID)) {
                $serverid = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag())
                    return false;//SYNC_SERVERENTRYID
            }

            if(self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_FILEREFERENCE)) {
                $filereference = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag())
                    return false;//SYNC_AIRSYNCBASE_FILEREFERENCE
            }

            if(self::$decoder->getElementStartTag(SYNC_ITEMOPERATIONS_OPTIONS)) {
                //TODO other options
                //schema
                //range
                //username
                //password
                //airsync:mimesupport
                //bodypartpreference
                //rm:RightsManagementSupport

                // Save all OPTIONS into a ContentParameters object
                $collection["cpo"] = new ContentParameters();
                while(1) {
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
                            return false;//SYNC_AIRSYNCBASE_BODYPREFERENCE
                    }
                    //break if it reached the endtag
                    $e = self::$decoder->peek();
                    if($e[EN_TYPE] == EN_TYPE_ENDTAG) {
                        self::$decoder->getElementEndTag();
                        break;
                    }
                }
            }
        }

        //TODO EmptyFolderContents
        //TODO move

        if(!self::$decoder->getElementEndTag())
            return false;//SYNC_ITEMOPERATIONS_ITEMOPERATIONS

        $status = SYNC_ITEMOPERATIONSSTATUS_SUCCESS;
        //TODO status handling

        self::$encoder->startWBXML();

        self::$encoder->startTag(SYNC_ITEMOPERATIONS_ITEMOPERATIONS);

        self::$encoder->startTag(SYNC_ITEMOPERATIONS_STATUS);
        self::$encoder->content($status);
        self::$encoder->endTag();//SYNC_ITEMOPERATIONS_STATUS

        self::$encoder->startTag(SYNC_ITEMOPERATIONS_RESPONSE);
        self::$encoder->startTag(SYNC_ITEMOPERATIONS_FETCH);

            self::$encoder->startTag(SYNC_ITEMOPERATIONS_STATUS);
            self::$encoder->content($status);
            self::$encoder->endTag();//SYNC_ITEMOPERATIONS_STATUS

            if (isset($folderid) && isset($serverid)) {
                self::$encoder->startTag(SYNC_FOLDERID);
                self::$encoder->content($folderid);
                self::$encoder->endTag(); // end SYNC_FOLDERID

                self::$encoder->startTag(SYNC_SERVERENTRYID);
                self::$encoder->content($serverid);
                self::$encoder->endTag(); // end SYNC_SERVERENTRYID

                self::$encoder->startTag(SYNC_FOLDERTYPE);
                self::$encoder->content("Email");
                self::$encoder->endTag();

                $data = self::$backend->Fetch($folderid, $serverid, $collection["cpo"]);
            }

            if (isset($filereference)) {
                self::$encoder->startTag(SYNC_AIRSYNCBASE_FILEREFERENCE);
                self::$encoder->content($filereference);
                self::$encoder->endTag(); // end SYNC_AIRSYNCBASE_FILEREFERENCE

                $data = self::$backend->GetAttachmentData($filereference);
            }

            //TODO put it in try catch block

            if (isset($data)) {
                self::$encoder->startTag(SYNC_ITEMOPERATIONS_PROPERTIES);
                $data->Encode(self::$encoder);
                self::$encoder->endTag(); //SYNC_ITEMOPERATIONS_PROPERTIES
            }

        self::$encoder->endTag();//SYNC_ITEMOPERATIONS_FETCH
        self::$encoder->endTag();//SYNC_ITEMOPERATIONS_RESPONSE

        self::$encoder->endTag();//SYNC_ITEMOPERATIONS_ITEMOPERATIONS

        return true;
    }

    /**
     * Handles get and set operations on global properties as well as out of office settings
     */
    static private function HandleSettings() {
        if (!self::$decoder->getElementStartTag(SYNC_SETTINGS_SETTINGS))
            return false;

        //save the request parameters
        $request = array();

        // Loop through properties. Possible are:
        // - Out of office
        // - DevicePassword
        // - DeviceInformation
        // - UserInformation
        // Each of them should only be once per request. Each property must be processed in order.
        while (1) {
            $propertyName = "";
            if (self::$decoder->getElementStartTag(SYNC_SETTINGS_OOF)) {
                $propertyName = SYNC_SETTINGS_OOF;
            }
            if (self::$decoder->getElementStartTag(SYNC_SETTINGS_DEVICEPW)) {
                $propertyName = SYNC_SETTINGS_DEVICEPW;
            }
            if (self::$decoder->getElementStartTag(SYNC_SETTINGS_DEVICEINFORMATION)) {
                $propertyName = SYNC_SETTINGS_DEVICEINFORMATION;
            }
            if (self::$decoder->getElementStartTag(SYNC_SETTINGS_USERINFORMATION)) {
                $propertyName = SYNC_SETTINGS_USERINFORMATION;
            }
            //TODO - check if it is necessary
            //no property name available - break
            if (!$propertyName)
                break;

            //the property name is followed by either get or set
            if (self::$decoder->getElementStartTag(SYNC_SETTINGS_GET)) {
                //get is only available for OOF and user information
                switch ($propertyName) {
                    case SYNC_SETTINGS_OOF:
                        $oofGet = new SyncOOF();
                        $oofGet->Decode(self::$decoder);
                        if(!self::$decoder->getElementEndTag())
                            return false; // SYNC_SETTINGS_GET
                        break;

                    case SYNC_SETTINGS_USERINFORMATION:
                        $userInformation = new SyncUserInformation();
                        break;

                    default:
                        //TODO: a special status code needed?
                        ZLog::Write(LOGLEVEL_WARN, sprintf ("This property ('%s') is not allowed to use get in request", $propertyName));
                }
            }
            elseif (self::$decoder->getElementStartTag(SYNC_SETTINGS_SET)) {
                //set is available for OOF, device password and device information
                switch ($propertyName) {
                    case SYNC_SETTINGS_OOF:
                        $oofSet = new SyncOOF();
                        $oofSet->Decode(self::$decoder);
                        //TODO check - do it after while(1) finished?
                        break;

                    case SYNC_SETTINGS_DEVICEPW:
                        //TODO device password
                        $devicepassword = new SyncDevicePassword();
                        $devicepassword->Decode(self::$decoder);
                        break;

                    case SYNC_SETTINGS_DEVICEINFORMATION:
                        $deviceinformation = new SyncDeviceInformation();
                        $deviceinformation->Decode(self::$decoder);
                        //TODO handle deviceinformation
                        break;

                    default:
                        //TODO: a special status code needed?
                        ZLog::Write(LOGLEVEL_WARN, sprintf ("This property ('%s') is not allowed to use set in request", $propertyName));
                }

                if(!self::$decoder->getElementEndTag())
                    return false; // SYNC_SETTINGS_SET
            }
            else {
                ZLog::Write(LOGLEVEL_WARN, sprintf("Neither get nor set found for property '%s'", $propertyName));
                return false;
            }

            if(!self::$decoder->getElementEndTag())
                return false; // SYNC_SETTINGS_OOF or SYNC_SETTINGS_DEVICEPW or SYNC_SETTINGS_DEVICEINFORMATION or SYNC_SETTINGS_USERINFORMATION

            //break if it reached the endtag
            $e = self::$decoder->peek();
            if($e[EN_TYPE] == EN_TYPE_ENDTAG) {
                self::$decoder->getElementEndTag(); //SYNC_SETTINGS_SETTINGS
                break;
            }
        }

        $status = SYNC_SETTINGSSTATUS_SUCCESS;

        //TODO put it in try catch block
        //TODO implement Settings in the backend
        //TODO save device information in device manager
        //TODO status handling
//        $data = self::$backend->Settings($request);

        self::$encoder->startWBXML();
        self::$encoder->startTag(SYNC_SETTINGS_SETTINGS);

            self::$encoder->startTag(SYNC_SETTINGS_STATUS);
            self::$encoder->content($status);
            self::$encoder->endTag(); //SYNC_SETTINGS_STATUS

            //get oof settings
            if (isset($oofGet)) {
                $oofGet = self::$backend->Settings($oofGet);
                self::$encoder->startTag(SYNC_SETTINGS_OOF);
                    self::$encoder->startTag(SYNC_SETTINGS_STATUS);
                    self::$encoder->content($oofGet->Status);
                    self::$encoder->endTag(); //SYNC_SETTINGS_STATUS

                    self::$encoder->startTag(SYNC_SETTINGS_GET);
                        $oofGet->Encode(self::$encoder);
                    self::$encoder->endTag(); //SYNC_SETTINGS_GET
                self::$encoder->endTag(); //SYNC_SETTINGS_OOF
            }

            //get user information
            //TODO none email address found
            if (isset($userInformation)) {
                self::$backend->Settings($userInformation);
                self::$encoder->startTag(SYNC_SETTINGS_USERINFORMATION);
                    self::$encoder->startTag(SYNC_SETTINGS_STATUS);
                    self::$encoder->content($userInformation->Status);
                    self::$encoder->endTag(); //SYNC_SETTINGS_STATUS

                    self::$encoder->startTag(SYNC_SETTINGS_GET);
                        $userInformation->Encode(self::$encoder);
                    self::$encoder->endTag(); //SYNC_SETTINGS_GET
                self::$encoder->endTag(); //SYNC_SETTINGS_USERINFORMATION
            }

            //set out of office
            if (isset($oofSet)) {
                $oofSet = self::$backend->Settings($oofSet);
                self::$encoder->startTag(SYNC_SETTINGS_OOF);
                    self::$encoder->startTag(SYNC_SETTINGS_SET);
                        self::$encoder->startTag(SYNC_SETTINGS_STATUS);
                        self::$encoder->content($oofSet->Status);
                        self::$encoder->endTag(); //SYNC_SETTINGS_STATUS
                    self::$encoder->endTag(); //SYNC_SETTINGS_SET
                self::$encoder->endTag(); //SYNC_SETTINGS_OOF
            }

            //set device passwort
            if (isset($devicepassword)) {
                self::$encoder->startTag(SYNC_SETTINGS_DEVICEPW);
                    self::$encoder->startTag(SYNC_SETTINGS_SET);
                        self::$encoder->startTag(SYNC_SETTINGS_STATUS);
                        self::$encoder->content($devicepassword->Status);
                        self::$encoder->endTag(); //SYNC_SETTINGS_STATUS
                    self::$encoder->endTag(); //SYNC_SETTINGS_SET
                self::$encoder->endTag(); //SYNC_SETTINGS_DEVICEPW
            }

            //set device information
            if (isset($deviceinformation)) {
                self::$encoder->startTag(SYNC_SETTINGS_DEVICEINFORMATION);
                    self::$encoder->startTag(SYNC_SETTINGS_SET);
                        self::$encoder->startTag(SYNC_SETTINGS_STATUS);
                        self::$encoder->content($deviceinformation->Status);
                        self::$encoder->endTag(); //SYNC_SETTINGS_STATUS
                    self::$encoder->endTag(); //SYNC_SETTINGS_SET
                self::$encoder->endTag(); //SYNC_SETTINGS_DEVICEINFORMATION
            }


        self::$encoder->endTag(); //SYNC_SETTINGS_SETTINGS

        return true;
    }

}
?>