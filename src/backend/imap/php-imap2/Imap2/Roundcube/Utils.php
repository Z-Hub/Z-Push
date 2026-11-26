<?php

/*
 +-----------------------------------------------------------------------+
 | This file is part of the Roundcube Webmail client                     |
 |                                                                       |
 | Copyright (C) The Roundcube Dev Team                                  |
 | Copyright (C) Kolab Systems AG                                        |
 |                                                                       |
 | Licensed under the GNU General Public License version 3 or            |
 | any later version with exceptions for skins & plugins.                |
 | See the README file for a full license statement.                     |
 |                                                                       |
 | PURPOSE:                                                              |
 |   Utility class providing common functions                            |
 +-----------------------------------------------------------------------+
 | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 | Author: Aleksander Machniak <alec@alec.pl>                            |
 +-----------------------------------------------------------------------+
*/

namespace PHPFUI\Imap2\Roundcube;

// Ported from Roundcube 1.7.5 (program/lib/Roundcube). Changes: namespace, class names,
// no Roundcube application object, and php-imap2's COMMAND_RAW_LASTLINE. See tools/port-roundcube.php.

class Utils
{

    /**
     * Explode quoted string
     *
     * @param string $delimiter Delimiter expression string for preg_match()
     * @param string $string    Input string
     *
     * @return array String items
     */
    public static function explode_quoted_string($delimiter, $string)
    {
        $res = [];
        $parts = preg_split('/("(?:[^"\\\]+|\\\.)*+(?:"|\\\?$))/s', $string, 0, \PREG_SPLIT_DELIM_CAPTURE);
        $isQuoted = false;
        $tmp = '';
        foreach ($parts as $part) {
            if ($isQuoted) {
                $tmp .= $part;
            } else {
                $isFirst = true;
                foreach (preg_split('/' . $delimiter . '/', $part) as $v) {
                    if ($isFirst) {
                        $tmp .= $v;
                        $isFirst = false;
                    } else {
                        $res[] = $tmp;
                        $tmp = $v;
                    }
                }
            }

            $isQuoted = !$isQuoted;
        }
        $res[] = $tmp;

        return $res;
    }

    /**
     * Improved equivalent to strtotime()
     *
     * @param string        $date     Date string
     * @param \DateTimeZone $timezone Timezone to use for DateTime object
     *
     * @return int Unix timestamp
     */
    public static function strtotime($date, $timezone = null)
    {
        $date = self::clean_datestr($date);

        // unix timestamp
        if ($date === '' || is_numeric($date)) {
            return (int) $date;
        }

        // It can be very slow when provided string is not a date and very long
        if (strlen($date) > 128) {
            $date = substr($date, 0, 128);
        }

        // Note we prefer UTC, if timezone is unknown, otherwise the result
        // would depend on PHP/system timezone.
        $tzname = $timezone ? ' ' . $timezone->getName() : ' UTC';

        // if date parsing fails, we have a date in non-rfc format.
        // remove token from the end and try again
        while (($ts = @strtotime($date . $tzname)) === false || $ts < 0) {
            if (($pos = strrpos($date, ' ')) === false) {
                break;
            }

            $date = rtrim(substr($date, 0, $pos));
        }

        return (int) $ts;
    }

    /**
     * Clean up date string for strtotime() input
     *
     * @param string $date Date string
     *
     * @return string Date string
     */
    public static function clean_datestr($date)
    {
        $date = trim((string) $date);

        // check for MS Outlook vCard date format YYYYMMDD
        if (preg_match('/^([12][90]\d\d)([01]\d)([0123]\d)$/', $date, $m)) {
            return sprintf('%04d-%02d-%02d 00:00:00', intval($m[1]), intval($m[2]), intval($m[3]));
        }

        // Clean malformed data
        $date = preg_replace(
            [
                '/\(.*\)/',                                 // remove RFC comments
                '/GMT\s*([+-][0-9]+)/',                     // support non-standard "GMTXXXX" literal
                '/[^a-z0-9\x20\x09:\/\.+-]/i',              // remove any invalid characters
                '/\s*(Mon|Tue|Wed|Thu|Fri|Sat|Sun)\s*/i',   // remove weekday names
            ],
            [
                '',
                '\1',
                '',
                '',
            ],
            $date
        );

        $date = trim($date);

        // try to fix dd/mm vs. mm/dd discrepancy, we can't do more here
        if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})(\s.*)?$/', $date, $m)) {
            $mdy = $m[2] > 12 && $m[1] <= 12;
            $day = $mdy ? $m[2] : $m[1];
            $month = $mdy ? $m[1] : $m[2];
            $date = sprintf('%04d-%02d-%02d%s', $m[3], $month, $day, $m[4] ?? ' 00:00:00');
        }
        // I've found that YYYY.MM.DD is recognized wrong, so here's a fix
        elseif (preg_match('/^(\d{4})\.(\d{1,2})\.(\d{1,2})(\s.*)?$/', $date, $m)) {
            $date = sprintf('%04d-%02d-%02d%s', $m[1], $m[2], $m[3], $m[4] ?? ' 00:00:00');
        }

        return $date;
    }

    /**
     * Clean the subject from reply and forward prefix
     *
     * @param string $subject Subject to clean
     * @param string $mode    Mode of cleaning : reply, forward or both
     *
     * @return string Cleaned subject
     */
    public static function remove_subject_prefix($subject, $mode = 'both')
    {
        // php-imap2: no Roundcube configuration, so every setting takes Roundcube's default
        $config = new class {
            public function get($name, $default = null)
            {
                return $default;
            }
        };
        $prefixes = [];

        // Clean subject prefix for reply, forward or both
        if ($mode == 'both') {
            $reply_prefixes = $config->get('subject_reply_prefixes', ['Re:']);
            $forward_prefixes = $config->get('subject_forward_prefixes', ['Fwd:', 'Fw:']);
            $prefixes = array_merge($reply_prefixes, $forward_prefixes);
        } elseif ($mode == 'reply') {
            $prefixes = $config->get('subject_reply_prefixes', ['Re:']);
            // replace (was: ...) (#1489375)
            $subject = preg_replace('/\s*\([wW]as:[^\)]+\)\s*$/', '', $subject);
        } elseif ($mode == 'forward') {
            $prefixes = $config->get('subject_forward_prefixes', ['Fwd:', 'Fw:']);
        }

        // replace Re:, Re[x]:, Re-x (#1490497)
        $pieces = array_map(static function ($prefix) {
            $prefix = strtolower(str_replace(':', '', $prefix));
            return "{$prefix}:|{$prefix}\\[\\d\\]:|{$prefix}-\\d:";
        }, $prefixes);
        $pattern = '/^(' . implode('|', $pieces) . ')\s*/i';
        do {
            $subject = preg_replace($pattern, '', $subject, -1, $count);
        } while ($count);

        return trim($subject);
    }

    /**
     * Generates the HAproxy style PROXY protocol header for injection
     * into the TCP stream, if configured.
     *
     * https://www.haproxy.org/download/1.6/doc/proxy-protocol.txt
     *
     * PROXY protocol headers must be sent before any other data is sent on the TCP socket.
     *
     * @param ?array $options Preferences array which may contain proxy_protocol (generally {driver}_conn_options)
     *
     * @return string Proxy protocol header data, if enabled, otherwise empty string
     */
    public static function proxy_protocol_header($options = null)
    {
        if (empty($options) || !array_key_exists('proxy_protocol', $options)) {
            return '';
        }

        if (is_array($options['proxy_protocol'])) {
            $version = $options['proxy_protocol']['version'];
            $options = $options['proxy_protocol'];
        } else {
            $version = (int) $options['proxy_protocol'];
            $options = [];
        }

        $remote_addr = array_key_exists('remote_addr', $options) ? $options['remote_addr'] : self::remote_addr();
        $remote_port = array_key_exists('remote_port', $options) ? $options['remote_port'] : ($_SERVER['REMOTE_PORT'] ?? null);
        $local_addr = array_key_exists('local_addr', $options) ? $options['local_addr'] : ($_SERVER['SERVER_ADDR'] ?? null);
        $local_port = array_key_exists('local_port', $options) ? $options['local_port'] : ($_SERVER['SERVER_PORT'] ?? null);
        $ip_version = !str_contains($remote_addr, ':') ? 4 : 6;

        // Text based PROXY protocol
        if ($version == 1) {
            // PROXY protocol does not support dual IPv6+IPv4 type addresses, e.g. ::127.0.0.1
            if ($ip_version === 6 && str_contains($remote_addr, '.')) {
                $remote_addr = inet_ntop(inet_pton($remote_addr));
            }
            if ($ip_version === 6 && str_contains($local_addr, '.')) {
                $local_addr = inet_ntop(inet_pton($local_addr));
            }

            return "PROXY TCP{$ip_version} {$remote_addr} {$local_addr} {$remote_port} {$local_port}\r\n";
        }

        // Binary PROXY protocol
        if ($version == 2) {
            $addr = inet_pton($remote_addr) . inet_pton($local_addr) . pack('n', $remote_port) . pack('n', $local_port);
            $head = implode('', [
                '0D0A0D0A000D0A515549540A',     // protocol header
                '21',                           // protocol version and command
                $ip_version === 6 ? '2' : '1',  // IP version type
                '1',                             // TCP
            ]);

            return pack('H*', $head) . pack('n', strlen($addr)) . $addr;
        }

        return '';
    }

    /**
     * Returns the client IP address (php-imap2: without Roundcube's proxy_whitelist)
     *
     * @return ?string
     */
    public static function remote_addr()
    {
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }
}
