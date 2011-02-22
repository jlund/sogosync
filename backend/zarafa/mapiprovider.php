<?php
/***********************************************
* File      :   mapiprovider.php
* Project   :   Z-Push
* Descr     :
*
* Created   :   14.02.2011
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

class MAPIProvider extends MAPIMapping{
    var $_session;
    var $_store;

    function MAPIProvider($session, $store) {
        $this->_session = $session;
        $this->_store = $store;
    }


    // ------------------- getter
    function _getMessage($mapimessage, $truncflag, $mimesupport = 0) {
        // Gets the Sync object from a MAPI object according to its message class

        $truncsize = $this->getTruncSize($truncflag);
        $props = mapi_getprops($mapimessage, array(PR_MESSAGE_CLASS));
        if(isset($props[PR_MESSAGE_CLASS]))
            $messageclass = $props[PR_MESSAGE_CLASS];
        else
            $messageclass = "IPM";

        if(strpos($messageclass,"IPM.Contact") === 0)
            return $this->_getContact($mapimessage, $truncsize, $mimesupport);
        else if(strpos($messageclass,"IPM.Appointment") === 0)
            return $this->_getAppointment($mapimessage, $truncsize, $mimesupport);
        else if(strpos($messageclass,"IPM.Task") === 0)
            return $this->_getTask($mapimessage, $truncsize, $mimesupport);
        else
            return $this->_getEmail($mapimessage, $truncsize, $mimesupport);
    }

    // Get an SyncContact object
    function _getContact($mapimessage, $truncsize, $mimesupport = 0) {
        $message = new SyncContact();

        $this->_getPropsFromMAPI($message, $mapimessage, $this->_contactmapping);

        //check the picture
        $haspic = $this->_getPropIDFromString("PT_BOOLEAN:{00062004-0000-0000-C000-000000000046}:0x8015");
        $messageprops = mapi_getprops($mapimessage, array( $haspic ));
        if (isset($messageprops[$haspic]) && $messageprops[$haspic]) {
            // Add attachments
            $attachtable = mapi_message_getattachmenttable($mapimessage);
            mapi_table_restrict($attachtable, getContactPicRestriction());
            $rows = mapi_table_queryallrows($attachtable, array(PR_ATTACH_NUM, PR_ATTACH_SIZE));

            foreach($rows as $row) {
                if(isset($row[PR_ATTACH_NUM])) {
                    if (isset($row[PR_ATTACH_SIZE]) && $row[PR_ATTACH_SIZE] < MAX_EMBEDDED_SIZE) {
                        $mapiattach = mapi_message_openattach($mapimessage, $row[PR_ATTACH_NUM]);
                        $message->picture = base64_encode(mapi_attach_openbin($mapiattach, PR_ATTACH_DATA_BIN));
                    }
                }
            }
        }

        return $message;
    }

    // Get an SyncTask object
    function _getTask($mapimessage, $truncsize, $mimesupport = 0) {
        $message = new SyncTask();

        $this->_getPropsFromMAPI($message, $mapimessage, $this->_taskmapping);

        // when set the task to complete using the WebAccess, the dateComplete property is not set correctly
        if ($message->complete == 1 && !isset($message->datecompleted))
            $message->datecompleted = time();

        return $message;
    }

    // Get an SyncAppointment object
    function _getAppointment($mapimessage, $truncsize, $mimesupport = 0) {
        $message = new SyncAppointment();

        // Standard one-to-one mappings first
        $this->_getPropsFromMAPI($message, $mapimessage, $this->_appointmentmapping);

        // Disable reminder if it is off
        $reminderset = $this->_getPropIDFromString("PT_BOOLEAN:{00062008-0000-0000-C000-000000000046}:0x8503");
        $remindertime = $this->_getPropIDFromString("PT_LONG:{00062008-0000-0000-C000-000000000046}:0x8501");
        $messageprops = mapi_getprops($mapimessage, array ( $reminderset, $remindertime ));

        if(!isset($messageprops[$reminderset]) || $messageprops[$reminderset] == false)
            $message->reminder = "";
        else {
            if ($messageprops[$remindertime] == 0x5AE980E1)
                $message->reminder = 15;
            else
                $message->reminder = $messageprops[$remindertime];
        }

        $messageprops = mapi_getprops($mapimessage, array ( PR_SOURCE_KEY ));

        if(!isset($message->uid))
            $message->uid = bin2hex($messageprops[PR_SOURCE_KEY]);
        else
            $message->uid = getICalUidFromOLUid($message->uid);

        // Get organizer information if it is a meetingrequest
        $meetingstatustag = $this->_getPropIDFromString("PT_LONG:{00062002-0000-0000-C000-000000000046}:0x8217");
        $messageprops = mapi_getprops($mapimessage, array($meetingstatustag, PR_SENT_REPRESENTING_ENTRYID, PR_SENT_REPRESENTING_NAME));

        if(isset($messageprops[$meetingstatustag]) && $messageprops[$meetingstatustag] > 0 && isset($messageprops[PR_SENT_REPRESENTING_ENTRYID]) && isset($messageprops[PR_SENT_REPRESENTING_NAME])) {
            $message->organizeremail = w2u($this->_getSMTPAddressFromEntryID($messageprops[PR_SENT_REPRESENTING_ENTRYID]));
            $message->organizername = w2u($messageprops[PR_SENT_REPRESENTING_NAME]);
        }

        $isrecurringtag = $this->_getPropIDFromString("PT_BOOLEAN:{00062002-0000-0000-C000-000000000046}:0x8223");
        $recurringstate = $this->_getPropIDFromString("PT_BINARY:{00062002-0000-0000-C000-000000000046}:0x8216");
        $timezonetag = $this->_getPropIDFromString("PT_BINARY:{00062002-0000-0000-C000-000000000046}:0x8233");

        // Now, get and convert the recurrence and timezone information
        $recurprops = mapi_getprops($mapimessage, array($isrecurringtag, $recurringstate, $timezonetag));

        if(isset($recurprops[$timezonetag]))
            $tz = $this->_getTZFromMAPIBlob($recurprops[$timezonetag]);
        else
            $tz = $this->_getGMTTZ();

        $message->timezone = base64_encode($this->_getSyncBlobFromTZ($tz));

        if(isset($recurprops[$isrecurringtag]) && $recurprops[$isrecurringtag]) {
            // Process recurrence
            $message->recurrence = new SyncRecurrence();
            $this->_getRecurrence($mapimessage, $recurprops, $message, $message->recurrence, $tz);
        }

        // Do attendees
        $reciptable = mapi_message_getrecipienttable($mapimessage);
        $rows = mapi_table_queryallrows($reciptable, array(PR_DISPLAY_NAME, PR_EMAIL_ADDRESS, PR_SMTP_ADDRESS, PR_ADDRTYPE));
        if(count($rows) > 0)
            $message->attendees = array();

        foreach($rows as $row) {
            $attendee = new SyncAttendee();

            $attendee->name = w2u($row[PR_DISPLAY_NAME]);
            //smtp address is always a proper email address
            if(isset($row[PR_SMTP_ADDRESS]))
                $attendee->email = w2u($row[PR_SMTP_ADDRESS]);
            elseif (isset($row[PR_ADDRTYPE]) && isset($row[PR_EMAIL_ADDRESS])) {
                //if address type is SMTP, it's also a proper email address
                if ($row[PR_ADDRTYPE] == "SMTP")
                    $attendee->email = w2u($row[PR_EMAIL_ADDRESS]);
                //if address type is ZARAFA, the PR_EMAIL_ADDRESS contains username
                elseif ($row[PR_ADDRTYPE] == "ZARAFA") {
                    $userinfo = mapi_zarafa_getuser_by_name($this->_store, $row[PR_EMAIL_ADDRESS]);
                    if (is_array($userinfo) && isset($userinfo["emailaddress"]))
                        $attendee->email = w2u($userinfo["emailaddress"]);
                }
            }
            // Some attendees have no email or name (eg resources), and if you
            // don't send one of those fields, the phone will give an error ... so
            // we don't send it in that case.
            // also ignore the "attendee" if the email is equal to the organizers' email
            if(isset($attendee->name) && isset($attendee->email) && (!isset($message->organizeremail) || (isset($message->organizeremail) && $attendee->email != $message->organizeremail)))
                array_push($message->attendees, $attendee);
        }
        // Force the 'alldayevent' in the object at all times. (non-existent == 0)
        if(!isset($message->alldayevent) || $message->alldayevent == "")
            $message->alldayevent = 0;

        return $message;
    }

    // Get an SyncXXXRecurrence
    function _getRecurrence($mapimessage, $recurprops, &$syncMessage, &$syncRecurrence, $tz) {
        $recurrence = new Recurrence($this->_store, $recurprops);

        switch($recurrence->recur["type"]) {
            case 10: // daily
                switch($recurrence->recur["subtype"]) {
                    default:
                    $syncRecurrence->type = 0;
                        break;
                    case 1:
                    $syncRecurrence->type = 0;
                    $syncRecurrence->dayofweek = 62; // mon-fri
                        break;
                }
                break;
            case 11: // weekly
                    $syncRecurrence->type = 1;
                break;
            case 12: // monthly
                switch($recurrence->recur["subtype"]) {
                    default:
                    $syncRecurrence->type = 2;
                        break;
                    case 3:
                    $syncRecurrence->type = 3;
                        break;
                }
                break;
            case 13: // yearly
                switch($recurrence->recur["subtype"]) {
                    default:
                    $syncRecurrence->type = 4;
                        break;
                    case 2:
                    $syncRecurrence->type = 5;
                        break;
                    case 3:
                    $syncRecurrence->type = 6;
                }
        }
        // Termination
        switch($recurrence->recur["term"]) {
           case 0x21:
            $syncRecurrence->until = $recurrence->recur["end"]; break;
            case 0x22:
            $syncRecurrence->occurrences = $recurrence->recur["numoccur"]; break;
            case 0x23:
                // never ends
                break;
        }

        // Correct 'alldayevent' because outlook fails to set it on recurring items of 24 hours or longer
        if($recurrence->recur["endocc"] - $recurrence->recur["startocc"] >= 1440)
            $syncMessage->alldayevent = true;

        // Interval is different according to the type/subtype
        switch($recurrence->recur["type"]) {
            case 10:
                if($recurrence->recur["subtype"] == 0)
                $syncRecurrence->interval = (int)($recurrence->recur["everyn"] / 1440);  // minutes
                break;
            case 11:
            case 12: $syncRecurrence->interval = $recurrence->recur["everyn"]; break; // months / weeks
            case 13: $syncRecurrence->interval = (int)($recurrence->recur["everyn"] / 12); break; // months
        }

        if(isset($recurrence->recur["weekdays"]))
        $syncRecurrence->dayofweek = $recurrence->recur["weekdays"]; // bitmask of days (1 == sunday, 128 == saturday
        if(isset($recurrence->recur["nday"]))
        $syncRecurrence->weekofmonth = $recurrence->recur["nday"]; // N'th {DAY} of {X} (0-5)
        if(isset($recurrence->recur["month"]))
        $syncRecurrence->monthofyear = (int)($recurrence->recur["month"] / (60 * 24 * 29)) + 1; // works ok due to rounding. see also $monthminutes below (1-12)
        if(isset($recurrence->recur["monthday"]))
        $syncRecurrence->dayofmonth = $recurrence->recur["monthday"]; // day of month (1-31)

        // All changed exceptions are appointments within the 'exceptions' array. They contain the same items as a normal appointment
        foreach($recurrence->recur["changed_occurences"] as $change) {
            $exception = new SyncAppointment();

            // start, end, basedate, subject, remind_before, reminderset, location, busystatus, alldayevent, label
            if(isset($change["start"]))
                $exception->starttime = $this->_getGMTTimeByTZ($change["start"], $tz);
            if(isset($change["end"]))
                $exception->endtime = $this->_getGMTTimeByTZ($change["end"], $tz);
            if(isset($change["basedate"]))
                $exception->exceptionstarttime = $this->_getGMTTimeByTZ($this->_getDayStartOfTimestamp($change["basedate"]) + $recurrence->recur["startocc"] * 60, $tz);
            if(isset($change["subject"]))
                $exception->subject = w2u($change["subject"]);
            if(isset($change["reminder_before"]) && $change["reminder_before"])
                $exception->reminder = $change["remind_before"];
            if(isset($change["location"]))
                $exception->location = w2u($change["location"]);
            if(isset($change["busystatus"]))
                $exception->busystatus = $change["busystatus"];
            if(isset($change["alldayevent"]))
                $exception->alldayevent = $change["alldayevent"];

            // set some data from the original appointment
            if (isset($syncMessage->uid))
                $exception->uid = $syncMessage->uid;
            if (isset($syncMessage->organizername))
                $exception->organizername = $syncMessage->organizername;
            if (isset($syncMessage->organizeremail))
                $exception->organizeremail = $syncMessage->organizeremail;

            if(!isset($syncMessage->exceptions))
                $syncMessage->exceptions = array();

            array_push($syncMessage->exceptions, $exception);
        }

        // Deleted appointments contain only the original date (basedate) and a 'deleted' tag
        foreach($recurrence->recur["deleted_occurences"] as $deleted) {
            $exception = new SyncAppointment();

            $exception->exceptionstarttime = $this->_getGMTTimeByTZ($this->_getDayStartOfTimestamp($deleted) + $recurrence->recur["startocc"] * 60, $tz);
            $exception->deleted = "1";

            if(!isset($syncMessage->exceptions))
                $syncMessage->exceptions = array();

            array_push($syncMessage->exceptions, $exception);
        }
    }


    // Get an SyncEmail object
    function _getEmail($mapimessage, $truncsize, $mimesupport = 0) {
        $message = new SyncMail();

        $this->_getPropsFromMAPI($message, $mapimessage, $this->_emailmapping);

        // Override 'From' to show "Full Name <user@domain.com>"
        $messageprops = mapi_getprops($mapimessage, array(PR_SENT_REPRESENTING_NAME, PR_SENT_REPRESENTING_ENTRYID, PR_SOURCE_KEY));

        // Override 'body' for truncation
        $body = mapi_openproperty($mapimessage, PR_BODY);
        if(strlen($body) > $truncsize) {
            $body = utf8_truncate($body, $truncsize);
            $message->bodytruncated = 1;
            $message->bodysize = strlen($body);
        } else {
            $message->bodytruncated = 0;
        }

        $message->body = str_replace("\n","\r\n", w2u(str_replace("\r","",$body)));

        if(isset($messageprops[PR_SOURCE_KEY]))
            $sourcekey = $messageprops[PR_SOURCE_KEY];
        else
            return false;

        $fromname = $fromaddr = "";

        if(isset($messageprops[PR_SENT_REPRESENTING_NAME]))
            $fromname = $messageprops[PR_SENT_REPRESENTING_NAME];
        if(isset($messageprops[PR_SENT_REPRESENTING_ENTRYID]))
            $fromaddr = $this->_getSMTPAddressFromEntryID($messageprops[PR_SENT_REPRESENTING_ENTRYID]);

        if($fromname == $fromaddr)
            $fromname = "";

        if($fromname)
            $from = "\"" . w2u($fromname) . "\" <" . w2u($fromaddr) . ">";
        else
            //START CHANGED dw2412 HTC shows "error" if sender name is unknown
            $from = "\"" . w2u($fromaddr) . "\" <" . w2u($fromaddr) . ">";
            //END CHANGED dw2412 HTC shows "error" if sender name is unknown

        $message->from = $from;

        // process Meeting Requests
        if(isset($message->messageclass) && strpos($message->messageclass, "IPM.Schedule.Meeting") === 0) {
            $message->meetingrequest = new SyncMeetingRequest();
            $this->_getPropsFromMAPI($message->meetingrequest, $mapimessage, $this->_meetingrequestmapping);

            $goidtag = $this->_getPropIdFromString("PT_BINARY:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0x3");
            $timezonetag = $this->_getPropIDFromString("PT_BINARY:{00062002-0000-0000-C000-000000000046}:0x8233");
            $recReplTime = $this->_getPropIDFromString("PT_SYSTIME:{00062002-0000-0000-C000-000000000046}:0x8228");
            $isrecurringtag = $this->_getPropIDFromString("PT_BOOLEAN:{00062002-0000-0000-C000-000000000046}:0x8223");
            $recurringstate = $this->_getPropIDFromString("PT_BINARY:{00062002-0000-0000-C000-000000000046}:0x8216");
            $appSeqNr = $this->_getPropIDFromString("PT_LONG:{00062002-0000-0000-C000-000000000046}:0x8201");
            $lidIsException = $this->_getPropIDFromString("PT_BOOLEAN:{00062002-0000-0000-C000-000000000046}:0xA");
            $recurStartTime = $this->_getPropIDFromString("PT_LONG:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0xE");

            $props = mapi_getprops($mapimessage, array($goidtag, $timezonetag, $recReplTime, $isrecurringtag, $recurringstate, $appSeqNr, $lidIsException, $recurStartTime));

            // Get the GOID
            if(isset($props[$goidtag]))
                $message->meetingrequest->globalobjid = base64_encode($props[$goidtag]);

            // Set Timezone
            if(isset($props[$timezonetag]))
                $tz = $this->_getTZFromMAPIBlob($props[$timezonetag]);
            else
                $tz = $this->_getGMTTZ();

            $message->meetingrequest->timezone = base64_encode($this->_getSyncBlobFromTZ($tz));

            // send basedate if exception
            if(isset($props[$recReplTime]) || (isset($props[$lidIsException]) && $props[$lidIsException] == true)) {
                if (isset($props[$recReplTime])){
                   $basedate = $props[$recReplTime];
                   $message->meetingrequest->recurrenceid = $this->_getGMTTimeByTZ($basedate, $this->_getGMTTZ());
                }
                else {
                   if (!isset($props[$goidtag]) || !isset($props[$recurStartTime]) || !isset($props[$timezonetag]))
                       writeLog(LOGLEVEL_WARN, "Missing property to set correct basedate for exception");
                   else {
                       $basedate = extractBaseDate($props[$goidtag], $props[$recurStartTime]);
                       $message->meetingrequest->recurrenceid = $this->_getGMTTimeByTZ($basedate, $tz);
                   }
                }
            }

            // Organizer is the sender
            $message->meetingrequest->organizer = $message->from;

            // Process recurrence
            if(isset($props[$isrecurringtag]) && $props[$isrecurringtag]) {
                $myrec = new SyncMeetingRequestRecurrence();
                // get recurrence -> put $message->meetingrequest as message so the 'alldayevent' is set correctly
                $this->_getRecurrence($mapimessage, $props, $message->meetingrequest, $myrec, $tz);
                $message->meetingrequest->recurrences = array($myrec);
            }

            // Force the 'alldayevent' in the object at all times. (non-existent == 0)
            if(!isset($message->meetingrequest->alldayevent) || $message->meetingrequest->alldayevent == "")
                $message->meetingrequest->alldayevent = 0;

            // Instancetype
            // 0 = single appointment
            // 1 = master recurring appointment
            // 2 = single instance of recurring appointment
            // 3 = exception of recurring appointment
            $message->meetingrequest->instancetype = 0;
            if (isset($props[$isrecurringtag]) && $props[$isrecurringtag] == 1)
                $message->meetingrequest->instancetype = 1;
            else if ((!isset($props[$isrecurringtag]) || $props[$isrecurringtag] == 0 )&& isset($message->meetingrequest->recurrenceid))
                if (isset($props[$appSeqNr]) && $props[$appSeqNr] == 0 )
                    $message->meetingrequest->instancetype = 2;
                else
                    $message->meetingrequest->instancetype = 3;

            // Disable reminder if it is off
            $reminderset = $this->_getPropIDFromString("PT_BOOLEAN:{00062008-0000-0000-C000-000000000046}:0x8503");
            $remindertime = $this->_getPropIDFromString("PT_LONG:{00062008-0000-0000-C000-000000000046}:0x8501");
            $messageprops = mapi_getprops($mapimessage, array ( $reminderset, $remindertime ));

            if(!isset($messageprops[$reminderset]) || $messageprops[$reminderset] == false)
                $message->meetingrequest->reminder = "";
            //the property saves reminder in minutes, but we need it in secs
            else {
                ///set the default reminder time to seconds
                if ($messageprops[$remindertime] == 0x5AE980E1)
                    $message->meetingrequest->reminder = 900;
                else
                    $message->meetingrequest->reminder = $messageprops[$remindertime] * 60;
            }

            // Set sensitivity to 0 if missing
            if(!isset($message->meetingrequest->sensitivity))
                $message->meetingrequest->sensitivity = 0;
        }


        // Add attachments
        $attachtable = mapi_message_getattachmenttable($mapimessage);
        $rows = mapi_table_queryallrows($attachtable, array(PR_ATTACH_NUM));

        foreach($rows as $row) {
            if(isset($row[PR_ATTACH_NUM])) {
                $mapiattach = mapi_message_openattach($mapimessage, $row[PR_ATTACH_NUM]);

                $attachprops = mapi_getprops($mapiattach, array(PR_ATTACH_LONG_FILENAME));

                $attach = new SyncAttachment();

                $stream = mapi_openpropertytostream($mapiattach, PR_ATTACH_DATA_BIN);
                if($stream) {
                    $stat = mapi_stream_stat($stream);

                    $attach->attsize = $stat["cb"];
                    $attach->displayname = w2u($attachprops[PR_ATTACH_LONG_FILENAME]);
                    $attach->attname = bin2hex($this->_folderid) . ":" . bin2hex($sourcekey) . ":" . $row[PR_ATTACH_NUM];

                    if(!isset($message->attachments))
                        $message->attachments = array();

                    array_push($message->attachments, $attach);
                }
            }
        }

        // Get To/Cc as SMTP addresses (this is different from displayto and displaycc because we are putting
        // in the SMTP addresses as well, while displayto and displaycc could just contain the display names
        $to = array();
        $cc = array();

        $reciptable = mapi_message_getrecipienttable($mapimessage);
        $rows = mapi_table_queryallrows($reciptable, array(PR_RECIPIENT_TYPE, PR_DISPLAY_NAME, PR_ADDRTYPE, PR_EMAIL_ADDRESS, PR_SMTP_ADDRESS));

        foreach($rows as $row) {
            $address = "";
            $fulladdr = "";

            $addrtype = isset($row[PR_ADDRTYPE]) ? $row[PR_ADDRTYPE] : "";

            if(isset($row[PR_SMTP_ADDRESS]))
                $address = $row[PR_SMTP_ADDRESS];
            else if($addrtype == "SMTP" && isset($row[PR_EMAIL_ADDRESS]))
                $address = $row[PR_EMAIL_ADDRESS];

            $name = isset($row[PR_DISPLAY_NAME]) ? $row[PR_DISPLAY_NAME] : "";

            if($name == "" || $name == $address)
                $fulladdr = w2u($address);
            else {
                if (substr($name, 0, 1) != '"' && substr($name, -1) != '"') {
                    $fulladdr = "\"" . w2u($name) ."\" <" . w2u($address) . ">";
                }
                else {
                    $fulladdr = w2u($name) ."<" . w2u($address) . ">";
                }
            }

            if($row[PR_RECIPIENT_TYPE] == MAPI_TO) {
                array_push($to, $fulladdr);
            } else if($row[PR_RECIPIENT_TYPE] == MAPI_CC) {
                array_push($cc, $fulladdr);
            }
        }

        $message->to = implode(", ", $to);
        $message->cc = implode(", ", $cc);

        if (!isset($message->body) || strlen($message->body) == 0)
            $message->body = " ";

        if ($mimesupport == 2 && function_exists("mapi_inetmapi_imtoinet")) {
            $addrBook = mapi_openaddressbook($this->_session);
            $mstream = mapi_inetmapi_imtoinet($this->_session, $addrBook, $mapimessage, array());

            $mstreamstat = mapi_stream_stat($mstream);
            if ($mstreamstat['cb'] < MAX_EMBEDDED_SIZE) {
                $message->mimetruncated = 0;
                $mstreamcontent = mapi_stream_read($mstream, MAX_EMBEDDED_SIZE);
                $message->mimedata = $mstreamcontent;
                $message->mimesize = $mstreamstat["cb"];
                unset($message->body, $message->bodytruncated);
            }
        }

        return $message;
    }


    function getTruncSize($truncation) {
        switch($truncation) {
            case SYNC_TRUNCATION_HEADERS:
                return 0;
            case SYNC_TRUNCATION_512B:
                return 512;
            case SYNC_TRUNCATION_1K:
                return 1024;
            case SYNC_TRUNCATION_5K:
                return 5*1024;
            case SYNC_TRUNCATION_SEVEN:
            case SYNC_TRUNCATION_ALL:
                return 1024*1024; // We'll limit to 1MB anyway
            default:
                return 1024; // Default to 1Kb
        }
    }



    // ------------------- setter


    function GetTZOffset($ts)
    {
        $Offset = date("O", $ts);

        $Parity = $Offset < 0 ? -1 : 1;
        $Offset = $Parity * $Offset;
        $Offset = ($Offset - ($Offset % 100)) / 100 * 60 + $Offset % 100;

        return $Parity * $Offset;
    }

    function gmtime($time)
    {
        $TZOffset = $this->GetTZOffset($time);

        $t_time = $time - $TZOffset * 60; #Counter adjust for localtime()
        $t_arr = localtime($t_time, 1);

        return $t_arr;
    }

    function _setMessage($mapimessage, $message) {
        switch(strtolower(get_class($message))) {
            case "synccontact":
                return $this->_setContact($mapimessage, $message);
            case "syncappointment":
                return $this->_setAppointment($mapimessage, $message);
            case "synctask":
                return $this->_setTask($mapimessage, $message);
            default:
                return $this->_setEmail($mapimessage, $message); // In fact, this is unimplemented. It never happens. You can't save or modify an email from the PDA (except readflags)
        }
    }

    function _setAppointment($mapimessage, $appointment) {
        // MAPI stores months as the amount of minutes until the beginning of the month in a
        // non-leapyear. Why this is, is totally unclear.
        $monthminutes = array(0,44640,84960,129600,172800,217440,260640,305280,348480,393120,437760,480960);

        // Get timezone info
        if(isset($appointment->timezone))
            $tz = $this->_getTZFromSyncBlob(base64_decode($appointment->timezone));
        else
            $tz = false;

        //calculate duration because without it some webaccess views are broken. duration is in min
        $localstart = $this->_getLocaltimeByTZ($appointment->starttime, $tz);
        $localend = $this->_getLocaltimeByTZ($appointment->endtime, $tz);
        $duration = ($localend - $localstart)/60;

        //nokia sends an yearly event with 0 mins duration but as all day event,
        //so make it end next day
        if ($appointment->starttime == $appointment->endtime && isset($appointment->alldayevent) && $appointment->alldayevent) {
            $duration = 1440;
            $appointment->endtime = $appointment->starttime + 24 * 60 * 60;
            $localend = $localstart + 24 * 60 * 60;
        }

        // is the transmitted UID OL compatible?
        // if not, encapsulate the transmitted uid
        $appointment->uid = getOLUidFromICalUid($appointment->uid);

        mapi_setprops($mapimessage, array(PR_MESSAGE_CLASS => "IPM.Appointment"));

        $this->_setPropsInMAPI($mapimessage, $appointment, $this->_appointmentmapping);

        //we also have to set the responsestatus and not only meetingstatus, so we use another mapi tag
        if (isset($appointment->meetingstatus))
            mapi_setprops($mapimessage, array(
                $this->_getPropIDFromString("PT_LONG:{00062002-0000-0000-C000-000000000046}:0x8218") =>  $appointment->meetingstatus));

        //sensitivity is not enough to mark an appointment as private, so we use another mapi tag
        if (isset($appointment->sensitivity) && $appointment->sensitivity == 0) $private = false;
        else  $private = true;

        // Set commonstart/commonend to start/end and remindertime to start, duration, private and cleanGlobalObjectId
        mapi_setprops($mapimessage, array(
            $this->_getPropIDFromString("PT_SYSTIME:{00062008-0000-0000-C000-000000000046}:0x8516") =>  $appointment->starttime,
            $this->_getPropIDFromString("PT_SYSTIME:{00062008-0000-0000-C000-000000000046}:0x8517") =>  $appointment->endtime,
            $this->_getPropIDFromString("PT_SYSTIME:{00062008-0000-0000-C000-000000000046}:0x8502") =>  $appointment->starttime,
            $this->_getPropIDFromString("PT_LONG:{00062002-0000-0000-C000-000000000046}:0x8213") =>     $duration,
            $this->_getPropIDFromString("PT_BOOLEAN:{00062008-0000-0000-C000-000000000046}:0x8506") =>  $private,
            $this->_getPropIDFromString("PT_BINARY:{6ED8DA90-450B-101B-98DA-00AA003F1305}:0x23") =>     $appointment->uid,
            ));

        // Set named prop 8510, unknown property, but enables deleting a single occurrence of a recurring
        // type in OLK2003.
        mapi_setprops($mapimessage, array(
            $this->_getPropIDFromString("PT_LONG:{00062008-0000-0000-C000-000000000046}:0x8510") => 369));

        // Set reminder boolean to 'true' if reminder is set
        mapi_setprops($mapimessage, array(
            $this->_getPropIDFromString("PT_BOOLEAN:{00062008-0000-0000-C000-000000000046}:0x8503") => isset($appointment->reminder) ? true : false));

        if(isset($appointment->reminder) && $appointment->reminder > 0) {
            // Set 'flagdueby' to correct value (start - reminderminutes)
            mapi_setprops($mapimessage, array(
                $this->_getPropIDFromString("PT_SYSTIME:{00062008-0000-0000-C000-000000000046}:0x8560") => $appointment->starttime - $appointment->reminder));
        }

        if(isset($appointment->recurrence)) {
            // Set PR_ICON_INDEX to 1025 to show correct icon in category view
            mapi_setprops($mapimessage, array(PR_ICON_INDEX => 1025));

            $recurrence = new Recurrence($this->_store, $mapimessage);

            if(!isset($appointment->recurrence->interval))
                $appointment->recurrence->interval = 1;

            switch($appointment->recurrence->type) {
                case 0:
                    $recur["type"] = 10;
                    if(isset($appointment->recurrence->dayofweek))
                        $recur["subtype"] = 1;
                    else
                        $recur["subtype"] = 0;

                    $recur["everyn"] = $appointment->recurrence->interval * (60 * 24);
                    break;
                case 1:
                    $recur["type"] = 11;
                    $recur["subtype"] = 1;
                    $recur["everyn"] = $appointment->recurrence->interval;
                    break;
                case 2:
                    $recur["type"] = 12;
                    $recur["subtype"] = 2;
                    $recur["everyn"] = $appointment->recurrence->interval;
                    break;
                case 3:
                    $recur["type"] = 12;
                    $recur["subtype"] = 3;
                    $recur["everyn"] = $appointment->recurrence->interval;
                    break;
                case 4:
                    $recur["type"] = 13;
                    $recur["subtype"] = 1;
                    $recur["everyn"] = $appointment->recurrence->interval * 12;
                    break;
                case 5:
                    $recur["type"] = 13;
                    $recur["subtype"] = 2;
                    $recur["everyn"] = $appointment->recurrence->interval * 12;
                    break;
                case 6:
                    $recur["type"] = 13;
                    $recur["subtype"] = 3;
                    $recur["everyn"] = $appointment->recurrence->interval * 12;
                    break;
            }

            $starttime = $this->gmtime($localstart);
            $endtime = $this->gmtime($localend);

            $recur["startocc"] = $starttime["tm_hour"] * 60 + $starttime["tm_min"];
            $recur["endocc"] = $recur["startocc"] + $duration; // Note that this may be > 24*60 if multi-day

            // "start" and "end" are in GMT when passing to class.recurrence
            $recur["start"] = $this->_getDayStartOfTimestamp($this->_getGMTTimeByTz($localstart, $tz));
            $recur["end"] = $this->_getDayStartOfTimestamp(0x7fffffff); // Maximum GMT value for end by default

            if(isset($appointment->recurrence->until)) {
                $recur["term"] = 0x21;
                $recur["end"] = $appointment->recurrence->until;
            } else if(isset($appointment->recurrence->occurrences)) {
                $recur["term"] = 0x22;
                $recur["numoccur"] = $appointment->recurrence->occurrences;
            } else {
                $recur["term"] = 0x23;
            }

            if(isset($appointment->recurrence->dayofweek))
                $recur["weekdays"] = $appointment->recurrence->dayofweek;
            if(isset($appointment->recurrence->weekofmonth))
                $recur["nday"] = $appointment->recurrence->weekofmonth;
            if(isset($appointment->recurrence->monthofyear))
                $recur["month"] = $monthminutes[$appointment->recurrence->monthofyear-1];
            if(isset($appointment->recurrence->dayofmonth))
                $recur["monthday"] = $appointment->recurrence->dayofmonth;

            // Process exceptions. The PDA will send all exceptions for this recurring item.
            if(isset($appointment->exceptions)) {
                foreach($appointment->exceptions as $exception) {
                    // we always need the base date
                    if(!isset($exception->exceptionstarttime))
                        continue;

                    if(isset($exception->deleted) && $exception->deleted) {
                        // Delete exception
                        if(!isset($recur["deleted_occurences"]))
                            $recur["deleted_occurences"] = array();

                        array_push($recur["deleted_occurences"], $this->_getDayStartOfTimestamp($exception->exceptionstarttime));
                    } else {
                        // Change exception
                        $mapiexception = array("basedate" => $this->_getDayStartOfTimestamp($exception->exceptionstarttime));

                        if(isset($exception->starttime))
                            $mapiexception["start"] = $this->_getLocaltimeByTZ($exception->starttime, $tz);
                        if(isset($exception->endtime))
                            $mapiexception["end"] = $this->_getLocaltimeByTZ($exception->endtime, $tz);
                        if(isset($exception->subject))
                            $mapiexception["subject"] = u2w($exception->subject);
                        if(isset($exception->location))
                            $mapiexception["location"] = u2w($exception->location);
                        if(isset($exception->busystatus))
                            $mapiexception["busystatus"] = $exception->busystatus;
                        if(isset($exception->reminder)) {
                            $mapiexception["reminder_set"] = 1;
                            $mapiexception["remind_before"] = $exception->reminder;
                        }
                        if(isset($exception->alldayevent))
                            $mapiexception["alldayevent"] = $exception->alldayevent;

                        if(!isset($recur["changed_occurences"]))
                            $recur["changed_occurences"] = array();

                        array_push($recur["changed_occurences"], $mapiexception);

                    }
                }
            }

            $recurrence->setRecurrence($tz, $recur);

        }
        else {
            $isrecurringtag = $this->_getPropIDFromString("PT_BOOLEAN:{00062002-0000-0000-C000-000000000046}:0x8223");
            mapi_setprops($mapimessage, array($isrecurringtag => false));
        }

        // Do attendees
        if(isset($appointment->attendees) && is_array($appointment->attendees)) {
            $recips = array();

            foreach($appointment->attendees as $attendee) {
                $recip = array();
                $recip[PR_DISPLAY_NAME] = u2w($attendee->name);
                $recip[PR_EMAIL_ADDRESS] = u2w($attendee->email);
                $recip[PR_ADDRTYPE] = "SMTP";
                $recip[PR_RECIPIENT_TYPE] = MAPI_TO;
                $recip[PR_ENTRYID] = mapi_createoneoff($recip[PR_DISPLAY_NAME], $recip[PR_ADDRTYPE], $recip[PR_EMAIL_ADDRESS]);

                array_push($recips, $recip);
            }

            mapi_message_modifyrecipients($mapimessage, 0, $recips);
            mapi_setprops($mapimessage, array(
                PR_ICON_INDEX => 1026,
                $this->_getPropIDFromString("PT_BOOLEAN:{00062002-0000-0000-C000-000000000046}:0x8229") => true
                ));
        }
    }

    function _setContact($mapimessage, $contact) {
        mapi_setprops($mapimessage, array(PR_MESSAGE_CLASS => "IPM.Contact"));

        $this->_setPropsInMAPI($mapimessage, $contact, $this->_contactmapping);

        // Set display name and subject to a combined value of firstname and lastname
        $cname = (isset($contact->prefix))?u2w($contact->prefix)." ":"";
        $cname .= u2w($contact->firstname);
        $cname .= (isset($contact->middlename))?" ". u2w($contact->middlename):"";
        $cname .= " ". u2w($contact->lastname);
        $cname .= (isset($contact->suffix))?" ". u2w($contact->suffix):"";
        $cname = trim($cname);

        //set contact specific mapi properties
        $props = array();
        $nremails = array();
        $abprovidertype = 0;
        if (isset($contact->email1address)) {
            $nremails[] = 0;
            $abprovidertype |= 1;
            $props[$this->_getPropIDFromString("PT_BINARY:{00062004-0000-0000-C000-000000000046}:0x8085")] = mapi_createoneoff($cname, "SMTP", $contact->email1address); //emailentryid
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8080")] = "$cname ({$contact->email1address})"; //displayname
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8082")] = "SMTP"; //emailadresstype
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8084")] = $contact->email1address; //original emailaddress
        }

        if (isset($contact->email2address)) {
            $nremails[] = 1;
            $abprovidertype |= 2;
            $props[$this->_getPropIDFromString("PT_BINARY:{00062004-0000-0000-C000-000000000046}:0x8095")] = mapi_createoneoff($cname, "SMTP", $contact->email2address); //emailentryid
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8090")] = "$cname ({$contact->email2address})"; //displayname
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8092")] = "SMTP"; //emailadresstype
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8094")] = $contact->email2address; //original emailaddress
        }

        if (isset($contact->email3address)) {
            $nremails[] = 2;
            $abprovidertype |= 4;
            $props[$this->_getPropIDFromString("PT_BINARY:{00062004-0000-0000-C000-000000000046}:0x80A5")] = mapi_createoneoff($cname, "SMTP", $contact->email3address); //emailentryid
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x80A0")] = "$cname ({$contact->email3address})"; //displayname
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x80A2")] = "SMTP"; //emailadresstype
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x80A4")] = $contact->email3address; //original emailaddress
        }


        $props[$this->_getPropIDFromString("PT_LONG:{00062004-0000-0000-C000-000000000046}:0x8029")] = $abprovidertype;
        $props[PR_DISPLAY_NAME] = $cname;
        $props[PR_SUBJECT] = $cname;

        //pda multiple e-mail addresses bug fix for the contact
        if (!empty($nremails))
            $props[$this->_getPropIDFromString("PT_MV_LONG:{00062004-0000-0000-C000-000000000046}:0x8028")] = $nremails;

        //addresses' fix
        $homecity = $homecountry = $homepostalcode = $homestate = $homestreet = $homeaddress = "";
        if (isset($contact->homecity))
            $props[PR_HOME_ADDRESS_CITY] = $homecity = u2w($contact->homecity);
        if (isset($contact->homecountry))
            $props[PR_HOME_ADDRESS_COUNTRY] = $homecountry = u2w($contact->homecountry);
        if (isset($contact->homepostalcode))
            $props[PR_HOME_ADDRESS_POSTAL_CODE] = $homepostalcode = u2w($contact->homepostalcode);
        if (isset($contact->homestate))
            $props[PR_HOME_ADDRESS_STATE_OR_PROVINCE] = $homestate = u2w($contact->homestate);
        if (isset($contact->homestreet))
            $props[PR_HOME_ADDRESS_STREET] = $homestreet = u2w($contact->homestreet);
        $homeaddress = buildAddressString($homestreet, $homepostalcode, $homecity, $homestate, $homecountry);
        if ($homeaddress)
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x801A")] = $homeaddress;

        $businesscity = $businesscountry = $businesspostalcode = $businessstate = $businessstreet = $businessaddress = "";
        if (isset($contact->businesscity))
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8046")] = $businesscity = u2w($contact->businesscity);
        if (isset($contact->businesscountry))
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8049")] = $businesscountry = u2w($contact->businesscountry);
        if (isset($contact->businesspostalcode))
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8048")] = $businesspostalcode = u2w($contact->businesspostalcode);
        if (isset($contact->businessstate))
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8047")] = $businessstate = u2w($contact->businessstate);
        if (isset($contact->businessstreet))
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x8045")] = $businessstreet = u2w($contact->businessstreet);
        $businessaddress = buildAddressString($businessstreet, $businesspostalcode, $businesscity, $businessstate, $businesscountry);
        if ($businessaddress) $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x801B")] = $businessaddress;

        $othercity = $othercountry = $otherpostalcode = $otherstate = $otherstreet = $otheraddress = "";
        if (isset($contact->othercity))
            $props[PR_OTHER_ADDRESS_CITY] = $othercity = u2w($contact->othercity);
        if (isset($contact->othercountry))
            $props[PR_OTHER_ADDRESS_COUNTRY] = $othercountry = u2w($contact->othercountry);
        if (isset($contact->otherpostalcode))
            $props[PR_OTHER_ADDRESS_POSTAL_CODE] = $otherpostalcode = u2w($contact->otherpostalcode);
        if (isset($contact->otherstate))
            $props[PR_OTHER_ADDRESS_STATE_OR_PROVINCE] = $otherstate = u2w($contact->otherstate);
        if (isset($contact->otherstreet))
            $props[PR_OTHER_ADDRESS_STREET] = $otherstreet = u2w($contact->otherstreet);
        $otheraddress = buildAddressString($otherstreet, $otherpostalcode, $othercity, $otherstate, $othercountry);
        if ($otheraddress)
            $props[$this->_getPropIDFromString("PT_STRING8:{00062004-0000-0000-C000-000000000046}:0x801C")] = $otheraddress;

           $mailingadresstype = 0;

        if ($businessaddress) $mailingadresstype = 2;
        elseif ($homeaddress) $mailingadresstype = 1;
        elseif ($othercity) $mailingadresstype = 3;

        if ($mailingadresstype) {
            $props[$this->_getPropIDFromString("PT_LONG:{00062004-0000-0000-C000-000000000046}:0x8022")] = $mailingadresstype;

            switch ($mailingadresstype) {
                case 1:
                    $this->_setMailingAdress($homestreet, $homepostalcode, $homecity, $homestate, $homecountry, $homeaddress, $props);
                    break;
                case 2:
                    $this->_setMailingAdress($businessstreet, $businesspostalcode, $businesscity, $businessstate, $businesscountry, $businessaddress, $props);
                    break;
                case 3:
                    $this->_setMailingAdress($otherstreet, $otherpostalcode, $othercity, $otherstate, $othercountry, $otheraddress, $props);
                    break;
                }
            }

        if (isset($contact->picture)) {
            $picbinary = base64_decode($contact->picture);
            $picsize = strlen($picbinary);
            if ($picsize < MAX_EMBEDDED_SIZE) {
                //set the has picture property to true
                $haspic = $this->_getPropIDFromString("PT_BOOLEAN:{00062004-0000-0000-C000-000000000046}:0x8015");

                $props[$haspic] = false;

                //check if contact has already got a picture. delete it first in that case
                //delete it also if it was removed on a mobile
                $picprops = mapi_getprops($mapimessage, array($haspic));
                if (isset($picprops[$haspic]) && $picprops[$haspic]) {
                    writeLog(LOGLEVEL_DEBUG, "Contact already has a picture. Delete it");

                    $attachtable = mapi_message_getattachmenttable($mapimessage);
                    mapi_table_restrict($attachtable, getContactPicRestriction());
                    $rows = mapi_table_queryallrows($attachtable, array(PR_ATTACH_NUM));
                    if (isset($rows) && is_array($rows)) {
                        foreach ($rows as $row) {
                            mapi_message_deleteattach($mapimessage, $row[PR_ATTACH_NUM]);
                        }
                    }
                }

                //only set picture if there's data in the request
                if ($picbinary !== false && $picsize > 0) {
                    $props[$haspic] = true;
                    $pic = mapi_message_createattach($mapimessage);
                    // Set properties of the attachment
                    $picprops = array(
                        PR_ATTACH_LONG_FILENAME_A => "ContactPicture.jpg",
                        PR_DISPLAY_NAME => "ContactPicture.jpg",
                        0x7FFF000B => true,
                        PR_ATTACHMENT_HIDDEN => false,
                        PR_ATTACHMENT_FLAGS => 1,
                        PR_ATTACH_METHOD => ATTACH_BY_VALUE,
                        PR_ATTACH_EXTENSION_A => ".jpg",
                        PR_ATTACH_NUM => 1,
                        PR_ATTACH_SIZE => $picsize,
                        PR_ATTACH_DATA_BIN => $picbinary,
                    );

                    mapi_setprops($pic, $picprops);
                    mapi_savechanges($pic);
                }
            }
        }

        mapi_setprops($mapimessage, $props);
    }

    function _setTask($mapimessage, $task) {
        mapi_setprops($mapimessage, array(PR_MESSAGE_CLASS => "IPM.Task"));

        $this->_setPropsInMAPI($mapimessage, $task, $this->_taskmapping);

        if(isset($task->complete)) {
            if($task->complete) {
                // Set completion to 100%
                // Set status to 'complete'
                mapi_setprops($mapimessage, array (
                    $this->_getPropIDFromString("PT_DOUBLE:{00062003-0000-0000-C000-000000000046}:0x8102") => 1.0,
                    $this->_getPropIDFromString("PT_LONG:{00062003-0000-0000-C000-000000000046}:0x8101") => 2 )
                );
            } else {
                // Set completion to 0%
                // Set status to 'not started'
                mapi_setprops($mapimessage, array (
                    $this->_getPropIDFromString("PT_DOUBLE:{00062003-0000-0000-C000-000000000046}:0x8102") => 0.0,
                    $this->_getPropIDFromString("PT_LONG:{00062003-0000-0000-C000-000000000046}:0x8101") => 0 )
                );
            }
        }
    }

    function _setMailingAdress($street, $zip, $city, $state, $country, $address, &$props) {
        $props[PR_STREET_ADDRESS] = $street;
        $props[PR_LOCALITY] = $city;
        $props[PR_COUNTRY] = $country;
        $props[PR_POSTAL_CODE] = $zip;
        $props[PR_STATE_OR_PROVINCE] = $state;
        $props[PR_POSTAL_ADDRESS] = $address;
    }

}

?>