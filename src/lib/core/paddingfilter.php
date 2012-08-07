<?php
/***********************************************
* File      :   paddingfilter.php
* Project   :   Z-Push
* Descr     :   Our own filter for stream padding with zero strings.
*
* Created   :   18.07.2012
*
* Copyright 2007 - 2012 Zarafa Deutschland GmbH
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

/* Define our filter class
 *
 * Usage: stream_filter_append($stream, 'padding.X');
 * where X is a number a stream will be padded to be
 * multiple of (e.g. padding.3 will pad the stream
 * to be multiple of 3 which is useful in base64
 * encoding).
 *
 * */
class padding_filter extends php_user_filter {
    private $padding = 4; // default padding

    /**
     * This method is called whenever data is read from or written to the attached stream
     *
     * @see php_user_filter::filter()
     *
     * @param resource      $in
     * @param resource      $out
     * @param int           $consumed
     * @param boolean       $closing
     *
     * @access public
     * @return int
     *
     */
    function filter($in, $out, &$consumed, $closing) {
        while ($bucket = stream_bucket_make_writeable($in)) {
            if ($this->padding != 0 && $bucket->datalen < 8192) {
                $bucket->data .= str_pad($bucket->data, $this->padding, 0x0);
            }
            $consumed += ($this->padding != 0 && $bucket->datalen < 8192) ? ($bucket->datalen + $this->padding) : $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }
        return PSFS_PASS_ON;
    }

    /**
     * Called when creating the filter
     *
     * @see php_user_filter::onCreate()
     *
     * @access public
     * @return boolean
     */
    function onCreate() {
        $delim = strrpos($this->filtername, '.');
        if ($delim !== false) {
            $padding = substr($this->filtername, $delim + 1);
            if (is_numeric($padding))
                $this->padding = $padding;
        }
        return true;
    }
}

stream_filter_register("padding.*", "padding_filter");
?>