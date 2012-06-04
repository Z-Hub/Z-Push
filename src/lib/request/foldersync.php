<?php
/***********************************************
* File      :   foldersync.php
* Project   :   Z-Push
* Descr     :   Provides the FOLDERSYNC command
*
* Created   :   16.02.2012
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

class FolderSync extends RequestProcessor {

    /**
     * Handles the FolderSync command
     *
     * @param int       $commandCode
     *
     * @access public
     * @return boolean
     */
    public function Handle ($commandCode) {
        // Maps serverid -> clientid for items that are received from the PIM
        $map = array();

        // Parse input
        if(!self::$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_FOLDERSYNC))
            return false;

        if(!self::$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_SYNCKEY))
            return false;

        $synckey = self::$decoder->getElementContent();

        if(!self::$decoder->getElementEndTag())
            return false;

        $status = SYNC_FSSTATUS_SUCCESS;
        $newsynckey = $synckey;
        try {
            $syncstate = self::$deviceManager->GetStateManager()->GetSyncState($synckey);

            // We will be saving the sync state under 'newsynckey'
            $newsynckey = self::$deviceManager->GetStateManager()->GetNewSyncKey($synckey);
        }
        catch (StateNotFoundException $snfex) {
                $status = SYNC_FSSTATUS_SYNCKEYERROR;
        }
        catch (StateInvalidException $sive) {
                $status = SYNC_FSSTATUS_SYNCKEYERROR;
        }

        // The ChangesWrapper caches all imports in-memory, so we can send a change count
        // before sending the actual data.
        // the HierarchyCache is notified and the changes from the PIM are transmitted to the actual backend
        $changesMem = self::$deviceManager->GetHierarchyChangesWrapper();

        // the hierarchyCache should now fully be initialized - check for changes in the additional folders
        $changesMem->Config(ZPush::GetAdditionalSyncFolders());

        // process incoming changes
        if(self::$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_CHANGES)) {
            // Ignore <Count> if present
            if(self::$decoder->getElementStartTag(SYNC_FOLDERHIERARCHY_COUNT)) {
                self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag())
                    return false;
            }

            // Process the changes (either <Add>, <Modify>, or <Remove>)
            $element = self::$decoder->getElement();

            if($element[EN_TYPE] != EN_TYPE_STARTTAG)
                return false;

            $importer = false;
            while(1) {
                $folder = new SyncFolder();
                if(!$folder->Decode(self::$decoder))
                    break;

                try {
                    if ($status == SYNC_FSSTATUS_SUCCESS && !$importer) {
                        // Configure the backends importer with last state
                        $importer = self::$backend->GetImporter();
                        $importer->Config($syncstate);
                        // the messages from the PIM will be forwarded to the backend
                        $changesMem->forwardImporter($importer);
                    }

                    if ($status == SYNC_FSSTATUS_SUCCESS) {
                        switch($element[EN_TAG]) {
                            case SYNC_ADD:
                            case SYNC_MODIFY:
                                $serverid = $changesMem->ImportFolderChange($folder);
                                break;
                            case SYNC_REMOVE:
                                $serverid = $changesMem->ImportFolderDeletion($folder);
                                break;
                        }

                        // TODO what does $map??
                        if($serverid)
                            $map[$serverid] = $folder->clientid;
                    }
                    else {
                        ZLog::Write(LOGLEVEL_WARN, sprintf("Request->HandleFolderSync(): ignoring incoming folderchange for folder '%s' as status indicates problem.", $folder->displayname));
                        self::$topCollector->AnnounceInformation("Incoming change ignored", true);
                    }
                }
                catch (StatusException $stex) {
                   $status = $stex->getCode();
                }
            }

            if(!self::$decoder->getElementEndTag())
                return false;
        }
        // no incoming changes
        else {
            // check for a potential process loop like described in Issue ZP-5
            if ($synckey != "0" && self::$deviceManager->IsHierarchyFullResyncRequired())
                $status = SYNC_FSSTATUS_SYNCKEYERROR;
                self::$deviceManager->AnnounceProcessStatus(false, $status);
        }

        if(!self::$decoder->getElementEndTag())
            return false;

        // We have processed incoming foldersync requests, now send the PIM
        // our changes

        // Output our WBXML reply now
        self::$encoder->StartWBXML();

        self::$encoder->startTag(SYNC_FOLDERHIERARCHY_FOLDERSYNC);
        {
            if ($status == SYNC_FSSTATUS_SUCCESS) {
                try {
                    // do nothing if this is an invalid device id (like the 'validate' Androids internal client sends)
                    if (!Request::IsValidDeviceID())
                        throw new StatusException(sprintf("Request::IsValidDeviceID() indicated that '%s' is not a valid device id", Request::GetDeviceID()), SYNC_FSSTATUS_SERVERERROR);

                    // Changes from backend are sent to the MemImporter and processed for the HierarchyCache.
                    // The state which is saved is from the backend, as the MemImporter is only a proxy.
                    $exporter = self::$backend->GetExporter();

                    $exporter->Config($syncstate);
                    $exporter->InitializeExporter($changesMem);

                    // Stream all changes to the ImportExportChangesMem
                    while(is_array($exporter->Synchronize()));

                    // get the new state from the backend
                    $newsyncstate = (isset($exporter))?$exporter->GetState():"";
                }
                catch (StatusException $stex) {
                    if ($stex->getCode() == SYNC_FSSTATUS_CODEUNKNOWN)
                        $status = SYNC_FSSTATUS_SYNCKEYERROR;
                    else
                        $status = $stex->getCode();
                }
            }

            self::$encoder->startTag(SYNC_FOLDERHIERARCHY_STATUS);
            self::$encoder->content($status);
            self::$encoder->endTag();

            if ($status == SYNC_FSSTATUS_SUCCESS) {
                self::$encoder->startTag(SYNC_FOLDERHIERARCHY_SYNCKEY);
                $synckey = ($changesMem->IsStateChanged()) ? $newsynckey : $synckey;
                self::$encoder->content($synckey);
                self::$encoder->endTag();

                // Stream folders directly to the PDA
                $streamimporter = new ImportChangesStream(self::$encoder, false);
                $changesMem->InitializeExporter($streamimporter);
                $changeCount = $changesMem->GetChangeCount();

                self::$encoder->startTag(SYNC_FOLDERHIERARCHY_CHANGES);
                {
                    self::$encoder->startTag(SYNC_FOLDERHIERARCHY_COUNT);
                    self::$encoder->content($changeCount);
                    self::$encoder->endTag();
                    while($changesMem->Synchronize());
                }
                self::$encoder->endTag();
                self::$topCollector->AnnounceInformation(sprintf("Outgoing %d folders",$changeCount), true);

                // everything fine, save the sync state for the next time
                if ($synckey == $newsynckey)
                    self::$deviceManager->GetStateManager()->SetSyncState($newsynckey, $newsyncstate);
            }
        }
        self::$encoder->endTag();

        return true;
    }
}
?>