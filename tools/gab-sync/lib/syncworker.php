<?php
/***********************************************
* File      :   synchworker.php
* Project   :   Z-Push - tools - GAB sync
* Descr     :   Main synchronization class.
*
* Created   :   28.01.2016
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

include_once("gabentry.php");

abstract class SyncWorker {
    protected $chunkType;
    private $hashFieldId;

    /**
     * The constructor should do all required login actions.
     */
    public function __construct() {
        $this->chunkType = @constant("HASHFIELD") . "-" . @constant("AMOUNT_OF_CHUNKS");
        $this->hashFieldId = @constant("HASHFIELD");
    }

    /**
     * Simulates the synchronization, showing statistics but without touching any data.
     *
     * @param string $targetGab
     *
     * @access public
     * @return void
     */
    public function Simulate($targetGab) {
        $this->Log("Simulating the synchronization. NO DATA IS GOING TO BE WRITTEN.".PHP_EOL);
        $this->Sync($targetGab, false);
    }

    /**
     * Performs the synchronization.
     *  - tries to get the hidden folder id (creates it if not available)
     *  - clears all messages from the folder that do not exist
     *  - gets all GAB entries
     *  - sorts them into chunks
     *  - serializes the chunk
     *  - sends it to setChunkData() to be written
     *  - shows some stats
     *
     * @param string $targetGab the gab name id that should be synchronized, if not set 'default' or all are used.
     * @param string $doWrite   if set to false, no data will be written (simulated mode). Default: true.
     *
     * @access public
     * @return void
     */
    public function Sync($targetGab = false, $doWrite = true) {
        // gets a list of GABs
        $gabs = $this->getGABs();

        if (empty($gabs)) {
            if($targetGab) {
                $this->Terminate("Multiple GABs not found, target should not be set. Aborting.");
            }
            // no multi-GABs, just go default
            $this->doSync(null, 'default', $doWrite);
        }
        else {
            foreach($gabs as $gabName => $gabId) {
                if (!$targetGab || $targetGab == $gabName || $targetGab == $gabId) {
                    $this->doSync($gabId, $gabName, $doWrite);
                }
            }
        }
    }

    /**
     * Performs the actual synchronization for a single GAB.
     *
     * @param string $gabId     the id of the gab to be synchronized. If not set (null) the default gab is synchronized.
     * @param string $gabName   the name of the gab to be synchronized. If not set (null) the default gab is synchronized.
     * @param string $doWrite   if set to false, no data will be written (simulated mode). Default: true.
     *
     * @access private
     * @return void
     */
    private function doSync($gabId = null, $gabName = 'default', $doWrite = true) {
        // get the folderid of the hidden folder - will be created if not yet available
        $folderid = $this->getFolderId($gabId, $gabName, $doWrite);
        if ($folderid === false) {
            $this->Log(sprintf("Aborting %sGAB sync to store '%s'%s. Store not found or create not possible.", (!$doWrite?"simulated ":""), HIDDEN_FOLDERSTORE, ($gabId?" of '".$gabName."'":"")));
            return;
        }

        $this->Log(sprintf("Starting %sGAB sync to store '%s' %s on id '%s'", (!$doWrite?"simulated ":""), HIDDEN_FOLDERSTORE, ($gabId?"of '".$gabName."'":""), $folderid));

        // remove all messages that do not match the current $chunkType
        if ($doWrite)
            $this->clearAllNotCurrentChunkType($folderid, $gabId, $gabName);

        // get all GAB entries
        $gab = $this->getGAB(false, $gabId, $gabName);

        // build the chunks
        $chunks = array();
        foreach ($gab as $entry) {
            $key = $this->getHashFieldValue($entry);
            // Ignore entries without the configured hash value. Warning is logged by getHashFieldValue().
            if (!$key)
                continue;

            $id = $this->calculateChunkId($key);
            if (!isset($chunks[$id])) {
                $chunks[$id] = array();
            }
            $chunks[$id][$key] = $entry;
        }

        $entries = 0;
        $minEntries = $minSize = 99999999999;
        $maxEntries = $maxSize = 0;
        $bytes = 0;
        foreach($chunks as $chunkId => $chunk) {
            // get the hash, sort the chunk and serialize it
            $amountEntries = count($chunk);
            // sort by keys
            ksort($chunk);
            $chunkData = json_encode($chunk);

            // some stats
            $entries += $amountEntries;
            if ($minEntries > $amountEntries)
                $minEntries = $amountEntries;
            if ($maxEntries < $amountEntries)
                $maxEntries = $amountEntries;
            $size = strlen($chunkData);
            $bytes += $size;
            if ($minSize > $size)
                $minSize = $size;
            if ($maxSize < $size)
                $maxSize = $size;

            // save/update the chunk data
            if ($doWrite) {
                $chunkName = $this->chunkType . "/". $chunkId;
                $chunkCRC = md5($chunkData);
                $this->setChunkData($folderid, $chunkName, $amountEntries, $chunkData, $chunkCRC, $gabId, $gabName);
            }
        }

        // Calc the ideal amount of chunks (round up to 5)
        //   by size: we want to have chunks with arount 500 KB of data in it
        //   by entries: max 10 entries per chunk
        $idealBySize = ceil($bytes/500000/ 5) * 5;
        $idealByEntries = ceil((count($gab)/10) / 5) * 5;

        $l  = sprintf("\nSync:\n\tItems in GAB:\t\t\t%d\n\tTotal data size: \t\t%d B\n\n", count($gab), $bytes);
        $l .= sprintf("\tAvg. of items per chunk: \t%g\n\tMin. of items per chunk: \t%d\n\tMax. of items per chunk: \t%d\n\n", ($entries/count($chunks)), $minEntries, $maxEntries);
        $l .= sprintf("\tAvg. of size per chunk: \t%d B\n\tMin. of size per chunk: \t%d B\n\tMax. of size per chunk: \t%d B\n\n", ($bytes/count($chunks)), $minSize, $maxSize);
        $l .= sprintf("\tConfigured amout of chunks:\t%d\n\tIdeal amount by entries: \t%d\n\tIdeal amount by size: \t\t%d", AMOUNT_OF_CHUNKS, $idealByEntries, $idealBySize);
        $this->Log($l);
    }

    /**
     * Updates a single entry of the GAB in the respective chunk.
     *
     * @param string $uniqueId
     * @param string $targetGab
     *
     * @access public
     * @return void
     */
    public function SyncOne($uniqueId, $targetGab) {
        $this->Log(sprintf("Sync-one: %s = '%s'%s", UNIQUEID, $uniqueId, ($targetGab) ? " of '".$targetGab."'":''));

        // gets a list of GABs
        $gabs = $this->getGABs();

        if (empty($gabs)) {
            if($targetGab) {
                $this->Terminate("Multiple GABs not found, target should not be set. Aborting.");
            }
            // default case, no multi-GABs, just go default
            $gabId = null;
            $gabName = 'default';
        }
        else {
            foreach($gabs as $testGabName => $testGabId) {
                if ($targetGab == $testGabName || $targetGab == $testGabId) {
                    $gabId = $testGabId;
                    $gabName = $testGabName;
                    break;
                }
            }
        }

        // search for the entry in the GAB
        $entries = $this->getGAB($uniqueId, $gabId, $gabName);

        // if an entry is found, update the chunk
        // if the entry is NOT found, we should remove it from the chunk (entry deleted)
        if (isset($entries[0])) {
            $entry = $entries[0];
            $key = $this->getHashFieldValue($entry);
            if (!$key) {
                $this->Terminate("SyncOne: Unique key can't be found in entry from GAB. Aborting.");
            }
        }
        else {
            $entry = false;
            $key = $uniqueId;
        }

        // get the data for the chunkId
        $folderid = $this->getFolderId($gabId, $gabName);
        $chunkId = $this->calculateChunkId($key);
        $chunkName = $this->chunkType . "/". $chunkId;
        $chunkdata = $this->getChunkData($folderid, $chunkName, $gabId, $gabName);
        $chunk = json_decode($chunkdata, true);

        // update or remove the entry
        if ($entry) {
            $chunk[$key] = $entry;
            $this->Log("Updating entry.");
        }
        else {
            // if we have the key in the chunk, it existed before and should be deleted
            if (isset($chunk[$key])) {
                unset($chunk[$key]);
                $this->Log("Deleting entry.");
            }
            // if we get here, the entry was not found in the GAB but also not in the chunk data. Invalid entry, abort!
            else {
                $this->Terminate(sprintf("No entry for '%s' can be found in GAB or hashed entries. Nothing to do here. Aborting.", $uniqueId));
            }
        }

        // get the hash, sort the chunk and serialize it
        $amountEntries = count($chunk);
        // sort by keys
        ksort($chunk);
        $chunkData = json_encode($chunk);

        // update the chunk data
        $chunkCRC = md5($chunkData);
        $status = $this->setChunkData($folderid, $chunkName, $amountEntries, $chunkData, $chunkCRC, $gabId, $gabName);
        if ($status) {
            $this->Log("Success!");
        }
    }

    /**
     * Clears all data from the hidden folder (without removing it) for a target GAB.
     * This will cause a serverside clearing of all user gabs.
     *
     * @param string $targetGab     A gab where the data should be cleared. If not set, it's 'default' or all.
     *
     * @access public
     * @return void
     */
    public function ClearAll($targetGab) {
        // gets a list of GABs
        $gabs = $this->getGABs();

        if (empty($gabs)) {
            if($targetGab) {
                $this->Terminate("Multiple GABs not found, target should not be set. Aborting.");
            }
            // no multi-GABs, just go default
            $this->doClearAll(null, 'default');
        }
        else {
            foreach($gabs as $gabName => $gabId) {
                if (!$targetGab || $targetGab == $gabName || $targetGab == $gabId) {
                    $this->doClearAll($gabId, $gabName);
                }
            }
        }
    }

    /**
     * Clears all data from the hidden folder (without removing it) for a specific gabId and gabName.
     * This will cause a serverside clearing of all user gabs.
     *
     * @param string $gabId         the id of the gab where the hidden folder should be searched. If not set (null) the default gab is used.
     * @param string $gabName       the name of the gab where the hidden folder should be searched. If not set (null) the default gab is used.
     *
     * @access private
     * @return boolean
     */
    private function doClearAll($gabId = null, $gabName = 'default') {
        $folderid = $this->getHiddenFolderId($gabId, $gabName);
        if (!$folderid) {
            $this->Log(sprintf("Could not locate folder in '%s'. Aborting.", $gabName));
            return false;
        }

        $status = $this->clearFolderContents($folderid, $gabId, $gabName);
        if ($status) {
            $this->Log(sprintf("Success for '%s'!", $gabName));
        }
        return !!$status;
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
    public function DeleteAll($targetGab) {
        // gets a list of GABs
        $gabs = $this->getGABs();

        if (empty($gabs)) {
            if($targetGab) {
                $this->Terminate("Multiple GABs not found, target should not be set. Aborting.");
            }
            // no multi-GABs, just go default
            $this->doDeleteAll(null, 'default');
        }
        else {
            foreach($gabs as $gabName => $gabId) {
                if (!$targetGab || $targetGab == $gabName || $targetGab == $gabId) {
                    $this->doDeleteAll($gabId, $gabName);
                }
            }
        }
    }

    /**
     * Clears all data from the hidden folder and removes it for the gab id and Name.
     * This will cause a serverside clearing of all user gabs and a synchronization stop.
     *
     * @param string $gabId         the id of the gab where the hidden folder should be searched. If not set (null) the default gab is used.
     * @param string $gabName       the name of the gab where the hidden folder should be searched. If not set (null) the default gab is used.
     *
     * @access private
     * @return boolean
     */
    private function doDeleteAll($gabId = null, $gabName = 'default') {
        $folderid = $this->getHiddenFolderId($gabId, $gabName);
        if (!$folderid) {
            $this->Log(sprintf("Could not locate folder in '%s'", $gabName));
            return false;
        }
        $emptystatus = $this->clearFolderContents($folderid, $gabId, $gabName);
        if ($emptystatus) {
            $status = $this->deleteHiddenFolder($folderid, $gabId, $gabName);
            if ($status) {
                $this->Log(sprintf("Success for '%s'!", $gabName));
                return true;
            }
        }
        return false;
    }

    /**
     * Logs a message to the command line.
     *
     * @param string  $msg          the message
     *
     * @access protected
     * @return void
     */
    protected function Log($msg, $error = false) {
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

    /**
     * Gets the ID of the hidden folder. If $doCreate is true (or not set) the
     * folder will be created if it does not yet exist.
     *
     * @param string $gabId         the id of the gab where the hidden folder should be searched. If not set (null) the default gab is used.
     * @param string $gabName       the name of the gab where the hidden folder should be searched. If not set (null) the default gab is used.
     * @param boolean $doCreate     Creates the folder if it does not exist, default: true
     *
     * @access protected
     * @return string
     */
    protected function getFolderId($gabId = null, $gabName = 'default', $doCreate = true) {
        $id = $this->getHiddenFolderId($gabId, $gabName);
        if (!$id) {
            if ($doCreate)
                $id = $this->createHiddenFolder($gabId, $gabName);
            else
                $id = "<does not yet exist>";
        }
        return $id;
    }

    /**
     * Returns the configured hash field from the GABEntry.
     * If it is not available the method returns false.
     *
     * @param GABEntry $gabEntry
     *
     * @access protected
     * @return string|boolean
     */
    protected function getHashFieldValue($gabEntry) {
        if (property_exists($gabEntry, $this->hashFieldId)) {
            return $gabEntry->{$this->hashFieldId};
        }
        else {
            $this->Log("getHashFieldValue: error, configured UNIQUEID is not set in GAB entry.");
            return false;
        }
    }

    /**
     * Calculated the chunk-id of a value.
     *
     * @param string $value
     *
     * @access protected
     * @return number
     */
    protected function calculateChunkId($value) {
        $hash = sprintf("%u", crc32($value));
        return fmod($hash, AMOUNT_OF_CHUNKS);
    }


    /*********************************
     * Abstract methods
     *********************************/

    /**
     * Creates the hidden folder.
     *
     * @param string $gabId         the id of the gab where the hidden folder should be created. If not set (null) the default gab is used.
     * @param string $gabName       the name of the gab where the hidden folder should be created. If not set (null) the default gab is used.
     *
     * @access protected
     * @return boolean
     */
    protected abstract function createHiddenFolder($gabId = null, $gabName = 'default');

    /**
     * Deletes the hidden folder.
     *
     * @param string $folderid
     * @param string $gabId         the id of the gab where the hidden folder should be searched. If not set (null) the default gab is used.
     * @param string $gabName       the name of the gab where the hidden folder should be searched. If not set (null) the default gab is used.
     *
     * @access protected
     * @return boolean
     */
    protected abstract function deleteHiddenFolder($folderid, $gabId = null, $gabName = 'default');

    /**
     * Returns the internal identifier (folder-id) of the hidden folder.
     *
     * @param string $gabId         the id of the gab where the hidden folder should be searched. If not set (null) the default gab is used.
     * @param string $gabName       the name of the gab where the hidden folder should be searched. If not set (null) the default gab is used.
     *
     * @access protected
     * @return string
    */
    protected abstract function getHiddenFolderId($gabId = null, $gabName = 'default');

    /**
     * Removes all messages that have not the same chunkType (chunk configuration changed!).
     *
     * @param string $folderid
     * @param string $gabId         the id of the gab where the hidden folder should be cleared. If not set (null) the default gab is used.
     * @param string $gabName       the name of the gab where the hidden folder should be cleared. If not set (null) the default gab is used.
     *
     * @access protected
     * @return boolean
     */
    protected abstract function clearFolderContents($folderid, $gabId = null, $gabName = 'default');

    /**
     * Removes all messages that do not match the current ChunkType.
     *
     * @param string $folderid
     * @param string $gabId         the id of the gab where the hidden folder should be cleared. If not set (null) the default gab is used.
     * @param string $gabName       the name of the gab where the hidden folder should be cleared. If not set (null) the default gab is used.
     *
     * @access protected
     * @return boolean
     */
    protected abstract function clearAllNotCurrentChunkType($folderid, $gabId = null, $gabName = 'default');

    /**
     * Returns a list of Global Address Books with their names and ids.
     *
     * @access protected
     * @return array
     */
    protected abstract function getGABs();

    /**
     * Returns a list with all GAB entries or a single entry specified by $uniqueId.
     * The search for that single entry is done using the configured UNIQUEID parameter.
     * If no entry is found for a $uniqueId an empty array() must be returned.
     *
     * @param string $uniqueId      A value to be found in the configured UNIQUEID.
     *                              If set, only one item is returned. If false or not set, the entire GAB is returned.
     *                              Default: false
     * @param string $gabId         Id that uniquely identifies the GAB. If not set or null the default GAB is assumed.
     * @param string $gabName       String that uniquely identifies the GAB. If not set the default GAB is assumed.
     *
     * @access protected
     * @return array of GABEntry
     */
    protected abstract function getGAB($uniqueId = false, $gabId = null, $gabName = 'default');

    /**
     * Returns the chunk data of the chunkId of the hidden folder.
     *
     * @param string    $folderid
     * @param string    $chunkName      The name of the chunk (used to find the chunk message).
     *                                  The name is saved in the 'subject' of the chunk message.
     * @param string    $gabId          Id that uniquely identifies the GAB. If not set or null the default GAB is assumed.
     * @param string    $gabName        String that uniquely identifies the GAB. If not set the default GAB is assumed.
     *
     *
     * @access protected
     * @return json string
     */
    protected abstract function getChunkData($folderid, $chunkName, $gabId = null, $gabName = 'default');

    /**
     * Updates the chunk data in the hidden folder if it changed.
     * If the chunkId is not available, it's created.
     *
     * @param string    $folderid
     * @param string    $chunkName      The name of the chunk (used to find/update the chunk message).
     *                                  The name is to be saved in the 'subject' of the chunk message.
     * @param int       $amountEntries  Amount of entries in the chunkdata.
     * @param string    $chunkData      The data containing all the data.
     * @param string    $chunkCRC       A checksum of the chunk data. To be saved in the 'location' of
     *                                  the chunk message. Used to identify changed chunks.
     * @param string    $gabId          Id that uniquely identifies the GAB. If not set or null the default GAB is assumed.
     * @param string    $gabName        String that uniquely identifies the GAB. If not set the default GAB is assumed.
     *
     * @access protected
     * @return boolean
     */
    protected abstract function setChunkData($folderid, $chunkName, $amountEntries, $chunkData, $chunkCRC, $gabId = null, $gabName = 'default');
}