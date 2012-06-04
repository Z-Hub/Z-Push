<?php
/***********************************************
* File      :   istatemachine.php
* Project   :   Z-Push
* Descr     :   Interface called from the Device and
*               StateManager to save states for a user/device/folder.
 *              Z-Push implements the FileStateMachine which
 *              saves states to disk.
 *              Backends provide their own IStateMachine
                implementation of this interface and return
 *              an IStateMachine instance with IBackend->GetStateMachine().
 *              Old sync states are not deleted until a new sync state
 *              is requested.
 *              At that moment, the PIM is apparently requesting an update
 *              since sync key X, so any sync states before X are already on
 *              the PIM, and can therefore be removed. This algorithm should be
 *              automatically enforced by the IStateMachine implementation.
*
* Created   :   02.01.2012
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

interface IStateMachine {
    const DEFTYPE = "";
    const DEVICEDATA = "devicedata";
    const FOLDERDATA = "fd";
    const FAILSAVE = "fs";
    const HIERARCHY = "hc";
    const BACKENDSTORAGE = "bs";

    /**
     * Constructor
     * @throws FatalMisconfigurationException
     */

    /**
     * Gets a hash value indicating the latest dataset of the named
     * state with a specified key and counter.
     * If the state is changed between two calls of this method
     * the returned hash should be different
     *
     * @param string    $devid              the device id
     * @param string    $type               the state type
     * @param string    $key                (opt)
     * @param string    $counter            (opt)
     *
     * @access public
     * @return string
     * @throws StateNotFoundException, StateInvalidException
     */
    public function GetStateHash($devid, $type, $key = false, $counter = false);

    /**
     * Gets a state for a specified key and counter.
     * This method sould call IStateMachine->CleanStates()
     * to remove older states (same key, previous counters)
     *
     * @param string    $devid              the device id
     * @param string    $type               the state type
     * @param string    $key                (opt)
     * @param string    $counter            (opt)
     * @param string    $cleanstates        (opt)
     *
     * @access public
     * @return mixed
     * @throws StateNotFoundException, StateInvalidException
     */
    public function GetState($devid, $type, $key = false, $counter = false, $cleanstates = true);

    /**
     * Writes ta state to for a key and counter
     *
     * @param mixed     $state
     * @param string    $devid              the device id
     * @param string    $type               the state type
     * @param string    $key                (opt)
     * @param int       $counter            (opt)
     *
     * @access public
     * @return boolean
     * @throws StateInvalidException
     */
    public function SetState($state, $devid, $type, $key = false, $counter = false);

    /**
     * Cleans up all older states
     * If called with a $counter, all states previous state counter can be removed
     * If called without $counter, all keys (independently from the counter) can be removed
     *
     * @param string    $devid              the device id
     * @param string    $type               the state type
     * @param string    $key
     * @param string    $counter            (opt)
     *
     * @access public
     * @return
     * @throws StateInvalidException
     */
    public function CleanStates($devid, $type, $key, $counter = false);

    /**
     * Links a user to a device
     *
     * @param string    $username
     * @param string    $devid
     *
     * @access public
     * @return array
     */
    public function LinkUserDevice($username, $devid);

   /**
     * Unlinks a device from a user
     *
     * @param string    $username
     * @param string    $devid
     *
     * @access public
     * @return array
     */
    public function UnLinkUserDevice($username, $devid);

    /**
     * Returns an array with all device ids for a user.
     * If no user is set, all device ids should be returned
     *
     * @param string    $username   (opt)
     *
     * @access public
     * @return array
     */
    public function GetAllDevices($username = false);
}

?>