<?php
/***********************************************
* File      :   imap.php
* Project   :   Z-Push
* Descr     :   This backend is based on
*               'BackendDiff' and implements an
*               IMAP interface
*
* Created   :   10.10.2007
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

include_once('diffbackend.php');
include_once('mimeDecode.php');
require_once('z_RFC822.php');


class BackendIMAP extends BackendDiff {
    private $wasteID;
    private $sentID;
    private $server;
    private $mbox;
    private $mboxFolder;
    private $username;
    private $domain;
    private $serverdelimiter;

    /**----------------------------------------------------------------------------------------------------------
     * default backend methods
     */

    /**
     * Authenticates the user
     *
     * @param string        $username
     * @param string        $domain
     * @param string        $password
     *
     * @access public
     * @return boolean
     */
    public function Logon($username, $domain, $password) {
        $this->wasteID = false;
        $this->sentID = false;
        $this->server = "{" . IMAP_SERVER . ":" . IMAP_PORT . "/imap" . IMAP_OPTIONS . "}";

        // TODO throw exception
        if (!function_exists("imap_open"))
            ZLog::Write(LOGLEVEL_FATAL, "ERROR BackendIMAP : php-imap module not installed!");

        // open the IMAP-mailbox
        $this->mbox = @imap_open($this->server , $username, $password, OP_HALFOPEN);
        $this->mboxFolder = "";

        if ($this->mbox) {
            ZLog::Write(LOGLEVEL_INFO, "IMAP connection opened sucessfully ");
            $this->username = $username;
            $this->domain = $domain;
            // set serverdelimiter
            $this->serverdelimiter = $this->getServerDelimiter();
            return true;
        }
        else {
            ZLog::Write(LOGLEVEL_ERROR, "IMAP can't connect: " . imap_last_error());
            return false;
        }
    }


    /**
     * Logs off
     * Called before shutting down the request to close the IMAP connection
     * writes errors to the log
     *
     * @access public
     * @return boolean
     */
    public function Logoff() {
        if ($this->mbox) {
            // list all errors
            $errors = imap_errors();
            if (is_array($errors)) {
                foreach ($errors as $e)
                    if (stripos($e, "fail") !== false)
                        ZLog::Write(LOGLEVEL_ERROR, "IMAP-errors: $e");
                    else
                        ZLog::Write(LOGLEVEL_WARN, "IMAP-errors: $e");
            }
            @imap_close($this->mbox);
            ZLog::Write(LOGLEVEL_DEBUG, "IMAP connection closed");
        }
    }

    /**
     * Sends an e-mail
     * Message is sent over imap_mail() or mail() depending on IMAP_USE_IMAPMAIL configuration
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
        ZLog::Write(LOGLEVEL_DEBUG, "IMAP-SendMail: for: $forward   reply: $reply   parent: $parent");
        ZLog::Write(LOGLEVEL_WBXML, "IMAP-SendMail RFC822:\n". $rfc822);

        $mobj = new Mail_mimeDecode($rfc822);
        $message = $mobj->decode(array('decode_headers' => false, 'decode_bodies' => true, 'include_bodies' => true, 'charset' => 'utf-8'));

        $Mail_RFC822 = new Mail_RFC822();
        $toaddr = $ccaddr = $bccaddr = "";
        if(isset($message->headers["to"]))
            $toaddr = $this->parseAddr($Mail_RFC822->parseAddressList($message->headers["to"]));
        if(isset($message->headers["cc"]))
            $ccaddr = $this->parseAddr($Mail_RFC822->parseAddressList($message->headers["cc"]));
        if(isset($message->headers["bcc"]))
            $bccaddr = $this->parseAddr($Mail_RFC822->parseAddressList($message->headers["bcc"]));

        // save some headers when forwarding mails (content type & transfer-encoding)
        $headers = "";
        $forward_h_ct = "";
        $forward_h_cte = "";
        $envelopefrom = "";

        $use_orgbody = false;

        // clean up the transmitted headers
        // remove default headers because we are using imap_mail
        $changedfrom = false;
        $returnPathSet = false;
        $body_base64 = false;
        $org_charset = "";
        $org_boundary = false;
        $multipartmixed = false;
        foreach($message->headers as $k => $v) {
            if ($k == "subject" || $k == "to" || $k == "cc" || $k == "bcc")
                continue;

            if ($k == "content-type") {
                // if the message is a multipart message, then we should use the sent body
                if (preg_match("/multipart/i", $v)) {
                    $use_orgbody = true;
                    $org_boundary = $message->ctype_parameters["boundary"];
                }

                // save the original content-type header for the body part when forwarding
                if ($forward && !$use_orgbody) {
                    $forward_h_ct = $v;
                    continue;
                }

                // set charset always to utf-8
                $org_charset = $v;
                $v = preg_replace("/charset=([A-Za-z0-9-\"']+)/", "charset=\"utf-8\"", $v);
            }

            if ($k == "content-transfer-encoding") {
                // if the content was base64 encoded, encode the body again when sending
                if (trim($v) == "base64") $body_base64 = true;

                // save the original encoding header for the body part when forwarding
                if ($forward) {
                    $forward_h_cte = $v;
                    continue;
                }
            }

            // check if "from"-header is set, do nothing if it's set
            // else set it to IMAP_DEFAULTFROM
            if ($k == "from") {
                if (trim($v)) {
                    $changedfrom = true;
                } elseif (! trim($v) && IMAP_DEFAULTFROM) {
                    $changedfrom = true;
                    if      (IMAP_DEFAULTFROM == 'username') $v = $this->username;
                    else if (IMAP_DEFAULTFROM == 'domain')   $v = $this->domain;
                    else $v = $this->username . IMAP_DEFAULTFROM;
                    $envelopefrom = "-f$v";
                }
            }

            // check if "Return-Path"-header is set
            if ($k == "return-path") {
                $returnPathSet = true;
                if (! trim($v) && IMAP_DEFAULTFROM) {
                    if      (IMAP_DEFAULTFROM == 'username') $v = $this->username;
                    else if (IMAP_DEFAULTFROM == 'domain')   $v = $this->domain;
                    else $v = $this->username . IMAP_DEFAULTFROM;
                }
            }

            // all other headers stay
            if ($headers) $headers .= "\n";
            $headers .= ucfirst($k) . ": ". $v;
        }

        // set "From" header if not set on the device
        if(IMAP_DEFAULTFROM && !$changedfrom){
            if      (IMAP_DEFAULTFROM == 'username') $v = $this->username;
            else if (IMAP_DEFAULTFROM == 'domain')   $v = $this->domain;
            else $v = $this->username . IMAP_DEFAULTFROM;
            if ($headers) $headers .= "\n";
            $headers .= 'From: '.$v;
            $envelopefrom = "-f$v";
        }

        // set "Return-Path" header if not set on the device
        if(IMAP_DEFAULTFROM && !$returnPathSet){
            if      (IMAP_DEFAULTFROM == 'username') $v = $this->username;
            else if (IMAP_DEFAULTFROM == 'domain')   $v = $this->domain;
            else $v = $this->username . IMAP_DEFAULTFROM;
            if ($headers) $headers .= "\n";
            $headers .= 'Return-Path: '.$v;
        }

        // if this is a multipart message with a boundary, we must use the original body
        if ($use_orgbody) {
            list(,$body) = $mobj->_splitBodyHeader($rfc822);
            $repl_body = $this->getBody($message);
        }
        else
            $body = $this->getBody($message);

        // reply
        if ($reply && $parent) {
            $this->imap_reopenFolder($parent);
            // receive entire mail (header + body) to decode body correctly
            $origmail = @imap_fetchheader($this->mbox, $reply, FT_UID) . @imap_body($this->mbox, $reply, FT_PEEK | FT_UID);
            $mobj2 = new Mail_mimeDecode($origmail);
            // receive only body
            $body .= $this->getBody($mobj2->decode(array('decode_headers' => false, 'decode_bodies' => true, 'include_bodies' => true, 'charset' => 'utf-8')));
            // unset mimedecoder & origmail - free memory
            unset($mobj2);
            unset($origmail);
        }

        // encode the body to base64 if it was sent originally in base64 by the pda
        // contrib - chunk base64 encoded body
        if ($body_base64 && !$forward) $body = chunk_split(base64_encode($body));


        // forward
        if ($forward && $parent) {
            $this->imap_reopenFolder($parent);
            // receive entire mail (header + body)
            $origmail = @imap_fetchheader($this->mbox, $forward, FT_UID) . @imap_body($this->mbox, $forward, FT_PEEK | FT_UID);

            if (!defined('IMAP_INLINE_FORWARD') || IMAP_INLINE_FORWARD === false) {
                // contrib - chunk base64 encoded body
                if ($body_base64) $body = chunk_split(base64_encode($body));
                //use original boundary if it's set
                $boundary = ($org_boundary) ? $org_boundary : false;
                // build a new mime message, forward entire old mail as file
                list($aheader, $body) = $this->mail_attach("forwarded_message.eml",strlen($origmail),$origmail, $body, $forward_h_ct, $forward_h_cte,$boundary);
                // add boundary headers
                $headers .= "\n" . $aheader;

            }
            else {
                $mobj2 = new Mail_mimeDecode($origmail);
                $mess2 = $mobj2->decode(array('decode_headers' => true, 'decode_bodies' => true, 'include_bodies' => true, 'charset' => 'utf-8'));

                if (!$use_orgbody)
                    $nbody = $body;
                else
                    $nbody = $repl_body;

                $nbody .= "\r\n\r\n";
                $nbody .= "-----Original Message-----\r\n";
                if(isset($mess2->headers['from']))
                    $nbody .= "From: " . $mess2->headers['from'] . "\r\n";
                if(isset($mess2->headers['to']) && strlen($mess2->headers['to']) > 0)
                    $nbody .= "To: " . $mess2->headers['to'] . "\r\n";
                if(isset($mess2->headers['cc']) && strlen($mess2->headers['cc']) > 0)
                    $nbody .= "Cc: " . $mess2->headers['cc'] . "\r\n";
                if(isset($mess2->headers['date']))
                    $nbody .= "Sent: " . $mess2->headers['date'] . "\r\n";
                if(isset($mess2->headers['subject']))
                    $nbody .= "Subject: " . $mess2->headers['subject'] . "\r\n";
                $nbody .= "\r\n";
                $nbody .= $this->getBody($mess2);

                if ($body_base64) {
                    // contrib - chunk base64 encoded body
                    $nbody = chunk_split(base64_encode($nbody));
                    if ($use_orgbody)
                    // contrib - chunk base64 encoded body
                        $repl_body = chunk_split(base64_encode($repl_body));
                }

                if ($use_orgbody) {
                    ZLog::Write(LOGLEVEL_DEBUG, "-------------------");
                    ZLog::Write(LOGLEVEL_DEBUG, "old:\n'$repl_body'\nnew:\n'$nbody'\nund der body:\n'$body'");
                    //$body is quoted-printable encoded while $repl_body and $nbody are plain text,
                    //so we need to decode $body in order replace to take place
                    $body = str_replace($repl_body, $nbody, quoted_printable_decode($body));
                }
                else
                    $body = $nbody;


                if(isset($mess2->parts)) {
                    $attached = false;

                    if ($org_boundary) {
                        $att_boundary = $org_boundary;
                        // cut end boundary from body
                        $body = substr($body, 0, strrpos($body, "--$att_boundary--"));
                    }
                    else {
                        $att_boundary = strtoupper(md5(uniqid(time())));
                        // add boundary headers
                        $headers .= "\n" . "Content-Type: multipart/mixed; boundary=$att_boundary";
                        $multipartmixed = true;
                    }

                    foreach($mess2->parts as $part) {
                        if(isset($part->disposition) && ($part->disposition == "attachment" || $part->disposition == "inline")) {

                            if(isset($part->d_parameters['filename']))
                                $attname = $part->d_parameters['filename'];
                            else if(isset($part->ctype_parameters['name']))
                                $attname = $part->ctype_parameters['name'];
                            else if(isset($part->headers['content-description']))
                                $attname = $part->headers['content-description'];
                            else $attname = "unknown attachment";

                            // ignore html content
                            if ($part->ctype_primary == "text" && $part->ctype_secondary == "html") {
                                continue;
                            }
                            //
                            if ($use_orgbody || $attached) {
                                $body .= $this->enc_attach_file($att_boundary, $attname, strlen($part->body),$part->body, $part->ctype_primary ."/". $part->ctype_secondary);
                            }
                            // first attachment
                            else {
                                $encmail = $body;
                                $attached = true;
                                $body = $this->enc_multipart($att_boundary, $body, $forward_h_ct, $forward_h_cte);
                                $body .= $this->enc_attach_file($att_boundary, $attname, strlen($part->body),$part->body, $part->ctype_primary ."/". $part->ctype_secondary);
                            }
                        }
                    }
                    if ($multipartmixed) {
                        //this happens if a multipart/alternative message is forwarded
                        //then it's a multipart/mixed message which consists of:
                        //1. text/plain part which was written on the mobile
                        //2. multipart/alternative part which is the original message
                        $body = "This is a message with multiple parts in MIME format.\n--".
                                $att_boundary.
                                "\nContent-Type: $forward_h_ct\nContent-Transfer-Encoding: $forward_h_cte\n\n".
                                (($body_base64) ? chunk_split(base64_encode($message->body)) : rtrim($message->body)).
                                "\n--".$att_boundary.
                                "\nContent-Type: {$mess2->headers['content-type']}\n\n".
                                @imap_body($this->mbox, $forward, FT_PEEK | FT_UID)."\n\n";
                    }
                    $body .= "--$att_boundary--\n\n";
                }

                unset($mobj2);
            }

            // unset origmail - free memory
            unset($origmail);

        }

        // remove carriage-returns from body
        $body = str_replace("\r\n", "\n", $body);

        if (!$multipartmixed) {
            if (!empty($forward_h_ct)) $headers .= "\nContent-Type: $forward_h_ct";
            if (!empty($forward_h_cte)) $headers .= "\nContent-Transfer-Encoding: $forward_h_cte";
        }

        // more debugging
        ZLog::Write(LOGLEVEL_DEBUG, "IMAP-SendMail: parsed message: ". print_r($message,1));
        ZLog::Write(LOGLEVEL_DEBUG, "IMAP-SendMail: headers: $headers");
        ZLog::Write(LOGLEVEL_DEBUG, "IMAP-SendMail: subject: {$message->headers["subject"]}");
        ZLog::Write(LOGLEVEL_DEBUG, "IMAP-SendMail: body: $body");

        if (!defined('IMAP_USE_IMAPMAIL') || IMAP_USE_IMAPMAIL == true) {
            $send =  @imap_mail ( $toaddr, $message->headers["subject"], $body, $headers, $ccaddr, $bccaddr);
        }
        else {
            if (!empty($ccaddr))  $headers .= "\nCc: $ccaddr";
            if (!empty($bccaddr)) $headers .= "\nBcc: $bccaddr";
            $send =  @mail ( $toaddr, $message->headers["subject"], $body, $headers, $envelopefrom );
        }

        // email sent?
        if (!$send) {
            ZLog::Write(LOGLEVEL_DEBUG, "The email could not be sent. Last-IMAP-error: ". imap_last_error());
        }

        // add message to the sent folder
        // build complete headers
        $headers .= "\nTo: $toaddr";
        $headers .= "\nSubject: " . $message->headers["subject"];

        if (!defined('IMAP_USE_IMAPMAIL') || IMAP_USE_IMAPMAIL == true) {
            if (!empty($ccaddr))  $headers .= "\nCc: $ccaddr";
            if (!empty($bccaddr)) $headers .= "\nBcc: $bccaddr";
        }
        ZLog::Write(LOGLEVEL_DEBUG, "IMAP-SendMail: complete headers: $headers");

        $asf = false;
        if ($this->sentID) {
            $asf = $this->addSentMessage($this->sentID, $headers, $body);
        }
        else if (IMAP_SENTFOLDER) {
            $asf = $this->addSentMessage(IMAP_SENTFOLDER, $headers, $body);
            ZLog::Write(LOGLEVEL_DEBUG, "IMAP-SendMail: Outgoing mail saved in configured 'Sent' folder '".IMAP_SENTFOLDER."': ". (($asf)?"success":"failed"));
        }
        // No Sent folder set, try defaults
        else {
            ZLog::Write(LOGLEVEL_DEBUG, "IMAP-SendMail: No Sent mailbox set");
            if($this->addSentMessage("INBOX.Sent", $headers, $body)) {
                ZLog::Write(LOGLEVEL_DEBUG, "IMAP-SendMail: Outgoing mail saved in 'INBOX.Sent'");
                $asf = true;
            }
            else if ($this->addSentMessage("Sent", $headers, $body)) {
                ZLog::Write(LOGLEVEL_DEBUG, "IMAP-SendMail: Outgoing mail saved in 'Sent'");
                $asf = true;
            }
            else if ($this->addSentMessage("Sent Items", $headers, $body)) {
                ZLog::Write(LOGLEVEL_DEBUG, "IMAP-SendMail: Outgoing mail saved in 'Sent Items'");
                $asf = true;
            }
        }

        // unset mimedecoder - free memory
        unset($mobj);
        return ($send && $asf);
    }

    /**
     * Returns the waste basket
     *
     * @access public
     * @return string
     */
    public function GetWasteBasket() {
        if ($this->wasteID == false) {
            //try to get the waste basket without doing complete hierarchy sync
            $wastebaskt = @imap_getmailboxes($this->mbox, $this->server, "Trash");
            if (isset($wastebaskt[0])) {
                $this->wasteID = imap_utf7_decode(substr($wastebaskt[0]->name, strlen($this->server)));
                return $this->wasteID;
            }
            //try get waste id from hierarchy if it wasn't possible with above for some reason
            $this->GetHierarchy();
        }
        return $this->wasteID;
    }

    /**
     * Returns the content of the named attachment. The passed attachment identifier is
     * the exact string that is returned in the 'AttName' property of an SyncAttachment.
     * Any information necessary to find the attachment must be encoded in that 'attname' property.
     * Data is written directly (with print $data;)
     *
     * @param string        $attname
     *
     * @access public
     * @return boolean
     */
    public function GetAttachmentData($attname) {
        ZLog::Write(LOGLEVEL_DEBUG, "getAttachmentDate: (attname: '$attname')");
        // TODO: this is broken, as $attname is HEX + : --> e.g. folderid is most probably not a hex value
        list($folderid, $id, $part) = explode(":", $attname);

        $this->imap_reopenFolder($folderid);
        $mail = @imap_fetchheader($this->mbox, $id, FT_UID) . @imap_body($this->mbox, $id, FT_PEEK | FT_UID);

        $mobj = new Mail_mimeDecode($mail);
        $message = $mobj->decode(array('decode_headers' => true, 'decode_bodies' => true, 'include_bodies' => true, 'charset' => 'utf-8'));

        if (isset($message->parts[$part]->body))
            print $message->parts[$part]->body;

        // unset mimedecoder & mail
        unset($mobj);
        unset($mail);
        return true;
    }

    /**
     * Returns true if the Backend implementation supports an alternative PING mechanism
     *
     * @access public
     * @return boolean
     */
    public function AlterPing() {
        return false;
    }

    /**
     * Requests an indication if changes happened in a folder since the syncstate
     *
     * @param string        $folderid       id of the folder
     * @param string        &$syncstate     reference of the syncstate
     *
     * @access public
     * @return boolean
     */
    public function AlterPingChanges($folderid, &$syncstate) {
        ZLog::Write(LOGLEVEL_DEBUG, "AlterPingChanges on $folderid stat: ". $syncstate);
        $this->imap_reopenFolder($folderid);

        // courier-imap only cleares the status cache after checking
        @imap_check($this->mbox);

        $status = imap_status($this->mbox, $this->server . $folderid, SA_ALL);
        if (!$status) {
            // TODO throw status exception
            ZLog::Write(LOGLEVEL_WARN, "AlterPingChanges: could not stat folder $folderid : ". imap_last_error());
            return false;
        }
        else {
            $newstate = "M:". $status->messages ."-R:". $status->recent ."-U:". $status->unseen;

            // message number is different - change occured
            if ($syncstate != $newstate) {
                $syncstate = $newstate;
                ZLog::Write(LOGLEVEL_INFO, "AlterPingChanges: Change FOUND!");
                // build a dummy change
                return array(array("type" => "fakeChange"));
            }
        }
        return array();
    }


    /**----------------------------------------------------------------------------------------------------------
     * implemented DiffBackend methods
     */


    /**
     * Returns a list (array) of folders.
     *
     * @access public
     * @return array
     */
    public function GetFolderList() {
        $folders = array();

        $list = @imap_getmailboxes($this->mbox, $this->server, "*");
        if (is_array($list)) {
            // reverse list to obtain folders in right order
            $list = array_reverse($list);

            foreach ($list as $val) {
                $box = array();
                // cut off serverstring
                $box["id"] = imap_utf7_decode(substr($val->name, strlen($this->server)));

                $fhir = array_map('imap_utf7_encode',explode($val->delimiter, $box["id"]));
                if (count($fhir) > 1) {
                    $this->getModAndParentNames($fhir, $box["mod"], $box["parent"]);
                }
                else {
                    $box["mod"] = imap_utf7_encode($box["id"]);
                    $box["parent"] = "0";
                }
                $folders[]=$box;
            }
        }
        else {
            // TODO throw status code
            ZLog::Write(LOGLEVEL_WARN, "GetFolderList: imap_list failed: " . imap_last_error());
        }

        return $folders;
    }

    /**
     * Returns an actual SyncFolder object
     *
     * @param string        $id           id of the folder
     *
     * @access public
     * @return object       SyncFolder with information
     */
    public function GetFolder($id) {
        $folder = new SyncFolder();
        $folder->serverid = $id;

        // explode hierarchy
        $fhir = explode($this->serverdelimiter, $id);

        // compare on lowercase strings
        $lid = strtolower($id);
// TODO WasteID or SentID could be saved for later ussage
        if($lid == "inbox") {
            $folder->parentid = "0"; // Root
            $folder->displayname = "Inbox";
            $folder->type = SYNC_FOLDER_TYPE_INBOX;
        }
        // Zarafa IMAP-Gateway outputs
        else if($lid == "drafts") {
            $folder->parentid = "0";
            $folder->displayname = "Drafts";
            $folder->type = SYNC_FOLDER_TYPE_DRAFTS;
        }
        else if($lid == "trash") {
            $folder->parentid = "0";
            $folder->displayname = "Trash";
            $folder->type = SYNC_FOLDER_TYPE_WASTEBASKET;
            $this->wasteID = $id;
        }
        else if($lid == "sent" || $lid == "sent items" || $lid == IMAP_SENTFOLDER) {
            $folder->parentid = "0";
            $folder->displayname = "Sent";
            $folder->type = SYNC_FOLDER_TYPE_SENTMAIL;
            $this->sentID = $id;
        }
        // courier-imap outputs and cyrus-imapd outputs
        else if($lid == "inbox.drafts" || $lid == "inbox/drafts") {
            $folder->parentid = $fhir[0];
            $folder->displayname = "Drafts";
            $folder->type = SYNC_FOLDER_TYPE_DRAFTS;
        }
        else if($lid == "inbox.trash" || $lid == "inbox/trash") {
            $folder->parentid = $fhir[0];
            $folder->displayname = "Trash";
            $folder->type = SYNC_FOLDER_TYPE_WASTEBASKET;
            $this->wasteID = $id;
        }
        else if($lid == "inbox.sent" || $lid == "inbox/sent") {
            $folder->parentid = $fhir[0];
            $folder->displayname = "Sent";
            $folder->type = SYNC_FOLDER_TYPE_SENTMAIL;
            $this->sentID = $id;
        }

        // define the rest as other-folders
        else {
               if (count($fhir) > 1) {
                   $this->getModAndParentNames($fhir, $folder->displayname, $folder->parentid);
                   $folder->displayname = windows1252_to_utf8(imap_utf7_decode($folder->displayname));
               }
               else {
                $folder->displayname = windows1252_to_utf8(imap_utf7_decode($id));
                $folder->parentid = "0";
               }
            $folder->type = SYNC_FOLDER_TYPE_OTHER;
        }

        //advanced debugging
        ZLog::Write(LOGLEVEL_DEBUG, "IMAP-GetFolder(id: '$id') -> " . $folder);

        return $folder;
    }

    /**
     * Returns folder stats. An associative array with properties is expected.
     *
     * @param string        $id             id of the folder
     *
     * @access public
     * @return array
     */
    public function StatFolder($id) {
        $folder = $this->GetFolder($id);

        $stat = array();
        $stat["id"] = $id;
        $stat["parent"] = $folder->parentid;
        $stat["mod"] = $folder->displayname;

        return $stat;
    }

    /**
     * Creates or modifies a folder
     * The folder type is ignored in IMAP, as all folders are Email folders
     *
     * @param string        $folderid       id of the parent folder
     * @param string        $oldid          if empty -> new folder created, else folder is to be renamed
     * @param string        $displayname    new folder name (to be created, or to be renamed to)
     * @param int           $type           folder type
     *
     * @access public
     * @return boolean      status
     *
     */
    public function ChangeFolder($folderid, $oldid, $displayname, $type){
        ZLog::Write(LOGLEVEL_INFO, "ChangeFolder: (parent: '$folderid'  oldid: '$oldid'  displayname: '$displayname'  type: '$type')");

        // go to parent mailbox
        $this->imap_reopenFolder($folderid);

        // build name for new mailbox
        $newname = $this->server . $folderid . $this->serverdelimiter . $displayname;

        $csts = false;
        // if $id is set => rename mailbox, otherwise create
        if ($oldid) {
            // rename doesn't work properly with IMAP
            // the activesync client doesn't support a 'changing ID'
            //$csts = imap_renamemailbox($this->mbox, $this->server . imap_utf7_encode(str_replace(".", $this->serverdelimiter, $oldid)), $newname);
        }
        else {
            $csts = @imap_createmailbox($this->mbox, $newname);
        }
        if ($csts) {
            return $this->StatFolder($folderid . $this->serverdelimiter . $displayname);
        }
        else
            return false;
    }

    /**
     * Returns a list (array) of messages
     *
     * @param string        $folderid       id of the parent folder
     * @param long          $cutoffdate     timestamp in the past from which on messages should be returned
     *
     * @access public
     * @return array        of messages
     */
    public function GetMessageList($folderid, $cutoffdate) {
        ZLog::Write(LOGLEVEL_DEBUG, "IMAP-GetMessageList: (fid: '$folderid'  cutdate: '$cutoffdate' )");

        $messages = array();
        $this->imap_reopenFolder($folderid, true);

        $sequence = "1:*";
        if ($cutoffdate > 0) {
            $search = @imap_search($this->mbox, "SINCE ". date("d-M-Y", $cutoffdate));
            if ($search !== false)
                $sequence = implode(",", $search);
        }
        $overviews = @imap_fetch_overview($this->mbox, $sequence);

        if (!$overviews) {
            // TODO throw status exception
            ZLog::Write(LOGLEVEL_WARN, "IMAP-GetMessageList: Failed to retrieve overview");
        } else {
            foreach($overviews as $overview) {
                $date = "";
                $vars = get_object_vars($overview);
                if (array_key_exists( "date", $vars)) {
                    // message is out of range for cutoffdate, ignore it
                    if(strtotime($overview->date) < $cutoffdate) continue;
                    $date = $overview->date;
                }

                // cut of deleted messages
                if (array_key_exists( "deleted", $vars) && $overview->deleted)
                    continue;

                if (array_key_exists( "uid", $vars)) {
                    $message = array();
                    $message["mod"] = $date;
                    $message["id"] = $overview->uid;
                    // 'seen' aka 'read' is the only flag we want to know about
                    $message["flags"] = 0;

                    if(array_key_exists( "seen", $vars) && $overview->seen)
                        $message["flags"] = 1;

                    array_push($messages, $message);
                }
            }
        }
        return $messages;
    }

    /**
     * Returns the actual SyncXXX object type.
     *
     * @param string        $folderid       id of the parent folder
     * @param string        $id             id of the message
     * @param int           $truncsize      truncation size in bytes
     * @param int           $mimesupport    output the mime message
     *
     * @access public
     * @return object
     */
    public function GetMessage($folderid, $id, $truncsize, $mimesupport = 0) {
        ZLog::Write(LOGLEVEL_DEBUG, "IMAP-GetMessage: (fid: '$folderid'  id: '$id'  truncsize: $truncsize)");

        // Get flags, etc
        $stat = $this->StatMessage($folderid, $id);

        if ($stat) {
            $this->imap_reopenFolder($folderid);
            $mail = @imap_fetchheader($this->mbox, $id, FT_UID) . @imap_body($this->mbox, $id, FT_PEEK | FT_UID);

            $mobj = new Mail_mimeDecode($mail);
            $message = $mobj->decode(array('decode_headers' => true, 'decode_bodies' => true, 'include_bodies' => true, 'charset' => 'utf-8'));

            $output = new SyncMail();

            $body = $this->getBody($message);
            // truncate body, if requested
            if(strlen($body) > $truncsize) {
                $body = Utils::Utf8_truncate($body, $truncsize);
                $output->bodytruncated = 1;
            } else {
                $body = $body;
                $output->bodytruncated = 0;
            }
            $body = str_replace("\n","\r\n", str_replace("\r","",$body));

            $output->bodysize = strlen($body);
            $output->body = $body;
            $output->datereceived = isset($message->headers["date"]) ? strtotime($message->headers["date"]) : null;
            $output->displayto = isset($message->headers["to"]) ? $message->headers["to"] : null;
            $output->importance = isset($message->headers["x-priority"]) ? preg_replace("/\D+/", "", $message->headers["x-priority"]) : null;
            $output->messageclass = "IPM.Note";
            $output->subject = isset($message->headers["subject"]) ? $message->headers["subject"] : "";
            $output->read = $stat["flags"];
            $output->to = isset($message->headers["to"]) ? $message->headers["to"] : null;
            $output->cc = isset($message->headers["cc"]) ? $message->headers["cc"] : null;
            $output->from = isset($message->headers["from"]) ? $message->headers["from"] : null;
            $output->reply_to = isset($message->headers["reply-to"]) ? $message->headers["reply-to"] : null;

            // Attachments are only searched in the top-level part
            $n = 0;
            if(isset($message->parts)) {
                foreach($message->parts as $part) {
                    if(isset($part->disposition) && ($part->disposition == "attachment" || $part->disposition == "inline")) {
                        $attachment = new SyncAttachment();

                        if (isset($part->body))
                            $attachment->attsize = strlen($part->body);

                        if(isset($part->d_parameters['filename']))
                            $attname = $part->d_parameters['filename'];
                        else if(isset($part->ctype_parameters['name']))
                            $attname = $part->ctype_parameters['name'];
                        else if(isset($part->headers['content-description']))
                            $attname = $part->headers['content-description'];
                        else $attname = "unknown attachment";

                        $attachment->displayname = $attname;
                        $attachment->attname = $folderid . ":" . $id . ":" . $n;
                        $attachment->attmethod = 1;
                        $attachment->attoid = isset($part->headers['content-id']) ? $part->headers['content-id'] : "";
                        array_push($output->attachments, $attachment);
                    }
                    $n++;
                }
            }
            // unset mimedecoder & mail
            unset($mobj);
            unset($mail);
            return $output;
        }
        // TODO throw status if message can not be retrieved
        return false;
    }

    /**
     * Returns message stats, analogous to the folder stats from StatFolder().
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     *
     * @access public
     * @return array
     */
    public function StatMessage($folderid, $id) {
        ZLog::Write(LOGLEVEL_DEBUG, "IMAP-StatMessage: (fid: '$folderid'  id: '$id' )");

        $this->imap_reopenFolder($folderid);
        $overview = @imap_fetch_overview( $this->mbox , $id , FT_UID);

        if (!$overview) {
            // TODO throw status
            ZLog::Write(LOGLEVEL_WARN, "IMAP-StatMessage: Failed to retrieve overview: ". imap_last_error());
            return false;
        }

        else {
            // check if variables for this overview object are available
            $vars = get_object_vars($overview[0]);

            // without uid it's not a valid message
            if (! array_key_exists( "uid", $vars)) return false;


            $entry = array();
            $entry["mod"] = (array_key_exists( "date", $vars)) ? $overview[0]->date : "";
            $entry["id"] = $overview[0]->uid;
            // 'seen' aka 'read' is the only flag we want to know about
            $entry["flags"] = 0;

            if(array_key_exists( "seen", $vars) && $overview[0]->seen)
                $entry["flags"] = 1;

            //advanced debugging
            ZLog::Write(LOGLEVEL_DEBUG, "IMAP-StatMessage-parsed: ". print_r($entry,1));

            return $entry;
        }
    }

    /**
     * Called when a message has been changed on the mobile.
     * This functionality is not available for emails.
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     * @param SyncXXX       $message        the SyncObject containing a message
     *
     * @access public
     * @return array        same return value as StatMessage()
     */
    public function ChangeMessage($folderid, $id, $message) {
        return false;
    }

    /**
     * Changes the 'read' flag of a message on disk
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     * @param int           $flags          read flag of the message
     *
     * @access public
     * @return boolean      status of the operation
     */
    public function SetReadFlag($folderid, $id, $flags) {
        ZLog::Write(LOGLEVEL_DEBUG, "IMAP-SetReadFlag: (fid: '$folderid'  id: '$id'  flags: '$flags' )");

        $this->imap_reopenFolder($folderid);

        if ($flags == 0) {
            // set as "Unseen" (unread)
            $status = @imap_clearflag_full ( $this->mbox, $id, "\\Seen", ST_UID);
        } else {
            // set as "Seen" (read)
            $status = @imap_setflag_full($this->mbox, $id, "\\Seen",ST_UID);
        }

        ZLog::Write(LOGLEVEL_DEBUG, "IMAP-SetReadFlag -> set as " . (($flags) ? "read" : "unread") . "-->". $status);

        return $status;
    }

    /**
     * Called when the user has requested to delete (really delete) a message
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     *
     * @access public
     * @return boolean      status of the operation
     */
    public function DeleteMessage($folderid, $id) {
        ZLog::Write(LOGLEVEL_DEBUG, "IMAP-DeleteMessage: (fid: '$folderid'  id: '$id' )");

        $this->imap_reopenFolder($folderid);
        $s1 = @imap_delete ($this->mbox, $id, FT_UID);
        $s11 = @imap_setflag_full($this->mbox, $id, "\\Deleted", FT_UID);
        $s2 = @imap_expunge($this->mbox);

        ZLog::Write(LOGLEVEL_DEBUG, "IMAP-DeleteMessage: s-delete: $s1   s-expunge: $s2    setflag: $s11");

        return ($s1 && $s2 && $s11);
    }

    /**
     * Called when the user moves an item on the PDA from one folder to another
     *
     * @param string        $folderid       id of the source folder
     * @param string        $id             id of the message
     * @param string        $newfolderid    id of the destination folder
     *
     * @access public
     * @return boolean      status of the operation
     */
    public function MoveMessage($folderid, $id, $newfolderid) {
        ZLog::Write(LOGLEVEL_DEBUG, "IMAP-MoveMessage: (sfid: '$folderid'  id: '$id'  dfid: '$newfolderid' )");

        $this->imap_reopenFolder($folderid);

        // read message flags
        $overview = @imap_fetch_overview ( $this->mbox , $id, FT_UID);

        if (!$overview) {
            // TODO throw status exception
            ZLog::Write(LOGLEVEL_WARN, "IMAP-MoveMessage: Failed to retrieve overview");
            return false;
        }
        else {
            // get next UID for destination folder
            // when moving a message we have to announce through ActiveSync the new messageID in the
            // destination folder. This is a "guessing" mechanism as IMAP does not inform that value.
            // when lots of simultaneous operations happen in the destination folder this could fail.
            // in the worst case the moved message is displayed twice on the mobile.
            $destStatus = imap_status($this->mbox, $this->server . $newfolderid, SA_ALL);
            $newid = $destStatus->uidnext;

            // move message
            $s1 = imap_mail_move($this->mbox, $id, $newfolderid, CP_UID);

            // delete message in from-folder
            $s2 = imap_expunge($this->mbox);

            // open new folder
            $this->imap_reopenFolder($newfolderid);

            // remove all flags
            $s3 = @imap_clearflag_full ($this->mbox, $newid, "\\Seen \\Answered \\Flagged \\Deleted \\Draft", FT_UID);
            $newflags = "";
            if ($overview[0]->seen) $newflags .= "\\Seen";
            if ($overview[0]->flagged) $newflags .= " \\Flagged";
            if ($overview[0]->answered) $newflags .= " \\Answered";
            $s4 = @imap_setflag_full ($this->mbox, $newid, $newflags, FT_UID);

            ZLog::Write(LOGLEVEL_DEBUG, "MoveMessage: (" . $folderid . "->" . $newfolderid . ":". $newid. ") s-move: $s1   s-expunge: $s2    unset-Flags: $s3    set-Flags: $s4");

            // return the new id "as string""
            return $newid . "";
        }
    }


    /**----------------------------------------------------------------------------------------------------------
     * private IMAP methods
     */


    /**
     * Parses the message and return only the plaintext body
     *
     * @param string        $message        html message
     *
     * @access private
     * @return string       plaintext message
     */
    private function getBody($message) {
        $body = "";
        $htmlbody = "";

        $this->getBodyRecursive($message, "plain", $body);

        if($body === "") {
            $this->getBodyRecursive($message, "html", $body);
            // remove css-style tags
            $body = preg_replace("/<style.*?<\/style>/is", "", $body);
            // remove all other html
            $body = strip_tags($body);
        }

        return $body;
    }

    /**
     * Get all parts in the message with specified type and concatenate them together, unless the
     * Content-Disposition is 'attachment', in which case the text is apparently an attachment
     *
     * @param string        $message        mimedecode message(part)
     * @param string        $message        message subtype
     * @param string        &$body          body reference
     *
     * @access private
     * @return
     */
    private function getBodyRecursive($message, $subtype, &$body) {
        if(!isset($message->ctype_primary)) return;
        if(strcasecmp($message->ctype_primary,"text")==0 && strcasecmp($message->ctype_secondary,$subtype)==0 && isset($message->body))
            $body .= $message->body;

        if(strcasecmp($message->ctype_primary,"multipart")==0 && isset($message->parts) && is_array($message->parts)) {
            foreach($message->parts as $part) {
                if(!isset($part->disposition) || strcasecmp($part->disposition,"attachment"))  {
                    $this->getBodyRecursive($part, $subtype, $body);
                }
            }
        }
    }

    /**
     * Returns the serverdelimiter for folder parsing
     *
     * @access private
     * @return string       delimiter
     */
    private function getServerDelimiter() {
        $list = @imap_getmailboxes($this->mbox, $this->server, "*");
        if (is_array($list)) {
            $val = $list[0];

            return $val->delimiter;
        }
        return "."; // default "."
    }

    /**
     * Helper to re-initialize the folder to speed things up
     * Remember what folder is currently open and only change if necessary
     *
     * @param string        $folderid       id of the folder
     * @param boolean       $force          re-open the folder even if currently opened
     *
     * @access private
     * @return
     */
    private function imap_reopenFolder($folderid, $force = false) {
        // to see changes, the folder has to be reopened!
           if ($this->mboxFolder != $folderid || $force) {
               $s = @imap_reopen($this->mbox, $this->server . $folderid);
               // TODO throw status exception
               if (!$s) ZLog::Write(LOGLEVEL_WARN, "failed to change folder: ". implode(", ", imap_errors()));
            $this->mboxFolder = $folderid;
        }
    }


    /**
     * Build a multipart RFC822, embedding body and one file (for attachments)
     *
     * @param string        $filenm         name of the file to be attached
     * @param long          $filesize       size of the file to be attached
     * @param string        $file_cont      content of the file
     * @param string        $body           current body
     * @param string        $body_ct        content-type
     * @param string        $body_cte       content-transfer-encoding
     * @param string        $boundary       optional existing boundary
     *
     * @access private
     * @return array        with [0] => $mail_header and [1] => $mail_body
     */
    private function mail_attach($filenm,$filesize,$file_cont,$body, $body_ct, $body_cte, $boundary = false) {
        if (!$boundary) $boundary = strtoupper(md5(uniqid(time())));

        //remove the ending boundary because we will add it at the end
        $body = str_replace("--$boundary--", "", $body);

        $mail_header = "Content-Type: multipart/mixed; boundary=$boundary\n";

        // build main body with the sumitted type & encoding from the pda
        $mail_body  = $this->enc_multipart($boundary, $body, $body_ct, $body_cte);
        $mail_body .= $this->enc_attach_file($boundary, $filenm, $filesize, $file_cont);

        $mail_body .= "--$boundary--\n\n";
        return array($mail_header, $mail_body);
    }

    /**
     * Helper for mail_attach()
     *
     * @param string        $boundary       boundary
     * @param string        $body           current body
     * @param string        $body_ct        content-type
     * @param string        $body_cte       content-transfer-encoding
     *
     * @access private
     * @return string       message body
     */
    private function enc_multipart($boundary, $body, $body_ct, $body_cte) {
        $mail_body = "This is a multi-part message in MIME format\n\n";
        $mail_body .= "$body\n\n";

        return $mail_body;
    }

    /**
     * Helper for mail_attach()
     *
     * @param string        $boundary       boundary
     * @param string        $filenm         name of the file to be attached
     * @param long          $filesize       size of the file to be attached
     * @param string        $file_cont      content of the file
     * @param string        $content_type   optional content-type
     *
     * @access private
     * @return string       message body
     */
    private function enc_attach_file($boundary, $filenm, $filesize, $file_cont, $content_type = "") {
        if (!$content_type) $content_type = "text/plain";
        $mail_body = "--$boundary\n";
        $mail_body .= "Content-Type: $content_type; name=\"$filenm\"\n";
        $mail_body .= "Content-Transfer-Encoding: base64\n";
        $mail_body .= "Content-Disposition: attachment; filename=\"$filenm\"\n";
        $mail_body .= "Content-Description: $filenm\n\n";
        //contrib - chunk base64 encoded attachments
        $mail_body .= chunk_split(base64_encode($file_cont)) . "\n\n";

        return $mail_body;
    }

    /**
     * Adds a message with seen flag to a specified folder (used for saving sent items)
     *
     * @param string        $folderid       id of the folder
     * @param string        $header         header of the message
     * @param long          $body           body of the message
     *
     * @access private
     * @return boolean      status
     */
    private function addSentMessage($folderid, $header, $body) {
        $header_body = str_replace("\n", "\r\n", str_replace("\r", "", $header . "\n\n" . $body));

        return @imap_append($this->mbox, $this->server . $folderid, $header_body, "\\Seen");
    }

    /**
     * Parses an mimedecode address array back to a simple "," separated string
     *
     * @param array         $ad             addresses array
     *
     * @access private
     * @return string       mail address(es) string
     */
    private function parseAddr($ad) {
        $addr_string = "";
        if (isset($ad) && is_array($ad)) {
            foreach($ad as $addr) {
                if ($addr_string) $addr_string .= ",";
                    $addr_string .= $addr->mailbox . "@" . $addr->host;
            }
        }
        return $addr_string;
    }

    /**
     * Recursive way to get mod and parent - repeat until only one part is left
     * or the folder is identified as an IMAP folder
     *
     * @param string        $fhir           folder hierarchy string
     * @param string        &$displayname   reference of the displayname
     * @param long          &$parent        reference of the parent folder
     *
     * @access private
     * @return
     */
    private function getModAndParentNames($fhir, &$displayname, &$parent) {
        // if mod is already set add the previous part to it as it might be a folder which has
        // delimiter in its name
        $displayname = (isset($displayname) && strlen($displayname) > 0) ? $displayname = array_pop($fhir).$this->serverdelimiter.$displayname : array_pop($fhir);
        $parent = implode($this->serverdelimiter, $fhir);

        if (count($fhir) == 1 || $this->checkIfIMAPFolder($parent)) {
            return;
        }
        //recursion magic
        $this->getModAndParentNames($fhir, $displayname, $parent);
    }

    /**
     * Checks if a specified name is a folder in the IMAP store
     *
     * @param string        $foldername     a foldername
     *
     * @access private
     * @return boolean
     */
    private function checkIfIMAPFolder($folderName) {
        $parent = imap_list($this->mbox, $this->server, $folderName);
        if ($parent === false) return false;
        return true;
    }

}

?>