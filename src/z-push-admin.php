#!/usr/bin/php
<?php
/***********************************************
* File      :   z-push-admin.php
* Project   :   Z-Push
* Descr     :   This is a small command line
*               client to see and modify the
*               wipe status of Zarafa users.
*
* Created   :   14.05.2010
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

include('lib/core/zpushdefs.php');
include('lib/core/zpush.php');
include('lib/core/stateobject.php');
include('lib/core/syncparameters.php');
include('lib/core/bodypreference.php');
include('lib/core/contentparameters.php');
include('lib/core/synccollections.php');
include('lib/core/zlog.php');
include('lib/core/statemanager.php');
include('lib/core/streamer.php');
include('lib/core/asdevice.php');
include('lib/core/interprocessdata.php');
include('lib/core/loopdetection.php');
include('lib/exceptions/exceptions.php');
include('lib/utils/utils.php');
include('lib/utils/zpushadmin.php');
include('lib/request/request.php');
include('lib/request/requestprocessor.php');
include('lib/interface/ibackend.php');
include('lib/interface/ichanges.php');
include('lib/interface/iexportchanges.php');
include('lib/interface/iimportchanges.php');
include('lib/interface/isearchprovider.php');
include('lib/interface/istatemachine.php');
include('lib/syncobjects/syncobject.php');
include('lib/syncobjects/syncbasebody.php');
include('lib/syncobjects/syncbaseattachment.php');
include('lib/syncobjects/syncmailflags.php');
include('lib/syncobjects/syncrecurrence.php');
include('lib/syncobjects/syncappointment.php');
include('lib/syncobjects/syncappointmentexception.php');
include('lib/syncobjects/syncattachment.php');
include('lib/syncobjects/syncattendee.php');
include('lib/syncobjects/syncmeetingrequestrecurrence.php');
include('lib/syncobjects/syncmeetingrequest.php');
include('lib/syncobjects/syncmail.php');
include('lib/syncobjects/syncnote.php');
include('lib/syncobjects/synccontact.php');
include('lib/syncobjects/syncfolder.php');
include('lib/syncobjects/syncprovisioning.php');
include('lib/syncobjects/synctaskrecurrence.php');
include('lib/syncobjects/synctask.php');
include('lib/syncobjects/syncoofmessage.php');
include('lib/syncobjects/syncoof.php');
include('lib/syncobjects/syncuserinformation.php');
include('lib/syncobjects/syncdeviceinformation.php');
include('lib/syncobjects/syncdevicepassword.php');
include('lib/syncobjects/syncitemoperationsattachment.php');
include('config.php');
include('version.php');

/**
 * //TODO resync of single folders of a users device
 */

/************************************************
 * MAIN
 */
    define('BASE_PATH_CLI',  dirname(__FILE__) ."/");
    set_include_path(get_include_path() . PATH_SEPARATOR . BASE_PATH_CLI);
    try {
        ZPush::CheckConfig();
        ZPushAdminCLI::CheckEnv();
        ZPushAdminCLI::CheckOptions();

        if (! ZPushAdminCLI::SureWhatToDo()) {
            // show error message if available
            if (ZPushAdminCLI::GetErrorMessage())
                echo "ERROR: ". ZPushAdminCLI::GetErrorMessage() . "\n";

            echo ZPushAdminCLI::UsageInstructions();
            exit(1);
        }

        ZPushAdminCLI::RunCommand();
    }
    catch (ZPushException $zpe) {
        die(get_class($zpe) . ": ". $zpe->getMessage() . "\n");
    }


/************************************************
 * Z-Push-Admin CLI
 */
class ZPushAdminCLI {
    const COMMAND_SHOWALLDEVICES = 1;
    const COMMAND_SHOWDEVICESOFUSER = 2;
    const COMMAND_SHOWUSERSOFDEVICE = 3;
    const COMMAND_WIPEDEVICE = 4;
    const COMMAND_REMOVEDEVICE = 5;
    const COMMAND_RESYNCDEVICE = 6;
    const COMMAND_CLEARLOOP = 7;

    static private $command;
    static private $user = false;
    static private $device = false;
    static private $errormessage;

    /**
     * Returns usage instructions
     *
     * @return string
     * @access public
     */
    static public function UsageInstructions() {
        return  "Usage:\n\tz-push-admin.php -a ACTION [options]\n\n" .
                "Parameters:\n\t-a list/wipe/remove/resync/clearloop\n\t[-u] username\n\t[-d] deviceid\n\n" .
                "Actions:\n\tlist\t\t\t\t Lists all devices and synchronized users\n" .
                "\tlist -u USER\t\t\t Lists all devices of user USER\n" .
                "\tlist -d DEVICE\t\t\t Lists all users of device DEVICE\n" .
                "\twipe -u USER\t\t\t Remote wipes all devices of user USER\n" .
                "\twipe -d DEVICE\t\t\t Remote wipes device DEVICE\n" .
                "\twipe -u USER -d DEVICE\t\t Remote wipes device DEVICE of user USER\n" .
                "\tremove -u USER\t\t\t Removes all state data of all devices of user USER\n" .
                "\tremove -d DEVICE\t\t Removes all state data of all users synchronized on device DEVICE\n" .
                "\tremove -u USER -d DEVICE\t Removes all related state data of device DEVICE of user USER\n" .
                "\tresync -u USER -d DEVICE\t Resynchronizes all data of device DEVICE of user USER\n" .
                "\tclearloop\t\t\t Clears system wide loop detection data\n" .
                "\tclearloop -d DEVICE -u USER\t Clears all loop detection data of a device DEVICE and an optional user USER\n" .
                "\n";
    }

    /**
     * Checks the environment
     *
     * @return
     * @access public
     */
    static public function CheckEnv() {
        if (!isset($_SERVER["TERM"]) || !isset($_SERVER["LOGNAME"]))
            self::$errormessage = "This script should not be called in a browser.";

        if (!function_exists("getopt"))
            self::$errormessage = "PHP Function getopt not found. Please check your PHP version and settings.";
    }

    /**
     * Checks the options from the command line
     *
     * @return
     * @access public
     */
    static public function CheckOptions() {
        if (self::$errormessage)
            return;

        $options = getopt("u:d:a:");

        // get 'user'
        if (isset($options['u']) && !empty($options['u']))
            self::$user = strtolower(trim($options['u']));
        else if (isset($options['user']) && !empty($options['user']))
            self::$user = strtolower(trim($options['user']));

        // get 'device'
        if (isset($options['d']) && !empty($options['d']))
            self::$device = trim($options['d']);
        else if (isset($options['device']) && !empty($options['device']))
            self::$device = trim($options['device']);

        // get 'action'
        $action = false;
        if (isset($options['a']) && !empty($options['a']))
            $action = strtolower(trim($options['a']));
        elseif (isset($options['action']) && !empty($options['action']))
            $action = strtolower(trim($options['action']));

        // get a command for the requested action
        switch ($action) {
            // list data
            case "list":
                if (self::$user === false && self::$device === false)
                    self::$command = self::COMMAND_SHOWALLDEVICES;

                if (self::$user !== false)
                    self::$command = self::COMMAND_SHOWDEVICESOFUSER;

                if (self::$device !== false)
                    self::$command = self::COMMAND_SHOWUSERSOFDEVICE;
                break;

            // remove wipe device
            case "wipe":
                if (self::$user === false && self::$device === false)
                    self::$errormessage = "Not possible to execute remote wipe. Device, user or both must be specified.";
                else
                    self::$command = self::COMMAND_WIPEDEVICE;
                break;

            // remove device data of user
            case "remove":
                if (self::$user === false && self::$device === false)
                    self::$errormessage = "Not possible to remove data. Device, user or both must be specified.";
                else
                    self::$command = self::COMMAND_REMOVEDEVICE;
                break;

            // resync a device
            case "resync":
            case "re-sync":
            case "sync":
            case "resynchronize":
            case "re-synchronize":
            case "synchronize":
                if (self::$user === false || self::$device === false)
                    self::$errormessage = "Not possible to resynchronize device. Device and user must be specified.";
                else
                    self::$command = self::COMMAND_RESYNCDEVICE;
                break;

            // clear loop detection data
            case "clearloop":
            case "clearloopdetection":
                self::$command = self::COMMAND_CLEARLOOP;
                break;


            default:
                self::UsageInstructions();
        }
    }

    /**
     * Indicates if the options from the command line
     * could be processed correctly
     *
     * @return boolean
     * @access public
     */
    static public function SureWhatToDo() {
        return isset(self::$command);
    }

    /**
     * Returns a errormessage of things which could have gone wrong
     *
     * @return string
     * @access public
     */
    static public function GetErrorMessage() {
        return (isset(self::$errormessage))?self::$errormessage:"";
    }

    /**
     * Runs a command requested from an action of the command line
     *
     * @return
     * @access public
     */
    static public function RunCommand() {
        echo "\n";
        switch(self::$command) {
            case self::COMMAND_SHOWALLDEVICES:
                self::CommandShowDevices();
                break;

            case self::COMMAND_SHOWDEVICESOFUSER:
                self::CommandShowDevices();
                break;

            case self::COMMAND_SHOWUSERSOFDEVICE:
                self::CommandDeviceUsers();
                break;

            case self::COMMAND_WIPEDEVICE:
                if (self::$device)
                    echo sprintf("Are you sure you want to REMOTE WIPE device '%s' [y/N]: ", self::$device);
                else
                    echo sprintf("Are you sure you want to REMOTE WIPE all devices of user '%s' [y/N]: ", self::$user);

                $confirm  =  strtolower(trim(fgets(STDIN)));
                if ( $confirm === 'y' || $confirm === 'yes')
                    self::CommandWipeDevice();
                else
                    echo "Aborted!\n";
                break;

            case self::COMMAND_REMOVEDEVICE:
                self::CommandRemoveDevice();
                break;

            case self::COMMAND_RESYNCDEVICE:
                if (self::$device == false) {
                    echo sprintf("Are you sure you want to re-synchronize all devices of user '%s' [y/N]: ", self::$user);
                    $confirm  =  strtolower(trim(fgets(STDIN)));
                    if ( !($confirm === 'y' || $confirm === 'yes'))
                        echo "Aborted!\n";
                        exit(1);
                }
                self::CommandResyncDevices();
                break;

            case self::COMMAND_CLEARLOOP:
                self::CommandClearLoopDetectionData();
                break;
        }
        echo "\n";
    }

    /**
     * Command "Show all devices" and "Show devices of user"
     * Prints the device id of/and connected users
     *
     * @return
     * @access public
     */
    static public function CommandShowDevices() {
        $devicelist = ZPushAdmin::ListDevices(self::$user);
        if (empty($devicelist))
            echo "\tno devices found\n";
        else {
            if (self::$user === false) {
                echo "All synchronized devices\n\n";
                echo str_pad("Device id", 36). "Synchronized users\n";
                echo "-----------------------------------------------------\n";
            }
            else
                echo "Synchronized devices of user: ". self::$user. "\n";
        }

        foreach ($devicelist as $deviceId) {
            if (self::$user === false) {
                echo str_pad($deviceId, 36) . implode (",", ZPushAdmin::ListUsers($deviceId)) ."\n";
            }
            else
                self::printDeviceData($deviceId, self::$user);
        }
    }

    /**
     * Command "Show users of device"
     * Prints informations about all users which use a device
     *
     * @return
     * @access public
     */
    static public function CommandDeviceUsers() {
        $users = ZPushAdmin::ListUsers(self::$device);

        if (empty($users))
            echo "\tno user data synchronized to device\n";

        foreach ($users as $user) {
            echo "Synchronized by user: ". $user. "\n";
            self::printDeviceData(self::$device, $user);
        }
    }

    /**
     * Command "Wipe device"
     * Marks a device of that user to be remotely wiped
     *
     * @return
     * @access public
     */
    static public function CommandWipeDevice() {
        $stat = ZPushAdmin::WipeDevice($_SERVER["LOGNAME"], self::$user, self::$device);

        if (self::$user !== false && self::$device !== false) {
            echo sprintf("Mark device '%s' of user '%s' to be wiped: %s", self::$device, self::$user, ($stat)?'OK':ZLog::GetLastMessage(LOGLEVEL_ERROR)). "\n";

            if ($stat) {
                echo "Updated information about this device:\n";
                self::printDeviceData(self::$device, self::$user);
            }
        }
        elseif (self::$user !== false) {
            echo sprintf("Mark devices of user '%s' to be wiped: %s", self::$user, ($stat)?'OK':ZLog::GetLastMessage(LOGLEVEL_ERROR)). "\n";
            self::CommandShowDevices();
        }
    }

    /**
     * Command "Remove device"
     * Remove a device of that user from the device list
     *
     * @return
     * @access public
     */
    static public function CommandRemoveDevice() {
        $stat = ZPushAdmin::RemoveDevice(self::$user, self::$device);
        if (self::$user === false)
           echo sprintf("State data of device '%s' removed: %s", self::$device, ($stat)?'OK':ZLog::GetLastMessage(LOGLEVEL_ERROR)). "\n";
        elseif (self::$device === false)
           echo sprintf("State data of all devices of user '%s' removed: %s", self::$user, ($stat)?'OK':ZLog::GetLastMessage(LOGLEVEL_ERROR)). "\n";
        else
           echo sprintf("State data of device '%s' of user '%s' removed: %s", self::$device, self::$user, ($stat)?'OK':ZLog::GetLastMessage(LOGLEVEL_ERROR)). "\n";
    }

    /**
     * Command "Resync device(s)"
     * Resyncs one or all devices of that user
     *
     * @return
     * @access public
     */
    static public function CommandResyncDevices() {
        $stat = ZPushAdmin::ResyncDevice(self::$user, self::$device);
        echo sprintf("Resync of device '%s' of user '%s': %s", self::$device, self::$user, ($stat)?'Requested':ZLog::GetLastMessage(LOGLEVEL_ERROR)). "\n";
    }

    static public function CommandClearLoopDetectionData() {
        $stat = false;
        $stat = ZPushAdmin::ClearLoopDetectionData(self::$user, self::$device);
        if (self::$user === false && self::$device === false)
           echo sprintf("System wide loop detection data removed: %s", ($stat)?'OK':ZLog::GetLastMessage(LOGLEVEL_ERROR)). "\n";
        elseif (self::$user === false)
           echo sprintf("Loop detection data of device '%s' removed: %s", self::$device, ($stat)?'OK':ZLog::GetLastMessage(LOGLEVEL_ERROR)). "\n";
        elseif (self::$device === false && self::$user !== false)
           echo sprintf("Error: %s", ($stat)?'OK':ZLog::GetLastMessage(LOGLEVEL_WARN)). "\n";
        else
           echo sprintf("Loop detection data of device '%s' of user '%s' removed: %s", self::$device, self::$user, ($stat)?'OK':ZLog::GetLastMessage(LOGLEVEL_ERROR)). "\n";
    }

    /**
     * Prints detailed informations about a device
     *
     * @param string    $deviceId       the id of the device
     *
     * @return
     * @access private
     */
    static private function printDeviceData($deviceId, $user) {
        $device = ZPushAdmin::GetDeviceDetails($deviceId, $user);

        if (! $device instanceof ASDevice)
            return false;

        // Gather some statistics about synchronized folders
        $folders = $device->GetAllFolderIds();
        $synchedFolders = 0;
        $synchedFolderTypes = array();
        foreach ($folders as $folderid) {
            if ($device->GetFolderUUID($folderid)) {
                $synchedFolders++;
                $type = $device->GetFolderType($folderid);
                switch($type) {
                    case SYNC_FOLDER_TYPE_APPOINTMENT:
                    case SYNC_FOLDER_TYPE_USER_APPOINTMENT:
                        $gentype = "Calendars";
                        break;
                    case SYNC_FOLDER_TYPE_CONTACT:
                    case SYNC_FOLDER_TYPE_USER_CONTACT:
                        $gentype = "Contacts";
                        break;
                    case SYNC_FOLDER_TYPE_TASK:
                    case SYNC_FOLDER_TYPE_USER_TASK:
                        $gentype = "Tasks";
                        break;
                    case SYNC_FOLDER_TYPE_NOTE:
                    case SYNC_FOLDER_TYPE_USER_NOTE:
                        $gentype = "Notes";
                        break;
                    default:
                        $gentype = "Emails";
                        break;
                }
                if (!isset($synchedFolderTypes[$gentype]))
                    $synchedFolderTypes[$gentype] = 0;
                $synchedFolderTypes[$gentype]++;
            }
        }
        $folderinfo = "";
        foreach ($synchedFolderTypes as $gentype=>$count) {
            $folderinfo .= $gentype;
            if ($count>1) $folderinfo .= "($count)";
            $folderinfo .= " ";
        }
        if (!$folderinfo) $folderinfo = "None available";

        echo "-----------------------------------------------------\n";
        echo "DeviceId:\t\t$deviceId\n";
        echo "Device type:\t\t". ($device->GetDeviceType() !== ASDevice::UNDEFINED ? $device->GetDeviceType() : "unknown") ."\n";
        echo "UserAgent:\t\t".($device->GetDeviceUserAgent()!== ASDevice::UNDEFINED ? $device->GetDeviceUserAgent() : "unknown") ."\n";
        // TODO implement $device->GetDeviceUserAgentHistory()

        // device information transmitted during Settings command
        if ($device->GetDeviceModel())
            echo "Device Model:\t\t". $device->GetDeviceModel(). "\n";
        if ($device->GetDeviceIMEI())
            echo "Device IMEI:\t\t". $device->GetDeviceIMEI(). "\n";
        if ($device->GetDeviceFriendlyName())
            echo "Device friendly name:\t". $device->GetDeviceFriendlyName(). "\n";
        if ($device->GetDeviceOS())
            echo "Device OS:\t\t". $device->GetDeviceOS(). "\n";
        if ($device->GetDeviceOSLanguage())
            echo "Device OS Language:\t". $device->GetDeviceOSLanguage(). "\n";
        if ($device->GetDevicePhoneNumber())
            echo "Device Phone nr:\t". $device->GetDevicePhoneNumber(). "\n";
        if ($device->GetDeviceMobileOperator())
            echo "Device Operator:\t\t". $device->GetDeviceMobileOperator(). "\n";
        if ($device->GetDeviceEnableOutboundSMS())
            echo "Device Outbound SMS:\t". $device->GetDeviceEnableOutboundSMS(). "\n";

        echo "ActiveSync version:\t".($device->GetASVersion() ? $device->GetASVersion() : "unknown") ."\n";
        echo "First sync:\t\t". strftime("%Y-%m-%d %H:%M", $device->GetFirstSyncTime()) ."\n";
        echo "Last sync:\t\t". ($device->GetLastSyncTime() ? strftime("%Y-%m-%d %H:%M", $device->GetLastSyncTime()) : "never")."\n";
        echo "Total folders:\t\t". count($folders). "\n";
        echo "Synchronized folders:\t". $synchedFolders . "\n";
        echo "Synchronized data:\t$folderinfo\n";
        echo "Status:\t\t\t";
        switch ($device->GetWipeStatus()) {
            case SYNC_PROVISION_RWSTATUS_OK:
                echo "OK\n";
                break;
            case SYNC_PROVISION_RWSTATUS_PENDING:
                echo "Pending wipe\n";
                break;
            case SYNC_PROVISION_RWSTATUS_REQUESTED:
                echo "Wipe requested on device\n";
                break;
            case SYNC_PROVISION_RWSTATUS_WIPED:
                echo "Wiped\n";
                break;
            default:
                echo "Not available\n";
                break;
        }

        echo "WipeRequest on:\t\t". ($device->GetWipeRequestedOn() ? strftime("%Y-%m-%d %H:%M", $device->GetWipeRequestedOn()) : "not set")."\n";
        echo "WipeRequest by:\t\t". ($device->GetWipeRequestedBy() ? $device->GetWipeRequestedBy() : "not set")."\n";
        echo "Wiped on:\t\t". ($device->GetWipeActionOn() ? strftime("%Y-%m-%d %H:%M", $device->GetWipeActionOn()) : "not set")."\n";

        echo "Attention needed:\t";

        if ($device->GetDeviceError())
            echo $device->GetDeviceError() ."\n";
        else if (!isset($device->ignoredmessages) || empty($device->ignoredmessages)) {
            echo "No errors known\n";
        }
        else {
            printf("%d messages need attention because they could not be synchronized\n", count($device->ignoredmessages));
            foreach ($device->ignoredmessages as $im) {
                $info = "";
                if (isset($im->asobject->subject))
                    $info .= sprintf("Subject: '%s'", $im->asobject->subject);
                if (isset($im->asobject->fileas))
                    $info .= sprintf("FileAs: '%s'", $im->asobject->fileas);
                if (isset($im->asobject->from))
                    $info .= sprintf(" - From: '%s'", $im->asobject->from);
                if (isset($im->asobject->starttime))
                    $info .= sprintf(" - On: '%s'", strftime("%Y-%m-%d %H:%M", $im->asobject->starttime));
                $reason = $im->reasonstring;
                if ($im->reasoncode == 2)
                    $reason = "Message was causing loop";
                printf("\tBroken object:\t'%s' ignored on '%s'\n", $im->asclass,  strftime("%Y-%m-%d %H:%M", $im->timestamp));
                printf("\tInformation:\t%s\n", $info);
                printf("\tReason: \t%s (%s)\n", $reason, $im->reasoncode);
                printf("\tItem/Parent id: %s/%s\n", $im->id, $im->folderid);
                echo "\n";
            }
        }

    }
}


?>