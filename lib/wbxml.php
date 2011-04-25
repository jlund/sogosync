<?php
/***********************************************
* File      :   wbxml.php
* Project   :   Z-Push
* Descr     :   WBXML Encoder and Decoder
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


/**
 * WBXML debug mode is configured in config.php in Z-Push 2
 * LOGLEVEL must be set to LOGLEVEL_WBXML to show WBXML data
 */

define('WBXML_SWITCH_PAGE',     0x00);
define('WBXML_END',             0x01);
define('WBXML_ENTITY',          0x02);
define('WBXML_STR_I',           0x03);
define('WBXML_LITERAL',         0x04);
define('WBXML_EXT_I_0',         0x40);
define('WBXML_EXT_I_1',         0x41);
define('WBXML_EXT_I_2',         0x42);
define('WBXML_PI',              0x43);
define('WBXML_LITERAL_C',       0x44);
define('WBXML_EXT_T_0',         0x80);
define('WBXML_EXT_T_1',         0x81);
define('WBXML_EXT_T_2',         0x82);
define('WBXML_STR_T',           0x83);
define('WBXML_LITERAL_A',       0x84);
define('WBXML_EXT_0',           0xC0);
define('WBXML_EXT_1',           0xC1);
define('WBXML_EXT_2',           0xC2);
define('WBXML_OPAQUE',          0xC3);
define('WBXML_LITERAL_AC',      0xC4);

define('EN_TYPE',               1);
define('EN_TAG',                2);
define('EN_CONTENT',            3);
define('EN_FLAGS',              4);
define('EN_ATTRIBUTES',         5);

define('EN_TYPE_STARTTAG',      1);
define('EN_TYPE_ENDTAG',        2);
define('EN_TYPE_CONTENT',       3);

define('EN_FLAGS_CONTENT',      1);
define('EN_FLAGS_ATTRIBUTES',   2);

class WBXMLDefs {
    /**
     * The WBXML DTDs
     */
    protected $dtd = array(
                    "codes" => array (
                        0 => array (
                            0x05 => "Synchronize",
                            0x06 => "Replies",
                            0x07 => "Add",
                            0x08 => "Modify",
                            0x09 => "Remove",
                            0x0a => "Fetch",
                            0x0b => "SyncKey",
                            0x0c => "ClientEntryId",
                            0x0d => "ServerEntryId",
                            0x0e => "Status",
                            0x0f => "Folder",
                            0x10 => "FolderType",
                            0x11 => "Version",
                            0x12 => "FolderId",
                            0x13 => "GetChanges",
                            0x14 => "MoreAvailable",
                            0x15 => "MaxItems",
                            0x16 => "Perform",
                            0x17 => "Options",
                            0x18 => "FilterType",
                            0x19 => "Truncation",
                            0x1a => "RtfTruncation",
                            0x1b => "Conflict",
                            0x1c => "Folders",
                            0x1d => "Data",
                            0x1e => "DeletesAsMoves",
                            0x1f => "NotifyGUID",
                            0x20 => "Supported",
                            0x21 => "SoftDelete",
                            0x22 => "MIMESupport",
                            0x23 => "MIMETruncation",
                        ),
                        1 => array (
                            0x05 => "Anniversary",
                            0x06 => "AssistantName",
                            0x07 => "AssistnamePhoneNumber",
                            0x08 => "Birthday",
                            0x09 => "Body",
                            0x0a => "BodySize",
                            0x0b => "BodyTruncated",
                            0x0c => "Business2PhoneNumber",
                            0x0d => "BusinessCity",
                            0x0e => "BusinessCountry",
                            0x0f => "BusinessPostalCode",
                            0x10 => "BusinessState",
                            0x11 => "BusinessStreet",
                            0x12 => "BusinessFaxNumber",
                            0x13 => "BusinessPhoneNumber",
                            0x14 => "CarPhoneNumber",
                            0x15 => "Categories",
                            0x16 => "Category",
                            0x17 => "Children",
                            0x18 => "Child",
                            0x19 => "CompanyName",
                            0x1a => "Department",
                            0x1b => "Email1Address",
                            0x1c => "Email2Address",
                            0x1d => "Email3Address",
                            0x1e => "FileAs",
                            0x1f => "FirstName",
                            0x20 => "Home2PhoneNumber",
                            0x21 => "HomeCity",
                            0x22 => "HomeCountry",
                            0x23 => "HomePostalCode",
                            0x24 => "HomeState",
                            0x25 => "HomeStreet",
                            0x26 => "HomeFaxNumber",
                            0x27 => "HomePhoneNumber",
                            0x28 => "JobTitle",
                            0x29 => "LastName",
                            0x2a => "MiddleName",
                            0x2b => "MobilePhoneNumber",
                            0x2c => "OfficeLocation",
                            0x2d => "OtherCity",
                            0x2e => "OtherCountry",
                            0x2f => "OtherPostalCode",
                            0x30 => "OtherState",
                            0x31 => "OtherStreet",
                            0x32 => "PagerNumber",
                            0x33 => "RadioPhoneNumber",
                            0x34 => "Spouse",
                            0x35 => "Suffix",
                            0x36 => "Title",
                            0x37 => "WebPage",
                            0x38 => "YomiCompanyName",
                            0x39 => "YomiFirstName",
                            0x3a => "YomiLastName",
                            0x3b => "Rtf",
                            0x3c => "Picture",
                        ),
                        2 => array (
                            0x05 => "Attachment",
                            0x06 => "Attachments",
                            0x07 => "AttName",
                            0x08 => "AttSize",
                            0x09 => "AttOid",
                            0x0a => "AttMethod",
                            0x0b => "AttRemoved",
                            0x0c => "Body",
                            0x0d => "BodySize",
                            0x0e => "BodyTruncated",
                            0x0f => "DateReceived",
                            0x10 => "DisplayName",
                            0x11 => "DisplayTo",
                            0x12 => "Importance",
                            0x13 => "MessageClass",
                            0x14 => "Subject",
                            0x15 => "Read",
                            0x16 => "To",
                            0x17 => "Cc",
                            0x18 => "From",
                            0x19 => "Reply-To",
                            0x1a => "AllDayEvent",
                            0x1b => "Categories",
                            0x1c => "Category",
                            0x1d => "DtStamp",
                            0x1e => "EndTime",
                            0x1f => "InstanceType",
                            0x20 => "BusyStatus",
                            0x21 => "Location",
                            0x22 => "MeetingRequest",
                            0x23 => "Organizer",
                            0x24 => "RecurrenceId",
                            0x25 => "Reminder",
                            0x26 => "ResponseRequested",
                            0x27 => "Recurrences",
                            0x28 => "Recurrence",
                            0x29 => "Type",
                            0x2a => "Until",
                            0x2b => "Occurrences",
                            0x2c => "Interval",
                            0x2d => "DayOfWeek",
                            0x2e => "DayOfMonth",
                            0x2f => "WeekOfMonth",
                            0x30 => "MonthOfYear",
                            0x31 => "StartTime",
                            0x32 => "Sensitivity",
                            0x33 => "TimeZone",
                            0x34 => "GlobalObjId",
                            0x35 => "ThreadTopic",
                            0x36 => "MIMEData",
                            0x37 => "MIMETruncated",
                            0x38 => "MIMESize",
                            0x39 => "InternetCPID",
                        ),
                        3 => array (
                            0x05 => "Notify",
                            0x06 => "Notification",
                            0x07 => "Version",
                            0x08 => "Lifetime",
                            0x09 => "DeviceInfo",
                            0x0a => "Enable",
                            0x0b => "Folder",
                            0x0c => "ServerEntryId",
                            0x0d => "DeviceAddress",
                            0x0e => "ValidCarrierProfiles",
                            0x0f => "CarrierProfile",
                            0x10 => "Status",
                            0x11 => "Replies",
//                                    0x05 => "Version='1.1'",
                            0x12 => "Devices",
                            0x13 => "Device",
                            0x14 => "Id",
                            0x15 => "Expiry",
                            0x16 => "NotifyGUID",
                        ),
                        4 => array (
                            0x05 => "Timezone",
                            0x06 => "AllDayEvent",
                            0x07 => "Attendees",
                            0x08 => "Attendee",
                            0x09 => "Email",
                            0x0a => "Name",
                            0x0b => "Body",
                            0x0c => "BodyTruncated",
                            0x0d => "BusyStatus",
                            0x0e => "Categories",
                            0x0f => "Category",
                            0x10 => "Rtf",
                            0x11 => "DtStamp",
                            0x12 => "EndTime",
                            0x13 => "Exception",
                            0x14 => "Exceptions",
                            0x15 => "Deleted",
                            0x16 => "ExceptionStartTime",
                            0x17 => "Location",
                            0x18 => "MeetingStatus",
                            0x19 => "OrganizerEmail",
                            0x1a => "OrganizerName",
                            0x1b => "Recurrence",
                            0x1c => "Type",
                            0x1d => "Until",
                            0x1e => "Occurrences",
                            0x1f => "Interval",
                            0x20 => "DayOfWeek",
                            0x21 => "DayOfMonth",
                            0x22 => "WeekOfMonth",
                            0x23 => "MonthOfYear",
                            0x24 => "Reminder",
                            0x25 => "Sensitivity",
                            0x26 => "Subject",
                            0x27 => "StartTime",
                            0x28 => "UID",
                        ),
                        5 => array (
                            0x05 => "Moves",
                            0x06 => "Move",
                            0x07 => "SrcMsgId",
                            0x08 => "SrcFldId",
                            0x09 => "DstFldId",
                            0x0a => "Response",
                            0x0b => "Status",
                            0x0c => "DstMsgId",
                        ),
                        6 => array (
                            0x05 => "GetItemEstimate",
                            0x06 => "Version",
                            0x07 => "Folders",
                            0x08 => "Folder",
                            0x09 => "FolderType",
                            0x0a => "FolderId",
                            0x0b => "DateTime",
                            0x0c => "Estimate",
                            0x0d => "Response",
                            0x0e => "Status",
                        ),
                        7 => array (
                            0x05 => "Folders",
                            0x06 => "Folder",
                            0x07 => "DisplayName",
                            0x08 => "ServerEntryId",
                            0x09 => "ParentId",
                            0x0a => "Type",
                            0x0b => "Response",
                            0x0c => "Status",
                            0x0d => "ContentClass",
                            0x0e => "Changes",
                            0x0f => "Add",
                            0x10 => "Remove",
                            0x11 => "Update",
                            0x12 => "SyncKey",
                            0x13 => "FolderCreate",
                            0x14 => "FolderDelete",
                            0x15 => "FolderUpdate",
                            0x16 => "FolderSync",
                            0x17 => "Count",
                            0x18 => "Version",
                        ),
                        8 => array (
                            0x05 => "CalendarId",
                            0x06 => "FolderId",
                            0x07 => "MeetingResponse",
                            0x08 => "RequestId",
                            0x09 => "Request",
                            0x0a => "Result",
                            0x0b => "Status",
                            0x0c => "UserResponse",
                            0x0d => "Version",
                        ),
                        9 => array (
                            0x05 => "Body",
                            0x06 => "BodySize",
                            0x07 => "BodyTruncated",
                            0x08 => "Categories",
                            0x09 => "Category",
                            0x0a => "Complete",
                            0x0b => "DateCompleted",
                            0x0c => "DueDate",
                            0x0d => "UtcDueDate",
                            0x0e => "Importance",
                            0x0f => "Recurrence",
                            0x10 => "Type",
                            0x11 => "Start",
                            0x12 => "Until",
                            0x13 => "Occurrences",
                            0x14 => "Interval",
                            0x16 => "DayOfWeek",
                            0x15 => "DayOfMonth",
                            0x17 => "WeekOfMonth",
                            0x18 => "MonthOfYear",
                            0x19 => "Regenerate",
                            0x1a => "DeadOccur",
                            0x1b => "ReminderSet",
                            0x1c => "ReminderTime",
                            0x1d => "Sensitivity",
                            0x1e => "StartDate",
                            0x1f => "UtcStartDate",
                            0x20 => "Subject",
                            0x21 => "Rtf",
                        ),
                        0xa => array (
                            0x05 => "ResolveRecipients",
                            0x06 => "Response",
                            0x07 => "Status",
                            0x08 => "Type",
                            0x09 => "Recipient",
                            0x0a => "DisplayName",
                            0x0b => "EmailAddress",
                            0x0c => "Certificates",
                            0x0d => "Certificate",
                            0x0e => "MiniCertificate",
                            0x0f => "Options",
                            0x10 => "To",
                            0x11 => "CertificateRetrieval",
                            0x12 => "RecipientCount",
                            0x13 => "MaxCertificates",
                            0x14 => "MaxAmbiguousRecipients",
                            0x15 => "CertificateCount",
                        ),
                        0xb => array (
                            0x05 => "ValidateCert",
                            0x06 => "Certificates",
                            0x07 => "Certificate",
                            0x08 => "CertificateChain",
                            0x09 => "CheckCRL",
                            0x0a => "Status",
                        ),
                        0xc => array (
                            0x05 => "CustomerId",
                            0x06 => "GovernmentId",
                            0x07 => "IMAddress",
                            0x08 => "IMAddress2",
                            0x09 => "IMAddress3",
                            0x0a => "ManagerName",
                            0x0b => "CompanyMainPhone",
                            0x0c => "AccountName",
                            0x0d => "NickName",
                            0x0e => "MMS",
                        ),
                        0xd => array (
                            0x05 => "Ping",
                            0x07 => "Status",
                            0x08 => "LifeTime",
                            0x09 => "Folders",
                            0x0a => "Folder",
                            0x0b => "ServerEntryId",
                            0x0c => "FolderType",
                        ),
                        0xe => array (
                            0x05 => "Provision",
                            0x06 => "Policies",
                            0x07 => "Policy",
                            0x08 => "PolicyType",
                            0x09 => "PolicyKey",
                            0x0A => "Data",
                            0x0B => "Status",
                            0x0C => "RemoteWipe",
                            0x0D => "EASProvisionDoc",
                            ),
                        0xf => array(
                            0x05 => "Search",
                            0x07 => "Store",
                            0x08 => "Name",
                            0x09 => "Query",
                            0x0A => "Options",
                            0x0B => "Range",
                            0x0C => "Status",
                            0x0D => "Response",
                            0x0E => "Result",
                            0x0F => "Properties",
                            0x10 => "Total",
                            0x11 => "EqualTo",
                            0x12 => "Value",
                            0x13 => "And",
                            0x14 => "Or",
                            0x15 => "FreeText",
                            0x17 => "DeepTraversal",
                            0x18 => "LongId",
                            0x19 => "RebuildResults",
                            0x1A => "LessThan",
                            0x1B => "GreaterThan",
                            0x1C => "Schema",
                            0x1D => "Supported",
                        ),
                        0x10 => array(
                            0x05 => "DisplayName",
                            0x06 => "Phone",
                            0x07 => "Office",
                            0x08 => "Title",
                            0x09 => "Company",
                            0x0A => "Alias",
                            0x0B => "FirstName",
                            0x0C => "LastName",
                            0x0D => "HomePhone",
                            0x0E => "MobilePhone",
                            0x0F => "EmailAddress",
                        )
                    ),
                    "namespaces" => array(
                        1 => "POOMCONTACTS",
                        2 => "POOMMAIL",
                        3 => "AirNotify",
                        4 => "POOMCAL",
                        5 => "Move",
                        6 => "GetItemEstimate",
                        7 => "FolderHierarchy",
                        8 => "MeetingResponse",
                        9 => "POOMTASKS",
                        0xA => "ResolveRecipients",
                        0xB => "ValidateCerts",
                        0xC => "POOMCONTACTS2",
                        0xD => "Ping",
                        0xE => "Provision",
                        0xF => "Search",
                        0x10 => "GAL",
                    )
                );
}

class WBXMLDecoder extends WBXMLDefs {
    private $in;

    private $version;
    private $publicid;
    private $publicstringid;
    private $charsetid;
    private $stringtable;

    private $tagcp = 0;
    private $attrcp = 0;

    private $ungetbuffer;

    private $logStack = array();

    /**
     * WBXML Decode Constructor
     *
     * @param  stream      $input          the incoming data stream
     *
     * @access public
     */
    public function WBXMLDecoder($input) {
        // make sure WBXML_DEBUG is defined. It should be at this point
        if (!defined('WBXML_DEBUG')) define('WBXML_DEBUG', false);

        $this->in = $input;

        $this->version = $this->getByte();
        $this->publicid = $this->getMBUInt();
        if($this->publicid == 0) {
            $this->publicstringid = $this->getMBUInt();
        }

        $this->charsetid = $this->getMBUInt();
        $this->stringtable = $this->getStringTable();
    }

    /**
     * Returns either start, content or end, and auto-concatenates successive content
     *
     * @access public
     * @return element/value
     */
    public function getElement() {
        $element = $this->getToken();

        switch($element[EN_TYPE]) {
            case EN_TYPE_STARTTAG:
                return $element;
            case EN_TYPE_ENDTAG:
                return $element;
            case EN_TYPE_CONTENT:
                while(1) {
                    $next = $this->getToken();
                    if($next == false)
                        return false;
                    else if($next[EN_TYPE] == EN_CONTENT) {
                        $element[EN_CONTENT] .= $next[EN_CONTENT];
                    } else {
                        $this->ungetElement($next);
                        break;
                    }
                }
                return $element;
        }

        return false;
    }

    /**
     * Get a peek at the next element
     *
     * @access public
     * @return element
     */
    public function peek() {
        $element = $this->getElement();
        $this->ungetElement($element);
        return $element;
    }

    /**
     * Get the element of a StartTag
     *
     * @param $tag
     *
     * @access public
     * @return element/boolean      returns false if not available
     */
    public function getElementStartTag($tag) {
        $element = $this->getToken();

        if($element[EN_TYPE] == EN_TYPE_STARTTAG && $element[EN_TAG] == $tag)
            return $element;
        else {
            ZLog::Write(LOGLEVEL_WBXMLSTACK, "Unmatched tag $tag:");
            ZLog::Write(LOGLEVEL_WBXMLSTACK, print_r($element,true));
            $this->ungetElement($element);
        }

        return false;
    }

    /**
     * Get the element of a EndTag
     *
     * @access public
     * @return element/boolean      returns false if not available
     */
    public function getElementEndTag() {
        $element = $this->getToken();

        if($element[EN_TYPE] == EN_TYPE_ENDTAG)
            return $element;
        else {
            ZLog::Write(LOGLEVEL_WBXMLSTACK, "Unmatched end tag:");
            ZLog::Write(LOGLEVEL_WBXMLSTACK, print_r($element,true));
            $bt = debug_backtrace();
            $c = count($bt);
            ZLog::Write(LOGLEVEL_WBXML, print_r($bt,true));
            ZLog::Write(LOGLEVEL_WBXMLSTACK, "From " . $bt[$c-2]["file"] . ":" . $bt[$c-2]["line"]);
            $this->ungetElement($element);
        }

        return false;
    }

    /**
     * Get the content of an element
     *
     * @access public
     * @return string/boolean       returns false if not available
     */
    public function getElementContent() {
        $element = $this->getToken();

        if($element[EN_TYPE] == EN_TYPE_CONTENT) {
            return $element[EN_CONTENT];
        }
        else {
            ZLog::Write(LOGLEVEL_WBXMLSTACK, "Unmatched content:");
            ZLog::Write(LOGLEVEL_WBXMLSTACK, print_r($element, true));
            $this->ungetElement($element);
        }

        return false;
    }

    /**
     * 'Ungets' an element writing it into a buffer to be 'get' again
     *
     * @param element       $element        the element to get ungetten
     *
     * @access public
     * @return
     */
    public function ungetElement($element) {
        if($this->ungetbuffer)
            ZLog::Write(LOGLEVEL_ERROR,"WBXML - Double unget!");

        $this->ungetbuffer = $element;
    }

    /**----------------------------------------------------------------------------------------------------------
     * Private WBXMLDecoder stuff
     */

    /**
     * Returns the next token
     *
     * @access private
     * @return token
     */
    private function getToken() {
        // See if there's something in the ungetBuffer
        if($this->ungetbuffer) {
            $element = $this->ungetbuffer;
            $this->ungetbuffer = false;
            return $element;
        }

        $el = $this->_getToken();
        $this->logToken($el);

        return $el;
    }

    /**
     * Log the a token to ZLog
     *
     * @param string    $el         token
     *
     * @access private
     * @return
     */
    private function logToken($el) {
        if(!WBXML_DEBUG)
            return;

        $spaces = str_repeat(" ", count($this->logStack));

        switch($el[EN_TYPE]) {
            case EN_TYPE_STARTTAG:
                if($el[EN_FLAGS] & EN_FLAGS_CONTENT) {
                    ZLog::Write(LOGLEVEL_WBXML,"I " . $spaces . " <". $el[EN_TAG] . ">");
                    array_push($this->logStack, $el[EN_TAG]);
                } else
                    ZLog::Write(LOGLEVEL_WBXML,"I " . $spaces . " <" . $el[EN_TAG] . "/>");

                break;
            case EN_TYPE_ENDTAG:
                $tag = array_pop($this->logStack);
                ZLog::Write(LOGLEVEL_WBXML,"I " . $spaces . "</" . $tag . ">");
                break;
            case EN_TYPE_CONTENT:
                ZLog::Write(LOGLEVEL_WBXML,"I " . $spaces . " " . $el[EN_CONTENT]);
                break;
        }
    }

    /**
     * Returns either a start tag, content or end tag
     *
     * @access private
     * @return
     */
    private function _getToken() {
        // Get the data from the input stream
        $element = array();

        while(1) {
            $byte = $this->getByte();

            if(!isset($byte))
                break;

            switch($byte) {
                case WBXML_SWITCH_PAGE:
                    $this->tagcp = $this->getByte();
                    continue;

                case WBXML_END:
                    $element[EN_TYPE] = EN_TYPE_ENDTAG;
                    return $element;

                case WBXML_ENTITY:
                    $entity = $this->getMBUInt();
                    $element[EN_TYPE] = EN_TYPE_CONTENT;
                    $element[EN_CONTENT] = $this->entityToCharset($entity);
                    return $element;

                case WBXML_STR_I:
                    $element[EN_TYPE] = EN_TYPE_CONTENT;
                    $element[EN_CONTENT] = $this->getTermStr();
                    return $element;

                case WBXML_LITERAL:
                    $element[EN_TYPE] = EN_TYPE_STARTTAG;
                    $element[EN_TAG] = $this->getStringTableEntry($this->getMBUInt());
                    $element[EN_FLAGS] = 0;
                    return $element;

                case WBXML_EXT_I_0:
                case WBXML_EXT_I_1:
                case WBXML_EXT_I_2:
                    $this->getTermStr();
                    // Ignore extensions
                    continue;

                case WBXML_PI:
                    // Ignore PI
                    $this->getAttributes();
                    continue;

                case WBXML_LITERAL_C:
                    $element[EN_TYPE] = EN_TYPE_STARTTAG;
                    $element[EN_TAG] = $this->getStringTableEntry($this->getMBUInt());
                    $element[EN_FLAGS] = EN_FLAGS_CONTENT;
                    return $element;

                case WBXML_EXT_T_0:
                case WBXML_EXT_T_1:
                case WBXML_EXT_T_2:
                    $this->getMBUInt();
                    // Ingore extensions;
                    continue;

                case WBXML_STR_T:
                    $element[EN_TYPE] = EN_TYPE_CONTENT;
                    $element[EN_CONTENT] = $this->getStringTableEntry($this->getMBUInt());
                    return $element;

                case WBXML_LITERAL_A:
                    $element[EN_TYPE] = EN_TYPE_STARTTAG;
                    $element[EN_TAG] = $this->getStringTableEntry($this->getMBUInt());
                    $element[EN_ATTRIBUTES] = $this->getAttributes();
                    $element[EN_FLAGS] = EN_FLAGS_ATTRIBUTES;
                    return $element;
                case WBXML_EXT_0:
                case WBXML_EXT_1:
                case WBXML_EXT_2:
                    continue;

                case WBXML_OPAQUE:
                    $length = $this->getMBUInt();
                    $element[EN_TYPE] = EN_TYPE_CONTENT;
                    $element[EN_CONTENT] = $this->getOpaque($length);
                    return $element;

                case WBXML_LITERAL_AC:
                    $element[EN_TYPE] = EN_TYPE_STARTTAG;
                    $element[EN_TAG] = $this->getStringTableEntry($this->getMBUInt());
                    $element[EN_ATTRIBUTES] = $this->getAttributes();
                    $element[EN_FLAGS] = EN_FLAGS_ATTRIBUTES | EN_FLAGS_CONTENT;
                    return $element;

                default:
                    $element[EN_TYPE] = EN_TYPE_STARTTAG;
                    $element[EN_TAG] = $this->getMapping($this->tagcp, $byte & 0x3f);
                    $element[EN_FLAGS] = ($byte & 0x80 ? EN_FLAGS_ATTRIBUTES : 0) | ($byte & 0x40 ? EN_FLAGS_CONTENT : 0);
                    if($byte & 0x80)
                        $element[EN_ATTRIBUTES] = $this->getAttributes();
                    return $element;
            }
        }
    }

    /**
     * Gets attributes
     *
     * @access private
     * @return
     */
    private function getAttributes() {
        $attributes = array();
        $attr = "";

        while(1) {
            $byte = $this->getByte();

            if(count($byte) == 0)
                break;

            switch($byte) {
                case WBXML_SWITCH_PAGE:
                    $this->attrcp = $this->getByte();
                    break;

                case WBXML_END:
                    if($attr != "")
                        $attributes += $this->splitAttribute($attr);

                    return $attributes;

                case WBXML_ENTITY:
                    $entity = $this->getMBUInt();
                    $attr .= $this->entityToCharset($entity);
                    return $element;

                case WBXML_STR_I:
                    $attr .= $this->getTermStr();
                    return $element;

                case WBXML_LITERAL:
                    if($attr != "")
                        $attributes += $this->splitAttribute($attr);

                    $attr = $this->getStringTableEntry($this->getMBUInt());
                    return $element;

                case WBXML_EXT_I_0:
                case WBXML_EXT_I_1:
                case WBXML_EXT_I_2:
                    $this->getTermStr();
                    continue;

                case WBXML_PI:
                case WBXML_LITERAL_C:
                    // Invalid
                    return false;

                case WBXML_EXT_T_0:
                case WBXML_EXT_T_1:
                case WBXML_EXT_T_2:
                    $this->getMBUInt();
                    continue;

                case WBXML_STR_T:
                    $attr .= $this->getStringTableEntry($this->getMBUInt());
                    return $element;

                case WBXML_LITERAL_A:
                    return false;

                case WBXML_EXT_0:
                case WBXML_EXT_1:
                case WBXML_EXT_2:
                    continue;

                case WBXML_OPAQUE:
                    $length = $this->getMBUInt();
                    $attr .= $this->getOpaque($length);
                    return $element;

                case WBXML_LITERAL_AC:
                    return false;

                default:
                    if($byte < 128) {
                        if($attr != "") {
                            $attributes += $this->splitAttribute($attr);
                            $attr = "";
                        }
                    }
                    $attr .= $this->getMapping($this->attrcp, $byte);
                    break;
            }
        }
    }

    /**
     * Splits an attribute
     *
     * @param string $attr     attribute to be splitted
     *
     * @access private
     * @return array
     */
    private function splitAttribute($attr) {
        $attributes = array();

        $pos = strpos($attr,chr(61)); // equals sign

        if($pos)
            $attributes[substr($attr, 0, $pos)] = substr($attr, $pos+1);
        else
            $attributes[$attr] = null;

        return $attributes;
    }

    /**
     * Reads from the stream until getting a string terminator
     *
     * @access private
     * @return string
     */
    private function getTermStr() {
        $str = "";
        while(1) {
            $in = $this->getByte();

            if($in == 0)
                break;
            else
                $str .= chr($in);
        }

        return $str;
    }

    /**
     * Reads $len from the input stream
     *
     * @param int   $len
     *
     * @access private
     * @return string
     */
    private function getOpaque($len) {
        return fread($this->in, $len);
    }

    /**
     * Reads one byte from the input stream
     *
     * @access private
     * @return int
     */
    private function getByte() {
        $ch = fread($this->in, 1);
        if(strlen($ch) > 0)
            return ord($ch);
        else
            return;
    }

    /**
     * Reads string length from the input stream
     *
     * @access private
     * @return
     */
    private function getMBUInt() {
        $uint = 0;

        while(1) {
          $byte = $this->getByte();

          $uint |= $byte & 0x7f;

          if($byte & 0x80)
              $uint = $uint << 7;
          else
              break;
        }

        return $uint;
    }

    /**
     * Reads string table from the input stream
     *
     * @access private
     * @return int
     */
    private function getStringTable() {
        $stringtable = "";

        $length = $this->getMBUInt();
        if($length > 0)
            $stringtable = fread($this->in, $length);

        return $stringtable;
    }

    /**
     * Returns the mapping for a specified codepage and id
     *
     * @param $cp   codepage
     * @param $id
     *
     * @access public
     * @return string
     */
    private function getMapping($cp, $id) {
        if(!isset($this->dtd["codes"][$cp]) || !isset($this->dtd["codes"][$cp][$id]))
            return false;
        else {
            if(isset($this->dtd["namespaces"][$cp])) {
                return $this->dtd["namespaces"][$cp] . ":" . $this->dtd["codes"][$cp][$id];
            } else
                return $this->dtd["codes"][$cp][$id];
        }
    }
}


class WBXMLEncoder extends WBXMLDefs {
    private $_dtd;
    private $_out;

    private $_tagcp;
    private $_attrcp;

    private $logStack = array();

    // We use a delayed output mechanism in which we only output a tag when it actually has something
    // in it. This can cause entire XML trees to disappear if they don't have output data in them; Ie
    // calling 'startTag' 10 times, and then 'endTag' will cause 0 bytes of output apart from the header.

    // Only when content() is called do we output the current stack of tags

    private $_stack;

    public function WBXMLEncoder($output) {
        $this->_out = $output;

        $this->_tagcp = 0;
        $this->_attrcp = 0;

        // reverse-map the DTD
        foreach($this->dtd["namespaces"] as $nsid => $nsname) {
            $this->_dtd["namespaces"][$nsname] = $nsid;
        }

        foreach($this->dtd["codes"] as $cp => $value) {
            $this->_dtd["codes"][$cp] = array();
            foreach($this->dtd["codes"][$cp] as $tagid => $tagname) {
                $this->_dtd["codes"][$cp][$tagname] = $tagid;
            }
        }
        $this->_stack = array();
    }

    /**
     * Puts the WBXML header on the stream
     *
     * @access public
     * @return
     */
    public function startWBXML() {
        header("Content-Type: application/vnd.ms-sync.wbxml");

        $this->outByte(0x03); // WBXML 1.3
        $this->outMBUInt(0x01); // Public ID 1
        $this->outMBUInt(106); // UTF-8
        $this->outMBUInt(0x00); // string table length (0)
    }

    /**
     * Puts a StartTag on the output stack
     *
     * @param $tag
     * @param $attributes
     * @param $nocontent
     *
     * @access public
     * @return
     */
    public function startTag($tag, $attributes = false, $nocontent = false) {
        $stackelem = array();

        if(!$nocontent) {
            $stackelem['tag'] = $tag;
            $stackelem['attributes'] = $attributes;
            $stackelem['nocontent'] = $nocontent;
            $stackelem['sent'] = false;

            array_push($this->_stack, $stackelem);

            // If 'nocontent' is specified, then apparently the user wants to force
            // output of an empty tag, and we therefore output the stack here
        } else {
            $this->_outputStack();
            $this->_startTag($tag, $attributes, $nocontent);
        }
    }

    /**
     * Puts an EndTag on the stack
     *
     * @access public
     * @return
     */
    public function endTag() {
        $stackelem = array_pop($this->_stack);

        // Only output end tags for items that have had a start tag sent
        if($stackelem['sent']) {
            $this->_endTag();
        }
    }

    /**
     * Puts content on the output stack
     *
     * @param $content
     *
     * @access public
     * @return string
     */
    public function content($content) {
        // We need to filter out any \0 chars because it's the string terminator in WBXML. We currently
        // cannot send \0 characters within the XML content anywhere.
        $content = str_replace("\0","",$content);

        if("x" . $content == "x")
            return;
        $this->_outputStack();
        $this->_content($content);
    }

    /**----------------------------------------------------------------------------------------------------------
     * Private WBXMLEncoder stuff
     */

    /**
     * Output any tags on the stack that haven't been output yet
     *
     * @access private
     * @return
     */
    private function _outputStack() {
        for($i=0;$i<count($this->_stack);$i++) {
            if(!$this->_stack[$i]['sent']) {
                $this->_startTag($this->_stack[$i]['tag'], $this->_stack[$i]['attributes'], $this->_stack[$i]['nocontent']);
                $this->_stack[$i]['sent'] = true;
            }
        }
    }

    /**
     * Outputs an actual start tag
     *
     * @access private
     * @return
     */
    private function _startTag($tag, $attributes = false, $nocontent = false) {
        $this->logStartTag($tag, $attributes, $nocontent);

        $mapping = $this->getMapping($tag);

        if(!$mapping)
            return false;

        if($this->_tagcp != $mapping["cp"]) {
            $this->outSwitchPage($mapping["cp"]);
            $this->_tagcp = $mapping["cp"];
        }

        $code = $mapping["code"];
        if(isset($attributes) && is_array($attributes) && count($attributes) > 0) {
            $code |= 0x80;
        }

        if(!isset($nocontent) || !$nocontent)
            $code |= 0x40;

        $this->outByte($code);

        if($code & 0x80)
            $this->outAttributes($attributes);
    }

    /**
     * Outputs actual data
     *
     * @access private
     * @return
     */
    private function _content($content) {
        $this->logContent($content);
        $this->outByte(WBXML_STR_I);
        $this->outTermStr($content);
    }

    /**
     * Outputs an actual end tag
     *
     * @access private
     * @return
     */
    private function _endTag() {
        $this->logEndTag();
        $this->outByte(WBXML_END);
    }

    /**
     * Outputs a byte
     *
     * @param $byte
     *
     * @access private
     * @return
     */
    private function outByte($byte) {
        fwrite($this->_out, chr($byte));
    }

    /**
     * Outputs a string table
     *
     * @param $uint
     *
     * @access private
     * @return
     */
    private function outMBUInt($uint) {
        while(1) {
            $byte = $uint & 0x7f;
            $uint = $uint >> 7;
            if($uint == 0) {
                $this->outByte($byte);
                break;
            } else {
                $this->outByte($byte | 0x80);
            }
        }
    }

    /**
     * Outputs content with string terminator
     *
     * @param $content
     *
     * @access private
     * @return
     */
    private function outTermStr($content) {
        fwrite($this->_out, $content);
        fwrite($this->_out, chr(0));
    }

    /**
     * Output attributes
     * We don't actually support this, because to do so, we would have
     * to build a string table before sending the data (but we can't
     * because we're streaming), so we'll just send an END, which just
     * terminates the attribute list with 0 attributes.
     *
     * @access private
     * @return
     */
    private function outAttributes() {
        $this->outByte(WBXML_END);
    }

    /**
     * Switches the codepage
     *
     * @param $page
     *
     * @access private
     * @return
     */
    private function outSwitchPage($page) {
        $this->outByte(WBXML_SWITCH_PAGE);
        $this->outByte($page);
    }

    /**
     * Get the mapping for a tag
     *
     * @param $tag
     *
     * @access private
     * @return array
     */
    private function getMapping($tag) {
        $mapping = array();

        $split = $this->splitTag($tag);

        if(isset($split["ns"])) {
            $cp = $this->_dtd["namespaces"][$split["ns"]];
        }
        else {
            $cp = 0;
        }

        $code = $this->_dtd["codes"][$cp][$split["tag"]];

        $mapping["cp"] = $cp;
        $mapping["code"] = $code;

        return $mapping;
    }

    /**
     * Split a tag from a the fulltag (namespace + tag)
     *
     * @param $fulltag
     *
     * @access private
     * @return array        keys: 'ns' (namespace), 'tag' (tag)
     */
    private function splitTag($fulltag) {
        $ns = false;
        $pos = strpos($fulltag, chr(58)); // chr(58) == ':'

        if($pos) {
            $ns = substr($fulltag, 0, $pos);
            $tag = substr($fulltag, $pos+1);
        }
        else {
            $tag = $fulltag;
        }

        $ret = array();
        if($ns)
            $ret["ns"] = $ns;
        $ret["tag"] = $tag;

        return $ret;
    }

    /**
     * Logs a StartTag to ZLog
     *
     * @param $tag
     * @param $attr
     * @param $nocontent
     *
     * @access private
     * @return
     */
    private function logStartTag($tag, $attr, $nocontent) {
        if(!WBXML_DEBUG)
            return;

        $spaces = str_repeat(" ", count($this->logStack));
        if($nocontent)
            ZLog::Write(LOGLEVEL_WBXML,"O " . $spaces . " <$tag/>");
        else {
            array_push($this->logStack, $tag);
            ZLog::Write(LOGLEVEL_WBXML,"O " . $spaces . " <$tag>");
        }
    }

    /**
     * Logs a EndTag to ZLog
     *
     * @access private
     * @return
     */
    private function logEndTag() {
        if(!WBXML_DEBUG)
            return;

        $spaces = str_repeat(" ", count($this->logStack));
        $tag = array_pop($this->logStack);
        ZLog::Write(LOGLEVEL_WBXML,"O " . $spaces . "</$tag>");
    }

    /**
     * Logs content to ZLog
     *
     * @param $content
     *
     * @access private
     * @return
     */
    private function logContent($content) {
        if(!WBXML_DEBUG)
            return;

        $spaces = str_repeat(" ", count($this->logStack));
        ZLog::Write(LOGLEVEL_WBXML,"O " . $spaces . $content);
    }
}
