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

		$success = $client->append($mailbox, $message);

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

		if ('{' == $mailbox[0])
			{
			$mailbox = (string)\preg_replace('/^{.+}/', '', $mailbox);
			}

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

		if ('{' == $mailbox[0])
			{
			$mailbox = (string)\preg_replace('/^{.+}/', '', $mailbox);
			}

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

	public static function listSubscribed(\IMAP\Connection $imap, $mailbox) : bool
		{
		return false;
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
		return $imap->getClient()->renameFolder($from, $to);
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

		$returnStatus = [];

		foreach ($status as $key => $value)
			{
			$returnStatus[$statusKeys[$key]] = \is_numeric($value) ? (int)$value : $value;
			}

		return (object)$returnStatus;
		}

	public static function subscribe(\IMAP\Connection $imap, string $mailbox) : bool
		{
		return $imap->getClient()->deleteFolder($mailbox);
		}

	public static function unsubscribe(\IMAP\Connection $imap, string $mailbox) : bool
		{
		return $imap->getClient()->deleteFolder($mailbox);
		}
	}
