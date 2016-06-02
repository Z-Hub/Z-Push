<?php
/***********************************************
* File      :   version.php
* Project   :   Z-Push
* Descr     :   version number
*
* Created   :   18.04.2008
*
* Copyright 2007 - 2013, 2015 Zarafa Deutschland GmbH
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

if (!defined("ZPUSH_VERSION")) {
    $path = escapeshellarg(dirname(realpath($_SERVER['SCRIPT_FILENAME'])));
    $branch = trim(exec("hash git && cd $path >/dev/null 2>&1 && git branch --no-color  2>/dev/null | sed -e '/^[^*]/d' -e \"s/* \(.*\)/\\1/\""));
    $version = exec("hash git && cd $path >/dev/null 2>&1 && git describe  --always &2>/dev/null");
    if ($branch && $version) {
        define("ZPUSH_VERSION", $branch .'-'. $version);
    }
    else {
        define("ZPUSH_VERSION", "GIT");
    }
}