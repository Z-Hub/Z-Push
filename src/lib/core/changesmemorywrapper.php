<?php
/***********************************************
* File      :   changesmemorywrapper.php
* Project   :   Z-Push
* Descr     :   Class that collect changes in memory
*
* Created   :   18.08.2011
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


class ChangesMemoryWrapper extends HierarchyCache implements IImportChanges, IExportChanges {
    const CHANGE = 1;
    const DELETION = 2;

    private $changes;
    private $step;
    private $destinationImporter;
    private $exportImporter;

    /**
     * Constructor
     *
     * @access public
     * @return
     */
    public function ChangesMemoryWrapper() {
        $this->changes = array();
        $this->step = 0;
        parent::HierarchyCache();
    }

    /**
     * Only used to load additional folder sync information for hierarchy changes
     *
     * @param array    $state               current state of additional hierarchy folders
     *
     * @access public
     * @return boolean
     */
    public function Config($state, $flags = 0) {
        // we should never forward this changes to a backend
        if (!isset($this->destinationImporter)) {
            foreach($state as $addKey => $addFolder) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("ChangesMemoryWrapper->Config(AdditionalFolders) : process folder '%s'", $addFolder->displayname));
                if (isset($addFolder->NoBackendFolder) && $addFolder->NoBackendFolder == true) {
                    $hasRights = ZPush::GetBackend()->Setup($addFolder->Store, true, $addFolder->serverid);
                    // delete the folder on the device
                    if (! $hasRights) {
                        // delete the folder only if it was an additional folder before, else ignore it
                        $synchedfolder = $this->GetFolder($addFolder->serverid);
                        if (isset($synchedfolder->NoBackendFolder) && $synchedfolder->NoBackendFolder == true)
                            $this->ImportFolderDeletion($addFolder->serverid, $addFolder->parentid);
                        continue;
                    }
                }
                // add folder to the device - if folder is already on the device, nothing will happen
                $this->ImportFolderChange($addFolder);
            }

            // look for folders which are currently on the device if there are now not to be synched anymore
            $alreadyDeleted = $this->GetDeletedFolders();
            foreach ($this->ExportFolders(true) as $sid => $folder) {
                // we are only looking at additional folders
                if (isset($folder->NoBackendFolder)) {
                    // look if this folder is still in the list of additional folders and was not already deleted (e.g. missing permissions)
                    if (!array_key_exists($sid, $state) && !array_key_exists($sid, $alreadyDeleted)) {
                        ZLog::Write(LOGLEVEL_INFO, sprintf("ChangesMemoryWrapper->Config(AdditionalFolders) : previously synchronized folder '%s' is not to be synched anymore. Sending delete to mobile.", $folder->displayname));
                        $this->ImportFolderDeletion($folder->serverid, $folder->parentid);
                    }
                }
            }
        }
        return true;
    }


    /**
     * Implement interfaces which are never used
     */
    public function GetState() { return false;}
    public function LoadConflicts($contentparameters, $state) { return true; }
    public function ConfigContentParameters($contentparameters) { return true; }
    public function ImportMessageReadFlag($id, $flags) { return true; }
    public function ImportMessageMove($id, $newfolder) { return true; }

    /**----------------------------------------------------------------------------------------------------------
     * IImportChanges & destination importer
     */

    /**
     * Sets an importer where incoming changes should be sent to
     *
     * @param IImportChanges    $importer   message to be changed
     *
     * @access public
     * @return boolean
     */
    public function SetDestinationImporter(&$importer) {
        $this->destinationImporter = $importer;
    }

    /**
     * Imports a message change, which is imported into memory
     *
     * @param string        $id         id of message which is changed
     * @param SyncObject    $message    message to be changed
     *
     * @access public
     * @return boolean
     */
    public function ImportMessageChange($id, $message) {
        $this->changes[] = array(self::CHANGE, $id);
        return true;
    }

    /**
     * Imports a message deletion, which is imported into memory
     *
     * @param string        $id     id of message which is deleted
     *
     * @access public
     * @return boolean
     */
    public function ImportMessageDeletion($id) {
        $this->changes[] = array(self::DELETION, $id);
        return true;
    }

    /**
     * Checks if a message id is flagged as changed
     *
     * @param string        $id     message id
     *
     * @access public
     * @return boolean
     */
    public function IsChanged($id) {
        return (array_search(array(self::CHANGE, $id), $this->changes) === false) ? false:true;
    }

    /**
     * Checks if a message id is flagged as deleted
     *
     * @param string        $id     message id
     *
     * @access public
     * @return boolean
     */
    public function IsDeleted($id) {
       return (array_search(array(self::DELETION, $id), $this->changes) === false) ? false:true;
    }

    /**
     * Imports a folder change
     *
     * @param SyncFolder    $folder     folder to be changed
     *
     * @access public
     * @return boolean
     */
    public function ImportFolderChange($folder) {
        // if the destinationImporter is set, then this folder should be processed by another importer
        // instead of being loaded in memory.
        if (isset($this->destinationImporter)) {
            // normally the $folder->type is not set, but we need this value to check if the change operation is permitted
            // e.g. system folders can normally not be changed - set the type from cache and let the destinationImporter decide
            if (!isset($folder->type)) {
                $cacheFolder = $this->GetFolder($folder->serverid);
                $folder->type = $cacheFolder->type;
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("ChangesMemoryWrapper->ImportFolderChange(): Set foldertype for folder '%s' from cache as it was not sent: '%s'", $folder->displayname, $folder->type));
            }

            $ret = $this->destinationImporter->ImportFolderChange($folder);

            // if the operation was sucessfull, update the HierarchyCache
            if ($ret) {
                // for folder creation, the serverid is not set and has to be updated before
                if (!isset($folder->serverid) || $folder->serverid == "")
                    $folder->serverid = $ret;

                $this->AddFolder($folder);
            }
            return $ret;
        }
        // load into memory
        else {
            if (isset($folder->serverid)) {
                // The Zarafa HierarchyExporter exports all kinds of changes for folders (e.g. update no. of unread messages in a folder).
                // These changes are not relevant for the mobiles, as something changes but the relevant displayname and parentid
                // stay the same. These changes will be dropped and are not sent!
                $cacheFolder = $this->GetFolder($folder->serverid);
                if ($folder->equals($this->GetFolder($folder->serverid))) {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("ChangesMemoryWrapper->ImportFolderChange(): Change for folder '%s' will not be sent as modification is not relevant.", $folder->displayname));
                    return false;
                }

                // load this change into memory
                $this->changes[] = array(self::CHANGE, $folder);

                // HierarchyCache: already add/update the folder so changes are not sent twice (if exported twice)
                $this->AddFolder($folder);
                return true;
            }
            return false;
        }
    }

    /**
     * Imports a folder deletion
     *
     * @param string        $id
     * @param string        $parent     (opt) the parent id of the folders
     *
     * @access public
     * @return boolean
     */
    public function ImportFolderDeletion($id, $parent = false) {
        // if the forwarder is set, then this folder should be processed by another importer
        // instead of being loaded in mem.
        if (isset($this->destinationImporter)) {
            $ret = $this->destinationImporter->ImportFolderDeletion($id, $parent);

            // if the operation was sucessfull, update the HierarchyCache
            if ($ret)
                $this->DelFolder($id);

            return $ret;
        }
        else {
            // if this folder is not in the cache, the change does not need to be streamed to the mobile
            if ($this->GetFolder($id)) {

                // load this change into memory
                $this->changes[] = array(self::DELETION, $id, $parent);

                // HierarchyCache: delete the folder so changes are not sent twice (if exported twice)
                $this->DelFolder($id);
                return true;
            }
        }
    }


    /**----------------------------------------------------------------------------------------------------------
     * IExportChanges & destination importer
     */

    /**
     * Initializes the Exporter where changes are synchronized to
     *
     * @param IImportChanges    $importer
     *
     * @access public
     * @return boolean
     */
    public function InitializeExporter(&$importer) {
        $this->exportImporter = $importer;
        $this->step = 0;
        return true;
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
     * Synchronizes a change. Only HierarchyChanges will be Synchronized()
     *
     * @access public
     * @return array
     */
    public function Synchronize() {
        if($this->step < count($this->changes) && isset($this->exportImporter)) {

            $change = $this->changes[$this->step];

            if ($change[0] == self::CHANGE) {
                if (! $this->GetFolder($change[1]->serverid, true))
                    $change[1]->flags = SYNC_NEWMESSAGE;

                $this->exportImporter->ImportFolderChange($change[1]);
            }
            // deletion
            else {
                $this->exportImporter->ImportFolderDeletion($change[1], $change[2]);
            }
            $this->step++;

            // return progress array
            return array("steps" => count($this->changes), "progress" => $this->step);
        }
        else
            return false;
    }

    /**
     * Initializes a few instance variables
     * called after unserialization
     *
     * @access public
     * @return array
     */
    public function __wakeup() {
        $this->changes = array();
        $this->step = 0;
    }
}

?>