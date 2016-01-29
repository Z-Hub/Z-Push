<?php
/***********************************************
* File      :   syncher.php
* Project   :   Z-Push - tools - OL GAB sync
* Descr     :   Main synchronization class.
*
* Created   :   28.01.2016
*
* Copyright 2016 Zarafa Deutschland GmbH
* ************************************************/

include_once("gabentry.php");

abstract class Syncher {
    protected $chunkType;
    private $hashFieldId;

    /**
     * The constructer should do all required login actions.
     */
    public function __construct() {
        $this->chunkType = @constant("HASHFIELD") . "-" . @constant("AMOUT_OF_CHUNKS");
        $this->hashFieldId = @constant("HASHFIELD");
    }

    /**
     * Simulates the synchronization, showing statistics but without touching any data.
     */
    public function Simulate() {
        $this->Log("Simulating the synchronization. NO DATA IS GOING TO BE WRITTEN.\n");
        $this->Sync(false);
    }

    /**
     * Performs the synchronization.
     *  - tries to get the hidden folder id (creates it if not available)
     *  - clears all messages from the folder that do not exist
     *  - gets all GAB entries
     *  - sorts them into chunks
     *  - serializes the chunk
     *  - sends it to SetChunkData() to be written
     *  - shows some stats
     *
     * @param string $doWrite   if set to false, no data will be written (simulated mode). Default: true.
     * @access public
     * @return void
     */
    public function Sync($doWrite = true) {
        // get the folderid of the hidden folder - will be created if not yet available
        $folderid = $this->getFolderId($doWrite);

        $this->Log(sprintf("Starting %sGAB sync to store '%s' on id '%s'", (!$doWrite?"simulated ":""), HIDDEN_FOLDERSTORE, $folderid));

        // remove all messages that do not match the current $chunkType
        if ($doWrite)
            $this->ClearAllNotCurrentChunkType($folderid);

        // get all GAB entries
        $gab = $this->GetGAB();

        // build the chunks
        $chunks = array();
        foreach ($gab as $entry) {
            $id = $this->calculateChunkId($entry);
            if (!isset($chunks[$id])) {
                $chunks[$id] = array();
            }
            $key = $entry->{$this->hashFieldId};
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
            if ($doWrite)
                $this->SetChunkData($folderid, $chunkId, $amountEntries, $chunkData);
        }

        // Calc the ideal amount of chunks (round up to 5)
        //   by size: we want to have chunks with arount 500 KB of data in it
        //   by entries: max 10 entries per chunk
        $idealBySize = ceil($bytes/500000/ 5) * 5;
        $idealByEntries = ceil((count($gab)/10) / 5) * 5;

        $l  = sprintf("\nSync:\n\tItems in GAB:\t\t\t%d\n\tTotal data size: \t\t%d B\n\n", count($gab), $bytes);
        $l .= sprintf("\tAvg. of items per chunk: \t%g\n\tMin. of items per chunk: \t%d\n\tMax. of items per chunk: \t%d\n\n", ($entries/count($chunks)), $minEntries, $maxEntries);
        $l .= sprintf("\tAvg. of size per chunk: \t%d B\n\tMin. of size per chunk: \t%d B\n\tMax. of size per chunk: \t%d B\n\n", ($bytes/count($chunks)), $minSize, $maxSize);
        $l .= sprintf("\tConfigured amout of chunks:\t%d\n\tIdeal amount by entries: \t%d\n\tIdeal amount by size: \t\t%d", AMOUT_OF_CHUNKS, $idealByEntries, $idealBySize);
        $this->Log($l);
    }

    /**
     * Updates a single entry of the GAB in the respective chunk.
     *
     * @param string $uniqueId
     *
     * @access public
     * @return void
     */
    public function SyncOne($uniqueId) {
        $this->Log(sprintf("Sync-one: %s = '%s'", UNIQUEID, $uniqueId));

        // search for the entry in the GAB
        $entries = $this->GetGAB($uniqueId);
        $entry = $entries[0];

        // get the chunkId and the data
        $chunkId = $this->calculateChunkId($entry);
        $folderid = $this->getFolderId();
        $chunkdata = $this->GetChunkData($folderid, $chunkId);
        $chunk = json_decode($chunkdata, true);

        // update the entry
        $key = $entry->{$this->hashFieldId};
        $chunk[$key] = $entry;

        // get the hash, sort the chunk and serialize it
        $amountEntries = count($chunk);
        // sort by keys
        ksort($chunk);
        $chunkData = json_encode($chunk);

        // update the chunk data
        $status = $this->SetChunkData($folderid, $chunkId, $amountEntries, $chunkData);
        if ($status) {
            $this->Log("Success!");
        }
    }

    /**
     * Clears all data from the hidden folder (without removing it).
     * This will cause a serverside clearing of all user gabs.
     *
     * @access public
     * @return void
     */
    public function ClearAll() {
        $folderid = $this->GetHiddenFolderId();
        if (!$folderid) {
            $this->Log("Could not locate folder. Aborting.", true);
        }

        $status = $this->ClearFolderContents($folderid);
        if ($status) {
            $this->Log("Success!");
        }
    }

    /**
     * Clears all data from the hidden folder and removes it.
     * This will cause a serverside clearing of all user gabs and a synchronization stop.
     *
     * @access public
     * @return void
     */
    public function DeleteAll() {
        $folderid = $this->GetHiddenFolderId();
        if (!$folderid) {
            $this->Log("Could not locate folder. Aborting.", true);
        }
        $emptystatus = $this->ClearFolderContents($folderid);
        if ($emptystatus) {
            $status = $this->DeleteHiddenFolder($folderid);
            if ($status) {
                $this->Log("Success!");
            }
        }
    }

    /**
     * Logs a message to the command line.
     *
     * @param string  $msg          the message
     * @param boolean $error        if set to true, message will be printed to STDERR and the script terminates.
     *
     * @access protected
     * @return void
     */
    protected function Log($msg, $error = false) {
        if ($error) {
            fwrite(STDERR, $msg);
            echo "\n\n";
            exit(1);
        }
        else
            echo $msg . "\n";
    }

    /**
     * Gets the ID of the hidden folder. If $doCreate is true (or not set) the
     * folder will be created if it does not yet exist.
     *
     * @param boolean $doCreate     Creates the folder if it does not exist, default: true
     *
     * @access protected
     * @return string
     */
    protected function getFolderId($doCreate = true) {
        $id = $this->GetHiddenFolderId();
        if (!$id) {
            if ($doCreate)
                $id = $this->CreateHiddenFolder();
            else
                $id = "<does not yet exist>";
        }
        return $id;
    }

    /**
     * Calculates the chunk-id for a GABEntry.
     *
     * @param GABEntry $gabEntry
     *
     * @access protected
     * @return boolean|number
     */
    protected function calculateChunkId($gabEntry) {
        $value = false;
        if (property_exists($gabEntry, $this->hashFieldId)) {
            $value = $gabEntry->{$this->hashFieldId};
        }
        if (!$value) {
            $this->Log("Calculate chunk-id: error, configured UNIQUEID is not set in GAB entry.");
            return false;
        }

        $hash = sprintf("%u", crc32($value));
        return fmod($hash, AMOUT_OF_CHUNKS);
    }


    /*********************************
     * Abstract methods
     *********************************/

    /**
     * Creates the hidden folder.
     *
     * @access protected
     * @return boolean
     */
    protected abstract function CreateHiddenFolder();

    /**
     * Deletes the hidden folder.
     *
     * @param string $folderid
     * @access protected
     * @return boolean
     */
    protected abstract function DeleteHiddenFolder($folderid);

    /**
     * Returns the internal identfier (folder-id) of the hidden folder.
     *
     * @access protected
     * @return string
    */
    protected abstract function GetHiddenFolderId();

    /**
     * Removes all messages that have not the same chunkType (chunk configuration changed!)
     *
     * @param string $folderid
     * @access protected
     * @return boolean
     */
    protected abstract function ClearFolderContents($folderid);

    /**
     * Removes all messages that do not match the current ChunkType.
     *
     * @param string $folderid
     * @access protected
     * @return boolean
     */
    protected abstract function ClearAllNotCurrentChunkType($folderid);

    /**
     * Returns a list with all GAB entries or a single entry specified by $uniqueId.
     * The search for that single entry is done using the configured UNIQUEID parameter.
     *
     * @param string $uniqueId      A value to be found in the configured UNIQUEID.
     *                              If set, only one item is returned. If false or not set, the entire GAB is returned.
     *                              Default: false
     *
     * @access protected
     * @return array of GABEntry
     */
    protected abstract function GetGAB($uniqueId = false);

    /**
     * Returns the chunk data of the chunkId of the hidden folder.
     *
     * @param string    $folderid
     * @param int       $chunkId        The id of the chunk (used to find the chunk message).
     *
     * @access protected
     * @return json string
     */
    protected abstract function GetChunkData($folderid, $chunkId);

    /**
     * Updates the chunk data in the hidden folder if it changed.
     * If the chunkId is not available, it's created.
     *
     * @param string    $folderid
     * @param int       $chunkId        The id of the chunk (used to find the chunk message).
     * @param int       $amountEntries  Amount of entries in the chunkdata.
     * @param string    $chunkData      The data containing all the data.
     *
     * @access protected
     * @return boolean
     */
    protected abstract function SetChunkData($folderid, $chunkId, $amountEntries, $chunkData);
}