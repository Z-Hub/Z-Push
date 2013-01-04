#!/usr/bin/php
<?php
/***********************************************
* File      :   listfolders.php
* Project   :   Z-Push
* Descr     :   This is a small command line
*               tool to list folders of a user
*               store or public folder available
*               for synchronization.
*
* Created   :   06.05.2011
*
* Copyright 2007 - 2013 Zarafa Deutschland GmbH
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

define("PHP_MAPI_PATH", "/usr/share/php/mapi/");
define('MAPI_SERVER', 'file:///var/run/zarafa');

$supported_classes = array (
    "IPF.Note"          => "SYNC_FOLDER_TYPE_USER_MAIL",
    "IPF.Task"          => "SYNC_FOLDER_TYPE_USER_TASK",
    "IPF.Appointment"   => "SYNC_FOLDER_TYPE_USER_APPOINTMENT",
    "IPF.Contact"       => "SYNC_FOLDER_TYPE_USER_CONTACT",
    "IPF.StickyNote"    => "SYNC_FOLDER_TYPE_USER_NOTE"
);

main();

function main() {
    listfolders_configure();
    listfolders_handle();
}

function listfolders_configure() {

    if (!isset($_SERVER["TERM"]) || !isset($_SERVER["LOGNAME"])) {
        echo "This script should not be called in a browser.\n";
        exit(1);
    }

    if (!function_exists("getopt")) {
        echo "PHP Function 'getopt()' not found. Please check your PHP version and settings.\n";
        exit(1);
    }

    require(PHP_MAPI_PATH.'mapi.util.php');
    require(PHP_MAPI_PATH.'mapidefs.php');
    require(PHP_MAPI_PATH.'mapicode.php');
    require(PHP_MAPI_PATH.'mapitags.php');
    require(PHP_MAPI_PATH.'mapiguid.php');
}

function listfolders_handle() {
    $shortoptions = "l:h:u:p:";
    $options = getopt($shortoptions);

    $mapi = MAPI_SERVER;
    $user = "SYSTEM";
    $pass = "";

    if (isset($options['h']))
        $mapi = $options['h'];

    if (isset($options['u']) && isset($options['p'])) {
        $user = $options['u'];
        $pass = $options['p'];
    }

    $zarafaAdmin = listfolders_zarafa_admin_setup($mapi, $user, $pass);
    if (isset($zarafaAdmin['adminStore']) && isset($options['l'])) {
        listfolders_getlist($zarafaAdmin['adminStore'], $zarafaAdmin['session'], trim($options['l']));
    }
    else {
        echo "Usage:\nlistfolders.php [actions] [options]\n\nActions: [-l username]\n\t-l username\tlist folders of user, for public folder use 'SYSTEM'\n\nGlobal options: [-h path] [[-u remoteuser] [-p password]]\n\t-h path\t\tconnect through <path>, e.g. file:///var/run/socket\n\t-u authuser\tlogin as authenticated administration user\n\t-p authpassword\tpassword of the remoteuser\n\n";
    }
}

function listfolders_zarafa_admin_setup ($mapi, $user, $pass) {
    $session = @mapi_logon_zarafa($user, $pass, $mapi);

    if (!$session) {
        echo "User '$user' could not login. The script will exit. Errorcode: 0x". sprintf("%x", mapi_last_hresult()) . "\n";
        exit(1);
    }

    $stores = @mapi_getmsgstorestable($session);
    $storeslist = @mapi_table_queryallrows($stores);
    $adminStore = @mapi_openmsgstore($session, $storeslist[0][PR_ENTRYID]);

    $zarafauserinfo = @mapi_zarafa_getuser_by_name($adminStore, $user);
    $admin = (isset($zarafauserinfo['admin']) && $zarafauserinfo['admin'])?true:false;

    if (!$stores || !$storeslist || !$adminStore || !$admin) {
        echo "There was error trying to log in as admin or retrieving admin info. The script will exit.\n";
        exit(1);
    }

    return array("session" => $session, "adminStore" => $adminStore);
}


function listfolders_getlist ($adminStore, $session, $user) {
    global $supported_classes;

    if (strtoupper($user) == 'SYSTEM') {
        // Find the public store store
        $storestables = @mapi_getmsgstorestable($session);
        $result = @mapi_last_hresult();

        if ($result == NOERROR){
            $rows = @mapi_table_queryallrows($storestables, array(PR_ENTRYID, PR_MDB_PROVIDER));

            foreach($rows as $row) {
                if (isset($row[PR_MDB_PROVIDER]) && $row[PR_MDB_PROVIDER] == ZARAFA_STORE_PUBLIC_GUID) {
                    if (!isset($row[PR_ENTRYID])) {
                        echo "Public folder are not available.\nIf this is a multi-tenancy system, use -u and -p and login with an admin user of the company.\nThe script will exit.\n";
                        exit (1);
                    }
                    $entryid = $row[PR_ENTRYID];
                    break;
                }
            }
        }
    }
    else
        $entryid = @mapi_msgstore_createentryid($adminStore, $user);

    $userStore = @mapi_openmsgstore($session, $entryid);
    $hresult = mapi_last_hresult();

    // Cache the store for later use
    if($hresult != NOERROR) {
        echo "Could not open store for '$user'. The script will exit.\n";
        exit (1);
    }

    $folder = @mapi_msgstore_openentry($userStore);
    $h_table = @mapi_folder_gethierarchytable($folder, CONVENIENT_DEPTH);
    $subfolders = @mapi_table_queryallrows($h_table, array(PR_ENTRYID, PR_DISPLAY_NAME, PR_CONTAINER_CLASS, PR_SOURCE_KEY));

    echo "Available folders in store '$user':\n" . str_repeat("-", 50) . "\n";
    foreach($subfolders as $folder) {
        if (isset($folder[PR_CONTAINER_CLASS]) && array_key_exists($folder[PR_CONTAINER_CLASS], $supported_classes)) {
            echo "Folder name:\t". $folder[PR_DISPLAY_NAME] . "\n";
            echo "Folder ID:\t". bin2hex($folder[PR_SOURCE_KEY]) . "\n";
            echo "Type:\t\t". $supported_classes[$folder[PR_CONTAINER_CLASS]] . "\n";
            echo "\n";
        }
    }
}

?>