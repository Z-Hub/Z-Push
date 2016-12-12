#!/usr/bin/env php
<?php
/***********************************************
* File      :   migrate-2.0.x-2.1.0.php
* Project   :   Z-Push - tools
* Descr     :   Convertes states from
*                 Z-Push 2.0.x  to  Z-Push 2.1.0
*
* Created   :   30.11.2012
*
* Copyright 2007 - 2013 Zarafa Deutschland GmbH
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
define('ZPUSH_BASE_PATH', "../src");



/************************************************
 * MAIN
*/
try {
    if (php_sapi_name() != "cli")
        die("This script can only be called from the CLI.");

    if (!defined('ZPUSH_BASE_PATH') || !file_exists(ZPUSH_BASE_PATH . "/config.php"))
        die("ZPUSH_BASE_PATH not set correctly or no config.php file found\n");

    define('BASE_PATH_CLI',  ZPUSH_BASE_PATH ."/");
    set_include_path(get_include_path() . PATH_SEPARATOR . ZPUSH_BASE_PATH);

    include('lib/core/zpushdefs.php');
    include('lib/core/zpush.php');
    include('lib/core/zlog.php');
    include('lib/core/statemanager.php');
    include('lib/core/stateobject.php');
    include('lib/core/asdevice.php');
    include('lib/core/interprocessdata.php');
    include('lib/exceptions/exceptions.php');
    include('lib/utils/utils.php');
    include('lib/request/request.php');
    include('lib/request/requestprocessor.php');
    include('lib/interface/ibackend.php');
    include('lib/interface/ichanges.php');
    include('lib/interface/iexportchanges.php');
    include('lib/interface/iimportchanges.php');
    include('lib/interface/isearchprovider.php');
    include('lib/interface/istatemachine.php');
    include('config.php');

    ZPush::CheckConfig();
    $migrate = new StateMigrator20xto210();

    if (!$migrate->MigrationNecessary())
        echo "Migration script was run before and eventually no migration is necessary. Rerunning checks\n";

    $migrate->DoMigration();
}
catch (ZPushException $zpe) {
    die(get_class($zpe) . ": ". $zpe->getMessage() . "\n");
}

echo "terminated\n";


class StateMigrator20xto210 {
    const FROMVERSION = "1"; // IStateMachine::STATEVERSION_01
    const TOVERSION = "2";   // IStateMachine::STATEVERSION_02

    private $sm;

    /**
     * Constructor
     */
    public function __construct() {
        $this->sm = false;
    }

    /**
     * Checks if the migration is necessary
     *
     * @access public
     * @throws FatalMisconfigurationException
     * @throws FatalNotImplementedException
     * @return boolean
     */
    public function MigrationNecessary() {
        try {
            $this->sm = ZPush::GetStateMachine();
        }
        catch (HTTPReturnCodeException $e) {
            echo "Check states: states versions do not match and need to be migrated\n\n";

            // we just try to get the statemachine again
            // the exception is only thrown the first time
            $this->sm = ZPush::GetStateMachine();
        }

        if (!$this->sm)
             throw new FatalMisconfigurationException("Could not get StateMachine from ZPush::GetStateMachine()");

        if (!($this->sm  instanceof FileStateMachine)) {
            throw new FatalNotImplementedException("This conversion script is only able to convert states of the FileStateMachine");
        }

        if ($this->sm->GetStateVersion() == ZPush::GetLatestStateVersion())
            return false;

        if ($this->sm->GetStateVersion() !== self::FROMVERSION || ZPush::GetLatestStateVersion() !== self::TOVERSION)
            throw new FatalNotImplementedException(sprintf("This script only converts from state version %d to %d. Currently the system is on %d and should go to %d. Please contact support.", self::FROMVERSION,  self::TOVERSION, $this->sm->GetStateVersion(), ZPush::GetLatestStateVersion()));

        // do migration
        return true;
    }

    /**
     * Execute the migration
     *
     * @access public
     * @return true
     */
    public function DoMigration() {
        // go through all files
        $files = glob(STATE_DIR. "/*/*/*", GLOB_NOSORT);
        $filetotal = count($files);
        $filecount = 0;
        $rencount = 0;
        $igncount = 0;

        foreach ($files as $file) {
            $filecount++;
            $newfile = strtolower($file);
            echo "\033[1G";

            if ($file !== $newfile) {
                $rencount++;
                rename ($file, $newfile);
            }
            else
                $igncount++;

            printf("Migrating file %d/%d\t%s", $filecount, $filetotal, $file);
        }
        echo "\033[1G". sprintf("Migrated total of %d files, %d renamed and %d ignored (as already correct)%s\n\n", $filetotal, $rencount, $igncount, str_repeat(" ", 50));

        // get all states of synchronized devices
        $alldevices = $this->sm->GetAllDevices(false);
        foreach ($alldevices as $devid) {
            $lowerDevid = strtolower($devid);

            echo "Processing device: ". $devid . "\t";

            // update device data
            $devState = ZPush::GetStateMachine()->GetState($lowerDevid, IStateMachine::DEVICEDATA);
            $newdata = array();
            foreach ($devState->devices as $user => $dev) {
                if (!isset($dev->deviceidOrg))
                    $dev->deviceidOrg = $dev->deviceid;

                $dev->deviceid = strtolower($dev->deviceid);
                $dev->useragenthistory = array_unique($dev->useragenthistory);
                $newdata[$user] = $dev;
            }
            $devState->devices = $newdata;
            $this->sm->SetState($devState, $lowerDevid, IStateMachine::DEVICEDATA);

            // go through the users again: device was updated sucessfully, now we change the global user <-> device link
            foreach ($devState->devices as $user => $dev) {
                printf("\n\tUn-linking %s with old device id %s", $user, $dev->deviceidOrg);
                $this->sm->UnLinkUserDevice($user, $dev->deviceidOrg);
                printf("\n\tRe-linking %s with new device id %s", $user, $dev->deviceid);
                $this->sm->LinkUserDevice($user, $dev->deviceid);
            }

            echo "\n\tcompleted\n";
        }
        echo "\nSetting new StateVersion\n";
        $this->sm->SetStateVersion(self::TOVERSION);
        echo "Migration completed!\n\n";

        return true;
    }
}
