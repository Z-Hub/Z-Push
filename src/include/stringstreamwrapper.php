<?php
/***********************************************
* File      :   stringstreamwrapper.php
* Project   :   Z-Push
* Descr     :   Wraps a string as a standard php stream
*               The used method names are predefined and can not be altered.
*
* Created   :   24.11.2011
*
* Copyright 2007 - 2011 Zarafa Deutschland GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation with the following additional
* term according to sec. 7:
*
* According to sec. 7 of the GNU Affero General Public License, version 3,
* the terms of the AGPL are supplemented with the following terms:
*
* "Zarafa" is a registered trademark of Zarafa B.V.
* "Z-Push" is a registered trademark of Zarafa Deutschland GmbH
* The licensing of the Program under the AGPL does not imply a trademark license.
* Therefore any rights, title and interest in our trademarks remain entirely with us.
*
* However, if you propagate an unmodified version of the Program you are
* allowed to use the term "Z-Push" to indicate that you distribute the Program.
* Furthermore you may use our trademarks where it is necessary to indicate
* the intended purpose of a product or service provided you use it in accordance
* with honest practices in industrial or commercial matters.
* If you want to propagate modified versions of the Program under the name "Z-Push",
* you may only do so if you have a written permission by Zarafa Deutschland GmbH
* (to acquire a permission please contact Zarafa at trademark@zarafa.com).
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* Consult LICENSE file for details
************************************************/

class StringStreamWrapper {
    const PROTOCOL = "stringstream";

    private $stringstream;
    private $position;
    private $stringlength;

    /**
     * Opens the stream
     * The string to be streamed is passed over the context
     *
     * @param string    $path           Specifies the URL that was passed to the original function
     * @param string    $mode           The mode used to open the file, as detailed for fopen()
     * @param int       $options        Holds additional flags set by the streams API
     * @param string    $opened_path    If the path is opened successfully, and STREAM_USE_PATH is set in options,
     *                                  opened_path should be set to the full path of the file/resource that was actually opened.
     *
     * @access public
     * @return boolean
     */
    public function stream_open($path, $mode, $options, &$opened_path) {
        $contextOptions = stream_context_get_options($this->context);
        if (!isset($contextOptions[self::PROTOCOL]['string']))
            return false;

        $this->position = 0;

        // this is our stream!
        $this->stringstream = $contextOptions[self::PROTOCOL]['string'];

        $this->stringlength = strlen($this->stringstream);
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("StringStreamWrapper::stream_open(): initialized stream length: %d", $this->stringlength));

        return true;
    }

    /**
     * Reads from stream
     *
     * @param int $len      amount of bytes to be read
     *
     * @access public
     * @return string
     */
    public function stream_read($len) {
        $data = substr($this->stringstream, $this->position, $len);
        $this->position += strlen($data);
        return $data;
    }

    /**
     * Returns the current position on stream
     *
     * @access public
     * @return int
     */
    public function stream_tell() {
        return $this->position;
    }

   /**
     * Indicates if 'end of file' is reached
     *
     * @access public
     * @return boolean
     */
    public function stream_eof() {
        return ($this->position >= $this->stringlength);
    }

    /**
    * Retrieves information about a stream
    *
    * @access public
    * @return array
    */
    public function stream_stat() {
        return array(
            7               => $this->stringlength,
            'size'          => $this->stringlength,
        );
    }

   /**
     * Instantiates a StringStreamWrapper
     *
     * @param string    $string     The string to be wrapped
     *
     * @access public
     * @return StringStreamWrapper
     */
     static public function Open($string) {
        $context = stream_context_create(array(self::PROTOCOL => array('string' => &$string)));
        return fopen(self::PROTOCOL . "://",'r', false, $context);
    }
}

stream_wrapper_register(StringStreamWrapper::PROTOCOL, "StringStreamWrapper")

?>