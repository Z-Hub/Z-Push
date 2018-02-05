#!/usr/bin/env php
<?php
/***********************************************
* File      :   gab2contacts.php
* Project   :   Z-Push - tools - GAB2Contacts
* Descr     :   Copies the GAB into a contact folder and can be used to keep them updated.
*
* Created   :   20.07.2016
*
* Copyright 2016 Zarafa Deutschland GmbH
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
* ************************************************/

// Path to the Z-Push directory relative to the gab2contacts script.
// The path set by default is as required for a GIT checkout.
define('PATH_TO_ZPUSH', '../../src/');

/************************************************
 * MAIN
 */
    define('BASE_PATH_CLI',  dirname(__FILE__) ."/");
    set_include_path(get_include_path() . PATH_SEPARATOR . BASE_PATH_CLI . PATH_SEPARATOR . PATH_TO_ZPUSH);
    include_once("vendor/autoload.php");

    if (!defined('CONTACT_CONFIG')) define('CONTACT_CONFIG', 'config.php');
    include_once(CONTACT_CONFIG);

    try {
        GAB2ContactsCLI::CheckEnv();
        GAB2ContactsCLI::CheckOptions();

        if (! GAB2ContactsCLI::SureWhatToDo()) {
            // show error message if available
            if (GAB2ContactsCLI::GetErrorMessage())
                fwrite(STDERR, GAB2ContactsCLI::GetErrorMessage() . PHP_EOL.PHP_EOL);

            echo GAB2ContactsCLI::UsageInstructions();
            exit(1);
        }
        else if (!GAB2ContactsCLI::SetupContactWorker()) {
            fwrite(STDERR, GAB2ContactsCLI::GetErrorMessage() . PHP_EOL);
            exit(1);
        }

        GAB2ContactsCLI::RunCommand();
    }
    catch (Exception $ex) {
        fwrite(STDERR, get_class($ex) . ": ". $ex->getMessage() . PHP_EOL);
        exit(1);
    }



/************************************************
 * GAB2Contacts CLI
 */
class GAB2ContactsCLI {
    const COMMAND_SYNC = 1;
    const COMMAND_DELETE = 2;

    static private $contactWorker;
    static private $command;
    static private $sourceGAB;
    static private $errormessage;

    /**
     * Returns usage instructions.
     *
     * @access public
     * @return string
     */
    static public function UsageInstructions() {
        return  "Usage:" .PHP_EOL.
                "\tgab2contact.php -a ACTION [options]" .PHP_EOL.PHP_EOL.
                "Parameters:" .PHP_EOL.
                 "\t-a sync | delete" .PHP_EOL.PHP_EOL.
                "Actions:" .PHP_EOL.
                "\tsync\t Synchronizes all data from the GAB to the target contact folder" .PHP_EOL.
                "\tdelete\t Removes all previously created contacts in the target contact folder." .PHP_EOL.
                PHP_EOL;
    }

    /**
     * Setup of the ContactWorker implementation.
     *
     * @access public
     * @return boolean
     */
    static public function SetupContactWorker() {
        $file = "lib/" .strtolower(CONTACTWORKER).".php";

        include_once($file);

        if (!class_exists(CONTACTWORKER)) {
            self::$errormessage = "ContactWorker file loaded, but class '".CONTACTWORKER."' can not be found. Check your configuration or implementation.";
        }
        else {
            self::$sourceGAB = @constant('SOURCE_GAB');

            $s = @constant('CONTACTWORKER');
            self::$contactWorker = new $s();
            return true;
        }
        return false;
    }

    /**
     * Checks the environment.
     *
     * @access public
     * @return void
     */
    static public function CheckEnv() {
        if (php_sapi_name() != "cli")
            self::$errormessage = "This script can only be called from the CLI.";

        if (!function_exists("getopt"))
            self::$errormessage = "PHP Function getopt not found. Please check your PHP version and settings.";

        if (!defined('CONTACT_FOLDERID') || CONTACT_FOLDERID == "")
            self::$errormessage = "No value set for 'CONTACT_FOLDERID'. Please check your configuration.";
    }

    /**
     * Checks the options from the command line.
     *
     * @access public
     * @return void
     */
    static public function CheckOptions() {
        if (self::$errormessage)
            return;

        $options = getopt("a:");

        // get 'action'
        $action = false;
        if (isset($options['a']) && !empty($options['a']))
            $action = strtolower(trim($options['a']));
        elseif (isset($options['action']) && !empty($options['action']))
            $action = strtolower(trim($options['action']));

        // get a command for the requested action
        switch ($action) {
            // sync!
            case "sync":
                self::$command = self::COMMAND_SYNC;
                break;

            // delete
            case "delete":
                self::$command = self::COMMAND_DELETE;
                break;

            default:
                self::UsageInstructions();
        }
    }

    /**
     * Indicates if the options from the command line
     * could be processed correctly.
     *
     * @access public
     * @return boolean
     */
    static public function SureWhatToDo() {
        return isset(self::$command);
    }

    /**
     * Returns a errormessage of things which could have gone wrong.
     *
     * @access public
     * @return string
     */
    static public function GetErrorMessage() {
        return (isset(self::$errormessage))?self::$errormessage:"";
    }

    /**
     * Runs a command requested from an action of the command line.
     *
     * @access public
     * @return void
     */
    static public function RunCommand() {
        switch(self::$command) {
            case self::COMMAND_SYNC:
                self::$contactWorker->Sync(self::$sourceGAB);
                break;

            case self::COMMAND_DELETE:
                echo "Are you sure you want to remove all contacts of GAB folder in the target folder? [y/N]: ";
                $confirm  = strtolower(trim(fgets(STDIN)));
                if ( $confirm === 'y' || $confirm === 'yes')
                    self::$contactWorker->Delete(self::$sourceGAB);
                else
                    echo "Aborted!".PHP_EOL;
                break;
        }
        echo PHP_EOL;
    }

    /**
     * Returns the Worker object.
     *
     * @access public
     * @return ContactWorker implementation
     */
    static public function GetWorker() {
        return self::$contactWorker;
    }
}