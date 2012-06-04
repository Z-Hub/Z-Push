<?php
/***********************************************
* File      :   iimportchanges.php
* Project   :   Z-Push
* Descr     :   IImportChanges interface. It's responsible for
*               importing (receiving) data, content and hierarchy changes.
*               This interface extends the IChanges interface.
*
* Created   :   02.01.2012
*
* Copyright 2007 - 2012 Zarafa Deutschland GmbH
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

interface IImportChanges extends IChanges {

    /**----------------------------------------------------------------------------------------------------------
     * Methods for to import contents
     */

    /**
     * Loads objects which are expected to be exported with the state
     * Before importing/saving the actual message from the mobile, a conflict detection should be done
     *
     * @param ContentParameters         $contentparameters
     * @param string                    $state
     *
     * @access public
     * @return boolean
     * @throws StatusException
     */
    public function LoadConflicts($contentparameters, $state);

    /**
     * Imports a single message
     *
     * @param string        $id
     * @param SyncObject    $message
     *
     * @access public
     * @return boolean/string               failure / id of message
     * @throws StatusException
     */
    public function ImportMessageChange($id, $message);

    /**
     * Imports a deletion. This may conflict if the local object has been modified
     *
     * @param string        $id
     *
     * @access public
     * @return boolean
     * @throws StatusException
     */
    public function ImportMessageDeletion($id);

    /**
     * Imports a change in 'read' flag
     * This can never conflict
     *
     * @param string        $id
     * @param int           $flags
     *
     * @access public
     * @return boolean
     * @throws StatusException
     */
    public function ImportMessageReadFlag($id, $flags);

    /**
     * Imports a move of a message. This occurs when a user moves an item to another folder
     *
     * @param string        $id
     * @param string        $newfolder      destination folder
     *
     * @access public
     * @return boolean
     * @throws StatusException
     */
    public function ImportMessageMove($id, $newfolder);


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
     * @throws StatusException
     */
    public function ImportFolderChange($folder);

    /**
     * Imports a folder deletion
     *
     * @param string        $id
     * @param string        $parent id
     *
     * @access public
     * @return boolean/int  success/SYNC_FOLDERHIERARCHY_STATUS
     * @throws StatusException
     */
    public function ImportFolderDeletion($id, $parent = false);

}

?>