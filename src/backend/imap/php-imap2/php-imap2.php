<?php

/***********************************************
* File      :   php-imap2.php
* Project   :   Z-Push
* Descr     :   php-imap2 wrapper for using within the IMAP backend
*
* Created   :   2026
*
* Copyright 2014 - 2016 Zarafa Deutschland GmbH
* Copyright 2026 Mat Wilson
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

/* check if imap function exists, and if php version is 8.3 or greater, then use the php-imap2 wrapper */
if (!function_exists('imap_open') && version_compare(PHP_VERSION, '8.3.0', '>=')) {
    require_once('IMAP/Connection.php');
    require_once('Imap2/Roundcube/Charset.php');
    require_once('Imap2/Roundcube/ImapClient.php');
    require_once('Imap2/Roundcube/MessageHeader.php');
    require_once('Imap2/Roundcube/Mime.php');
    require_once('Imap2/Roundcube/ResultIndex.php');
    require_once('Imap2/Roundcube/ResultThread.php');
    require_once('Imap2/Roundcube/Utils.php');
    require_once('Imap2/BodyStructure.php');
    require_once('Imap2/Errors.php');
    require_once('Imap2/Functions.php');
    require_once('Imap2/HeaderInfo.php');
    require_once('Imap2/IMAPStubs.php');
    require_once('Imap2/Mail.php');
    require_once('Imap2/Mailbox.php');
    require_once('Imap2/Message.php');
    require_once('Imap2/Thread.php');
    require_once('Imap2/Timeout.php');
}


