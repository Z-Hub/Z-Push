<?php

\define('IMAP2_CHARSET', 'UTF-8');

if (! \defined('NIL')) {
	\define('NIL', 0);
}

if (! \defined('OP_DEBUG')) {
	\define('OP_DEBUG', 1);
}

if (! \defined('OP_READONLY')) {
	\define('OP_READONLY', 2);
}

if (! \defined('OP_ANONYMOUS')) {
	\define('OP_ANONYMOUS', 4);
}

if (! \defined('OP_SHORTCACHE')) {
	\define('OP_SHORTCACHE', 8);
}

if (! \defined('OP_SILENT')) {
	\define('OP_SILENT', 16);
}

if (! \defined('OP_PROTOTYPE')) {
	\define('OP_PROTOTYPE', 32);
}

if (! \defined('OP_HALFOPEN')) {
	\define('OP_HALFOPEN', 64);
}

if (! \defined('OP_EXPUNGE')) {
	\define('OP_EXPUNGE', 128);
}

if (! \defined('OP_SECURE')) {
	\define('OP_SECURE', 256);
}

if (! \defined('OP_XOAUTH2')) {
	\define('OP_XOAUTH2', 512);
}

if (! \defined('CL_EXPUNGE')) {
	\define('CL_EXPUNGE', 32768);
}

if (! \defined('FT_UID')) {
	\define('FT_UID', 1);
}

if (! \defined('FT_PEEK')) {
	\define('FT_PEEK', 2);
}

if (! \defined('FT_NOT')) {
	\define('FT_NOT', 4);
}

if (! \defined('FT_INTERNAL')) {
	\define('FT_INTERNAL', 8);
}

if (! \defined('FT_PREFETCHTEXT')) {
	\define('FT_PREFETCHTEXT', 32);
}

if (! \defined('ST_UID')) {
	\define('ST_UID', 1);
}

if (! \defined('ST_SILENT')) {
	\define('ST_SILENT', 2);
}

if (! \defined('ST_SET')) {
	\define('ST_SET', 4);
}

if (! \defined('CP_UID')) {
	\define('CP_UID', 1);
}

if (! \defined('CP_MOVE')) {
	\define('CP_MOVE', 2);
}

if (! \defined('SE_UID')) {
	\define('SE_UID', 1);
}

if (! \defined('SE_FREE')) {
	\define('SE_FREE', 2);
}

if (! \defined('SE_NOPREFETCH')) {
	\define('SE_NOPREFETCH', 4);
}

if (! \defined('SO_FREE')) {
	\define('SO_FREE', 8);
}

if (! \defined('SO_NOSERVER')) {
	\define('SO_NOSERVER', 8);
}

if (! \defined('SA_MESSAGES')) {
	\define('SA_MESSAGES', 1);
}

if (! \defined('SA_RECENT')) {
	\define('SA_RECENT', 2);
}

if (! \defined('SA_UNSEEN')) {
	\define('SA_UNSEEN', 4);
}

if (! \defined('SA_UIDNEXT')) {
	\define('SA_UIDNEXT', 8);
}

if (! \defined('SA_UIDVALIDITY')) {
	\define('SA_UIDVALIDITY', 16);
}

if (! \defined('SA_ALL')) {
	\define('SA_ALL', 31);
}

if (! \defined('LATT_NOINFERIORS')) {
	\define('LATT_NOINFERIORS', 1);
}

if (! \defined('LATT_NOSELECT')) {
	\define('LATT_NOSELECT', 2);
}

if (! \defined('LATT_MARKED')) {
	\define('LATT_MARKED', 4);
}

if (! \defined('LATT_UNMARKED')) {
	\define('LATT_UNMARKED', 8);
}

if (! \defined('LATT_REFERRAL')) {
	\define('LATT_REFERRAL', 16);
}

if (! \defined('LATT_HASCHILDREN')) {
	\define('LATT_HASCHILDREN', 32);
}

if (! \defined('LATT_HASNOCHILDREN')) {
	\define('LATT_HASNOCHILDREN', 64);
}

if (! \defined('SORTDATE')) {
	\define('SORTDATE', 0);
}

if (! \defined('SORTARRIVAL')) {
	\define('SORTARRIVAL', 1);
}

if (! \defined('SORTFROM')) {
	\define('SORTFROM', 2);
}

if (! \defined('SORTSUBJECT')) {
	\define('SORTSUBJECT', 3);
}

if (! \defined('SORTTO')) {
	\define('SORTTO', 4);
}

if (! \defined('SORTCC')) {
	\define('SORTCC', 5);
}

if (! \defined('SORTSIZE')) {
	\define('SORTSIZE', 6);
}

if (! \defined('TYPETEXT')) {
	\define('TYPETEXT', 0);
}

if (! \defined('TYPEMULTIPART')) {
	\define('TYPEMULTIPART', 1);
}

if (! \defined('TYPEMESSAGE')) {
	\define('TYPEMESSAGE', 2);
}

if (! \defined('TYPEAPPLICATION')) {
	\define('TYPEAPPLICATION', 3);
}

if (! \defined('TYPEAUDIO')) {
	\define('TYPEAUDIO', 4);
}

if (! \defined('TYPEIMAGE')) {
	\define('TYPEIMAGE', 5);
}

if (! \defined('TYPEVIDEO')) {
	\define('TYPEVIDEO', 6);
}

if (! \defined('TYPEMODEL')) {
	\define('TYPEMODEL', 7);
}

if (! \defined('TYPEOTHER')) {
	\define('TYPEOTHER', 8);
}

if (! \defined('ENC7BIT')) {
	\define('ENC7BIT', 0);
}

if (! \defined('ENC8BIT')) {
	\define('ENC8BIT', 1);
}

if (! \defined('ENCBINARY')) {
	\define('ENCBINARY', 2);
}

if (! \defined('ENCBASE64')) {
	\define('ENCBASE64', 3);
}

if (! \defined('ENCQUOTEDPRINTABLE')) {
	\define('ENCQUOTEDPRINTABLE', 4);
}

if (! \defined('ENCOTHER')) {
	\define('ENCOTHER', 5);
}

if (! \defined('IMAP_OPENTIMEOUT')) {
	\define('IMAP_OPENTIMEOUT', 1);
}

if (! \defined('IMAP_READTIMEOUT')) {
	\define('IMAP_READTIMEOUT', 2);
}

if (! \defined('IMAP_WRITETIMEOUT')) {
	\define('IMAP_WRITETIMEOUT', 3);
}

if (! \defined('IMAP_CLOSETIMEOUT')) {
	\define('IMAP_CLOSETIMEOUT', 4);
}

if (! \defined('IMAP_GC_ELT')) {
	\define('IMAP_GC_ELT', 1);
}

if (! \defined('IMAP_GC_ENV')) {
	\define('IMAP_GC_ENV', 2);
}

if (! \defined('IMAP_GC_TEXTS')) {
	\define('IMAP_GC_TEXTS', 4);
}

if (! \function_exists('imap_8bit'))
  {
  function imap_8bit(string $string) : string
		{
		return $string;
		}
  }

if (! \function_exists('imap_alerts'))
  {
  function imap_alerts() : array
		{
		return \PHPFUI\Imap2\Errors::alerts();
		}
  }

if (! \function_exists('imap_append'))
  {
  function imap_append(\IMAP\Connection $imap, string $folder, string $message, ?string $options = null, ?string $internal_date = null) : bool
		{
		return \PHPFUI\Imap2\Mailbox::append($imap, $folder, $message, $options, $internal_date);
		}
  }

if (! \function_exists('imap_base64'))
  {
  function imap_base64(string $string) : string|false
		{
		return \base64_decode($string, true);
		}
  }

if (! \function_exists('imap_binary'))
  {
  function imap_binary(string $string) : string
		{
		return \base64_encode($string);
		}
  }

if (! \function_exists('imap_body'))
  {
  function imap_body(\IMAP\Connection $imap, int $message_num, int $flags = 0) : string|false
		{
		return \PHPFUI\Imap2\Message::body($imap, "{$message_num}", $flags);
		}
  }

if (! \function_exists('imap_bodystruct'))
  {
  function imap_bodystruct(\IMAP\Connection $imap, int $message_num, string $section) : \stdClass|false
	{
	return \PHPFUI\Imap2\Message::bodyStruct($imap, "{$message_num}", $section);
	}
  }

if (! \function_exists('imap_check'))
  {
  function imap_check(\IMAP\Connection $imap) : \stdClass
		{
		return \PHPFUI\Imap2\Mailbox::check($imap);
		}
  }

if (! \function_exists('imap_clearflag_full'))
  {
  function imap_clearflag_full(\IMAP\Connection $imap, string $sequence, string $flag, int $options = 0) : true
		{
		\PHPFUI\Imap2\Message::clearFlagFull($imap, $sequence, $flag, $options);

		return true;
		}
  }

if (! \function_exists('imap_close'))
  {
  function imap_close(\IMAP\Connection $imap, int $flags = 0) : true
		{
		return \IMAP\Connection::close($imap, $flags);
		}
  }

if (! \function_exists('imap_create'))
  {
  function imap_create(\IMAP\Connection $imap, string $mailbox) : bool
		{
		return \PHPFUI\Imap2\Mailbox::createMailbox($imap, $mailbox);
		}
  }

if (! \function_exists('imap_createmailbox'))
  {
  function imap_createmailbox(\IMAP\Connection $imap, string $mailbox) : bool
		{
		return \PHPFUI\Imap2\Mailbox::createMailbox($imap, $mailbox);
		}
  }

if (! \function_exists('imap_delete'))
  {
  function imap_delete(\IMAP\Connection $imap, string $message_nums, int $flags = 0) : true
		{
		return \PHPFUI\Imap2\Message::delete($imap, $message_nums, $flags);
		}
  }

if (! \function_exists('imap_deletemailbox'))
  {
  function imap_deletemailbox(\IMAP\Connection $imap, string $mailbox) : bool
		{
		return \PHPFUI\Imap2\Mailbox::deleteMailbox($imap, $mailbox);
		}
  }

if (! \function_exists('imap_errors'))
  {
  function imap_errors() : array | false
		{
		return \PHPFUI\Imap2\Errors::errors();
		}
  }

if (! \function_exists('imap_expunge'))
  {
  function imap_expunge(\IMAP\Connection $imap) : true
		{
		return \PHPFUI\Imap2\Message::expunge($imap);
		}
  }

if (! \function_exists('imap_fetch_overview'))
  {
  function imap_fetch_overview(\IMAP\Connection $imap, string $sequence, int $flags = 0) : array|false
		{
		return \PHPFUI\Imap2\Message::fetchOverview($imap, $sequence, $flags);
		}
  }

if (! \function_exists('imap_fetchbody'))
  {
  function imap_fetchbody(\IMAP\Connection $imap, int $message_num, string $section, int $flags = 0) : string|false
		{
		return \PHPFUI\Imap2\Message::fetchBody($imap, "{$message_num}", $section, $flags);
		}
  }

if (! \function_exists('imap_fetchheader'))
  {
  function imap_fetchheader(\IMAP\Connection $imap, int $message_num, int $flags = 0) : string|false
		{
		return \PHPFUI\Imap2\Message::fetchHeader($imap, "{$message_num}", $flags);
		}
  }

if (! \function_exists('imap_fetchmime'))
  {
  function imap_fetchmime(\IMAP\Connection $imap, int $message_num, string $section, int $flags = 0) : string|false
		{
		return \PHPFUI\Imap2\Message::fetchMime($imap, $message_num, $section, $flags);
		}
  }

if (! \function_exists('imap_fetchstructure'))
  {
  function imap_fetchstructure(\IMAP\Connection $imap, int $message_num, int $flags = 0) : \stdClass|false
		{
		return \PHPFUI\Imap2\Message::fetchStructure($imap, "{$message_num}", $flags);
		}
  }

if (! \function_exists('imap_fetchtext'))
  {
  function imap_fetchtext(\IMAP\Connection $imap, int $message_num, int $flags = 0) : string|false
		{
		return \PHPFUI\Imap2\Message::body($imap, "{$message_num}", $flags);
		}
  }

if (! \function_exists('imap_gc'))
  {
  function imap_gc(\IMAP\Connection $imap, int $flags) : true
		{
		return \PHPFUI\Imap2\Message::clearCache($imap, $flags);
		}
  }

if (! \function_exists('imap_get_quota'))
  {
  function imap_get_quota(\IMAP\Connection $imap, string $quotaRoot) : array | false
		{
		if (! $imap->isConnected())
			{
			return false;
			}

		$client = $imap->getClient();

		return $client->getQuota();
		}
  }

if (! \function_exists('imap_get_quotaroot'))
  {
  function imap_get_quotaroot(\IMAP\Connection $imap, string $mailbox) : array | false
		{
		if (! $imap->isConnected())
			{
			return false;
			}

		$client = $imap->getClient();

		return $client->getQuota($mailbox);
		}
  }

if (! \function_exists('imap_getacl'))
  {
  function imap_getacl(\IMAP\Connection $imap, string $mailbox) : array | false
		{
		if (! $imap->isConnected())
			{
			return false;
			}

		$client = $imap->getClient();

		return $client->getACL($mailbox);
		}
  }

if (! \function_exists('imap_getmailboxes'))
  {
  function imap_getmailboxes(\IMAP\Connection $imap, string $reference, string $pattern) : array|false
		{
		return \PHPFUI\Imap2\Mailbox::getMailboxes($imap, $reference, $pattern);
		}
  }

if (! \function_exists('imap_getsubscribed'))
  {
  function imap_getsubscribed(\IMAP\Connection $imap, string $reference, string $pattern) : array|false
		{
		return \PHPFUI\Imap2\Mailbox::getMailboxes($imap, $reference, $pattern);
		}
  }

if (! \function_exists('imap_headerinfo'))
  {
  function imap_headerinfo(\IMAP\Connection $imap, int $message_num, int $from_length = 0, int $subject_length = 0) : \stdClass|false
		{
		return \PHPFUI\Imap2\Message::headerInfo($imap, "{$message_num}", $from_length, $subject_length);
		}
  }

if (! \function_exists('imap_headers'))
  {
  function imap_headers(\IMAP\Connection $imap) : array
		{
		return \PHPFUI\Imap2\Message::headers($imap);
		}
  }

if (! \function_exists('imap_last_error'))
  {
  function imap_last_error() : string | false
		{
		return \PHPFUI\Imap2\Errors::lastError();
		}
  }

if (! \function_exists('imap_list'))
  {
  function imap_list(\IMAP\Connection $imap, string $reference, string $pattern) : array
		{
		return \PHPFUI\Imap2\Mailbox::list($imap, $reference, $pattern);
		}
  }

if (! \function_exists('imap_listmailbox'))
  {
  function imap_listmailbox(\IMAP\Connection $imap, string $reference, string $pattern) : array
		{
		return \PHPFUI\Imap2\Mailbox::list($imap, $reference, $pattern);
		}
  }

if (! \function_exists('imap_listscan'))
  {
  function imap_listscan(\IMAP\Connection $imap, string $reference, string $pattern, string $content) : array
		{
		return \PHPFUI\Imap2\Mailbox::listScan($imap, $reference, $pattern);
		}
	}

//if (! \function_exists('imap_listsubscribed'))
//  {
//  function imap_listsubscribed(\IMAP\Connection $imap, string $reference, string $pattern) : array|false
//		{
//		return \PHPFUI\Imap2\Mailbox::listSubscribed($imap, $reference);
//		}
//  }
//
//if (! \function_exists('imap_lsub'))
//  {
//  function imap_lsub(\IMAP\Connection $imap, string $reference, string $pattern) : array|false
//		{
//		return \PHPFUI\Imap2\Mailbox::listSubscribed($imap, $reference);
//		}
//  }
//
//if (! \function_exists('imap_mail'))
//  {
//  function imap_mail(string $to, string $subject, string $message, ?string $additional_headers = null, ?string $cc = null, ?string $bcc = null, ?string $return_path = null) : bool
//		{
//		throw new \Exception('imap_mail is not implemented');
//		}
//  }
//
//if (! \function_exists('imap_mail_compose'))
//  {
//  function imap_mail_compose(array $envelope, array $bodies) : string|false
//		{
//		throw new \Exception('imap_mail_compose is not implemented');
//		}
//  }

if (! \function_exists('imap_mail_copy'))
  {
  function imap_mail_copy(\IMAP\Connection $imap, string $message_nums, string $mailbox, int $flags = 0) : bool
		{
		return \PHPFUI\Imap2\Mail::copy($imap, $message_nums, $mailbox, $flags);
		}
  }

if (! \function_exists('imap_mail_move'))
  {
  function imap_mail_move(\IMAP\Connection $imap, string $message_nums, string $mailbox, int $flags = 0) : bool
		{
		return \PHPFUI\Imap2\Mail::move($imap, $message_nums, $mailbox, $flags);
		}
  }

if (! \function_exists('imap_mailboxmsginfo'))
  {
  function imap_mailboxmsginfo(\IMAP\Connection $imap) : stdclass
		{
		return \PHPFUI\Imap2\Mailbox::mailboxMsgInfo($imap);
		}
  }

//if (! \function_exists('imap_mime_header_decode'))
//  {
//  function imap_mime_header_decode(string $string) : array
//		{
//		throw new \Exception(__FUNCTION__ . ' is not implemented.');
//		}
//  }

if (! \function_exists('imap_msgno'))
  {
  function imap_msgno(\IMAP\Connection $imap, int $message_uid) : int
		{
		return \PHPFUI\Imap2\Message::msgNo($imap, $message_uid);
		}
  }

if (! \function_exists('imap_mutf7_to_utf8'))
  {
  function imap_mutf7_to_utf8(string $string) : string
		{
		return $string;
		}
  }

if (! \function_exists('imap_num_msg'))
  {
  function imap_num_msg(\IMAP\Connection $imap) : int
		{
		return \PHPFUI\Imap2\Mailbox::numMsg($imap);
		}
  }

if (! \function_exists('imap_num_recent'))
  {
  function imap_num_recent(\IMAP\Connection $imap) : int
		{
		return \PHPFUI\Imap2\Mailbox::numRecent($imap);
		}
  }

if (! \function_exists('imap_open'))
  {
  function imap_open(string $mailbox, string $user, string $password, int $flags = 0, int $retries = 0, array $options = []) : \IMAP\Connection|false
		{
		return \IMAP\Connection::open($mailbox, $user, $password, $flags, $retries, $options);
		}
  }

if (! \function_exists('imap_ping'))
  {
  function imap_ping(\IMAP\Connection $imap) : bool
		{
		return \IMAP\Connection::ping($imap);
		}
  }

if (! \function_exists('imap_qprint'))
  {
  function imap_qprint(string $string) : string
		{
		return \quoted_printable_decode($string);
		}
  }

if (! \function_exists('imap_rename'))
  {
  function imap_rename(\IMAP\Connection $imap, string $from, string $to) : bool
		{
		return \PHPFUI\Imap2\Mailbox::renameMailbox($imap, $from, $to);
		}
  }

if (! \function_exists('imap_renamemailbox'))
  {
  function imap_renamemailbox(\IMAP\Connection $imap, string $from, string $to) : bool
		{
		return \PHPFUI\Imap2\Mailbox::renameMailbox($imap, $from, $to);
		}
  }

if (! \function_exists('imap_reopen'))
  {
  function imap_reopen(\IMAP\Connection $imap, string $mailbox, int $flags = 0, int $retries = 0) : bool
		{
		return \IMAP\Connection::reopen($imap, $mailbox, $flags, $retries);
		}
  }

if (! \function_exists('imap_rfc822_parse_adrlist'))
  {
  function imap_rfc822_parse_adrlist(string $string, ?string $default_hostname) : array
		{
		$message = \ZBateson\MailMimeParser\Message::from('To: ' . $string, false);

		return \PHPFUI\Imap2\Functions::getAddressObjectList(
			$message->getHeader(\ZBateson\MailMimeParser\Header\HeaderConsts::TO)->getAddresses(), // @phpstan-ignore-line
			$default_hostname
		);
		}
  }

if (! \function_exists('imap_rfc822_parse_headers'))
  {
  function imap_rfc822_parse_headers(string $headers, string $default_hostname = 'UNKNOWN') : \stdClass
		{
		$message = \ZBateson\MailMimeParser\Message::from($headers, false);

		$date = $message->getHeaderValue(\ZBateson\MailMimeParser\Header\HeaderConsts::DATE);
		$subject = $message->getHeaderValue(\ZBateson\MailMimeParser\Header\HeaderConsts::SUBJECT);

		$hasReplyTo = null !== $message->getHeader(\ZBateson\MailMimeParser\Header\HeaderConsts::REPLY_TO);
		$hasSender = null !== $message->getHeader(\ZBateson\MailMimeParser\Header\HeaderConsts::SENDER);

		return (object)[
			'date' => $date,
			'Date' => $date,
			'subject' => $subject,
			'Subject' => $subject,
			'message_id' => '<' . $message->getHeaderValue(\ZBateson\MailMimeParser\Header\HeaderConsts::MESSAGE_ID) . '>',
			'toaddress' => $message->getHeaderValue(\ZBateson\MailMimeParser\Header\HeaderConsts::TO),
			'to' => \PHPFUI\Imap2\Functions::getAddressObjectList($message->getHeader(\ZBateson\MailMimeParser\Header\HeaderConsts::TO)->getAddresses()), // @phpstan-ignore-line
			'fromaddress' => $message->getHeaderValue(\ZBateson\MailMimeParser\Header\HeaderConsts::FROM),
			'from' => \PHPFUI\Imap2\Functions::getAddressObjectList($message->getHeader(\ZBateson\MailMimeParser\Header\HeaderConsts::FROM)->getAddresses()), // @phpstan-ignore-line
			'reply_toaddress' => $message->getHeaderValue($hasReplyTo ? \ZBateson\MailMimeParser\Header\HeaderConsts::REPLY_TO : \ZBateson\MailMimeParser\Header\HeaderConsts::FROM),
			'reply_to' => \PHPFUI\Imap2\Functions::getAddressObjectList($message->getHeader($hasReplyTo ? \ZBateson\MailMimeParser\Header\HeaderConsts::REPLY_TO : \ZBateson\MailMimeParser\Header\HeaderConsts::FROM)->getAddresses()), // @phpstan-ignore-line
			'senderaddress' => $message->getHeaderValue($hasSender ? \ZBateson\MailMimeParser\Header\HeaderConsts::SENDER : \ZBateson\MailMimeParser\Header\HeaderConsts::FROM),
			'sender' => \PHPFUI\Imap2\Functions::getAddressObjectList($message->getHeader($hasSender ? \ZBateson\MailMimeParser\Header\HeaderConsts::SENDER : \ZBateson\MailMimeParser\Header\HeaderConsts::FROM)->getAddresses()), // @phpstan-ignore-line
		];
		}
  }

if (! \function_exists('imap_rfc822_write_address'))
  {
  function imap_rfc822_write_address(string $mailbox, string $hostname, string $personal) : string
		{
		$ret = $mailbox;

		if (! \filter_var($mailbox, FILTER_VALIDATE_EMAIL) && ! empty($hostname))
			{
			$ret .= '@' . $hostname;
			}

		if (! empty($personal))
			{
			$ret = \mb_encode_mimeheader($personal, 'UTF-8') . ' <' . $ret . '>';
			}

		return $ret;
		}
  }

if (! \function_exists('imap_savebody'))
  {
  function imap_savebody(\IMAP\Connection $imap, mixed $file, int $message_num, string $section = '', int $flags = 0) : bool
		{
		return \PHPFUI\Imap2\Message::saveBody($imap, $file, "{$message_num}", $section, $flags);
		}
  }

if (! \function_exists('imap_scan'))
  {
  function imap_scan(\IMAP\Connection $imap, string $reference, string $pattern, string $content) : array
		{
		return \PHPFUI\Imap2\Mailbox::listScan($imap, $reference, $pattern);
		}
  }

if (! \function_exists('imap_scanmailbox'))
  {
  function imap_scanmailbox(\IMAP\Connection $imap, string $reference, string $pattern, string $content) : array
		{
		return \PHPFUI\Imap2\Mailbox::listScan($imap, $reference, $pattern);
		}
  }

if (! \function_exists('imap_search'))
  {
  function imap_search(\IMAP\Connection $imap, string $criteria, int $flags = SE_FREE, string $charset = '') : array|false
		{
		return \PHPFUI\Imap2\Message::search($imap, $criteria, $flags, $charset);
		}
  }

//if (! \function_exists('imap_set_quota'))
//  {
//  function imap_set_quota(\IMAP\Connection $imap, string $quota_root) : array|false
//		{
//		throw new \Exception(__FUNCTION__ . ' is not implemented.');
//		}
//  }

if (! \function_exists('imap_setacl'))
  {
  function imap_setacl(\IMAP\Connection $imap, string $mailbox, string $userId, string $rights) : void
		{
		$client = $imap->getClient();
		$client->setACL($mailbox, $userId, $rights);
		}
  }

if (! \function_exists('imap_setflag_full'))
  {
  function imap_setflag_full(\IMAP\Connection $imap, string $sequence, string $flag, int $options = 0) : bool
		{
		return \PHPFUI\Imap2\Message::setFlagFull($imap, $sequence, $flag, $options);
		}
  }

if (! \function_exists('imap_sort'))
  {
  function imap_sort(\IMAP\Connection $imap, int $criteria, bool $reverse, int $flags = 0, ?string $search_criteria = null, ?string $charset = null) : array|false
		{
		return \PHPFUI\Imap2\Message::sort($imap, $criteria, $reverse, $flags, $search_criteria, $charset);
		}
  }

if (! \function_exists('imap_status'))
  {
  function imap_status(\IMAP\Connection $imap, string $mailbox, int $flags) : \stdClass|false
		{
		return \PHPFUI\Imap2\Mailbox::status($imap, $mailbox, $flags);
		}
  }

if (! \function_exists('imap_subscribe'))
  {
  function imap_subscribe(\IMAP\Connection $imap, string $mailbox) : bool
		{
		return \PHPFUI\Imap2\Mailbox::subscribe($imap, $mailbox);
		}
  }

if (! \function_exists('imap_thread'))
  {
  function imap_thread(\IMAP\Connection $imap, int $flags = SE_FREE) : array|false
		{
		return \PHPFUI\Imap2\Thread::thread($imap, $flags);
		}
  }

if (! \function_exists('imap_timeout'))
  {
  function imap_timeout(int $timeout_type, int $timeout = -1) : int|bool
		{
		return \PHPFUI\Imap2\Timeout::set($timeout_type, $timeout);
		}
  }

if (! \function_exists('imap_uid'))
  {
  function imap_uid(\IMAP\Connection $imap, int $message_num) : int
		{
		return \PHPFUI\Imap2\Message::uid($imap, $message_num);
		}
  }

if (! \function_exists('imap_undelete'))
  {
  function imap_undelete(\IMAP\Connection $imap, string $message_nums, int $flags = 0) : true
		{
		return \PHPFUI\Imap2\Message::undelete($imap, $message_nums, $flags);
		}
  }

if (! \function_exists('imap_unsubscribe'))
  {
  function imap_unsubscribe(\IMAP\Connection $imap, string $mailbox) : bool
		{
		return \PHPFUI\Imap2\Mailbox::unsubscribe($imap, $mailbox);
		}
  }

if (! \function_exists('imap_utf7_decode'))
  {
  function imap_utf7_decode(string $string) : string
		{
		return \mb_convert_encoding($string, 'UTF7-IMAP', 'ISO-8859-1');
		}
  }

if (! \function_exists('imap_utf7_encode'))
  {
  function imap_utf7_encode(string $string) : string
		{
		return \mb_convert_encoding($string, 'ISO-8859-1', 'UTF7-IMAP');
		}
  }

if (! \function_exists('imap_utf8'))
	{
  function imap_utf8(string $string) : string
		{
		return \iconv_mime_decode($string, 0, 'UTF-8');
		}
	}

if (! \function_exists('imap_utf8_to_mutf7'))
  {
  function imap_utf8_to_mutf7(string $string) : string
		{
		return \mb_convert_encoding($string, 'UTF8', 'UTF7-IMAP');
		}
	}
