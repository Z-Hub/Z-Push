<?php
/***********************************************
* File      :   zlog.php
* Project   :   Z-Push
* Descr     :   Debug and logging
*
* Created   :   01.10.2007
*
* Copyright 2007 - 2013 Zarafa Deutschland GmbH
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
    static private $userLog = false;
    static private $unAuthCache = array();
    static private $syslogEnabled = false;

    /**
     * Initializes the logging
     *
     * @access public
     * @return boolean
     */
    static public function Initialize() {
        global $specialLogUsers;

        if (defined('LOG_SYSLOG_ENABLED') && LOG_SYSLOG_ENABLED) {
            self::$syslogEnabled = true;
            ZSyslog::Initialize();
        }

        // define some constants for the logging
        if (!defined('LOGUSERLEVEL'))
            define('LOGUSERLEVEL', LOGLEVEL_OFF);

        if (!defined('LOGLEVEL'))
            define('LOGLEVEL', LOGLEVEL_OFF);

        list($user,) = Utils::SplitDomainUser(strtolower(Request::GetGETUser()));
        self::$userLog = in_array($user, $specialLogUsers);
        if (!defined('WBXML_DEBUG') && $user) {
            // define the WBXML_DEBUG mode on user basis depending on the configurations
            if (LOGLEVEL >= LOGLEVEL_WBXML || (LOGUSERLEVEL >= LOGLEVEL_WBXML && self::$userLog))
                define('WBXML_DEBUG', true);
            else
                define('WBXML_DEBUG', false);
        }

        if ($user)
            self::$user = '['. $user .'] ';
        else
            self::$user = '';

        // log the device id if the global loglevel is set to log devid or the user is in  and has the right log level
        if (Request::GetDeviceID() != "" && (LOGLEVEL >= LOGLEVEL_DEVICEID || (LOGUSERLEVEL >= LOGLEVEL_DEVICEID && self::$userLog)))
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
     * @param boolean   $truncate           indicate if the message should be truncated, default true
     *
     * @access public
     * @return
     */
    static public function Write($loglevel, $message, $truncate = true) {
        // truncate messages longer than 10 KB
        $messagesize = strlen($message);
        if ($truncate && $messagesize > 10240)
            $message = substr($message, 0, 10240) . sprintf(" <log message with %d bytes truncated>", $messagesize);

        self::$lastLogs[$loglevel] = $message;
        $data = self::buildLogString($loglevel) . $message . "\n";

        if ($loglevel <= LOGLEVEL) {
            self::writeToLog($loglevel, $data, LOGFILE);
        }

        // should we write this into the user log?
        if ($loglevel <= LOGUSERLEVEL && self::$userLog) {
            // padd level for better reading
            $data = str_replace(self::getLogLevelString($loglevel), self::getLogLevelString($loglevel,true), $data);

            // is the user authenticated?
            if (self::logToUserFile()) {
                // something was logged before the user was authenticated, write this to the log
                if (!empty(self::$unAuthCache)) {
                    self::writeToLog($loglevel, implode('', self::$unAuthCache), LOGFILEDIR . self::logToUserFile() . ".log");
                    self::$unAuthCache = array();
                }
                // only use plain old a-z characters for the generic log file
                self::writeToLog($loglevel, $data, LOGFILEDIR . self::logToUserFile() . ".log");
            }
            // the user is not authenticated yet, we save the log into memory for now
            else {
                self::$unAuthCache[] = $data;
            }
        }

        if (($loglevel & LOGLEVEL_FATAL) || ($loglevel & LOGLEVEL_ERROR)) {
            self::writeToLog($loglevel, $data, LOGERRORFILE);
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


    /**
     * Writes info at the end of the request but only if the LOGLEVEL is DEBUG or more verbose
     *
     * @access public
     * @return
     */
    static public function WriteEnd() {
        if (LOGLEVEL_DEBUG <= LOGLEVEL) {
            if (version_compare(phpversion(), '5.4.0') < 0) {
                $time_used = number_format(time() - $_SERVER["REQUEST_TIME"], 4);
            }
            else {
                $time_used = number_format(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"], 4);
            }

            ZLog::Write(LOGLEVEL_DEBUG, sprintf("Memory usage information: %s/%s - Execution time: %s - HTTP responde code: %s", memory_get_peak_usage(false), memory_get_peak_usage(true), $time_used, http_response_code()));
            ZLog::Write(LOGLEVEL_DEBUG, "-------- End");
        }
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

        if (self::$syslogEnabled)
            return self::$pidstr . self::getLogLevelString($loglevel, (LOGLEVEL > LOGLEVEL_INFO)) . " " . self::$user . self::$devid;
        else
            return Utils::GetFormattedTime() . " " . self::$pidstr . self::getLogLevelString($loglevel, (LOGLEVEL > LOGLEVEL_INFO)) . " " . self::$user . self::$devid;
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

    /**
     * Write the message to the log facility.
     *
     * @param int       $loglevel
     * @param string    $data
     * @param string    $logfile
     *
     * @access private
     * @return void
     */
    static private function writeToLog($loglevel, $data, $logfile = null) {
        if (self::$syslogEnabled) {
            if (ZSyslog::send($loglevel, $data) === false) {
                error_log("Unable to send to syslog");
                error_log($data);
            }
        }
        else {
            if (@file_put_contents($logfile, $data, FILE_APPEND) === false) {
                error_log(sprintf("Unable to write in %s", $logfile));
                error_log($data);
            }
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

// E_DEPRECATED only available since PHP 5.3.0
if (!defined('E_DEPRECATED')) define(E_DEPRECATED, 8192);

// TODO review error handler
function zarafa_error_handler($errno, $errstr, $errfile, $errline, $errcontext) {
    $bt = debug_backtrace();
    switch ($errno) {
        case E_DEPRECATED:
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


function zpush_fatal_handler() {
    $errfile = "unknown file";
    $errstr  = "shutdown";
    $errno   = E_CORE_ERROR;
    $errline = 0;

    $error = error_get_last();

    if( $error !== null) {
        $errno   = $error["type"];
        $errfile = $error["file"];
        $errline = $error["line"];
        $errstr  = $error["message"];

		// do NOT log PHP Notice, Warning or Deprecated as FATAL
        if ($errno & ~(E_NOTICE|E_WARNING|E_DEPRECATED)) {
            ZLog::Write(LOGLEVEL_FATAL, sprintf("Fatal error: %s:%d - %s (%s)", $errfile, $errline, $errstr, $errno));
        }
    }
}
register_shutdown_function("zpush_fatal_handler");
