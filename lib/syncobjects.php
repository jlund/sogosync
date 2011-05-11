<?php
/***********************************************
* File      :   syncobjects.php
* Project   :   Z-Push
* Descr     :   WBXML entities that can be parsed
*               directly (as a stream) from WBXML.
*               They are automatically decoded
*               according to $mapping,
*               and the Sync WBXML mappings.
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


abstract class SyncObject extends Streamer {
    protected $unsetVars;

    public function SyncObject($mapping) {
        $this->unsetVars = array();
        parent::Streamer($mapping);
    }

    /**
     * Sets all supported but not transmitted variables
     * of this SyncObject to an "empty" value, so they are deleted when being saved
     *
     * @param array     $supportedFields        array with all supported fields, if available
     *
     * @access public
     * @return boolean
     */
    public function emptySupported($supportedFields) {
        if ($supportedFields === false || !is_array($supportedFields))
            return false;

        foreach ($supportedFields as $field) {
            if (!isset($this->mapping[$field])) {
                ZLog::Write(LOGLEVEL_WARN, sprintf("Field '%s' is supposed to be emptied but is not defined for '%s'", $field, get_class($this)));
                continue;
            }
            $var = $this->mapping[$field][self::STREAMER_VAR];
            // add var to $this->unsetVars if $var is not set
            if (!isset($this->$var))
                $this->unsetVars[] = $var;
        }
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("Supported variables to be unset: %s", implode(',', $this->unsetVars)));
        return true;
    }

    /**
     * Compares this a SyncObject to another.
     * In case that all available mapped fields are exactly EQUAL, it returns true
     *
     * @see SyncObject
     * @param SyncObject $odo other SyncObject
     * @return boolean
     */
    public function equals($odo, $log = false) {
        // check objecttype
        if (! ($odo instanceof SyncObject)) {
            return false;
        }
        // we add a fake property so we can compare on it. This way, it's never streamed to the device.
        // TODO this could be done directly in the SyncObject. It should then have a flag so it's not streamed
        $custMapping = $this->mapping;
        $custMapping["customValueStore"] = array(self::STREAMER_VAR => "Store");

        // check for mapped fields
        foreach ($custMapping as $v) {
            $val = $v[self::STREAMER_VAR];
            // array of values?
            if (isset($v[self::STREAMER_ARRAY])) {
                // seek for differences in the arrays
                if (is_array($this->$val) && is_array($odo->$val)) {
                    if (count(array_diff($this->$val, $odo->$val)) + count(array_diff($odo->$val, $this->$val)) > 0) {
                        return false;
                    }
                }
                else
                    return false;
            }
            else {
                if (isset($this->$val) && isset($odo->$val)) {
                    if ($this->$val != $odo->$val)
                        return false;
                }
                else
                    return false;
            }
        }

        return true;
    }

    /**
     * String representation of the object
     *
     * @return String
     */
    public function __toString() {
        $str = get_class($this) . " (\n";

        $streamerVars = array();
        foreach ($this->mapping as $k=>$v)
            $streamerVars[$v[self::STREAMER_VAR]] = (isset($v[self::STREAMER_TYPE]))?$v[self::STREAMER_TYPE]:false;

        foreach (get_object_vars($this) as $k=>$v) {
            if ($k == "_mapping") continue;

            if (array_key_exists($k, $streamerVars))
                $strV = "(S) ";
            else
                $strV = "";

            // self::STREAMER_ARRAY ?
            if (is_array($v)) {
                $str .= "\t". $strV . $k ."(Array) size: " . count($v) ."\n";
                foreach ($v as $value) $str .= "\t\t". Utils::PrintAsString($value) ."\n";
            }
            else if ($v instanceof Streamer) {
                $str .= "\t". $strV .$k ." => ". str_replace("\n", "\n\t\t\t", $v->__toString()) . "\n";
            }
            else
                $str .= "\t". $strV .$k ." => " . (isset($this->$k)? Utils::PrintAsString($this->$k) :"null") . "\n";
        }
        $str .= ")";

        return $str;
    }

    /**
     * Returns the properties which have to be unset on the server
     *
     * @access public
     * @return array
     */
    public function getUnsetVars() {
        return $this->unsetVars;
    }
}


class SyncFolder extends SyncObject {
    public $serverid;
    public $parentid;
    public $displayname;
    public $type;

    function SyncFolder() {
        $mapping = array (
                    SYNC_FOLDERHIERARCHY_SERVERENTRYID => array (self::STREAMER_VAR => "serverid"),
                    SYNC_FOLDERHIERARCHY_PARENTID => array (self::STREAMER_VAR => "parentid"),
                    SYNC_FOLDERHIERARCHY_DISPLAYNAME => array (self::STREAMER_VAR => "displayname"),
                    SYNC_FOLDERHIERARCHY_TYPE => array (self::STREAMER_VAR => "type")
                );

        parent::SyncObject($mapping);
    }
}

class SyncAttachment extends SyncObject {
    public $attmethod;
    public $attsize;
    public $displayname;
    public $attname;
    public $attoid;
    public $attremoved;

    function SyncAttachment() {
        $mapping = array(
                    SYNC_POOMMAIL_ATTMETHOD => array (self::STREAMER_VAR => "attmethod"),
                    SYNC_POOMMAIL_ATTSIZE => array (self::STREAMER_VAR => "attsize"),
                    SYNC_POOMMAIL_DISPLAYNAME => array (self::STREAMER_VAR => "displayname"),
                    SYNC_POOMMAIL_ATTNAME => array (self::STREAMER_VAR => "attname"),
                    SYNC_POOMMAIL_ATTOID => array (self::STREAMER_VAR => "attoid"),
                    SYNC_POOMMAIL_ATTREMOVED => array (self::STREAMER_VAR => "attremoved"),
                );

        parent::SyncObject($mapping);
    }
}

class SyncMeetingRequest extends SyncObject {
    public $alldayevent;
    public $starttime;
    public $dtstamp;
    public $endtime;
    public $instancetype;
    public $location;
    public $organizer;
    public $recurrenceid;
    public $reminder;
    public $responserequested;
    public $recurrences = array();
    public $sensitivity;
    public $busystatus;
    public $timezone;
    public $globalobjid;

    function SyncMeetingRequest() {
        $mapping = array (
                    SYNC_POOMMAIL_ALLDAYEVENT => array (self::STREAMER_VAR => "alldayevent"),
                    SYNC_POOMMAIL_STARTTIME => array (self::STREAMER_VAR => "starttime", self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES),
                    SYNC_POOMMAIL_DTSTAMP => array (self::STREAMER_VAR => "dtstamp", self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES),
                    SYNC_POOMMAIL_ENDTIME => array (self::STREAMER_VAR => "endtime", self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES),
                    SYNC_POOMMAIL_INSTANCETYPE => array (self::STREAMER_VAR => "instancetype"),
                    SYNC_POOMMAIL_LOCATION => array (self::STREAMER_VAR => "location"),
                    SYNC_POOMMAIL_ORGANIZER => array (self::STREAMER_VAR => "organizer"),
                    SYNC_POOMMAIL_RECURRENCEID => array (self::STREAMER_VAR => "recurrenceid", self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES),
                    SYNC_POOMMAIL_REMINDER => array (self::STREAMER_VAR => "reminder"),
                    SYNC_POOMMAIL_RESPONSEREQUESTED => array (self::STREAMER_VAR => "responserequested"),
                    SYNC_POOMMAIL_RECURRENCES => array (self::STREAMER_VAR => "recurrences", self::STREAMER_TYPE => "SyncMeetingRequestRecurrence", self::STREAMER_ARRAY => SYNC_POOMMAIL_RECURRENCE),
                    SYNC_POOMMAIL_SENSITIVITY => array (self::STREAMER_VAR => "sensitivity"),
                    SYNC_POOMMAIL_BUSYSTATUS => array (self::STREAMER_VAR => "busystatus"),
                    SYNC_POOMMAIL_TIMEZONE => array (self::STREAMER_VAR => "timezone"),
                    SYNC_POOMMAIL_GLOBALOBJID => array (self::STREAMER_VAR => "globalobjid"),
                );

        parent::SyncObject($mapping);
    }
}

class SyncMail extends SyncObject {
    public $to;
    public $cc;
    public $from;
    public $subject;
    public $threadtopic;
    public $datereceived;
    public $displayto;
    public $importance;
    public $read;
    public $attachments = array();
    public $mimetruncated;
    public $mimedata;
    public $mimesize;
    public $bodytruncated;
    public $bodysize;
    public $body;
    public $messageclass;
    public $meetingrequest;
    public $reply_to;

    // AS 2.5 prop
    public $internetcpid;

    function SyncMail() {
        $mapping = array (
                    SYNC_POOMMAIL_TO => array (self::STREAMER_VAR => "to"),
                    SYNC_POOMMAIL_CC => array (self::STREAMER_VAR => "cc"),
                    SYNC_POOMMAIL_FROM => array (self::STREAMER_VAR => "from"),
                    SYNC_POOMMAIL_SUBJECT => array (self::STREAMER_VAR => "subject"),
                    SYNC_POOMMAIL_THREADTOPIC => array (self::STREAMER_VAR => "threadtopic"),
                    SYNC_POOMMAIL_DATERECEIVED => array (self::STREAMER_VAR => "datereceived", self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES ),
                    SYNC_POOMMAIL_DISPLAYTO =>  array (self::STREAMER_VAR => "displayto"),
                    SYNC_POOMMAIL_IMPORTANCE => array (self::STREAMER_VAR => "importance"),
                    SYNC_POOMMAIL_READ => array (self::STREAMER_VAR => "read"),
                    SYNC_POOMMAIL_ATTACHMENTS => array (self::STREAMER_VAR => "attachments", self::STREAMER_TYPE => "SyncAttachment", self::STREAMER_ARRAY => SYNC_POOMMAIL_ATTACHMENT ),
                    SYNC_POOMMAIL_MIMETRUNCATED => array ( self::STREAMER_VAR => "mimetruncated" ),//
                    SYNC_POOMMAIL_MIMEDATA => array ( self::STREAMER_VAR => "mimedata", self::STREAMER_TYPE => self::STREAMER_TYPE_MAPI_STREAM),
                    SYNC_POOMMAIL_MIMESIZE => array ( self::STREAMER_VAR => "mimesize" ),//
                    SYNC_POOMMAIL_BODYTRUNCATED => array (self::STREAMER_VAR => "bodytruncated"),
                    SYNC_POOMMAIL_BODYSIZE => array (self::STREAMER_VAR => "bodysize"),
                    SYNC_POOMMAIL_BODY => array (self::STREAMER_VAR => "body"),
                    SYNC_POOMMAIL_MESSAGECLASS => array (self::STREAMER_VAR => "messageclass"),
                    SYNC_POOMMAIL_MEETINGREQUEST => array (self::STREAMER_VAR => "meetingrequest", self::STREAMER_TYPE => "SyncMeetingRequest"),
                    SYNC_POOMMAIL_REPLY_TO => array (self::STREAMER_VAR => "reply_to"),
                );

        if(Request::getProtocolVersion() >= 2.5) {
            $mapping += array(
                        SYNC_POOMMAIL_INTERNETCPID => array (self::STREAMER_VAR => "internetcpid"),
                    );
        }

        parent::SyncObject($mapping);
    }
}

class SyncContact extends SyncObject {
    public $anniversary;
    public $assistantname;
    public $assistnamephonenumber;
    public $birthday;
    public $body;
    public $bodysize;
    public $bodytruncated;
    public $business2phonenumber;
    public $businesscity;
    public $businesscountry;
    public $businesspostalcode;
    public $businessstate;
    public $businessstreet;
    public $businessfaxnumber;
    public $businessphonenumber;
    public $carphonenumber;
    public $children = array();
    public $companyname;
    public $department;
    public $email1address;
    public $email2address;
    public $email3address;
    public $fileas;
    public $firstname;
    public $home2phonenumber;
    public $homecity;
    public $homecountry;
    public $homepostalcode;
    public $homestate;
    public $homestreet;
    public $homefaxnumber;
    public $homephonenumber;
    public $jobtitle;
    public $lastname;
    public $middlename;
    public $mobilephonenumber;
    public $officelocation;
    public $othercity;
    public $othercountry;
    public $otherpostalcode;
    public $otherstate;
    public $otherstreet;
    public $pagernumber;
    public $radiophonenumber;
    public $spouse;
    public $suffix;
    public $title;
    public $webpage;
    public $yomicompanyname;
    public $yomifirstname;
    public $yomilastname;
    public $rtf;
    public $picture;
    public $categories = array();

    // AS 2.5 props
    public $customerid;
    public $governmentid;
    public $imaddress;
    public $imaddress2;
    public $imaddress3;
    public $managername;
    public $companymainphone;
    public $accountname;
    public $nickname;
    public $mms;

    function SyncContact() {
        $mapping = array (
                    SYNC_POOMCONTACTS_ANNIVERSARY => array (self::STREAMER_VAR => "anniversary", self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES  ),
                    SYNC_POOMCONTACTS_ASSISTANTNAME => array (self::STREAMER_VAR => "assistantname"),
                    SYNC_POOMCONTACTS_ASSISTNAMEPHONENUMBER => array (self::STREAMER_VAR => "assistnamephonenumber"),
                    SYNC_POOMCONTACTS_BIRTHDAY => array (self::STREAMER_VAR => "birthday", self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES  ),
                    SYNC_POOMCONTACTS_BODY => array (self::STREAMER_VAR => "body"),
                    SYNC_POOMCONTACTS_BODYSIZE => array (self::STREAMER_VAR => "bodysize"),
                    SYNC_POOMCONTACTS_BODYTRUNCATED => array (self::STREAMER_VAR => "bodytruncated"),
                    SYNC_POOMCONTACTS_BUSINESS2PHONENUMBER => array (self::STREAMER_VAR => "business2phonenumber"),
                    SYNC_POOMCONTACTS_BUSINESSCITY => array (self::STREAMER_VAR => "businesscity"),
                    SYNC_POOMCONTACTS_BUSINESSCOUNTRY => array (self::STREAMER_VAR => "businesscountry"),
                    SYNC_POOMCONTACTS_BUSINESSPOSTALCODE => array (self::STREAMER_VAR => "businesspostalcode"),
                    SYNC_POOMCONTACTS_BUSINESSSTATE => array (self::STREAMER_VAR => "businessstate"),
                    SYNC_POOMCONTACTS_BUSINESSSTREET => array (self::STREAMER_VAR => "businessstreet"),
                    SYNC_POOMCONTACTS_BUSINESSFAXNUMBER => array (self::STREAMER_VAR => "businessfaxnumber"),
                    SYNC_POOMCONTACTS_BUSINESSPHONENUMBER => array (self::STREAMER_VAR => "businessphonenumber"),
                    SYNC_POOMCONTACTS_CARPHONENUMBER => array (self::STREAMER_VAR => "carphonenumber"),
                    SYNC_POOMCONTACTS_CHILDREN => array (self::STREAMER_VAR => "children", self::STREAMER_ARRAY => SYNC_POOMCONTACTS_CHILD ),
                    SYNC_POOMCONTACTS_COMPANYNAME => array (self::STREAMER_VAR => "companyname"),
                    SYNC_POOMCONTACTS_DEPARTMENT => array (self::STREAMER_VAR => "department"),
                    SYNC_POOMCONTACTS_EMAIL1ADDRESS => array (self::STREAMER_VAR => "email1address"),
                    SYNC_POOMCONTACTS_EMAIL2ADDRESS => array (self::STREAMER_VAR => "email2address"),
                    SYNC_POOMCONTACTS_EMAIL3ADDRESS => array (self::STREAMER_VAR => "email3address"),
                    SYNC_POOMCONTACTS_FILEAS => array (self::STREAMER_VAR => "fileas"),
                    SYNC_POOMCONTACTS_FIRSTNAME => array (self::STREAMER_VAR => "firstname"),
                    SYNC_POOMCONTACTS_HOME2PHONENUMBER => array (self::STREAMER_VAR => "home2phonenumber"),
                    SYNC_POOMCONTACTS_HOMECITY => array (self::STREAMER_VAR => "homecity"),
                    SYNC_POOMCONTACTS_HOMECOUNTRY => array (self::STREAMER_VAR => "homecountry"),
                    SYNC_POOMCONTACTS_HOMEPOSTALCODE => array (self::STREAMER_VAR => "homepostalcode"),
                    SYNC_POOMCONTACTS_HOMESTATE => array (self::STREAMER_VAR => "homestate"),
                    SYNC_POOMCONTACTS_HOMESTREET => array (self::STREAMER_VAR => "homestreet"),
                    SYNC_POOMCONTACTS_HOMEFAXNUMBER => array (self::STREAMER_VAR => "homefaxnumber"),
                    SYNC_POOMCONTACTS_HOMEPHONENUMBER => array (self::STREAMER_VAR => "homephonenumber"),
                    SYNC_POOMCONTACTS_JOBTITLE => array (self::STREAMER_VAR => "jobtitle"),
                    SYNC_POOMCONTACTS_LASTNAME => array (self::STREAMER_VAR => "lastname"),
                    SYNC_POOMCONTACTS_MIDDLENAME => array (self::STREAMER_VAR => "middlename"),
                    SYNC_POOMCONTACTS_MOBILEPHONENUMBER => array (self::STREAMER_VAR => "mobilephonenumber"),
                    SYNC_POOMCONTACTS_OFFICELOCATION => array (self::STREAMER_VAR => "officelocation"),
                    SYNC_POOMCONTACTS_OTHERCITY => array (self::STREAMER_VAR => "othercity"),
                    SYNC_POOMCONTACTS_OTHERCOUNTRY => array (self::STREAMER_VAR => "othercountry"),
                    SYNC_POOMCONTACTS_OTHERPOSTALCODE => array (self::STREAMER_VAR => "otherpostalcode"),
                    SYNC_POOMCONTACTS_OTHERSTATE => array (self::STREAMER_VAR => "otherstate"),
                    SYNC_POOMCONTACTS_OTHERSTREET => array (self::STREAMER_VAR => "otherstreet"),
                    SYNC_POOMCONTACTS_PAGERNUMBER => array (self::STREAMER_VAR => "pagernumber"),
                    SYNC_POOMCONTACTS_RADIOPHONENUMBER => array (self::STREAMER_VAR => "radiophonenumber"),
                    SYNC_POOMCONTACTS_SPOUSE => array (self::STREAMER_VAR => "spouse"),
                    SYNC_POOMCONTACTS_SUFFIX => array (self::STREAMER_VAR => "suffix"),
                    SYNC_POOMCONTACTS_TITLE => array (self::STREAMER_VAR => "title"),
                    SYNC_POOMCONTACTS_WEBPAGE => array (self::STREAMER_VAR => "webpage"),
                    SYNC_POOMCONTACTS_YOMICOMPANYNAME => array (self::STREAMER_VAR => "yomicompanyname"),
                    SYNC_POOMCONTACTS_YOMIFIRSTNAME => array (self::STREAMER_VAR => "yomifirstname"),
                    SYNC_POOMCONTACTS_YOMILASTNAME => array (self::STREAMER_VAR => "yomilastname"),
                    SYNC_POOMCONTACTS_RTF => array (self::STREAMER_VAR => "rtf"),
                    SYNC_POOMCONTACTS_PICTURE => array (self::STREAMER_VAR => "picture"),
                    SYNC_POOMCONTACTS_CATEGORIES => array (self::STREAMER_VAR => "categories", self::STREAMER_ARRAY => SYNC_POOMCONTACTS_CATEGORY ),
                );

        if(Request::getProtocolVersion() >= 2.5) {
            $mapping += array(
                        SYNC_POOMCONTACTS2_CUSTOMERID => array (self::STREAMER_VAR => "customerid"),
                        SYNC_POOMCONTACTS2_GOVERNMENTID => array (self::STREAMER_VAR => "governmentid"),
                        SYNC_POOMCONTACTS2_IMADDRESS => array (self::STREAMER_VAR => "imaddress"),
                        SYNC_POOMCONTACTS2_IMADDRESS2 => array (self::STREAMER_VAR => "imaddress2"),
                        SYNC_POOMCONTACTS2_IMADDRESS3 => array (self::STREAMER_VAR => "imaddress3"),
                        SYNC_POOMCONTACTS2_MANAGERNAME => array (self::STREAMER_VAR => "managername"),
                        SYNC_POOMCONTACTS2_COMPANYMAINPHONE => array (self::STREAMER_VAR => "companymainphone"),
                        SYNC_POOMCONTACTS2_ACCOUNTNAME => array (self::STREAMER_VAR => "accountname"),
                        SYNC_POOMCONTACTS2_NICKNAME => array (self::STREAMER_VAR => "nickname"),
                        SYNC_POOMCONTACTS2_MMS => array (self::STREAMER_VAR => "mms"),
                    );
        }

        parent::SyncObject($mapping);
    }
}

class SyncAttendee extends SyncObject {
    public $email;
    public $name;

    function SyncAttendee() {
        $mapping = array(
                    SYNC_POOMCAL_EMAIL => array (self::STREAMER_VAR => "email"),
                    SYNC_POOMCAL_NAME => array (self::STREAMER_VAR => "name" )
                );

        parent::SyncObject($mapping);
    }
}

class SyncAppointment extends SyncObject {
    public $timezone;
    public $dtstamp;
    public $starttime;
    public $subject;
    public $uid;
    public $organizername;
    public $organizeremail;
    public $location;
    public $endtime;
    public $recurrence;
    public $sensitivity;
    public $busystatus;
    public $alldayevent;
    public $reminder;
    public $rtf;
    public $meetingstatus;
    public $attendees;
    public $body;
    public $bodytruncated;
    public $exception;
    public $deleted;
    public $exceptionstarttime;
    public $categories = array();

    function SyncAppointment() {
        $mapping = array(
                    SYNC_POOMCAL_TIMEZONE => array (self::STREAMER_VAR => "timezone"),
                    SYNC_POOMCAL_DTSTAMP => array (self::STREAMER_VAR => "dtstamp", self::STREAMER_TYPE => self::STREAMER_TYPE_DATE),
                    SYNC_POOMCAL_STARTTIME => array (self::STREAMER_VAR => "starttime", self::STREAMER_TYPE => self::STREAMER_TYPE_DATE),
                    SYNC_POOMCAL_SUBJECT => array (self::STREAMER_VAR => "subject"),
                    SYNC_POOMCAL_UID => array (self::STREAMER_VAR => "uid"),
                    SYNC_POOMCAL_ORGANIZERNAME => array (self::STREAMER_VAR => "organizername"),
                    SYNC_POOMCAL_ORGANIZEREMAIL => array (self::STREAMER_VAR => "organizeremail"),
                    SYNC_POOMCAL_LOCATION => array (self::STREAMER_VAR => "location"),
                    SYNC_POOMCAL_ENDTIME => array (self::STREAMER_VAR => "endtime", self::STREAMER_TYPE => self::STREAMER_TYPE_DATE),
                    SYNC_POOMCAL_RECURRENCE => array (self::STREAMER_VAR => "recurrence", self::STREAMER_TYPE => "SyncRecurrence"),
                    SYNC_POOMCAL_SENSITIVITY => array (self::STREAMER_VAR => "sensitivity"),
                    SYNC_POOMCAL_BUSYSTATUS => array (self::STREAMER_VAR => "busystatus"),
                    SYNC_POOMCAL_ALLDAYEVENT => array (self::STREAMER_VAR => "alldayevent"),
                    SYNC_POOMCAL_REMINDER => array (self::STREAMER_VAR => "reminder"),
                    SYNC_POOMCAL_RTF => array (self::STREAMER_VAR => "rtf"),
                    SYNC_POOMCAL_MEETINGSTATUS => array (self::STREAMER_VAR => "meetingstatus"),
                    SYNC_POOMCAL_ATTENDEES => array (self::STREAMER_VAR => "attendees", self::STREAMER_TYPE => "SyncAttendee", self::STREAMER_ARRAY => SYNC_POOMCAL_ATTENDEE),
                    SYNC_POOMCAL_BODY => array (self::STREAMER_VAR => "body"),
                    SYNC_POOMCAL_BODYTRUNCATED => array (self::STREAMER_VAR => "bodytruncated"),
                    SYNC_POOMCAL_EXCEPTIONS => array (self::STREAMER_VAR => "exceptions", self::STREAMER_TYPE => "SyncAppointment", self::STREAMER_ARRAY => SYNC_POOMCAL_EXCEPTION),
                    SYNC_POOMCAL_DELETED => array (self::STREAMER_VAR => "deleted"),
                    SYNC_POOMCAL_EXCEPTIONSTARTTIME => array (self::STREAMER_VAR => "exceptionstarttime", self::STREAMER_TYPE => self::STREAMER_TYPE_DATE),
                    SYNC_POOMCAL_CATEGORIES => array (self::STREAMER_VAR => "categories", self::STREAMER_ARRAY => SYNC_POOMCAL_CATEGORY),
                );

        parent::SyncObject($mapping);
    }
}

class SyncRecurrence extends SyncObject {
    public $type;
    public $until;
    public $occurrences;
    public $interval;
    public $dayofweek;
    public $dayofmonth;
    public $weekofmonth;
    public $monthofyear;

    function SyncRecurrence() {
        $mapping = array (
                    SYNC_POOMCAL_TYPE => array (self::STREAMER_VAR => "type"),
                    SYNC_POOMCAL_UNTIL => array (self::STREAMER_VAR => "until", self::STREAMER_TYPE => self::STREAMER_TYPE_DATE),
                    SYNC_POOMCAL_OCCURRENCES => array (self::STREAMER_VAR => "occurrences"),
                    SYNC_POOMCAL_INTERVAL => array (self::STREAMER_VAR => "interval"),
                    SYNC_POOMCAL_DAYOFWEEK => array (self::STREAMER_VAR => "dayofweek"),
                    SYNC_POOMCAL_DAYOFMONTH => array (self::STREAMER_VAR => "dayofmonth"),
                    SYNC_POOMCAL_WEEKOFMONTH => array (self::STREAMER_VAR => "weekofmonth"),
                    SYNC_POOMCAL_MONTHOFYEAR => array (self::STREAMER_VAR => "monthofyear")
                );

        parent::SyncObject($mapping);
    }
}

// Exactly the same as SyncRecurrence, but then with SYNC_POOMMAIL_*
class SyncMeetingRequestRecurrence extends SyncObject {
    public $type;
    public $until;
    public $occurrences;
    public $interval;
    public $dayofweek;
    public $dayofmonth;
    public $weekofmonth;
    public $monthofyear;

    function SyncMeetingRequestRecurrence() {
        $mapping = array (
                    SYNC_POOMMAIL_TYPE => array (self::STREAMER_VAR => "type"),
                    SYNC_POOMMAIL_UNTIL => array (self::STREAMER_VAR => "until", self::STREAMER_TYPE => self::STREAMER_TYPE_DATE),
                    SYNC_POOMMAIL_OCCURRENCES => array (self::STREAMER_VAR => "occurrences"),
                    SYNC_POOMMAIL_INTERVAL => array (self::STREAMER_VAR => "interval"),
                    SYNC_POOMMAIL_DAYOFWEEK => array (self::STREAMER_VAR => "dayofweek"),
                    SYNC_POOMMAIL_DAYOFMONTH => array (self::STREAMER_VAR => "dayofmonth"),
                    SYNC_POOMMAIL_WEEKOFMONTH => array (self::STREAMER_VAR => "weekofmonth"),
                    SYNC_POOMMAIL_MONTHOFYEAR => array (self::STREAMER_VAR => "monthofyear")
                );

        parent::SyncObject($mapping);
    }
}

// Exactly the same as SyncRecurrence, but then with SYNC_POOMTASKS_*
class SyncTaskRecurrence extends SyncObject {
    public $start;
    public $type;
    public $until;
    public $occurrences;
    public $interval;
    public $dayofweek;
    public $dayofmonth;
    public $weekofmonth;
    public $monthofyear;

    function SyncTaskRecurrence() {
        $mapping = array (
                    SYNC_POOMTASKS_START => array (self::STREAMER_VAR => "start", self::STREAMER_TYPE => self::STREAMER_TYPE_DATE),
                    SYNC_POOMTASKS_TYPE => array (self::STREAMER_VAR => "type"),
                    SYNC_POOMTASKS_UNTIL => array (self::STREAMER_VAR => "until", self::STREAMER_TYPE => self::STREAMER_TYPE_DATE),
                    SYNC_POOMTASKS_OCCURRENCES => array (self::STREAMER_VAR => "occurrences"),
                    SYNC_POOMTASKS_INTERVAL => array (self::STREAMER_VAR => "interval"),
                    SYNC_POOMTASKS_DAYOFWEEK => array (self::STREAMER_VAR => "dayofweek"),
                    SYNC_POOMTASKS_DAYOFMONTH => array (self::STREAMER_VAR => "dayofmonth"),
                    SYNC_POOMTASKS_WEEKOFMONTH => array (self::STREAMER_VAR => "weekofmonth"),
                    SYNC_POOMTASKS_MONTHOFYEAR => array (self::STREAMER_VAR => "monthofyear"),
                );
        parent::SyncObject($mapping);
    }
}

class SyncTask extends SyncObject {
    public $body;
    public $complete;
    public $datecompleted;
    public $duedate;
    public $utcduedate;
    public $importance;
    public $recurrence;
    public $regenerate;
    public $deadoccur;
    public $reminderset;
    public $remindertime;
    public $sensitivity;
    public $startdate;
    public $utcstartdate;
    public $subject;
    public $rtf;
    public $categories = array();

    function SyncTask() {
        $mapping = array (
                    SYNC_POOMTASKS_BODY => array (self::STREAMER_VAR => "body"),
                    SYNC_POOMTASKS_COMPLETE => array (self::STREAMER_VAR => "complete"),
                    SYNC_POOMTASKS_DATECOMPLETED => array (self::STREAMER_VAR => "datecompleted", self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES),
                    SYNC_POOMTASKS_DUEDATE => array (self::STREAMER_VAR => "duedate", self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES),
                    SYNC_POOMTASKS_UTCDUEDATE => array (self::STREAMER_VAR => "utcduedate", self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES),
                    SYNC_POOMTASKS_IMPORTANCE => array (self::STREAMER_VAR => "importance"),
                    SYNC_POOMTASKS_RECURRENCE => array (self::STREAMER_VAR => "recurrence", self::STREAMER_TYPE => "SyncTaskRecurrence"),
                    SYNC_POOMTASKS_REGENERATE => array (self::STREAMER_VAR => "regenerate"),
                    SYNC_POOMTASKS_DEADOCCUR => array (self::STREAMER_VAR => "deadoccur"),
                    SYNC_POOMTASKS_REMINDERSET => array (self::STREAMER_VAR => "reminderset"),
                    SYNC_POOMTASKS_REMINDERTIME => array (self::STREAMER_VAR => "remindertime", self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES),
                    SYNC_POOMTASKS_SENSITIVITY => array (self::STREAMER_VAR => "sensitivity"),
                    SYNC_POOMTASKS_STARTDATE => array (self::STREAMER_VAR => "startdate", self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES),
                    SYNC_POOMTASKS_UTCSTARTDATE => array (self::STREAMER_VAR => "utcstartdate", self::STREAMER_TYPE => self::STREAMER_TYPE_DATE_DASHES),
                    SYNC_POOMTASKS_SUBJECT => array (self::STREAMER_VAR => "subject"),
                    SYNC_POOMTASKS_RTF => array (self::STREAMER_VAR => "rtf"),
                    SYNC_POOMTASKS_CATEGORIES => array (self::STREAMER_VAR => "categories", self::STREAMER_ARRAY => SYNC_POOMTASKS_CATEGORY),
                );

        parent::SyncObject($mapping);
    }
}

?>