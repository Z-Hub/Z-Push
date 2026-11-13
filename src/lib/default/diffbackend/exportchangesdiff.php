<?php
/***********************************************
* File      :   exportchangesdiff.php
* Project   :   Z-Push
* Descr     :   IExportChanges implementation using
*               the differential engine
*
* Created   :   02.01.2012
*
* Copyright 2007 - 2016 Zarafa Deutschland GmbH
* Copyright 2023 Mat Wilson
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

class ExportChangesDiff extends DiffState implements IExportChanges{
    private $importer;
    private $folderid;
    private $changes;
    private $step;

    /**
     * Constructor
     *
     * @param object        $backend
     * @param string        $folderid
     *
     * @access public
     * @throws StatusException
     */
    public function __construct($backend, $folderid) {
        $this->backend = $backend;
        $this->folderid = $folderid;
    }

    /**
     * Sets the importer the exporter will sent it's changes to
     * and initializes the Exporter
     *
     * @param object        &$importer  Implementation of IImportChanges
     *
     * @access public
     * @return boolean
     * @throws StatusException
     */
    public function InitializeExporter(&$importer) {
        $this->changes = array();
        $this->step = 0;
        $this->importer = $importer;

        if($this->folderid) {
            // Get the changes since the last sync
            if(!isset($this->syncstate) || !$this->syncstate)
                $this->syncstate = array();

            ZLog::Write(LOGLEVEL_DEBUG,sprintf("ExportChangesDiff->InitializeExporter(): Initializing message diff engine. '%d' messages in state", count($this->syncstate)));

            //do nothing if it is a dummy folder
            if ($this->folderid != SYNC_FOLDER_TYPE_DUMMY) {
                // Get our lists - syncstate (old)  and msglist (new)
                $msglist = $this->backend->GetMessageList($this->folderid, $this->cutoffdate);
                // if the folder was deleted, no information is available anymore. A hierarchysync should be executed
                if($msglist === false)
                    throw new StatusException("ExportChangesDiff->InitializeExporter(): Error, no message list available from the backend", SYNC_STATUS_FOLDERHIERARCHYCHANGED, null, LOGLEVEL_INFO);

                $this->changes = $this->getDiffTo($msglist);
            }
        }
        else {
            ZLog::Write(LOGLEVEL_DEBUG, "ExportChangesDiff->InitializeExporter(): Initializing folder diff engine");

            $folderlist = $this->backend->GetFolderList();
            if($folderlist === false)
                throw new StatusException("ExportChangesDiff->InitializeExporter(): error, no folders available from the backend", SYNC_FSSTATUS_CODEUNKNOWN, null, LOGLEVEL_WARN);

            if(!isset($this->syncstate) || !$this->syncstate)
                $this->syncstate = array();

            $this->changes = $this->getDiffTo($folderlist);
        }

        ZLog::Write(LOGLEVEL_INFO, sprintf("ExportChangesDiff->InitializeExporter(): Found '%d' changes for '%s'", count($this->changes), ($this->folderid)?$this->folderid : 'hierarchy' ));
    }

    /**
     * Returns the amount of changes to be exported
     *
     * @access public
     * @return int
     */
    public function GetChangeCount() {
        return count($this->changes);
    }

    /**
     * Synchronizes a change
     *
     * @access public
     * @return array
     */
    public function Synchronize() {
        $progress = array();

        // Get one of our stored changes and send it to the importer, store the new state if
        // it succeeds
        if($this->folderid == false) {
            if($this->step < count($this->changes)) {
                $change = $this->changes[$this->step];

                switch($change["type"]) {
                    case "change":
                        $folder = $this->backend->GetFolder($change["id"]);
                        $stat = $this->backend->StatFolder($change["id"]);

                        if(!$folder)
                            return;

                        if($this->flags & BACKEND_DISCARD_DATA || $this->importer->ImportFolderChange($folder))
                            $this->updateState("change", $stat);
                        break;
                    case "delete":
                        if($this->flags & BACKEND_DISCARD_DATA || $this->importer->ImportFolderDeletion(SyncFolder::GetObject($change["id"])))
                            $this->updateState("delete", $change);
                        break;
                }

                $this->step++;

                $progress = array();
                $progress["steps"] = count($this->changes);
                $progress["progress"] = $this->step;

                return $progress;
            } else {
                return false;
            }
        }
        else {
            if($this->step < count($this->changes)) {
                $change = $this->changes[$this->step];

                switch($change["type"]) {
                    case "change":
                        // Note: because 'parseMessage' and 'statMessage' are two seperate
                        // calls, we have a chance that the message has changed between both
                        // calls. This may cause our algorithm to 'double see' changes.

                        try {
                            $stat = $this->backend->StatMessage($this->folderid, $change["id"]);
                            $message = $this->backend->GetMessage($this->folderid, $change["id"], $this->contentparameters);
                        }
                        catch (StatusException $e) {
                            // Transient fetch failure (e.g. a one-off empty FETCH response from
                            // the IMAP server). The step below only advances when no exception
                            // propagates, so an uncaught error would retry this single message
                            // until the WBXML loop guard aborts the whole request.
                            // Retry once after a short backoff; if it still fails, skip the
                            // message for this pass - the next sync picks it up again.
                            if ($e->getCode() != SYNC_ITEMOPERATIONSSTATUS_INVALIDATT) {
                                throw $e;
                            }
                            ZLog::Write(LOGLEVEL_WARN, sprintf("ExportChangesDiff->Synchronize(): transient error reading message '%s' from folder '%s' (code: %d, %s), retrying once after backoff", $change["id"], $this->folderid, $e->getCode(), $e->getMessage()));
                            sleep(3);
                            try {
                                $stat = $this->backend->StatMessage($this->folderid, $change["id"]);
                                $message = $this->backend->GetMessage($this->folderid, $change["id"], $this->contentparameters);
                            }
                            catch (StatusException $e2) {
                                if ($e2->getCode() != SYNC_ITEMOPERATIONSSTATUS_INVALIDATT) {
                                    throw $e2;
                                }
                                ZLog::Write(LOGLEVEL_WARN, sprintf("ExportChangesDiff->Synchronize(): message '%s' from folder '%s' still not readable after retry (code: %d, %s), skipping it for this pass", $change["id"], $this->folderid, $e2->getCode(), $e2->getMessage()));
                                $stat = false;
                                $message = false;
                            }
                        }

                        // copy the flag to the message
                        if($message)
                            $message->flags = (isset($change["flags"])) ? $change["flags"] : 0;

                        if($stat && $message) {
                            if($this->flags & BACKEND_DISCARD_DATA || $this->importer->ImportMessageChange($change["id"], $message) == true)
                                $this->updateState("change", $stat);
                        }
                        break;
                    case "delete":
                        if($this->flags & BACKEND_DISCARD_DATA || $this->importer->ImportMessageDeletion($change["id"]) == true)
                            $this->updateState("delete", $change);
                        break;
                    case "flags":
                        if($this->flags & BACKEND_DISCARD_DATA || $this->importer->ImportMessageReadFlag($change["id"], $change["flags"]) == true)
                            $this->updateState("flags", $change);
                        break;
                    case "move":
                        if($this->flags & BACKEND_DISCARD_DATA || $this->importer->ImportMessageMove($change["id"], $change["parent"]) == true)
                            $this->updateState("move", $change);
                        break;
                }

                $this->step++;

                $progress = array();
                $progress["steps"] = count($this->changes);
                $progress["progress"] = $this->step;

                return $progress;
            } else {
                return false;
            }
        }
    }
}
