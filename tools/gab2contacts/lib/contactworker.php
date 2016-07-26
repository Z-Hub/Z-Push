<?php
/***********************************************
* File      :   contactworker.php
* Project   :   Z-Push - tools - GAB2Contacts
* Descr     :   Main contact synchronization class.
*
* Created   :   20.07.2016
*
* Copyright 2016 Zarafa Deutschland GmbH
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
* ************************************************/

abstract class ContactWorker {

    /**
     * Constructor
     */
    public function __construct() {

    }

    /**
     * Performs the synchronization.
     *  - finds the correct GAB
     *  - gets all GAB entries
     *  - decides if it has to create/update/delete them
     *
     * @access public
     * @return void
     */
    public function Sync($sourceGAB = 'default') {
        $targetFolderId = CONTACT_FOLDERID;
        // gets a list of GABs
        $gabs = $this->GetGABs();

        if (empty($gabs) || $sourceGAB == 'default') {
            // no multi-GABs, just go default
            $this->doSync($targetFolderId, null, 'default');
        }
        else {
            $found = false;
            foreach($gabs as $gabName => $gabId) {
                if (!$sourceGAB || $sourceGAB == $gabName || $sourceGAB == $gabId) {
                    $this->doSync($targetFolderId, $gabId, $gabName);
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $this->Terminate(sprintf("Specified GAB '%s' can not be found. Aborting.", $sourceGAB));
            }
        }
    }

    /**
     * Clears all data from the hidden folder and removes it.
     * This will cause a serverside clearing of all user gabs and a synchronization stop.
     *
     * @param string $targetGab     A gab where the data should be cleared. If not set, it's 'default' or all.
     *
     * @access public
     * @return void
     */
    public function Delete($sourceGAB = 'default') {
        $targetFolderId = CONTACT_FOLDERID;

        // gets a list of GABs
        $gabs = $this->GetGABs();

        if (empty($gabs) || $sourceGAB == 'default') {
            // no multi-GABs, just go default
            $this->doDelete($targetFolderId, null, 'default');
        }
        else {
            $found = false;
            foreach($gabs as $gabName => $gabId) {
                if (!$sourceGAB || $sourceGAB == $gabName || $sourceGAB == $gabId) {
                    $this->doDelete($gabId, $gabName);
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $this->Terminate(sprintf("Specified GAB '%s' can not be found. Aborting.", $sourceGAB));
            }
        }
    }

    /**
     * Logs a message to the command line.
     *
     * @param string  $msg          the message
     *
     * @access protected
     * @return void
     */
    protected function Log($msg) {
        echo $msg . PHP_EOL;
    }

    /**
     * Writes a message to STDERR and terminates the script.
     *
     * @param string  $msg          the message
     *
     * @access protected
     * @return void
     */
    protected function Terminate($msg) {
        fwrite(STDERR, $msg);
        echo PHP_EOL.PHP_EOL;
        exit(1);
    }


    /*********************************
     * Abstract methods
     *********************************/
    /**
     * Returns a list of Global Address Books with their name and ids.
     *
     * @access protected
     * @return array
     */
    protected abstract function GetGABs();

    /**
     * Performs the actual synchronization for a single GAB.
     *
     * @param string $targetFolderId    the id of the folder where the contacts should be stored.
     * @param string $gabId     the id of the gab to be synchronized. If not set (null) the default gab is synchronized.
     * @param string $gabName   the name of the gab to be synchronized. If not set (null) the default gab is synchronized.
     *
     * @access protected
     * @return void
     */
    protected abstract function doSync($targetFolderId, $gabId = null, $gabName = 'default');

    /**
     * Deletes all contacts that were created by the script before.
     *
     * @param string $targetFolderId    the id of the folder where the contacts should be deleted.
     * @param string $gabId             the id of the gab to be synchronized. If not set (null) the default gab is synchronized.
     * @param string $gabName           the name of the gab to be synchronized. If not set (null) the default gab is synchronized.
     *
     * @access protected
     * @return boolean
     */
    protected abstract function doDelete($targetFolderId, $gabId = null, $gabName = 'default');
}

/**
 * Overwrite ZLog class to provide basic logging.
 * All debug messages are ignored, others are logged.
 */
class ZLog {
    static public function Write($level, $msg, $truncate = false) {
        if ($level < LOGLEVEL_INFO) {
            GAB2ContactsCLI::GetWorker()->Log($msg);
        }
    }
}

