<?php

/*
 * This file is part of the PHP IMAP2 package.
 *
 * (c) Francesco Bianco <bianco@PHPFUI.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace IMAP;

class Connection
	{
	protected \PHPFUI\Imap2\Roundcube\ImapClient $client;

	protected bool $connected;

	protected string $currentMailbox;

	protected string $host;

	protected int $port;

	protected array $registry;

	protected string $sslMode;

	public function __construct(protected $mailbox, protected string $user, protected string $password, protected int $flags = 0, protected int $retries = 0, protected array $options = [])
		{
		$this->openMailbox($mailbox);

		$this->client = new \PHPFUI\Imap2\Roundcube\ImapClient();
		}

	public static function close(\IMAP\Connection $imap, int $flags = 0) : true
		{
		$client = $imap->getClient();

		if ($client->close())
			{
			return true;
			}

		$client->closeConnection();

		return true;
		}

	public function getClient() : \PHPFUI\Imap2\Roundcube\ImapClient
		{
		return $this->client;
		}

	public function getHost() : string
		{
		return $this->host;
		}

	public function getLastError() : string
		{
		$client = $this->getClient();

		return $client->error;
		}

	public function getMailbox() : string
		{
		return $this->mailbox;
		}

	public function getMailboxName() : string
		{
		return $this->currentMailbox;
		}

	public function getRegistryValue(string $space, string $item, $key) : string | false
		{
		if (isset($this->registry[$space][$item][$key]))
			{
			return $this->registry[$space][$item][$key];
			}

		return false;
		}

	public function isConnected() : bool
		{
		return $this->connected;
		}

	public static function isValid(\IMAP\Connection $imap) : bool
		{
		return $imap->isConnected();
		}

	/**
	 * Open an IMAP stream to a mailbox.
	 */
	public static function open(string $mailbox, string $user, string $password, int $flags = 0, int $retries = 0, array $options = []) : Connection | false
		{
		if ($flags && ! ($flags & 4095) && ! ($flags & CL_EXPUNGE))
			{
			throw new \ValueError('imap_open(): Argument #4 ($flags) must be a bitmask of the OP_* constants, and CL_EXPUNGE');
			}

		$connection = new Connection($mailbox, $user, $password, $flags, $retries, $options);

		$success = $connection->connect();

		if (empty($success))
			{
			\PHPFUI\Imap2\Errors::appendErrorCanNotOpen($connection->getMailbox(), $connection->getLastError());

			\trigger_error(\PHPFUI\Imap2\Errors::couldNotOpenStream($connection->getMailbox()), E_USER_WARNING);

			return false;
			}

		return $connection;
		}

	public function openMailbox(string $mailbox) : void
		{
		$this->mailbox = $mailbox;

		$mailboxParts = \PHPFUI\Imap2\Functions::parseMailboxString($mailbox);

		$this->host = \PHPFUI\Imap2\Functions::getHostFromMailbox($mailboxParts);
		$this->port = $mailboxParts['port'] ?? 0;
		$this->sslMode = \PHPFUI\Imap2\Functions::getSslModeFromMailbox($mailboxParts);
		$this->currentMailbox = $mailboxParts['mailbox'];
		}

	public static function ping(\IMAP\Connection $imap) : bool
		{
		$client = $imap->getClient();
		$status = $client->status($imap->getMailboxName(), ['UIDNEXT']);

		return isset($status['UIDNEXT']) && $status['UIDNEXT'] > 0;
		}

	public static function reopen(\IMAP\Connection $imap, string $mailbox, int $flags = 0, int $retries = 0) : bool
		{
		$imap->openMailbox($mailbox);

		$success = $imap->connect();

		if (empty($success))
			{
			\trigger_error('imap_reopen(): Couldn\'t re-open stream', E_USER_WARNING);

			return false;
			}

		$imap->selectMailbox();

		return true;
		}

	public function selectMailbox() : void
		{
		$success = $this->client->select($this->currentMailbox);

		if (empty($success))
			{
			$this->rewriteMailbox('<no_mailbox>');
			}
		}

	public function setRegistryValue($space, $item, $key, $value) : void
		{
		if (empty($this->registry))
			{
			$this->registry = [];
			}

		if (empty($this->registry[$space]))
			{
			$this->registry[$space] = [];
			}

		if (empty($this->registry[$space][$item]))
			{
			$this->registry[$space][$item] = [];
			}

		$this->registry[$space][$item][$key] = $value;
		}

	protected function connect() : bool|static
		{
		$this->connected = false;
		$client = $this->getClient();

		$success = $client->connect($this->host, $this->user, $this->password, [
			'port' => $this->port,
			'ssl_mode' => $this->sslMode,
			'auth_type' => $this->flags & OP_XOAUTH2 ? 'XOAUTH2' : 'IMAP',
			'timeout' => -1,
			'force_caps' => false,
		]);

		if (empty($success))
			{
			return false;
			}

		if (empty($this->currentMailbox))
			{
			$mailboxes = $this->client->listMailboxes('', '*');

			if (empty($mailboxes))
				{
				return false;
				}

			if (\in_array('INBOX', $mailboxes))
				{
				$this->currentMailbox = 'INBOX';
				$this->mailbox .= 'INBOX';
				}
			}

		$this->rewriteMailbox();

		$this->connected = true;

		return $this;
		}

	protected function rewriteMailbox(?string $forceMailbox = null) : void
		{
		$mailboxParts = \PHPFUI\Imap2\Functions::parseMailboxString($this->mailbox);

		$params = [];

		$params[] = 'imap';

		if ('ssl' == $this->sslMode)
			{
			$params[] = 'notls';
			$params[] = 'ssl';
			}
		$params[] = 'user="' . $this->user . '"';

		$mailboxName = $forceMailbox ?: $mailboxParts['mailbox'];

		$updatedMailbox = '{' . $mailboxParts['host'] . ':' . $mailboxParts['port'] . '/' . \implode('/', $params) . '}' . $mailboxName;

		$this->mailbox = $updatedMailbox;
		}
	}
