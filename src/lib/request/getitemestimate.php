<?php
/***********************************************
* File      :   getitemestimate.php
* Project   :   Z-Push
* Descr     :   Provides the GETITEMESTIMATE command
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

class GetItemEstimate extends RequestProcessor {

    /**
     * Handles the GetItemEstimate command
     * Returns an estimation of how many items will be synchronized at the next sync
     * This is mostly used to show something in the progress bar
     *
     * @param int       $commandCode
     *
     * @access public
     * @return boolean
     */
    public function Handle($commandCode) {
        $sc = new SyncCollections();

        if(!self::$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_GETITEMESTIMATE))
            return false;

        if(!self::$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERS))
            return false;

        while(self::$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDER)) {
            $spa = new SyncParameters();
            $spastatus = false;

            if (Request::GetProtocolVersion() >= 14.0) {
                if(self::$decoder->getElementStartTag(SYNC_SYNCKEY)) {
                    try {
                        $spa->SetSyncKey(self::$decoder->getElementContent());
                    }
                    catch (StateInvalidException $siex) {
                        $spastatus = SYNC_GETITEMESTSTATUS_SYNCSTATENOTPRIMED;
                    }

                    if(!self::$decoder->getElementEndTag())
                        return false;
                }

                if(self::$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERID)) {
                    $spa->SetFolderId( self::$decoder->getElementContent());

                    if(!self::$decoder->getElementEndTag())
                        return false;
                }

                // conversation mode requested
                if(self::$decoder->getElementStartTag(SYNC_CONVERSATIONMODE)) {
                    $spa->SetConversationMode(true);
                    if(($conversationmode = self::$decoder->getElementContent()) !== false) {
                        $spa->SetConversationMode((boolean)$conversationmode);
                        if(!self::$decoder->getElementEndTag())
                            return false;
                    }
                }

                if(self::$decoder->getElementStartTag(SYNC_OPTIONS)) {
                    while(1) {
                        if(self::$decoder->getElementStartTag(SYNC_FILTERTYPE)) {
                            $spa->SetFilterType(self::$decoder->getElementContent());
                            if(!self::$decoder->getElementEndTag())
                                return false;
                        }

                        if(self::$decoder->getElementStartTag(SYNC_FOLDERTYPE)) {
                            $spa->SetContentClass(self::$decoder->getElementContent());
                            if(!self::$decoder->getElementEndTag())
                                return false;
                        }

                        if(self::$decoder->getElementStartTag(SYNC_MAXITEMS)) {
                            $spa->SetWindowSize($maxitems = self::$decoder->getElementContent());
                            if(!self::$decoder->getElementEndTag())
                                return false;
                        }

                        $e = self::$decoder->peek();
                        if($e[EN_TYPE] == EN_TYPE_ENDTAG) {
                            self::$decoder->getElementEndTag();
                            break;
                        }
                    }
                }
            }
            else {
                //get items estimate does not necessarily send the folder type
                if(self::$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERTYPE)) {
                    $spa->SetContentClass(self::$decoder->getElementContent());

                    if(!self::$decoder->getElementEndTag())
                        return false;
                }

                if(self::$decoder->getElementStartTag(SYNC_GETITEMESTIMATE_FOLDERID)) {
                    $spa->SetFolderId(self::$decoder->getElementContent());

                    if(!self::$decoder->getElementEndTag())
                        return false;
                }

                if(!self::$decoder->getElementStartTag(SYNC_FILTERTYPE))
                    return false;

                $spa->SetFilterType(self::$decoder->getElementContent());

                if(!self::$decoder->getElementEndTag())
                    return false;

                if(!self::$decoder->getElementStartTag(SYNC_SYNCKEY))
                    return false;

                try {
                    $spa->SetSyncKey(self::$decoder->getElementContent());
                }
                catch (StateInvalidException $siex) {
                    $spastatus = SYNC_GETITEMESTSTATUS_SYNCSTATENOTPRIMED;
                }

                if(!self::$decoder->getElementEndTag())
                    return false;
            }

            if(!self::$decoder->getElementEndTag())
                return false; //SYNC_GETITEMESTIMATE_FOLDER

            // Process folder data

            //In AS 14 request only collectionid is sent, without class
            if (! $spa->HasContentClass() && $spa->HasFolderId()) {
               try {
                    $spa->SetContentClass(self::$deviceManager->GetFolderClassFromCacheByID($spa->GetFolderId()));
                }
                catch (NoHierarchyCacheAvailableException $nhca) {
                    $spastatus = SYNC_GETITEMESTSTATUS_COLLECTIONINVALID;
                }
            }

            // compatibility mode AS 1.0 - get folderid which was sent during GetHierarchy()
            if (! $spa->HasFolderId() && $spa->HasContentClass()) {
                $spa->SetFolderId(self::$deviceManager->GetFolderIdFromCacheByClass($spa->GetContentClass()));
            }

            // Add collection to SC and load state
            $sc->AddCollection($spa);
            if ($spastatus) {
                // the CPO has a folder id now, so we can set the status
                $sc->AddParameter($spa, "status", $spastatus);
            }
            else {
                try {
                    $sc->AddParameter($spa, "state", self::$deviceManager->GetStateManager()->GetSyncState($spa->GetSyncKey()));

                    // if this is an additional folder the backend has to be setup correctly
                    if (!self::$backend->Setup(ZPush::GetAdditionalSyncFolderStore($spa->GetFolderId())))
                        throw new StatusException(sprintf("HandleGetItemEstimate() could not Setup() the backend for folder id '%s'", $spa->GetFolderId()), SYNC_GETITEMESTSTATUS_COLLECTIONINVALID);
                }
                catch (StateNotFoundException $snfex) {
                    // ok, the key is invalid. Question is, if the hierarchycache is still ok
                    //if not, we have to issue SYNC_GETITEMESTSTATUS_COLLECTIONINVALID which triggers a FolderSync
                    try {
                        self::$deviceManager->GetFolderClassFromCacheByID($spa->GetFolderId());
                        // we got here, so the HierarchyCache is ok
                        $sc->AddParameter($spa, "status", SYNC_GETITEMESTSTATUS_SYNCKKEYINVALID);
                    }
                    catch (NoHierarchyCacheAvailableException $nhca) {
                        $sc->AddParameter($spa, "status", SYNC_GETITEMESTSTATUS_COLLECTIONINVALID);
                    }

                    self::$topCollector->AnnounceInformation("StateNotFoundException ". $sc->GetParameter($spa, "status"), true);
                }
                catch (StatusException $stex) {
                    if ($stex->getCode() == SYNC_GETITEMESTSTATUS_COLLECTIONINVALID)
                        $sc->AddParameter($spa, "status", SYNC_GETITEMESTSTATUS_COLLECTIONINVALID);
                    else
                        $sc->AddParameter($spa, "status", SYNC_GETITEMESTSTATUS_SYNCSTATENOTPRIMED);
                    self::$topCollector->AnnounceInformation("StatusException ". $sc->GetParameter($spa, "status"), true);
                }
            }
        }
        if(!self::$decoder->getElementEndTag())
            return false; //SYNC_GETITEMESTIMATE_FOLDERS

        if(!self::$decoder->getElementEndTag())
            return false; //SYNC_GETITEMESTIMATE_GETITEMESTIMATE

        self::$encoder->startWBXML();
        self::$encoder->startTag(SYNC_GETITEMESTIMATE_GETITEMESTIMATE);
        {
            $status = SYNC_GETITEMESTSTATUS_SUCCESS;
            // look for changes in all collections

            try {
                $sc->CountChanges();
            }
            catch (StatusException $ste) {
                $status = SYNC_GETITEMESTSTATUS_COLLECTIONINVALID;
            }
            $changes = $sc->GetChangedFolderIds();

            foreach($sc as $folderid => $spa) {
                self::$encoder->startTag(SYNC_GETITEMESTIMATE_RESPONSE);
                {
                    if ($sc->GetParameter($spa, "status"))
                        $status = $sc->GetParameter($spa, "status");

                    self::$encoder->startTag(SYNC_GETITEMESTIMATE_STATUS);
                    self::$encoder->content($status);
                    self::$encoder->endTag();

                    self::$encoder->startTag(SYNC_GETITEMESTIMATE_FOLDER);
                    {
                        self::$encoder->startTag(SYNC_GETITEMESTIMATE_FOLDERTYPE);
                        self::$encoder->content($spa->GetContentClass());
                        self::$encoder->endTag();

                        self::$encoder->startTag(SYNC_GETITEMESTIMATE_FOLDERID);
                        self::$encoder->content($spa->GetFolderId());
                        self::$encoder->endTag();

                        if (isset($changes[$folderid]) && $changes[$folderid] !== false) {
                            self::$encoder->startTag(SYNC_GETITEMESTIMATE_ESTIMATE);
                            self::$encoder->content($changes[$folderid]);
                            self::$encoder->endTag();

                            if ($changes[$folderid] > 0)
                                self::$topCollector->AnnounceInformation(sprintf("%s %d changes", $spa->GetContentClass(), $changes[$folderid]), true);
                        }
                    }
                    self::$encoder->endTag();
                }
                self::$encoder->endTag();
            }
            if (array_sum($changes) == 0)
                self::$topCollector->AnnounceInformation("No changes found", true);
        }
        self::$encoder->endTag();

        return true;
    }
}
?>