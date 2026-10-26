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
 * |   THREAD response handler                                             |
 * +-----------------------------------------------------------------------+
 * | Author: Thomas Bruederli <roundcube@gmail.com>                        |
 * | Author: Aleksander Machniak <alec@alec.pl>                            |
 * +-----------------------------------------------------------------------+
 */

namespace PHPFUI\Imap2\Roundcube;

/**
 * Class for accessing IMAP's THREAD result
 *
 * @package    Framework
 * @subpackage Storage
 */
class ResultThread
{
	public const SEPARATOR_ELEMENT = ' ';

	public const SEPARATOR_ITEM = '~';

	public const SEPARATOR_LEVEL = ':';

	protected array $meta = [];

	protected string $order = 'ASC';

	protected string $raw_data = '';

	/**
	 * Object constructor.
	 */
	public function __construct(protected ?string $mailbox = null, ?string $data = null)
		{
		$this->init($data);
		}

	/**
	 * Returns number of elements (threads) in the result
	 *
	 * @return int Number of elements
	 */
	public function count() : int
		{
		if (isset($this->meta['count']))
			{
			return (int)$this->meta['count'];
			}

		if (empty($this->raw_data))
			{
			$this->meta['count'] = 0;
			}
		else
			{
			$this->meta['count'] = 1 + \substr_count($this->raw_data, self::SEPARATOR_ELEMENT);
			}

		if (! $this->meta['count'])
			{
			$this->meta['messages'] = 0;
			}

		return (int)$this->meta['count'];
		}

	/**
	 * Returns number of all messages in the result
	 *
	 * @return int Number of elements
	 */
	public function count_messages() : int
		{
		if (null !== $this->meta['messages'])
			{
			return $this->meta['messages'];
			}

		if (empty($this->raw_data))
			{
			$this->meta['messages'] = 0;
			}
		else
			{
			$this->meta['messages'] = 1
				+ \substr_count($this->raw_data, self::SEPARATOR_ELEMENT)
				+ \substr_count($this->raw_data, self::SEPARATOR_ITEM);
			}

		if (0 == $this->meta['messages'] || 1 == $this->meta['messages'])
			{
			$this->meta['count'] = $this->meta['messages'];
			}

		return $this->meta['messages'];
		}

	/**
	 * Filters data set. Removes threads not listed in $roots list.
	 *
	 * @param array $roots List of IDs of thread roots.
	 */
	public function filter(array $roots) : void
	{
		$datalen = \strlen($this->raw_data);
		$roots = \array_flip($roots);
		$result = '';
		$start = 0;

		$this->meta = [];
		$this->meta['count'] = 0;

		while (($pos = @\strpos($this->raw_data, self::SEPARATOR_ELEMENT, $start))
			|| ($start < $datalen && ($pos = $datalen))
		) {
			$len = $pos - $start;
			$elem = \substr($this->raw_data, $start, $len);
			$start = $pos + 1;

			// extract root message ID
			if ($npos = \strpos($elem, self::SEPARATOR_ITEM)) {
				$root = (int)\substr($elem, 0, $npos);
			}
			else {
				$root = $elem;
			}

			if (isset($roots[$root])) {
				$this->meta['count']++;
				$result .= self::SEPARATOR_ELEMENT . $elem;
			}
		}

		$this->raw_data = \ltrim($result, self::SEPARATOR_ELEMENT);
	}

	/**
	 * Return IDs of all messages in the result. Threaded data will be flattened.
	 *
	 * @return array List of message identifiers
	 */
	public function get() : array
	{
		if (empty($this->raw_data)) {
			return [];
		}

		$regexp = '/(' . \preg_quote(self::SEPARATOR_ELEMENT, '/')
			. '|' . \preg_quote(self::SEPARATOR_ITEM, '/') . '[0-9]+' . \preg_quote(self::SEPARATOR_LEVEL, '/')
			. ')/';

		return \preg_split($regexp, $this->raw_data);
	}

	/**
	 * Returns response parameters e.g. MAILBOX, ORDER
	 *
	 * @param string $param Parameter name
	 *
	 * @return array|string Response parameters or parameter value
	 */
	public function get_parameters(?string $param = null)
	{
		$params = [];
		$params['MAILBOX'] = $this->mailbox;
		$params['ORDER'] = $this->order;

		if ($param) {
			return $params[$param];
		}

		return $params;
	}

	/**
	 * Returns data as tree
	 *
	 * @return array Data tree
	 */
	public function get_tree() : array
	{
		$datalen = \strlen($this->raw_data);
		$result = [];
		$start = 0;

		while (($pos = @\strpos($this->raw_data, self::SEPARATOR_ELEMENT, $start))
			|| ($start < $datalen && ($pos = $datalen))
		) {
			$len = $pos - $start;
			$elem = \substr($this->raw_data, $start, $len);
			$items = \explode(self::SEPARATOR_ITEM, $elem);
			$result[\array_shift($items)] = $this->build_thread($items);
			$start = $pos + 1;
		}

		return $result;
	}

	/**
	 * Initializes object with IMAP command response
	 *
	 * @param string $data IMAP response string
	 */
	public function init(?string $data = null) : void
	{
		$this->meta = [];

		$data = \explode('*', (string)$data);

		// ...skip unilateral untagged server responses
		for ($i = 0, $len = \count($data); $i < $len; $i++) {
			if (\preg_match('/^ THREAD/i', $data[$i])) {
				// valid response, initialize raw_data for is_error()
				$this->raw_data = '';
				$data[$i] = \substr($data[$i], 7);

				break;
			}

			unset($data[$i]);
		}

		if (empty($data)) {
			return;
		}

		$data = \array_shift($data);
		$data = \trim($data);
		$data = \preg_replace('/[\r\n]/', '', $data);
		$data = \preg_replace('/\s+/', ' ', $data);

		$this->raw_data = $this->parse_thread($data);
	}

	/**
	 * Checks the result from IMAP command
	 *
	 * @return bool True if the result is an error, False otherwise
	 */
	public function is_error() : bool
	{
		return '' === $this->raw_data;
	}

	/**
	 * Returns maximum message identifier in the result
	 *
	 * @return int Maximum message identifier
	 */
	public function max()
	{
		if (! isset($this->meta['max'])) {
			$this->meta['max'] = (int)@\max($this->get());
		}

		return $this->meta['max'];
	}

	/**
	 * Returns minimum message identifier in the result
	 *
	 * @return int Minimum message identifier
	 */
	public function min()
	{
		if (! isset($this->meta['min'])) {
			$this->meta['min'] = (int)@\min($this->get());
		}

		return $this->meta['min'];
	}

	/**
	 * THREAD=REFS sorting implementation (based on provided index)
	 *
	 * @param ResultIndex $index  Sorted message identifiers
	 */
	public function sort($index) : void
	{
		$this->order = $index->get_parameters('ORDER');

		if (empty($this->raw_data)) {
			return;
		}

		// when sorting search result it's good to make the index smaller
		if ($index->count() != $this->count_messages()) {
			$index->filter($this->get());
		}

		$result = \array_fill_keys($index->get(), null);
		$datalen = \strlen($this->raw_data);
		$start = 0;

		// Here we're parsing raw_data twice, we want only one big array
		// in memory at a time

		// Assign roots
		while (($pos = @\strpos($this->raw_data, self::SEPARATOR_ELEMENT, $start))
			|| ($start < $datalen && ($pos = $datalen))
		) {
			$len = $pos - $start;
			$elem = \substr($this->raw_data, $start, $len);
			$start = $pos + 1;

			$items = \explode(self::SEPARATOR_ITEM, $elem);
			$root = (int)\array_shift($items);

			if ($root) {
				$result[$root] = $root;

				foreach ($items as $item) {
					[$lv, $id] = \explode(self::SEPARATOR_LEVEL, $item);
					$result[$id] = $root;
				}
			}
		}

		// get only unique roots
		$result = \array_filter($result); // make sure there are no nulls
		$result = \array_unique($result);

		// Re-sort raw data
		$result = \array_fill_keys($result, null);
		$start = 0;

		while (($pos = @\strpos($this->raw_data, self::SEPARATOR_ELEMENT, $start))
			|| ($start < $datalen && ($pos = $datalen))
		) {
			$len = $pos - $start;
			$elem = \substr($this->raw_data, $start, $len);
			$start = $pos + 1;

			$npos = \strpos($elem, self::SEPARATOR_ITEM);
			$root = (int)($npos ? \substr($elem, 0, $npos) : $elem);

			$result[$root] = $elem;
		}

		$this->raw_data = \implode(self::SEPARATOR_ELEMENT, $result);
	}

	/**
	 * Converts part of the raw thread into an array
	 */
	protected function build_thread($items, $level = 1, &$pos = 0)
	{
		$result = [];

		for ($len = \count($items); $pos < $len; $pos++) {
			[$lv, $id] = \explode(self::SEPARATOR_LEVEL, $items[$pos]);

			if ($level == $lv) {
				$pos++;
				$result[$id] = $this->build_thread($items, $level + 1, $pos);
			}
			else {
				$pos--;

				break;
			}
		}

		return $result;
	}

	/**
	 * Creates 'depth' and 'children' arrays from stored thread 'tree' data.
	 */
	protected function build_thread_data($data, &$depth, &$children, $level = 0) : void
	{
		foreach ((array)$data as $key => $val) {
			$empty = empty($val) || ! \is_array($val);
			$children[$key] = ! $empty;
			$depth[$key] = $level;

			if (! $empty) {
				$this->build_thread_data($val, $depth, $children, $level + 1);
			}
		}
	}

	/**
	 * IMAP THREAD response parser
	 */
	protected function parse_thread(string $str, int $begin = 0, int $end = 0, int $depth = 0) : string
	{
		// Don't be tempted to change $str to pass by reference to speed this up - it will slow it down by about
		// 7 times instead :-) See comments on http://uk2.php.net/references and this article:
		// http://derickrethans.nl/files/phparch-php-variables-article.pdf
		$node = '';

		if (! $end) {
			$end = \strlen($str);
		}

		// Let's try to store data in max. compacted stracture as a string,
		// arrays handling is much more expensive
		// For the following structure: THREAD (2)(3 6 (4 23)(44 7 96))
		// -- 2
		// -- 3
		//     \-- 6
		//         |-- 4
		//         |    \-- 23
		//         |
		//         \-- 44
		//               \-- 7
		//                    \-- 96
		//
		// The output will be: 2,3^1:6^2:4^3:23^2:44^3:7^4:96

		if ('(' != $str[$begin]) {
			// find next bracket
			$stop = $begin + \strcspn($str, '()', $begin, $end - $begin);
			$messages = \explode(' ', \trim(\substr($str, $begin, $stop - $begin)));

			foreach ($messages as $msg) {
				if ($msg) {
					$node .= ($depth ? self::SEPARATOR_ITEM . $depth . self::SEPARATOR_LEVEL : '') . $msg;
					$this->meta['messages']++;
					$depth++;
				}
			}

			if ($stop < $end) {
				$node .= $this->parse_thread($str, $stop, $end, $depth);
			}
		}
		else {
			$off = $begin;

			while ($off < $end) {
				$start = $off;
				$off++;
				$n = 1;

				while ($n > 0) {
					$p = \strpos($str, ')', $off);

					if (false === $p) {
						// error, wrong structure, mismatched brackets in IMAP THREAD response
						// @TODO: write error to the log or maybe set $this->raw_data = null;
						return $node;
					}
					$p1 = \strpos($str, '(', $off);

					if (false !== $p1 && $p1 < $p) {
						$off = $p1 + 1;
						$n++;
					}
					else {
						$off = $p + 1;
						$n--;
					}
				}

				$thread = $this->parse_thread($str, $start + 1, $off - 1, $depth);

				if ($thread) {
					if (! $depth) {
						if ($node) {
							$node .= self::SEPARATOR_ELEMENT;
						}
					}
					$node .= $thread;
				}
			}
		}

		return $node;
	}
}
