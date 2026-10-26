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

class Errors
  {
  protected static array $alerts = [];

  protected static array | false $errors = [];

  protected static string | bool $lastError = false;

  public static function alerts() : array
		{
		$return = self::$alerts;

		self::$alerts = [];

		return $return;
		}

  public static function appendError(string $error) : string
		{
		self::$lastError = $error;

		if (! self::$errors)
			{
			self::$errors = [];
			}
		self::$errors[] = $error;

		return $error;
		}

  public static function appendErrorCanNotOpen(string $mailbox, string $error) : string
		{
		if (\strlen($mailbox) && '{' == $mailbox[0])
			{
			$error = \preg_replace("/^AUTHENTICATE [A-Z]+\d*:\s/i", '', $error);
			$error = 'Can not authenticate to IMAP server: ' . $error;
			}
		else
			{
			$error = "Can't open mailbox {$mailbox}: no such mailbox";
			}

		return self::appendError($error);
		}

  public static function badMessageNumber() : string
		{
		$backtrace = \debug_backtrace();
		$error = $backtrace[1]['function'] . '(): Bad message number in '
				 . $backtrace[1]['file'] . ' on line ' . $backtrace[1]['line'];

		return self::appendError($error);
		}

  public static function couldNotOpenStream(string $mailbox) : string
		{
		$backtrace = \debug_backtrace();
		$depth = 1;

		if (isset($backtrace[$depth + 1]['function']) && 'imap_open' == $backtrace[$depth + 1]['function'])
			{
			$depth++;
			}

		return $backtrace[$depth]['function'] . '(): Couldn\'t open stream ' . $mailbox
			 . ' in ' . $backtrace[$depth]['file'] . ' on line ' . $backtrace[$depth]['line'];
		}

	public static function errors() : array | false
		{
		$return = self::$errors;

		self::$errors = false;

		return $return;
		}

	public static function lastError() : string | false
		{
		return self::$lastError;
		}
	}
