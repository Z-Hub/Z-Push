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

class Message
	{
	public static function body(\IMAP\Connection $imap, array | string $messageNum, int $flags = 0)
		{
		$client = $imap->getClient();

		$isUid = (bool)($flags & FT_UID);

		/**
		 * TODO
		 * The information on whether the fetch operation will be performed in PEEK mode should be obtained
		 * from the $flags parameter. The $flags parameter needs to be moved to where the fetch() function is called,
		 * and the PEEK mode needs to be decided whether the $flags variable contains the FT_PEEK value.
		 */
		$messages = $client->fetch($imap->getMailboxName(), $messageNum, $isUid, ['BODY.PEEK[TEXT]']);

		if ($isUid && \is_array($messages))
			{
			$messages = \PHPFUI\Imap2\Functions::keyBy('uid', $messages);
			}

		return $messages[$messageNum]->bodypart['TEXT'];
		}

	public static function bodyStruct(\IMAP\Connection $imap, array | string $messageNum, $section)
		{
		$client = $imap->getClient();

		$messages = $client->fetch($imap->getMailboxName(), $messageNum, false, ['BODY.PEEK[' . $section . ']']);

		if ($section)
			{
			return $messages[$messageNum]->bodypart[$section];
			}

		return $messages[$messageNum]->body;
		}

	/**
	 * Clears cache
	 */
	public static function clearCache(\IMAP\Connection $imap, int $flags) : true
		{
		$client = $imap->getClient();

		// also clears the status cache of the selected mailbox
		$client->clear_mailbox_cache();

		return true;
		}

	/**
	 * Clears flags on messages.
	 */
	public static function clearFlagFull(\IMAP\Connection $imap, array | string $sequence, $flag, $options = 0) : bool
		{
		return self::modifyFlags($imap, $sequence, (string)$flag, (int)$options, false);
		}

	public static function delete(\IMAP\Connection $imap, array | string $messageNums, int $flags = 0)
		{
		$client = $imap->getClient();

		$messages = $client->fetch($imap->getMailboxName(), $messageNums, false, ['UID']);

		$uid = [];

		foreach ($messages as $message)
			{
			$uid[] = $message->uid;
			}

		$client->flag($imap->getMailboxName(), \implode(',', $uid), $client->flags['DELETED']);

		return true;
		}

	public static function expunge(\IMAP\Connection $imap)
		{
		return $imap->getClient()->expunge($imap->getMailboxName());
		}

	public static function fetchBody(\IMAP\Connection $imap, array | string $messageNum, $section, int $flags = 0)
		{
		$client = $imap->getClient();

		$isUid = (bool)($flags & FT_UID);
		$messages = $client->fetch($imap->getMailboxName(), $messageNum, $isUid, ['BODY.PEEK[' . $section . ']']);

		if (empty($messages))
			{
			\trigger_error(\PHPFUI\Imap2\Errors::badMessageNumber(), E_USER_WARNING);

			return false;
			}

		if ($isUid)
			{
			$messages = \PHPFUI\Imap2\Functions::keyBy('uid', $messages);
			}

		if ($section)
			{
			return $messages[$messageNum]->bodypart[$section];
			}

		return $messages[$messageNum]->body;
		}

	public static function fetchHeader(\IMAP\Connection $imap, array | string $messageNum, int $flags = 0)
		{
		$client = $imap->getClient();

		$isUid = (bool)($flags & FT_UID);

		$messages = $client->fetch($imap->getMailboxName(), $messageNum, $isUid, ['BODY.PEEK[HEADER]']);

		if (empty($messages))
			{
			return false;
			}

		foreach ($messages as $message)
			{
			return $message->bodypart['HEADER'] ?? false;
			}
		}

	public static function fetchMime(\IMAP\Connection $imap, int $messageNum, $section, int $flags = 0) : string | false
		{
		if ($messageNum <= 0)
			{
			\trigger_error(\PHPFUI\Imap2\Errors::badMessageNumber(), E_USER_WARNING);

			return false;
			}

		$client = $imap->getClient();

		$isUid = (bool)($flags & FT_UID);

		$sectionKey = $section . '.MIME';
		$messages = $client->fetch($imap->getMailboxName(), "{$messageNum}", $isUid, ['BODY.PEEK[' . $sectionKey . ']']);

		if (empty($messages))
			{
			return false;
			}

		if ($isUid)
			{
			$messages = \PHPFUI\Imap2\Functions::keyBy('uid', $messages);
			}

		if ($section && isset($messages[$messageNum]->bodypart[$sectionKey]))
			{
			return $messages[$messageNum]->bodypart[$sectionKey];
			}

		return $messages[$messageNum]->body;
		}

	public static function fetchOverview(\IMAP\Connection $imap, array | string $sequence, int $flags = 0) : array | false
		{
		$client = $imap->getClient();

		$isUid = (bool)($flags & FT_UID);
		$messages = $client->fetch($imap->getMailboxName(), $sequence, $isUid, [
			'BODY.PEEK[HEADER.FIELDS (SUBJECT FROM TO CC REPLYTO MESSAGEID DATE SIZE REFERENCES)]',
			'UID',
			'FLAGS',
			'INTERNALDATE',
			'RFC822.SIZE',
			'ENVELOPE',
			'RFC822.HEADER'
		]);


		if (false === $messages)
			{
			return false;
			}

		// Like ext-imap: messages that do not exist are left out, so a missing UID gives an empty array
		$overview = [];

		foreach ($messages as $message)
			{
			$envelope = \is_array($message->envelope ?? null) ? $message->envelope : [];

			// ext-imap only sets these properties when the message has a value for them
			$optional = [
				'subject' => $envelope[1] ?? null,
				// ext-imap gives only the first address of From and To
				'from' => \PHPFUI\Imap2\Functions::writeAddressFromEnvelope(\PHPFUI\Imap2\Functions::firstAddress($envelope[2] ?? null)),
				'to' => \PHPFUI\Imap2\Functions::writeAddressFromEnvelope(\PHPFUI\Imap2\Functions::firstAddress($envelope[5] ?? null)),
				'date' => $envelope[0] ?? null,
				'message_id' => $envelope[9] ?? null,
				'references' => $message->references ?? null,
				'in_reply_to' => $envelope[8] ?? null,
			];

			$messageEntry = new \stdClass();

			foreach ($optional as $name => $value)
				{
				if (null !== $value && '' !== $value)
					{
					$messageEntry->{$name} = $value;
					}
				}

			$messageEntry->size = $message->size;
			$messageEntry->uid = $message->uid;
			$messageEntry->msgno = $message->id;
			$messageEntry->recent = (int)($message->flags['RECENT'] ?? 0);
			$messageEntry->flagged = (int)($message->flags['FLAGGED'] ?? 0);
			$messageEntry->answered = (int)($message->flags['ANSWERED'] ?? 0);
			$messageEntry->deleted = (int)($message->flags['DELETED'] ?? 0);
			$messageEntry->seen = (int)($message->flags['SEEN'] ?? 0);
			$messageEntry->draft = (int)($message->flags['DRAFT'] ?? 0);
			$messageEntry->udate = \strtotime($message->internaldate);

			$overview[] = $messageEntry;
			}

		return $overview;
		}

	public static function fetchStructure(\IMAP\Connection $imap, array | string $messageNum, int $flags = 0) : \stdClass | false
		{
		$client = $imap->getClient();

		$isUid = (bool)($flags & FT_UID);

		$messages = $client->fetch($imap->getMailboxName(), $messageNum, $isUid, ['BODYSTRUCTURE']);

		if (! empty($messages))
			{
			foreach ($messages as $message)
				{
				return \PHPFUI\Imap2\BodyStructure::fromMessage($message);
				}
			}

		return false;
		}

	public static function fetchUids(\IMAP\Connection $imap, array | string $sequence, int $flags = 0) : array | false
		{
		$client = $imap->getClient();

		$isUid = (bool)($flags & FT_UID);
		$messages = $client->fetch($imap->getMailboxName(), $sequence, $isUid, ['UID']);

		if ('*' != $sequence && \count($messages) < \PHPFUI\Imap2\Functions::expectedNumberOfMessages($sequence))
			{
			return false;
			}

		return $messages;
		}

	public static function headerInfo(\IMAP\Connection $imap, array | string $messageNum, int $fromLength = 0, int $subjectLength = 0, ?string $defaultHost = null) : \stdClass | false
		{
		$client = $imap->getClient();

		$messages = $client->fetch($imap->getMailboxName(), $messageNum, false, [
			'BODY.PEEK[HEADER.FIELDS (SUBJECT FROM TO CC REPLY-TO DATE SIZE REFERENCES)]',
			'ENVELOPE',
			'INTERNALDATE',
			'UID',
			'FLAGS',
			'RFC822.SIZE',
			'RFC822.HEADER'
		]);

		if (\count($messages))
			{
			foreach ($messages as $message)
				{
				return \PHPFUI\Imap2\HeaderInfo::fromMessage($message, $defaultHost);
				}
			}

		return false;
		}

	public static function headers(\IMAP\Connection $imap) : array
		{
		$client = $imap->getClient();

		$status = $client->status($imap->getMailboxName(), ['MESSAGES']);

		if (empty($status['MESSAGES']))
			{
			return [];
			}

		$sequence = '1:' . (int)($status['MESSAGES']);
		$messages = $client->fetch($imap->getMailboxName(), $sequence, false, [
			'BODY.PEEK[HEADER.FIELDS (SUBJECT FROM TO CC REPLYTO MESSAGEID DATE SIZE REFERENCES)]',
			//'UID',
			'FLAGS',
			'INTERNALDATE',
			'RFC822.SIZE',
			//'ENVELOPE',
			'RFC822.HEADER'
		]);

		if (empty($messages))
			{
			return [];
			}

		$headers = [];

		foreach ($messages as $message)
			{
			$from = ' ';

			if ('no_host' != $message->from)
				{
				$from = \imap_rfc822_parse_adrlist($message->from, 'no_host');
				$from = $from[0]->personal ?? $message->from;
				}

			$date = \explode(' ', $message->internaldate);
			$subject = empty($message->subject) ? ' ' : $message->subject;
			$unseen = empty($message->flags['SEEN']) ? 'U' : ' ';
			$flagged = empty($message->flags['FLAGGED']) ? ' ' : 'F';
			$answered = empty($message->flags['ANSWERED']) ? ' ' : 'A';
			$draft = empty($message->flags['DRAFT']) ? ' ' : 'D';
			$deleted = empty($message->flags['DELETED']) ? ' ' : 'X';

			$header = ' ' . $unseen . $flagged . $answered . $draft . $deleted . ' '
					. \str_pad($message->id, 3, ' ', STR_PAD_LEFT) . ')' . $date[0] . ' ' . \str_pad($from, 20, ' ') . ' '
					. \substr($subject, 0, 25) . ' (' . $message->size . ' chars)';

			$headers[] = $header;
			}

		return $headers;
		}

	/**
	 * Convert a string contain a sequence of message id to and equivalent with uid.
	 */
	public static function idToUid(\IMAP\Connection $imap, array | string $messageNums) : string
		{
		$client = $imap->getClient();

		$messages = $client->fetch($imap->getMailboxName(), $messageNums, false, ['UID']);

		$uid = [];

		foreach ($messages as $message)
			{
			$uid[] = $message->uid;
			}

		return \implode(',', $uid);
		}

	public static function msgno(\IMAP\Connection $imap, $messageUid)
		{
		$client = $imap->getClient();

		$msgNo = self::uidtoid($imap, $messageUid);

		return \is_numeric($msgNo) ? (int)$msgNo : $msgNo;
		}

	public static function saveBody(\IMAP\Connection $imap, $file, array | string $messageNum, string $section = '', $flags = 0)
		{
		$client = $imap->getClient();

		$messages = $client->fetch($imap->getMailboxName(), $messageNum, false, ['BODY.PEEK[' . $section . ']']);

		$body = $section ? $messages[$messageNum]->bodypart[$section] : $messages[$messageNum]->body;

		return \file_put_contents($file, $body);
		}

	/**
	 * Returns an array of messages matching the given search criteria.
	 */
	public static function search(\IMAP\Connection $imap, $criteria, int $flags = SE_FREE, string $charset = '') : array | false
		{
		$client = $imap->getClient();

		try
			{
			$program = \PHPFUI\Imap2\SearchCriteria::toImap((string)$criteria, $charset);
			}
		catch (\InvalidArgumentException $e)
			{
			\PHPFUI\Imap2\Errors::appendError($e->getMessage());

			return false;
			}

		$result = $client->search($imap->getMailboxName(), $program, (bool)($flags & SE_UID));

		if (empty($result->count()))
			{
			return false;
			}

		$messages = [];

		foreach ($result->get() as $message)
			{
			if (\ctype_digit((string)$message))
				{
				$messages[] = (int)$message;
				}
			}

		return $messages ?: false;
		}

	/**
	 * Sets flags on messages.
	 */
	public static function setFlagFull(\IMAP\Connection $imap, array | string $sequence, string $flag, int $options = 0) : bool
		{
		return self::modifyFlags($imap, $sequence, $flag, $options, true);
		}

	/**
	 * Sets or clears every flag in a space separated list, like "\Seen \Flagged $Forwarded"
	 *
	 * Each flag is stored as given, so system flags keep their backslash and keywords are not altered.
	 * Like ext-imap the result does not depend on the server reply: failures are recorded for imap_errors().
	 */
	private static function modifyFlags(\IMAP\Connection $imap, array | string $sequence, string $flag, int $options, bool $set) : bool
		{
		$client = $imap->getClient();
		$mailbox = $imap->getMailboxName();

		if (! ($options & ST_UID))
			{
			$messages = $client->fetch($mailbox, $sequence, false, ['UID']);

			$uid = [];

			foreach ($messages ?: [] as $message)
				{
				$uid[] = $message->uid;
				}

			if (! $uid)
				{
				return true;
				}

			$sequence = \implode(',', $uid);
			}

		foreach (\preg_split('/\s+/', \trim($flag), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $oneFlag)
			{
			$ok = $set ? $client->flag($mailbox, $sequence, $oneFlag) : $client->unflag($mailbox, $sequence, $oneFlag);

			if (! $ok)
				{
				\PHPFUI\Imap2\Errors::appendError(($set ? 'Unable to set flag ' : 'Unable to clear flag ') . $oneFlag);
				}
			}

		return true;
		}

	public static function sort(\IMAP\Connection $imap, $criteria, $reverse, int $flags = 0, $searchCriteria = null, ?string $charset = null) : array | false
		{
		$fields = [
			SORTDATE => 'DATE',
			SORTARRIVAL => 'ARRIVAL',
			SORTFROM => 'FROM',
			SORTSUBJECT => 'SUBJECT',
			SORTTO => 'TO',
			SORTCC => 'CC',
			SORTSIZE => 'SIZE',
		];

		if (! isset($fields[$criteria]))
			{
			\PHPFUI\Imap2\Errors::appendError('Unknown sort criterion');

			return false;
			}

		try
			{
			$program = \PHPFUI\Imap2\SearchCriteria::toImap((string)$searchCriteria);
			}
		catch (\InvalidArgumentException $e)
			{
			\PHPFUI\Imap2\Errors::appendError($e->getMessage());

			return false;
			}

		$client = $imap->getClient();

		$result = $client->sort($imap->getMailboxName(), $fields[$criteria], 'ALL' === $program ? '' : $program, (bool)($flags & SE_UID), $charset ?: 'UTF-8');

		$messages = [];

		foreach ($result->get() as $message)
			{
			if (\ctype_digit((string)$message))
				{
				$messages[] = (int)$message;
				}
			}

		return $reverse ? \array_reverse($messages) : $messages;
		}

	/**
	 * The UID of a message number, or false when there is no such message (as ext-imap)
	 */
	public static function uid(\IMAP\Connection $imap, int $messageNum) : int | false
		{
		$uid = self::idToUid($imap, (string)$messageNum);

		return \ctype_digit($uid) ? (int)$uid : false;
		}

	/**
	 * Convert a string contain a sequence of uid(s) to an equivalent with id(s).
	 */
	public static function uidToId(\IMAP\Connection $imap, int $messageUid) : string
		{
		$client = $imap->getClient();

		$messages = $client->fetch($imap->getMailboxName(), "{$messageUid}", true, ['UID']);

		$id = [];

		foreach ($messages as $message)
			{
			$id[] = $message->id;
			}

		return \implode(',', $id);
		}

	public static function undelete(\IMAP\Connection $imap, string $messageNums, int $flags = 0)
		{
		$client = $imap->getClient();

		$messages = $client->fetch($imap->getMailboxName(), $messageNums, false, ['UID']);

		foreach ($messages as $message)
			{
			$client->unflag($imap->getMailboxName(), $message->uid, $client->flags['DELETED']);
			}

		return true;
		}
	}
