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

class Mailbox
	{
	/**
	 * Append a string message to a specified mailbox.
	 */
	public static function append(\IMAP\Connection $imap, $folder, $message, $options = null, $internalDate = null) : bool
		{
		$folderParts = \explode('}', $folder);
		$client = $imap->getClient();

		$mailbox = empty($folderParts[1]) ? 'INBOX' : $folderParts[1];

		// $options holds the flags, like "\Seen \Draft". The client takes its own names for the flags it knows
		// ("SEEN" for "\Seen"), so those are converted, and anything else is passed on as it is.
		$flags = [];

		foreach (\preg_split('/\s+/', \trim((string)$options), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $flag)
			{
			$name = \array_search(\strtolower($flag), \array_map('strtolower', $client->flags), true);
			$flags[] = false === $name ? $flag : $name;
			}

		$success = $client->append($mailbox, $message, $flags, $internalDate ?: null);

		if (! $success)
			{
			\PHPFUI\Imap2\Errors::appendError($client->error ?: "Can't append to mailbox {$mailbox}");
			}

		return (bool)$success;
		}

	public static function check(\IMAP\Connection $imap) : \stdClass
		{
		$imap->selectMailbox();

		$client = $imap->getClient();
		$status = $client->status($imap->getMailboxName(), ['MESSAGES', 'RECENT']);

		return (object)[
			'Date' => \date('D, j M Y G:i:s') . ' +0000 (UTC)',
			'Driver' => 'imap',
			'Mailbox' => $imap->getMailbox(),
			'Nmsgs' => (int)($status['MESSAGES']),
			'Recent' => (int)($status['RECENT']),
		];
		}

	public static function createMailbox(\IMAP\Connection $imap, string $mailbox) : bool
		{
		$client = $imap->getClient();

		$mailbox = self::withoutServer($mailbox);

		$success = $client->createFolder($mailbox);

		if (! $success)
			{
			\PHPFUI\Imap2\Errors::appendError($client->getRawLastLine());
			}

		return $success;
		}

	public static function deleteMailbox(\IMAP\Connection $imap, string $mailbox)
		{
		$client = $imap->getClient();

		$mailbox = self::withoutServer($mailbox);

		$result = $client->execute('DELETE', [$client->escape($mailbox)], \PHPFUI\Imap2\Roundcube\ImapClient::COMMAND_RAW_LASTLINE);

		$success = \PHPFUI\Imap2\Roundcube\ImapClient::ERROR_OK == $result[0];

		if (! $success && $imap->getRegistryValue('mailbox', $mailbox, 'deleted'))
			{
			\PHPFUI\Imap2\Errors::appendError($result[1]);
			}
		elseif (! $success)
			{
			\PHPFUI\Imap2\Errors::appendError("Can't delete mailbox {$mailbox}: no such mailbox");
			}
		else
			{
			$imap->setRegistryValue('mailbox', $mailbox, 'deleted', true);
			}

		return $success;
		}

	public static function getMailboxes(\IMAP\Connection $imap, $reference, $pattern) : array | false
		{
		if (! $imap->isConnected())
			{
			return false;
			}

		$referenceParts = \explode('}', $reference);
		$client = $imap->getClient();
		$return = [];
		$delimiter = $client->getHierarchyDelimiter();
		$mailboxes = $client->listMailboxes($referenceParts[1], $pattern);

		foreach ($mailboxes as $mailbox)
			{
			$attributesValue = Functions::getListAttributesValue($client->data['LIST'][$mailbox]);

			if ('[Gmail]' == $mailbox && 'imap.gmail.com' == $imap->getHost())
				{
				$attributesValue = 34;
				}
			$return[] = (object)[
				'name' => $referenceParts[0] . '}' . $mailbox,
				'attributes' => $attributesValue,
				'delimiter' => $delimiter,
			];
			}

		return $return;
		}

	public static function list(\IMAP\Connection $imap, $reference, $pattern) : array
		{
		$referenceParts = \explode('}', $reference);
		$client = $imap->getClient();
		$return = [];
		$mailboxes = $client->listMailboxes($referenceParts[1], $pattern);

		foreach ($mailboxes as $mailbox)
			{
			if (\in_array('\\Noselect', $client->data['LIST'][$mailbox]))
				{
				continue;
				}
			$return[] = $referenceParts[0] . '}' . $mailbox;
			}

		return $return;
		}

	public static function listScan(\IMAP\Connection $imap, $reference, $pattern) : array
		{
		$referenceParts = \explode('}', $reference);
		$client = $imap->getClient();
		$return = [];
		$mailboxes = $client->listMailboxes($referenceParts[1], $pattern);

		foreach ($mailboxes as $mailbox)
			{
			if (\in_array('\\Noselect', $client->data['LIST'][$mailbox]))
				{
				continue;
				}
			$return[] = $referenceParts[0] . '}' . $mailbox;
			}

		return $return;
		}

	/**
	 * Subscribed mailboxes as objects, like imap_getsubscribed()
	 */
	public static function getSubscribed(\IMAP\Connection $imap, string $reference, string $pattern) : array | false
		{
		if (! $imap->isConnected())
			{
			return false;
			}

		$referenceParts = \explode('}', $reference, 2);
		$client = $imap->getClient();
		$delimiter = $client->getHierarchyDelimiter();
		$mailboxes = $client->listSubscribed($referenceParts[1] ?? '', $pattern);

		if (! \is_array($mailboxes) || ! $mailboxes)
			{
			return false;
			}

		$return = [];

		foreach ($mailboxes as $mailbox)
			{
			$return[] = (object)[
				'name' => $referenceParts[0] . '}' . $mailbox,
				'attributes' => Functions::getListAttributesValue($client->data['LIST'][$mailbox] ?? []),
				'delimiter' => $delimiter,
			];
			}

		return $return;
		}

	/**
	 * Subscribed mailbox names, like imap_lsub()
	 */
	public static function listSubscribed(\IMAP\Connection $imap, string $reference, string $pattern) : array | false
		{
		$mailboxes = self::getSubscribed($imap, $reference, $pattern);

		if (false === $mailboxes)
			{
			return false;
			}

		return \array_map(static fn(\stdClass $mailbox) : string => $mailbox->name, $mailboxes);
		}

	public static function mailboxMsgInfo(\IMAP\Connection $imap) : \stdClass
		{
		$client = $imap->getClient();

		$imap->selectMailbox();
		$mailboxName = $imap->getMailboxName();

		$status = $client->status($mailboxName, [
			'MESSAGES',
			'UNSEEN',
			'RECENT',
			'UIDNEXT',
			'UIDVALIDITY'
		]);

		$mailboxInfo = [
			'Unread' => (int)($status['UNSEEN']),
			'Deleted' => 0,
			'Nmsgs' => (int)($status['MESSAGES']),
			'Size' => 0,
			'Date' => \date('D, j M Y G:i:s') . ' +0000 (UTC)',
			'Driver' => 'imap',
			'Mailbox' => $imap->getMailbox(),
			'Recent' => (int)($status['RECENT'])
		];

		return (object)$mailboxInfo;
		}

	public static function numMsg(\IMAP\Connection $imap) : int
		{
		$imap->selectMailbox();
		$client = $imap->getClient();

		$status = $client->status($imap->getMailboxName(), ['MESSAGES']);

		return (int)($status['MESSAGES']);
		}

	public static function numRecent(\IMAP\Connection $imap) : int
		{
		$client = $imap->getClient();
		$imap->selectMailbox();

		return (int)$client->data['RECENT'];
		}

	public static function renameMailbox(\IMAP\Connection $imap, string $from, string $to) : bool
		{
		$client = $imap->getClient();

		$success = $client->renameFolder(self::withoutServer($from), self::withoutServer($to));

		if (! $success)
			{
			\PHPFUI\Imap2\Errors::appendError($client->error ?: "Can't rename mailbox {$from}");
			}

		return $success;
		}

	public static function status(\IMAP\Connection $imap, string $mailbox, int $flags) : \stdClass | false
		{
		$mailboxName = Functions::getMailboxName($mailbox);

		$client = $imap->getClient();

		$items = [];

		$statusKeys = [
			'MESSAGES' => 'messages',
			'UNSEEN' => 'unseen',
			'RECENT' => 'recent',
			'UIDNEXT' => 'uidnext',
			'UIDVALIDITY' => 'uidvalidity',
		];

		if ($flags & SA_MESSAGES || $flags & SA_ALL)
			{
			$items[] = 'MESSAGES';
			}

		if ($flags & SA_RECENT || $flags & SA_ALL)
			{
			$items[] = 'RECENT';
			}

		if ($flags & SA_UNSEEN || $flags & SA_ALL)
			{
			$items[] = 'UNSEEN';
			}

		if ($flags & SA_UIDNEXT || $flags & SA_ALL)
			{
			$items[] = 'UIDNEXT';
			}

		if ($flags & SA_UIDVALIDITY || $flags & SA_ALL)
			{
			$items[] = 'UIDVALIDITY';
			}

		$status = $client->status($mailboxName, $items);

		if (empty($status))
			{
			return false;
			}

		// Like ext-imap: "flags" says which SA_* values were asked for, and only those are set, in this order
		$returnStatus = ['flags' => $flags & SA_ALL];

		foreach (['MESSAGES' => SA_MESSAGES, 'RECENT' => SA_RECENT, 'UNSEEN' => SA_UNSEEN, 'UIDNEXT' => SA_UIDNEXT, 'UIDVALIDITY' => SA_UIDVALIDITY] as $key => $flag)
			{
			if (($flags & $flag) && isset($status[$key]))
				{
				$returnStatus[$statusKeys[$key]] = \is_numeric($status[$key]) ? (int)$status[$key] : $status[$key];
				}
			}

		return (object)$returnStatus;
		}

	public static function subscribe(\IMAP\Connection $imap, string $mailbox) : bool
		{
		// ext-imap only reaches the server for a {server}mailbox name; a bare name is looked up locally and fails
		if (! \str_starts_with($mailbox, '{'))
			{
			\PHPFUI\Imap2\Errors::appendError("Can't subscribe to mailbox {$mailbox}: no such mailbox");

			return false;
			}

		$client = $imap->getClient();

		$success = $client->subscribe(self::withoutServer($mailbox));

		if (! $success)
			{
			\PHPFUI\Imap2\Errors::appendError($client->error ?: "Can't subscribe to mailbox {$mailbox}");
			}

		return $success;
		}

	public static function unsubscribe(\IMAP\Connection $imap, string $mailbox) : bool
		{
		if (! \str_starts_with($mailbox, '{'))
			{
			\PHPFUI\Imap2\Errors::appendError("Can't unsubscribe from mailbox {$mailbox}: no such mailbox");

			return false;
			}

		$client = $imap->getClient();

		$success = $client->unsubscribe(self::withoutServer($mailbox));

		if (! $success)
			{
			\PHPFUI\Imap2\Errors::appendError($client->error ?: "Can't unsubscribe from mailbox {$mailbox}");
			}

		return $success;
		}

	/**
	 * Removes a leading {server} part, so "{imap.example.com:993/imap/ssl}Sent" becomes "Sent"
	 */
	private static function withoutServer(string $mailbox) : string
		{
		if ('' === $mailbox || '{' !== $mailbox[0])
			{
			return $mailbox;
			}

		$end = \strpos($mailbox, '}');

		return false === $end ? $mailbox : \substr($mailbox, $end + 1);
		}
	}
