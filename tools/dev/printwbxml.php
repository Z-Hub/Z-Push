<?php
/***********************************************
 * File      :   printwbxml.php
 * Project   :   Z-Push
 * Descr     :   decodes and prints wbxml as base64 to stdout
 *
 * Created   :   18.05.2015
 *
 * Copyright 2015-2016 Zarafa Deutschland GmbH
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
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

if (count($argv) < 2) {
    die("\tUsage: printwbmxl.php WBXML-INPUT-HERE or PATH-TO-FILE\n\n");
}
$wbxml64 = is_file($argv[1]) ? realpath($argv[1]) : $argv[1];
chdir(__DIR__);

// include the stuff we need
include_once('../../src/lib/utils/stringstreamwrapper.php');
include_once('../../src/lib/wbxml/wbxmldefs.php');
include_once('../../src/lib/wbxml/wbxmldecoder.php');
include_once('../../src/lib/wbxml/wbxmlencoder.php');

// minimal definitions & log to stdout overwrite
define('WBXML_DEBUG', true);
define("LOGLEVEL_WBXML", "wbxml");
define("LOGLEVEL_DEBUG", "debug");
class ZLog {
    static public function Write($level, $msg, $truncate = false) {
        // we only care about the wbxml
        if ($level == "wbxml") {
            if (substr($msg,0,1) == "I") {
                echo substr($msg,1) . "\n";
            }
            else {
                echo $msg . "\n";
            }
        }
    }

    static public function IsWbxmlDebugEnabled() {
        return true;
    }
}

// setup
$wxbml = is_file($wbxml64) ? fopen($wbxml64, 'r+') : StringStreamWrapper::Open($wbxml64);
$base64filter = stream_filter_append($wxbml, 'convert.base64-decode');
$decoder = new WBXMLDecoder($wxbml);
if (! $decoder->IsWBXML()) {
    die("input is not WBXML as base64\n\n");
}

echo "\n";
// read everything and log it
$decoder->readRemainingData();
echo "\n";
