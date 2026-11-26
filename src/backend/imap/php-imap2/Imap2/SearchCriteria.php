<?php

/*
 * This file is part of the PHP IMAP2 package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPFUI\Imap2;

/**
 * Reads an imap_search() criteria string the way ext-imap (c-client) does, and rebuilds it as an IMAP SEARCH program.
 *
 * Only the search keys c-client understands are accepted. Every value is re-encoded with ImapClient::escape(), so
 * the caller's text is never sent to the server as raw protocol.
 */
class SearchCriteria
	{
	/** Keys without an argument */
	private const FLAG_KEYS = ['ALL', 'ANSWERED', 'DELETED', 'FLAGGED', 'NEW', 'OLD', 'RECENT', 'SEEN', 'UNANSWERED', 'UNDELETED', 'UNFLAGGED', 'UNSEEN'];

	/** Keys followed by a date */
	private const DATE_KEYS = ['BEFORE', 'ON', 'SINCE'];

	/** Keys followed by a flag keyword */
	private const KEYWORD_KEYS = ['KEYWORD', 'UNKEYWORD'];

	/** Keys followed by a string */
	private const STRING_KEYS = ['BCC', 'BODY', 'CC', 'FROM', 'SUBJECT', 'TEXT', 'TO'];

	/** Date formats c-client accepts, tried in order */
	private const DATE_FORMATS = ['!j-M-Y', '!j M Y', '!Y-m-d'];

	/**
	 * Returns the IMAP search program for $criteria
	 *
	 * @throws \InvalidArgumentException with the message ext-imap would report, when the criteria are not valid
	 */
	public static function toImap(string $criteria, string $charset = '') : string
		{
		// c-client rejects line breaks anywhere in the criteria
		if (\strpbrk($criteria, "\r\n") !== false)
			{
			throw new \InvalidArgumentException('Invalid search criteria: line break');
			}

		$program = [];
		$hasEightBit = false;
		$position = 0;
		$length = \strlen($criteria);

		while (null !== ($key = self::nextAtom($criteria, $position, $length)))
			{
			$key = \strtoupper($key);

			if (\in_array($key, self::FLAG_KEYS, true))
				{
				$program[] = $key;
				}
			elseif (\in_array($key, self::DATE_KEYS, true))
				{
				$program[] = $key . ' ' . self::parseDate(self::nextValue($criteria, $position, $length, $key));
				}
			elseif (\in_array($key, self::KEYWORD_KEYS, true))
				{
				$keyword = self::nextValue($criteria, $position, $length, $key);

				if (\preg_match('/[\x00-\x20\x22\x25\x28-\x2A\x5C\x5D\x7B\x7F-\xFF]/', $keyword))
					{
					throw new \InvalidArgumentException("Invalid search keyword: {$keyword}");
					}

				$program[] = $key . ' ' . $keyword;
				}
			elseif (\in_array($key, self::STRING_KEYS, true))
				{
				$value = self::nextValue($criteria, $position, $length, $key);
				$hasEightBit = $hasEightBit || \preg_match('/[\x80-\xFF]/', $value);
				$program[] = $key . ' ' . \PHPFUI\Imap2\Roundcube\ImapClient::escape($value);
				}
			else
				{
				throw new \InvalidArgumentException("Unknown search criterion: {$key}");
				}
			}

		if (! $program)
			{
			return 'ALL';
			}

		$search = \implode(' ', $program);

		if ($hasEightBit && '' !== $charset)
			{
			$search = 'CHARSET ' . \PHPFUI\Imap2\Roundcube\ImapClient::escape($charset) . ' ' . $search;
			}

		return $search;
		}

	/**
	 * Returns the next space separated word, or null at the end of the criteria
	 */
	private static function nextAtom(string $criteria, int &$position, int $length) : ?string
		{
		while ($position < $length && ' ' === $criteria[$position])
			{
			++$position;
			}

		if ($position >= $length)
			{
			return null;
			}

		$end = \strpos($criteria, ' ', $position);
		$end = false === $end ? $length : $end;
		$atom = \substr($criteria, $position, $end - $position);
		$position = $end;

		return $atom;
		}

	/**
	 * Returns the argument of $key: a quoted string (read up to the next quote, backslashes kept as they are) or a word
	 */
	private static function nextValue(string $criteria, int &$position, int $length, string $key) : string
		{
		while ($position < $length && ' ' === $criteria[$position])
			{
			++$position;
			}

		if ($position >= $length)
			{
			throw new \InvalidArgumentException("Missing argument for search criterion: {$key}");
			}

		if ('"' !== $criteria[$position])
			{
			return (string)self::nextAtom($criteria, $position, $length);
			}

		$end = \strpos($criteria, '"', $position + 1);

		if (false === $end)
			{
			throw new \InvalidArgumentException("Unterminated string for search criterion: {$key}");
			}

		// the closing quote must end the word
		if ($end + 1 < $length && ' ' !== $criteria[$end + 1])
			{
			throw new \InvalidArgumentException("Invalid string for search criterion: {$key}");
			}

		$value = \substr($criteria, $position + 1, $end - $position - 1);
		$position = $end + 1;

		if ('' === $value)
			{
			throw new \InvalidArgumentException("Empty string for search criterion: {$key}");
			}

		return $value;
		}

	/**
	 * Converts a date in any format c-client accepts to an IMAP date (d-Mon-yyyy)
	 */
	private static function parseDate(string $value) : string
		{
		foreach (self::DATE_FORMATS as $format)
			{
			$date = \DateTimeImmutable::createFromFormat($format, $value);
			$errors = \DateTimeImmutable::getLastErrors();

			if (false !== $date && (false === $errors || (0 === $errors['warning_count'] && 0 === $errors['error_count'])))
				{
				return $date->format('j-M-Y');
				}
			}

		throw new \InvalidArgumentException("Invalid date for search criterion: {$value}");
		}
	}
