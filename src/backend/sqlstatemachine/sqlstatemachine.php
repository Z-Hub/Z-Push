<?php
/***********************************************
* File      :   sqlstatemachine.php
* Project   :   Z-Push
* Descr     :   This class handles state requests;
*               Each Import/Export mechanism can
*               store its own state information,
*               which is stored through the
*               state machine.
*
* Created   :   25.08.2013
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

//include the SqlStateMachine's own config file
require_once("backend/sqlstatemachine/config.php");

class SqlStateMachine implements IStateMachine {
    const SUPPORTED_STATE_VERSION = IStateMachine::STATEVERSION_02;
    const VERSION = "version";

    const UNKNOWNDATABASE = 1049;
    const CREATETABLE_ZPUSH_SETTINGS = "CREATE TABLE IF NOT EXISTS zpush_settings (key_name VARCHAR(50) NOT NULL, key_value VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (key_name));";
    const CREATETABLE_ZPUSH_USERS = "CREATE TABLE IF NOT EXISTS zpush_users (username VARCHAR(50) NOT NULL, device_id VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (username, device_id));";
    const CREATETABLE_ZPUSH_STATES = "CREATE TABLE IF NOT EXISTS zpush_states (id_state INTEGER AUTO_INCREMENT, device_id VARCHAR(50) NOT NULL, uuid VARCHAR(50), state_type VARCHAR(50), counter INTEGER, state_data MEDIUMBLOB, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (id_state));";
    const CREATEINDEX_ZPUSH_STATES = "CREATE UNIQUE INDEX idx_zpush_states_unique ON zpush_states (device_id, uuid, state_type, counter);";

    private $dbh;
    private $options;

    /**
     * Constructor
     *
     * Performs some basic checks and initializes the state directory.
     *
     * @access public
     * @throws FatalMisconfigurationException
     */
    public function __construct() {
        ZLog::Write(LOGLEVEL_DEBUG, "SqlStateMachine(): init");

        if (!trim(STATE_SQL_SERVER) || !trim(STATE_SQL_PORT) || !trim(STATE_SQL_DATABASE) || !trim(STATE_SQL_USER)) {
            throw new FatalMisconfigurationException("Missing configuration for the state sql. Check STATE_SQL_* values in the config.php.");
        }

        $this->options = array();
        if (trim(STATE_SQL_OPTIONS)) {
            $this->options = unserialize(STATE_SQL_OPTIONS);
        }

        // check if the database and necessary tables exist and try to create them if necessary.
        try {
            $this->checkDbAndTables();
        }
        catch(PDOException $ex) {
            throw new FatalMisconfigurationException(sprintf("Not possible to connect to the state database: %s", $ex->getMessage()));
        }

        $this->clearConnection($this->dbh);
    }

    /**
     * Gets a hash value indicating the latest dataset of the named
     * state with a specified key and counter.
     * If the state is changed between two calls of this method
     * the returned hash should be different.
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
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->GetStateHash(): '%s', '%s', '%s', '%s'", $devid, $type, $key, $counter));

        $sql = "select updated_at from zpush_states where device_id = :devid and state_type = :type and uuid = :key and counter = :counter";
        $params = $this->getParams($devid, $type, $key, $counter);

        $hash = null;
        $sth = null;
        $record = null;
        $dsn = sprintf("%s:host=%s;port=%s;dbname=%s", STATE_SQL_ENGINE, STATE_SQL_SERVER, STATE_SQL_PORT, STATE_SQL_DATABASE);
        try {
            $this->dbh = new PDO($dsn, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);

            $sth = $this->dbh->prepare($sql);
            $sth->execute($params);

            $record = $sth->fetch(PDO::FETCH_ASSOC);
            if (!$record) {
                $this->clearConnection($this->dbh, $sth, $record);
                throw new StateNotFoundException(sprintf("SqlStateMachine->GetStateHash(): Could not locate state"));
            }
            else {
                // datetime->format("U") returns EPOCH
                $datetime = new DateTime($record["updated_at"]);
                $hash = $datetime->format("U");
            }
        }
        catch(PDOException $ex) {
            $this->clearConnection($this->dbh, $sth, $record);
            throw new StateNotFoundException(sprintf("SqlStateMachine->GetStateHash(): Could not locate state: %s", $ex->getMessage()));
        }

        $this->clearConnection($this->dbh, $sth, $record);

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->GetStateHash(): return '%s'", $hash));

        return $hash;
    }

    /**
     * Gets a state for a specified key and counter.
     * This method sould call IStateMachine->CleanStates()
     * to remove older states (same key, previous counters).
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
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->GetState(): '%s', '%s', '%s', '%s', '%s'", $devid, $type, $key, $counter, $cleanstates));
        if ($counter && $cleanstates)
            $this->CleanStates($devid, $type, $key, $counter);

        $sql = "select state_data from zpush_states where device_id = :devid and state_type = :type and uuid = :key and counter = :counter";
        $params = $this->getParams($devid, $type, $key, $counter);

        $data = null;
        $sth = null;
        $record = null;
        $dsn = sprintf("%s:host=%s;port=%s;dbname=%s", STATE_SQL_ENGINE, STATE_SQL_SERVER, STATE_SQL_PORT, STATE_SQL_DATABASE);
        try {
            $this->dbh = new PDO($dsn, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);

            $sth = $this->dbh->prepare($sql);
            $sth->execute($params);

            $record = $sth->fetch(PDO::FETCH_ASSOC);
            if (!$record) {
                $this->clearConnection($this->dbh, $sth, $record);
                // throw an exception on all other states, but not FAILSAVE as it's most of the times not there by default
                if ($type !== IStateMachine::FAILSAVE) {
                    throw new StateNotFoundException(sprintf("SqlStateMachine->GetState(): Could not locate state"));
                }
            }
            else {
                if (is_string($record["state_data"])) {
                    // MySQL-PDO returns a string for LOB objects
                    $data = unserialize($record["state_data"]);
                }
                else {
                    $data = unserialize(stream_get_contents($record["state_data"]));
                }
            }
        }
        catch(PDOException $ex) {
            $this->clearConnection($this->dbh, $sth, $record);
            throw new StateNotFoundException(sprintf("SqlStateMachine->GetState(): Could not locate state: %s", $ex->getMessage()));
        }

        $this->clearConnection($this->dbh, $sth, $record);

        return $data;
    }

    /**
     * Writes ta state to for a key and counter.
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
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->SetState(): '%s', '%s', '%s', '%s'", $devid, $type, $key, $counter));

        $sql = "select device_id from zpush_states where device_id = :devid and state_type = :type and uuid = :key and counter = :counter";
        $params = $this->getParams($devid, $type, $key, $counter);

        $sth = null;
        $record = null;
        $bytes = 0;
        $dsn = sprintf("%s:host=%s;port=%s;dbname=%s", STATE_SQL_ENGINE, STATE_SQL_SERVER, STATE_SQL_PORT, STATE_SQL_DATABASE);

        try {
            $this->dbh = new PDO($dsn, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);

            $sth = $this->dbh->prepare($sql);
            $sth->execute($params);

            $record = $sth->fetch(PDO::FETCH_ASSOC);
            if (!$record) {
                // New record
                $sql = "insert into zpush_states (device_id, state_type, uuid, counter, state_data, created_at, updated_at) values (:devid, :type, :key, :counter, :data, :created_at, :updated_at)";

                $sth = $this->dbh->prepare($sql);
                $sth->bindValue(":created_at", $this->getNow(), PDO::PARAM_STR);
            }
            else {
                // Existing record, we update it
                $sql = "update zpush_states set state_data = :data, updated_at = :updated_at where device_id = :devid and state_type = :type and uuid = :key and counter = :counter";

                $sth = $this->dbh->prepare($sql);
            }

            $sth->bindParam(":devid", $devid, PDO::PARAM_STR);
            $sth->bindParam(":type", $type, PDO::PARAM_STR);
            $sth->bindParam(":key", $key, PDO::PARAM_STR);
            $sth->bindValue(":counter", ($counter === false ? -1 : $counter), PDO::PARAM_INT);
            $sth->bindValue(":data", serialize($state), PDO::PARAM_LOB);
            $sth->bindValue(":updated_at", $this->getNow(), PDO::PARAM_STR);

            if (!$sth->execute() ) {
                $this->clearConnection($this->dbh, $sth);
                throw new FatalMisconfigurationException(sprintf("SqlStateMachine->SetState(): Could not write state"));
            }
            else {
                $bytes = strlen(serialize($state));
            }
        }
        catch(PDOException $ex) {
            $this->clearConnection($this->dbh, $sth);
            throw new FatalMisconfigurationException(sprintf("SqlStateMachine->SetState(): Could not write state: %s", $ex->getMessage()));
        }

        $this->clearConnection($this->dbh, $sth, $record);

        return $bytes;
    }

    /**
     * Cleans up all older states.
     * If called with a $counter, all states previous state counter can be removed.
     * If called without $counter, all keys (independently from the counter) can be removed.
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
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->CleanStates(): '%s', '%s', '%s', '%s'", $devid, $type, $key, $counter));


        if ($counter === false) {
            // Remove all the states. Counter are -1 or > 0, then deleting >= -1 deletes all
            $sql = "delete from zpush_states where device_id = :devid and state_type = :type and uuid = :key and counter >= :counter";
        }
        else {
            $sql = "delete from zpush_states where device_id = :devid and state_type = :type and uuid = :key and counter < :counter";
        }
        $params = $this->getParams($devid, $type, $key, $counter);

        $sth = null;
        $dsn = sprintf("%s:host=%s;port=%s;dbname=%s", STATE_SQL_ENGINE, STATE_SQL_SERVER, STATE_SQL_PORT, STATE_SQL_DATABASE);
        try {
            $this->dbh = new PDO($dsn, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);

            $sth = $this->dbh->prepare($sql);
            $sth->execute($params);
        }
        catch(PDOException $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->CleanStates(): Error deleting states: %s", $ex->getMessage()));
        }

        $this->clearConnection($this->dbh, $sth, $record);
    }

    /**
     * Links a user to a device.
     *
     * @param string    $username
     * @param string    $devid
     *
     * @access public
     * @return boolean     indicating if the user was added or not (existed already)
     */
    public function LinkUserDevice($username, $devid) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->LinkUserDevice(): '%s', '%s'", $username, $devid));

        $sth = null;
        $record = null;
        $changed = false;
        $dsn = sprintf("%s:host=%s;port=%s;dbname=%s", STATE_SQL_ENGINE, STATE_SQL_SERVER, STATE_SQL_PORT, STATE_SQL_DATABASE);
        try {
            $this->dbh = new PDO($dsn, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);

            $sql = "select username from zpush_users where username = :username and device_id = :devid";
            $params = array(":username" => $username, ":devid" => $devid);

            $sth = $this->dbh->prepare($sql);
            $sth->execute($params);

            $record = $sth->fetch(PDO::FETCH_ASSOC);
            if ($record) {
                ZLog::Write(LOGLEVEL_DEBUG, "SqlStateMachine->LinkUserDevice(): nothing changed");
            }
            else {
                $sth = null;
                $sql = "insert into zpush_users (username, device_id, created_at, updated_at) values (:username, :devid, :created_at, :updated_at)";
                $params[":created_at"] = $params[":updated_at"] = $this->getNow();
                $sth = $this->dbh->prepare($sql);
                if ($sth->execute($params)) {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->LinkUserDevice(): Linked user-device: '%s' '%s'", $username, $devid));
                    $changed = true;
                }
                else {
                    ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->LinkUserDevice(): Unable to link user-device"));
                }
            }
        }
        catch(PDOException $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->LinkUserDevice(): Error linking user-device: %s", $ex->getMessage()));
        }

        $this->clearConnection($this->dbh, $sth, $record);

        return $changed;
    }

   /**
     * Unlinks a device from a user.
     *
     * @param string    $username
     * @param string    $devid
     *
     * @access public
     * @return boolean
     */
    public function UnLinkUserDevice($username, $devid) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->UnLinkUserDevice(): '%s', '%s'", $username, $devid));

        $sth = null;
        $changed = false;
        $dsn = sprintf("%s:host=%s;port=%s;dbname=%s", STATE_SQL_ENGINE, STATE_SQL_SERVER, STATE_SQL_PORT, STATE_SQL_DATABASE);
        try {
            $this->dbh = new PDO($dsn, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);

            $sql = "delete from zpush_users where username = :username and device_id = :devid";
            $params = array(":username" => $username, ":devid" => $devid);

            $sth = $this->dbh->prepare($sql);
            if ($sth->execute($params)) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->UnLinkUserDevice(): Unlinked user-device: '%s' '%s'", $username, $devid));
                $changed = true;
            }
            else {
                ZLog::Write(LOGLEVEL_DEBUG, "SqlStateMachine->UnLinkUserDevice(): nothing changed");
            }
        }
        catch(PDOException $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->UnLinkUserDevice(): Error unlinking user-device: %s", $ex->getMessage()));
        }

        $this->clearConnection($this->dbh, $sth);

        return $changed;
    }

    /**
     * Get all UserDevice mapping.
     *
     * @access public
     * @return array
     */
    public function GetAllUserDevice() {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->GetAllUserDevice(): '%s'", $username));

        $sth = null;
        $record = null;
        $out = array();
        $dsn = sprintf("%s:host=%s;port=%s;dbname=%s", STATE_SQL_ENGINE, STATE_SQL_SERVER, STATE_SQL_PORT, STATE_SQL_DATABASE);
        try {
            $this->dbh = new PDO($dsn, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);

            $sql = "select device_id, username from zpush_users order by username";
            $sth = $this->dbh->prepare($sql);
            $sth->execute();

            while ($record = $sth->fetch(PDO::FETCH_ASSOC)) {
                if (!array_key_exists($record["username"], $out)) {
                    $out[$record["username"]] = array();
                }
                $out[$record["username"]][] = $record["device_id"];
            }
        }
        catch(PDOException $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->GetAllUserDevice(): Error listing devices: %s", $ex->getMessage()));
        }

        $this->clearConnection($this->dbh, $sth, $record);

        return $out;
    }

    /**
     * Returns an array with all device ids for a user.
     * If no user is set, all device ids should be returned.
     *
     * @param string    $username   (opt)
     *
     * @access public
     * @return array
     */
    public function GetAllDevices($username = false) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->GetAllDevices(): '%s'", $username));

        $sth = null;
        $record = null;
        $out = array();
        $dsn = sprintf("%s:host=%s;port=%s;dbname=%s", STATE_SQL_ENGINE, STATE_SQL_SERVER, STATE_SQL_PORT, STATE_SQL_DATABASE);
        try {
            $this->dbh = new PDO($dsn, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);

            if  ($username === false) {
                $sql = "select distinct(device_id) from zpush_users order by device_id";
                $params = array();
            }
            else {
                $sql = "select device_id from zpush_users where username = :username order by device_id";
                $params = array(":username" => $username);
            }
            $sth = $this->dbh->prepare($sql);
            $sth->execute($params);

            while ($record = $sth->fetch(PDO::FETCH_ASSOC)) {
                $out[] = $record["device_id"];
            }
        }
        catch(PDOException $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->GetAllDevices(): Error listing devices: %s", $ex->getMessage()));
        }

        $this->clearConnection($this->dbh, $sth, $record);

        return $out;
    }

    /**
     * Returns the current version of the state files.
     *
     * @access public
     * @return int
     */
    public function GetStateVersion() {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->GetStateVersion()"));

        $sth = null;
        $record = null;
        $version = IStateMachine::STATEVERSION_01;
        $dsn = sprintf("%s:host=%s;port=%s;dbname=%s", STATE_SQL_ENGINE, STATE_SQL_SERVER, STATE_SQL_PORT, STATE_SQL_DATABASE);
        try {
            $this->dbh = new PDO($dsn, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);

            $sql = "select key_value from zpush_settings where key_name = :key_name";
            $params = array(":key_name" => self::VERSION);

            $sth = $this->dbh->prepare($sql);
            $sth->execute($params);

            $record = $sth->fetch(PDO::FETCH_ASSOC);
            if ($record) {
                $version = $record["key_value"];
            }
            else {
                $this->SetStateVersion(self::SUPPORTED_STATE_VERSION);
                $version = self::SUPPORTED_STATE_VERSION;
            }
        }
        catch(PDOException $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->GetStateVersion(): Error getting state version: %s", $ex->getMessage()));
        }

        $this->clearConnection($this->dbh, $sth, $record);

        return $version;
    }

    /**
     * Sets the current version of the state files.
     *
     * @param int       $version            the new supported version
     *
     * @access public
     * @return boolean
     */
    public function SetStateVersion($version) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->SetStateVersion(): '%s'", $version));

        $sth = null;
        $record = null;
        $status = false;
        $dsn = sprintf("%s:host=%s;port=%s;dbname=%s", STATE_SQL_ENGINE, STATE_SQL_SERVER, STATE_SQL_PORT, STATE_SQL_DATABASE);
        try {
            $this->dbh = new PDO($dsn, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);

            $sql = "select key_value from zpush_settings where key_name = :key_name";
            $params = array(":key_name" => self::VERSION);

            $sth = $this->dbh->prepare($sql);
            $sth->execute($params);

            $record = $sth->fetch(PDO::FETCH_ASSOC);
            if ($record) {
                $sth = null;
                $sql = "update zpush_settings set key_value = :value, updated_at = :updated_at where key_name = :key_name";
                $params[":value"] = $version;
                $params[":updated_at"] = $this->getNow();

                $sth = $this->dbh->prepare($sql);
                if ($sth->execute($params)) {
                    $status = true;
                }
            }
            else {
                $sth = null;
                $sql = "insert into zpush_settings (key_name, key_value, created_at, updated_at) values (:key_name, :value, :created_at, :updated_at)";
                $params[":value"] = $version;
                $params[":updated_at"] = $params[":created_at"] = $this->getNow();

                $sth = $this->dbh->prepare($sql);
                if ($sth->execute($params)) {
                    $status = true;
                }
            }
        }
        catch(PDOException $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->SetStateVersion(): Error saving state version: %s", $ex->getMessage()));
        }

        $this->clearConnection($this->dbh, $sth, $record);

        return $status;
    }

    /**
     * Returns all available states for a device id.
     *
     * @param string    $devid              the device id
     *
     * @access public
     * @return array(mixed)
     */
    public function GetAllStatesForDevice($devid) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->GetAllStatesForDevice(): '%s'", $devid));

        $sth = null;
        $record = null;
        $out = array();
        $dsn = sprintf("%s:host=%s;port=%s;dbname=%s", STATE_SQL_ENGINE, STATE_SQL_SERVER, STATE_SQL_PORT, STATE_SQL_DATABASE);
        try {
            $this->dbh = new PDO($dsn, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);

            $sql = "select state_type, uuid, counter from zpush_states where device_id = :devid order by id_state";
            $params = array(":devid" => $devid);

            $sth = $this->dbh->prepare($sql);
            $sth->execute($params);

            while ($record = $sth->fetch(PDO::FETCH_ASSOC)) {
                $state = array('type' => false, 'counter' => false, 'uuid' => false);
                if ($record["state_type"] !== null && strlen($record["state_type"]) > 0) {
                    $state["type"] = $record["state_type"];
                }
                else {
                    if ($record["counter"] !== null && is_numeric($record["counter"])) {
                        $state["type"] = "";
                    }
                }
                if ($record["counter"] !== null && strlen($record["counter"]) > 0) {
                    $state["counter"] = $record["counter"];
                }
                if ($record["uuid"] !== null && strlen($record["uuid"]) > 0) {
                    $state["uuid"] = $record["uuid"];
                }
                $out[] = $state;
            }
        }
        catch(PDOException $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->GetAllStatesForDevice(): Error listing states: %s", $ex->getMessage()));
        }

        $this->clearConnection($this->dbh, $sth, $record);

        return $out;
    }

    /**
     * Return if the User-Device has permission to sync against this Z-Push.
     *
     * @param string $user          Username
     * @param string $devid         DeviceId
     *
     * @access public
     * @return integer
     */
    public function GetUserDevicePermission($user, $devid) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->GetUserDevicePermission('%s', '%s')", $user, $devid));

        $status = SYNC_COMMONSTATUS_SUCCESS;

        $userExist = false;
        $userBlocked = false;
        $deviceExist = false;
        $deviceBlocked = false;

        // Android PROVISIONING initial step
            // LG-D802 is sending an empty deviceid
        if ($devid != "validate" && $devid != "") {

            $sth = null;
            $record = null;
            $dsn = sprintf("%s:host=%s;port=%s;dbname=%s", STATE_SQL_ENGINE, STATE_SQL_SERVER, STATE_SQL_PORT, STATE_SQL_DATABASE);
            try {
                $this->dbh = new PDO($dsn, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);

                $sql = "select count(*) as pcount from zpush_preauth_users where username = :user and device_id != 'authorized' and authorized = 1";
                $params = array(":user" => $user);

                // Get number of authorized devices for user
                $num_devid_user = 0;
                $sth = $this->dbh->prepare($sql);
                $sth->execute($params);
                if ($record = $sth->fetch(PDO::FETCH_ASSOC)) {
                    $num_devid_user = $record["pcount"];
                }
                $record = null;
                $sth = null;

                $sql = "select authorized from zpush_preauth_users where username = :user and device_id = :devid";
                $params = array(":user" => $user, ":devid" => "authorized");
                $paramsNewDevid = array();
                $paramsNewUser = array();

                $sth = $this->dbh->prepare($sql);
                $sth->execute($params);
                if ($record = $sth->fetch(PDO::FETCH_ASSOC)) {
                    $userExist = true;
                    $userBlocked = !$record["authorized"];
                }
                $record = null;
                $sth = null;

                if ($userExist) {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->GetUserDevicePermission(): User '%s', already pre-authorized", $user));

                    // User could be blocked if a "authorized" device exist and it's false
                    if ($userBlocked) {
                        $status = SYNC_COMMONSTATUS_USERDISABLEDFORSYNC;
                        ZLog::Write(LOGLEVEL_INFO, sprintf("SqlStateMachine->GetUserDevicePermission(): Blocked user '%s', tried '%s'", $user, $devid));
                    }
                    else {
                        $params[":devid"] = $devid;

                        $sth = $this->dbh->prepare($sql);
                        $sth->execute($params);
                        if ($record = $sth->fetch(PDO::FETCH_ASSOC)) {
                            $deviceExist = true;
                            $deviceBlocked = !$record["authorized"];
                        }
                        $record = null;
                        $sth = null;

                        if ($deviceExist) {
                            // Device pre-authorized found

                            if ($deviceBlocked) {
                                $status = SYNC_COMMONSTATUS_DEVICEBLOCKEDFORUSER;
                                ZLog::Write(LOGLEVEL_INFO, sprintf("SqlStateMachine->GetUserDevicePermission(): Blocked device '%s' for user '%s'", $devid, $user));
                            }
                            else {
                                ZLog::Write(LOGLEVEL_INFO, sprintf("SqlStateMachine->GetUserDevicePermission(): Pre-authorized device '%s' for user '%s'", $devid, $user));
                            }
                        }
                        else {
                            // Device not pre-authorized

                            if (defined('PRE_AUTHORIZE_NEW_DEVICES') && PRE_AUTHORIZE_NEW_DEVICES === true) {
                                if (defined('PRE_AUTHORIZE_MAX_DEVICES') && PRE_AUTHORIZE_MAX_DEVICES > $num_devid_user) {
                                    $paramsNewDevid[":auth"] = true;
                                    ZLog::Write(LOGLEVEL_INFO, sprintf("SqlStateMachine->GetUserDevicePermission(): Pre-authorized new device '%s' for user '%s'", $devid, $user));
                                }
                                else {
                                    $status = SYNC_COMMONSTATUS_MAXDEVICESREACHED;
                                    ZLog::Write(LOGLEVEL_INFO, sprintf("SqlStateMachine->GetUserDevicePermission(): Max number of devices reached for user '%s', tried '%s'", $user, $devid));
                                }
                            }
                            else {
                                $status = SYNC_COMMONSTATUS_DEVICEBLOCKEDFORUSER;
                                $paramsNewDevid[":auth"] = false;
                                ZLog::Write(LOGLEVEL_INFO, sprintf("SqlStateMachine->GetUserDevicePermission(): Blocked new device '%s' for user '%s'", $devid, $user));
                            }
                        }
                    }
                }
                else {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->GetUserDevicePermission(): User '%s', not pre-authorized", $user));

                    if (defined('PRE_AUTHORIZE_NEW_USERS') && PRE_AUTHORIZE_NEW_USERS === true) {
                        $paramsNewUser[":auth"] = true;
                        if (defined('PRE_AUTHORIZE_NEW_DEVICES') && PRE_AUTHORIZE_NEW_DEVICES === true) {
                            if (defined('PRE_AUTHORIZE_MAX_DEVICES') && PRE_AUTHORIZE_MAX_DEVICES > $num_devid_user) {
                                $paramsNewDevid[":auth"] = true;
                                ZLog::Write(LOGLEVEL_INFO, sprintf("SqlStateMachine->GetUserDevicePermission(): Pre-authorized new device '%s' for new user '%s'", $devid, $user));
                            }
                            else {
                                $status = SYNC_COMMONSTATUS_MAXDEVICESREACHED;
                                ZLog::Write(LOGLEVEL_INFO, sprintf("SqlStateMachine->GetUserDevicePermission(): Max number of devices reached for user '%s', tried '%s'", $user, $devid));
                            }
                        }
                        else {
                            $status = SYNC_COMMONSTATUS_DEVICEBLOCKEDFORUSER;
                            $paramsNewDevid[":auth"] = false;
                            ZLog::Write(LOGLEVEL_INFO, sprintf("SqlStateMachine->GetUserDevicePermission(): Blocked new device '%s' for new user '%s'", $devid, $user));
                        }
                    }
                    else {
                        $status = SYNC_COMMONSTATUS_USERDISABLEDFORSYNC;
                        $paramsNewUser[":auth"] = false;
                        $paramsNewDevid[":auth"] = false;
                        ZLog::Write(LOGLEVEL_INFO, sprintf("SqlStateMachine->GetUserDevicePermission(): Blocked new user '%s' and device '%s'", $user, $devid));
                    }
                }

                if (count($paramsNewUser) > 0) {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->GetUserDevicePermission(): Creating new user '%s'", $user));

                    $sql = "insert into zpush_preauth_users (username, device_id, authorized, created_at, updated_at) values (:user, :devid, :auth, :created_at, :updated_at)";
                    $paramsNewUser[":user"] = $user;
                    $paramsNewUser[":devid"] = "authorized";
                    $paramsNewUser[":created_at"] = $paramsNewUser[":updated_at"] = $this->getNow();

                    $sth = $this->dbh->prepare($sql);
                    if (!$sth->execute($paramsNewUser)) {
                        ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->GetUserDevicePermission(): Error creating new user"));
                        $status = SYNC_COMMONSTATUS_USERDISABLEDFORSYNC;
                    }
                }

                if (count($paramsNewDevid) > 0) {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->GetUserDevicePermission(): Creating new device '%s' for user '%s'", $devid, $user));

                    $sql = "insert into zpush_preauth_users (username, device_id, authorized, created_at, updated_at) values (:user, :devid, :auth, :created_at, :updated_at)";
                    $paramsNewDevid[":user"] = $user;
                    $paramsNewDevid[":devid"] = $devid;
                    $paramsNewDevid[":created_at"] = $paramsNewDevid[":updated_at"] = $this->getNow();

                    $sth = $this->dbh->prepare($sql);
                    if (!$sth->execute($paramsNewDevid)) {
                        ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->GetUserDevicePermission(): Error creating user new device"));
                        $status = SYNC_COMMONSTATUS_USERDISABLEDFORSYNC;
                    }
                }
            }
            catch(PDOException $ex) {
                ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->GetUserDevicePermission(): Error checking permission for username '%s' device '%s': %s", $user, $devid, $ex->getMessage()));
                $status = SYNC_COMMONSTATUS_USERDISABLEDFORSYNC;
            }

            $this->clearConnection($this->dbh, $sth, $record);
        }

        return $status;
    }

    /**
     * Retrieves the mapped username for a specific username and backend.
     *
     * @param string $username The username to lookup
     * @param string $backend Name of the backend to lookup
     *
     * @return string The mapped username or null if none found
     */
    public function GetMappedUsername($username, $backend) {
        $result = null;

        $dsn = sprintf("%s:host=%s;port=%s;dbname=%s", STATE_SQL_ENGINE, STATE_SQL_SERVER, STATE_SQL_PORT, STATE_SQL_DATABASE);
        $this->dbh = new PDO($dsn, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);

        $sql = "SELECT `mappedname` FROM `zpush_combined_usermap` WHERE `username` = :user AND `backend` = :backend";
        $params = array("user" => $username, "backend" => $backend);
        $sth = $this->dbh->prepare($sql);
        if ($sth->execute($params) === false) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->GetMappedUsername(): Failed to execute query"));
        } else if ($record = $sth->fetch(PDO::FETCH_ASSOC)) {
            $result = $record["mappedname"];
        }

        $this->clearConnection($this->dbh, $sth, $record);

        return $result;
    }

    /**
     * Maps a username for a specific backend to another username.
     *
     * @param string $username The username to map
     * @param string $backend Name of the backend
     * @param string $mappedname The mappend username
     *
     * @return boolean
     */
    public function MapUsername($username, $backend, $mappedname) {
        $dsn = sprintf("%s:host=%s;port=%s;dbname=%s", STATE_SQL_ENGINE, STATE_SQL_SERVER, STATE_SQL_PORT, STATE_SQL_DATABASE);
        $this->dbh = new PDO($dsn, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);

        $sql = "
            INSERT INTO `zpush_combined_usermap` (`username`, `backend`, `mappedname`, `created_at`, `updated_at`)
            VALUES (:user, :backend, :mappedname, NOW(), NOW())
            ON DUPLICATE KEY UPDATE `mappedname` = :mappedname2, `updated_at` = NOW()
        ";
        $params = array("user" => $username, "backend" => $backend, "mappedname" => $mappedname, "mappedname2" => $mappedname);
        $sth = $this->dbh->prepare($sql);
        if ($sth->execute($params) === false) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->MapUsername(): Failed to execute query"));
            return false;
        }

        $this->clearConnection($this->dbh, $sth);
        return true;
    }

    /**
     * Unmaps a username for a specific backend.
     *
     * @param string $username The username to unmap
     * @param string $backend Name of the backend
     *
     * @return boolean
     */
    public function UnmapUsername($username, $backend) {
        $dsn = sprintf("%s:host=%s;port=%s;dbname=%s", STATE_SQL_ENGINE, STATE_SQL_SERVER, STATE_SQL_PORT, STATE_SQL_DATABASE);
        $this->dbh = new PDO($dsn, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);

        $sql = "DELETE FROM `zpush_combined_usermap` WHERE `username` = :user AND `backend` = :backend";
        $params = array("user" => $username, "backend" => $backend);
        $sth = $this->dbh->prepare($sql);
        if ($sth->execute($params) === false) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->UnmapUsername(): Failed to execute query"));
            return false;
        } else if ($sth->rowCount() !== 1) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("SqlStateMachine->MapUsername(): Invalid mapping of username and backend"));
            return false;
        }

        $this->clearConnection($this->dbh, $sth);
        return true;
    }

    /**----------------------------------------------------------------------------------------------------------
     * Private SqlStateMachine stuff
     */

    /**
     * Return a string with the datetime NOW.
     *
     * @return string
     * @access private
     */
    private function getNow() {
        $now = new DateTime("NOW");
        return $now->format("Y-m-d H:i:s");
    }

    /**
     * Return an array with the params for the PDO query.
     *
     * @params string $devid
     * @params string $type
     * @params string $key
     * @params string $counter
     * @return array
     * @access private
     */
    private function getParams($devid, $type, $key, $counter) {
        return array(":devid" => $devid, ":type" => $type, ":key" => $key, ":counter" => ($counter === false ? -1 : $counter) );
    }

    /**
     * Free PDO resources.
     *
     * @params PDOConnection $dbh
     * @params PDOStatement $sth
     * @params PDORecord $record
     * @access private
     */
    private function clearConnection(&$dbh, &$sth = null, &$record = null) {
        if ($record != null) {
            $record = null;
        }
        if ($sth != null) {
            $sth = null;
        }
        if ($dbh != null) {
            $dbh = null;
        }
    }

    /**
     * Check if the database and necessary tables exist.
     *
     * @access private
     * @return boolean
     * @throws RuntimeException
     */
    private function checkDbAndTables() {
        ZLog::Write(LOGLEVEL_DEBUG, "SqlStateMachine->checkDbAndTables(): Checking if database and tables are available.");
        $dsn = sprintf("%s:host=%s;port=%s;dbname=%s", STATE_SQL_ENGINE, STATE_SQL_SERVER, STATE_SQL_PORT, STATE_SQL_DATABASE);
        try {
            $this->dbh = new PDO($dsn, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);
            $sqlStmt = sprintf("SHOW TABLES FROM %s LIKE 'zpush%%'", STATE_SQL_DATABASE);
            $sth = $this->dbh->prepare($sqlStmt);
            $sth->execute();
            if ($sth->rowCount() != 3) {
                $this->createTables();
            }
            ZLog::Write(LOGLEVEL_DEBUG, "SqlStateMachine->checkDbAndTables(): Database and tables exist.");
            return true;
        }
        catch (PDOException $ex) {
            ZLog::Write(LOGLEVEL_INFO, sprintf("SqlStateMachine->checkDbAndTables(): error checking the database (%s): %s", $ex->getCode(), $ex->getMessage()));
            // try to create the database if it doesn't exist
            if ($ex->getCode() == self::UNKNOWNDATABASE) {
                $this->createDB();
            }
            else {
                throw new RuntimeException(sprintf("SqlStateMachine->checkDbAndTables(): PDOException (%s): %s", $ex->getCode(), $ex->getMessage()));
            }
        }

        // try to connect to the db again and do the create tables calls
        $this->createTables();
        $this->clearConnection($this->dbh);
    }

    /**
     * Create the states database.
     *
     * @access private
     * @return boolean
     * @throws RuntimeException
     */
    private function createDB() {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SqlStateMachine->createDB(): %s database is not available, trying to create it.", STATE_SQL_DATABASE));
        $dsn = sprintf("%s:host=%s;port=%s", STATE_SQL_ENGINE, STATE_SQL_SERVER, STATE_SQL_PORT);
        try {
            $this->dbh = new PDO($dsn, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);
            $sqlStmt = sprintf("CREATE DATABASE %s", STATE_SQL_DATABASE);
            $sth = $this->dbh->prepare($sqlStmt);
            $sth->execute();
            ZLog::Write(LOGLEVEL_DEBUG, "SqlStateMachine->createDB(): Database created succesfully.");
            $this->createTables();
            return true;
        }
        catch (PDOException $ex) {
            throw new RuntimeException(sprintf("SqlStateMachine->createDB(): PDOException (%s): %s", $ex->getCode(), $ex->getMessage()));
        }
    }

    /**
     * Create the tables in the database.
     *
     * @access private
     * @return boolean
     * @throws RuntimeException
     */
    private function createTables() {
        ZLog::Write(LOGLEVEL_DEBUG, "SqlStateMachine->createTables(): tables are not available, trying to create them.");
        $dsn = sprintf("%s:host=%s;port=%s;dbname=%s", STATE_SQL_ENGINE, STATE_SQL_SERVER, STATE_SQL_PORT, STATE_SQL_DATABASE);
        try {
            $this->dbh = new PDO($dsn, STATE_SQL_USER, STATE_SQL_PASSWORD, $this->options);
            $sqlStmt = self::CREATETABLE_ZPUSH_SETTINGS . self::CREATETABLE_ZPUSH_USERS . self::CREATETABLE_ZPUSH_STATES . self::CREATEINDEX_ZPUSH_STATES;
            $sth = $this->dbh->prepare($sqlStmt);
            $sth->execute();
            ZLog::Write(LOGLEVEL_DEBUG, "SqlStateMachine->createTables(): tables created succesfully.");
            return true;
        }
        catch (PDOException $ex) {
            throw new RuntimeException(sprintf("SqlStateMachine->checkDbAndTables(): PDOException (%s): %s", $ex->getCode(), $ex->getMessage()));
        }
    }
}