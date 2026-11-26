<?php

/*
 * This file is part of the PHP IMAP2 package.
 *
 * (c) Francesco Bianco <bianco@PHPFUI.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPFUI\Imap2;

class Functions
	{
	public static function expectedNumberOfMessages(string $sequence) : int
		{
		if (\strpos($sequence, ',') > 0)
			{
			return \count(\explode(',', $sequence));
			}
		elseif (\strpos($sequence, ':') > 0)
			{
			$range = \explode(':', $sequence);

			return (int)$range[1] - (int)$range[0];
			}

		return 1;
		}

	public static function getAddressObjectList(array $addressList, ?string $defaultHost = 'UNKNOWN') : array
		{
		$addressObjectList = [];

		foreach ($addressList as $toAddress)
			{
			$email = \explode('@', $toAddress->getEmail());

			$addressObject = (object)[
				'mailbox' => $email[0],
				'host' => $email[1] ?? $defaultHost,
			];

			$personal = $toAddress->getName();

			if ($personal)
				{
				$addressObject->personal = $personal;
				}

			$addressObjectList[] = $addressObject;
			}

		return $addressObjectList;
		}

	public static function getHostFromMailbox(string|array $mailbox) : string
		{
		$mailboxParts = \is_array($mailbox) ? $mailbox : self::parseMailboxString($mailbox);

		return $mailboxParts['host'] ?? '';
		}

	public static function getListAttributesValue(array $attributes) : int
		{
		$attributesValue = 0;

		foreach ($attributes as $attribute)
			{
			switch ($attribute) {
				case '\\NoInferiors':
					$attributesValue |= LATT_NOINFERIORS;

					break;

				case '\\NoSelect':
					$attributesValue |= LATT_NOSELECT;

					break;

				case '\\Marked':
					$attributesValue |= LATT_MARKED;

					break;

				case '\\UnMarked':
					$attributesValue |= LATT_UNMARKED;

					break;

				case '\\Referral':
					$attributesValue |= LATT_REFERRAL;

					break;

				case '\\HasChildren':
					$attributesValue |= LATT_HASCHILDREN;

					break;

				case '\\HasNoChildren':
					$attributesValue |= LATT_HASNOCHILDREN;

					break;
				}
			}

		return $attributesValue;
		}

	/**
	 * Splits a header into plain text and RFC 2047 encoded words, like ext-imap's imap_mime_header_decode()
	 *
	 * Each part is an object with charset ("default" for plain text) and text. Encoded words are decoded from
	 * Q or base64 but keep their charset, and whitespace between two encoded words is dropped.
	 *
	 * @return array<\stdClass>|false
	 */
	public static function mimeHeaderDecode(string $string) : array | false
		{
		$parts = [];
		$offset = 0;
		$end = \strlen($string);
		$addPart = static function(string $charset, string $text) use (&$parts) : void
			{
			$parts[] = (object)['charset' => $charset, 'text' => $text];
			};

		while ($offset < $end)
			{
			$start = \strpos($string, '=?', $offset);

			if (false === $start)
				{
				break;
				}

			if ($start !== $offset)
				{
				$addPart('default', \substr($string, $offset, $start - $offset));
				}

			$encodingMark = \strpos($string, '?', $start + 2);
			$close = false === $encodingMark ? false : \strpos($string, '?=', $encodingMark + 3);

			if (false === $close)
				{
				// no complete encoded word: the rest is plain text
				$offset = $start;

				break;
				}

			$charset = \substr($string, $start + 2, $encodingMark - $start - 2);
			$encoding = $string[$encodingMark + 1] ?? '';
			$text = \substr($string, $encodingMark + 3, $close - $encodingMark - 3);

			if ('q' === $encoding || 'Q' === $encoding)
				{
				$decoded = \quoted_printable_decode(\str_replace('_', ' ', $text));
				}
			elseif ('b' === $encoding || 'B' === $encoding)
				{
				$decoded = \base64_decode(\preg_replace('/\s+/', '', $text), true);
				}
			else
				{
				// an unknown encoding is kept as it is
				$decoded = $text;
				}

			if (false === $decoded)
				{
				return false;
				}

			$addPart($charset, $decoded);
			$offset = $close + 2;

			// whitespace between two encoded words is not part of the text
			$skip = \strspn($string, " \t\r\n", $offset);

			if ('=?' === \substr($string, $offset + $skip, 2))
				{
				$offset += $skip;
				}
			}

		if ($offset < $end)
			{
			$addPart('default', \substr($string, $offset));
			}

		return $parts;
		}

	/**
	 * imap_utf7_encode() as ext-imap does it: runs of bytes outside printable ASCII are base64 encoded as they are,
	 * with "," for "/" and no padding, between "&" and "-", and "&" becomes "&-". The bytes are not converted to
	 * UTF-16 first, so this is not the modified UTF-7 of RFC 3501 for non-ASCII text; use imap_utf8_to_mutf7() for that.
	 */
	public static function utf7Encode(string $string) : string
		{
		return (string)\preg_replace_callback('/&|[^\x20-\x7E]+/', static function(array $match) : string
			{
			if ('&' === $match[0])
				{
				return '&-';
				}

			return '&' . \strtr(\rtrim(\base64_encode($match[0]), '='), '/', ',') . '-';
			}, $string);
		}

	/**
	 * imap_utf7_decode() as ext-imap does it, the reverse of utf7Encode(); false when the input is not valid
	 */
	public static function utf7Decode(string $string) : string | false
		{
		if (\preg_match('/[^\x20-\x7E]/', $string))
			{
			return false;
			}

		$result = '';
		$offset = 0;
		$length = \strlen($string);

		while ($offset < $length)
			{
			$amp = \strpos($string, '&', $offset);

			if (false === $amp)
				{
				$result .= \substr($string, $offset);

				break;
				}

			$result .= \substr($string, $offset, $amp - $offset);
			$dash = \strpos($string, '-', $amp + 1);

			if (false === $dash)
				{
				return false;
				}

			$encoded = \substr($string, $amp + 1, $dash - $amp - 1);

			if ('' === $encoded)
				{
				$result .= '&';
				}
			else
				{
				$decoded = \preg_match('/^[A-Za-z0-9+,]+$/', $encoded) ? \base64_decode(\strtr($encoded, ',', '/'), true) : false;

				if (false === $decoded || '' === $decoded)
					{
					return false;
					}

				$result .= $decoded;
				}

			$offset = $dash + 1;
			}

		return $result;
		}

	/**
	 * Get name from full mailbox string.
	 */
	public static function getMailboxName(string $mailbox) : string
		{
		$mailboxParts = \explode('}', $mailbox, 2);

		return empty($mailboxParts[1]) ? 'INBOX' : $mailboxParts[1];
		}

	public static function getSslModeFromMailbox(array|string $mailbox) : string | bool
		{
		$mailboxParts = \is_array($mailbox) ? $mailbox : self::parseMailboxString($mailbox);

		if (\in_array('ssl', $mailboxParts['path']))
			{
			return 'ssl';
			}

		return false;
		}

	public static function keyBy(string $name, array $list) : array
		{
		$keyBy = [];

		foreach ($list as $item)
			{
			if (! isset($item->{$name}))
				{
				\trigger_error('keyBy: key "' . $name . '" not found!', E_USER_WARNING);

				continue;
				}

			if (isset($keyBy[$item->{$name}]))
				{
				\trigger_error('keyBy: duplicate key "' . $name . '" = "' . $item->{$name} . '"', E_USER_WARNING);

				continue;
				}
			$keyBy[$item->{$name}] = $item;
			}

		return $keyBy;
		}

	public static function parseMailboxString(string $mailbox) : array
		{
		$mailboxParts = \explode('}', $mailbox);
		$mailboxParts[0] = \substr($mailboxParts[0], 1);

		$values = \parse_url($mailboxParts[0]);

		$values['mailbox'] = $mailboxParts[1] ?? '';
		$values['path'] = \explode('/', $values['path']);

		return $values;
		}

	public static function sanitizeAddress(string $address, string $defaultHost = 'UNKNOWN') : string
		{
		$addressList = \imap_rfc822_parse_adrlist($address, $defaultHost);

		$sanitizedAddress = [];

		foreach ($addressList as $addressEntry)
			{
			$sanitizedAddress[] = \imap_rfc822_write_address($addressEntry->mailbox, $addressEntry->host, $addressEntry->personal);
			}

		return \implode(', ', $sanitizedAddress);
		}

	/**
	 * Characters that make c-client quote a display name (rspecials) or a mailbox (wspecials)
	 */
	private const PERSONAL_SPECIALS = '()<>@,;:\\".[]';

	private const MAILBOX_SPECIALS = ' ()<>@,;:\\"[]';

	/**
	 * Returns the first entry of an envelope address list, or null when the list is empty or not a list
	 *
	 * @return ?array<array<mixed>>
	 */
	public static function firstAddress(mixed $addressList) : ?array
		{
		if (! \is_array($addressList) || empty($addressList) || ! \is_array(\reset($addressList)))
			{
			return null;
			}

		return [\reset($addressList)];
		}

	/**
	 * Formats one address the way ext-imap's imap_rfc822_write_address() does
	 */
	public static function writeAddress(?string $mailbox, ?string $host, ?string $personal) : string
		{
		$address = self::quoteSpecials($mailbox ?? '', self::MAILBOX_SPECIALS) . '@' . ('' === ($host ?? '') ? '""' : $host);

		if (null === $personal || '' === $personal)
			{
			return $address;
			}

		return self::quoteSpecials($personal, self::PERSONAL_SPECIALS) . ' <' . $address . '>';
		}

	/**
	 * Formats an envelope address list (entries of [personal, route, mailbox, host]) like ext-imap
	 *
	 * A group start (mailbox set, host NIL) becomes "name: " and a group end (mailbox and host NIL) closes it with ";"
	 *
	 * @param ?array<mixed> $addressList
	 */
	public static function writeAddressFromEnvelope(?array $addressList) : string
		{
		if (empty($addressList))
			{
			return '';
			}

		$result = '';
		$separator = '';

		foreach ($addressList as $addressEntry)
			{
			if (! \is_array($addressEntry))
				{
				continue;
				}

			$personal = self::envelopeString($addressEntry[0] ?? null);
			$mailbox = self::envelopeString($addressEntry[2] ?? null);
			$host = self::envelopeString($addressEntry[3] ?? null);

			if (null === $host)
				{
				if (null === $mailbox)
					{
					// end of group
					$result .= ';';
					$separator = ', ';
					}
				else
					{
					// start of group
					$result .= $separator . $mailbox . ': ';
					$separator = '';
					}

				continue;
				}

			$result .= $separator . self::writeAddress($mailbox, $host, $personal);
			$separator = ', ';
			}

		return $result;
		}

	private static function envelopeString(mixed $value) : ?string
		{
		return \is_string($value) ? $value : null;
		}

	private static function quoteSpecials(string $value, string $specials) : string
		{
		if ('' !== $value && false === \strpbrk($value, $specials))
			{
			return $value;
			}

		return '"' . \str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
		}
	}
