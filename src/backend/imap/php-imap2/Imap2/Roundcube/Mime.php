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
 * |   MIME message parsing utilities                                      |
 * +-----------------------------------------------------------------------+
 * | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 * | Author: Aleksander Machniak <alec@alec.pl>                            |
 * +-----------------------------------------------------------------------+
 */

namespace PHPFUI\Imap2\Roundcube;

/**
 * Class for parsing MIME messages
 *
 * @package    Framework
 * @subpackage Storage
 */
class Mime
{
	private static string $default_charset;

	/**
	 * Object constructor.
	 */
	public function __construct($default_charset = null)
	{
		self::$default_charset = $default_charset;
	}

	/**
	 * Decode a mime part
	 *
	 * @param string $input    Input string
	 * @param string $encoding Part encoding
	 *
	 * @return string Decoded string
	 */
	public static function decode(string $input, string $encoding = '7bit') : string
	{
		switch (\strtolower($encoding)) {
			case 'quoted-printable':
				return \quoted_printable_decode($input);

			case 'base64':
				return \base64_decode($input);

			case 'x-uuencode':
			case 'x-uue':
			case 'uue':
			case 'uuencode':
				return \convert_uudecode($input);

			case '7bit':
			default:
				return $input;
		}
	}

	/**
	 * Decode a message header value
	 *
	 * @param string  $input    Header value
	 * @param string  $fallback Fallback charset if none specified
	 *
	 * @return string Decoded string
	 */
	public static function decode_header($input, $fallback = null)
	{
		$str = self::decode_mime_string((string)$input, $fallback);

		return $str;
	}

	/**
	 * Decode a mime-encoded string to internal charset
	 *
	 * @param string $input    Header value
	 * @param string $fallback Fallback charset if none specified
	 *
	 * @return string Decoded string
	 */
	public static function decode_mime_string($input, $fallback = null)
	{
		$default_charset = $fallback ?: self::get_charset();

		// rfc: all line breaks or other characters not found
		// in the Base64 Alphabet must be ignored by decoding software
		// delete all blanks between MIME-lines, differently we can
		// receive unnecessary blanks and broken utf-8 symbols
		$input = \preg_replace("/\?=\s+=\?/", '?==?', $input);

		// encoded-word regexp
		$re = '/=\?([^?]+)\?([BbQq])\?([^\n]*?)\?=/';

		// Find all RFC2047's encoded words
		if (\preg_match_all($re, $input, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
			// Initialize variables
			$tmp = [];
			$out = '';
			$start = 0;

			foreach ($matches as $idx => $m) {
				$pos = $m[0][1];
				$charset = $m[1][0];
				$encoding = $m[2][0];
				$text = $m[3][0];
				$length = \strlen($m[0][0]);

				// Append everything that is before the text to be decoded
				if ($start != $pos) {
					$substr = \substr($input, $start, $pos - $start);
					$out .= Charset::convert($substr, $default_charset);
					$start = $pos;
				}
				$start += $length;

				// Per RFC2047, each string part "MUST represent an integral number
				// of characters . A multi-octet character may not be split across
				// adjacent encoded-words." However, some mailers break this, so we
				// try to handle characters spanned across parts anyway by iterating
				// through and aggregating sequential encoded parts with the same
				// character set and encoding, then perform the decoding on the
				// aggregation as a whole.

				$tmp[] = $text;

				if (isset($matches[$idx + 1]) && ($next_match = $matches[$idx + 1])) {
					if ($next_match[0][1] == $start
						&& $next_match[1][0] == $charset
						&& $next_match[2][0] == $encoding
					) {
						continue;
					}
				}

				$count = \count($tmp);
				$text = '';

				// Decode and join encoded-word's chunks
				if ('B' == $encoding || 'b' == $encoding) {
					$rest = '';

					// base64 must be decoded a segment at a time.
					// However, there are broken implementations that continue
					// in the following word, we'll handle that (#6048)
					for ($i = 0; $i < $count; $i++) {
						$chunk = $rest . $tmp[$i];
						$length = \strlen($chunk);

						if ($length % 4) {
							$length = (int)(\floor($length / 4) * 4);
							$rest = \substr($chunk, $length);
							$chunk = \substr($chunk, 0, $length);
						}

						$text .= \base64_decode($chunk);
					}
				}
				else { //if ($encoding == 'Q' || $encoding == 'q') {
					// quoted printable can be combined and processed at once
					for ($i = 0; $i < $count; $i++)
						$text .= $tmp[$i];

					$text = \str_replace('_', ' ', $text);
					$text = \quoted_printable_decode($text);
				}

				$out .= Charset::convert($text, $charset);
				$tmp = [];
			}

			// add the last part of the input string
			if ($start != \strlen($input)) {
				$out .= Charset::convert(\substr($input, $start), $default_charset);
			}

			// return the results
			return $out;
		}

		// no encoding information, use fallback
		return Charset::convert($input, $default_charset);
	}

	/**
	 * Explodes header (e.g. address-list) string into array of strings
	 * using specified separator characters with proper handling
	 * of quoted-strings and comments (RFC2822)
	 *
	 * @param string $separator       String containing separator characters
	 * @param string $str             Header string
	 * @param bool   $remove_comments Enable to remove comments
	 *
	 * @return array Header items
	 */
	public static function explode_header_string($separator, $str, $remove_comments = false)
	{
		$length = \strlen($str);
		$result = [];
		$quoted = false;
		$comment = 0;
		$out = '';

		for ($i = 0; $i < $length; $i++) {
			// we're inside a quoted string
			if ($quoted) {
				if ('"' == $str[$i]) {
					$quoted = false;
				}
				elseif ('\\' == $str[$i]) {
					if ($comment <= 0) {
						$out .= '\\';
					}
					$i++;
				}
			}
			// we are inside a comment string
			elseif ($comment > 0) {
				if (')' == $str[$i]) {
					$comment--;
				}
				elseif ('(' == $str[$i]) {
					$comment++;
				}
				elseif ('\\' == $str[$i]) {
					$i++;
				}

				continue;
			}
			// separator, add to result array
			elseif (false !== \strpos($separator, $str[$i])) {
				if ($out) {
					$result[] = $out;
				}
				$out = '';

				continue;
			}
			// start of quoted string
			elseif ('"' == $str[$i]) {
				$quoted = true;
			}
			// start of comment
			elseif ($remove_comments && '(' == $str[$i]) {
				$comment++;
			}

			if ($comment <= 0) {
				$out .= $str[$i];
			}
		}

		if ($out && $comment <= 0) {
			$result[] = $out;
		}

		return $result;
	}

	/**
	 * Try to fix invalid email addresses
	 */
	public static function fix_email($email)
	{
		$parts = Utils::explode_quoted_string('@', $email);

		foreach ($parts as $idx => $part) {
			// remove redundant quoting (#1490040)
			if ('"' == $part[0] && \preg_match('/^"([a-zA-Z0-9._+=-]+)"$/', $part, $m)) {
				$parts[$idx] = $m[1];
			}
		}

		return \implode('@', $parts);
	}

	/**
	 * Returns message/object character set name
	 *
	 * @return string Character set name
	 */
	public static function get_charset() : string
	{
		if (self::$default_charset) {
			return self::$default_charset;
		}

		return IMAP2_CHARSET;
	}
}
