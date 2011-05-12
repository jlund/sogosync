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
if (Utils::CheckMapiExtVersion('6.40.4')) {
    include_once('mapi/class.baserecurrence.php');
    include_once('mapi/class.taskrecurrence.php');
}
include_once('mapi/class.recurrence.php');
include_once('mapi/class.meetingrequest.php');
include_once('mapi/class.freebusypublish.php');

// processing of RFC822 messages
include_once('include/mimeDecode.php');
require_once('include/z_RFC822.php');

// components of Zarafa backend
include_once('mapiutils.php');
include_once('mapimapping.php');
include_once('mapiprovider.php');
include_once('mapiphpwrapper.php');
include_once('importer.php');
include_once('exporter.php');
// TODO use own mapi include and recurrence classes files
// TODO use this define in the own file
if (!defined("PSETID_AirSync")) define ("PSETID_AirSync", makeguid("{71035549-0739-4DCB-9163-00F0580DBBDF}"));


class BackendZarafa implements IBackend, ISearchProvider {
    private $mainUser;
    private $session;
    private $defaultstore;
    private $store;
    private $storeName;
    private $storeCache;
    private $importedFolders;

    /**
     * Constructor of the Zarafa Backend
     *
     * @access public
     */
    public function BackendZarafa() {
        $this->session = false;
        $this->store = false;
        $this->storeName = false;
        $this->storeCache = array();
        $this->importedFolders = array();
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
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZarafaBackend->Logon(): Trying to authenticate user '%s'..", $user));
        $this->mainUser = $user;

        try {
            $this->session = @mapi_logon_zarafa($user, $pass, MAPI_SERVER);
        }
        catch (Exception $ex) {
            throw new AuthenticationRequiredException($ex->getMessage(), AUTHENTICATION_FAILED);
        }

        if($this->session === false) {
            ZLog::Write(LOGLEVEL_WARN, "logon failed for user $user");
            $this->defaultstore = false;
            return false;
        }

        // Get/open default store
        $this->defaultstore = $this->openMessageStore($user);

        if($this->defaultstore === false) {
            // TODO set HTTP status code if available
            ZLog::Write(LOGLEVEL_ERROR, sprintf("ZarafaBackend->Logon(): User '%s' has no default store", $user));
            return false;
        }
        else {
            $this->store = $this->defaultstore;
            $this->storeName = $user;
        }

        ZLog::Write(LOGLEVEL_INFO, sprintf("ZarafaBackend->Logon(): User '%s' is authenticated",$user));

        // check if this is a Zarafa 7 store with unicode support
        MAPIUtils::IsUnicodeStore($this->store);
        return true;
    }

    /**
     * Setup the backend to work on a specific store or checks ACLs there.
     * If only the $store is submitted, all Import/Export/Fetch/Etc operations should be
     * performed on this store (switch operations store).
     * If the ACL check is enabled, this operation should just indicate the ACL status on
     * the submitted store, without changing the store for operations.
     * For the ACL status, the currently logged on user MUST have access rights on
     *  - the entire store - admin access if no folderid is sent, or
     *  - on a specific folderid in the store (secretary/full access rights)
     *
     * The ACLcheck MUST fail if a folder of the authenticated user is checked!
     *
     * @param string        $store              target store, could contain a "domain\user" value
     * @param boolean       $checkACLonly       if set to true, Setup() should just check ACLs
     * @param string        $folderid           if set, only ACLs on this folderid are relevant
     *
     * @access public
     * @return boolean
     */
    public function Setup($store, $checkACLonly = false, $folderid = false) {
        list($user, $domain) = Utils::SplitDomainUser($store);

        if (!isset($this->mainUser))
            return false;

        if ($user === false)
            $user = $this->mainUser;

        // This is a special case. A user will get it's entire folder structure by the foldersync by default.
        // The ACL check is executed when an additional folder is going to be sent to the mobile.
        // Configured that way the user could receive the same folderid twice, with two different names.
        if ($this->mainUser == $user && $checkACLonly && $folderid) {
            ZLog::Write(LOGLEVEL_DEBUG, "ZarafaBackend->Setup(): Checking ACLs for folder of the users defaultstore. Fail is forced to avoid folder duplications on mobile.");
            return false;
        }

        // get the users store
        $userstore = $this->openMessageStore($user);

        // only proceed if a store was found, else return false
        if ($userstore) {
            // only check permissions
            if ($checkACLonly == true) {
                // check for admin rights
                if (!$folderid) {
                    if ($user != $this->mainUser) {
                        $zarafauserinfo = @mapi_zarafa_getuser_by_name($this->defaultstore, $this->mainUser);
                        $admin = (isset($zarafauserinfo['admin']) && $zarafauserinfo['admin'])?true:false;
                    }
                    // the user has always full access to his own store
                    else
                        $admin = true;

                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZarafaBackend->Setup(): Checking for admin ACLs on store '%s': '%s'", $user, Utils::PrintAsString($admin)));
                    return $admin;
                }
                // check 'secretary' permissions on this folder
                else {
                    $rights = $this->hasSecretaryACLs($userstore, $folderid);
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZarafaBackend->Setup(): Checking for secretary ACLs on '%s' of store '%s': '%s'", $folderid, $user, Utils::PrintAsString($rights)));
                    return $rights;
                }
            }

            // switch operations store
            // this should also be done if called with user = mainuser or user = false
            // which means to switch back to the default store
            else {
                // switch active store
                $this->store = $userstore;
                $this->storeName = $user;
                return true;
            }
        }
        return false;
    }

    /**
     * Logs off
     * Free/Busy information is updated for modified calendars
     * This is done after the synchronization process is completed
     *
     * @access public
     * @return boolean
     */
    public function Logoff() {
        // update if the calendar which received incoming changes
        foreach($this->importedFolders as $folderid => $store) {
            // open the root of the store
            $storeprops = mapi_getprops($store, array(PR_USER_ENTRYID));
            $root = mapi_msgstore_openentry($store);
            if (!$root)
                continue;

            // get the entryid of the main calendar of the store and the calendar to be published
            $rootprops = mapi_getprops($root, array(PR_IPM_APPOINTMENT_ENTRYID));
            $entryid = mapi_msgstore_entryidfromsourcekey($store, hex2bin($folderid));

            // only publish free busy for the main calendar
            if(isset($rootprops[PR_IPM_APPOINTMENT_ENTRYID]) && $rootprops[PR_IPM_APPOINTMENT_ENTRYID] == $entryid) {
                ZLog::Write(LOGLEVEL_INFO, sprintf("ZarafaBackend->Logoff(): Updating freebusy information on folder id '%s'", $folderid));
                $calendar = mapi_msgstore_openentry($store, $entryid);

                $pub = new FreeBusyPublish($this->session, $store, $calendar, $storeprops[PR_USER_ENTRYID]);
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
        $mapiprovider = new MAPIProvider($this->session, $this->store);

        $rootfolder = mapi_msgstore_openentry($this->store);
        $rootfolderprops = mapi_getprops($rootfolder, array(PR_SOURCE_KEY));
        $rootfoldersourcekey = bin2hex($rootfolderprops[PR_SOURCE_KEY]);

        $hierarchy =  mapi_folder_gethierarchytable($rootfolder, CONVENIENT_DEPTH);
        $rows = mapi_table_queryallrows($hierarchy, array(PR_ENTRYID));

        foreach ($rows as $row) {
            $mapifolder = mapi_msgstore_openentry($this->store, $row[PR_ENTRYID]);
            $folder = $mapiprovider->GetFolder($mapifolder);

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
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendZarafa->GetImporter() folderid: '%s'", Utils::PrintAsString($folderid)));
        if($folderid !== false) {
            // check if the user of the current store has permissions to import to this folderid
            if ($this->storeName != $this->mainUser && !$this->hasSecretaryACLs($this->store, $folderid)) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendZarafa->GetImporter(): missing permissions on folderid: '%s'.", Utils::PrintAsString($folderid)));
                return false;
            }
            $this->importedFolders[$folderid] = $this->store;
            return new ImportChangesICS($this->session, $this->store, hex2bin($folderid));
        }
        else
            return new ImportChangesICS($this->session, $this->store);
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
        if($folderid !== false) {
            // check if the user of the current store has permissions to export from this folderid
            if ($this->storeName != $this->mainUser && !$this->hasSecretaryACLs($this->store, $folderid)) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendZarafa->GetExporter(): missing permissions on folderid: '%s'.", Utils::PrintAsString($folderid)));
                return false;
            }
            return new ExportChangesICS($this->session, $this->store, hex2bin($folderid));
        }
        else
            return new ExportChangesICS($this->session, $this->store);
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
            ZLog::Write(LOGLEVEL_WBXML, "SendMail: forward: $forward   reply: $reply   parent: $parent");
            foreach(preg_split("/((\r)?\n)/", $rfc822) as $rfc822line)
                ZLog::Write(LOGLEVEL_WBXML, "SendMail RFC822:". $rfc822line);
        }

        $mimeParams = array('decode_headers' => true,
                            'decode_bodies' => true,
                            'include_bodies' => true,
                            'charset' => 'utf-8');

        $mimeObject = new Mail_mimeDecode($rfc822);
        $message = $mimeObject->decode($mimeParams);

        // Open the outbox and create the message there
        $storeprops = mapi_getprops($this->store, array(PR_IPM_OUTBOX_ENTRYID, PR_IPM_SENTMAIL_ENTRYID));
        if(!isset($storeprops[PR_IPM_OUTBOX_ENTRYID])) {
            ZLog::Write(LOGLEVEL_ERROR, "Outbox not found to create message");
            return false;
        }

        $outbox = mapi_msgstore_openentry($this->store, $storeprops[PR_IPM_OUTBOX_ENTRYID]);
        if(!$outbox) {
            // TODO: this should throw a hard error, stop all further syncs and notify the administrator
            ZLog::Write(LOGLEVEL_ERROR, "Unable to open outbox");
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
                    if (!isset($tnefAndIcalProps)) {
                        $tnefAndIcalProps = MAPIMapping::GetTnefAndIcalProperties();
                        $tnefAndIcalProps = getPropIdsFromStrings($this->store, $tnefAndIcalProps);
                    }

                    require_once('tnefparser.php');
                    $zptnef = new TNEFParser($this->store, $tnefAndIcalProps);
                    $mapiprops = array();

                    $zptnef->ExtractProps($part->body, $mapiprops);
                    if (is_array($mapiprops) && !empty($mapiprops)) {
                        //check if it is a recurring item
                        if (isset($mapiprops[$tnefAndIcalProps["tnefrecurr"]])) {
                            MAPIUtils::handleRecurringItem($mapiprops, $tnefAndIcalProps);
                        }
                        mapi_setprops($mapimessage, $mapiprops);
                    }
                    else ZLog::Write(LOGLEVEL_WARN, "TNEFParser: Mapi property array was empty");
                }
                // iCalendar
                elseif($part->ctype_primary == "text" && $part->ctype_secondary == "calendar") {
                    if (!isset($tnefAndIcalProps)) {
                        $tnefAndIcalProps = MAPIMapping::GetTnefAndIcalProperties();
                        $tnefAndIcalProps = getPropIdsFromStrings($this->store, $tnefAndIcalProps);
                    }

                    require_once('icalparser.php');
                    $zpical = new ICalParser($this->store, $tnefAndIcalProps);
                    $mapiprops = array();
                    $zpical->ExtractProps($part->body, $mapiprops);

                    // iPhone sends a second ICS which we ignore if we can
                    if (!isset($mapiprops[PR_MESSAGE_CLASS]) && strlen(trim($body)) == 0) {
                        ZLog::Write(LOGLEVEL_WARN, "Secondary iPhone response is being ignored!! Mail dropped!");
                        return true;
                    }

                    if (! Utils::CheckMapiExtVersion("6.30") && is_array($mapiprops) && !empty($mapiprops)) {
                        mapi_setprops($mapimessage, $mapiprops);
                    }
                    else {
                        // store ics as attachment
                        //see Utils::IcalTimezoneFix() in utils.php for more information
                        $part->body = Utils::IcalTimezoneFix($part->body);
                        MAPIUtils::StoreAttachment($mapimessage, $part);
                        ZLog::Write(LOGLEVEL_INFO, "Sending ICS file as attachment");
                    }
                }
                // any other type, store as attachment
                else
                    MAPIUtils::StoreAttachment($mapimessage, $part);
            }
        } else {
            $body = u2wi($message->body);
        }

        // some devices only transmit a html body
        if (strlen($body) == 0 && strlen($body_html)>0) {
            ZLog::Write(LOGLEVEL_INFO, "only html body sent, transformed into plain text");
            $body = strip_tags($body_html);
        }

        if($forward)
            $orig = $forward;
        if($reply)
            $orig = $reply;

        if(isset($orig) && $orig) {
            // Append the original text body for reply/forward
            $entryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin($parent), hex2bin($orig));
            $fwmessage = mapi_msgstore_openentry($this->store, $entryid);

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
                ZLog::Write(LOGLEVEL_WARN, "Unable to open item with id $orig for forward/reply");
            }
        }

        if($forward) {
            // Add attachments from the original message in a forward
            $entryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin($parent), hex2bin($orig));
            $fwmessage = mapi_msgstore_openentry($this->store, $entryid);

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

        ZLog::Write(LOGLEVEL_DEBUG, "ZarafaBackend->SendMail(): email submitted");
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
        // get the entry id of the message
        $entryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin($folderid), hex2bin($id));
        if(!$entryid) {
            // TODO: this should trigger a folder resync (status)
            ZLog::Write(LOGLEVEL_WARN, "Unknown ID passed to Fetch");
            return false;
        }

        // open the message
        $message = mapi_msgstore_openentry($this->store, $entryid);
        if(!$message) {
            // TODO: this should trigger a folder resync (status)
            ZLog::Write(LOGLEVEL_WARN, "Unable to open message for Fetch command");
            return false;
        }

        // convert the mapi message into a SyncObject and return it
        $mapiprovider = new MAPIProvider($this->session, $this->store);
        return $mapiprovider->GetMessage($message, SYNC_TRUNCATION_ALL, $mimesupport);
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
        list($id, $attachnum) = explode(":", $attname);

        if(!isset($id) || !isset($attachnum)) {
            ZLog::Write(LOGLEVEL_WARN, "Attachment requested for non-existing item $attname");
            return false;
        }

        // TODO: errors must trigger status codes
        $entryid = hex2bin($id);
        $message = mapi_msgstore_openentry($this->store, $entryid);
        if(!$message) {
            ZLog::Write(LOGLEVEL_WARN, "Unable to open item for attachment data for $id");
            return false;
        }

        $attach = mapi_message_openattach($message, $attachnum);
        if(!$attach) {
            ZLog::Write(LOGLEVEL_WARN, "Unable to open attachment number $attachnum");
            return false;
        }

        $stream = mapi_openpropertytostream($attach, PR_ATTACH_DATA_BIN);
        if(!$stream) {
            ZLog::Write(LOGLEVEL_WARN, "Unable to open attachment data stream");
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
        $reqentryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin($folderid), hex2bin($requestid));
        $mapimessage = mapi_msgstore_openentry($this->store, $reqentryid);

        // TODO: trigger status codes
        if(!$mapimessage) {
            ZLog::Write(LOGLEVEL_WARN, "Unable to open request message for response");
            return false;
        }

        $meetingrequest = new Meetingrequest($this->store, $mapimessage);

        if(!$meetingrequest->isMeetingRequest()) {
            ZLog::Write(LOGLEVEL_WARN, "Attempt to respond to non-meeting request");
            return false;
        }

        if($meetingrequest->isLocalOrganiser()) {
            ZLog::Write(LOGLEVEL_WARN, "Attempt to response to meeting request that we organized");
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
            $newitem = mapi_msgstore_openentry($this->store, $entryid);
            $newprops = mapi_getprops($newitem, array(PR_SOURCE_KEY));
            $calendarid = bin2hex($newprops[PR_SOURCE_KEY]);
        }

        // on recurring items, the MeetingRequest class responds with a wrong entryid
        if ($requestid == $calendarid) {
            ZLog::Write(LOGLEVEL_DEBUG, "returned calender id is the same as the requestid - re-searching");
            $props = MAPIMapping::GetMeetingRequestProperties();
            $props = getPropIdsFromStrings($this->store, $props);

            $messageprops = mapi_getprops($mapimessage, Array($props["goidtag"], PR_OWNER_APPT_ID));
            $goid = $messageprops[$props["goidtag"]];
            if(isset($messageprops[PR_OWNER_APPT_ID]))
                $apptid = $messageprops[PR_OWNER_APPT_ID];
            else
                $apptid = false;

            $items = $meetingrequest->findCalendarItems($goid, $apptid);

            if (is_array($items)) {
               $newitem = mapi_msgstore_openentry($this->store, $items[0]);
               $newprops = mapi_getprops($newitem, array(PR_SOURCE_KEY));
               $calendarid = bin2hex($newprops[PR_SOURCE_KEY]);
               ZLog::Write(LOGLEVEL_DEBUG, "found other calendar entryid");
            }
        }


        // delete meeting request from Inbox
        $folderentryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin($folderid));
        $folder = mapi_msgstore_openentry($this->store, $folderentryid);
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
        $addrbook = mapi_openaddressbook($this->session);
        $ab_entryid = mapi_ab_getdefaultdir($addrbook);
        $ab_dir = mapi_ab_openentry($addrbook, $ab_entryid);

        $table = mapi_folder_getcontentstable($ab_dir);
        $restriction = MAPIUtils::GetSearchRestriction(u2w($searchquery));
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
     * Private methods
     */

    /**
     * Open the store marked with PR_DEFAULT_STORE = TRUE
     * if $return_public is set, the public store is opened
     *
     * @param string    $user               User which store should be opened
     *
     * @access public
     * @return boolean
     */
    private function openMessageStore($user) {
        // During PING requests the operations store has to be switched constantly
        // the cache prevents the same store opened several times
        if (isset($this->storeCache[$user]))
           return  $this->storeCache[$user];

        $entryid = false;
        $return_public = false;

        if (strtoupper($user) == 'SYSTEM')
            $return_public = true;

        // loop through the storestable if authenticated user of public folder
        if ($user == $this->mainUser || $return_public === true) {
            // Find the default store
            $storestables = mapi_getmsgstorestable($this->session);
            $result = mapi_last_hresult();

            if ($result == NOERROR){
                $rows = mapi_table_queryallrows($storestables, array(PR_ENTRYID, PR_DEFAULT_STORE, PR_MDB_PROVIDER));

                foreach($rows as $row) {
                    if(!$return_public && isset($row[PR_DEFAULT_STORE]) && $row[PR_DEFAULT_STORE] == true) {
                        $entryid = $row[PR_ENTRYID];
                        break;
                    }
                    if ($return_public && isset($row[PR_MDB_PROVIDER]) && $row[PR_MDB_PROVIDER] == ZARAFA_STORE_PUBLIC_GUID) {
                        $entryid = $row[PR_ENTRYID];
                        break;
                    }
                }
            }
        }
        else
            $entryid = @mapi_msgstore_createentryid($this->defaultstore, $user);

        if($entryid) {
            $store = @mapi_openmsgstore($this->session, $entryid);

            // add this store to the cache
            if (!isset($this->storeCache[$user]))
                $this->storeCache[$user] = $store;

            ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZarafaBackend->openMessageStore('%s'): Found '%s' store: '%s'", $user, (($return_public)?'PUBLIC':'DEFAULT'),$store));
            return $store;
        }
        else {
            ZLog::Write(LOGLEVEL_WARN, sprintf("ZarafaBackend->openMessageStore('%s'): No store found for this user", $user));
            return false;
        }
    }

    private function hasSecretaryACLs($store, $folderid) {
        $entryid = mapi_msgstore_entryidfromsourcekey($store, hex2bin($folderid));
        if (!$entryid)  return false;

        $folder = mapi_msgstore_openentry($store, $entryid);
        if (!$folder) return false;

        $props = mapi_getprops($folder, array(PR_RIGHTS));
        if (isset($props[PR_RIGHTS]) &&
            ($props[PR_RIGHTS] & ecRightsReadAny) &&
            ($props[PR_RIGHTS] & ecRightsCreate) &&
            ($props[PR_RIGHTS] & ecRightsEditOwned) &&
            ($props[PR_RIGHTS] & ecRightsDeleteOwned) &&
            ($props[PR_RIGHTS] & ecRightsEditAny) &&
            ($props[PR_RIGHTS] & ecRightsDeleteAny) &&
            ($props[PR_RIGHTS] & ecRightsFolderVisible) ) {
            return true;
        }
        return false;
    }

}

/**
 * DEPRECATED legacy class
 */
class BackendICS extends BackendZarafa {}

?>