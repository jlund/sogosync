<?php
/***********************************************
* File      :   exceptions.php
* Project   :   Z-Push
* Descr     :   Defines all exceptions used in Z-Push
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

// Main Z-Push exception
class ZPushException extends Exception {
    protected $defaultLogLevel = LOGLEVEL_FATAL;
    protected $httpReturnCode = HTTP_CODE_500;
    protected $httpReturnMessage = "Internal Server Error";
    protected $httpHeaders = array();
    protected $showLegal = true;

    public function ZPushException($message = "", $code = 0, $previous = NULL, $logLevel = false) {
        if (! $message)
            $message = $this->httpReturnMessage;

        if (!$logLevel)
            $logLevel = $this->defaultLogLevel;

        ZLog::Write($logLevel, get_class($this) .': '. $message . ' - code: '.$code);
        parent::__construct($message, (int) $code);
    }

    public function getHTTPCodeString() {
        return $this->httpReturnCode . " ". $this->httpReturnMessage;
    }

    public function getHTTPHeaders() {
        return $this->httpHeaders;
    }

    public function showLegalNotice() {
        return $this->showLegal;
    }
}

// Fatal exceptions (execution stops)
class FatalException extends ZPushException {}
class FatalMisconfigurationException extends FatalException {}
class FatalNotImplementedException extends FatalException {}
class WBXMLException extends FatalNotImplementedException {}

class NoPostRequestException extends FatalException {
    const OPTIONS_REQUEST = 1;
    const GET_REQUEST = 2;
    protected $defaultLogLevel = LOGLEVEL_DEBUG;
}

// HTTP return code exceptions
class HTTPReturnCodeException extends FatalException {
    protected $defaultLogLevel = LOGLEVEL_ERROR;
    protected $showLegal = false;

    public function HTTPReturnCodeException($message = "", $code = 0, $previous = NULL, $logLevel = false) {
        if ($code)
            $this->httpReturnCode = $code;
        parent::__construct($message, (int) $code, $previous, $logLevel);
    }
}

class AuthenticationRequiredException extends HTTPReturnCodeException {
    protected $defaultLogLevel = LOGLEVEL_INFO;
    protected $httpReturnCode = HTTP_CODE_401;
    protected $httpReturnMessage = "Unauthorized";
    protected $httpHeaders = array('WWW-Authenticate: Basic realm="ZPush"');
    protected $showLegal = true;
}

class ProvisioningRequiredException extends HTTPReturnCodeException {
    protected $defaultLogLevel = LOGLEVEL_INFO;
    protected $httpReturnCode = HTTP_CODE_449;
    protected $httpReturnMessage = "Retry after sending a PROVISION command";
}

// Non fatal exceptions
class NotImplementedException extends ZPushException {
    protected $defaultLogLevel = LOGLEVEL_ERROR;
}

class SyncObjectBrokenException extends ZPushException {
    protected $defaultLogLevel = LOGLEVEL_WARN;
    private $syncObject;

    /**
     * Returns the SyncObject which caused this Exception (if set)
     *
     * @access public
     * @return SyncObject
     */
    public function GetSyncObject() {
        return isset($this->syncObject) ? $this->syncObject : false;
    }

    /**
     * Sets the SyncObject which caused the exception so it can be later retrieved
     *
     * @param SyncObject    $syncobject
     *
     * @access public
     * @return boolean
     */
    public function SetSyncObject($syncobject) {
        $this->syncObject = $syncobject;
        return true;
    }
}

class StatusException extends ZPushException {
    protected $defaultLogLevel = LOGLEVEL_INFO;
}
class StateNotFoundException extends StatusException {}
class StateInvalidException extends StatusException {}
class NoHierarchyCacheAvailableException extends StateNotFoundException {}
class StateNotYetAvailableException extends StatusException {}
?>