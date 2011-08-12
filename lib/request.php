<?php
/***********************************************
* File      :   request.php
* Project   :   Z-Push
* Descr     :   This class checks and processes
*               all incoming data of the request.
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

    /**
     * Initializes request data
     *
     * @access public
     * @return
     */
    static public function Initialize() {
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

        //TODO AS 14 style query string
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

        ZLog::Write(LOGLEVEL_DEBUG, "Incoming PolicyKey: " . (self::wasPolicyKeySent() ? self::$policykey:'none'));
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
     * Sometimes strange device ids are sumbitted
     * No device information should be saved when this happens
     *
     * @access public
     * @return boolean       false if invalid
     */
    static public function isValidDeviceID() {
        if (self::getDeviceID() === "validate")
            return false;
        else
            return true;
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
?>