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

class Mail
	{
	/**
	 * Copy specified messages to a mailbox.
	 */
	public static function copy(\IMAP\Connection $imap, $messageNums, string $mailbox, int $flags = 0) : bool
		{
		if ($flags & CP_MOVE)
			{
			return Mail::move($imap, $messageNums, $mailbox, $flags);
			}

		$client = $imap->getClient();

		if (! ($flags & CP_UID))
			{
			$messageNums = \PHPFUI\Imap2\Message::idToUid($imap, $messageNums);
			}

		$from = $imap->getMailboxName();
		$to = $mailbox;

		return $client->copy($messageNums, $from, $to);
		}

	/**
	 * Move specified messages to a mailbox.
	 */
	public static function move(\IMAP\Connection $imap, string $messageNums, string $mailbox, int $flags = 0) : bool
		{
		$client = $imap->getClient();

		if (! ($flags & CP_UID))
			{
			$messageNums = \PHPFUI\Imap2\Message::idToUid($imap, $messageNums);
			}

		return $client->move($messageNums, $imap->getMailboxName(), $mailbox);
		}
	}
