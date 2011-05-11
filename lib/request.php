<?php
/***********************************************
* File      :   request.php
* Project   :   Z-Push
* Descr     :   This file contains the actual
*               request analization and handling routines.
*               The request handlers are optimised
*               so that as little as possible
*               data is kept-in-memory, and all
*               output data is directly streamed
*               to the client, while also streaming
*               input data from the client.
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

class Request {
    /**
     * self::filterEvilInput() options
     */
    const LETTERS_ONLY = 1;
    const HEX_ONLY = 2;
    const WORDCHAR_ONLY = 3;
    const NUMBERS_ONLY = 4;
    const NUMBERSDOT_ONLY = 5;
    const HEX_EXTENDED = 6;

    static private $input;
    static private $output;
    static private $headers;
    static private $getparameters;
    static private $command = "-";
    static private $device;
    static private $method;
    static private $remoteAddr;
    static private $getUser;
    static private $devid;
    static private $devtype;
    static private $authUser;
    static private $authDomain;
    static private $authPassword;
    static private $asProtocolVersion = "1.0";
    static private $policykey;
    static private $useragent;
    static private $userIsAuthenticated;

    /**
     * Initializes request data
     *
     * @access public
     * @return
     */
    static public function Initialize() {
        self::$userIsAuthenticated = false;

        // try to open stdin & stdout
        self::$input = fopen("php://input", "r");
        self::$output = fopen("php://output", "w+");

        // Parse the standard GET parameters
        if(isset($_GET["Cmd"]))
            self::$command = self::filterEvilInput($_GET["Cmd"], self::LETTERS_ONLY);

        // getUser is unfiltered, as everything is allowed.. even "/", "\" or ".."
        if(isset($_GET["User"]))
            self::$getUser = $_GET["User"];
        if(isset($_GET["DeviceId"]))
            self::$devid = self::filterEvilInput($_GET["DeviceId"], self::WORDCHAR_ONLY);
        if(isset($_GET["DeviceType"]))
            self::$devtype = self::filterEvilInput($_GET["DeviceType"], self::LETTERS_ONLY);

        if(isset($_SERVER["REQUEST_METHOD"]))
            self::$method = self::filterEvilInput($_SERVER["REQUEST_METHOD"], self::LETTERS_ONLY);
        // TODO check IPv6 addresses
        if(isset($_SERVER["REMOTE_ADDR"]))
            self::$remoteAddr = self::filterEvilInput($_SERVER["REMOTE_ADDR"], self::NUMBERSDOT_ONLY);
    }

    /**
     * Reads and processes the request headers
     *
     * @access public
     * @return
     */
    static public function ProcessHeaders() {
        // TODO implement alternative to apache_request_headers()
        if (function_exists("apache_request_headers"))
            self::$headers = array_change_key_case(apache_request_headers(), CASE_LOWER);
        else
            throw new FatalException("Not running on an Apache server. Function apache_request_headers() is not available");

        self::$asProtocolVersion = (isset(self::$headers["ms-asprotocolversion"]))? self::filterEvilInput(self::$headers["ms-asprotocolversion"], self::NUMBERSDOT_ONLY) : "1.0";
        self::$useragent = (isset(self::$headers["user-agent"]))? self::$headers["user-agent"] : "unknown";

        if (isset(self::$headers["x-ms-policykey"]))
            self::$policykey = (int) self::filterEvilInput(self::$headers["x-ms-policykey"], self::NUMBERS_ONLY);
        else
            self::$policykey = 0;

        ZLog::Write(LOGLEVEL_DEBUG, "Incoming policykey: " . self::$policykey);
        ZLog::Write(LOGLEVEL_DEBUG, "Client supports version: " . self::$asProtocolVersion);

    }

    /**
     * Reads and parses the HTTP-Basic-Auth data
     *
     * @access public
     * @return boolean      data sent or not
     */
    static public function AuthenticationInfo() {
        // split username & domain if received as one
        if (isset($_SERVER['PHP_AUTH_USER'])) {
            list(self::$authUser, self::$authDomain) = Utils::SplitDomainUser($_SERVER['PHP_AUTH_USER']);
            self::$authPassword = (isset($_SERVER['PHP_AUTH_PW']))?$_SERVER['PHP_AUTH_PW'] : "";
        }
        // authUser & authPassword are unfiltered!
        return (self::$authUser != "" && self::$authPassword != "");
    }


    /**----------------------------------------------------------------------------------------------------------
     * Getter & Checker
     */

    /**
     * Returns the input stream
     *
     * @access public
     * @return handle/boolean      false if not available
     */
    static public function getInputStream() {
        if (isset(self::$input))
            return self::$input;
        else
            return false;
    }

    /**
     * Returns the output stream
     *
     * @access public
     * @return handle/boolean      false if not available
     */
    static public function getOutputStream() {
        if (isset(self::$output))
            return self::$output;
        else
            return false;
    }

    /**
     * Returns the request method
     *
     * @access public
     * @return string
     */
    static public function getMethod() {
        if (isset(self::$method))
            return self::$method;
        else
            return "UNKNOWN";
    }

    /**
     * Returns the value of the user parameter of the querystring
     *
     * @access public
     * @return string/boolean       false if not available
     */
    static public function getGETUser() {
        if (isset(self::$getUser))
            return self::$getUser;
        else
            return false;
    }

    /**
     * Returns the value of the ItemId parameter of the querystring
     *
     * @access public
     * @return string/boolean       false if not available
     */
    static public function getGETItemId() {
        return (isset($_GET["ItemId"]))? self::filterEvilInput($_GET["ItemId"], self::HEX_ONLY) : false;
    }

    /**
     * Returns the value of the CollectionId parameter of the querystring
     *
     * @access public
     * @return string/boolean       false if not available
     */
    static public function getGETCollectionId() {
        return (isset($_GET["CollectionId"]))? self::filterEvilInput($_GET["CollectionId"], self::HEX_ONLY) : false;
    }

    /**
     * Returns if the SaveInSent parameter of the querystring is set
     *
     * @access public
     * @return boolean
     */
    static public function getGETSaveInSent() {
        return (isset($_GET["SaveInSent"]))? ($_GET["SaveInSent"] == "T") : false;
    }

    /**
     * Returns the value of the AttachmentName parameter of the querystring
     *
     * @access public
     * @return string/boolean       false if not available
     */
    static public function getGETAttachmentName() {
        return (isset($_GET["AttachmentName"]))? self::filterEvilInput($_GET["AttachmentName"], self::HEX_EXTENDED) : false;
    }

    /**
     * Returns the authenticated user
     *
     * @access public
     * @return string/boolean       false if not available
     */
    static public function getAuthUser() {
        if (isset(self::$authUser))
            return self::$authUser;
        else
            return false;
    }

    /**
     * Returns the authenticated domain for the user
     *
     * @access public
     * @return string/boolean       false if not available
     */
    static public function getAuthDomain() {
        if (isset(self::$authDomain))
            return self::$authDomain;
        else
            return false;
    }

    /**
     * Returns the transmitted password
     *
     * @access public
     * @return string/boolean       false if not available
     */
    static public function getAuthPassword() {
        if (isset(self::$authPassword))
            return self::$authPassword;
        else
            return false;
    }

    /**
     * Indicates if the user request was marked as "authenticated"
     *
     * @access public
     * @return boolean
     */
    static public function isUserAuthenticated() {
        return self::$userIsAuthenticated;
    }

    /**
     * Marks the user request as "authenticated"
     *
     * @access public
     * @return boolean
     */
    static public function ConfirmUserAuthentication() {
        self::$userIsAuthenticated = true;
        return true;
    }


    /**
     * Returns the RemoteAddress
     *
     * @access public
     * @return string
     */
    static public function getRemoteAddr() {
        if (isset(self::$getUser))
            return self::$remoteAddr;
        else
            return "UNKNOWN";
    }

    /**
     * Returns the command to be executed
     *
     * @access public
     * @return string/boolean       false if not available
     */
    static public function getCommand() {
        if (isset(self::$getUser))
            return self::$command;
        else
            return false;
    }

    /**
     * Returns the device id transmitted
     *
     * @access public
     * @return string/boolean       false if not available
     */
    static public function getDeviceID() {
        if (isset(self::$devid))
            return self::$devid;
        else
            return false;
    }

    /**
     * Returns the device type if transmitted
     *
     * @access public
     * @return string/boolean       false if not available
     */
    static public function getDeviceType() {
        if (isset(self::$devtype))
            return self::$devtype;
        else
            return false;
    }

    /**
     * Returns the value of supported AS protocol from the headers
     *
     * @access public
     * @return string/boolean       false if not available
     */
    static public function getProtocolVersion() {
        if (isset(self::$asProtocolVersion))
            return self::$asProtocolVersion;
        else
            return false;
    }

    /**
     * Returns the user agent sent in the headers
     *
     * @access public
     * @return string/boolean       false if not available
     */
    static public function getUserAgent() {
        if (isset(self::$useragent))
            return self::$useragent;
        else
            return false;
    }

    /**
     * Returns policy key sent by the device
     *
     * @access public
     * @return int/boolean       false if not available
     */
    static public function getPolicyKey() {
        if (isset(self::$policykey))
            return self::$policykey;
        else
            return false;
    }

    /**
     * Indicates if a policy key was sent by the device
     *
     * @access public
     * @return boolean
     */
    static public function wasPolicyKeySent() {
        return isset(self::$headers["x-ms-policykey"]);
    }

    /**
     * Indicates if Z-Push was called with a POST request
     *
     * @access public
     * @return boolean
     */
    static public function isMethodPOST() {
        return (self::$method == "POST");
    }

    /**
     * Indicates if Z-Push was called with a GET request
     *
     * @access public
     * @return boolean
     */
    static public function isMethodGET() {
        return (self::$method == "GET");
    }

    /**
     * Indicates if Z-Push was called with a OPTIONS request
     *
     * @access public
     * @return boolean
     */
    static public function isMethodOPTIONS() {
        return (self::$method == "OPTIONS");
    }

    /**
     * Returns the amount of data sent in this request (from the headers)
     *
     * @access public
     * @return int
     */
    static public function getContentLength() {
        return (isset(self::$headers["content-length"]))? (int) self::$headers["content-length"] : 0;
    }


    /**----------------------------------------------------------------------------------------------------------
     * Private stuff
     */

    /**
     * Replaces all not allowed characters in a string
     *
     * @param string    $input          the input string
     * @param int       $filter         one of the predefined filters: LETTERS_ONLY, HEX_ONLY, WORDCHAR_ONLY, NUMBERS_ONLY, NUMBERSDOT_ONLY
     * @param char      $replacevalue   (opt) a character the filtered characters should be replaced with
     *
     * @access public
     * @return string
     */
    static private function filterEvilInput($input, $filter, $replacevalue = '') {
        $re = false;
        if ($filter == self::LETTERS_ONLY)            $re = "/[^A-Za-z]/";
        else if ($filter == self::HEX_ONLY)           $re = "/[^A-Fa-f0-9]/";
        else if ($filter == self::WORDCHAR_ONLY)      $re = "/[^A-Za-z0-9]/";
        else if ($filter == self::NUMBERS_ONLY)       $re = "/[^0-9]/";
        else if ($filter == self::NUMBERSDOT_ONLY)    $re = "/[^0-9\.]/";
        else if ($filter == self::HEX_EXTENDED)       $re = "/[^A-Fa-f0-9\:]/";

        return ($re) ? preg_replace($re, $replacevalue, $input) : '';
    }
}



class RequestProcessor {
    static private $backend;
    static private $deviceManager;
    static private $decoder;
    static private $encoder;

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
                throw new FatalNotImplementedException("Command '$cmd' is not implemented");
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

            $importer = self::$backend->GetImporter($move["srcfldid"]);
            $result = $importer->ImportMessageMove($move["srcmsgid"], $move["dstfldid"]);
            // We discard the importer state for now.

            // TODO more status codes
            self::$encoder->startTag(SYNC_MOVE_STATUS);
            self::$encoder->content($result ? 3 : 1);
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
        $folders = self::$backend->GetHierarchy();
        if(!$folders)
            return false;

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

        $status = 1;
        // TODO: if the statemachine is not able to load the states (not found) a status code should be returned
        try {
            $syncstate = self::$deviceManager->GetSyncState($synckey);
        }
        catch (StateNotFoundException $snfex) {
            // Android sends "validate" as deviceID which causes the state not to be found......
            if (self::$deviceManager->TolerateException($snfex))
                $syncstate = "";
            else
                $status = 9;
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

                if (!$importer) {
                    // Configure the backends importer with last state
                    $importer = self::$backend->GetImporter();
                    $importer->Config($syncstate);
                    // the messages from the PIM will be forwarded to the backend
                    $changesMem->forwardImporter($importer);
                }

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
            self::$encoder->startTag(SYNC_FOLDERHIERARCHY_STATUS);
            // TODO correct status has to be returned
            self::$encoder->content($status);
            self::$encoder->endTag();

            if ($status == 1) {

                // Request changes from backend, they will be sent to the MemImporter passed as the first
                // argument, which stores them in $importer. Returns the new sync state for this exporter.
                $exporter = self::$backend->GetExporter();

                //public function Config(&$importer, $mclass, $restrict, $syncstate, $flags, $truncation)
                //$exporter->Config($importer, false, false, $syncstate, 0, 0);
                $exporter->Config($syncstate);
                $exporter->InitializeExporter($changesMem);

                // Stream all changes to the ImportExportChangesMem
                while(is_array($exporter->Synchronize()));
            }

            self::$encoder->startTag(SYNC_FOLDERHIERARCHY_SYNCKEY);
            // only send new synckey if changes were processed or there are outgoing changes
            self::$encoder->content((($changesMem->isStateChanged())?$newsynckey:$synckey));
            self::$encoder->endTag();

            if ($status == 1) {
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
            }
        }
        self::$encoder->endTag();

        // Save the sync state for the next time
        $syncstate = (isset($exporter))?$exporter->GetState():"";
        self::$deviceManager->SetSyncState($newsynckey, $syncstate);

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

        // Start decode
        if(!self::$decoder->getElementStartTag(SYNC_SYNCHRONIZE))
            return false;

        // AS 1.0 sends version information in WBXML
        if(self::$decoder->getElementStartTag(SYNC_VERSION)) {
            // TODO: what to do with this version information?
            $sync_version = self::$decoder->getElementContent();
            ZLog::Write(LOGLEVEL_DEBUG, "Sync version: {$sync_version}");
            if(!self::$decoder->getElementEndTag())
                return false;
        }

        if(!self::$decoder->getElementStartTag(SYNC_FOLDERS))
            return false;

        while(self::$decoder->getElementStartTag(SYNC_FOLDER)) {
            $collection = array();
            $collection["truncation"] = SYNC_TRUNCATION_ALL;
            $collection["clientids"] = array();
            $collection["fetchids"] = array();

            if(!self::$decoder->getElementStartTag(SYNC_FOLDERTYPE))
                return false;

            $collection["class"] = self::$decoder->getElementContent();
            ZLog::Write(LOGLEVEL_DEBUG, "Sync folder: {$collection["class"]}");

            if(!self::$decoder->getElementEndTag())
                return false;

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

            if(self::$decoder->getElementStartTag(SYNC_GETCHANGES))
                $collection["getchanges"] = true;

            if(self::$decoder->getElementStartTag(SYNC_WINDOWSIZE)) {
                $collection["windowsize"] = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag())
                    return false;
            }

            if(self::$decoder->getElementStartTag(SYNC_OPTIONS)) {
                while(1) {
                    if(self::$decoder->getElementStartTag(SYNC_FILTERTYPE)) {
                        $collection["filtertype"] = self::$decoder->getElementContent();
                        if(!self::$decoder->getElementEndTag())
                            return false;
                    }
                    if(self::$decoder->getElementStartTag(SYNC_TRUNCATION)) {
                        $collection["truncation"] = self::$decoder->getElementContent();
                        if(!self::$decoder->getElementEndTag())
                            return false;
                    }
                    if(self::$decoder->getElementStartTag(SYNC_RTFTRUNCATION)) {
                        $collection["rtftruncation"] = self::$decoder->getElementContent();
                        if(!self::$decoder->getElementEndTag())
                            return false;
                    }

                    if(self::$decoder->getElementStartTag(SYNC_MIMESUPPORT)) {
                        $collection["mimesupport"] = self::$decoder->getElementContent();
                        if(!self::$decoder->getElementEndTag())
                            return false;
                    }

                    if(self::$decoder->getElementStartTag(SYNC_MIMETRUNCATION)) {
                        $collection["mimetruncation"] = self::$decoder->getElementContent();
                        if(!self::$decoder->getElementEndTag())
                            return false;
                    }

                    if(self::$decoder->getElementStartTag(SYNC_CONFLICT)) {
                        $collection["conflict"] = self::$decoder->getElementContent();
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
                (!isset($collection["filtertype"]) || $collection["filtertype"] > SYNC_FILTERTIME_MAX)) {
                    $collection["filtertype"] = SYNC_FILTERTIME_MAX;
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

            // TODO really implement Status
            $status = 1;
            // Get our sync state for this collection
            try {
                $collection["syncstate"] = self::$deviceManager->GetSyncState($collection["synckey"]);

                // if this is an additional folder the backend has to be setup correctly
                self::$backend->Setup(ZPush::GetAdditionalSyncFolderStore($collection["collectionid"]));
            }
            catch (StateNotFoundException $snfex) {
                ZLog::Write(LOGLEVEL_WARN, sprintf("State not found for SyncKey '%s'. Triggering error on device.", $collection["synckey"]));
                $status = 3;
            }

            if(self::$decoder->getElementStartTag(SYNC_PERFORM)) {
                // TODO status == 1 is ugly..
                if ($status == 1) {
                    // Configure importer with last state
                    $importer = self::$backend->GetImporter($collection["collectionid"]);
                    // the importer could not be initialized if there are missing permissions
                    if ($importer === false) {
                        // force a hierarchysync
                        // TODO status exceptions should be thrown
                        $status = 12;
                    }
                    else
                        $importer->Config($collection["syncstate"], $collection["conflict"]);
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
                    if ($status == 1 && $nchanges == 0)
                        $importer->LoadConflicts($collection["class"], (isset($collection["filtertype"])) ? $collection["filtertype"] : false, $collection["syncstate"]);

                    if ($status == 1)
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

                    if ($status != 1) {
                        ZLog::Write(LOGLEVEL_WARN, "Ignored incoming change, invalid state.");
                        continue;
                    }

                    switch($element[EN_TAG]) {
                        case SYNC_MODIFY:
                            if(isset($message->read)) // Currently, 'read' is only sent by the PDA when it is ONLY setting the read flag.
                                $importer->ImportMessageReadFlag($serverid, $message->read);
                            else
                                $importer->ImportMessageChange($serverid, $message);
                            $collection["importedchanges"] = true;
                            break;
                        case SYNC_ADD:
                            $id = $importer->ImportMessageChange(false, $message);

                            if($clientid && $id) {
                                $collection["clientids"][$clientid] = $id;
                                $collection["importedchanges"] = true;
                            }
                            break;
                        case SYNC_REMOVE:
                            // if message deletions are to be moved, move them
                            if(isset($collection["deletesasmoves"])) {
                                $folderid = self::$backend->GetWasteBasket();

                                if($folderid) {
                                    $importer->ImportMessageMove($serverid, $folderid);
                                    $collection["importedchanges"] = true;
                                    break;
                                }
                                else
                                    ZLog::Write(LOGLEVEL_WARN, "Message should be moved to WasteBasket, but the Backend did not return a destination ID. Message is hard deleted now!");
                            }

                            $importer->ImportMessageDeletion($serverid);
                            $collection["importedchanges"] = true;
                            break;
                        case SYNC_FETCH:
                            array_push($collection["fetchids"], $serverid);
                            break;
                    }

                    if(!self::$decoder->getElementEndTag()) // end add/change/delete/move
                        return false;
                }

                if ($status == 1) {
                    ZLog::Write(LOGLEVEL_INFO, "Processed $nchanges incoming changes");

                    // Save the updated state, which is used for the exporter later
                    $collection["syncstate"] = $importer->GetState();
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
                    if($status == 1 && isset($collection["getchanges"])) {
                        // Use the state from the importer, as changes may have already happened
                        $exporter = self::$backend->GetExporter($collection["collectionid"]);

                        if ($exporter === false) {
                            ZLog::Write(LOGLEVEL_DEBUG, "No exporter available, forcing hierarchy synchronization");
                            $status = 12;
                        }
                        else {
                            // Stream the messages directly to the PDA
                            $streamimporter = new ImportChangesStream(self::$encoder, ZPush::getSyncObjectFromFolderClass($collection["class"]));

                            $filtertype = isset($collection["filtertype"]) ? $collection["filtertype"] : false;
                            $exporter->Config($collection["syncstate"]);
                            $exporter->ConfigContentParameters($collection["class"], $filtertype, $collection["truncation"]);
                            $exporter->InitializeExporter($streamimporter);

                            $changecount = $exporter->GetChangeCount();
                        }
                    }

                    // Get a new sync key to output to the client if any changes have been requested or will be send
                    if (isset($collection["importedchanges"]) || $changecount > 0 || $collection["synckey"] == "0")
                        $collection["newsynckey"] = self::$deviceManager->GetNewSyncKey($collection["synckey"]);

                    self::$encoder->startTag(SYNC_FOLDER);

                    self::$encoder->startTag(SYNC_FOLDERTYPE);
                    self::$encoder->content($collection["class"]);
                    self::$encoder->endTag();

                    self::$encoder->startTag(SYNC_SYNCKEY);

                    if(isset($collection["newsynckey"]))
                        self::$encoder->content($collection["newsynckey"]);
                    else
                        self::$encoder->content($collection["synckey"]);

                    self::$encoder->endTag();

                    self::$encoder->startTag(SYNC_FOLDERID);
                    self::$encoder->content($collection["collectionid"]);
                    self::$encoder->endTag();

                    // TODO return status
                    self::$encoder->startTag(SYNC_STATUS);
                    self::$encoder->content($status);
                    self::$encoder->endTag();

                    //check the mimesupport because we need it for advanced emails
                    $mimesupport = isset($collection['mimesupport']) ? $collection['mimesupport'] : 0;

                    // Output server IDs for new items we received from the PDA
                    if($status == 1 && isset($collection["clientids"]) || count($collection["fetchids"]) > 0) {
                        self::$encoder->startTag(SYNC_REPLIES);
                        foreach($collection["clientids"] as $clientid => $serverid) {
                            self::$encoder->startTag(SYNC_ADD);
                            self::$encoder->startTag(SYNC_CLIENTENTRYID);
                            self::$encoder->content($clientid);
                            self::$encoder->endTag();
                            self::$encoder->startTag(SYNC_SERVERENTRYID);
                            self::$encoder->content($serverid);
                            self::$encoder->endTag();
                            self::$encoder->startTag(SYNC_STATUS);
                            self::$encoder->content(1);
                            self::$encoder->endTag();
                            self::$encoder->endTag();
                        }
                        foreach($collection["fetchids"] as $id) {
                            $data = self::$backend->Fetch($collection["collectionid"], $id, $mimesupport);
                            if($data !== false) {
                                self::$encoder->startTag(SYNC_FETCH);
                                self::$encoder->startTag(SYNC_SERVERENTRYID);
                                self::$encoder->content($id);
                                self::$encoder->endTag();
                                self::$encoder->startTag(SYNC_STATUS);
                                // TODO return correct status
                                self::$encoder->content(1);
                                self::$encoder->endTag();
                                self::$encoder->startTag(SYNC_DATA);
                                $data->encode(self::$encoder);
                                self::$encoder->endTag();
                                self::$encoder->endTag();
                            } else {
                                // TODO: add status!
                                ZLog::Write(LOGLEVEL_WARN, "unable to fetch $id");
                            }
                        }
                        self::$encoder->endTag();
                    }

                    if($status == 1 && isset($collection["getchanges"])) {
                        // exporter already intialized

                        if($changecount > $collection["windowsize"]) {
                            self::$encoder->startTag(SYNC_MOREAVAILABLE, false, true);
                        }

                        // Output message changes per folder
                        self::$encoder->startTag(SYNC_PERFORM);

                        $filtertype = isset($collection["filtertype"]) ? $collection["filtertype"] : 0;

                        $n = 0;
                        while(1) {
                            $progress = $exporter->Synchronize();
                            if(!is_array($progress))
                                break;
                            $n++;

                            if($n >= $collection["windowsize"]) {
                                ZLog::Write(LOGLEVEL_DEBUG, "Exported maxItems of messages: ". $collection["windowsize"] . " / ". $changecount);
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

                        if (isset($state)) self::$deviceManager->SetSyncState($collection["newsynckey"], $state, $collection["collectionid"]);
                        else ZLog::Write(LOGLEVEL_ERROR, "error saving " . $collection["newsynckey"] . " - no state information available");
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
            $collection["filtertype"] = $filtertype;
            $collection["collectionid"] = $collectionid;

            array_push($collections, $collection);
        }

        self::$encoder->startWBXML();

        self::$encoder->startTag(SYNC_GETITEMESTIMATE_GETITEMESTIMATE);
        {
            foreach($collections as $collection) {
                self::$encoder->startTag(SYNC_GETITEMESTIMATE_RESPONSE);
                {
                    // TODO implement correct status handling
                    $changecount = 0;
                    $status = 1;
                    $exporter = self::$backend->GetExporter($collection["collectionid"]);
                    if ($exporter === false) {
                        ZLog::Write(LOGLEVEL_DEBUG, "No exporter available, forcing hierarchy synchronization");
                        $status = 12;
                    }
                    else {
                        $importer = new ChangesMemoryWrapper();
                        // TODO this could also fail -> set correct status
                        $syncstate = self::$deviceManager->GetSyncState($collection["synckey"]);

                        $exporter->Config($syncstate);
                        $exporter->ConfigContentParameters($collection["class"], $collection["filtertype"], 0);
                        $exporter->InitializeExporter($importer);
                        $changecount = $exporter->GetChangeCount();
                    }

                    // TODO set status
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
        ZLog::Write(LOGLEVEL_INFO, "Ping received");

        $timeout = 5;

        $collections = array();
        $lifetime = 0;

        // TODO all active PING requests should be logged and terminate themselfs (e.g. volatile devicedata!!)

        // Get previous pingdata, if available
        list($collections, $lifetime) = self::$deviceManager->GetPingState();

        if(self::$decoder->getElementStartTag(SYNC_PING_PING)) {
            ZLog::Write(LOGLEVEL_DEBUG, "Ping init");
            if(self::$decoder->getElementStartTag(SYNC_PING_LIFETIME)) {
                $lifetime = self::$decoder->getElementContent();
                self::$decoder->getElementEndTag();
            }

            if(self::$decoder->getElementStartTag(SYNC_PING_FOLDERS)) {
                // avoid ping init if not necessary
                $saved_collections = $collections;

                $collections = array();

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
                                ZLog::Write(LOGLEVEL_DEBUG, "reusing saved state for ". $collection["class"]);
                                break;
                            }
                        }

                    if ($collection["state"] == "")
                        ZLog::Write(LOGLEVEL_DEBUG, "empty state for ". $collection["class"]);

                    // switch user store if this is a additional folder
                    self::$backend->Setup(ZPush::GetAdditionalSyncFolderStore($collection["serverid"]));

                    // Create start state for this collection
                    $exporter = self::$backend->GetExporter($collection["serverid"]);
                    $importer = false;

                    $exporter->Config($collection["state"], BACKEND_DISCARD_DATA);
                    $exporter->ConfigContentParameters($collection["class"], SYNC_FILTERTYPE_1DAY, 0);
                    $exporter->InitializeExporter($importer);
                    while(is_array($exporter->Synchronize()));
                    $collection["state"] = $exporter->GetState();
                    array_push($collections, $collection);
                }

                if(!self::$decoder->getElementEndTag())
                    return false;
            }

            if(!self::$decoder->getElementEndTag())
                return false;
        }

        $changes = array();
        $dataavailable = false;

        ZLog::Write(LOGLEVEL_INFO, "Waiting for changes... (lifetime $lifetime)");
        // Wait for something to happen
        for($n=0;$n<$lifetime / $timeout; $n++ ) {
            // Check if provisioning is necessary
            if (PROVISIONING === true && Request::wasPolicyKeySent() && self::$deviceManager->ProvisioningRequired(Request::getPolicyKey())) {
                //return 7 because it forces folder sync
                $pingstatus = 7;
                break;
            }

            // TODO we could also check if a hierarchy sync is necessary, as the state is saved in the ASDevice
            if(count($collections) == 0) {
                $error = 1;
                break;
            }

            for($i=0;$i<count($collections);$i++) {
                $collection = $collections[$i];

                // switch user store if this is a additional folder (true -> do not debug)
                self::$backend->Setup(ZPush::GetAdditionalSyncFolderStore($collection["serverid"], true));

                $exporter = self::$backend->GetExporter($collection["serverid"]);
                // during the ping, permissions could be revoked
                // TODO during ping, the permissions are constantly checked. This could be improved.
                if ($exporter === false) {
                    $pingstatus = 7;
                    // reset current collections
                    $collections = array();
                    break 2;
                }
                $importer = false;

                $exporter->Config($collection["state"], BACKEND_DISCARD_DATA);
                $exporter->ConfigContentParameters($collection["class"], SYNC_FILTERTYPE_1DAY, 0);
                $ret = $exporter->InitializeExporter($importer);

                // give it a rest if exporter can not be configured atm
                if ($ret === false ) {
                    // force "ping" to stop
                    $n = $lifetime / $timeout;
                    ZLog::Write(LOGLEVEL_WARN, "Ping error: Exporter can not be configured. Waiting 30 seconds before ping is retried.");
                    sleep(30);
                    break;
                }

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

            if($dataavailable) {
                ZLog::Write(LOGLEVEL_INFO, "Found change");
                break;
            }

            sleep($timeout);
        }

        self::$encoder->StartWBXML();

        self::$encoder->startTag(SYNC_PING_PING);
        {
            // TODO review status codes
            self::$encoder->startTag(SYNC_PING_STATUS);
            if(isset($error))
                self::$encoder->content(3);
            elseif (isset($pingstatus))
                self::$encoder->content($pingstatus);
            else
                self::$encoder->content(count($changes) > 0 ? 2 : 1);
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
        return $this->HandleSendMail(Request::getGETItemId(), false, Request::getGETCollectionId());
    }

    /**
     * Reply and sends an e-mail
     * SmartReply should add the original message to the end of the message body
     *
     * @access private
     * @return boolean
     */
    static private function HandleSmartReply() {
        return $this->HandleSendMail(false, Request::getGETItemId(), Request::getGETCollectionId());
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

        // Get state of hierarchy
        $syncstate = self::$deviceManager->GetSyncState($synckey);
        $newsynckey = self::$deviceManager->GetNewSyncKey($synckey);

        // Over the ChangesWrapper the HierarchyCache is notified about all changes
        $changesMem = self::$deviceManager->GetHierarchyChangesWrapper();

        // TODO check how mobile triggered changes affect additional synched folders (e.g. public folders). This should return an "impossible" return code.

        // switch user store if this is a additional folder (true -> do not debug)
        self::$backend->Setup(ZPush::GetAdditionalSyncFolderStore($serverid));

        // Configure importer with last state
        $importer = self::$backend->GetImporter();
        $importer->Config($syncstate);

        // the messages from the PIM will be forwarded to the real importer
        $changesMem->forwardImporter($importer);

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
            $deletedstat = $changesMem->ImportFolderDeletion($serverid, 0);
        }

        self::$encoder->startWBXML();
        if ($create) {

            self::$encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERCREATE);
            {
                {
                    self::$encoder->startTag(SYNC_FOLDERHIERARCHY_STATUS);
                    self::$encoder->content(1);
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
                    self::$encoder->content(1);
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
                    self::$encoder->content($deletedstat);
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
            $calendarid = "";
            $ok = self::$backend->MeetingResponse($req["requestid"], $req["folderid"], $req["response"], $calendarid);
            self::$encoder->startTag(SYNC_MEETINGRESPONSE_RESULT);
                self::$encoder->startTag(SYNC_MEETINGRESPONSE_REQUESTID);
                    self::$encoder->content($req["requestid"]);
                self::$encoder->endTag();

                // TODO check for other status
                self::$encoder->startTag(SYNC_MEETINGRESPONSE_STATUS);
                    self::$encoder->content($ok ? 1 : 2);
                self::$encoder->endTag();

                if($ok) {
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
        // TODO HandleProvision is broken, due changed API
        return false;

        $devid = Request::getDeviceID();
        $user = Request::getAuthUser();
        $auth_pw = Request::getAuthPassword();

        $status = SYNC_PROVISION_STATUS_SUCCESS;
        $rwstatus = self::$backend->getDeviceRWStatus($user, $auth_pw, $devid);
        $rwstatusWiped = false;

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
            if ($policytype != 'MS-WAP-Provisioning-XML') {
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

        //set the new final policy key in the backend
        // START ADDED dw2412 Android provisioning fix
        //in case the send one does not match the one already in backend. If it matches, we
        //just return the already defined key. (This helps at least the RoadSync 5.0 Client to sync)
        if (self::$backend->CheckPolicy($policykey,$devid) == SYNC_PROVISION_STATUS_SUCCESS) {
            ZLog::Write(LOGLEVEL_INFO, "Policykey is OK! Will not generate a new one!");
        }
        else {
            if (!$phase2) {
                $policykey = self::$backend->generatePolicyKey();
                self::$backend->SetPolicyKey($policykey, $devid);
            }
            else {
                // just create a temporary key (i.e. iPhone OS4 Beta does not like policykey 0 in response)
                $policykey = self::$backend->GeneratePolicyKey();
            }
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
                    else {
                        ZLog::Write(LOGLEVEL_WARN, "Wrong policy type");
                        return false;
                    }

                    self::$encoder->endTag();//data
                }
                self::$encoder->endTag();//policy
            self::$encoder->endTag(); //policies
        }

        //wipe data if status is pending or wiped
        if ($rwstatus == SYNC_PROVISION_RWSTATUS_PENDING || $rwstatus == SYNC_PROVISION_RWSTATUS_WIPED) {
            self::$encoder->startTag(SYNC_PROVISION_REMOTEWIPE, false, true);
            self::$backend->setDeviceRWStatus($user, $auth_pw, $devid, ($rwstatusWiped)?SYNC_PROVISION_RWSTATUS_WIPED:SYNC_PROVISION_RWSTATUS_PENDING);
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

        // TODO support other searches
        if ($searchprovider->SupportsType($searchname)) {
            if ($searchname == "GAL") {
                //get search results from the searchprovider
                $rows = $searchprovider->GetGALSearchResults($searchquery, $searchrange);
            }
            else
                $rows = array();
        }
        else {
            // TODO throw exception?
            ZLog::Write(LOGLEVEL_WARN, sprintf("Searchtype '%s' is not supported.", $searchname));
            return false;
        }


        $searchprovider->Disconnect();

        self::$encoder->startWBXML();

        self::$encoder->startTag(SYNC_SEARCH_SEARCH);

            self::$encoder->startTag(SYNC_SEARCH_STATUS);
            self::$encoder->content(1);
            self::$encoder->endTag();

            self::$encoder->startTag(SYNC_SEARCH_RESPONSE);
                self::$encoder->startTag(SYNC_SEARCH_STORE);

                    self::$encoder->startTag(SYNC_SEARCH_STATUS);
                    self::$encoder->content(1);
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
        self::$encoder->endTag();//search

        return true;
    }

}
?>