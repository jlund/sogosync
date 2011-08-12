<?php
/***********************************************
* File      :   index.php
* Project   :   Z-Push
* Descr     :   This is the entry point
*               through which all requests
*               are processed.
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

ob_start(false, 1048576);

include_once('lib/zpushdefs.php');
include_once('lib/exceptions.php');
include_once('lib/utils.php');
include_once('lib/device.php');
include_once('lib/zpush.php');
include_once('lib/interfaces.php');
include_once('lib/streamer.php');
include_once('lib/streamimporter.php');
include_once('lib/syncobjects.php');
include_once('lib/debug.php');
include_once('lib/wbxml.php');
include_once('lib/backend.php');
include_once('lib/searchprovider.php');
include_once('lib/request.php');
include_once('lib/hierarchymemorywrapper.php');

include_once('config.php');
include_once('version.php');


    // Attempt to set maximum execution time
    ini_set('max_execution_time', SCRIPT_TIMEOUT);
    set_time_limit(SCRIPT_TIMEOUT);

    try {
        // check config & initialize the basics
        ZPush::CheckConfig();   // throws Exception
        Request::Initialize();
        ZLog::Initialize();

        ZLog::Write(LOGLEVEL_INFO,
                    sprintf("-------- Start version='%s' method='%s' from='%s' cmd='%s' getUser='%s' devId='%s' devType='%s'",
                                    @constant('ZPUSH_VERSION'), Request::getMethod(), Request::getRemoteAddr(),
                                    Request::getCommand(), Request::getGETUser(), Request::getDeviceID(), Request::getDeviceType()));

        // Stop here if this is an OPTIONS request
        if (Request::isMethodOPTIONS())
            throw new NoPostRequestException("Options request", NoPostRequestException::OPTIONS_REQUEST);

        // Process request headers and look for AS headers
        Request::ProcessHeaders();

        // Check required GET parameters
        if(Request::isMethodPOST() && (!Request::getCommand() || !Request::getGETUser() || !Request::getDeviceID() || !Request::getDeviceType()))
            throw new FatalException("Requested the Z-Push URL without the required GET parameters");

        // Load the backend
        $backend = ZPush::GetBackend();

        // always request the authorization header
        if (! Request::AuthenticationInfo())
            throw new AuthenticationRequiredException("Access denied. Please send authorisation information");

        // check the provisioning information
        if (PROVISIONING === true && Request::isMethodPOST() && ZPush::CommandNeedsProvisioning(Request::getCommand()) &&
            ((Request::wasPolicyKeySent() && Request::getPolicyKey() == 0) || ZPush::GetDeviceManager()->ProvisioningRequired(Request::getPolicyKey())) &&
            (LOOSE_PROVISIONING === false ||
            (LOOSE_PROVISIONING === true && Request::wasPolicyKeySent())))
            //TODO for AS 14 send a wbxml response
            throw new ProvisioningRequiredException();

        // most commands require an authenticated user
        if (ZPush::CommandNeedsAuthentication(Request::getCommand()))
            RequestProcessor::Authenticate();

        // Do the actual processing of the request
        if (Request::isMethodGET())
            throw new NoPostRequestException("This is the Z-Push location and can only be accessed by Microsoft ActiveSync-capable devices", NoPostRequestException::GET_REQUEST);

        // Do the actual request
        header(ZPush::getServerHeader());
        RequestProcessor::Initialize();
        if(!RequestProcessor::HandleRequest())
            throw new WBXMLException(ZLog::GetWBXMLDebugInfo());

        // stream the data
        $len = ob_get_length();
        $data = ob_get_contents();
        ob_end_clean();

        // log amount of data transferred
        // TODO check $len when streaming more data (e.g. Attachments), as the data will be send chunked
        ZPush::GetDeviceManager()->sentData($len);

        // Unfortunately, even though Z-Push can stream the data to the client
        // with a chunked encoding, using chunked encoding breaks the progress bar
        // on the PDA. So the data is de-chunk here, written a content-length header and
        // data send as a 'normal' packet. If the output packet exceeds 1MB (see ob_start)
        // then it will be sent as a chunked packet anyway because PHP will have to flush
        // the buffer.
        header("Content-Length: $len");
        print $data;

        // destruct backend after all data is on the stream
        $backend->Logoff();
    }

    catch (NoPostRequestException $nopostex) {
        if ($nopostex->getCode() == NoPostRequestException::OPTIONS_REQUEST) {
            header(ZPush::GetServerHeader());
            header(ZPush::GetSupportedProtocolVersions());
            header(ZPush::GetSupportedCommands());
            ZLog::Write(LOGLEVEL_INFO, $nopostex->getMessage());
        }
        else if ($nopostex->getCode() == NoPostRequestException::GET_REQUEST) {
            if (Request::getUserAgent())
                ZLog::Write(LOGLEVEL_INFO, sprintf("User-agent: '%s'", Request::getUserAgent()));
            if (!headers_sent() && $nopostex->showLegalNotice())
                ZPush::PrintZPushLegal('GET not supported', $nopostex->getMessage());
        }
    }

    catch (Exception $ex) {
        if (Request::getUserAgent())
            ZLog::Write(LOGLEVEL_INFO, sprintf("User-agent: '%s'", Request::getUserAgent()));
        $exclass = get_class($ex);

        if(!headers_sent()) {
            if ($ex instanceof ZPushException) {
                header('HTTP/1.1 '. $ex->getHTTPCodeString());
                foreach ($ex->getHTTPHeaders() as $h)
                    header($h);
            }
            // something really unexpected happened!
            else
                header('HTTP/1.1 500 Internal Server Error');
        }
        else
            ZLog::Write(LOGLEVEL_FATAL, "Exception: ($exclass) - headers were already sent. Message: ". $ex->getMessage());

        // Try to output some kind of error information. This is only possible if
        // the output had not started yet. If it has started already, we can't show the user the error, and
        // the device will give its own (useless) error message.
        if (!($ex instanceof ZPushException) || $ex->showLegalNotice()) {
            $cmdinfo = (Request::getCommand())? sprintf(" processing command <i>%s</i>", Request::getCommand()): "";
            $extrace = $ex->getTrace();
            $trace = (!empty($extrace))? "\n\nTrace:\n". print_r($extrace,1):"";
            ZPush::PrintZPushLegal($exclass . $cmdinfo, sprintf('<pre>%s</pre>',$ex->getMessage() . $trace));
        }
    }

    // save device data if the DeviceManager is available
    if (ZPush::GetDeviceManager(false))
        ZPush::GetDeviceManager()->save();

    // end gracefully
    ZLog::Write(LOGLEVEL_INFO, '-------- End');
?>