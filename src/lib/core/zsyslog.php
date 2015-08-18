<?php
// local, remote and papertrail compatible syslogclass
// https://gist.github.com/coderofsalvation/11325307


class ZSyslog {
    private static $hostname   = false;
    private static $port       = 514;
    private static $program    = "[z-push]";

    /**
     * Initializes the logging
     *
     * @access public
     * @return boolean
     */
    public static function Initialize() {
        if (defined('LOG_SYSLOG_HOST'))
            self::$hostname = LOG_SYSLOG_HOST;
        if (defined('LOG_SYSLOG_PORT'))
            self::$port = LOG_SYSLOG_PORT;
        if (defined('LOG_SYSLOG_PROGRAM'))
            self::$program = LOG_SYSLOG_PROGRAM;
    }

    /**
     * Send a message in the syslog facility.
     *
     * @params int      $zlog_level     Z-Push LogLevel
     * @params string   $message
     *
     * @access public
     * @return boolean
     */
    public static function send($zlog_level, $message) {
        $level = self::zlogLevel2SyslogLevel($zlog_level);
        if ($level === false) {
            return false;
        }

        if (self::$hostname === false) {
            return syslog($level, $message);
        }
        else {
            $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            $facility = 1; // user level
            $pri = ($facility*8) + $level; // multiplying the Facility number by 8 + adding the level
            foreach (explode("\n", $message) as $line) {
                if (strlen(trim($line)) > 0) {
                    $syslog_message = "<{$pri}>" . date('M d H:i:s ') . self::$program . ': ' . $line;
                    socket_sendto($sock, $syslog_message, strlen($syslog_message), 0, self::$hostname, self::$port);
                }
            }
            socket_close($sock);
        }

        return true;
    }

    /**
     * Converts the ZLog level to SYSLOG level.
     *
     * @params int      $loglevel     Z-Push LogLevel
     *
     * @access private
     * @return SYSLOG_LEVEL or false
     */
    private static function zlogLevel2SyslogLevel($loglevel) {
        switch($loglevel) {
            case LOGLEVEL_OFF:   return false; break;
            case LOGLEVEL_FATAL: return LOG_ALERT; break;
            case LOGLEVEL_ERROR: return LOG_ERR; break;
            case LOGLEVEL_WARN:  return LOG_WARNING; break;
            case LOGLEVEL_INFO:  return LOG_INFO; break;
            case LOGLEVEL_DEBUG: return LOG_DEBUG; break;
            case LOGLEVEL_WBXML: return LOG_DEBUG; break;
            case LOGLEVEL_DEVICEID: return LOG_DEBUG; break;
            case LOGLEVEL_WBXMLSTACK: return LOG_DEBUG; break;
        }
    }
}