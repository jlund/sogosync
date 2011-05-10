<?php
/***********************************************
* File      :   zpush.php
* Project   :   Z-Push
* Descr     :   Core functionalities
*
* Created   :   12.04.2011
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

class ZPush {
    const UNAUTHENTICATED = 1;
    const UNPROVISIONED = 2;
    const NOACTIVESYNCCOMMAND = 3;
    const WEBSERVICECOMMAND = 4;
    const HIERARCHYCOMMAND = 5;
    const REQUIRESPROTOCOLVERSION = 6;
    const PLAININPUT = 7;

    static private $supportedASVersions = array("1.0","2.0","2.1","2.5");
    static private $supportedCommands = array(
                                            'Sync' => false,
                                            'SendMail' => array(self::PLAININPUT),
                                            'SmartForward' => array(self::PLAININPUT),
                                            'SmartReply' => array(self::PLAININPUT),
                                            'GetAttachment' => false,
                                            'GetHierarchy' => array(self::HIERARCHYCOMMAND),
                                            'CreateCollection' => false,
                                            'DeleteCollection' => false,
                                            'MoveCollection' => false,
                                            'FolderSync' => array(self::HIERARCHYCOMMAND),
                                            'FolderCreate' => array(self::HIERARCHYCOMMAND),
                                            'FolderDelete' => array(self::HIERARCHYCOMMAND),
                                            'FolderUpdate' => array(self::HIERARCHYCOMMAND),
                                            'MoveItems' => false,
                                            'GetItemEstimate' => false,
                                            'MeetingResponse' => false,
                                            'ResolveRecipients' => false,
                                            'ValidateCert' => false,
                                            'Provision' => array(self::UNAUTHENTICATED, self::UNPROVISIONED),
                                            'Search' => false,
                                            'Ping' => array(self::UNPROVISIONED),
                                            'Notify' => false,
                                          );

    static private $classes = array("Email"     => array("SyncMail", SYNC_FOLDER_TYPE_INBOX),
                                    "Contacts"  => array("SyncContact", SYNC_FOLDER_TYPE_CONTACT, self::REQUIRESPROTOCOLVERSION),
                                    "Calendar"  => array("SyncAppointment", SYNC_FOLDER_TYPE_APPOINTMENT),
                                    "Tasks"     => array("SyncTask", SYNC_FOLDER_TYPE_TASK),
                                    );

    static private $stateMachine;
    static private $searchProvider;
    static private $deviceManager;
    static private $backend;
    static private $addSyncFolders;


    /**
     * Verifies configuration
     *
     * @access public
     * @return boolean
     * @trows FatalMisconfigurationException
     */
    static public function CheckConfig() {
        // this is a legacy thing
        global $specialLogUsers, $additionalFolders;

        // check the php version

        if (version_compare(phpversion(),'5.1.0') < 0)
            throw new FatalException("The configured PHP version is to old. Please make sure at least PHP 5.1 is used.");

        // some basic checks
        if (!defined('BASE_PATH'))
            throw new FatalMisconfigurationException("The BASE_PATH is not configured. Check if the config.php file is in place.");

        if (substr(BASE_PATH, -1,1) != "/")
            throw new FatalMisconfigurationException("The BASE_PATH should terminate with a '/'");

        if (!file_exists(BASE_PATH))
            throw new FatalMisconfigurationException("The configured BASE_PATH does not exist or can not be accessed.");

        if (!defined('LOGFILEDIR'))
            throw new FatalMisconfigurationException("The LOGFILEDIR is not configured. Check if the config.php file is in place.");

        if (substr(LOGFILEDIR, -1,1) != "/")
            throw new FatalMisconfigurationException("The LOGFILEDIR should terminate with a '/'");

        if (!file_exists(LOGFILEDIR))
            throw new FatalMisconfigurationException("The configured LOGFILEDIR does not exist or can not be accessed.");

        if (!touch(LOGFILE))
            throw new FatalMisconfigurationException("The configured LOGFILE can not be modified.");

        if (!touch(LOGERRORFILE))
            throw new FatalMisconfigurationException("The configured LOGFILE can not be modified.");

        if (!is_array($specialLogUsers))
            throw new FatalMisconfigurationException("The WBXML log users is not an array.");

        // the check on additional folders will not throw hard errors, as this is probably changed on live systems
        if (isset($additionalFolders) && !is_array($additionalFolders))
            ZLog::Write(LOGLEVEL_ERROR, "ZPush::CheckConfig() : The additional folders synchronization not available as array.");
        else {
            self::$addSyncFolders = array();

            // process configured data
            foreach ($additionalFolders as $af) {

                if (!is_array($af) || !isset($af['store']) || !isset($af['folderid']) || !isset($af['name']) || !isset($af['type'])) {
                    ZLog::Write(LOGLEVEL_ERROR, "ZPush::CheckConfig() : the additional folder synchronization is not configured correctly. Missing parameters. Entry will be ignored.");
                    continue;
                }

                if ($af['store'] == "" || $af['folderid'] == "" || $af['name'] == "" || $af['type'] == "") {
                    ZLog::Write(LOGLEVEL_WARN, "ZPush::CheckConfig() : the additional folder synchronization is not configured correctly. Empty parameters. Entry will be ignored.");
                    continue;
                }

                if (!in_array($af['type'], array(SYNC_FOLDER_TYPE_USER_CONTACT, SYNC_FOLDER_TYPE_USER_APPOINTMENT, SYNC_FOLDER_TYPE_USER_TASK, SYNC_FOLDER_TYPE_USER_MAIL))) {
                    ZLog::Write(LOGLEVEL_ERROR, sprintf("ZPush::CheckConfig() : the type of the additional synchronization folder '%s is not permitted.", $af['name']));
                    continue;
                }

                $folder = new SyncFolder();
                $folder->serverid = $af['folderid'];
                $folder->parentid = 0;                  // only top folders are supported
                $folder->displayname = $af['name'];
                $folder->type = $af['type'];
                // save store as custom property which is not streamed directly to the device
                $folder->NoBackendFolder = true;
                $folder->Store = $af['store'];
                self::$addSyncFolders[$folder->serverid] = $folder;
            }

        }
        return true;
    }

    /**
     * Returns the StateMachine object
     * which has to be an IStateMachine implementation
     *
     * @access public
     * @return object   implementation of IStateMachine
     * @throws FatalNotImplementedException
     */
    static public function GetStateMachine() {
        if (!isset(ZPush::$stateMachine)) {
            // the backend could also return an own IStateMachine implementation
            $backendStateMachine = self::GetBackend()->GetStateMachine();

            // if false is returned, use the default StateMachine
            if ($backendStateMachine !== false) {
                ZLog::Write(LOGLEVEL_DEBUG, "Backend implementation of IStateMachine: ".get_class($backendStateMachine));
                if (in_array('IStateMachine', class_implements($backendStateMachine)))
                    ZPush::$stateMachine = $backendStateMachine;
                else
                    throw new FatalNotImplementedException("State machine returned by the backend does not implement the IStateMachine interface!");
            }
            else {
                // Initialize the default StateMachine
                include_once('lib/filestatemachine.php');
                ZPush::$stateMachine = new FileStateMachine();
            }
        }
        return ZPush::$stateMachine;
    }

    /**
     * Returns the DeviceManager object
     *
     * @access public
     * @return object DeviceManager
     */
    static public function GetDeviceManager() {
        if (!isset(ZPush::$deviceManager))
            ZPush::$deviceManager = new DeviceManager();

        return ZPush::$deviceManager;
    }


    /**
     * Returns the SearchProvider object
     * which has to be an ISearchProvider implementation
     *
     * @access public
     * @return object   implementation of ISearchProvider
     * @throws FatalNotImplementedException
     */
    static public function GetSearchProvider() {
        if (!isset(ZPush::$searchProvider)) {
            // is a global searchprovider configured ? It will  outrank the backend
            // TODO eventually the searchprovider class has to be loaded separately
            if (defined('SEARCH_PROVIDER') && @constant('SEARCH_PROVIDER') != "" && class_exists(SEARCH_PROVIDER)) {
                $searchClass = @constant('SEARCH_PROVIDER');
                $aSearchProvider = new $searchClass();
            }
            // get the searchprovider from the backend
            else
                $aSearchProvider = self::GetBackend()->GetSearchProvider();

            if (in_array('ISearchProvider', class_implements($aSearchProvider)))
                ZPush::$searchProvider = $aSearchProvider;
            else
                throw new FatalNotImplementedException("Instantiated SearchProvider does not implement the ISearchProvider interface!");
        }
        return ZPush::$searchProvider;
    }

    /**
     * Returns the Backend for this request
     * the backend has to be an IBackend implementation
     *
     * @access public
     * @return object     IBackend implementation
     */
    static public function GetBackend() {
        // if the backend is not yet loaded, load backend drivers and instantiate it
        if (!isset(ZPush::$backend)) {
            $backend_dir = opendir(BASE_PATH . "backend");
            while($entry = readdir($backend_dir)) {
                // TODO Only load our main backend (or it's subfolder should be included by default). The backend should then load all other dependencies.
                $subdirfile = BASE_PATH . "backend/" . $entry . "/" . $entry . ".php";

                if(substr($entry,0,1) == "." || (substr($entry,-3) != "php" && !is_file($subdirfile)))
                    continue;

                // do not load Zarafa backend if PHP-MAPI is unavailable
                if (!function_exists("mapi_logon") && ($entry == "zarafa"))
                    continue;

                // do not load Kolab backend if not a Kolab system
                if (! file_exists('Horde/Kolab/Kolab_Zpush/lib/kolabActivesyncData.php') && ($entry == "kolab"))
                    continue;

                if (is_file($subdirfile))
                    $entry = $entry . "/" . $entry . ".php";

                ZLog::Write(LOGLEVEL_DEBUG, "including backend file: " . $entry);
                include_once(BASE_PATH . "backend/" . $entry);
            }

            // Initialize our backend
            $ourBackend = @constant('BACKEND_PROVIDER');
            if (class_exists($ourBackend))
                ZPush::$backend = new $ourBackend();
            else
                throw new FatalMisconfigurationException("Backend provider '".@constant('BACKEND_PROVIDER')."' can not be loaded. Check configuration!");
        }
        return ZPush::$backend;
    }

    /**
     * Returns additional folder objects which should be synchronized to the device
     *
     * @access public
     * @return array
     */
    static public function GetAdditionalSyncFolders() {
        // TODO if there are any user based folders which should be synchronized, they have to be returned here as well!!
        return self::$addSyncFolders;
    }

    /**
     * Returns additional folder objects which should be synchronized to the device
     *
     * @param string        $folderid
     * @param boolean       $noDebug        (opt) by default, debug message is shown
     *
     * @access public
     * @return string
     */
    static public function GetAdditionalSyncFolderStore($folderid, $noDebug = false) {
        $val = (isset(self::$addSyncFolders[$folderid]->Store))? self::$addSyncFolders[$folderid]->Store : false;
        if (!$noDebug)
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZPush::GetAdditionalSyncFolderStore('%s'): '%s'", $folderid, Utils::PrintAsString($val)));
        return $val;
    }

    /**
     * Returns a SyncObject class name for a folder class
     *
     * @param string $folderclass
     *
     * @access public
     * @return string
     * @throws FatalNotImplementedException
     */
    static public function getSyncObjectFromFolderClass($folderclass) {
        if (!isset(self::$classes[$folderclass]))
            throw new FatalNotImplementedException("Class '$folderclass' is not supported");

        $class = self::$classes[$folderclass][0];
        if (in_array(self::REQUIRESPROTOCOLVERSION, self::$classes[$folderclass]))
            return new $class(Request::getProtocolVersion());
        else
            return new $class();
    }

    /**
     * Returns the default foldertype for a folder class
     *
     * @param string $folderclass   folderclass sent by the mobile
     *
     * @access public
     * @return string
     */
    static public function getDefaultFolderTypeFromFolderClass($folderclass) {
        ZLog::Write(LOGLEVEL_DEBUG, "ZPush::getDefaultFolderTypeFromFolderClass('$folderclass'): ". self::$classes[$folderclass][1]);
        return self::$classes[$folderclass][1];
    }

    /**
     * Prints the Z-Push legal header to STDOUT
     * Using this breaks ActiveSync synchronization if wbxml is expected
     *
     * @param string $message               (opt) message to be displayed
     * @param string $additionalMessage     (opt) additional message to be displayed

     * @access public
     * @return
     *
     */
    static public function PrintZPushLegal($message = "", $additionalMessage = "") {
        $zpush_version = @constant('ZPUSH_VERSION');

        if ($message)
            $message = "<h3>". $message . "</h3>";
        if ($additionalMessage)
            $additionalMessage .= "<br>";

        header("Content-type: text/html");
        print <<<END
        <html>
        <header>
        <title>Z-Push ActiveSync</title>
        </header>
        <body>
        <font face="verdana">
        <h2>Z-Push - Open Source ActiveSync</h2>
        <b>Version $zpush_version</b><br>
        $message $additionalMessage
        <br><br>
        More information about Z-Push can be found at:<br>
        <a href="http://z-push.sf.net/">Z-Push homepage</a><br>
        <a href="http://z-push.sf.net/download">Z-Push download page at BerliOS</a><br>
        <a href="http://z-push.sf.net/tracker">Z-Push Bugtracker and Roadmap</a><br>
        <br>
        All modifications to this sourcecode must be published and returned to the community.<br>
        Please see <a href="http://www.gnu.org/licenses/agpl-3.0.html">AGPLv3 License</a> for details.<br>
        </font face="verdana">
        </body>
        </html>
END;
    }

    /**
     * Returns AS server header
     *
     * @access public
     * @return string
     */
    static public function GetServerHeader() {
        return "MS-Server-ActiveSync: 6.5.7638.1";
    }

    /**
     * Returns AS protocol versions which are supported
     *
     * @access public
     * @return string
     */
    static public function GetSupportedProtocolVersions() {
        return "MS-ASProtocolVersions: ". implode(',', self::$supportedASVersions);
    }

    /**
     * Returns AS commands which are supported
     *
     * @access public
     * @return string
     */
    static public function GetSupportedCommands() {
        $asCommands = array();
        // filter all non-activesync commands
        foreach (self::$supportedCommands as $c=>$v)
            if ($v === false || (is_array($v) && !in_array(self::NOACTIVESYNCCOMMAND, $v)))
                $asCommands[] = $c;

        return "MS-ASProtocolCommands: ". implode(',', $asCommands);
    }

    /**
     * Indicates if a commands requires authentication or not
     *
     * @param string $command
     *
     * @access public
     * @return boolean
     */
    static public function CommandNeedsAuthentication($command) {
        $stat = ! self::checkCommandOptions($command, self::UNAUTHENTICATED);
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZPush::CommandNeedsAuthentication('%s'): %s", $command, Utils::PrintAsString($stat)));
        return $stat;
    }

    /**
     * Indicates if the Provisioning check has to be forced on these commands
     *
     * @param string $command

     * @access public
     * @return boolean
     */
    static public function CommandNeedsProvisioning($command) {
        $stat = ! self::checkCommandOptions($command, self::UNPROVISIONED);
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZPush::CommandNeedsProvisioning('%s'): %s", $command, Utils::PrintAsString($stat)));
        return $stat;
    }

    /**
     * Indicates if these commands expect plain text input instead of wbxml
     *
     * @param string $command
     *
     * @access public
     * @return boolean
     */
    static public function CommandNeedsPlainInput($command) {
        $stat = self::checkCommandOptions($command, self::PLAININPUT);
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZPush::CommandNeedsPlainInput('%s'): %s", $command, Utils::PrintAsString($stat)));
        return $stat;
    }

    /**
     * Indicates if the comand to be executed operates on the hierarchy
     *
     * @param string $command

     * @access public
     * @return boolean
     */
    static public function HierarchyCommand($command) {
        $stat = self::checkCommandOptions($command, self::HIERARCHYCOMMAND);
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZPush::HierarchyCommand('%s'): %s", $command, Utils::PrintAsString($stat)));
        return $stat;
    }

    /**
     * Checks access types of a command
     *
     * @param string $command       a command
     * @param string $option        e.g. UNAUTHENTICATED

     * @access public
     * @return object StateMachine
     */
    static private function checkCommandOptions($command, $option) {
        if ($command === false) return false;

        if (!array_key_exists($command, self::$supportedCommands))
            throw new FatalNotImplementedException("Command '$command' is not supported");

        $capa = self::$supportedCommands[$command];
        return (is_array($capa))?in_array($option, $capa):false;
    }

}
?>