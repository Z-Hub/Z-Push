<?php
/*
 * Copyright 2015 Leon van Kammen / Coder of Salvation. All rights reserved.
 * Modifications 2015 - Francisco Miguel Biete
 *
 * Redistribution and use in source and binary forms, with or without modification, are
 * permitted provided that the following conditions are met:
 *
 *    1. Redistributions of source code must retain the above copyright notice, this list of
 *       conditions and the following disclaimer.
 *
 *    2. Redistributions in binary form must reproduce the above copyright notice, this list
 *       of conditions and the following disclaimer in the documentation and/or other materials
 *       provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY Leon van Kammen / Coder of Salvation AS IS'' AND ANY EXPRESS OR IMPLIED
 * WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND
 * FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL Leon van Kammen / Coder of Salvation OR
 * CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
 * ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING
 * NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF
 * ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * The views and conclusions contained in the software and documentation are those of the
 * authors and should not be interpreted as representing official policies, either expressed
 * or implied, of Leon van Kammen / Coder of Salvation
 */

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