<?php
/***********************************************
* File      :   webservicedevice.php
* Project   :   Z-Push
* Descr     :   Device remote administration tasks
*               used over webservice e.g. by the
*               Mobile Device Management Plugin for Zarafa.
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
include ('lib/utils/zpushadmin.php');

class WebserviceDevice {

    /**
     * Returns a list of all known devices of the Request::GetGETUser()
     *
     * @access public
     * @return array
     */
    public function ListDevicesDetails() {
        $user = Request::GetGETUser();
        $devices = ZPushAdmin::ListDevices($user);
        $output = array();

        ZLog::Write(LOGLEVEL_INFO, sprintf("WebserviceDevice::ListDevicesDetails(): found %d devices of user '%s'", count($devices), $user));
        ZPush::GetTopCollector()->AnnounceInformation(sprintf("Retrieved details of %d devices", count($devices)), true);

        foreach ($devices as $devid)
            $output[] = ZPushAdmin::GetDeviceDetails($devid, $user);

        return $output;
    }

    /**
     * Remove all state data for a device of the Request::GetGETUser()
     *
     * @param string    $deviceId       the device id
     *
     * @access public
     * @return boolean
     * @throws SoapFault
     */
    public function RemoveDevice($deviceId) {
        $deviceId = preg_replace("/[^A-Za-z0-9]/", "", $deviceId);
        ZLog::Write(LOGLEVEL_INFO, sprintf("WebserviceDevice::RemoveDevice('%s'): remove device state data of user '%s'", $deviceId, Request::GetGETUser()));

        if (! ZPushAdmin::RemoveDevice(Request::GetGETUser(), $deviceId)) {
            ZPush::GetTopCollector()->AnnounceInformation(ZLog::GetLastMessage(LOGLEVEL_ERROR), true);
            throw new SoapFault("ERROR", ZLog::GetLastMessage(LOGLEVEL_ERROR));
        }

        ZPush::GetTopCollector()->AnnounceInformation(sprintf("Removed device id '%s'", $deviceId), true);
        return true;
    }

    /**
     * Marks a device of the Request::GetGETUser() to be remotely wiped
     *
     * @param string    $deviceId       the device id
     *
     * @access public
     * @return boolean
     * @throws SoapFault
     */
    public function WipeDevice($deviceId) {
        $deviceId = preg_replace("/[^A-Za-z0-9]/", "", $deviceId);
        ZLog::Write(LOGLEVEL_INFO, sprintf("WebserviceDevice::WipeDevice('%s'): mark device of user '%s' for remote wipe", $deviceId, Request::GetGETUser()));

        if (! ZPushAdmin::WipeDevice(Request::GetAuthUser(), Request::GetGETUser(), $deviceId)) {
            ZPush::GetTopCollector()->AnnounceInformation(ZLog::GetLastMessage(LOGLEVEL_ERROR), true);
            throw new SoapFault("ERROR", ZLog::GetLastMessage(LOGLEVEL_ERROR));
        }

        ZPush::GetTopCollector()->AnnounceInformation(sprintf("Wipe requested - device id '%s'", $deviceId), true);
        return true;
    }

    /**
     * Marks a a device of the Request::GetGETUser() for resynchronization
     *
     * @param string    $deviceId       the device id
     *
     * @access public
     * @return boolean
     * @throws SoapFault
     */
    public function ResyncDevice($deviceId) {
        $deviceId = preg_replace("/[^A-Za-z0-9]/", "", $deviceId);
        ZLog::Write(LOGLEVEL_INFO, sprintf("WebserviceDevice::ResyncDevice('%s'): mark device of user '%s' for resynchronization", $deviceId, Request::GetGETUser()));

        if (! ZPushAdmin::ResyncDevice(Request::GetGETUser(), $deviceId)) {
            ZPush::GetTopCollector()->AnnounceInformation(ZLog::GetLastMessage(LOGLEVEL_ERROR), true);
            throw new SoapFault("ERROR", ZLog::GetLastMessage(LOGLEVEL_ERROR));
        }

        ZPush::GetTopCollector()->AnnounceInformation(sprintf("Resync requested - device id '%s'", $deviceId), true);
        return true;
    }
}
?>