<?php
/***********************************************
* File      :   zpushadmin.php
* Project   :   Z-Push
* Descr     :   Administration tasks for users and devices
*
* Created   :   23.12.2011
*
* Copyright 2007 - 2011 Zarafa Deutschland GmbH
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

class ZPushAdmin {
    /**
     * //TODO resync of a foldertype for all users (e.g. Appointment)
     */

    /**
     * List devices known to Z-Push.
     * If no user is given, all devices are listed
     *
     * @param string    $user       devices of that user, if false all devices of all users
     *
     * @return array
     * @access public
     */
    static public function ListDevices($user = false) {
        return ZPush::GetStateMachine()->GetAllDevices($user);
    }

    /**
     * List users of a device known to Z-Push.
     *
     * @param string    $devid      users of that device
     *
     * @return array
     * @access public
     */
    static public function ListUsers($devid) {
        try {
            $devState = ZPush::GetStateMachine()->GetState($devid, IStateMachine::DEVICEDATA);

            if ($devState instanceof StateObject && isset($devState->devices) && is_array($devState->devices))
                return array_keys($devState->devices);
            else
                return array();
        }
        catch (StateNotFoundException $stnf) {
            return array();
        }
    }

    /**
     * Returns details of a device like synctimes,
     * policy and wipe status, synched folders etc
     *
     * @param string    $devid      device id
     * @param string    $user       user to be looked up
     *
     * @return ASDevice object
     * @access public
     */
    static public function GetDeviceDetails($devid, $user) {

        try {
            $device = new ASDevice($devid, ASDevice::UNDEFINED, $user, ASDevice::UNDEFINED);
            $device->SetData(ZPush::GetStateMachine()->GetState($devid, IStateMachine::DEVICEDATA), false);
            $device->StripData();

            try {
                $lastsync = SyncCollections::GetLastSyncTimeOfDevice($device);
                if ($lastsync)
                    $device->SetLastSyncTime($lastsync);
            }
            catch (StateInvalidException $sive) {
                ZLog::Write(LOGLEVEL_WARN, sprintf("ZPushAdmin::GetDeviceDetails(): device '%s' of user '%s' has invalid states. Please sync to solve this issue.", $devid, $user));
                $device->SetDeviceError("Invalid states. Please force synchronization!");
            }

            return $device;
        }
        catch (StateNotFoundException $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("ZPushAdmin::GetDeviceDetails(): device '%s' of user '%s' can not be found", $devid, $user));
            return false;
        }
    }

    /**
     * Wipes 'a' or all devices of a user.
     * If no user is set, the device is generally wiped.
     * If no device id is set, all devices of the user will be wiped.
     * Device id or user must be set!
     *
     * @param string    $requestedBy    user which requested this operation
     * @param string    $user           (opt)user of the device
     * @param string    $devid          (opt) device id which should be wiped
     *
     * @return boolean
     * @access public
     */
    static public function WipeDevice($requestedBy, $user, $devid = false) {
        if ($user === false && $devid === false)
            return false;

        if ($devid === false) {
            $devicesIds = ZPush::GetStateMachine()->GetAllDevices($user);
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZPushAdmin::WipeDevice(): all '%d' devices for user '%s' found to be wiped", count($devicesIds), $user));
            foreach ($devicesIds as $deviceid) {
                if (!self::WipeDevice($requestedBy, $user, $deviceid)) {
                    ZLog::Write(LOGLEVEL_ERROR, sprintf("ZPushAdmin::WipeDevice(): wipe devices failed for device '%s' of user '%s'. Aborting.", $deviceid, $user));
                    return false;
                }
            }
        }

        // wipe a device completely (for connected users to this device)
        else if ($devid !== false && $user === false) {
            $users = self::ListUsers($devid);
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZPushAdmin::WipeDevice(): device '%d' is used by '%d' users and will be wiped", $devid, count($users)));
            if (count($users) == 0)
                ZLog::Write(LOGLEVEL_ERROR, sprintf("ZPushAdmin::WipeDevice(): no user found on device '%s'. Aborting.", $devid));

            return self::WipeDevice($requestedBy, $users[0], $devid);
        }

        else {
            // load device data
            $device = new ASDevice($devid, ASDevice::UNDEFINED, $user, ASDevice::UNDEFINED);
            try {
                $device->SetData(ZPush::GetStateMachine()->GetState($devid, IStateMachine::DEVICEDATA), false);
            }
            catch (StateNotFoundException $e) {
                ZLog::Write(LOGLEVEL_ERROR, sprintf("ZPushAdmin::WipeDevice(): device '%s' of user '%s' can not be found", $devid, $user));
                return false;
            }

            // set wipe status
            if ($device->GetWipeStatus() == SYNC_PROVISION_RWSTATUS_WIPED)
                ZLog::Write(LOGLEVEL_INFO, sprintf("ZPushAdmin::WipeDevice(): device '%s' of user '%s' was alread sucessfully remote wiped on %s", $devid , $user, strftime("%Y-%m-%d %H:%M", $device->GetWipeActionOn())));
            else
                $device->SetWipeStatus(SYNC_PROVISION_RWSTATUS_PENDING, $requestedBy);

            // save device data
            try {
                if ($device->IsNewDevice()) {
                    ZLog::Write(LOGLEVEL_ERROR, sprintf("ZPushAdmin::WipeDevice(): data of user '%s' not synchronized on device '%s'. Aborting.", $user, $devid));
                    return false;
                }

                ZPush::GetStateMachine()->SetState($device->GetData(), $devid, IStateMachine::DEVICEDATA);
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZPushAdmin::WipeDevice(): device '%s' of user '%s' marked to be wiped", $devid, $user));
            }
            catch (StateNotFoundException $e) {
                ZLog::Write(LOGLEVEL_ERROR, sprintf("ZPushAdmin::WipeDevice(): state for device '%s' of user '%s' can not be saved", $devid, $user));
                return false;
            }
        }
        return true;
    }


    /**
     * Removes device details from the z-push directory.
     * If device id is not set, all devices of a user are removed.
     * If the user is not set, the details of the device (independently if used by several users) is removed.
     * Device id or user must be set!
     *
     * @param string    $user           (opt) user of the device
     * @param string    $devid          (opt) device id which should be wiped
     *
     * @return boolean
     * @access public
     */
    static public function RemoveDevice($user = false, $devid = false) {
        if ($user === false && $devid === false)
            return false;

        // remove all devices for user
        if ($devid === false && $user !== false) {
            $devicesIds = ZPush::GetStateMachine()->GetAllDevices($user);
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZPushAdmin::RemoveDevice(): all '%d' devices for user '%s' found to be removed", count($devicesIds), $user));
            foreach ($devicesIds as $deviceid) {
                if (!self::RemoveDevice($user, $deviceid)) {
                    ZLog::Write(LOGLEVEL_ERROR, sprintf("ZPushAdmin::RemoveDevice(): removing devices failed for device '%s' of user '%s'. Aborting", $deviceid, $user));
                    return false;
                }
            }
        }
        // remove a device completely (for connected users to this device)
        else if ($devid !== false && $user === false) {
            $users = self::ListUsers($devid);
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZPushAdmin::RemoveDevice(): device '%d' is used by '%d' users and will be removed", $devid, count($users)));
            foreach ($users as $aUser) {
                if (!self::RemoveDevice($aUser, $devid)) {
                    ZLog::Write(LOGLEVEL_ERROR, sprintf("ZPushAdmin::RemoveDevice(): removing user '%s' from device '%s' failed. Aborting", $aUser, $devid));
                    return false;
                }
            }
        }

        // user and deviceid set
        else {
            // load device data
            $device = new ASDevice($devid, ASDevice::UNDEFINED, $user, ASDevice::UNDEFINED);
            $devices = array();
            try {
                $devicedata = ZPush::GetStateMachine()->GetState($devid, IStateMachine::DEVICEDATA);
                $device->SetData($devicedata, false);
                $devices = $devicedata->devices;
            }
            catch (StateNotFoundException $e) {
                ZLog::Write(LOGLEVEL_ERROR, sprintf("ZPushAdmin::RemoveDevice(): device '%s' of user '%s' can not be found", $devid, $user));
                return false;
            }

            // remove all related states
            foreach ($device->GetAllFolderIds() as $folderid)
                StateManager::UnLinkState($device, $folderid);

            // remove hierarchcache
            StateManager::UnLinkState($device, false);

            // remove backend storage permanent data
            ZPush::GetStateMachine()->CleanStates($device->GetDeviceId(), IStateMachine::BACKENDSTORAGE, false, 99999999999);

            // remove devicedata and unlink user from device
            unset($devices[$user]);
            if (isset($devicedata->devices))
                $devicedata->devices = $devices;
            ZPush::GetStateMachine()->UnLinkUserDevice($user, $devid);

            // no more users linked for device - remove device data
            if (count($devices) == 0)
                ZPush::GetStateMachine()->CleanStates($devid, IStateMachine::DEVICEDATA, false);

            // save data if something left
            else
                ZPush::GetStateMachine()->SetState($devicedata, $devid, IStateMachine::DEVICEDATA);

            ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZPushAdmin::RemoveDevice(): data of device '%s' of user '%s' removed", $devid, $user));
        }
        return true;
    }


    /**
     * Marks a folder of a device of a user for re-synchronization
     *
     * @param string    $user           user of the device
     * @param string    $devid          device id which should be wiped
     * @param string    $folderid       if not set, hierarchy state is linked
     *
     * @return boolean
     * @access public
     */
    static public function ResyncFolder($user, $devid, $folderid) {
        // load device data
        $device = new ASDevice($devid, ASDevice::UNDEFINED, $user, ASDevice::UNDEFINED);
        try {
            $device->SetData(ZPush::GetStateMachine()->GetState($devid, IStateMachine::DEVICEDATA), false);

            if ($device->IsNewDevice()) {
                ZLog::Write(LOGLEVEL_ERROR, sprintf("ZPushAdmin::ResyncFolder(): data of user '%s' not synchronized on device '%s'. Aborting.",$user, $devid));
                return false;
            }

            // remove folder state
            StateManager::UnLinkState($device, $folderid);

            ZPush::GetStateMachine()->SetState($device->GetData(), $devid, IStateMachine::DEVICEDATA);
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZPushAdmin::ResyncFolder(): folder '%s' on device '%s' of user '%s' marked to be re-synchronized.", $devid, $user));
        }
        catch (StateNotFoundException $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("ZPushAdmin::ResyncFolder(): state for device '%s' of user '%s' can not be found or saved", $devid, $user));
            return false;
        }
    }


    /**
     * Marks a all folders synchronized to a device for re-synchronization
     * If no user is set all user which are synchronized for a device are marked for re-synchronization.
     * If no device id is set all devices of that user are marked for re-synchronization.
     * If no user and no device are set then ALL DEVICES are marked for resynchronization (use with care!).
     *
     * @param string    $user           (opt) user of the device
     * @param string    $devid          (opt)device id which should be wiped
     *
     * @return boolean
     * @access public
     */
    static public function ResyncDevice($user, $devid = false) {

        // search for target devices
        if ($devid === false) {
            $devicesIds = ZPush::GetStateMachine()->GetAllDevices($user);
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZPushAdmin::ResyncDevice(): all '%d' devices for user '%s' found to be re-synchronized", count($devicesIds), $user));
            foreach ($devicesIds as $deviceid) {
                if (!self::ResyncDevice($user, $deviceid)) {
                    ZLog::Write(LOGLEVEL_ERROR, sprintf("ZPushAdmin::ResyncDevice(): wipe devices failed for device '%s' of user '%s'. Aborting", $deviceid, $user));
                    return false;
                }
            }
        }
        else {
            // get devicedata
            try {
                $devicedata = ZPush::GetStateMachine()->GetState($devid, IStateMachine::DEVICEDATA);
            }
            catch (StateNotFoundException $e) {
                ZLog::Write(LOGLEVEL_ERROR, sprintf("ZPushAdmin::ResyncDevice(): state for device '%s' can not be found", $devid));
                return false;

            }

            // loop through all users which currently use this device
            if ($user === false && $devicedata instanceof StateObject && isset($devicedata->devices) &&
                is_array($devicedata->devices) && count($devicedata->devices) > 1) {
                foreach (array_keys($devicedata) as $aUser) {
                    if (!self::ResyncDevice($aUser, $devid)) {
                        ZLog::Write(LOGLEVEL_ERROR, sprintf("ZPushAdmin::ResyncDevice(): re-synchronization failed for device '%s' of user '%s'. Aborting", $devid, $aUser));
                        return false;
                    }
                }
            }

            // load device data
            $device = new ASDevice($devid, ASDevice::UNDEFINED, $user, ASDevice::UNDEFINED);
            try {
                $device->SetData($devicedata, false);

                if ($device->IsNewDevice()) {
                    ZLog::Write(LOGLEVEL_ERROR, sprintf("ZPushAdmin::ResyncDevice(): data of user '%s' not synchronized on device '%s'. Aborting.",$user, $devid));
                    return false;
                }

                // delete all uuids
                foreach ($device->GetAllFolderIds() as $folderid)
                    StateManager::UnLinkState($device, $folderid);

                // remove hierarchcache
                StateManager::UnLinkState($device, false);

                ZPush::GetStateMachine()->SetState($device->GetData(), $devid, IStateMachine::DEVICEDATA);

                ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZPushAdmin::ResyncDevice(): all folders synchronized to device '%s' of user '%s' marked to be re-synchronized.", $devid, $user));
            }
            catch (StateNotFoundException $e) {
                ZLog::Write(LOGLEVEL_ERROR, sprintf("ZPushAdmin::ResyncDevice(): state for device '%s' of user '%s' can not be found or saved", $devid, $user));
                return false;
            }
        }
        return true;
    }

    /**
     * Clears loop detection data
     *
     * @param string    $user           (opt) user which data should be removed - user may not be specified without device id
     * @param string    $devid          (opt) device id which data to be removed
     *
     * @return boolean
     * @access public
     */
    static public function ClearLoopDetectionData($user = false, $devid = false) {
        $loopdetection = new LoopDetection();
        return $loopdetection->ClearData($user, $devid);
    }

    /**
     * Returns loop detection data of a user & device
     *
     * @param string    $user
     * @param string    $devid
     *
     * @return array/boolean            returns false if data is not available
     * @access public
     */
    static public function GetLoopDetectionData($user, $devid) {
        $loopdetection = new LoopDetection();
        return $loopdetection->GetCachedData($user, $devid);
    }


}

?>