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

class MAPIProvider {
    private $session;
    private $store;

    /**
     * Constructor of the MAPI Provider
     * Almost all methods of this class require a MAPI session and/or store
     *
     * @param ressource         $session
     * @param ressource         $store
     *
     * @access public
     */
    function MAPIProvider($session, $store) {
        $this->session = $session;
        $this->store = $store;
    }


    /**----------------------------------------------------------------------------------------------------------
     * GETTER
     */

    /**
     * Reads a message from MAPI
     * Depending on the message class, a contact, appointment, task or email is read
     *
     * @param mixed             $mapimessage
     * @param int               $truncflag
     * @param int               $mimesupport
     *
     * // TODO parameters might be refactored into an own class, as more options will be necessary
     * @access public
     * @return SyncObject
     */
    public function GetMessage($mapimessage, $truncflag, $mimesupport = 0) {
        // Gets the Sync object from a MAPI object according to its message class

        $truncsize = Utils::GetTruncSize($truncflag);
        $props = mapi_getprops($mapimessage, array(PR_MESSAGE_CLASS));
        if(isset($props[PR_MESSAGE_CLASS]))
            $messageclass = $props[PR_MESSAGE_CLASS];
        else
            $messageclass = "IPM";

        if(strpos($messageclass,"IPM.Contact") === 0)
            return $this->getContact($mapimessage, $truncsize, $mimesupport);
        else if(strpos($messageclass,"IPM.Appointment") === 0)
            return $this->getAppointment($mapimessage, $truncsize, $mimesupport);
        else if(strpos($messageclass,"IPM.Task") === 0)
            return $this->getTask($mapimessage, $truncsize, $mimesupport);
        else
            return $this->getEmail($mapimessage, $truncsize, $mimesupport);
    }

    /**
     * Reads a contact object from MAPI
     *
     * @param mixed             $mapimessage
     * @param int               $truncflag
     * @param int               $mimesupport    (opt)
     *
     * // TODO parameters might be refactored into an own class, as more options will be necessary
     * @access private
     * @return SyncContact
     */
    private function getContact($mapimessage, $truncsize, $mimesupport = 0) {
        $message = new SyncContact();

        // Standard one-to-one mappings first
        $this->getPropsFromMAPI($message, $mapimessage, MAPIMapping::GetContactMapping());

        // Contact specific props
        $contactproperties = MAPIMapping::GetContactProperties();
        $messageprops = $this->getProps($mapimessage, $contactproperties);

        //check the picture
        if (isset($messageprops[$contactproperties["haspic"]]) && $messageprops[$contactproperties["haspic"]]) {
            // Add attachments
            $attachtable = mapi_message_getattachmenttable($mapimessage);
            mapi_table_restrict($attachtable, MAPIUtils::GetContactPicRestriction());
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

    /**
     * Reads a task object from MAPI
     *
     * @param mixed             $mapimessage
     * @param int               $truncflag
     * @param int               $mimesupport    (opt)
     *
     * // TODO parameters might be refactored into an own class, as more options will be necessary
     * @access private
     * @return SyncTask
     */
    private function getTask($mapimessage, $truncsize, $mimesupport = 0) {
        $message = new SyncTask();

        // Standard one-to-one mappings first
        $this->getPropsFromMAPI($message, $mapimessage, MAPIMapping::GetTaskMapping());

        // Task specific props
        $taskproperties = MAPIMapping::GetTaskProperties();
        $messageprops = $this->getProps($mapimessage, $taskproperties);

        //task with deadoccur is an occurrence of a recurring task and does not need to be handled as recurring
        //webaccess does not set deadoccur for the initial recurring task
        if(isset($messageprops[$taskproperties["isrecurringtag"]]) &&
            $messageprops[$taskproperties["isrecurringtag"]] &&
            (!isset($messageprops[$taskproperties["deadoccur"]]) ||
            (isset($messageprops[$taskproperties["deadoccur"]]) &&
            !$messageprops[$taskproperties["deadoccur"]]))) {
            // Process recurrence
            $message->recurrence = new SyncTaskRecurrence();
            $this->getRecurrence($mapimessage, $messageprops, $message, $message->recurrence, false);
        }

        // when set the task to complete using the WebAccess, the dateComplete property is not set correctly
        if ($message->complete == 1 && !isset($message->datecompleted))
            $message->datecompleted = time();

        return $message;
    }

    /**
     * Reads an appointment object from MAPI
     *
     * @param mixed             $mapimessage
     * @param int               $truncflag
     * @param int               $mimesupport    (opt)
     *
     * // TODO parameters might be refactored into an own class, as more options will be necessary
     * @access private
     * @return SyncAppointment
     */
    private function getAppointment($mapimessage, $truncsize, $mimesupport = 0) {
        $message = new SyncAppointment();

        // Standard one-to-one mappings first
        $this->getPropsFromMAPI($message, $mapimessage, MAPIMapping::GetAppointmentMapping());

        // Appointment specific props
        $appointmentprops = MAPIMapping::GetAppointmentProperties();
        $messageprops = $this->getProps($mapimessage, $appointmentprops);

        // Disable reminder if it is off
        if(!isset($messageprops[$appointmentprops["reminderset"]]) || $messageprops[$appointmentprops["reminderset"]] == false)
            $message->reminder = "";
        else {
            if ($messageprops[$appointmentprops["remindertime"]] == 0x5AE980E1)
                $message->reminder = 15;
            else
                $message->reminder = $messageprops[$appointmentprops["remindertime"]];
        }

        if(!isset($message->uid))
            $message->uid = bin2hex($messageprops[$appointmentprops["sourcekey"]]);
        else
            $message->uid = Utils::GetICalUidFromOLUid($message->uid);

        // Get organizer information if it is a meetingrequest
        if(isset($messageprops[$appointmentprops["meetingstatus"]]) &&
            $messageprops[$appointmentprops["meetingstatus"]] > 0 &&
            isset($messageprops[$appointmentprops["representingentryid"]]) &&
            isset($messageprops[$appointmentprops["representingname"]])) {

            $message->organizeremail = w2u($this->getSMTPAddressFromEntryID($messageprops[$appointmentprops["representingentryid"]]));
            $message->organizername = w2u($messageprops[$appointmentprops["representingname"]]);
        }

        if(isset($messageprops[$appointmentprops["timezonetag"]]))
            $tz = $this->getTZFromMAPIBlob($messageprops[$appointmentprops["timezonetag"]]);
        else
            $tz = $this->getGMTTZ();

        $message->timezone = base64_encode($this->getSyncBlobFromTZ($tz));

        if(isset($messageprops[$appointmentprops["isrecurring"]]) && $messageprops[$appointmentprops["isrecurring"]]) {
            // Process recurrence
            $message->recurrence = new SyncRecurrence();
            $this->getRecurrence($mapimessage, $messageprops, $message, $message->recurrence, $tz);
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
                    $userinfo = mapi_zarafa_getuser_by_name($this->store, $row[PR_EMAIL_ADDRESS]);
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

    /**
     * Reads recurrence information from MAPI
     *
     * @param mixed             $mapimessage
     * @param array             $recurprops
     * @param SyncObject        &$syncMessage       the message
     * @param SyncObject        &$syncRecurrence    the  recurrene message
     * @param array             $tz                 timezone information
     *
     * @access private
     * @return
     */
    private function getRecurrence($mapimessage, $recurprops, &$syncMessage, &$syncRecurrence, $tz) {
        if (class_exists('TaskRecurrence') && $syncRecurrence instanceof SyncTaskRecurrence)
            $recurrence = new TaskRecurrence($this->store, $recurprops);
        else
            $recurrence = new Recurrence($this->store, $recurprops);

//TODO formatting
        switch($recurrence->recur["type"]) {
            case 10: // daily
                switch($recurrence->recur["subtype"]) {
                    default:
                    $syncRecurrence->type = 0;
                        break;
                    case 1:
                    $syncRecurrence->type = 0;
                    $syncRecurrence->dayofweek = 62; // mon-fri
                    $syncRecurrence->interval = 1;
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
                $syncRecurrence->until = $recurrence->recur["end"];
                // fixes Mantis #350 : recur-end does not consider timezones - use ClipEnd if available
                if (isset($recurprops[$recurrence->proptags["enddate_recurring"]]))
                    $syncRecurrence->until = $recurprops[$recurrence->proptags["enddate_recurring"]];
                // add one day (minus 1 sec) to the end time to make sure the last occurrence is covered
                $syncRecurrence->until += 86399;
                break;
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
                $exception->starttime = $this->getGMTTimeByTZ($change["start"], $tz);
            if(isset($change["end"]))
                $exception->endtime = $this->getGMTTimeByTZ($change["end"], $tz);
            if(isset($change["basedate"]))
                $exception->exceptionstarttime = $this->getGMTTimeByTZ($this->getDayStartOfTimestamp($change["basedate"]) + $recurrence->recur["startocc"] * 60, $tz);
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

            $exception->exceptionstarttime = $this->getGMTTimeByTZ($this->getDayStartOfTimestamp($deleted) + $recurrence->recur["startocc"] * 60, $tz);
            $exception->deleted = "1";

            if(!isset($syncMessage->exceptions))
                $syncMessage->exceptions = array();

            array_push($syncMessage->exceptions, $exception);
        }

        if (isset($syncMessage->complete) && $syncMessage->complete) {
            $syncRecurrence->complete = $syncMessage->complete;
        }
    }

    /**
     * Reads an email object from MAPI
     *
     * @param mixed             $mapimessage
     * @param int               $truncflag
     * @param int               $mimesupport    (opt)
     *
     * // TODO parameters might be refactored into an own class, as more options will be necessary
     * @access private
     * @return SyncEmail
     */
    private function getEmail($mapimessage, $truncsize, $mimesupport = 0) {
        $message = new SyncMail();

        $this->getPropsFromMAPI($message, $mapimessage, MAPIMapping::GetEmailMapping());

        $emailproperties = MAPIMapping::GetEmailProperties();
        $messageprops = $this->getProps($mapimessage, $emailproperties);

        // Override 'body' for truncation
        $body = mapi_openproperty($mapimessage, PR_BODY);
        if(strlen($body) > $truncsize) {
            $body = Utils::Utf8_truncate($body, $truncsize);
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

        if(isset($messageprops[$emailproperties["representingname"]]))
            $fromname = $messageprops[$emailproperties["representingname"]];
        if(isset($messageprops[$emailproperties["representingentryid"]]))
            $fromaddr = $this->getSMTPAddressFromEntryID($messageprops[$emailproperties["representingentryid"]]);

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
            $this->getPropsFromMAPI($message->meetingrequest, $mapimessage, MAPIMapping::GetMeetingRequestMapping());

            $meetingrequestproperties = MAPIMapping::GetMeetingRequestProperties();
            $props = $this->getProps($mapimessage, $meetingrequestproperties);

            // Get the GOID
            if(isset($props[$meetingrequestproperties["goidtag"]]))
                $message->meetingrequest->globalobjid = base64_encode($props[$meetingrequestproperties["goidtag"]]);

            // Set Timezone
            if(isset($props[$meetingrequestproperties["timezonetag"]]))
                $tz = $this->getTZFromMAPIBlob($props[$meetingrequestproperties["timezonetag"]]);
            else
                $tz = $this->getGMTTZ();

            $message->meetingrequest->timezone = base64_encode($this->getSyncBlobFromTZ($tz));

            // send basedate if exception
            if(isset($props[$meetingrequestproperties["recReplTime"]]) ||
                (isset($props[$meetingrequestproperties["lidIsException"]]) && $props[$meetingrequestproperties["lidIsException"]] == true)) {
                if (isset($props[$meetingrequestproperties["recReplTime"]])){
                    $basedate = $props[$meetingrequestproperties["recReplTime"]];
                    $message->meetingrequest->recurrenceid = $this->getGMTTimeByTZ($basedate, $this->getGMTTZ());
                }
                else {
                    if (!isset($props[$meetingrequestproperties["goidtag"]]) || !isset($props[$meetingrequestproperties["recurStartTime"]]) || !isset($props[$meetingrequestproperties["timezonetag"]]))
                        ZLog::Write(LOGLEVEL_WARN, "Missing property to set correct basedate for exception");
                    else {
                        $basedate = Utils::ExtractBaseDate($props[$meetingrequestproperties["goidtag"]], $props[$meetingrequestproperties["recurStartTime"]]);
                        $message->meetingrequest->recurrenceid = $this->getGMTTimeByTZ($basedate, $tz);
                    }
                }
            }

            // Organizer is the sender
            $message->meetingrequest->organizer = $message->from;

            // Process recurrence
            if(isset($props[$meetingrequestproperties["isrecurringtag"]]) && $props[$meetingrequestproperties["isrecurringtag"]]) {
                $myrec = new SyncMeetingRequestRecurrence();
                // get recurrence -> put $message->meetingrequest as message so the 'alldayevent' is set correctly
                $this->getRecurrence($mapimessage, $props, $message->meetingrequest, $myrec, $tz);
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
            if (isset($props[$meetingrequestproperties["isrecurringtag"]]) && $props[$meetingrequestproperties["isrecurringtag"]] == 1)
                $message->meetingrequest->instancetype = 1;
            else if ((!isset($props[$meetingrequestproperties["isrecurringtag"]]) || $props[$meetingrequestproperties["isrecurringtag"]] == 0 )&& isset($message->meetingrequest->recurrenceid))
                if (isset($props[$meetingrequestproperties["appSeqNr"]]) && $props[$meetingrequestproperties["appSeqNr"]] == 0 )
                    $message->meetingrequest->instancetype = 2;
                else
                    $message->meetingrequest->instancetype = 3;

            // Disable reminder if it is off
            if(!isset($props[$meetingrequestproperties["reminderset"]]) || $props[$meetingrequestproperties["reminderset"]] == false)
                $message->meetingrequest->reminder = "";
            //the property saves reminder in minutes, but we need it in secs
            else {
                ///set the default reminder time to seconds
                if ($props[$meetingrequestproperties["remindertime"]] == 0x5AE980E1)
                    $message->meetingrequest->reminder = 900;
                else
                    $message->meetingrequest->reminder = $props[$meetingrequestproperties["remindertime"]] * 60;
            }

            // Set sensitivity to 0 if missing
            if(!isset($message->meetingrequest->sensitivity))
                $message->meetingrequest->sensitivity = 0;
        }

        // Add attachments
        $attachtable = mapi_message_getattachmenttable($mapimessage);
        $rows = mapi_table_queryallrows($attachtable, array(PR_ATTACH_NUM));
        $entryid = bin2hex($messageprops[$emailproperties["entryid"]]);

        foreach($rows as $row) {
            if(isset($row[PR_ATTACH_NUM])) {
                $mapiattach = mapi_message_openattach($mapimessage, $row[PR_ATTACH_NUM]);

                $attachprops = mapi_getprops($mapiattach, array(PR_ATTACH_LONG_FILENAME, PR_ATTACH_FILENAME));

                $attach = new SyncAttachment();

                $stream = mapi_openpropertytostream($mapiattach, PR_ATTACH_DATA_BIN);
                if($stream) {
                    $stat = mapi_stream_stat($stream);

                    $attach->attsize = $stat["cb"];
                    $attach->displayname = w2u((isset($attachprops[PR_ATTACH_LONG_FILENAME]))?$attachprops[PR_ATTACH_LONG_FILENAME]:((isset($attachprops[PR_ATTACH_FILENAME]))?$attachprops[PR_ATTACH_FILENAME]:"attachment.bin"));
                    $attach->attname = $entryid.":".$row[PR_ATTACH_NUM];

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
            $addrBook = mapi_openaddressbook($this->session);
            $mstream = mapi_inetmapi_imtoinet($this->session, $addrBook, $mapimessage, array());

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

    /**
     * Reads a folder object from MAPI
     *
     * @param mixed             $mapimessage
     *
     * @access public
     * @return SyncFolder
     */
    public function GetFolder($mapifolder) {
        $folder = new SyncFolder();

        $folderprops = mapi_getprops($mapifolder, array(PR_DISPLAY_NAME, PR_PARENT_ENTRYID, PR_SOURCE_KEY, PR_PARENT_SOURCE_KEY, PR_ENTRYID, PR_CONTAINER_CLASS));
        $storeprops = mapi_getprops($this->store, array(PR_IPM_SUBTREE_ENTRYID));

        if(!isset($folderprops[PR_DISPLAY_NAME]) ||
           !isset($folderprops[PR_PARENT_ENTRYID]) ||
           !isset($folderprops[PR_SOURCE_KEY]) ||
           !isset($folderprops[PR_ENTRYID]) ||
           !isset($folderprops[PR_PARENT_SOURCE_KEY]) ||
           !isset($storeprops[PR_IPM_SUBTREE_ENTRYID])) {
            ZLog::Write(LOGLEVEL_ERROR, "Missing properties on folder");
            return false;
        }

        $folder->serverid = bin2hex($folderprops[PR_SOURCE_KEY]);
        if($folderprops[PR_PARENT_ENTRYID] == $storeprops[PR_IPM_SUBTREE_ENTRYID])
            $folder->parentid = "0";
        else
            $folder->parentid = bin2hex($folderprops[PR_PARENT_SOURCE_KEY]);
        $folder->displayname = w2u($folderprops[PR_DISPLAY_NAME]);
        $folder->type = $this->getFolderType($folderprops[PR_ENTRYID], isset($folderprops[PR_CONTAINER_CLASS])?$folderprops[PR_CONTAINER_CLASS]:false);

        return $folder;
    }

    /**
     * Returns the foldertype for an entryid
     * Gets the folder type by checking the default folders in MAPI
     *
     * @param string            $entryid
     * @param string            $class      (opt)
     *
     * @access private
     * @return long
     */
    private function getFolderType($entryid, $class = false) {
        $storeprops = mapi_getprops($this->store, array(PR_IPM_OUTBOX_ENTRYID, PR_IPM_WASTEBASKET_ENTRYID, PR_IPM_SENTMAIL_ENTRYID));
        $inbox = mapi_msgstore_getreceivefolder($this->store);
        $inboxprops = mapi_getprops($inbox, array(PR_ENTRYID, PR_IPM_DRAFTS_ENTRYID, PR_IPM_TASK_ENTRYID, PR_IPM_APPOINTMENT_ENTRYID, PR_IPM_CONTACT_ENTRYID, PR_IPM_NOTE_ENTRYID, PR_IPM_JOURNAL_ENTRYID));

        if($entryid == $inboxprops[PR_ENTRYID])
            return SYNC_FOLDER_TYPE_INBOX;
        if($entryid == $inboxprops[PR_IPM_DRAFTS_ENTRYID])
            return SYNC_FOLDER_TYPE_DRAFTS;
        if($entryid == $storeprops[PR_IPM_WASTEBASKET_ENTRYID])
            return SYNC_FOLDER_TYPE_WASTEBASKET;
        if($entryid == $storeprops[PR_IPM_SENTMAIL_ENTRYID])
            return SYNC_FOLDER_TYPE_SENTMAIL;
        if($entryid == $storeprops[PR_IPM_OUTBOX_ENTRYID])
            return SYNC_FOLDER_TYPE_OUTBOX;
        if($entryid == $inboxprops[PR_IPM_TASK_ENTRYID])
            return SYNC_FOLDER_TYPE_TASK;
        if($entryid == $inboxprops[PR_IPM_APPOINTMENT_ENTRYID])
            return SYNC_FOLDER_TYPE_APPOINTMENT;
        if($entryid == $inboxprops[PR_IPM_CONTACT_ENTRYID])
            return SYNC_FOLDER_TYPE_CONTACT;
        if($entryid == $inboxprops[PR_IPM_NOTE_ENTRYID])
            return SYNC_FOLDER_TYPE_NOTE;
        if($entryid == $inboxprops[PR_IPM_JOURNAL_ENTRYID])
            return SYNC_FOLDER_TYPE_JOURNAL;

        // user created folders
        if ($class == "IPF.Note")
            return SYNC_FOLDER_TYPE_USER_MAIL;
        if ($class == "IPF.Task")
            return SYNC_FOLDER_TYPE_USER_TASK;
        if ($class == "IPF.Appointment")
            return SYNC_FOLDER_TYPE_USER_APPOINTMENT;
        if ($class == "IPF.Contact")
            return SYNC_FOLDER_TYPE_USER_CONTACT;
        if ($class == "IPF.StickyNote")
            return SYNC_FOLDER_TYPE_USER_NOTE;
        if ($class == "IPF.Journal")
            return  SYNC_FOLDER_TYPE_USER_JOURNAL;

        return SYNC_FOLDER_TYPE_OTHER;
    }


    /**----------------------------------------------------------------------------------------------------------
     * SETTER
     */

    /**
     * Writes a SyncObject to MAPI
     * Depending on the message class, a contact, appointment, task or email is written
     *
     * @param mixed             $mapimessage
     * @param SyncObject        $message
     *
     * @access public
     * @return boolean
     */
    public function SetMessage($mapimessage, $message) {
        // TODO check with instanceof
        switch(strtolower(get_class($message))) {
            case "synccontact":
                return $this->setContact($mapimessage, $message);
            case "syncappointment":
                return $this->setAppointment($mapimessage, $message);
            case "synctask":
                return $this->setTask($mapimessage, $message);
            default:
                ZLog::Write(LOGLEVEL_ERROR, "Not possible to save message of type: ". get_class($message));
                return false;
                // TODO setEmail is not implemented
                return $this->setEmail($mapimessage, $message); // In fact, this is unimplemented. It never happens. You can't save or modify an email from the PDA (except readflags)
        }
    }

    /**
     * Writes a SyncAppointment to MAPI
     *
     * @param mixed             $mapimessage
     * @param SyncAppointment   $message
     *
     * @access private
     * @return boolean
     */
    private function setAppointment($mapimessage, $appointment) {
        // Get timezone info
        if(isset($appointment->timezone))
            $tz = $this->getTZFromSyncBlob(base64_decode($appointment->timezone));
        else
            $tz = false;

        //calculate duration because without it some webaccess views are broken. duration is in min
        $localstart = $this->getLocaltimeByTZ($appointment->starttime, $tz);
        $localend = $this->getLocaltimeByTZ($appointment->endtime, $tz);
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
        $appointment->uid = Utils::GetOLUidFromICalUid($appointment->uid);

        mapi_setprops($mapimessage, array(PR_MESSAGE_CLASS => "IPM.Appointment"));

        $this->setPropsInMAPI($mapimessage, $appointment, MAPIMapping::GetAppointmentMapping());
        $appointmentprops = MAPIMapping::GetAppointmentProperties();
        $appointmentprops = $this->getPropIdsFromStrings($appointmentprops);
        //appointment specific properties to be set
        $props = array();

        //we also have to set the responsestatus and not only meetingstatus, so we use another mapi tag
        if (isset($appointment->meetingstatus)) $props[$appointmentprops["meetingstatus"]] = $appointment->meetingstatus;

        //sensitivity is not enough to mark an appointment as private, so we use another mapi tag
        $private = (isset($appointment->sensitivity) && $appointment->sensitivity == 0) ? false : true;

        // Set commonstart/commonend to start/end and remindertime to start, duration, private and cleanGlobalObjectId
        $props[$appointmentprops["commonstart"]] = $appointment->starttime;
        $props[$appointmentprops["commonend"]] = $appointment->endtime;
        $props[$appointmentprops["reminderstart"]] = $appointment->starttime;
        // Set reminder boolean to 'true' if reminder is set
        $props[$appointmentprops["reminderset"]] = isset($appointment->reminder) ? true : false;
        $props[$appointmentprops["duration"]] = $duration;
        $props[$appointmentprops["private"]] = $private;
        $props[$appointmentprops["uid"]] = $appointment->uid;
        // Set named prop 8510, unknown property, but enables deleting a single occurrence of a recurring
        // type in OLK2003.
        $props[$appointmentprops["sideeffects"]] = 369;


        if(isset($appointment->reminder) && $appointment->reminder > 0) {
            // Set 'flagdueby' to correct value (start - reminderminutes)
            $props[$appointmentprops["flagdueby"]] = $appointment->starttime - $appointment->reminder * 60;
        }

        if(isset($appointment->recurrence)) {
            // Set PR_ICON_INDEX to 1025 to show correct icon in category view
            $props[$appointmentprops["icon"]] = 1025;

            $recurrence = new Recurrence($this->store, $mapimessage);
            $recur = array();
            $this->setRecurrence($appointment, $recur);

            $starttime = $this->gmtime($localstart);
            $endtime = $this->gmtime($localend);

            //set recurrence start here because it's calculated differently for tasks and appointments
            $recur["start"] = $this->getDayStartOfTimestamp($this->getGMTTimeByTZ($localstart, $tz));

            $recur["startocc"] = $starttime["tm_hour"] * 60 + $starttime["tm_min"];
            $recur["endocc"] = $recur["startocc"] + $duration; // Note that this may be > 24*60 if multi-day

            //only tasks can regenerate
            $recur["regen"] = false;

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

                        array_push($recur["deleted_occurences"], $this->getDayStartOfTimestamp($exception->exceptionstarttime));
                    } else {
                        // Change exception
                        $mapiexception = array("basedate" => $this->getDayStartOfTimestamp($exception->exceptionstarttime));

                        if(isset($exception->starttime))
                            $mapiexception["start"] = $this->getLocaltimeByTZ($exception->starttime, $tz);
                        if(isset($exception->endtime))
                            $mapiexception["end"] = $this->getLocaltimeByTZ($exception->endtime, $tz);
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
            $props[$appointmentprops["isrecurring"]] = false;
        }

        // Do attendees
        if(isset($appointment->attendees) && is_array($appointment->attendees)) {
            $recips = array();

            //open addresss book for user resolve
            $addrbook = mapi_openaddressbook($this->session);
            foreach($appointment->attendees as $attendee) {
                $recip = array();
                $recip[PR_EMAIL_ADDRESS] = u2w($attendee->email);

                // lookup information in GAB if possible so we have up-to-date name for given address
                $userinfo = array( array( PR_DISPLAY_NAME => $recip[PR_EMAIL_ADDRESS] ) );
                $userinfo = mapi_ab_resolvename($addrbook, $userinfo, EMS_AB_ADDRESS_LOOKUP);
                if(mapi_last_hresult() == NOERROR) {
                    $recip[PR_DISPLAY_NAME] = $userinfo[0][PR_DISPLAY_NAME];
                    $recip[PR_EMAIL_ADDRESS] = $userinfo[0][PR_EMAIL_ADDRESS];
                    $recip[PR_SEARCH_KEY] = $userinfo[0][PR_SEARCH_KEY];
                    $recip[PR_ADDRTYPE] = $userinfo[0][PR_ADDRTYPE];
                    $recip[PR_ENTRYID] = $userinfo[0][PR_ENTRYID];
                    $recip[PR_RECIPIENT_TYPE] = MAPI_TO;
                }
                else {
                    $recip[PR_DISPLAY_NAME] = u2w($attendee->name);
                    $recip[PR_SEARCH_KEY] = $recip[PR_EMAIL_ADDRESS];
                    $recip[PR_ADDRTYPE] = "SMTP";
                    $recip[PR_RECIPIENT_TYPE] = MAPI_TO;
                    $recip[PR_ENTRYID] = mapi_createoneoff($recip[PR_DISPLAY_NAME], $recip[PR_ADDRTYPE], $recip[PR_EMAIL_ADDRESS]);
                }

                array_push($recips, $recip);
            }

            mapi_message_modifyrecipients($mapimessage, 0, $recips);
            $props[$appointmentprops["icon"]] = 1026;
            $props[$appointmentprops["mrwassent"]] = true;
        }
        mapi_setprops($mapimessage, $props);
    }

    /**
     * Writes a SyncContact to MAPI
     *
     * @param mixed             $mapimessage
     * @param SyncContact       $contact
     *
     * @access private
     * @return boolean
     */
    private function setContact($mapimessage, $contact) {
        mapi_setprops($mapimessage, array(PR_MESSAGE_CLASS => "IPM.Contact"));

        $contactmapping = MAPIMapping::GetContactMapping();
        $contactprops = MAPIMapping::GetContactProperties();
        $this->setPropsInMAPI($mapimessage, $contact, $contactmapping);

        ///set display name from contact's properties
        $cname = $this->composeDisplayName($contact);

        //get contact specific mapi properties and merge them with the AS properties
        $contactprops = array_merge($this->getPropIdsFromStrings($contactmapping), $this->getPropIdsFromStrings($contactprops));

        //contact specific properties to be set
        $props = array();

        //need to be set in order to show contacts properly in outlook and wa
        $nremails = array();
        $abprovidertype = 0;

        $this->setEmailAddress($contact->email1address, $cname, 1, $props, $contactprops, $nremails, $abprovidertype);
        $this->setEmailAddress($contact->email2address, $cname, 2, $props, $contactprops, $nremails, $abprovidertype);
        $this->setEmailAddress($contact->email3address, $cname, 3, $props, $contactprops, $nremails, $abprovidertype);

        $props[$contactprops["addressbooklong"]] = $abprovidertype;
        $props[$contactprops["displayname"]] = $props[$contactprops["subject"]] = $cname;

        //pda multiple e-mail addresses bug fix for the contact
        if (!empty($nremails)) $props[$contactprops["addressbookmv"]] = $nremails;


        //set addresses
        $this->setAddress("home", u2w($contact->homecity), u2w($contact->homecountry), u2w($contact->homepostalcode), u2w($contact->homestate), u2w($contact->homestreet), $props, $contactprops);
        $this->setAddress("business", u2w($contact->businesscity), u2w($contact->businesscountry), u2w($contact->businesspostalcode), u2w($contact->businessstate), u2w($contact->businessstreet), $props, $contactprops);
        $this->setAddress("other", u2w($contact->othercity), u2w($contact->othercountry), u2w($contact->otherpostalcode), u2w($contact->otherstate), u2w($contact->otherstreet), $props, $contactprops);

//TODO change mailing address handling
        //set the mailing address and its type
        if (isset($props[$contactprops["businessaddress"]])) {
            $props[$contactprops["mailingaddress"]] = 2;
            $this->setMailingAddress($props[$contactprops["businesscity"]], $props[$contactprops["businesscountry"]], $props[$contactprops["businesspostalcode"]], $props[$contactprops["businessstate"]], $props[$contactprops["businessstreet"]], $props[$contactprops["businessaddress"]], $props, $contactprops);
        }
        elseif (isset($props[$contactprops["homeaddress"]])) {
            $props[$contactprops["mailingaddress"]] = 1;
            $this->setMailingAddress($props[$contactprops["homecity"]], $props[$contactprops["homecountry"]], $props[$contactprops["homepostalcode"]], $props[$contactprops["homestate"]], $props[$contactprops["homestreet"]], $props[$contactprops["homeaddress"]], $props, $contactprops);
        }
        elseif (isset($props[$contactprops["otheraddress"]])) {
            $props[$contactprops["mailingaddress"]] = 3;
            $this->setMailingAddress($props[$contactprops["othercity"]], $props[$contactprops["othercountry"]], $props[$contactprops["otherpostalcode"]], $props[$contactprops["otherstate"]], $props[$contactprops["otherstreet"]], $props[$contactprops["otheraddress"]], $props, $contactprops);
        }

        if (isset($contact->picture)) {
            $picbinary = base64_decode($contact->picture);
            $picsize = strlen($picbinary);
            if ($picsize < MAX_EMBEDDED_SIZE) {
                $props[$contactprops["haspic"]] = false;

                // TODO contact picture handling
                // check if contact has already got a picture. delete it first in that case
                // delete it also if it was removed on a mobile
                $picprops = mapi_getprops($mapimessage, array($props[$contactprops["haspic"]]));
                if (isset($picprops[$props[$contactprops["haspic"]]]) && $picprops[$props[$contactprops["haspic"]]]) {
                    ZLog::Write(LOGLEVEL_DEBUG, "Contact already has a picture. Delete it");

                    $attachtable = mapi_message_getattachmenttable($mapimessage);
                    mapi_table_restrict($attachtable, MAPIUtils::GetContactPicRestriction());
                    $rows = mapi_table_queryallrows($attachtable, array(PR_ATTACH_NUM));
                    if (isset($rows) && is_array($rows)) {
                        foreach ($rows as $row) {
                            mapi_message_deleteattach($mapimessage, $row[PR_ATTACH_NUM]);
                        }
                    }
                }

                // only set picture if there's data in the request
                if ($picbinary !== false && $picsize > 0) {
                    $props[$contactprops["haspic"]] = true;
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

    /**
     * Writes a SyncTask to MAPI
     *
     * @param mixed             $mapimessage
     * @param SyncTask          $task
     *
     * @access private
     * @return boolean
     */
    private function setTask($mapimessage, $task) {
        mapi_setprops($mapimessage, array(PR_MESSAGE_CLASS => "IPM.Task"));

        $this->setPropsInMAPI($mapimessage, $task, MAPIMapping::GetTaskMapping());
        $taskprops = MAPIMapping::GetTaskProperties();
        $taskprops = $this->getPropIdsFromStrings($taskprops);

        // task specific properties to be set
        $props = array();

        if(isset($task->complete)) {
            if($task->complete) {
                // Set completion to 100%
                // Set status to 'complete'
                $props[$taskprops["completion"]] = 1.0;
                $props[$taskprops["status"]] = 2;
            } else {
                // Set completion to 0%
                // Set status to 'not started'
                $props[$taskprops["completion"]] = 0.0;
                $props[$taskprops["status"]] = 0;
            }
        }
        if (isset($task->recurrence) && class_exists('TaskRecurrence')) {
            $deadoccur = false;
            if (isset($task->recurrence->occurrences) && $task->recurrence->occurrences == 1) $deadoccur = true;

            // Set PR_ICON_INDEX to 1281 to show correct icon in category view
            $props[$taskprops["icon"]] = 1281;
            // dead occur - false if new occurrences should be generated from the task
            // true - if it is the last ocurrence of the task
            $props[$taskprops["deadoccur"]] = $deadoccur;
            $props[$taskprops["isrecurringtag"]] = true;

            $recurrence = new TaskRecurrence($this->store, $mapimessage);
            $recur = array();
            $this->setRecurrence($task, $recur);

            // task specific recurrence properties which we need to set here
            // "start" and "end" are in GMT when passing to class.recurrence
            // set recurrence start here because it's calculated differently for tasks and appointments
            $recur["start"] = $task->recurrence->start;
            $recur["regen"] = $task->regenerate;
            //Also add dates to $recur
            $recur["duedate"] = $task->duedate;
            $recurrence->setRecurrence($recur);
        }
        mapi_setprops($mapimessage, $props);
    }


    /**----------------------------------------------------------------------------------------------------------
     * HELPER
     */

    /**
     * Returns the tiemstamp offset
     *
     * @param string            $ts
     *
     * @access private
     * @return long
     */
    private function GetTZOffset($ts) {
        $Offset = date("O", $ts);

        $Parity = $Offset < 0 ? -1 : 1;
        $Offset = $Parity * $Offset;
        $Offset = ($Offset - ($Offset % 100)) / 100 * 60 + $Offset % 100;

        return $Parity * $Offset;
    }

    /**
     * Localtime of the timestamp
     *
     * @param long              $time
     *
     * @access private
     * @return array
     */
    private function gmtime($time) {
        $TZOffset = $this->GetTZOffset($time);

        $t_time = $time - $TZOffset * 60; #Counter adjust for localtime()
        $t_arr = localtime($t_time, 1);

        return $t_arr;
    }

    /**
     * Sets the properties in a MAPI object according to an Sync object and a property mapping
     *
     * @param mixed             $mapimessage
     * @param SyncObject        $message
     * @param array             $mapping
     *
     * @access private
     * @return
     */
    private function setPropsInMAPI($mapimessage, $message, $mapping) {
        $mapiprops = $this->getPropIdsFromStrings($mapping);
        $unsetVars = $message->getUnsetVars();
        $propsToDelete = array();
        $propsToSet = array();

        foreach ($mapiprops as $asprop => $mapiprop) {
            if(isset($message->$asprop)) {

                // UTF8->windows1252.. this is ok for all numerical values
                if(mapi_prop_type($mapiprop) != PT_BINARY && mapi_prop_type($mapiprop) != PT_MV_BINARY) {
                    if(is_array($message->$asprop))
                        $value = array_map("u2wi", $message->$asprop);
                    else
                        $value = u2wi($message->$asprop);
                } else {
                    $value = $message->$asprop;
                }

                // Make sure the php values are the correct type
                switch(mapi_prop_type($mapiprop)) {
                    case PT_BINARY:
                    case PT_STRING8:
                        settype($value, "string");
                        break;
                    case PT_BOOLEAN:
                        settype($value, "boolean");
                        break;
                    case PT_SYSTIME:
                    case PT_LONG:
                        settype($value, "integer");
                        break;
                }

                // decode base64 value
                if($mapiprop == PR_RTF_COMPRESSED) {
                    $value = base64_decode($value);
                    if(strlen($value) == 0)
                        continue; // PDA will sometimes give us an empty RTF, which we'll ignore.

                    // Note that you can still remove notes because when you remove notes it gives
                    // a valid compressed RTF with nothing in it.

                }
                // all properties will be set at once
                $propsToSet[$mapiprop] = $value;
            }
            elseif (in_array($asprop, $unsetVars)) {
                $propsToDelete[] = $mapiprop;
            }
        }

        mapi_setprops($mapimessage, $propsToSet);
        if (mapi_last_hresult()) {
            Zlog::Write(LOGLEVEL_WARN, sprintf("Failed to set properties, trying to set them separately. Error code was:%x", mapi_last_hresult()));
            $this->setPropsIndividually($mapimessage, $propsToSet, $mapiprops);
        }

        mapi_deleteprops($mapimessage, $propsToDelete);

        //clean up
        unset($unsetVars, $propsToDelete);
    }

    /**
     * Sets the properties one by one in a MAPI object
     *
     * @param mixed             &$mapimessage
     * @param array             &$propsToSet
     * @param array             &$mapiprops
     *
     * @access private
     * @return
     */
    private function setPropsIndividually(&$mapimessage, &$propsToSet, &$mapiprops) {
        foreach ($propsToSet as $prop => $value) {
            mapi_setprops($mapimessage, array($prop => $value));
            if (mapi_last_hresult()) {
                Zlog::Write(LOGLEVEL_ERROR, sprintf("Failed setting property [%s] with value [%s], error code was:%x", array_search($prop, $mapiprops), $value, mapi_last_hresult()));
            }
        }

    }

    /**
     * Gets the properties from a MAPI object and sets them in the Sync object according to mapping
     *
     * @param SyncObject        &$message
     * @param mixed             $mapimessage
     * @param array             $mapping
     *
     * @access private
     * @return
     */
    private function getPropsFromMAPI(&$message, $mapimessage, $mapping) {
        $messageprops = $this->getProps($mapimessage, $mapping);
        foreach ($mapping as $asprop => $mapiprop) {
             // Get long strings via openproperty
            if (isset($messageprops[mapi_prop_tag(PT_ERROR, mapi_prop_id($mapiprop))])) {
                if ($messageprops[mapi_prop_tag(PT_ERROR, mapi_prop_id($mapiprop))] == -2147024882 || // 32 bit
                $messageprops[mapi_prop_tag(PT_ERROR, mapi_prop_id($mapiprop))] == 2147942414) {  // 64 bit
                    $messageprops[$mapiprop] = MAPIUtils::readPropStream($mapimessage, $mapiprop);
                }
            }

            if(isset($messageprops[$mapiprop])) {
                if(mapi_prop_type($mapiprop) == PT_BOOLEAN) {
                    // Force to actual '0' or '1'
                    if($messageprops[$mapiprop])
                        $message->$asprop = 1;
                    else
                        $message->$asprop = 0;
                } else {
                    // Special handling for PR_MESSAGE_FLAGS
                    if($mapiprop == PR_MESSAGE_FLAGS)
                        $message->$asprop = $messageprops[$mapiprop] & 1; // only look at 'read' flag
                    else if($mapiprop == PR_RTF_COMPRESSED)
                        //do not send rtf to the mobile
                        continue;
                    else if(is_array($messageprops[$mapiprop]))
                        $message->$asprop = array_map("w2u", $messageprops[$mapiprop]);
                    else {
                        if(mapi_prop_type($mapiprop) != PT_BINARY && mapi_prop_type($mapiprop) != PT_MV_BINARY)
                            $message->$asprop = w2u($messageprops[$mapiprop]);
                        else
                            $message->$asprop = $messageprops[$mapiprop];
                    }
                }
            }
        }
    }

    /**
     * Wraps getPropIdsFromStrings() calls
     *
     * @param mixed             &$mapiprops
     *
     * @access private
     * @return
     */
    private function getPropIdsFromStrings(&$mapiprops) {
        return getPropIdsFromStrings($this->store, $mapiprops);
    }

    /**
     * Wraps mapi_getprops() calls
     *
     * @param mixed             &$mapiprops
     *
     * @access private
     * @return
     */
    protected function getProps($mapimessage, &$mapiproperties) {
        $mapiproperties = $this->getPropIdsFromStrings($mapiproperties);
        return mapi_getprops($mapimessage, $mapiproperties);
    }

    /**
     * Returns an GMT timezone array
     *
     * @access private
     * @return array
     */
    private function getGMTTZ() {
        $tz = array("bias" => 0, "stdbias" => 0, "dstbias" => 0, "dstendyear" => 0, "dstendmonth" =>0, "dstendday" =>0, "dstendweek" => 0, "dstendhour" => 0, "dstendminute" => 0, "dstendsecond" => 0, "dstendmillis" => 0,
                                      "dststartyear" => 0, "dststartmonth" =>0, "dststartday" =>0, "dststartweek" => 0, "dststarthour" => 0, "dststartminute" => 0, "dststartsecond" => 0, "dststartmillis" => 0);
        return $tz;
    }

    /**
     * Unpack timezone info from MAPI
     *
     * @param string    $data
     *
     * @access private
     * @return array
     */
    private function getTZFromMAPIBlob($data) {
        $unpacked = unpack("lbias/lstdbias/ldstbias/" .
                           "vconst1/vdstendyear/vdstendmonth/vdstendday/vdstendweek/vdstendhour/vdstendminute/vdstendsecond/vdstendmillis/" .
                           "vconst2/vdststartyear/vdststartmonth/vdststartday/vdststartweek/vdststarthour/vdststartminute/vdststartsecond/vdststartmillis", $data);
        return $unpacked;
    }

    /**
     * Unpack timezone info from Sync
     *
     * @param string    $data
     *
     * @access private
     * @return array
     */
    private function getTZFromSyncBlob($data) {
        $tz = unpack(   "lbias/a64name/vdstendyear/vdstendmonth/vdstendday/vdstendweek/vdstendhour/vdstendminute/vdstendsecond/vdstendmillis/" .
                        "lstdbias/a64name/vdststartyear/vdststartmonth/vdststartday/vdststartweek/vdststarthour/vdststartminute/vdststartsecond/vdststartmillis/" .
                        "ldstbias", $data);

        // Make the structure compatible with class.recurrence.php
        $tz["timezone"] = $tz["bias"];
        $tz["timezonedst"] = $tz["dstbias"];

        return $tz;
    }

    /**
     * Pack timezone info for Sync
     *
     * @param array     $tz
     *
     * @access private
     * @return string
     */
    private function getSyncBlobFromTZ($tz) {
        $packed = pack("la64vvvvvvvv" . "la64vvvvvvvv" . "l",
                $tz["bias"], "", 0, $tz["dstendmonth"], $tz["dstendday"], $tz["dstendweek"], $tz["dstendhour"], $tz["dstendminute"], $tz["dstendsecond"], $tz["dstendmillis"],
                $tz["stdbias"], "", 0, $tz["dststartmonth"], $tz["dststartday"], $tz["dststartweek"], $tz["dststarthour"], $tz["dststartminute"], $tz["dststartsecond"], $tz["dststartmillis"],
                $tz["dstbias"]);

        return $packed;
    }

    /**
     * Pack timezone info for MAPI
     *
     * @param array     $tz
     *
     * @access private
     * @return string
     */
    private function getMAPIBlobFromTZ($tz) {
        $packed = pack("lll" . "vvvvvvvvv" . "vvvvvvvvv",
                      $tz["bias"], $tz["stdbias"], $tz["dstbias"],
                      0, 0, $tz["dstendmonth"], $tz["dstendday"], $tz["dstendweek"], $tz["dstendhour"], $tz["dstendminute"], $tz["dstendsecond"], $tz["dstendmillis"],
                      0, 0, $tz["dststartmonth"], $tz["dststartday"], $tz["dststartweek"], $tz["dststarthour"], $tz["dststartminute"], $tz["dststartsecond"], $tz["dststartmillis"]);

        return $packed;
    }

    /**
     * Checks the date to see if it is in DST, and returns correct GMT date accordingly
     *
     * @param long      $localtime
     * @param array     $tz
     *
     * @access private
     * @return long
     */
    private function getGMTTimeByTZ($localtime, $tz) {
        if(!isset($tz) || !is_array($tz))
            return $localtime;

        if($this->isDST($localtime, $tz))
            return $localtime + $tz["bias"]*60 + $tz["dstbias"]*60;
        else
            return $localtime + $tz["bias"]*60;
    }

    /**
     * Returns the local time for the given GMT time, taking account of the given timezone
     *
     * @param long      $gmttime
     * @param array     $tz
     *
     * @access private
     * @return long
     */
    private function getLocaltimeByTZ($gmttime, $tz) {
        if(!isset($tz) || !is_array($tz))
            return $gmttime;

        if($this->isDST($gmttime - $tz["bias"]*60, $tz)) // may bug around the switch time because it may have to be 'gmttime - bias - dstbias'
            return $gmttime - $tz["bias"]*60 - $tz["dstbias"]*60;
        else
            return $gmttime - $tz["bias"]*60;
    }

    /**
     * Returns TRUE if it is the summer and therefore DST is in effect
     *
     * @param long      $localtime
     * @param array     $tz
     *
     * @access private
     * @return boolean
     */
    private function isDST($localtime, $tz) {
        if( !isset($tz) || !is_array($tz) ||
            !isset($tz["dstbias"]) || $tz["dstbias"] == 0 ||
            !isset($tz["dststartmonth"]) || $tz["dststartmonth"] == 0 ||
            !isset($tz["dstendmonth"]) || $tz["dstendmonth"] == 0)
            return false;

        $year = gmdate("Y", $localtime);
        $start = $this->getTimestampOfWeek($year, $tz["dststartmonth"], $tz["dststartweek"], $tz["dststartday"], $tz["dststarthour"], $tz["dststartminute"], $tz["dststartsecond"]);
        $end = $this->getTimestampOfWeek($year, $tz["dstendmonth"], $tz["dstendweek"], $tz["dstendday"], $tz["dstendhour"], $tz["dstendminute"], $tz["dstendsecond"]);

        if($start < $end) {
            // northern hemisphere (july = dst)
          if($localtime >= $start && $localtime < $end)
              $dst = true;
          else
              $dst = false;
        } else {
            // southern hemisphere (january = dst)
          if($localtime >= $end && $localtime < $start)
              $dst = false;
          else
              $dst = true;
        }

        return $dst;
    }

    /**
     * Returns the local timestamp for the $week'th $wday of $month in $year at $hour:$minute:$second
     *
     * @param int       $year
     * @param int       $month
     * @param int       $week
     * @param int       $wday
     * @param int       $hour
     * @param int       $minute
     * @param int       $second
     *
     * @access private
     * @return long
     */
    private function getTimestampOfWeek($year, $month, $week, $wday, $hour, $minute, $second) {
        if ($month == 0)
            return;

        $date = gmmktime($hour, $minute, $second, $month, 1, $year);

        // Find first day in month which matches day of the week
        while(1) {
            $wdaynow = gmdate("w", $date);
            if($wdaynow == $wday)
                break;
            $date += 24 * 60 * 60;
        }

        // Forward $week weeks (may 'overflow' into the next month)
        $date = $date + $week * (24 * 60 * 60 * 7);

        // Reverse 'overflow'. Eg week '10' will always be the last week of the month in which the
        // specified weekday exists
        while(1) {
            $monthnow = gmdate("n", $date); // gmdate returns 1-12
            if($monthnow > $month)
                $date = $date - (24 * 7 * 60 * 60);
            else
                break;
        }

        return $date;
    }

    /**
     * Normalize the given timestamp to the start of the day
     *
     * @param long      $timestamp
     *
     * @access private
     * @return long
     */
    private function getDayStartOfTimestamp($timestamp) {
        return $timestamp - ($timestamp % (60 * 60 * 24));
    }

    /**
     * Returns an SMTP address from an entry id
     *
     * @param string    $entryid
     *
     * @access private
     * @return string
     */
    private function getSMTPAddressFromEntryID($entryid) {
        $ab = mapi_openaddressbook($this->session);

        $mailuser = mapi_ab_openentry($ab, $entryid);
        if(!$mailuser)
            return "";

        $props = mapi_getprops($mailuser, array(PR_ADDRTYPE, PR_SMTP_ADDRESS, PR_EMAIL_ADDRESS));

        $addrtype = isset($props[PR_ADDRTYPE]) ? $props[PR_ADDRTYPE] : "";

        if(isset($props[PR_SMTP_ADDRESS]))
            return $props[PR_SMTP_ADDRESS];

        if($addrtype == "SMTP" && isset($props[PR_EMAIL_ADDRESS]))
            return $props[PR_EMAIL_ADDRESS];

        return "";
    }

    /**
     * Builds a displayname from several separated values
     *
     * @param SyncContact       $contact
     *
     * @access private
     * @return string
     */
    private function composeDisplayName(&$contact) {
        // Set display name and subject to a combined value of firstname and lastname
        $cname = (isset($contact->prefix))?u2w($contact->prefix)." ":"";
        $cname .= u2w($contact->firstname);
        $cname .= (isset($contact->middlename))?" ". u2w($contact->middlename):"";
        $cname .= " ". u2w($contact->lastname);
        $cname .= (isset($contact->suffix))?" ". u2w($contact->suffix):"";
        return trim($cname);
    }

    /**
     * Sets all dependent properties for an email address
     *
     * @param string            $emailAddress
     * @param string            $displayName
     * @param int               $cnt
     * @param array             &$props
     * @param array             &$properties
     * @param array             &$nremails
     * @param int               &$abprovidertype
     *
     * @access private
     * @return
     */
    private function setEmailAddress($emailAddress, $displayName, $cnt, &$props, &$properties, &$nremails, &$abprovidertype){
        if (isset($emailAddress)){
            $name = (isset($displayName)) ? $displayName : $emailAddress;

            $props[$properties["emailaddress$cnt"]] = $emailAddress;
            $props[$properties["emailaddressdemail$cnt"]] = $emailAddress;
            $props[$properties["emailaddressdname$cnt"]] = $name;
            $props[$properties["emailaddresstype$cnt"]] = "SMTP";
            $props[$properties["emailaddressentryid$cnt"]] = mapi_createoneoff($name, "SMTP", $emailAddress);
            $nremails[] = $cnt - 1;
            $abprovidertype |= 2 ^ ($cnt - 1);
        }
    }

    /**
     * Sets the properties for an address string
     *
     * @param string            $type               which address is being set
     * @param string            $city
     * @param string            $country
     * @param string            $postalcode
     * @param string            $state
     * @param string            $street
     * @param array             &$props
     * @param array             &$properties
     *
     * @access private
     * @return
     */
     private function setAddress($type, $city, $country, $postalcode, $state, $street, &$props, &$properties) {
        if (isset($city)) $props[$properties[$type."city"]] = $city;

        if (isset($country)) $props[$properties[$type."country"]] = $country;

        if (isset($postalcode)) $props[$properties[$type."postalcode"]] = $postalcode;

        if (isset($state)) $props[$properties[$type."state"]] = $state;

        if (isset($street)) $props[$properties[$type."street"]] = $street;

        //set composed address
        $address = Utils::BuildAddressString($street, $postalcode, $city, $state, $country);
        if ($address) $props[$properties[$type."address"]] = $address;
    }

    /**
     * Sets the properties for a mailing address
     *
     * @param string            $city
     * @param string            $country
     * @param string            $postalcode
     * @param string            $state
     * @param string            $street
     * @param string            $address
     * @param array             &$props
     * @param array             &$properties
     *
     * @access private
     * @return
     */
    private function setMailingAddress($city, $country, $postalcode,  $state, $street, $address, &$props, &$properties) {
        if (isset($city)) $props[$properties["city"]] = $city;
        if (isset($country)) $props[$properties["country"]] = $country;
        if (isset($postalcode)) $props[$properties["postalcode"]] = $postalcode;
        if (isset($state)) $props[$properties["state"]] = $state;
        if (isset($street)) $props[$properties["street"]] = $street;
        if (isset($address)) $props[$properties["postaladdress"]] = $address;
    }

    /**
     * Sets data in a recurrence array
     *
     * @param SyncObject        $message
     * @param array             &$recur
     *
     * @access private
     * @return
     */
    private function setRecurrence($message, &$recur) {
        if (isset($message->complete)) {
            $recur["complete"] = $message->complete;
        }

        if(!isset($message->recurrence->interval))
            $message->recurrence->interval = 1;

        switch($message->recurrence->type) {
            case 0:
                $recur["type"] = 10;
                if(isset($message->recurrence->dayofweek))
                    $recur["subtype"] = 1;
                else
                    $recur["subtype"] = 0;

                $recur["everyn"] = $message->recurrence->interval * (60 * 24);
                break;
            case 1:
                $recur["type"] = 11;
                $recur["subtype"] = 1;
                $recur["everyn"] = $message->recurrence->interval;
                break;
            case 2:
                $recur["type"] = 12;
                $recur["subtype"] = 2;
                $recur["everyn"] = $message->recurrence->interval;
                break;
            case 3:
                $recur["type"] = 12;
                $recur["subtype"] = 3;
                $recur["everyn"] = $message->recurrence->interval;
                break;
            case 4:
                $recur["type"] = 13;
                $recur["subtype"] = 1;
                $recur["everyn"] = $message->recurrence->interval * 12;
                break;
            case 5:
                $recur["type"] = 13;
                $recur["subtype"] = 2;
                $recur["everyn"] = $message->recurrence->interval * 12;
                break;
            case 6:
                $recur["type"] = 13;
                $recur["subtype"] = 3;
                $recur["everyn"] = $message->recurrence->interval * 12;
                break;
        }

        // "start" and "end" are in GMT when passing to class.recurrence
        $recur["end"] = $this->getDayStartOfTimestamp(0x7fffffff); // Maximum GMT value for end by default

        if(isset($message->recurrence->until)) {
            $recur["term"] = 0x21;
            $recur["end"] = $message->recurrence->until;
        } else if(isset($message->recurrence->occurrences)) {
            $recur["term"] = 0x22;
            $recur["numoccur"] = $message->recurrence->occurrences;
        } else {
            $recur["term"] = 0x23;
        }

        if(isset($message->recurrence->dayofweek))
            $recur["weekdays"] = $message->recurrence->dayofweek;
        if(isset($message->recurrence->weekofmonth))
            $recur["nday"] = $message->recurrence->weekofmonth;
        if(isset($message->recurrence->monthofyear)) {
            // MAPI stores months as the amount of minutes until the beginning of the month in a
            // non-leapyear. Why this is, is totally unclear.
            $monthminutes = array(0,44640,84960,129600,172800,217440,260640,305280,348480,393120,437760,480960);
            $recur["month"] = $monthminutes[$message->recurrence->monthofyear-1];
        }
        if(isset($message->recurrence->dayofmonth))
            $recur["monthday"] = $message->recurrence->dayofmonth;
    }
}

?>