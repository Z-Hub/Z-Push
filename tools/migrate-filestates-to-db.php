#!/usr/bin/env php
<?php
/**********************************************************
* File      :   migrate-filestates-to-db.php
* Project   :   Z-Push - tools
* Descr     :   Copies file states to the database
*
* Created   :   12.02.2016
*
* Copyright 2007 - 2016 Zarafa Deutschland GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation.
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

/************************************************
 * MAIN
 */
print("Starting the filestate to database migration script." . PHP_EOL);

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

    if (!defined('ZPUSH_CONFIG')) define('ZPUSH_CONFIG', BASE_PATH_CLI . 'config.php');
    include_once(ZPUSH_CONFIG);


    if (STATE_MACHINE != 'FILE') {
        die(sprintf("Only migration from 'FILE' StateMachine is possible. Your STATE_MACHINE setting is '%s'. Please modify your config.php.%s", STATE_MACHINE, PHP_EOL));
    }

    printf("Also check the logfile at %s for more information.%s", LOGFILE, PHP_EOL);

    ZPush::CheckConfig();
    $migrate = new StateMigratorFileToDB();

    if (!$migrate->MigrationNecessary()) {
        exit(1);
    }

    $migrate->DoMigration();
}
catch (ZPushException $zpe) {
    die(get_class($zpe) . ": ". $zpe->getMessage() . "\n");
}

sprintf("terminated%s", PHP_EOL);

class StateMigratorFileToDB {
    private $fsm;
    private $dbsm;


    /**
     * Check if the migration is necessary.
     *
     * @access public
     * @throws FatalMisconfigurationException
     * @throws FatalNotImplementedException
     * @return boolean
     */
    public function MigrationNecessary() {
        print("StateMigratorFileToDB->MigrationNecessary(): checking if migration is necessary." . PHP_EOL);
        try {
            $this->dbsm = new SqlStateMachine();
            if ($this->dbsm->DoTablesHaveData()) {
                print ("Tables already have data. Migration aborted. Drop database or truncate tables and try again." . PHP_EOL);
                return false;
            }
        }
        catch (ZPushException $ex) {
            die(get_class($ex) . ": ". $ex->getMessage() . PHP_EOL);
        }
        return true;
    }

    /**
     * Execute the migration.
     *
     * @access public
     * @return true
     */
    public function DoMigration() {
        print("StateMigratorFileToDB->DoMigration(): Starting migration routine." . PHP_EOL);
        $starttime = time();
        $deviceCount = 0;
        $stateCount = 0;
        try {
            // Fix hierarchy folder data before starting the migration
            ZPushAdmin::FixStatesHierarchyFolderData();

            $this->fsm = new FileStateMachine();

            if (!($this->fsm  instanceof FileStateMachine)) {
                throw new FatalNotImplementedException("This conversion script is only able to convert states from the FileStateMachine");
            }

            // get all state information for all devices
            $alldevices = $this->fsm->GetAllDevices(false);
            foreach ($alldevices as $devid) {
                $deviceCount++;
                $lowerDevid = strtolower($devid);

                $allStates = $this->fsm->GetAllStatesForDevice($lowerDevid);
                printf("Processing device: %s with %s states\t", str_pad($devid,35), str_pad(count($allStates), 4, ' ',STR_PAD_LEFT));
                $migrated = 0;
                foreach ($allStates as $stateInfo) {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("StateMigratorFileToDB->DoMigration(): Migrating state type:'%s' uuid:'%s' counter:'%s'", Utils::PrintAsString($stateInfo['type']), Utils::PrintAsString($stateInfo['uuid']), Utils::PrintAsString($stateInfo['counter'])));
                    $state = $this->fsm->GetState($lowerDevid, $stateInfo['type'], $stateInfo['uuid'], (int) $stateInfo['counter'], false);
                    $this->dbsm->SetState($state, $lowerDevid, $stateInfo['type'], (empty($stateInfo['uuid']) ? NULL : $stateInfo['uuid']), (int) $stateInfo['counter']);
                    $migrated++;
                }

                // link devices to users
                $devState = $this->fsm->GetState($lowerDevid, IStateMachine::DEVICEDATA);
                foreach ($devState->devices as $user => $dev) {
                    $this->dbsm->LinkUserDevice($user, $dev->deviceid);
                }

                print(" completed migration of $migrated states" . PHP_EOL);
                $stateCount += $migrated;
            }
        }
        catch (ZPushException $ex) {
            print (PHP_EOL . "Something went wrong during the migration. The script will now exit." . PHP_EOL);
            die(get_class($ex) . ": ". $ex->getMessage() . PHP_EOL);
        }
        $timeSpent = gmdate("H:i:s", time() - $starttime);
        printf(PHP_EOL ."StateMigratorFileToDB->DoMigration(): Migration completed successfuly. Migrated %d devices with %d states in %s.".PHP_EOL.PHP_EOL, $deviceCount, $stateCount, $timeSpent);
    }
}
