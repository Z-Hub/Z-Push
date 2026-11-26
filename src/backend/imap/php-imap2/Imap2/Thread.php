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

class Thread
	{
	public static function thread(\IMAP\Connection $imap, int $flags = SE_FREE) : false | array
		{
		$client = $imap->getClient();
		$thread = $client->thread($imap->getMailboxName());

		if (empty($thread->count()))
			{
			return false;
			}

		return $thread->get();
		}
	}
