<?php
/***********************************************
* File      :   utils.php
* Project   :   Z-Push
* Descr     :   Several utility functions
*
* Created   :   03.04.2008
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

class Utils {
    /**
     * Prints a variable as string
     * If a boolean is sent, 'true' or 'false' is displayed
     *
     * @param string $var
     * @access public
     * @return string
     */
    static public function PrintAsString($var) {
        return ($var)?(($var===true)?'true':$var):'false';
    }

    /**
     * Splits a "domain\user" string into two values
     * If the string cotains only the user, domain is returned empty
     *
     * @param string    $domainuser
     *
     * @access public
     * @return array    index 0: user  1: domain
     */
    static public function SplitDomainUser($domainuser) {
        $pos = strrpos($domainuser, '\\');
        if($pos === false){
            $user = $domainuser;
            $domain = '';
        }
        else{
            $domain = substr($domainuser,0,$pos);
            $user = substr($domainuser,$pos+1);
        }
        return array($user, $domain);
    }

    /**
     * iPhone defines standard summer time information for current year only,
     * starting with time change in February. Dates from the 1st January until
     * the time change are undefined and the server uses GMT or its current time.
     * The function parses the ical attachment and replaces DTSTART one year back
     * in VTIMEZONE section if the event takes place in this undefined time.
     * See also http://developer.berlios.de/mantis/view.php?id=311
     *
     * @param string    $ical               iCalendar data
     *
     * @access public
     * @return string
     */
    static public function IcalTimezoneFix($ical) {
        $eventDate = substr($ical, (strpos($ical, ":", strpos($ical, "DTSTART", strpos($ical, "BEGIN:VEVENT")))+1), 8);
        $posStd = strpos($ical, "DTSTART:", strpos($ical, "BEGIN:STANDARD")) + strlen("DTSTART:");
        $posDst = strpos($ical, "DTSTART:", strpos($ical, "BEGIN:DAYLIGHT")) + strlen("DTSTART:");
        $beginStandard = substr($ical, $posStd , 8);
        $beginDaylight = substr($ical, $posDst , 8);

        if (($eventDate < $beginStandard) && ($eventDate < $beginDaylight) ) {
            ZLog::Write(LOGLEVEL_DEBUG,"icalTimezoneFix for event on $eventDate, standard:$beginStandard, daylight:$beginDaylight");
            $year = intval(date("Y")) - 1;
            $ical = substr_replace($ical, $year, (($beginStandard < $beginDaylight) ? $posDst : $posStd), strlen($year));
        }

        return $ical;
    }

    /**
     * Build an address string from the components
     *
     * @param string    $street     the street
     * @param string    $zip        the zip code
     * @param string    $city       the city
     * @param string    $state      the state
     * @param string    $country    the country
     *
     * @access public
     * @return string   the address string or null
     */
    static public function BuildAddressString($street, $zip, $city, $state, $country) {
        $out = "";

        if (isset($country) && $street != "") $out = $country;

        $zcs = "";
        if (isset($zip) && $zip != "") $zcs = $zip;
        if (isset($city) && $city != "") $zcs .= (($zcs)?" ":"") . $city;
        if (isset($state) && $state != "") $zcs .= (($zcs)?" ":"") . $state;
        if ($zcs) $out = $zcs . "\r\n" . $out;

        if (isset($street) && $street != "") $out = $street . (($out)?"\r\n\r\n". $out: "") ;

        return ($out)?$out:null;
    }

    /**
     * Checks if the PHP-MAPI extension is available and in a requested version
     *
     * @param string    $version    the version to be checked ("6.30.10-18495", parts or build number)
     *
     * @access public
     * @return boolean installed version is superior to the checked strin
     */
    static public function CheckMapiExtVersion($version = "") {
        // compare build number if requested
        if (preg_match('/^\d+$/', $version) && strlen($version) > 3) {
            $vs = preg_split('/-/', phpversion("mapi"));
            return ($version <= $vs[1]);
        }

        if (extension_loaded("mapi")){
            if (version_compare(phpversion("mapi"), $version) == -1){
                return false;
            }
        }
        else
            return false;

        return true;
    }

    /**
     * Parses and returns an ecoded vCal-Uid from an
     * OL compatible GlobalObjectID
     *
     * @param string    $olUid      an OL compatible GlobalObjectID
     *
     * @access public
     * @return string   the vCal-Uid if available in the olUid, else the original olUid as HEX
     */
    static public function GetICalUidFromOLUid($olUid){
        //check if "vCal-Uid" is somewhere in outlookid case-insensitive
        $icalUid = stristr($olUid, "vCal-Uid");
        if ($icalUid !== false) {
            //get the length of the ical id - go back 4 position from where "vCal-Uid" was found
            $begin = unpack("V", substr($olUid, strlen($icalUid) * (-1) - 4, 4));
            //remove "vCal-Uid" and packed "1" and use the ical id length
            return substr($icalUid, 12, ($begin[1] - 13));
        }
        return strtoupper(bin2hex($olUid));
    }

    /**
     * Checks the given UID if it is an OL compatible GlobalObjectID
     * If not, the given UID is encoded inside the GlobalObjectID
     *
     * @param string    $icalUid    an appointment uid as HEX
     *
     * @access public
     * @return string   an OL compatible GlobalObjectID
     *
     */
    static public function GetOLUidFromICalUid($icalUid) {
        if (strlen($icalUid) <= 64) {
            $len = 13 + strlen($icalUid);
            $OLUid = pack("V", $len);
            $OLUid .= "vCal-Uid";
            $OLUid .= pack("V", 1);
            $OLUid .= $icalUid;
            return hex2bin("040000008200E00074C5B7101A82E0080000000000000000000000000000000000000000". bin2hex($OLUid). "00");
        }
        else
           return hex2bin($icalUid);
    }

    /**
     * Extracts the basedate of the GlobalObjectID and the RecurStartTime
     *
     * @param string    $goid           OL compatible GlobalObjectID
     * @param long      $recurStartTime
     *
     * @access public
     * @return long     basedate
     */
    static public function ExtractBaseDate($goid, $recurStartTime) {
        $hexbase = substr(bin2hex($goid), 32, 8);
        $day = hexdec(substr($hexbase, 6, 2));
        $month = hexdec(substr($hexbase, 4, 2));
        $year = hexdec(substr($hexbase, 0, 4));

        if ($day && $month && $year) {
            $h = $recurStartTime >> 12;
            $m = ($recurStartTime - $h * 4096) >> 6;
            $s = $recurStartTime - $h * 4096 - $m * 64;

            return gmmktime($h, $m, $s, $month, $day, $year);
        }
        else
            return false;
    }

    /**
     * Converts SYNC_FILTERTYPE into a timestamp
     *
     * @param int       Filtertype
     *
     * @access public
     * @return long
     */
    static public function GetCutOffDate($restrict) {
        switch($restrict) {
            case SYNC_FILTERTYPE_1DAY:
                $back = 60 * 60 * 24;
                break;
            case SYNC_FILTERTYPE_3DAYS:
                $back = 60 * 60 * 24 * 3;
                break;
            case SYNC_FILTERTYPE_1WEEK:
                $back = 60 * 60 * 24 * 7;
                break;
            case SYNC_FILTERTYPE_2WEEKS:
                $back = 60 * 60 * 24 * 14;
                break;
            case SYNC_FILTERTYPE_1MONTH:
                $back = 60 * 60 * 24 * 31;
                break;
            case SYNC_FILTERTYPE_3MONTHS:
                $back = 60 * 60 * 24 * 31 * 3;
                break;
            case SYNC_FILTERTYPE_6MONTHS:
                $back = 60 * 60 * 24 * 31 * 6;
                break;
            default:
                break;
        }

        if(isset($back)) {
            $date = time() - $back;
            return $date;
        } else
            return 0; // unlimited
    }

    /**
     * Converts SYNC_TRUNCATION into bytes
     *
     * @param int       SYNC_TRUNCATION
     *
     * @return long
     */
    static public function GetTruncSize($truncation) {
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

    /**
     * Truncate an UTF-8 encoded sting correctly
     *
     * If it's not possible to truncate properly, an empty string is returned
     *
     * @param string $string - the string
     * @param string $length - position where string should be cut
     * @return string truncated string
     */
    static public function Utf8_truncate($string, $length) {
        if (strlen($string) <= $length)
            return $string;

        while($length >= 0) {
            if ((ord($string[$length]) < 0x80) || (ord($string[$length]) >= 0xC0))
                return substr($string, 0, $length);

            $length--;
        }
        return "";
    }
}


/**
 * Complementary function to bin2hey() which converts a hex entryid to a binary entryid.
 * hex2bin() is not part of the Utils class
 *
 * @param string    $data   the hexadecimal string
 *
 * @returns string
 */
function hex2bin($data) {
    return pack("H*", $data);
}


// TODO Win1252/UTF8 functions are deprecated and will be removed sometime

function utf8_to_windows1252($string, $option = "") {
    //if the store supports unicode return the string without converting it
    if (defined('STORE_SUPPORTS_UNICODE') && STORE_SUPPORTS_UNICODE == true) return $string;

    if (function_exists("iconv")){
        return @iconv("UTF-8", "Windows-1252" . $option, $string);
    }else{
        return utf8_decode($string); // no euro support here
    }
}

function windows1252_to_utf8($string, $option = "") {
    //if the store supports unicode return the string without converting it
    if (defined('STORE_SUPPORTS_UNICODE') && STORE_SUPPORTS_UNICODE == true) return $string;

    if (function_exists("iconv")){
        return @iconv("Windows-1252", "UTF-8" . $option, $string);
    }else{
        return utf8_encode($string); // no euro support here
    }
}

function w2u($string) { return windows1252_to_utf8($string); }
function u2w($string) { return utf8_to_windows1252($string); }

function w2ui($string) { return windows1252_to_utf8($string, "//TRANSLIT"); }
function u2wi($string) { return utf8_to_windows1252($string, "//TRANSLIT"); }


?>