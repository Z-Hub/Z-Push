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

	public static function writeAddressFromEnvelope(?array $addressList) : string
		{
		if (empty($addressList))
			{
			return '';
			}

		$sanitizedAddress = [];

		foreach ($addressList as $addressEntry)
			{
			$parsedAddressEntry = \imap_rfc822_write_address($addressEntry[2], $addressEntry[3], $addressEntry[0]);

			if ('@""' == \substr($parsedAddressEntry, -3))
				{
				$parsedAddressEntry = \substr($parsedAddressEntry, 0, \strlen($parsedAddressEntry) - 3) . ': ';
				}
			$sanitizedAddress[] = $parsedAddressEntry;
			}

		return \implode(', ', $sanitizedAddress);
		}
	}
