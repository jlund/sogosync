<?php
/***********************************************
* File      :   debug.php
* Project   :   Z-Push
* Descr     :   Debuging functions
*
* Created   :   01.10.2007
*
* Copyright 2007 - 2010 Zarafa Deutschland GmbH
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

if (!defined('LOGUSERLEVEL'))
    define('LOGUSERLEVEL', LOGLEVEL_OFF);

if (!defined('LOGLEVEL'))
    define('LOGLEVEL', LOGLEVEL_OFF);

function writeLog($loglevel, $message) {
    global $auth_user, $devid, $wbxmlLogUsers;

    if (!defined('WBXML_DEBUG') && isset($auth_user)) {
        // define the WBXML_DEBUG mode on user basis depending on the configurations
        if (LOGLEVEL >= LOGLEVEL_WBXML || (LOGUSERLEVEL >= LOGLEVEL_WBXML && in_array($auth_user, $wbxmlLogUsers)))
            define('WBXML_DEBUG', true);
        else
            define('WBXML_DEBUG', false);
    }

    $user = (isset($auth_user))?" [". $auth_user ."]":"";
    // log the device id if the global loglevel is set to log devid or the user is in the $wbxmlLogUsers and has the right log level
    if (isset($devid) && $devid != "" && (LOGLEVEL >= LOGLEVEL_DEVICEID || (LOGUSERLEVEL >= LOGLEVEL_DEVICEID && in_array($auth_user, $wbxmlLogUsers)))) {
        $user .= " [". $devid ."]";
    }

    @$date = strftime("%x %X");
    $data = "$date [". str_pad(getmypid(),5," ",STR_PAD_LEFT) ."] ". getLogLevelString($loglevel, (LOGLEVEL > LOGLEVEL_INFO)) . $user . " $message\n";

    if ($loglevel <= LOGLEVEL) {
        @file_put_contents(LOGFILE, $data, FILE_APPEND);
    }
    if ($loglevel <= LOGUSERLEVEL && in_array($auth_user, $wbxmlLogUsers)) {
        // padd level for better reading
        $data = str_replace(getLogLevelString($loglevel), getLogLevelString($loglevel,true), $data);
        // only use plain old a-z characters for the generic log file
        @file_put_contents(LOGFILEDIR . "/". preg_replace('/[^a-z]/', '', strtolower($auth_user)). ".log", $data, FILE_APPEND);
    }
    if (($loglevel & LOGLEVEL_FATAL) || ($loglevel & LOGLEVEL_ERROR)) {
        @file_put_contents(LOGERRORFILE, $data, FILE_APPEND);
    }

    if ($loglevel & LOGLEVEL_WBXMLSTACK) {
        // debugstr holds wbxml debug info
        global $debugstr;
        if (!isset($debugstr))
            $debugstr = "";
        $debugstr .= "$message\n";
    }
}


// WBXML STACK
// expose wbxml debug information to the client
function getDebugInfo() {
    global $debugstr;
    return (isset($debugstr))? $debugstr : "not available";
}


function getLogLevelString($loglevel, $pad = false) {
    if ($pad) $s = " ";
    else      $s = "";
    switch($loglevel) {
        case LOGLEVEL_OFF:   return ""; break;
        case LOGLEVEL_FATAL: return "[FATAL]"; break;
        case LOGLEVEL_ERROR: return "[ERROR]"; break;
        case LOGLEVEL_WARN:  return "[".$s."WARN]"; break;
        case LOGLEVEL_INFO:  return "[".$s."INFO]"; break;
        case LOGLEVEL_DEBUG: return "[DEBUG]"; break;
        case LOGLEVEL_WBXML: return "[WBXML]"; break;
        case LOGLEVEL_DEVICEID: return "[DEVICEID]"; break;
        case LOGLEVEL_WBXMLSTACK: return "[WBXMLSTACK]"; break;
    }
}

function zarafa_error_handler($errno, $errstr, $errfile, $errline, $errcontext) {
    $bt = debug_backtrace();
    switch ($errno) {
        case 8192:      // E_DEPRECATED since PHP 5.3.0
            // do not handle this message
            break;

        case E_NOTICE:
        case E_WARNING:
            writeLog(LOGLEVEL_WARN, "$errfile:$errline $errstr ($errno)");
            break;

        default:
            writeLog(LOGLEVEL_ERROR, "trace error: $errfile:$errline $errstr ($errno) - backtrace: ". (count($bt)-1) . " steps");
            for($i = 1, $bt_length = count($bt); $i < $bt_length; $i++) {
                $file = $line = "unknown";
                if (isset($bt[$i]['file'])) $file = $bt[$i]['file'];
                if (isset($bt[$i]['line'])) $line = $bt[$i]['line'];
                writeLog(LOGLEVEL_ERROR, "trace: $i:". $file . ":" . $line. " - " . ((isset($bt[$i]['class']))? $bt[$i]['class'] . $bt[$i]['type']:""). $bt[$i]['function']. "()");
            }
            break;
    }
}

// deprecated
// backwards compatible
function debugLog($message) {
    writeLog(LOGLEVEL_DEBUG, $message);
}


error_reporting(E_ALL);
set_error_handler("zarafa_error_handler");

?>