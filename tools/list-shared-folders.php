#!/usr/bin/env php
<?php
/**********************************************************
* File      :   list-shared-folders.php
* Project   :   Z-Push - tools
* Descr     :   Lists all users and devices that have open additional folders
*
* Created   :   20.10.2016
*
* Copyright 2007 - 2016 Zarafa Deutschland GmbH
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

// Please adjust to match your z-push installation directory, usually /usr/share/z-push
define('ZPUSH_BASE_PATH', "/usr/share/z-push");

/**
 * Usage:
 *     php list-shared-folders.php
 *              List all shared folders for all devices
 *
 *     php list-shared-folders.php | awk -F',' '$6 == "13" && $7 != ""'
 *              List all shared folders of type calendar (13) that are synchronized on a device (else SyncUUID is empty)
 *
 *     php list-shared-folders.php | awk -F',' '$6 == "13" && $7 != "" { system("z-push-admin -a resync -d " $1 " -u " $2 " -t " $5) }'
 *              Resynchronizes all shared folders from type calendar (13) that are synchronized already
 */


/************************************************
 * MAIN
 */

try {
    if (php_sapi_name() != "cli") {
        die("This script can only be called from the CLI.");
    }

    if (!defined('ZPUSH_BASE_PATH') || !file_exists(ZPUSH_BASE_PATH . "/config.php")) {
        die("ZPUSH_BASE_PATH not set correctly or no config.php file found\n");
    }

    define('BASE_PATH_CLI',  ZPUSH_BASE_PATH ."/");
    set_include_path(get_include_path() . PATH_SEPARATOR . ZPUSH_BASE_PATH);

    require_once 'vendor/autoload.php';

    if (!defined('ZPUSH_CONFIG')) define('ZPUSH_CONFIG', 'config.php');
    include_once(ZPUSH_CONFIG);

    ZPush::CheckConfig();

    $sm = ZPush::GetStateMachine();
    $devices = $sm->GetAllDevices();

    if (!empty($devices)) {
        printf("%s,%s,%s,%s,%s,%s,%s,%s\n", "DeviceId", "User", "Store", "SyncFolderId", "FolderId", "Type", "SyncUUID", "Name");
    }
    foreach ($devices as $devid) {
        $users = ZPushAdmin::ListUsers($devid);
        foreach($users as $user) {
            $device = ZPushAdmin::GetDeviceDetails($devid, $user);
            foreach ($device->GetAdditionalFolders() as $folder) {
                $syncfolderid = $device->GetFolderIdForBackendId($folder['folderid'], false, false, null);
                if(Utils::GetFolderOriginFromId($syncfolderid) !== DeviceManager::FLD_ORIGIN_SHARED) {
                    continue;
                }
                $syncfolder = $device->GetHierarchyCache()->GetFolder($syncfolderid);
                // if there is no syncfolder then this folder was not synchronized to the client (e.g. no permissions)
                if (!$syncfolder) {
                    continue;
                }
                $folderUuid = $device->GetFolderUUID($syncfolderid);
                printf("%s,%s,%s,%s,%s,%d,%s,%s\n", $devid, $user, $syncfolder->Store, $syncfolderid, $folder['folderid'], $syncfolder->type, $folderUuid, $syncfolder->displayname);
            }
        }
    }
}
catch (ZPushException $zpe) {
    die(get_class($zpe) . ": ". $zpe->getMessage() . "\n");
}
