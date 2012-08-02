<?php
/***********************************************
* File      :   zlog.php
* Project   :   Z-Push
* Descr     :   Debug and logging
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

class ZLog {
    static private $devid = '';
    static private $user = '';
    static private $authUser = false;
    static private $pidstr;
    static private $wbxmlDebug = '';
    static private $lastLogs = array();

    /**
     * Initializes the logging
     *
     * @access public
     * @return boolean
     */
    static public function Initialize() {
        global $specialLogUsers;

        // define some constants for the logging
        if (!defined('LOGUSERLEVEL'))
            define('LOGUSERLEVEL', LOGLEVEL_OFF);

        if (!defined('LOGLEVEL'))
            define('LOGLEVEL', LOGLEVEL_OFF);

        list($user,) = Utils::SplitDomainUser(Request::GetGETUser());
        if (!defined('WBXML_DEBUG') && $user) {
            // define the WBXML_DEBUG mode on user basis depending on the configurations
            if (LOGLEVEL >= LOGLEVEL_WBXML || (LOGUSERLEVEL >= LOGLEVEL_WBXML && in_array($user, $specialLogUsers)))
                define('WBXML_DEBUG', true);
            else
                define('WBXML_DEBUG', false);
        }

        if ($user)
            self::$user = '['. $user .'] ';
        else
            self::$user = '';

        // log the device id if the global loglevel is set to log devid or the user is in  and has the right log level
        if (Request::GetDeviceID() != "" && (LOGLEVEL >= LOGLEVEL_DEVICEID || (LOGUSERLEVEL >= LOGLEVEL_DEVICEID && in_array($user, $specialLogUsers))))
            self::$devid = '['. Request::GetDeviceID() .'] ';
        else
            self::$devid = '';

        return true;
    }

    /**
     * Writes a log line
     *
     * @param int       $loglevel           one of the defined LOGLEVELS
     * @param string    $message
     *
     * @access public
     * @return
     */
    static public function Write($loglevel, $message) {
        self::$lastLogs[$loglevel] = $message;
        $data = self::buildLogString($loglevel) . $message . "\n";

        if ($loglevel <= LOGLEVEL) {
            @file_put_contents(LOGFILE, $data, FILE_APPEND);
        }

        if ($loglevel <= LOGUSERLEVEL && self::logToUserFile()) {
            // padd level for better reading
            $data = str_replace(self::getLogLevelString($loglevel), self::getLogLevelString($loglevel,true), $data);
            // only use plain old a-z characters for the generic log file
            @file_put_contents(LOGFILEDIR . self::logToUserFile() . ".log", $data, FILE_APPEND);
        }

        if (($loglevel & LOGLEVEL_FATAL) || ($loglevel & LOGLEVEL_ERROR)) {
            @file_put_contents(LOGERRORFILE, $data, FILE_APPEND);
        }

        if ($loglevel & LOGLEVEL_WBXMLSTACK) {
            self::$wbxmlDebug .= $message. "\n";
        }
    }

    /**
     * Returns logged information about the WBXML stack
     *
     * @access public
     * @return string
     */
    static public function GetWBXMLDebugInfo() {
        return trim(self::$wbxmlDebug);
    }

    /**
     * Returns the last message logged for a log level
     *
     * @param int       $loglevel           one of the defined LOGLEVELS
     *
     * @access public
     * @return string/false     returns false if there was no message logged in that level
     */
    static public function GetLastMessage($loglevel) {
        return (isset(self::$lastLogs[$loglevel]))?self::$lastLogs[$loglevel]:false;
    }

    /**----------------------------------------------------------------------------------------------------------
     * private log stuff
     */

    /**
     * Returns the filename logs for a WBXML debug log user should be saved to
     *
     * @access private
     * @return string
     */
    static private function logToUserFile() {
        global $specialLogUsers;

        if (self::$authUser === false) {
            if (RequestProcessor::isUserAuthenticated()) {
                $authuser = Request::GetAuthUser();
                if ($authuser && in_array($authuser, $specialLogUsers))
                    self::$authUser = preg_replace('/[^a-z0-9]/', '_', strtolower($authuser));
            }
        }
        return self::$authUser;
    }

    /**
     * Returns the string to be logged
     *
     * @access private
     * @return string
     */
    static private function buildLogString($loglevel) {
        if (!isset(self::$pidstr))
            self::$pidstr = '[' . str_pad(@getmypid(),5," ",STR_PAD_LEFT) . '] ';

        if (!isset(self::$user))
            self::$user = '';

        if (!isset(self::$devid))
            self::$devid = '';

        return Utils::GetFormattedTime() ." ". self::$pidstr . self::getLogLevelString($loglevel, (LOGLEVEL > LOGLEVEL_INFO)) ." ". self::$user . self::$devid;
    }

    /**
     * Returns the string representation of the LOGLEVEL.
     * String can be padded
     *
     * @param int       $loglevel           one of the LOGLEVELs
     * @param boolean   $pad
     *
     * @access private
     * @return string
     */
    static private function getLogLevelString($loglevel, $pad = false) {
        if ($pad) $s = " ";
        else      $s = "";
        switch($loglevel) {
            case LOGLEVEL_OFF:   return ""; break;
            case LOGLEVEL_FATAL: return "[FATAL]"; break;
            case LOGLEVEL_ERROR: return "[ERROR]"; break;
            case LOGLEVEL_WARN:  return "[".$s."WARN]"; break;
            case LOGLEVEL_INFO:  return "[".$s."INFO]"; break;
            case LOGLEVEL_DEBUG: return "[DEBUG]"; break;
            case LOGLEVEL_WBXML: return "[WBXML]"; break;
            case LOGLEVEL_DEVICEID: return "[DEVICEID]"; break;
            case LOGLEVEL_WBXMLSTACK: return "[WBXMLSTACK]"; break;
        }
    }
}

/**----------------------------------------------------------------------------------------------------------
 * Legacy debug stuff
 */

// deprecated
// backwards compatible
function debugLog($message) {
    ZLog::Write(LOGLEVEL_DEBUG, $message);
}

// TODO review error handler
function zarafa_error_handler($errno, $errstr, $errfile, $errline, $errcontext) {
    $bt = debug_backtrace();
    switch ($errno) {
        case 8192:      // E_DEPRECATED since PHP 5.3.0
            // do not handle this message
            break;

        case E_NOTICE:
        case E_WARNING:
            // TODO check if there is a better way to avoid these messages
            if (stripos($errfile,'interprocessdata') !== false && stripos($errstr,'shm_get_var()') !== false)
                break;
            ZLog::Write(LOGLEVEL_WARN, "$errfile:$errline $errstr ($errno)");
            break;

        default:
            ZLog::Write(LOGLEVEL_ERROR, "trace error: $errfile:$errline $errstr ($errno) - backtrace: ". (count($bt)-1) . " steps");
            for($i = 1, $bt_length = count($bt); $i < $bt_length; $i++) {
                $file = $line = "unknown";
                if (isset($bt[$i]['file'])) $file = $bt[$i]['file'];
                if (isset($bt[$i]['line'])) $line = $bt[$i]['line'];
                ZLog::Write(LOGLEVEL_ERROR, "trace: $i:". $file . ":" . $line. " - " . ((isset($bt[$i]['class']))? $bt[$i]['class'] . $bt[$i]['type']:""). $bt[$i]['function']. "()");
            }
            //throw new Exception("An error occured.");
            break;
    }
}

error_reporting(E_ALL);
set_error_handler("zarafa_error_handler");

?>