<?php
/***********************************************
* File      :   ics.php
* Project   :   Z-Push
* Descr     :   This is a generic class that is
*               used by both the proxy importer
*               (for outgoing messages) and our
*               local importer (for incoming
*               messages). Basically all shared
*               conversion data for converting
*               to and from MAPI objects is in here.
*
* Created   :   01.10.2011
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
*************************************************/

// default PHP-MAPI classes
include_once('mapi/mapi.util.php');
include_once('mapi/mapidefs.php');
include_once('mapi/mapitags.php');
include_once('mapi/mapicode.php');
include_once('mapi/mapiguid.php');
//task recurrence support in php-mapi is available since ZCP 6.40.4
if (checkMapiExtVersion('6.40.4')) {
    include_once('mapi/class.baserecurrence.php');
    include_once('mapi/class.taskrecurrence.php');
}
include_once('mapi/class.recurrence.php');
include_once('mapi/class.meetingrequest.php');
include_once('mapi/class.freebusypublish.php');

// processing of RFC822 messages
include_once('include/mimeDecode.php');
require_once('include/z_RFC822.php');
include_once('include/z_tnef.php');
include_once('include/z_ical.php');

// components of Zarafa backend
include_once('mapiutils.php');
include_once('mapiprovider.php');
include_once('mapiphpwrapper.php');
include_once('importer.php');
include_once('exporter.php');


class BackendZarafa implements IBackend, ISearchProvider {
    protected $_session;
    protected $_user;
    protected $_devid;
    protected $_importedFolders;

    /**
     * Constructor of the Zarafa Backend
     *
     * @access public
     */
    public function BackendZarafa() {
        $this->_session = false;
        $this->_user = false;
        $this->_devid = false;
        $this->_importedFolders = array();
    }

    /**
     * Indicates which StateMachine should be used
     *
     * @access public
     * @return boolean      ZarafaBackend uses the default FileStateMachine
     */
    public function GetStateMachine() {
        return false;
    }

    /**
     * Returns the ZarafaBackend as it implements the ISearchProvider interface
     * This could be overwritten by the global configuration
     *
     * @access public
     * @return object       Implementation of ISearchProvider
     */
    public function GetSearchProvider() {
        return $this;
    }

    /**
     * Authenticates the user with the configured Zarafa server
     *
     * @param string        $username
     * @param string        $domain
     * @param string        $password
     *
     * @access public
     * @return boolean
     */
    public function Logon($user, $domain, $pass) {
        $pos = strpos($user, "\\");
        if($pos)
            $user = substr($user, $pos+1);

        try {
            $this->_session = @mapi_logon_zarafa($user, $pass, MAPI_SERVER);
        }
        catch (Exception $ex) {
            throw new AuthenticationRequiredException($ex->getMessage(), AUTHENTICATION_FAILED);
        }

        if($this->_session === false) {
            writeLog(LOGLEVEL_WARN, "logon failed for user $user");
            $this->_defaultstore = false;
            return false;
        }

        // Get/open default store
        $this->_defaultstore = $this->_openDefaultMessageStore($this->_session);

        if($this->_defaultstore === false) {
            // TODO set HTTP status code if available
            writeLog(LOGLEVEL_ERROR, "user $user has no default store");
            return false;
        }

        writeLog(LOGLEVEL_INFO, "User $user logged on");

        // check if this is a Zarafa 7 store with unicode support
        $this->_isUnicodeStore();
        return true;
    }

    /**
     * Setup of the backend
     *
     * @param string        $user
     * @param string        $devid
     * @param string        $protocolversion
     *
     * @access public
     * @return boolean
     */
    public function Setup($user, $devid, $protocolversion) {
        $this->_user = $user;
        $this->_devid = $devid;
        $this->_protocolversion = $protocolversion;

        return true;
    }

    /**
     * Logs off
     * Free/Busy information is updated for modified calendars
     *
     * @access public
     * @return boolean
     */
    public function Logoff() {
        // TODO: if a calendar of a public/shared folder was changed, F/B should be published as well!
        // publish free busy time after finishing the synchronization process
        // update if the calendar folder received incoming changes
        $storeprops = mapi_getprops($this->_defaultstore, array(PR_USER_ENTRYID));
        $root = mapi_msgstore_openentry($this->_defaultstore);
        if (!$root) return true;

        $rootprops = mapi_getprops($root, array(PR_IPM_APPOINTMENT_ENTRYID));
        foreach($this->_importedFolders as $folderid) {
            $entryid = mapi_msgstore_entryidfromsourcekey($this->_defaultstore, hex2bin($folderid));
            if($rootprops[PR_IPM_APPOINTMENT_ENTRYID] == $entryid) {
                writeLog(LOGLEVEL_DEBUG, "Update freebusy for ". $folderid);
                $calendar = mapi_msgstore_openentry($this->_defaultstore, $entryid);

                $pub = new FreeBusyPublish($this->_session, $this->_defaultstore, $calendar, $storeprops[PR_USER_ENTRYID]);
                $pub->publishFB(time() - (7 * 24 * 60 * 60), 6 * 30 * 24 * 60 * 60); // publish from one week ago, 6 months ahead
            }
        }

        return true;
    }

    /**
     * Returns an array of SyncFolder types with the entire folder hierarchy
     * on the server (the array itself is flat, but refers to parents via the 'parent' property
     *
     * provides AS 1.0 compatibility
     *
     * @access public
     * @return array SYNC_FOLDER
     */
    public function GetHierarchy() {
        $folders = array();
        $importer = false;
        $himp= new PHPHierarchyWrapper($this->_defaultstore, $importer);

        $rootfolder = mapi_msgstore_openentry($this->_defaultstore);
        $rootfolderprops = mapi_getprops($rootfolder, array(PR_SOURCE_KEY));
        $rootfoldersourcekey = bin2hex($rootfolderprops[PR_SOURCE_KEY]);

        $hierarchy =  mapi_folder_gethierarchytable($rootfolder, CONVENIENT_DEPTH);
        $rows = mapi_table_queryallrows($hierarchy, array(PR_ENTRYID));

        foreach ($rows as $row) {
            $mapifolder = mapi_msgstore_openentry($this->_defaultstore, $row[PR_ENTRYID]);
            $folder = $himp->_getFolder($mapifolder);

            if (isset($folder->parentid) && $folder->parentid != $rootfoldersourcekey)
                $folders[] = $folder;
        }

        return $folders;
    }

    /**
     * Returns the importer to process changes from the mobile
     * If no $folderid is given, hierarchy importer is expected
     *
     * @param string        $folderid (opt)
     *
     * @access public
     * @return object(ImportChanges)
     */
    public function GetImporter($folderid = false) {
        writeLog(LOGLEVEL_DEBUG, sprintf("BackendZarafa->GetImporter() folderid: '%s'", Utils::PrintAsString($folderid)));
        if($folderid !== false) {
            $this->_importedFolders[] = $folderid;
            return new ImportChangesICS($this->_session, $this->_defaultstore, hex2bin($folderid));
        }
        else
            return new ImportChangesICS($this->_session, $this->_defaultstore);
    }

    /**
     * Returns the exporter to send changes to the mobile
     * If no $folderid is given, hierarchy exporter is expected
     *
     * @param string        $folderid (opt)
     *
     * @access public
     * @return object(ExportChanges)
     */
    public function GetExporter($folderid = false) {
        if($folderid !== false)
            return new ExportChangesICS($this->_session, $this->_defaultstore, hex2bin($folderid));
        else
            return new ExportChangesICS($this->_session, $this->_defaultstore);
    }

    /**
     * Sends an e-mail
     * This messages needs to be saved into the 'sent items' folder
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
     // TODO implement , $saveInSent = true
    public function SendMail($rfc822, $forward = false, $reply = false, $parent = false, $saveInSent = true) {
        if (WBXML_DEBUG == true) {
            writeLog(LOGLEVEL_WBXML, "SendMail: forward: $forward   reply: $reply   parent: $parent");
            foreach(preg_split("/(\r?\n)/", $rfc822) as $rfc822line)
                writeLog(LOGLEVEL_WBXML, "SendMail RFC822:". $rfc822line);
        }

        $mimeParams = array('decode_headers' => true,
                            'decode_bodies' => true,
                            'include_bodies' => true,
                            'charset' => 'utf-8');

        $mimeObject = new Mail_mimeDecode($rfc822);
        $message = $mimeObject->decode($mimeParams);

        // Open the outbox and create the message there
        $storeprops = mapi_getprops($this->_defaultstore, array(PR_IPM_OUTBOX_ENTRYID, PR_IPM_SENTMAIL_ENTRYID));
        if(!isset($storeprops[PR_IPM_OUTBOX_ENTRYID])) {
            writeLog(LOGLEVEL_ERROR, "Outbox not found to create message");
            return false;
        }

        $outbox = mapi_msgstore_openentry($this->_defaultstore, $storeprops[PR_IPM_OUTBOX_ENTRYID]);
        if(!$outbox) {
            // TODO: this should throw a hard error, stop all further syncs and notify the administrator
            writeLog(LOGLEVEL_ERROR, "Unable to open outbox");
            return false;
        }

        $mapimessage = mapi_folder_createmessage($outbox);

        mapi_setprops($mapimessage, array(
            PR_SUBJECT => u2wi(isset($message->headers["subject"])?$message->headers["subject"]:""),
            PR_SENTMAIL_ENTRYID => $storeprops[PR_IPM_SENTMAIL_ENTRYID],
            PR_MESSAGE_CLASS => "IPM.Note",
            PR_MESSAGE_DELIVERY_TIME => time()
        ));

        if(isset($message->headers["x-priority"])) {
            switch($message->headers["x-priority"]) {
                case 1:
                case 2:
                    $priority = PRIO_URGENT;
                    $importance = IMPORTANCE_HIGH;
                    break;
                case 4:
                case 5:
                    $priority = PRIO_NONURGENT;
                    $importance = IMPORTANCE_LOW;
                    break;
                case 3:
                default:
                    $priority = PRIO_NORMAL;
                    $importance = IMPORTANCE_NORMAL;
                    break;
            }
            mapi_setprops($mapimessage, array(PR_IMPORTANCE => $importance, PR_PRIORITY => $priority));
        }

        $addresses = array();

        $toaddr = $ccaddr = $bccaddr = array();

        $Mail_RFC822 = new Mail_RFC822();
        if(isset($message->headers["to"]))
            $toaddr = $Mail_RFC822->parseAddressList($message->headers["to"]);
        if(isset($message->headers["cc"]))
            $ccaddr = $Mail_RFC822->parseAddressList($message->headers["cc"]);
        if(isset($message->headers["bcc"]))
            $bccaddr = $Mail_RFC822->parseAddressList($message->headers["bcc"]);

        // Add recipients
        $recips = array();

        if(isset($toaddr)) {
            foreach(array(MAPI_TO => $toaddr, MAPI_CC => $ccaddr, MAPI_BCC => $bccaddr) as $type => $addrlist) {
                foreach($addrlist as $addr) {
                    $mapirecip[PR_ADDRTYPE] = "SMTP";
                    $mapirecip[PR_EMAIL_ADDRESS] = $addr->mailbox . "@" . $addr->host;
                    if(isset($addr->personal) && strlen($addr->personal) > 0)
                        $mapirecip[PR_DISPLAY_NAME] = u2wi($addr->personal);
                    else
                        $mapirecip[PR_DISPLAY_NAME] = $mapirecip[PR_EMAIL_ADDRESS];
                    $mapirecip[PR_RECIPIENT_TYPE] = $type;

                    $mapirecip[PR_ENTRYID] = mapi_createoneoff($mapirecip[PR_DISPLAY_NAME], $mapirecip[PR_ADDRTYPE], $mapirecip[PR_EMAIL_ADDRESS]);

                    array_push($recips, $mapirecip);
                }
            }
        }

        mapi_message_modifyrecipients($mapimessage, 0, $recips);

        // Loop through message subparts.
        $body = "";
        $body_html = "";
        if($message->ctype_primary == "multipart" && ($message->ctype_secondary == "mixed" || $message->ctype_secondary == "alternative")) {
            $mparts = $message->parts;
            for($i=0; $i<count($mparts); $i++) {
                $part = $mparts[$i];

                // palm pre & iPhone send forwarded messages in another subpart which are also parsed
                if($part->ctype_primary == "multipart" && ($part->ctype_secondary == "mixed" || $part->ctype_secondary == "alternative"  || $part->ctype_secondary == "related")) {
                    foreach($part->parts as $spart)
                        $mparts[] = $spart;
                    continue;
                }

                // standard body
                if($part->ctype_primary == "text" && $part->ctype_secondary == "plain" && isset($part->body) && (!isset($part->disposition) || $part->disposition != "attachment")) {
                        $body .= u2wi($part->body); // assume only one text body
                }
                // html body
                elseif($part->ctype_primary == "text" && $part->ctype_secondary == "html") {
                    $body_html .= u2wi($part->body);
                }
                // TNEF
                elseif($part->ctype_primary == "ms-tnef" || $part->ctype_secondary == "ms-tnef") {
                    $zptnef = new ZPush_tnef($this->_defaultstore);
                    $mapiprops = array();
                    $zptnef->extractProps($part->body, $mapiprops);
                    if (is_array($mapiprops) && !empty($mapiprops)) {
                        //check if it is a recurring item
                        $tnefrecurr = GetPropIDFromString($this->_defaultstore, "PT_BOOLEAN:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0x5");
                        if (isset($mapiprops[$tnefrecurr])) {
                            $this -> _handleRecurringItem($mapimessage, $mapiprops);
                        }
                        mapi_setprops($mapimessage, $mapiprops);
                    }
                    else writeLog(LOGLEVEL_WARN, "TNEF: Mapi props array was empty");
                }
                // iCalendar
                elseif($part->ctype_primary == "text" && $part->ctype_secondary == "calendar") {
                    $zpical = new ZPush_ical($this->_defaultstore);
                    $mapiprops = array();
                    $zpical->extractProps($part->body, $mapiprops);

                    // iPhone sends a second ICS which we ignore if we can
                    if (!isset($mapiprops[PR_MESSAGE_CLASS]) && strlen(trim($body)) == 0) {
                        writeLog(LOGLEVEL_WARN, "Secondary iPhone response is being ignored!! Mail dropped!");
                        return true;
                    }

                    if (!checkMapiExtVersion("6.30") && is_array($mapiprops) && !empty($mapiprops)) {
                        mapi_setprops($mapimessage, $mapiprops);
                    }
                    else {
                        // store ics as attachment
                        //see Utils::IcalTimezoneFix() in utils.php for more information
                        $part->body = Utils::IcalTimezoneFix($part->body);
                        $this->_storeAttachment($mapimessage, $part);
                        writeLog(LOGLEVEL_INFO, "Sending ICS file as attachment");
                    }
                }
                // any other type, store as attachment
                else
                    $this->_storeAttachment($mapimessage, $part);
            }
        } else {
            $body = u2wi($message->body);
        }

        // some devices only transmit a html body
        if (strlen($body) == 0 && strlen($body_html)>0) {
            writeLog(LOGLEVEL_INFO, "only html body sent, transformed into plain text");
            $body = strip_tags($body_html);
        }

        if($forward)
            $orig = $forward;
        if($reply)
            $orig = $reply;

        if(isset($orig) && $orig) {
            // Append the original text body for reply/forward
            $entryid = mapi_msgstore_entryidfromsourcekey($this->_defaultstore, hex2bin($parent), hex2bin($orig));
            $fwmessage = mapi_msgstore_openentry($this->_defaultstore, $entryid);

            if($fwmessage) {
                //update icon when forwarding or replying message
                if ($forward) mapi_setprops($fwmessage, array(PR_ICON_INDEX=>262));
                elseif ($reply) mapi_setprops($fwmessage, array(PR_ICON_INDEX=>261));
                mapi_savechanges($fwmessage);

                $stream = mapi_openproperty($fwmessage, PR_BODY, IID_IStream, 0, 0);
                $fwbody = "";

                while(1) {
                    $data = mapi_stream_read($stream, 1024);
                    if(strlen($data) == 0)
                        break;
                    $fwbody .= $data;
                }

                $stream = mapi_openproperty($fwmessage, PR_HTML, IID_IStream, 0, 0);
                $fwbody_html = "";

                while(1) {
                    $data = mapi_stream_read($stream, 1024);
                    if(strlen($data) == 0)
                        break;
                    $fwbody_html .= $data;
                }

                if($forward) {
                    // During a forward, we have to add the forward header ourselves. This is because
                    // normally the forwarded message is added as an attachment. However, we don't want this
                    // because it would be rather complicated to copy over the entire original message due
                    // to the lack of IMessage::CopyTo ..

                    $fwmessageprops = mapi_getprops($fwmessage, array(PR_SENT_REPRESENTING_NAME, PR_DISPLAY_TO, PR_DISPLAY_CC, PR_SUBJECT, PR_CLIENT_SUBMIT_TIME));

                    $fwheader = "\r\n\r\n";
                    $fwheader .= "-----Original Message-----\r\n";
                    if(isset($fwmessageprops[PR_SENT_REPRESENTING_NAME]))
                        $fwheader .= "From: " . $fwmessageprops[PR_SENT_REPRESENTING_NAME] . "\r\n";
                    if(isset($fwmessageprops[PR_DISPLAY_TO]) && strlen($fwmessageprops[PR_DISPLAY_TO]) > 0)
                        $fwheader .= "To: " . $fwmessageprops[PR_DISPLAY_TO] . "\r\n";
                    if(isset($fwmessageprops[PR_DISPLAY_CC]) && strlen($fwmessageprops[PR_DISPLAY_CC]) > 0)
                        $fwheader .= "Cc: " . $fwmessageprops[PR_DISPLAY_CC] . "\r\n";
                    if(isset($fwmessageprops[PR_CLIENT_SUBMIT_TIME]))
                        $fwheader .= "Sent: " . strftime("%x %X", $fwmessageprops[PR_CLIENT_SUBMIT_TIME]) . "\r\n";
                    if(isset($fwmessageprops[PR_SUBJECT]))
                        $fwheader .= "Subject: " . $fwmessageprops[PR_SUBJECT] . "\r\n";
                    $fwheader .= "\r\n";


                    // add fwheader to body and body_html
                    $body .= $fwheader;
                    if (strlen($body_html) > 0)
                        $body_html .= str_ireplace("\r\n", "<br>", $fwheader);
                }

                if(strlen($body) > 0)
                    $body .= $fwbody;

                if (strlen($body_html) > 0)
                      $body_html .= $fwbody_html;

            }
            else {
                // TODO: this should throw a hard error (status code?). This message can NEVER be forwarded
                writeLog(LOGLEVEL_WARN, "Unable to open item with id $orig for forward/reply");
            }
        }

        if($forward) {
            // Add attachments from the original message in a forward
            $entryid = mapi_msgstore_entryidfromsourcekey($this->_defaultstore, hex2bin($parent), hex2bin($orig));
            $fwmessage = mapi_msgstore_openentry($this->_defaultstore, $entryid);

            $attachtable = mapi_message_getattachmenttable($fwmessage);
            $rows = mapi_table_queryallrows($attachtable, array(PR_ATTACH_NUM));

            foreach($rows as $row) {
                if(isset($row[PR_ATTACH_NUM])) {
                    $attach = mapi_message_openattach($fwmessage, $row[PR_ATTACH_NUM]);

                    $newattach = mapi_message_createattach($mapimessage);

                    // Copy all attachments from old to new attachment
                    $attachprops = mapi_getprops($attach);
                    mapi_setprops($newattach, $attachprops);

                    if(isset($attachprops[mapi_prop_tag(PT_ERROR, mapi_prop_id(PR_ATTACH_DATA_BIN))])) {
                        // Data is in a stream
                        $srcstream = mapi_openpropertytostream($attach, PR_ATTACH_DATA_BIN);
                        $dststream = mapi_openpropertytostream($newattach, PR_ATTACH_DATA_BIN, MAPI_MODIFY | MAPI_CREATE);

                        while(1) {
                            $data = mapi_stream_read($srcstream, 4096);
                            if(strlen($data) == 0)
                                break;

                            mapi_stream_write($dststream, $data);
                        }

                        mapi_stream_commit($dststream);
                    }
                    mapi_savechanges($newattach);
                }
            }
        }

        //set PR_INTERNET_CPID to 65001 (utf-8) if store supports it and to 1252 otherwise
        $internetcpid = 1252;
        if (defined('STORE_SUPPORTS_UNICODE') && STORE_SUPPORTS_UNICODE == true) {
            $internetcpid = 65001;
        }

        mapi_setprops($mapimessage, array(PR_BODY => $body, PR_INTERNET_CPID => $internetcpid));

        if(strlen($body_html) > 0){
            mapi_setprops($mapimessage, array(PR_HTML => $body_html));
        }
        mapi_savechanges($mapimessage);
        mapi_message_submitmessage($mapimessage);

        return true;
    }

    /**
     * Returns all available data of a single message
     *
     * @param string        $folderid
     * @param string        $id
     * @param string        $mimesupport flag
     *
     * @access public
     * @return object(SyncObject)
     */
    public function Fetch($folderid, $id, $mimesupport = 0) {
        $foldersourcekey = hex2bin($folderid);
        $messagesourcekey = hex2bin($id);

        $dummy = false;

        // Fake a contents importer because it can do the conversion for us
        $importer = new PHPContentsWrapper($this->_session, $this->_defaultstore, $foldersourcekey, $dummy, SYNC_TRUNCATION_ALL);

        $entryid = mapi_msgstore_entryidfromsourcekey($this->_defaultstore, $foldersourcekey, $messagesourcekey);
        if(!$entryid) {
            // TODO: this should trigger a folder resync (status)
            writeLog(LOGLEVEL_WARN, "Unknown ID passed to Fetch");
            return false;
        }

        $message = mapi_msgstore_openentry($this->_defaultstore, $entryid);
        if(!$message) {
            // TODO: this should trigger a folder resync (status)
            writeLog(LOGLEVEL_WARN, "Unable to open message for Fetch command");
            return false;
        }

        return $importer->_getMessage($message, 1024*1024, $mimesupport); // Get 1MB of body size
    }

    /**
     * Returns the waste basket
     *
     * @access public
     * @return string
     */
    public function GetWasteBasket() {
        // TODO: implement GetWasteBasket() for deletion operations on WM
        return false;
    }

    /**
     * Returns the content of the named attachment
     * data is written directly (with print $data;)
     *
     * @param string        $attname
     * @access public
     * @return boolean
     */
    public function GetAttachmentData($attname) {
        list($folderid, $id, $attachnum) = explode(":", $attname);

        if(!isset($id) || !isset($attachnum))
            return false;

        $sourcekey = hex2bin($id);
        $foldersourcekey = hex2bin($folderid);

        // TODO: errors must trigger status codes
        $entryid = mapi_msgstore_entryidfromsourcekey($this->_defaultstore, $foldersourcekey, $sourcekey);
        if(!$entryid) {
            writeLog(LOGLEVEL_WARN, "Attachment requested for non-existing item $attname");
            return false;
        }

        $message = mapi_msgstore_openentry($this->_defaultstore, $entryid);
        if(!$message) {
            writeLog(LOGLEVEL_WARN, "Unable to open item for attachment data for " . bin2hex($entryid));
            return false;
        }

        $attach = mapi_message_openattach($message, $attachnum);
        if(!$attach) {
            writeLog(LOGLEVEL_WARN, "Unable to open attachment number $attachnum");
            return false;
        }

        $stream = mapi_openpropertytostream($attach, PR_ATTACH_DATA_BIN);
        if(!$stream) {
            writeLog(LOGLEVEL_WARN, "Unable to open attachment data stream");
            return false;
        }

        while(1) {
            $data = mapi_stream_read($stream, 4096);
            if(strlen($data) == 0)
                break;
            print $data;
        }

        return true;
    }

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
    public function MeetingResponse($requestid, $folderid, $response, &$calendarid) {
        // Use standard meeting response code to process meeting request
        $reqentryid = mapi_msgstore_entryidfromsourcekey($this->_defaultstore, hex2bin($folderid), hex2bin($requestid));
        $mapimessage = mapi_msgstore_openentry($this->_defaultstore, $reqentryid);

        // TODO: trigger status codes
        if(!$mapimessage) {
            writeLog(LOGLEVEL_WARN, "Unable to open request message for response");
            return false;
        }

        $meetingrequest = new Meetingrequest($this->_defaultstore, $mapimessage);

        if(!$meetingrequest->isMeetingRequest()) {
            writeLog(LOGLEVEL_WARN, "Attempt to respond to non-meeting request");
            return false;
        }

        if($meetingrequest->isLocalOrganiser()) {
            writeLog(LOGLEVEL_WARN, "Attempt to response to meeting request that we organized");
            return false;
        }

        // Process the meeting response. We don't have to send the actual meeting response
        // e-mail, because the device will send it itself.
        switch($response) {
            case 1:     // accept
            default:
                $entryid = $meetingrequest->doAccept(false, false, false, false, false, false, true); // last true is the $userAction
                break;
            case 2:        // tentative
                $entryid = $meetingrequest->doAccept(true, false, false, false, false, false, true); // last true is the $userAction
                break;
            case 3:        // decline
                $meetingrequest->doDecline(false);
                break;
        }

        // F/B will be updated on logoff

        // We have to return the ID of the new calendar item, so do that here
        if (isset($entryid)) {
            $newitem = mapi_msgstore_openentry($this->_defaultstore, $entryid);
            $newprops = mapi_getprops($newitem, array(PR_SOURCE_KEY));
            $calendarid = bin2hex($newprops[PR_SOURCE_KEY]);
        }

        // on recurring items, the MeetingRequest class responds with a wrong entryid
        if ($requestid == $calendarid) {
            writeLog(LOGLEVEL_DEBUG, "returned calender id is the same as the requestid - re-searching");
            $goidprop = GetPropIDFromString($this->_defaultstore, "PT_BINARY:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0x3");

            $messageprops = mapi_getprops($mapimessage, Array($goidprop, PR_OWNER_APPT_ID));
                $goid = $messageprops[$goidprop];
                if(isset($messageprops[PR_OWNER_APPT_ID]))
                    $apptid = $messageprops[PR_OWNER_APPT_ID];
                else
                    $apptid = false;

                $items = $meetingrequest->findCalendarItems($goid, $apptid);

                if (is_array($items)) {
                   $newitem = mapi_msgstore_openentry($this->_defaultstore, $items[0]);
                   $newprops = mapi_getprops($newitem, array(PR_SOURCE_KEY));
                   $calendarid = bin2hex($newprops[PR_SOURCE_KEY]);
                   writeLog(LOGLEVEL_DEBUG, "found other calendar entryid");
                }
        }


        // delete meeting request from Inbox
        $folderentryid = mapi_msgstore_entryidfromsourcekey($this->_defaultstore, hex2bin($folderid));
        $folder = mapi_msgstore_openentry($this->_defaultstore, $folderentryid);
        mapi_folder_deletemessages($folder, array($reqentryid), 0);

        return true;
    }

    /**
     * ZarafaBackend uses ICS to do change detection
     *
     * @access public
     * @return boolean
     */
    public function AlterPing() {
        return false;
    }

    /**
     * ZarafaBackend has own machanism
     *
     * @param string        $folderid       id of the folder
     * @param string        &$syncstate     reference of the syncstate
     *
     * @access public
     * @return boolean
     */
    public function AlterPingChanges($folderid, &$syncstate) {
        return array();
    }


    /**----------------------------------------------------------------------------------------------------------
     * Implementation of the ISearchProvider interface
     */

    /**
     * Indicates if a search type is supported by this SearchProvider
     * Currently only the type "GAL" (Global Address List) is implemented
     *
     * @param string        $searchtype
     *
     * @access public
     * @return boolean
     */
    public function SupportsType($searchtype) {
        return ($searchtype == "GAL");
    }

    /**
     * Searches the GAB of Zarafa
     * Can be overwitten globally by configuring a SearchBackend
     *
     * @param string        $searchquery
     * @param string        $searchrange
     *
     * @access public
     * @return array
     */
    public function GetGALSearchResults($searchquery, $searchrange){
        // only return users from who the displayName or the username starts with $name
        //TODO: use PR_ANR for this restriction instead of PR_DISPLAY_NAME and PR_ACCOUNT
        $addrbook = mapi_openaddressbook($this->_session);
        $ab_entryid = mapi_ab_getdefaultdir($addrbook);
        $ab_dir = mapi_ab_openentry($addrbook, $ab_entryid);

        $table = mapi_folder_getcontentstable($ab_dir);
        $restriction = $this->_getSearchRestriction(u2w($searchquery));
        mapi_table_restrict($table, $restriction);
        mapi_table_sort($table, array(PR_DISPLAY_NAME => TABLE_SORT_ASCEND));

        //range for the search results, default symbian range end is 50, wm 99,
        //so we'll use that of nokia
        $rangestart = 0;
        $rangeend = 50;

        if ($searchrange != '0') {
            $pos = strpos($searchrange, '-');
            $rangestart = substr($searchrange, 0, $pos);
            $rangeend = substr($searchrange, ($pos + 1));
        }
        $items = array();

        $querycnt = mapi_table_getrowcount($table);
        //do not return more results as requested in range
        $querylimit = (($rangeend + 1) < $querycnt) ? ($rangeend + 1) : $querycnt;
        $items['range'] = $rangestart.'-'.($querylimit - 1);
        $items['searchtotal'] = $querycnt;

        if ($querycnt > 0)
            $abentries = mapi_table_queryrows($table, array(PR_ACCOUNT, PR_DISPLAY_NAME, PR_SMTP_ADDRESS, PR_BUSINESS_TELEPHONE_NUMBER, PR_GIVEN_NAME, PR_SURNAME, PR_MOBILE_TELEPHONE_NUMBER, PR_HOME_TELEPHONE_NUMBER), $rangestart, $querylimit);

        for ($i = 0; $i < $querylimit; $i++) {
            $items[$i][SYNC_GAL_DISPLAYNAME] = w2u($abentries[$i][PR_DISPLAY_NAME]);

            if (strlen(trim($items[$i][SYNC_GAL_DISPLAYNAME])) == 0)
                $items[$i][SYNC_GAL_DISPLAYNAME] = w2u($abentries[$i][PR_ACCOUNT]);

            $items[$i][SYNC_GAL_ALIAS] = $items[$i][SYNC_GAL_DISPLAYNAME];
            //it's not possible not get first and last name of an user
            //from the gab and user functions, so we just set lastname
            //to displayname and leave firstname unset
            //this was changed in Zarafa 6.40, so we try to get first and
            //last name and fall back to the old behaviour if these values are not set
            if (isset($abentries[$i][PR_GIVEN_NAME]))
                $items[$i][SYNC_GAL_FIRSTNAME] = w2u($abentries[$i][PR_GIVEN_NAME]);
            if (isset($abentries[$i][PR_SURNAME]))
                $items[$i][SYNC_GAL_LASTNAME] = w2u($abentries[$i][PR_SURNAME]);

            if (!isset($items[$i][SYNC_GAL_LASTNAME])) $items[$i][SYNC_GAL_LASTNAME] = $items[$i][SYNC_GAL_DISPLAYNAME];

            $items[$i][SYNC_GAL_EMAILADDRESS] = w2u($abentries[$i][PR_SMTP_ADDRESS]);
            //check if an user has an office number or it might produce warnings in the log
            if (isset($abentries[$i][PR_BUSINESS_TELEPHONE_NUMBER]))
                $items[$i][SYNC_GAL_OFFICE] = w2u($abentries[$i][PR_BUSINESS_TELEPHONE_NUMBER]);
            //check if an user has a mobile number or it might produce warnings in the log
            if (isset($abentries[$i][PR_MOBILE_TELEPHONE_NUMBER]))
                $items[$i][SYNC_GAL_MOBILEPHONE] = w2u($abentries[$i][PR_MOBILE_TELEPHONE_NUMBER]);
            //check if an user has a home number or it might produce warnings in the log
            if (isset($abentries[$i][PR_HOME_TELEPHONE_NUMBER]))
                $items[$i][SYNC_GAL_HOMEPHONE] = w2u($abentries[$i][PR_HOME_TELEPHONE_NUMBER]);

            if (isset($abentries[$i][PR_ACCOUNT]))
                $items[$i][SYNC_GAL_COMPANY] = w2u($abentries[$i][PR_ACCOUNT]);
        }
        return $items;
    }


    /**
     * Disconnects from the current search provider
     *
     * @access public
     * @return boolean
     */
    public function Disconnect() {
        return true;
    }

    /**----------------------------------------------------------------------------------------------------------
     * Getter
     */

    /**
     * Getter for session
     *
     * @access public
     * @return MAPISession
     */
    public function _getSession() {
        return $this->_session;
    }

    /**
     * Getter for Defaultstore
     *
     * @access public
     * @return MAPISession
     */
    public function _getDefaultstore() {
        return $this->_openDefaultMessageStore($this->_session);
    }

    /**----------------------------------------------------------------------------------------------------------
     * Private methods
     */

    // TODO: in generall all necessary stores might be opened (public, shared folders etc.)
    // Open the store marked with PR_DEFAULT_STORE = TRUE
    protected function _openDefaultMessageStore($session)
    {
        // Find the default store
        $storestables = mapi_getmsgstorestable($session);
        $result = mapi_last_hresult();
        $entryid = false;

        if ($result == NOERROR){
            $rows = mapi_table_queryallrows($storestables, array(PR_ENTRYID, PR_DEFAULT_STORE, PR_MDB_PROVIDER));

            foreach($rows as $row) {
                if(isset($row[PR_DEFAULT_STORE]) && $row[PR_DEFAULT_STORE] == true) {
                    $entryid = $row[PR_ENTRYID];
                    break;
                }
            }
        }

        if($entryid) {
            return mapi_openmsgstore($session, $entryid);
        } else {
            return false;
        }
    }

    // Adds all folders in $mapifolder to $list, recursively
    protected function _getFoldersRecursive($mapifolder, $parent, &$list) {
        $hierarchytable = mapi_folder_gethierarchytable($mapifolder);
        $folderprops = mapi_getprops($mapifolder, array(PR_ENTRYID));
        if(!$hierarchytable)
            return false;

        $rows = mapi_table_queryallrows($hierarchytable, array(PR_DISPLAY_NAME, PR_SUBFOLDERS, PR_ENTRYID));

        foreach($rows as $row) {
            $folder = array();
            $folder["mod"] = $row[PR_DISPLAY_NAME];
            $folder["id"] = bin2hex($row[PR_ENTRYID]);
            $folder["parent"] = $parent;

            array_push($list, $folder);

            if(isset($row[PR_SUBFOLDERS]) && $row[PR_SUBFOLDERS]) {
                $this->_getFoldersRecursive(mapi_msgstore_openentry($this->_defaultstore, $row[PR_ENTRYID]), $folderprops[PR_ENTRYID], $list);
            }
        }

        return true;
    }

    // TODO: _storeAttachment() should go into MAPIutils
    // gets attachment from a parsed email and stores it to MAPI
    protected function _storeAttachment($mapimessage, $part) {
        // attachment
        $attach = mapi_message_createattach($mapimessage);

        $filename = "";
        // Filename is present in both Content-Type: name=.. and in Content-Disposition: filename=
        if(isset($part->ctype_parameters["name"]))
            $filename = $part->ctype_parameters["name"];
        else if(isset($part->d_parameters["name"]))
            $filename = $part->d_parameters["filename"];
        else if (isset($part->d_parameters["filename"])) // sending appointment with nokia & android only filename is set
            $filename = $part->d_parameters["filename"];
        // filenames with more than 63 chars as splitted several strings
        else if (isset($part->d_parameters["filename*0"])) {
            for ($i=0; $i< count($part->d_parameters); $i++)
               if (isset($part->d_parameters["filename*".$i]))
                   $filename .= $part->d_parameters["filename*".$i];
        }
        else
            $filename = "untitled";

        // Android just doesn't send content-type, so mimeDecode doesn't performs base64 decoding
        // on meeting requests text/calendar somewhere inside content-transfer-encoding
        if (isset($part->headers['content-transfer-encoding']) && strpos($part->headers['content-transfer-encoding'], 'base64')) {
            if (strpos($part->headers['content-transfer-encoding'], 'text/calendar') !== false) {
                $part->ctype_primary = 'text';
                $part->ctype_secondary = 'calendar';
            }
            if (!isset($part->headers['content-type']))
                $part->body = base64_decode($part->body);
        }

        // Set filename and attachment type
        mapi_setprops($attach, array(PR_ATTACH_LONG_FILENAME => u2wi($filename), PR_ATTACH_METHOD => ATTACH_BY_VALUE));

        // Set attachment data
        mapi_setprops($attach, array(PR_ATTACH_DATA_BIN => $part->body));

        // Set MIME type
        mapi_setprops($attach, array(PR_ATTACH_MIME_TAG => $part->ctype_primary . "/" . $part->ctype_secondary));

        mapi_savechanges($attach);
    }

    // TODO: handleRecurringItem() should go into MAPIutils
    //handles recurring item for meeting request
    protected function _handleRecurringItem(&$mapimessage, &$mapiprops) {
        $props = array();
        //set isRecurring flag to true
        $props[0] = "PT_BOOLEAN:{00062002-0000-0000-C000-000000000046}:0x8223";
        // Set named prop 8510, unknown property, but enables deleting a single occurrence of a recurring type in OLK2003.
        $props[1] = "PT_LONG:{00062008-0000-0000-C000-000000000046}:0x8510";
        //goid and goid2 from tnef
        $props[2] = "PT_BINARY:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0x3";
        $props[3] = "PT_BINARY:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0x23";
        $props[4] = "PT_STRING8:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0x24"; //type
        $props[5] = "PT_LONG:{00062002-0000-0000-C000-000000000046}:0x8205"; //busystatus
        $props[6] = "PT_LONG:{00062002-0000-0000-C000-000000000046}:0x8217"; //meeting status
        $props[7] = "PT_LONG:{00062002-0000-0000-C000-000000000046}:0x8218"; //response status
        $props[8] = "PT_BOOLEAN:{00062008-0000-0000-C000-000000000046}:0x8582";
        $props[9] = "PT_BOOLEAN:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0xa"; //is exception

        $props[10] = "PT_I2:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0x11"; //day interval
        $props[11] = "PT_I2:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0x12"; //week interval
        $props[12] = "PT_I2:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0x13"; //month interval
        $props[13] = "PT_I2:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0x14"; //year interval

        $props = getPropIdsFromStrings($this->_defaultstore, $props);

        $mapiprops[$props[0]] = true;
        $mapiprops[$props[1]] = 369;
        //both goids have the same value
        $mapiprops[$props[3]] = $mapiprops[$props[2]];
        $mapiprops[$props[4]] = "IPM.Appointment";
        $mapiprops[$props[5]] = 1; //tentative
        $mapiprops[PR_RESPONSE_REQUESTED] = true;
        $mapiprops[PR_ICON_INDEX] = 1027;
        $mapiprops[$props[6]] = olMeetingReceived; // The recipient is receiving the request
        $mapiprops[$props[7]] = olResponseNotResponded;
        $mapiprops[$props[8]] = true;
    }

    protected function _getSearchRestriction($query) {
        return array(RES_AND,
                    array(
                        array(RES_OR,
                            array(
                                array(RES_CONTENT, array(FUZZYLEVEL => FL_SUBSTRING | FL_IGNORECASE, ULPROPTAG => PR_DISPLAY_NAME, VALUE => $query)),
                                array(RES_CONTENT, array(FUZZYLEVEL => FL_SUBSTRING | FL_IGNORECASE, ULPROPTAG => PR_ACCOUNT, VALUE => $query)),
                            ), // RES_OR
                        ),
                        array(
                            RES_PROPERTY,
                            array(RELOP => RELOP_EQ, ULPROPTAG => PR_OBJECT_TYPE, VALUE => MAPI_MAILUSER)
                        )
                    ) // RES_AND
        );
    }


    protected function _isUnicodeStore() {
        $supportmask = mapi_getprops($this->_defaultstore, array(PR_STORE_SUPPORT_MASK));
        if (isset($supportmask[PR_STORE_SUPPORT_MASK]) && ($supportmask[PR_STORE_SUPPORT_MASK] & STORE_UNICODE_OK)) {
            writeLog(LOGLEVEL_DEBUG, "Store supports properties containing Unicode characters.");
            define('STORE_SUPPORTS_UNICODE', true);
            //setlocale to UTF-8 in order to support properties containing Unicode characters
            setlocale(LC_CTYPE, "en_US.UTF-8");
        }
    }
}

/**
 * DEPRECATED legacy class
 */
class BackendICS extends BackendZarafa {}

?>