<?php
/***********************************************
* File      :   filestatemachine.php
* Project   :   Z-Push
* Descr     :   This class handles state requests;
*               Each Import/Export mechanism can
*               store its own state information,
*               which is stored through the
*               state machine.
*
* Created   :   01.10.2007
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

class FileStateMachine implements IStateMachine {
    private $userfilename;

    /**
     * Constructor
     *
     * Performs some basic checks and initilizes the state directory
     *
     * @access public
     * @throws FatalMisconfigurationException
     */
    public function FileStateMachine() {
        if (!defined('STATE_DIR'))
            throw new FatalMisconfigurationException("No configuration for the state directory available.");

        if (substr(STATE_DIR, -1,1) != "/")
            throw new FatalMisconfigurationException("The configured state directory should terminate with a '/'");

        if (!file_exists(STATE_DIR))
            throw new FatalMisconfigurationException("The configured state directory does not exist or can not be accessed: ". STATE_DIR);
        // checks if the directory exists and tries to create the necessary subfolders if they do not exist
        $this->getDirectoryForDevice(Request::GetDeviceID());
        $this->userfilename = STATE_DIR . 'users';

        if (!touch($this->userfilename))
            throw new FatalMisconfigurationException("Not possible to write to the configured state directory.");
    }

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
    public function GetStateHash($devid, $type, $key = false, $counter = false) {
        $filename = $this->getFullFilePath($devid, $type, $key, $counter);

        // the filemodification time is enough to track changes
        if(file_exists($filename))
            return filemtime($filename);
        else
            throw new StateNotFoundException(sprintf("FileStateMachine->GetStateHash(): Could not locate state '%s'",$filename));
    }

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
    public function GetState($devid, $type, $key = false, $counter = false, $cleanstates = true) {
        if ($counter && $cleanstates)
            $this->CleanStates($devid, $type, $key, $counter);

        // Read current sync state
        $filename = $this->getFullFilePath($devid, $type, $key, $counter);

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("FileStateMachine->GetState() on file: '%s'", $filename));

        if(file_exists($filename)) {
            return unserialize(file_get_contents($filename));
        }
        // throw an exception on all other states, but not FAILSAVE as it's most of the times not there by default
        else if ($type !== IStateMachine::FAILSAVE)
            throw new StateNotFoundException(sprintf("FileStateMachine->GetState(): Could not locate state '%s'",$filename));
    }

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
    public function SetState($state, $devid, $type, $key = false, $counter = false) {
        $state = serialize($state);

        $filename = $this->getFullFilePath($devid, $type, $key, $counter);
        if (($bytes = file_put_contents($filename, $state)) === false)
            throw new FatalMisconfigurationException(sprintf("FileStateMachine->SetState(): Could not write state '%s'",$filename));

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("FileStateMachine->SetState() written %d bytes on file: '%s'", $bytes, $filename));
        return $bytes;
    }

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
    public function CleanStates($devid, $type, $key, $counter = false) {
        $matching_files = glob($this->getFullFilePath($devid, $type, $key). "*", GLOB_NOSORT);
        if (is_array($matching_files)) {
            foreach($matching_files as $state) {
                $file = false;
                if($counter !== false && preg_match('/([0-9]+)$/', $state, $matches)) {
                    if($matches[1] < $counter) {
                        $candidate = $this->getFullFilePath($devid, $type, $key, (int)$matches[1]);

                        if ($candidate == $state)
                            $file = $candidate;
                    }
                }
                else if ($counter === false)
                    $file =  $this->getFullFilePath($devid, $type, $key);

                if ($file !== false) {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("FileStateMachine->CleanStates(): Deleting file: '%s'", $file));
                    unlink ($file);
                }
            }
        }
    }

    /**
     * Links a user to a device
     *
     * @param string    $username
     * @param string    $devid
     *
     * @access public
     * @return array
     */
    public function LinkUserDevice($username, $devid) {
        include_once("simplemutex.php");
        $mutex = new SimpleMutex();

        // exclusive block
        if ($mutex->Block()) {
            $filecontents = @file_get_contents($this->userfilename);

            if ($filecontents)
                $users = unserialize($filecontents);
            else
                $users = array();

            $changed = false;

            // add user/device to the list
            if (!isset($users[$username])) {
                $users[$username] = array();
                $changed = true;
            }
            if (!isset($users[$username][$devid])) {
                $users[$username][$devid] = 1;
                $changed = true;
            }

            if ($changed) {
                $bytes = file_put_contents($this->userfilename, serialize($users));
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("FileStateMachine->LinkUserDevice(): wrote %d bytes to users file", $bytes));
            }
            else
                ZLog::Write(LOGLEVEL_DEBUG, "FileStateMachine->LinkUserDevice(): nothing changed");

            $mutex->Release();
        }
    }

   /**
     * Unlinks a device from a user
     *
     * @param string    $username
     * @param string    $devid
     *
     * @access public
     * @return array
     */
    public function UnLinkUserDevice($username, $devid) {
        include_once("simplemutex.php");
        $mutex = new SimpleMutex();

        // exclusive block
        if ($mutex->Block()) {
            $filecontents = @file_get_contents($this->userfilename);

            if ($filecontents)
                $users = unserialize($filecontents);
            else
                $users = array();

            $changed = false;

            // is this user listed at all?
            if (isset($users[$username])) {
                if (isset($users[$username][$devid])) {
                    unset($users[$username][$devid]);
                    $changed = true;
                }

                // if there is no device left, remove the user
                if (empty($users[$username])) {
                    unset($users[$username]);
                    $changed = true;
                }
            }

            if ($changed) {
                $bytes = file_put_contents($this->userfilename, serialize($users));
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("FileStateMachine->UnLinkUserDevice(): wrote %d bytes to users file", $bytes));
            }
            else
                ZLog::Write(LOGLEVEL_DEBUG, "FileStateMachine->UnLinkUserDevice(): nothing changed");

            $mutex->Release();
        }
    }

    /**
     * Returns an array with all device ids for a user.
     * If no user is set, all device ids should be returned
     *
     * @param string    $username   (opt)
     *
     * @access public
     * @return array
     */
    public function GetAllDevices($username = false) {
        $out = array();
        if ($username === false) {
            foreach (glob(STATE_DIR. "/*/*/*-".IStateMachine::DEVICEDATA, GLOB_NOSORT) as $devdata)
                if (preg_match('/\/([A-Za-z0-9]+)-'. IStateMachine::DEVICEDATA. '$/', $devdata, $matches))
                    $out[] = $matches[1];
            return $out;
        }
        else {
            $filecontents = file_get_contents($this->userfilename);
            if ($filecontents)
                $users = unserialize($filecontents);
            else
                $users = array();

            // get device list for the user
            if (isset($users[$username]))
                return array_keys($users[$username]);
            else
                return array();
        }
    }


    /**----------------------------------------------------------------------------------------------------------
     * Private FileStateMachine stuff
     */

    /**
     * Returns the full path incl. filename for a key (generally uuid) and a counter
     *
     * @param string    $devid              the device id
     * @param string    $type               the state type
     * @param string    $key                (opt)
     * @param string    $counter            (opt) default false
     * @param boolean   $doNotCreateDirs    (opt) indicates if missing subdirectories should be created, default false
     *
     * @access private
     * @return string
     * @throws StateInvalidException
     */
    private function getFullFilePath($devid, $type, $key = false, $counter = false, $doNotCreateDirs = false) {
        $testkey = $devid . (($key !== false)? "-". $key : "") . (($type !== "")? "-". $type : "");
        if (preg_match('/^[a-zA-Z0-9-]+$/', $testkey, $matches) || ($type == "" && $key === false))
            $internkey = $testkey . (($counter && is_int($counter))?"-".$counter:"");
        else
            throw new StateInvalidException("FileStateMachine->getFullFilePath(): Invalid state deviceid, type, key or in any combination");

        return $this->getDirectoryForDevice($devid, $doNotCreateDirs) ."/". $internkey;
    }

    /**
     * Checks if the configured path exists and if a subfolder structure is available
     *  A two level deep subdirectory structure is build to save the states.
     *  The subdirectories where to save, are determined with device id
     *
     * @param string    $devid                  the device id
     * @param boolen    $doNotCreateDirs        (opt) by default false - indicates if the subdirs should be created
     *
     * @access private
     * @return string/boolean                   returns the full directory of false if the dirs can not be created
     * @throws FatalMisconfigurationException   when configured directory is not writeable
     */
    private function getDirectoryForDevice($devid, $doNotCreateDirs = false) {
        $firstLevel = substr(strtolower($devid), -1, 1);
        $secondLevel = substr(strtolower($devid), -2, 1);

        $dir = STATE_DIR . $firstLevel . "/" . $secondLevel;
        if (is_dir($dir))
            return $dir;

        if ($doNotCreateDirs === false) {
            // try to create the subdirectory structure necessary
            $fldir = STATE_DIR . $firstLevel;
            if (!is_dir($fldir)) {
                $dirOK = mkdir($fldir);
                if (!$dirOK)
                    throw new FatalMisconfigurationException("FileStateMachine->getDirectoryForDevice(): Not possible to create state sub-directory: ". $fldir);
            }

            if (!is_dir($dir)) {
                $dirOK = mkdir($dir);
                if (!$dirOK)
                    throw new FatalMisconfigurationException("FileStateMachine->getDirectoryForDevice(): Not possible to create state sub-directory: ". $dir);
            }
            else
                return $dir;
        }
        return false;
    }

}
?>