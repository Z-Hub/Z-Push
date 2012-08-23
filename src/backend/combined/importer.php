<?php
/***********************************************
* File      :   backend/combined/importer.php
* Project   :   Z-Push
* Descr     :   Importer class for the combined backend.
*
* Created   :   11.05.2010
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

class ImportChangesCombined implements IImportChanges {
    private $backend;
    private $folderid;
    private $icc;

    /**
     * Constructor of the ImportChangesCombined class
     *
     * @param object $backend
     * @param string $folderid
     * @param object $importer
     *
     * @access public
     */
    public function ImportChangesCombined(&$backend, $folderid = false, $icc = false) {
        $this->backend = $backend;
        $this->folderid = $folderid;
        $this->icc = &$icc;
    }

    /**
     * Loads objects which are expected to be exported with the state
     * Before importing/saving the actual message from the mobile, a conflict detection should be done
     *
     * @param ContentParameters         $contentparameters         class of objects
     * @param string                    $state
     *
     * @access public
     * @return boolean
     * @throws StatusException
     */
    public function LoadConflicts($contentparameters, $state) {
        if (!$this->icc) {
            ZLog::Write(LOGLEVEL_ERROR, "ImportChangesCombined->LoadConflicts() icc not configured");
            return false;
        }
        $this->icc->LoadConflicts($contentparameters, $state);
    }

    /**
     * Imports a single message
     *
     * @param string        $id
     * @param SyncObject    $message
     *
     * @access public
     * @return boolean/string               failure / id of message
     */
    public function ImportMessageChange($id, $message) {
        if (!$this->icc) {
            ZLog::Write(LOGLEVEL_ERROR, "ImportChangesCombined->ImportMessageChange() icc not configured");
            return false;
        }
        return $this->icc->ImportMessageChange($id, $message);
    }

    /**
     * Imports a deletion. This may conflict if the local object has been modified
     *
     * @param string        $id
     *
     * @access public
     * @return boolean
     */
    public function ImportMessageDeletion($id) {
        if (!$this->icc) {
            ZLog::Write(LOGLEVEL_ERROR, "ImportChangesCombined->ImportMessageDeletion() icc not configured");
            return false;
        }
        return $this->icc->ImportMessageDeletion($id);
    }

    /**
     * Imports a change in 'read' flag
     * This can never conflict
     *
     * @param string        $id
     * @param int           $flags
     *
     * @access public
     * @return boolean
     */
    public function ImportMessageReadFlag($id, $flags) {
        if (!$this->icc) {
            ZLog::Write(LOGLEVEL_ERROR, "ImportChangesCombined->ImportMessageReadFlag() icc not configured");
            return false;
        }
        return $this->icc->ImportMessageReadFlag($id, $flags);
    }

    /**
     * Imports a move of a message. This occurs when a user moves an item to another folder
     *
     * @param string        $id
     * @param string        $newfolder
     *
     * @access public
     * @return boolean
     */
    public function ImportMessageMove($id, $newfolder) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ImportChangesCombined->ImportMessageMove('%s', '%s')", $id, $newfolder));
        if (!$this->icc) {
            ZLog::Write(LOGLEVEL_ERROR, "ImportChangesCombined->ImportMessageMove icc not configured");
            return false;
        }
        if($this->backend->GetBackendId($this->folderid) != $this->backend->GetBackendId($newfolder)){
            ZLog::Write(LOGLEVEL_WARN, "ImportChangesCombined->ImportMessageMove() cannot move message between two backends");
            return false;
        }
        return $this->icc->ImportMessageMove($id, $this->backend->GetBackendFolder($newfolder));
    }


    /**----------------------------------------------------------------------------------------------------------
     * Methods to import hierarchy
     */

    /**
     * Imports a change on a folder
     *
     * @param object        $folder         SyncFolder
     *
     * @access public
     * @return boolean/string               status/id of the folder
     */
    public function ImportFolderChange($folder) {
        $id = $folder->serverid;
        $parent = $folder->parentid;
        ZLog::Write(LOGLEVEL_DEBUG, "ImportChangesCombined->ImportFolderChange() ".print_r($folder, 1));
        if($parent == '0') {
            if($id) {
                $backendid = $this->backend->GetBackendId($id);
            }
            else {
                $backendid = $this->backend->config['rootcreatefolderbackend'];
            }
        }
        else {
            $backendid = $this->backend->GetBackendId($parent);
            $parent = $this->backend->GetBackendFolder($parent);
        }

        if(!empty($this->backend->config['backends'][$backendid]['subfolder']) && $id == $backendid.$this->backend->config['delimiter'].'0') {
            ZLog::Write(LOGLEVEL_WARN, "ImportChangesCombined->ImportFolderChange() cannot change static folder");
            return false;
        }

        if($id != false) {
            if($backendid != $this->backend->GetBackendId($id)) {
                ZLog::Write(LOGLEVEL_WARN, "ImportChangesCombined->ImportFolderChange() cannot move folder between two backends");
                return false;
            }
            $id = $this->backend->GetBackendFolder($id);
        }

        $this->icc = $this->backend->getBackend($backendid)->GetImporter();
        $res = $this->icc->ImportFolderChange($folder);
        ZLog::Write(LOGLEVEL_DEBUG, 'ImportChangesCombined->ImportFolderChange() success');
        return $backendid.$this->backend->config['delimiter'].$res;
    }

    /**
     * Imports a folder deletion
     *
     * @param string        $id
     * @param string        $parent id
     *
     * @access public
     * @return boolean/int  success/SYNC_FOLDERHIERARCHY_STATUS
     */
    public function ImportFolderDeletion($id, $parent = false) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ImportChangesCombined->ImportFolderDeletion('%s', '%s'), $id, $parent"));
        $backendid = $this->backend->GetBackendId($id);
        if(!empty($this->backend->config['backends'][$backendid]['subfolder']) && $id == $backendid.$this->backend->config['delimiter'].'0') {
            ZLog::Write(LOGLEVEL_WARN, "ImportChangesCombined->ImportFolderDeletion() cannot change static folder");
            return false; //we can not change a static subfolder
        }

        $backend = $this->backend->GetBackend($id);
        $id = $this->backend->GetBackendFolder($id);

        if($parent != '0')
            $parent = $this->backend->GetBackendFolder($parent);

        $this->icc = $backend->GetImporter();
        $res = $this->icc->ImportFolderDeletion($id, $parent);
        ZLog::Write(LOGLEVEL_DEBUG, 'ImportChangesCombined->ImportFolderDeletion() success');
        return $res;
    }


    /**
     * Initializes the state and flags
     *
     * @param string        $state
     * @param int           $flags
     *
     * @access public
     * @return boolean      status flag
     */
    public function Config($state, $flags = 0) {
        ZLog::Write(LOGLEVEL_DEBUG, 'ImportChangesCombined->Config(...)');
        if (!$this->icc) {
            ZLog::Write(LOGLEVEL_ERROR, "ImportChangesCombined->Config() icc not configured");
            return false;
        }
        $this->icc->Config($state, $flags);
        ZLog::Write(LOGLEVEL_DEBUG, 'ImportChangesCombined->Config() success');
    }

    /**
     * Reads and returns the current state
     *
     * @access public
     * @return string
     */
    public function GetState() {
        if (!$this->icc) {
            ZLog::Write(LOGLEVEL_ERROR, "ImportChangesCombined->GetState() icc not configured");
            return false;
        }
        return $this->icc->GetState();
    }
}


/**
 * The ImportHierarchyChangesCombinedWrap class wraps the importer given in ExportChangesCombined->Config.
 * It prepends the backendid to all folderids and checks foldertypes.
 */

class ImportHierarchyChangesCombinedWrap {
    private $ihc;
    private $backend;
    private $backendid;

    /**
     * Constructor of the ImportChangesCombined class
     *
     * @param string $backendid
     * @param object $backend
     * @param object $ihc
     *
     * @access public
     */
    public function ImportHierarchyChangesCombinedWrap($backendid, &$backend, &$ihc) {
        ZLog::Write(LOGLEVEL_DEBUG, "ImportHierarchyChangesCombinedWrap->ImportHierarchyChangesCombinedWrap('$backendid',...)");
        $this->backendid = $backendid;
        $this->backend =& $backend;
        $this->ihc = &$ihc;
    }

    /**
     * Imports a change on a folder
     *
     * @param object        $folder         SyncFolder
     *
     * @access public
     * @return boolean/string               status/id of the folder
     */
    public function ImportFolderChange($folder) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ImportHierarchyChangesCombinedWrap->ImportFolderChange('%s')", $folder->serverid));
        $folder->serverid = $this->backendid.$this->backend->config['delimiter'].$folder->serverid;
        if($folder->parentid != '0' || !empty($this->backend->config['backends'][$this->backendid]['subfolder'])){
            $folder->parentid = $this->backendid.$this->backend->config['delimiter'].$folder->parentid;
        }
        if(isset($this->backend->config['folderbackend'][$folder->type]) && $this->backend->config['folderbackend'][$folder->type] != $this->backendid){
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("not using folder: '%s' ('%s')", $folder->displayname, $folder->serverid));
            return true;
        }
        ZLog::Write(LOGLEVEL_DEBUG, "ImportHierarchyChangesCombinedWrap->ImportFolderChange() success");
        return $this->ihc->ImportFolderChange($folder);
    }

    /**
     * Imports a folder deletion
     *
     * @param string        $id
     *
     * @access public
     *
     * @return boolean/int  success/SYNC_FOLDERHIERARCHY_STATUS
     */
    public function ImportFolderDeletion($id) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ImportHierarchyChangesCombinedWrap->ImportFolderDeletion('%s')", $id));
        return $this->ihc->ImportFolderDeletion($this->backendid.$this->backend->config['delimiter'].$id);
    }
}

?>