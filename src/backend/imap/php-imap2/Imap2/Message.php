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

		$client->clear_mailbox_cache();
		$client->clear_status_cache();

		return true;
		}

	/**
	 * Clears flags on messages.
	 */
	public static function clearFlagFull(\IMAP\Connection $imap, array | string $sequence, $flag, $options = 0) : string | false
		{
		$client = $imap->getClient();

		if (! ($options & ST_UID))
			{
			$messages = $client->fetch($imap->getMailboxName(), $sequence, false, ['UID']);

			$uid = [];

			foreach ($messages as $message)
				{
				$uid[] = $message->uid;
				}

			$sequence = \implode(',', $uid);
			}

		$client->unflag($imap->getMailboxName(), $sequence, \strtoupper(\substr($flag, 1)));

		return false;
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

		if ('*' != $sequence && \count($messages) < \PHPFUI\Imap2\Functions::expectedNumberOfMessages($sequence))
			{
			return false;
			}

		$overview = [];

		foreach ($messages as $message)
			{
			$messageEntry = (object)[
				'subject' => $message->envelope[1],
				'from' => \PHPFUI\Imap2\Functions::writeAddressFromEnvelope($message->envelope[2]),
				'to' => $message->get('to'),
				'date' => $message->envelope[0],
				'message_id' => $message->envelope[9],
				'references' => $message->references,
				'in_reply_to' => $message->envelope[8],
				'size' => $message->size,
				'uid' => $message->uid,
				'msgno' => $message->id,
				'recent' => (int)($message->flags['RECENT'] ?? 0),
				'flagged' => (int)($message->flags['FLAGGED'] ?? 0),
				'answered' => (int)($message->flags['ANSWERED'] ?? 0),
				'deleted' => (int)($message->flags['DELETED'] ?? 0),
				'seen' => (int)($message->flags['SEEN'] ?? 0),
				'draft' => (int)($message->flags['DRAFT'] ?? 0),
				'udate' => \strtotime($message->internaldate),
			];

			if (empty($messageEntry->subject))
				{
				$messageEntry->subject = null;
				}

			if (empty($messageEntry->references))
				{
				$messageEntry->references = null;
				}

			if (empty($messageEntry->in_reply_to))
				{
				$messageEntry->in_reply_to = null;
				}

			if (empty($messageEntry->to))
				{
				$messageEntry->to = null;
				}

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

		$result = $client->search($imap->getMailboxName(), $criteria, (bool)($flags & SE_UID));

		if (empty($result->count()))
			{
			return false;
			}

		$messages = $result->get();

		foreach ($messages as &$message)
			{
			$message = \is_numeric($message) ? (int)$message : $message;
			}

		return $messages;
		}

	/**
	 * Sets flags on messages.
	 */
	public static function setFlagFull(\IMAP\Connection $imap, array | string $sequence, string $flag, int $options = 0) : bool
		{
		$client = $imap->getClient();

		if (! ($options & ST_UID))
			{
			$messages = $client->fetch($imap->getMailboxName(), $sequence, false, ['UID']);

			$uid = [];

			foreach ($messages as $message)
				{
				$uid[] = $message->uid;
				}

			$sequence = \implode(',', $uid);
			}

		return $client->flag($imap->getMailboxName(), $sequence, \strtoupper(\substr($flag, 1)));
		}

	public static function sort(\IMAP\Connection $imap, $criteria, $reverse, int $flags = 0, $searchCriteria = null, ?string $charset = null) : array | false
		{
		$client = $imap->getClient();

		$result = $client->search($imap->getMailboxName(), $criteria, (bool)($flags & SE_UID));

		if (empty($result->count()))
			{
			return false;
			}

		$messages = $result->get();

		foreach ($messages as &$message)
			{
			$message = \is_numeric($message) ? (int)$message : $message;
			}

		return $messages;
		}

	public static function uid(\IMAP\Connection $imap, int $messageNum) : int | string
		{
		$uid = self::idToUid($imap, $messageNum);

		return \is_numeric($uid) ? (int)$uid : $uid;
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
