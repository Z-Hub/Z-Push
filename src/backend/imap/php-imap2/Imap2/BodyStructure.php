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

class BodyStructure
	{
	public const int ENC7BIT = 0;              // 7 bit SMTP semantic data

	public const int ENC8BIT = 1;              // 8 bit SMTP semantic data

	public const int ENCBASE64 = 3;            // base-64 encoded data

	public const int ENCBINARY = 2;            // 8 bit binary data

	public const int ENCOTHER = 5;             // unknown

	public const int ENCQUOTEDPRINTABLE = 4;   // human-readable 8-as-7 bit data

	public const int TYPEAPPLICATION = 3;      // application data

	public const int TYPEAUDIO = 4;            // audio

	public const int TYPEIMAGE = 5;            // static image

	public const int TYPEMESSAGE = 2;         // encapsulated message

	public const int TYPEMODEL = 7;            // model

	public const int TYPEMULTIPART = 1;        // multiple part

	public const int TYPEOTHER = 8;            // unknown

	public const int TYPETEXT = 0;             // unformatted text

	public const int TYPEVIDEO = 6;            // video

	protected static array $body_encodings = [
		self::ENC7BIT => '7BIT',
		self::ENC8BIT => '8BIT',
		self::ENCBINARY => 'BINARY',
		self::ENCBASE64 => 'BASE64',
		self::ENCQUOTEDPRINTABLE => 'QUOTED-PRINTABLE',
		self::ENCOTHER => 'X-UNKNOWN'
	];

	protected static array $body_types = [
		self::TYPETEXT => 'TEXT',
		self::TYPEMULTIPART => 'MULTIPART',
		self::TYPEMESSAGE => 'MESSAGE',
		self::TYPEAPPLICATION => 'APPLICATION',
		self::TYPEAUDIO => 'AUDIO',
		self::TYPEIMAGE => 'IMAGE',
		self::TYPEVIDEO => 'VIDEO',
		self::TYPEMODEL => 'MODEL',
		self::TYPEOTHER => 'X-UNKNOWN'
	];

	public static function fromMessage($message) : \stdClass | false
		{
		return self::extractBodyStructure($message->bodystructure);
		}

	protected static function extractBodyStructure($structure) : \stdClass | false
		{
		// if NIL
		if ( null === $structure )
			{
			return false;
			}

		// body structure list
		$length = \count($structure);

		if ( ! $length)
			{
			return false;
			}

		$body = (object)[
			'type' => self::TYPEOTHER,
			'encoding' => self::ENC7BIT,
			'ifsubtype' => 0,
			'subtype' => null,
			'ifdescription' => 0,
			'description' => null,
			'ifid' => 0,
			'id' => null,
			'lines' => null,
			'bytes' => null,
			'ifdisposition' => 0,
			'disposition' => null,
			'ifdparameters' => 0,
			'dparameters' => null,
			'ifparameters' => 0,
			'parameters' => null
		];

		// multipart body?
		if ( \is_array($structure[0]) )
			{
			// yes, set its type
			$body->type = self::TYPEMULTIPART;

			$index = 0;
			$parts = [];

			// for each body part
			while( \is_array($structure[$index]) )
				{
				$parts[] = self::extractBodyStructure( $structure[$index++] );
				}

			// parse subtype
			if ( $body->subtype = \strtoupper($structure[$index++]) )
				{
				$body->ifsubtype = 1;
				}

			// multipart parameters
			if ( $index < $length )
				{
				if ( \count( $body->parameters = self::extractParameters($structure[$index++], []) ) )
					{
					$body->ifparameters = 1;
					}
				else
					{
					$body->parameters = (object)[];
					}
				}

			// disposition
			if ( $index < $length )
				{
				if ( \is_array($disposition = $structure[$index++]) )
					{
					$body->disposition = $disposition[0];
					$body->ifdisposition = 1;

					if ( \count( $body->dparameters = self::extractParameters($disposition[1], []) ))
						{
						$body->ifdparameters = 1;
						}
					else
						{
						$body->dparameters = null;
						}
					}
				}

			// location
			if ( $index < $length )
				{
				++$index;
				}

			while( $index < $length )
				{
				//parse_extension
				++$index;
				}

			$body->parts = $parts;

			}
		// not multipart, parse type name
		else
			{
			// assume unknown type
			$body->type = self::TYPEOTHER;
			// and unknown encoding
			$body->encoding = self::ENCOTHER;

			// parse type
			if ( ($type = \array_search(\strtoupper($structure[0]), self::$body_types)) !== false )
				{
				$body->type = $type;
				}

			// encoding always gets uppercase form
			if ( ($encoding = \array_search(\strtoupper($structure[5]), self::$body_encodings)) !== false )
				{
				$body->encoding = $encoding;
				}

			// parse subtype
			if ( $body->subtype = \strtoupper($structure[1]) )
				{
				$body->ifsubtype = 1;
				}

			$body->ifdescription = 0;

			if ( ! empty($structure[4]) )
				{
				$body->ifdescription = 1;
				$body->description = $structure[4];
				}

			$body->ifid = 0;

			if ( ! empty($structure[3]) )
				{
				$body->ifid = 1;
				$body->id = $structure[3];
				}

			// parse size of contents in bytes
			$body->bytes = (int)($structure[6]);

			$index = 7;

			// possible extra stuff
			switch ( $body->type )
				{
				// message envelope and body
				case self::TYPEMESSAGE:
					// non MESSAGE/RFC822 is basic type
					if ( \strcmp($body->subtype, 'RFC822') ) break;

					// make certain server sends an envelope
					++$index;

					$body->parts[] = self::extractBodyStructure( $structure[$index++] );

					// drop into text case

				// size in lines
				// Intentionally fall through
				case self::TYPETEXT:
					$body->lines = (int)($structure[$index++]);

					break;

				// otherwise nothing special
				default:
					break;

				}

			// extension data - md5
			if ( $index < $length )
				{
				++$index;
				}

			// disposition
			if ( $index < $length )
				{
				if ( \is_array($disposition = $structure[$index++]) )
					{
					$body->disposition = $disposition[0];
					$body->ifdisposition = 1;

					if ( \count( $body->dparameters = self::extractParameters($disposition[1], []) ) )
						{
						$body->ifdparameters = 1;
						}
					else
						{
						$body->dparameters = null;
						}
					}
				}

			// language
			if ( $index < $length )
				{
				//parse_language
				++$index;
				}

			// location
			if ( $index < $length )
				{
				++$index;
				}

			while( $index < $length )
				{
				//parse_extension
				++$index;
				}

			if ( \count( $body->parameters = self::extractParameters($structure[2], []) ) )
				{
				$body->ifparameters = 1;
				}
			else
				{
				$body->parameters = (object)[];
				}
			}

		if ( null === $body->description ) $body->description = null;

		if ( null === $body->id ) $body->id = null;

		if ( null === $body->disposition ) $body->disposition = null;

		if ( null === $body->dparameters ) $body->dparameters = null;

		if ( null === $body->parameters ) $body->parameters = null;

		return $body;
		}

	protected static function extractParameters(array $attributes, array $parameters) : array
		{
		if (empty($attributes))
			{
			return [];
			}

		$attribute = null;

		foreach ($attributes as $value)
			{
			if (empty($attribute))
				{
				$attribute = [
					'attribute' => $value,
					'value' => null,
				];
				}
			else
				{
				$attribute['value'] = $value;
				$parameters[] = (object)$attribute;
				$attribute = null;
				}
			}

		return $parameters;
		}
	}
