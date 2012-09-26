<?php
/***********************************************
* File      :   compat.php
* Project   :   Z-Push
* Descr     :   Help function for files
*
* Created   :   01.10.2007
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

if (!function_exists("quoted_printable_encode")) {
    /**
     * Process a string to fit the requirements of RFC2045 section 6.7. Note that
     * this works, but replaces more characters than the minimum set. For readability
     * the spaces and CRLF pairs aren't encoded though.
     *
     * @param string    $string     string to be encoded
     *
     * @see http://www.php.net/manual/en/function.quoted-printable-decode.php#89417
     */
    function quoted_printable_encode($string) {
        return preg_replace('/[^\r\n]{73}[^=\r\n]{2}/', "$0=\n", str_replace(array('%20', '%0D%0A', '%'), array(' ', "\r\n", '='), rawurlencode($string)));
    }
}

if (!function_exists("apache_request_headers")) {
    /**
      * When using other webservers or using php as cgi in apache
      * the function apache_request_headers() is not available.
      * This function parses the environment variables to extract
      * the necessary headers for Z-Push
      */
    function apache_request_headers() {
        $headers = array();
        foreach ($_SERVER as $key => $value)
            if (substr($key, 0, 5) == 'HTTP_')
                $headers[strtr(substr($key, 5), '_', '-')] = $value;

        return $headers;
    }
}

if (!function_exists("hex2bin")) {
    /**
     * Complementary function to bin2hex() which converts a hex entryid to a binary entryid.
     * Since PHP 5.4 an internal hex2bin() implementation is available.
     *
     * @param string    $data   the hexadecimal string
     *
     * @returns string
     */
    function hex2bin($data) {
        return pack("H*", $data);
    }
}
?>