<?php
/***********************************************
* File      :   streamimporter.php
* Project   :   Z-Push
* Descr     :   sends changes directly to the wbxml stream
*
* Created   :   01.10.2007
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

class ImportChangesStream implements IImportChanges {
    private $encoder;
    private $objclass;
    private $seenObjects;
    private $importedMsgs;
    private $checkForIgnoredMessages;

    /**
     * Constructor of the StreamImporter
     *
     * @param WBXMLEncoder  $encoder        Objects are streamed to this encoder
     * @param SyncObject    $class          SyncObject class (only these are accepted when streaming content messages)
     *
     * @access public
     */
    public function ImportChangesStream(&$encoder, $class) {
        $this->encoder = &$encoder;
        $this->objclass = $class;
        $this->classAsString = (is_object($class))?get_class($class):'';
        $this->seenObjects = array();
        $this->importedMsgs = 0;
        $this->checkForIgnoredMessages = true;
    }

    /**
     * Implement interface - never used
     */
    public function Config($state, $flags = 0) { return true; }
    public function GetState() { return false;}
    public function LoadConflicts($contentparameters, $state) { return true; }

    /**
     * Imports a single message
     *
     * @param string        $id
     * @param SyncObject    $message
     *
     * @access public
     * @return boolean
     */
    public function ImportMessageChange($id, $message) {
        // ignore other SyncObjects
        if(!($message instanceof $this->classAsString))
            return false;

        // prevent sending the same object twice in one request
        if (in_array($id, $this->seenObjects)) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("Object '%s' discarded! Object already sent in this request.", $id));
            return true;
        }

        $this->importedMsgs++;
        $this->seenObjects[] = $id;

        // checks if the next message may cause a loop or is broken
        if (ZPush::GetDeviceManager()->DoNotStreamMessage($id, $message)) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("ImportChangesStream->ImportMessageChange('%s'): message ignored and requested to be removed from mobile", $id));

            // this is an internal operation & should not trigger an update in the device manager
            $this->checkForIgnoredMessages = false;
            $stat = $this->ImportMessageDeletion($id);
            $this->checkForIgnoredMessages = true;

            return $stat;
        }

        if ($message->flags === false || $message->flags === SYNC_NEWMESSAGE)
            $this->encoder->startTag(SYNC_ADD);
        else {
            // on update of an SyncEmail we only export the flags
            if($message instanceof SyncMail && isset($message->flag) && $message->flag instanceof SyncMailFlags) {
                $newmessage = new SyncMail();
                $newmessage->read = $message->read;
                $newmessage->flag = $message->flag;
                $message = $newmessage;
                unset($newmessage);
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("ImportChangesStream->ImportMessageChange('%s'): SyncMail message updated. Message content is striped, only flags are streamed.", $id));
            }

            $this->encoder->startTag(SYNC_MODIFY);
        }
            $this->encoder->startTag(SYNC_SERVERENTRYID);
                $this->encoder->content($id);
            $this->encoder->endTag();
            $this->encoder->startTag(SYNC_DATA);
                $message->Encode($this->encoder);
            $this->encoder->endTag();
        $this->encoder->endTag();

        return true;
    }

    /**
     * Imports a deletion
     *
     * @param string        $id
     *
     * @access public
     * @return boolean
     */
    public function ImportMessageDeletion($id) {
        if ($this->checkForIgnoredMessages) {
           ZPush::GetDeviceManager()->RemoveBrokenMessage($id);
        }

        $this->importedMsgs++;
        $this->encoder->startTag(SYNC_REMOVE);
            $this->encoder->startTag(SYNC_SERVERENTRYID);
                $this->encoder->content($id);
            $this->encoder->endTag();
        $this->encoder->endTag();

        return true;
    }

    /**
     * Imports a change in 'read' flag
     * Can only be applied to SyncMail (Email) requests
     *
     * @param string        $id
     * @param int           $flags - read/unread
     *
     * @access public
     * @return boolean
     */
    public function ImportMessageReadFlag($id, $flags) {
        if(!($this->objclass instanceof SyncMail))
            return false;

        $this->importedMsgs++;

        $this->encoder->startTag(SYNC_MODIFY);
            $this->encoder->startTag(SYNC_SERVERENTRYID);
                $this->encoder->content($id);
            $this->encoder->endTag();
            $this->encoder->startTag(SYNC_DATA);
                $this->encoder->startTag(SYNC_POOMMAIL_READ);
                    $this->encoder->content($flags);
                $this->encoder->endTag();
            $this->encoder->endTag();
        $this->encoder->endTag();

        return true;
    }

    /**
     * ImportMessageMove is not implemented, as this operation can not be streamed to a WBXMLEncoder
     *
     * @param string        $id
     * @param int           $flags      read/unread
     *
     * @access public
     * @return boolean
     */
    public function ImportMessageMove($id, $newfolder) {
        return true;
    }

    /**
     * Imports a change on a folder
     *
     * @param object        $folder     SyncFolder
     *
     * @access public
     * @return string       id of the folder
     */
    public function ImportFolderChange($folder) {
        // checks if the next message may cause a loop or is broken
        if (ZPush::GetDeviceManager(false) && ZPush::GetDeviceManager()->DoNotStreamMessage($folder->serverid, $folder)) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("ImportChangesStream->ImportFolderChange('%s'): folder ignored as requested by DeviceManager.", $folder->serverid));
            return true;
        }

        // send a modify flag if the folder is already known on the device
        if (isset($folder->flags) && $folder->flags === SYNC_NEWMESSAGE)
            $this->encoder->startTag(SYNC_FOLDERHIERARCHY_ADD);
        else
            $this->encoder->startTag(SYNC_FOLDERHIERARCHY_UPDATE);

        $folder->Encode($this->encoder);
        $this->encoder->endTag();

        return true;
    }

    /**
     * Imports a folder deletion
     *
     * @param string        $id
     * @param string        $parent id
     *
     * @access public
     * @return boolean
     */
    public function ImportFolderDeletion($id, $parent = false) {
        $this->encoder->startTag(SYNC_FOLDERHIERARCHY_REMOVE);
            $this->encoder->startTag(SYNC_FOLDERHIERARCHY_SERVERENTRYID);
                $this->encoder->content($id);
            $this->encoder->endTag();
        $this->encoder->endTag();

        return true;
    }

    /**
     * Returns the number of messages which were changed, deleted and had changed read status
     *
     * @access public
     * @return int
     */
    public function GetImportedMessages() {
        return $this->importedMsgs;
    }
}
?>