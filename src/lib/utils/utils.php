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
      return ($var)?(($var===true)?'true':$var):(($var===false)?'false':(($var==='')?'empty':$var));
//return ($var)?(($var===true)?'true':$var):'false';
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
     * Build the fileas string from the components according to the configuration.
     *
     * @param string $lastname
     * @param string $firstname
     * @param string $middlename
     * @param string $company
     *
     * @access public
     * @return string fileas
     */
    static public function BuildFileAs($lastname = "", $firstname = "", $middlename = "", $company = "") {
        if (defined('FILEAS_ORDER')) {
            $fileas = $lastfirst = $firstlast = "";
            $names = trim ($firstname . " " . $middlename);
            $lastname = trim($lastname);
            $company = trim($company);

            // lastfirst is "lastname, firstname middlename"
            // firstlast is "firstname middlename lastname"
            if (strlen($lastname) > 0) {
                $lastfirst = $lastname;
                if (strlen($names) > 0){
                    $lastfirst .= ", $names";
                    $firstlast = "$names $lastname";
                }
                else {
                    $firstlast = $lastname;
                }
            }
            elseif (strlen($names) > 0) {
                $lastfirst = $firstlast = $names;
            }

            // if fileas with a company is selected
            // but company is emtpy then it will
            // fallback to firstlast or lastfirst
            // (depending on which is selected for company)
            switch (FILEAS_ORDER) {
                case SYNC_FILEAS_COMPANYONLY:
                    if (strlen($company) > 0) {
                        $fileas = $company;
                    }
                    elseif (strlen($firstlast) > 0)
                        $fileas = $firstlast;
                    break;
                case SYNC_FILEAS_COMPANYLAST:
                    if (strlen($company) > 0) {
                        $fileas = $company;
                        if (strlen($lastfirst) > 0)
                            $fileas .= "($lastfirst)";
                    }
                    elseif (strlen($lastfirst) > 0)
                        $fileas = $lastfirst;
                    break;
                case SYNC_FILEAS_COMPANYFIRST:
                    if (strlen($company) > 0) {
                        $fileas = $company;
                        if (strlen($firstlast) > 0) {
                            $fileas .= " ($firstlast)";
                        }
                    }
                    elseif (strlen($firstlast) > 0) {
                        $fileas = $firstlast;
                    }
                    break;
                case SYNC_FILEAS_FIRSTCOMPANY:
                    if (strlen($firstlast) > 0) {
                        $fileas = $firstlast;
                        if (strlen($company) > 0) {
                            $fileas .= " ($company)";
                        }
                    }
                    elseif (strlen($company) > 0) {
                        $fileas = $company;
                    }
                    break;
                case SYNC_FILEAS_LASTCOMPANY:
                    if (strlen($lastfirst) > 0) {
                        $fileas = $lastfirst;
                        if (strlen($company) > 0) {
                            $fileas .= " ($company)";
                        }
                    }
                    elseif (strlen($company) > 0) {
                        $fileas = $company;
                    }
                    break;
                case SYNC_FILEAS_LASTFIRST:
                    if (strlen($lastfirst) > 0) {
                        $fileas = $lastfirst;
                    }
                    break;
                default:
                    $fileas = $firstlast;
                    break;
            }
            if (strlen($fileas) == 0)
                ZLog::Write(LOGLEVEL_DEBUG, "Fileas is empty.");
            return $fileas;
        }
        ZLog::Write(LOGLEVEL_DEBUG, "FILEAS_ORDER not defined. Add it to your config.php.");
        return null;
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
            case SYNC_TRUNCATION_2K:
                return 2*1024;
            case SYNC_TRUNCATION_5K:
                return 5*1024;
            case SYNC_TRUNCATION_10K:
                return 10*1024;
            case SYNC_TRUNCATION_20K:
                return 20*1024;
            case SYNC_TRUNCATION_50K:
                return 50*1024;
            case SYNC_TRUNCATION_100K:
                return 100*1024;
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
        // make sure length is always an interger
        $length = (int)$length;

        if (strlen($string) <= $length)
            return $string;

        while($length >= 0) {
            if ((ord($string[$length]) < 0x80) || (ord($string[$length]) >= 0xC0))
                return substr($string, 0, $length);

            $length--;
        }
        return "";
    }

    /**
     * Indicates if the specified folder type is a system folder
     *
     * @param int            $foldertype
     *
     * @access public
     * @return boolean
     */
    static public function IsSystemFolder($foldertype) {
        return ($foldertype == SYNC_FOLDER_TYPE_INBOX || $foldertype == SYNC_FOLDER_TYPE_DRAFTS || $foldertype == SYNC_FOLDER_TYPE_WASTEBASKET || $foldertype == SYNC_FOLDER_TYPE_SENTMAIL ||
                $foldertype == SYNC_FOLDER_TYPE_OUTBOX || $foldertype == SYNC_FOLDER_TYPE_TASK || $foldertype == SYNC_FOLDER_TYPE_APPOINTMENT || $foldertype == SYNC_FOLDER_TYPE_CONTACT ||
                $foldertype == SYNC_FOLDER_TYPE_NOTE || $foldertype == SYNC_FOLDER_TYPE_JOURNAL) ? true:false;
    }

    /**
     * Our own utf7_decode function because imap_utf7_decode converts a string
     * into ISO-8859-1 encoding which doesn't have euro sign (it will be converted
     * into two chars: [space](ascii 32) and "¬" ("not sign", ascii 172)). Also
     * php iconv function expects '+' as delimiter instead of '&' like in IMAP.
     *
     * @param string $string IMAP folder name
     *
     * @access public
     * @return string
    */
    static public function Utf7_iconv_decode($string) {
        //do not alter string if there aren't any '&' or '+' chars because
        //it won't have any utf7-encoded chars and nothing has to be escaped.
        if (strpos($string, '&') === false && strpos($string, '+') === false ) return $string;

        //Get the string length and go back through it making the replacements
        //necessary
        $len = strlen($string) - 1;
        while ($len > 0) {
            //look for '&-' sequence and replace it with '&'
            if ($len > 0 && $string{($len-1)} == '&' && $string{$len} == '-') {
                $string = substr_replace($string, '&', $len - 1, 2);
                $len--; //decrease $len as this char has alreasy been processed
            }
            //search for '&' which weren't found in if clause above and
            //replace them with '+' as they mark an utf7-encoded char
            if ($len > 0 && $string{($len-1)} == '&') {
                $string = substr_replace($string, '+', $len - 1, 1);
                $len--; //decrease $len as this char has alreasy been processed
            }
            //finally "escape" all remaining '+' chars
            if ($len > 0 && $string{($len-1)} == '+') {
                $string = substr_replace($string, '+-', $len - 1, 1);
            }
            $len--;
        }
        return $string;
    }

    /**
     * Our own utf7_encode function because the string has to be converted from
     * standard UTF7 into modified UTF7 (aka UTF7-IMAP).
     *
     * @param string $str IMAP folder name
     *
     * @access public
     * @return string
    */
    static public function Utf7_iconv_encode($string) {
        //do not alter string if there aren't any '&' or '+' chars because
        //it won't have any utf7-encoded chars and nothing has to be escaped.
        if (strpos($string, '&') === false && strpos($string, '+') === false ) return $string;

        //Get the string length and go back through it making the replacements
        //necessary
        $len = strlen($string) - 1;
        while ($len > 0) {
            //look for '&-' sequence and replace it with '&'
            if ($len > 0 && $string{($len-1)} == '+' && $string{$len} == '-') {
                $string = substr_replace($string, '+', $len - 1, 2);
                $len--; //decrease $len as this char has alreasy been processed
            }
            //search for '&' which weren't found in if clause above and
            //replace them with '+' as they mark an utf7-encoded char
            if ($len > 0 && $string{($len-1)} == '+') {
                $string = substr_replace($string, '&', $len - 1, 1);
                $len--; //decrease $len as this char has alreasy been processed
            }
            //finally "escape" all remaining '+' chars
            if ($len > 0 && $string{($len-1)} == '&') {
                $string = substr_replace($string, '&-', $len - 1, 1);
            }
            $len--;
        }
        return $string;
    }

    /**
     * Converts an UTF-7 encoded string into an UTF-8 string.
     *
     * @param string $string to convert
     *
     * @access public
     * @return string
     */
    static public function Utf7_to_utf8($string) {
        if (function_exists("iconv")){
            return @iconv("UTF-7", "UTF-8", $string);
        }
        return $string;
    }

    /**
     * Converts an UTF-8 encoded string into an UTF-7 string.
     *
     * @param string $string to convert
     *
     * @access public
     * @return string
     */
    static public function Utf8_to_utf7($string) {
        if (function_exists("iconv")){
            return @iconv("UTF-8", "UTF-7", $string);
        }
        return $string;
    }

    /**
     * Checks for valid email addresses
     * The used regex actually only checks if a valid email address is part of the submitted string
     * it also returns true for the mailbox format, but this is not checked explicitly
     *
     * @param string $email     address to be checked
     *
     * @access public
     * @return boolean
     */
    static public function CheckEmail($email) {
        return (bool) preg_match('#([a-zA-Z0-9_\-])+(\.([a-zA-Z0-9_\-])+)*@((\[(((([0-1])?([0-9])?[0-9])|(2[0-4][0-9])|(2[0-5][0-5])))\.(((([0-1])?([0-9])?[0-9])|(2[0-4][0-9])|(2[0-5][0-5])))\.(((([0-1])?([0-9])?[0-9])|(2[0-4][0-9])|(2[0-5][0-5])))\.(((([0-1])?([0-9])?[0-9])|(2[0-4][0-9])|(2[0-5][0-5]))\]))|((([a-zA-Z0-9])+(([\-])+([a-zA-Z0-9])+)*\.)+([a-zA-Z])+(([\-])+([a-zA-Z0-9])+)*)|localhost)#', $email);
    }

    /**
     * Checks if a string is base64 encoded
     *
     * @param string $string    the string to be checked
     *
     * @access public
     * @return boolean
     */
    static public function IsBase64String($string) {
        return (bool) preg_match("#^([A-Za-z0-9+/]{4})*([A-Za-z0-9+/]{2}==|[A-Za-z0-9+\/]{3}=|[A-Za-z0-9+/]{4})?$#", $string);
    }

    /**
     * Decodes base64 encoded query parameters. Based on dw2412 contribution.
     *
     * @param string $query     the query to decode
     *
     * @access public
     * @return array
     */
    static public function DecodeBase64URI($query) {
        /*
         * The query string has a following structure. Number in () is position:
         * 1 byte       - protocoll version (0)
         * 1 byte       - command code (1)
         * 2 bytes      - locale (2)
         * 1 byte       - device ID length (4)
         * variable     - device ID (4+device ID length)
         * 1 byte       - policy key length (5+device ID length)
         * 0 or 4 bytes - policy key (5+device ID length + policy key length)
         * 1 byte       - device type length (6+device ID length + policy key length)
         * variable     - device type (6+device ID length + policy key length + device type length)
         * variable     - command parameters, array which consists of:
         *                      1 byte      - tag
         *                      1 byte      - length
         *                      variable    - value of the parameter
         *
         */
        $decoded = base64_decode($query);
        $devIdLength = ord($decoded[4]); //device ID length
        $polKeyLength = ord($decoded[5+$devIdLength]); //policy key length
        $devTypeLength = ord($decoded[6+$devIdLength+$polKeyLength]); //device type length
        //unpack the decoded query string values
        $unpackedQuery = unpack("CProtVer/CCommand/vLocale/CDevIDLen/H".($devIdLength*2)."DevID/CPolKeyLen".($polKeyLength == 4 ? "/VPolKey" : "")."/CDevTypeLen/A".($devTypeLength)."DevType", $decoded);

        //get the command parameters
        $pos = 7 + $devIdLength + $polKeyLength + $devTypeLength;
        $decoded = substr($decoded, $pos);
        while (strlen($decoded) > 0) {
            $paramLength = ord($decoded[1]);
            $unpackedParam = unpack("CParamTag/CParamLength/A".$paramLength."ParamValue", $decoded);
            $unpackedQuery[ord($decoded[0])] = $unpackedParam['ParamValue'];
            //remove parameter from decoded query string
            $decoded = substr($decoded, 2 + $paramLength);
        }
        return $unpackedQuery;
    }

    /**
     * Returns a command string for a given command code.
     *
     * @param int $code
     *
     * @access public
     * @return string or false if code is unknown
     */
    public static function GetCommandFromCode($code) {
        switch ($code) {
            case ZPush::COMMAND_SYNC:                 return 'Sync';
            case ZPush::COMMAND_SENDMAIL:             return 'SendMail';
            case ZPush::COMMAND_SMARTFORWARD:         return 'SmartForward';
            case ZPush::COMMAND_SMARTREPLY:           return 'SmartReply';
            case ZPush::COMMAND_GETATTACHMENT:        return 'GetAttachment';
            case ZPush::COMMAND_FOLDERSYNC:           return 'FolderSync';
            case ZPush::COMMAND_FOLDERCREATE:         return 'FolderCreate';
            case ZPush::COMMAND_FOLDERDELETE:         return 'FolderDelete';
            case ZPush::COMMAND_FOLDERUPDATE:         return 'FolderUpdate';
            case ZPush::COMMAND_MOVEITEMS:            return 'MoveItems';
            case ZPush::COMMAND_GETITEMESTIMATE:      return 'GetItemEstimate';
            case ZPush::COMMAND_MEETINGRESPONSE:      return 'MeetingResponse';
            case ZPush::COMMAND_SEARCH:               return 'Search';
            case ZPush::COMMAND_SETTINGS:             return 'Settings';
            case ZPush::COMMAND_PING:                 return 'Ping';
            case ZPush::COMMAND_ITEMOPERATIONS:       return 'ItemOperations';
            case ZPush::COMMAND_PROVISION:            return 'Provision';
            case ZPush::COMMAND_RESOLVERECIPIENTS:    return 'ResolveRecipients';
            case ZPush::COMMAND_VALIDATECERT:         return 'ValidateCert';

            // Deprecated commands
            case ZPush::COMMAND_GETHIERARCHY:         return 'GetHierarchy';
            case ZPush::COMMAND_CREATECOLLECTION:     return 'CreateCollection';
            case ZPush::COMMAND_DELETECOLLECTION:     return 'DeleteCollection';
            case ZPush::COMMAND_MOVECOLLECTION:       return 'MoveCollection';
            case ZPush::COMMAND_NOTIFY:               return 'Notify';

            // Webservice commands
            case ZPush::COMMAND_WEBSERVICE_DEVICE:    return 'WebserviceDevice';
        }
        return false;
    }

    /**
     * Returns a command code for a given command.
     *
     * @param string $command
     *
     * @access public
     * @return int or false if command is unknown
     */
    public static function GetCodeFromCommand($command) {
        switch ($command) {
            case 'Sync':                 return ZPush::COMMAND_SYNC;
            case 'SendMail':             return ZPush::COMMAND_SENDMAIL;
            case 'SmartForward':         return ZPush::COMMAND_SMARTFORWARD;
            case 'SmartReply':           return ZPush::COMMAND_SMARTREPLY;
            case 'GetAttachment':        return ZPush::COMMAND_GETATTACHMENT;
            case 'FolderSync':           return ZPush::COMMAND_FOLDERSYNC;
            case 'FolderCreate':         return ZPush::COMMAND_FOLDERCREATE;
            case 'FolderDelete':         return ZPush::COMMAND_FOLDERDELETE;
            case 'FolderUpdate':         return ZPush::COMMAND_FOLDERUPDATE;
            case 'MoveItems':            return ZPush::COMMAND_MOVEITEMS;
            case 'GetItemEstimate':      return ZPush::COMMAND_GETITEMESTIMATE;
            case 'MeetingResponse':      return ZPush::COMMAND_MEETINGRESPONSE;
            case 'Search':               return ZPush::COMMAND_SEARCH;
            case 'Settings':             return ZPush::COMMAND_SETTINGS;
            case 'Ping':                 return ZPush::COMMAND_PING;
            case 'ItemOperations':       return ZPush::COMMAND_ITEMOPERATIONS;
            case 'Provision':            return ZPush::COMMAND_PROVISION;
            case 'ResolveRecipients':    return ZPush::COMMAND_RESOLVERECIPIENTS;
            case 'ValidateCert':         return ZPush::COMMAND_VALIDATECERT;

            // Deprecated commands
            case 'GetHierarchy':         return ZPush::COMMAND_GETHIERARCHY;
            case 'CreateCollection':     return ZPush::COMMAND_CREATECOLLECTION;
            case 'DeleteCollection':     return ZPush::COMMAND_DELETECOLLECTION;
            case 'MoveCollection':       return ZPush::COMMAND_MOVECOLLECTION;
            case 'Notify':               return ZPush::COMMAND_NOTIFY;

            // Webservice commands
            case 'WebserviceDevice':     return ZPush::COMMAND_WEBSERVICE_DEVICE;
        }
        return false;
    }

    /**
     * Normalize the given timestamp to the start of the day
     *
     * @param long      $timestamp
     *
     * @access private
     * @return long
     */
    public static function getDayStartOfTimestamp($timestamp) {
        return $timestamp - ($timestamp % (60 * 60 * 24));
    }

    /**
     * Returns a formatted string output from an optional timestamp.
     * If no timestamp is sent, NOW is used.
     *
     * @param long  $timestamp
     *
     * @access public
     * @return string
     */
    public static function GetFormattedTime($timestamp = false) {
        if (!$timestamp)
            return @strftime("%d/%m/%Y %H:%M:%S");
        else
            return @strftime("%d/%m/%Y %H:%M:%S", $timestamp);
    }


   /**
    * Get charset name from a codepage
    *
    * @see http://msdn.microsoft.com/en-us/library/dd317756(VS.85).aspx
    *
    * Table taken from common/codepage.cpp
    *
    * @param integer codepage Codepage
    *
    * @access public
    * @return string iconv-compatible charset name
    */
    public static function GetCodepageCharset($codepage) {
        $codepages = array(
            20106 => "DIN_66003",
            20108 => "NS_4551-1",
            20107 => "SEN_850200_B",
            950 => "big5",
            50221 => "csISO2022JP",
            51932 => "euc-jp",
            51936 => "euc-cn",
            51949 => "euc-kr",
            949 => "euc-kr",
            936 => "gb18030",
            52936 => "csgb2312",
            852 => "ibm852",
            866 => "ibm866",
            50220 => "iso-2022-jp",
            50222 => "iso-2022-jp",
            50225 => "iso-2022-kr",
            1252 => "windows-1252",
            28591 => "iso-8859-1",
            28592 => "iso-8859-2",
            28593 => "iso-8859-3",
            28594 => "iso-8859-4",
            28595 => "iso-8859-5",
            28596 => "iso-8859-6",
            28597 => "iso-8859-7",
            28598 => "iso-8859-8",
            28599 => "iso-8859-9",
            28603 => "iso-8859-13",
            28605 => "iso-8859-15",
            20866 => "koi8-r",
            21866 => "koi8-u",
            932 => "shift-jis",
            1200 => "unicode",
            1201 => "unicodebig",
            65000 => "utf-7",
            65001 => "utf-8",
            1250 => "windows-1250",
            1251 => "windows-1251",
            1253 => "windows-1253",
            1254 => "windows-1254",
            1255 => "windows-1255",
            1256 => "windows-1256",
            1257 => "windows-1257",
            1258 => "windows-1258",
            874 => "windows-874",
            20127 => "us-ascii"
        );

        if(isset($codepages[$codepage])) {
            return $codepages[$codepage];
        } else {
            // Defaulting to iso-8859-15 since it is more likely for someone to make a mistake in the codepage
            // when using west-european charsets then when using other charsets since utf-8 is binary compatible
            // with the bottom 7 bits of west-european
            return "iso-8859-15";
        }
    }

    /**
     * Converts a string encoded with codepage into an UTF-8 string
     *
     * @param int $codepage
     * @param string $string
     *
     * @access public
     * @return string
     */
    public static function ConvertCodepageStringToUtf8($codepage, $string) {
        if (function_exists("iconv")) {
            $charset = self::GetCodepageCharset($codepage);

            return iconv($charset, "utf-8", $string);
        }
        return $string;
    }
}



// TODO Win1252/UTF8 functions are deprecated and will be removed sometime
//if the ICS backend is loaded in CombinedBackend and Zarafa > 7
//STORE_SUPPORTS_UNICODE is true and the convertion will not be done
//for other backends.
function utf8_to_windows1252($string, $option = "", $force_convert = false) {
    //if the store supports unicode return the string without converting it
    if (!$force_convert && defined('STORE_SUPPORTS_UNICODE') && STORE_SUPPORTS_UNICODE == true) return $string;

    if (function_exists("iconv")){
        return @iconv("UTF-8", "Windows-1252" . $option, $string);
    }else{
        return utf8_decode($string); // no euro support here
    }
}

function windows1252_to_utf8($string, $option = "", $force_convert = false) {
    //if the store supports unicode return the string without converting it
    if (!$force_convert && defined('STORE_SUPPORTS_UNICODE') && STORE_SUPPORTS_UNICODE == true) return $string;

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