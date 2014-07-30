<?php
/***********************************************
* File      :   config.php
* Project   :   Z-Push
* Descr     :   Autodiscover configuration file
*
* Created   :   30.07.2014
*
* Copyright 2007 - 2014 Zarafa Deutschland GmbH
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

/**********************************************************************************
 *  Default settings
 */
    // Defines the base path on the server
    define('BASE_PATH', dirname($_SERVER['SCRIPT_FILENAME']). '/');

    // The Z-Push server location for the autodiscover response
    define('SERVERURL', 'https://localhost/Microsoft-Server-ActiveSync');

    /*
     * Whether to use the complete email address as a login name
     * (e.g. user@company.com) or the username only (user).
     * Possible values:
     * false - use the username only (default).
     * true - use the complete email address.
     */
    define('USE_FULLEMAIL_FOR_LOGIN', false);

/**********************************************************************************
 *  Logging settings
 *  Possible LOGLEVEL and LOGUSERLEVEL values are:
 *  LOGLEVEL_OFF            - no logging
 *  LOGLEVEL_FATAL          - log only critical errors
 *  LOGLEVEL_ERROR          - logs events which might require corrective actions
 *  LOGLEVEL_WARN           - might lead to an error or require corrective actions in the future
 *  LOGLEVEL_INFO           - usually completed actions
 *  LOGLEVEL_DEBUG          - debugging information, typically only meaningful to developers
 *  LOGLEVEL_WBXML          - also prints the WBXML sent to/from the device
 *  LOGLEVEL_DEVICEID       - also prints the device id for every log entry
 *  LOGLEVEL_WBXMLSTACK     - also prints the contents of WBXML stack
 *
 *  The verbosity increases from top to bottom. More verbose levels include less verbose
 *  ones, e.g. setting to LOGLEVEL_DEBUG will also output LOGLEVEL_FATAL, LOGLEVEL_ERROR,
 *  LOGLEVEL_WARN and LOGLEVEL_INFO level entries.
 */
    define('LOGFILEDIR', '/var/log/z-push/');
    define('LOGFILE', LOGFILEDIR . 'autodiscover.log');
    define('LOGERRORFILE', LOGFILEDIR . 'autodiscover-error.log');
    define('LOGLEVEL', LOGLEVEL_INFO);
    define('LOGAUTHFAIL', false);

/**********************************************************************************
 *  Backend settings
 */
    // the backend data provider
    define('BACKEND_PROVIDER', '');
?>