<?php
/***********************************************
* File      :   config.php
* Project   :   Z-Push
* Descr     :   CalDAV backend configuration file
*
* Created   :   27.11.2012
*
* Copyright 2012 - 2014 Jean-Louis Dupond
*
* Jean-Louis Dupond released this code as AGPLv3 here: https://github.com/dupondje/PHP-Push-2/issues/93
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

// ************************
//  BackendCalDAV settings
// ************************

// Server protocol: http or https
define('CALDAV_PROTOCOL', 'https');

// Server name
define('CALDAV_SERVER', 'caldavserver.domain.com');

// Server port
define('CALDAV_PORT', '443');

// Path
define('CALDAV_PATH', '/caldav.php/%u/');

// Default CalDAV folder (calendar folder/principal). This will be marked as the default calendar in the mobile
define('CALDAV_PERSONAL', 'PRINCIPAL');

// If the CalDAV server supports the sync-collection operation
// DAViCal, SOGo and SabreDav support it
// SabreDav version must be at least 1.9.0, otherwise set this to false
// Setting this to false will work with most servers, but it will be slower
define('CALDAV_SUPPORTS_SYNC', false);


// Maximum period to sync.
// Some servers don't support more than 10 years so you will need to change this
define('CALDAV_MAX_SYNC_PERIOD', 2147483647);