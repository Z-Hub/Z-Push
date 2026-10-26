<?php

/**
 * +-----------------------------------------------------------------------+
 * | This file is part of the Roundcube Webmail client                     |
 * |                                                                       |
 * | Copyright (C) The Roundcube Dev Team                                  |
 * | Copyright (C) Kolab Systems AG                                        |
 * |                                                                       |
 * | Licensed under the GNU General Public License version 3 or            |
 * | any later version with exceptions for skins & plugins.                |
 * | See the README file for a full license statement.                     |
 * |                                                                       |
 * | PURPOSE:                                                              |
 * |   Utility class providing common functions                            |
 * +-----------------------------------------------------------------------+
 * | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 * | Author: Aleksander Machniak <alec@alec.pl>                            |
 * +-----------------------------------------------------------------------+
 */

namespace PHPFUI\Imap2\Roundcube;

use PHPFUI\Imap2\DateTime;

/**
 * Utility class providing common functions
 *
 * @package    Framework
 * @subpackage Utils
 */
class Utils
{
	/**
	 * Clean up date string for strtotime() input
	 *
	 * @param string $date Date string
	 *
	 * @return string Date string
	 */
	public static function clean_datestr(string $date) : string
	{
		$date = \trim($date);

		// check for MS Outlook vCard date format YYYYMMDD
		if (\preg_match('/^([12][90]\d\d)([01]\d)([0123]\d)$/', $date, $m)) {
			return \sprintf('%04d-%02d-%02d 00:00:00', (int)($m[1]), (int)($m[2]), (int)($m[3]));
		}

		// Clean malformed data
		$date = \preg_replace(
			[
				'/\(.*\)/',                                 // remove RFC comments
				'/GMT\s*([+-][0-9]+)/',                     // support non-standard "GMTXXXX" literal
				'/[^a-z0-9\x20\x09:\/\.+-]/i',              // remove any invalid characters
				'/\s*(Mon|Tue|Wed|Thu|Fri|Sat|Sun)\s*/i',   // remove weekday names
			],
			[
				'',
				'\\1',
				'',
				'',
			],
			$date
		);

		$date = \trim($date);

		// try to fix dd/mm vs. mm/dd discrepancy, we can't do more here
		if (\preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})(\s.*)?$/', $date, $m)) {
			$mdy = $m[2] > 12 && $m[1] <= 12;
			$day = $mdy ? $m[2] : $m[1];
			$month = $mdy ? $m[1] : $m[2];
			$date = \sprintf('%04d-%02d-%02d%s', $m[3], $month, $day, $m[4] ?? ' 00:00:00');
		}
		// I've found that YYYY.MM.DD is recognized wrong, so here's a fix
		elseif (\preg_match('/^(\d{4})\.(\d{1,2})\.(\d{1,2})(\s.*)?$/', $date, $m)) {
			$date = \sprintf('%04d-%02d-%02d%s', $m[1], $m[2], $m[3], $m[4] ?? ' 00:00:00');
		}

		return $date;
	}

	/**
	 * Explode quoted string
	 *
	 * @param string $delimiter Delimiter expression string for preg_match()
	 * @param string $string    Input string
	 *
	 * @return array String items
	 */
	public static function explode_quoted_string(string $delimiter, string $string) : array
	{
		$result = [];
		$strlen = \strlen($string);

		for ($q = $p = $i = 0; $i < $strlen; $i++) {
			if ('"' == $string[$i] && '\\' != $string[$i - 1]) {
				$q = $q ? false : true;
			}
			elseif (! $q && \preg_match("/{$delimiter}/", $string[$i])) {
				$result[] = \substr($string, $p, $i - $p);
				$p = $i + 1;
			}
		}

		$result[] = (string)\substr($string, $p);

		return $result;
	}

	/**
	 * Improved equivalent to strtotime()
	 *
	 * @param string       $date     Date string
	 * @param \DateTimeZone $timezone Timezone to use for DateTime object
	 *
	 * @return int Unix timestamp
	 */
	public static function strtotime(string $date, ?\DateTimeZone $timezone = null) : int
	{
		$date = self::clean_datestr($date);
		$tzname = $timezone ? ' ' . $timezone->getName() : '';

		// unix timestamp
		if (\is_numeric($date)) {
			return (int)$date;
		}

		// It can be very slow when provided string is not a date and very long
		if (\strlen($date) > 128) {
			$date = \substr($date, 0, 128);
		}

		// if date parsing fails, we have a date in non-rfc format.
		// remove token from the end and try again
		while (($ts = @\strtotime($date . $tzname)) === false || $ts < 0) {
			if (($pos = \strrpos($date, ' ')) === false) {
				break;
			}

			$date = \rtrim(\substr($date, 0, $pos));
		}

		return (int)$ts;
	}
}
