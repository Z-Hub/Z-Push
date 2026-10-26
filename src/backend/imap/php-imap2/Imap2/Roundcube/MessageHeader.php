<?php

/**
 * +-----------------------------------------------------------------------+
 * | This file is part of the Roundcube Webmail client                     |
 * |                                                                       |
 * | Copyright (C) The Roundcube Dev Team                                  |
 * | Copyright (C) Kolab Systems AG                                        |
 * |                                                                       |
 * | Licensed under the GNU General Public License version 3 or            |
 * | any later version with exceptions for skins & plugins.                |
 * | See the README file for a full license statement.                     |
 * |                                                                       |
 * | PURPOSE:                                                              |
 * |   E-mail message headers representation                               |
 * +-----------------------------------------------------------------------+
 * | Author: Aleksander Machniak <alec@alec.pl>                            |
 * +-----------------------------------------------------------------------+
 */

namespace PHPFUI\Imap2\Roundcube;

/**
 * Struct representing an e-mail message header
 *
 * @package    Framework
 * @subpackage Storage
 */
class MessageHeader
{
	/**
	 * Message additional recipients (bCc)
	 */
	public string $bcc;

	public $body;

	public $bodypart;

	/**
	 * IMAP bodystructure string
	 */
	public string $bodystructure;

	/**
	 * Message additional recipients (Cc)
	 */
	public string $cc;

	/**
	 * Message charset
	 */
	public string $charset;

	/**
	 * Message Content-type
	 */
	public string $ctype;

	/**
	 * Message date (Date)
	 */
	public string $date;

	/**
	 * Message encoding
	 */
	public string $encoding;

	public $envelope;

	/**
	 * Message flags
	 */
	public array $flags = [];

	/**
	 * IMAP folder this message is stored in
	 */
	public string $folder;

	/**
	 * Message sender (From)
	 */
	public string $from;

	/**
	 * Message sequence number
	 */
	public int $id;

	/**
	 * Message In-Reply-To header
	 */
	public string $in_reply_to;

	/**
	 * IMAP internal date
	 */
	public string $internaldate;

	/**
	 * Message receipt recipient
	 */
	public string $mdn_to;

	/**
	 * Message identifier (Message-ID)
	 */
	public string $messageID;

	public $modseq;

	/**
	 * Other message headers
	 */
	public array $others = [];

	/**
	 * Message priority (X-Priority)
	 */
	public int $priority;

	/**
	 * Message References header
	 */
	public string $references = '';

	/**
	 * Message Reply-To header
	 */
	public string $replyto;

	/**
	 * Message size
	 */
	public int $size;

	/**
	 * Message subject
	 */
	public string $subject;

	/**
	 * Message timestamp (based on message date)
	 */
	public int $timestamp;

	/**
	 * Message recipient (To)
	 */
	public string $to;

	/**
	 * Message unique identifier
	 */
	public int $uid;

	// map header to rcube_message_header object property
	private $obj_headers = [
		'date' => 'date',
		'from' => 'from',
		'to' => 'to',
		'subject' => 'subject',
		'reply-to' => 'replyto',
		'cc' => 'cc',
		'bcc' => 'bcc',
		'mbox' => 'folder',
		'folder' => 'folder',
		'content-transfer-encoding' => 'encoding',
		'in-reply-to' => 'in_reply_to',
		'content-type' => 'ctype',
		'charset' => 'charset',
		'references' => 'references',
		'return-receipt-to' => 'mdn_to',
		'disposition-notification-to' => 'mdn_to',
		'x-confirm-reading-to' => 'mdn_to',
		'message-id' => 'messageID',
		'x-priority' => 'priority',
	];

	/**
	 * Returns header value
	 */
	public function get(string $name, bool $decode = true)
	{
		$name = \strtolower($name);

		if (isset($this->obj_headers[$name])) {
			$value = $this->{$this->obj_headers[$name]};
		}
		else {
			$value = $this->others[$name];
		}

		if ($decode) {
			if (\is_array($value)) {
				foreach ($value as $key => $val) {
					$val = Mime::decode_header($val, $this->charset);
					$value[$key] = Charset::clean($val);
				}
			}
			else {
				$value = Mime::decode_header($value, $this->charset);
				$value = Charset::clean($value);
			}
		}

		return $value;
	}

	/**
	 * Sets header value
	 */
	public function set(string $name, $value) : void
	{
		$name = \strtolower($name);

		if (isset($this->obj_headers[$name])) {
			$this->{$this->obj_headers[$name]} = $value;
		}
		else {
			$this->others[$name] = $value;
		}
	}
}
