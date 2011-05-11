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
        // initialize some basics & check config
        Request::Initialize();
        ZPush::CheckConfig();   // throws Exception
        ZLog::Initialize();

        ZLog::Write(LOGLEVEL_INFO,
                    sprintf("-------- Start version='%s' method='%s' from='%s' cmd='%s' getUser='%s' devId='%s' devType='%s'",
                                    @constant('ZPUSH_VERSION'), Request::getMethod(), Request::getRemoteAddr(),
                                    Request::getCommand(), Request::getGETUser(), Request::getDeviceID(), Request::getDeviceType()));

        // Stop here if this is an OPTIONS request
        if (Request::isMethodOPTIONS())
            throw new NoPostRequestException("Options request", NoPostRequestException::OPTIONS_REQUEST);

        // Get the DeviceManager
        $deviceManager = ZPush::GetDeviceManager();

        // Check required GET parameters
        if(Request::isMethodPOST() && (!Request::getCommand() || !Request::getGETUser() || !Request::getDeviceID() || !Request::getDeviceType()))
            throw new FatalException("Requested the Z-Push URL without the required GET parameters");

        // Process request headers and look for AS headers
        Request::ProcessHeaders();

        // Load our backend drivers
        $backend = ZPush::GetBackend();

        // check the provisioning information
        if (PROVISIONING === true && Request::isMethodPOST() && ZPush::CommandNeedsProvisioning(Request::getCommand()) &&
            $deviceManager->ProvisioningRequired(Request::getPolicyKey()) &&
            (LOOSE_PROVISIONING === false ||
            (LOOSE_PROVISIONING === true && Request::wasPolicyKeySent())))
            throw new ProvisioningRequiredException("Retry after sending a PROVISION command");

        // most commands need authentication
        if (ZPush::CommandNeedsAuthentication(Request::getCommand())) {
            if (! Request::AuthenticationInfo())
                throw new AuthenticationRequiredException("Access denied. Please send authorisation information", AuthenticationRequiredException::AUTHENTICATION_NOT_SENT);

            if($backend->Logon(Request::getAuthUser(), Request::getAuthDomain(), Request::getAuthPassword()) == false)
                throw new AuthenticationRequiredException("Access denied.  Username or password incorrect", AuthenticationRequiredException::AUTHENTICATION_FAILED);

            // mark this request as "authenticated"
            Request::ConfirmUserAuthentication();

            // do Backend->Setup() for permission check
            // Request::getGETUser() is usually the same as the Request::getAuthUser().
            // If the GETUser is different from the AuthUser, the AuthUser MUST HAVE admin
            // permissions on GETUser store. Only then the Setup() will be sucessfull.
            // This allows the user 'john' do operations as user 'joe' if he has sufficient privileges.
            if($backend->Setup(Request::getGETUser(), true) == false)
                throw new AuthenticationRequiredException(sprintf("Not enough privileges of '%s' to setup for user '%s': Permission denied", Request::getAuthUser(), Request::getGETUser()),
                            AuthenticationRequiredException::SETUP_FAILED);
        }

        // Do the actual processing of the request
        if (Request::isMethodGET())
            throw new NoPostRequestException("This is the Z-Push location and can only be accessed by Microsoft ActiveSync-capable devices", NoPostRequestException::GET_REQUEST);

        else if (Request::isMethodPOST()) {
            header(ZPush::getServerHeader());
            ZLog::Write(LOGLEVEL_DEBUG, "POST cmd: ". Request::getCommand());

            // Do the actual request
            RequestProcessor::Initialize();

            if(!RequestProcessor::HandleRequest())
                throw new WBXMLException(ZLog::GetWBXMLDebugInfo());
        }

        // stream the data
        $len = ob_get_length();
        $data = ob_get_contents();
        ob_end_clean();

        // log amount of data transferred
        // TODO check $len when streaming more data (e.g. Attachments), as the data will be send chunked
        $deviceManager->sentData($len);

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

    catch (ProvisioningRequiredException $prex) {
        header('HTTP/1.1 449 '. $prex->getMessage());
        header(ZPush::GetServerHeader());
        header(ZPush::GetSupportedProtocolVersions());
        header(ZPush::GetSupportedCommands());
        header('Cache-Control: private');
        ZLog::Write(LOGLEVEL_INFO, 'ProvisioningRequiredException: '. $prex->getMessage());
        if (isset($deviceManager))
            $deviceManager->setException($prex);
    }

    catch (AuthenticationRequiredException $auex) {
        header('HTTP/1.1 401 Unauthorized');
        header('WWW-Authenticate: Basic realm="ZPush"');
        ZLog::Write(LOGLEVEL_INFO,'User-agent: '. Request::getUserAgent());
        ZLog::Write(LOGLEVEL_WARN, 'AuthenticationRequiredException: '. $auex->getMessage());

        ZPush::PrintZPushLegal($auex->getMessage());

        if (isset($deviceManager))
            $deviceManager->setException($auex);
    }

    catch (NoPostRequestException $nopostex) {
        if ($nopostex->getCode() == NoPostRequestException::OPTIONS_REQUEST) {
            header(ZPush::GetServerHeader());
            header(ZPush::GetSupportedProtocolVersions());
            header(ZPush::GetSupportedCommands());
            ZLog::Write(LOGLEVEL_INFO, $nopostex->getMessage());
            if (isset($deviceManager))
                $deviceManager->setException($nopostex);
        }
        else if ($nopostex->getCode() == NoPostRequestException::GET_REQUEST) {
            ZLog::Write(LOGLEVEL_INFO, 'User-agent: '. Request::getUserAgent());
            ZPush::PrintZPushLegal('GET not supported', $nopostex->getMessage());
            if (isset($deviceManager))
                $deviceManager->setException($nopostex);
        }
    }

    catch (Exception $ex) {
        $exclass = get_class($ex);
        ZLog::Write(LOGLEVEL_FATAL, "Exception: ($exclass) ". $ex->getMessage());

        // Something unexpected happened. Try to output some kind of error information. This is only possible if
        // the output had not started yet. If it has started already, we can't show the user the error, and
        // the device will give its own (useless) error message.
        if(!headers_sent()) {
            // TODO search for AS return codes and send these to the mobile
            header('HTTP/1.1 500 Internal Server Error');
            ZPush::PrintZPushLegal($exclass. " processing command <i>". Request::getCommand() ."</i>!", sprintf('<pre>%s</pre>', $ex->getMessage()));
        }
        if (isset($deviceManager))
            $deviceManager->setException($ex);
    }

    // save device data anyway
    if (isset($deviceManager))
        $deviceManager->save();

    // end gracefully
    ZLog::Write(LOGLEVEL_INFO, '-------- End');
?>