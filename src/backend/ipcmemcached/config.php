<?php
/***********************************************
* File      :   ipcmemcached/config.php
* Project   :   Z-Push
* Descr     :   Configuration file for the
*               memcache IPC provider.
*
* Created   :   02.05.2016
*
* Copyright 2007-2016 Zarafa Deutschland GmbH
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

// Comma separated list of available memcache servers.
// Servers can be added as 'hostname:port,otherhost:port'
define('MEMCACHED_SERVERS','localhost:11211');

// Memcached down indicator
// In case memcached is not available, a lock file will be written to disk
define('MEMCACHED_DOWN_LOCK_FILE', '/tmp/z-push-memcache-down');
// indicates how long the lock file will be maintained (in seconds)
define('MEMCACHED_DOWN_LOCK_EXPIRATION', 30);

// Prefix to used for keys
define('MEMCACHED_PREFIX', 'z-push-ipc');

// Connection timeout in ms
define('MEMCACHED_TIMEOUT', 100);

// Mutex timeout (in seconds)
define('MEMCACHED_MUTEX_TIMEOUT', 5);

// Waiting time before re-trying to aquire mutex (in ms), must be higher than 0
define('MEMCACHED_BLOCK_WAIT', 10);
