<?php
/***********************************************
* File      :   sync.php
* Project   :   Z-Push
* Descr     :   Provides the SYNC command
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

class Sync extends RequestProcessor {
    // Ignored SMS identifier
    const ZPUSHIGNORESMS = "ZPISMS";
    private $importer;

    /**
     * Handles the Sync command
     * Performs the synchronization of messages
     *
     * @param int       $commandCode
     *
     * @access public
     * @return boolean
     */
    public function Handle($commandCode) {
        // Contains all requested folders (containers)
        $sc = new SyncCollections();
        $status = SYNC_STATUS_SUCCESS;
        $wbxmlproblem = false;
        $emptysync = false;

        // Start Synchronize
        if(self::$decoder->getElementStartTag(SYNC_SYNCHRONIZE)) {

            // AS 1.0 sends version information in WBXML
            if(self::$decoder->getElementStartTag(SYNC_VERSION)) {
                $sync_version = self::$decoder->getElementContent();
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("WBXML sync version: '%s'", $sync_version));
                if(!self::$decoder->getElementEndTag())
                    return false;
            }

            // Synching specified folders
            // Android still sends heartbeat sync even if all syncfolders are disabled.
            // Check if Folders tag is empty (<Folders/>) and only sync if there are
            // some folders in the request. See ZP-172
            $startTag = self::$decoder->getElementStartTag(SYNC_FOLDERS);
            if(isset($startTag[EN_FLAGS]) && $startTag[EN_FLAGS]) {
                while(self::$decoder->getElementStartTag(SYNC_FOLDER)) {
                    $actiondata = array();
                    $actiondata["requested"] = true;
                    $actiondata["clientids"] = array();
                    $actiondata["modifyids"] = array();
                    $actiondata["removeids"] = array();
                    $actiondata["fetchids"] = array();
                    $actiondata["statusids"] = array();

                    // read class, synckey and folderid without SyncParameters Object for now
                    $class = $synckey = $folderid = false;

                    //for AS versions < 2.5
                    if(self::$decoder->getElementStartTag(SYNC_FOLDERTYPE)) {
                        $class = self::$decoder->getElementContent();
                        ZLog::Write(LOGLEVEL_DEBUG, sprintf("Sync folder: '%s'", $class));

                        if(!self::$decoder->getElementEndTag())
                            return false;
                    }

                    // SyncKey
                    if(self::$decoder->getElementStartTag(SYNC_SYNCKEY)) {
                        $synckey = "0";
                        if (($synckey = self::$decoder->getElementContent()) !== false) {
                            if(!self::$decoder->getElementEndTag()) {
                                return false;
                            }
                        }
                    }
                    else
                        return false;

                    // FolderId
                    if(self::$decoder->getElementStartTag(SYNC_FOLDERID)) {
                        $folderid = self::$decoder->getElementContent();

                        if(!self::$decoder->getElementEndTag())
                            return false;
                    }

                    // compatibility mode AS 1.0 - get folderid which was sent during GetHierarchy()
                    if (! $folderid && $class) {
                        $folderid = self::$deviceManager->GetFolderIdFromCacheByClass($class);
                    }

                    // folderid HAS TO BE known by now, so we retrieve the correct SyncParameters object for an update
                    try {
                        $spa = self::$deviceManager->GetStateManager()->GetSynchedFolderState($folderid);

                        // TODO remove resync of folders for < Z-Push 2 beta4 users
                        // this forces a resync of all states previous to Z-Push 2 beta4
                        if (! $spa instanceof SyncParameters)
                            throw new StateInvalidException("Saved state are not of type SyncParameters");

                        // new/resync requested
                        if ($synckey == "0")
                            $spa->RemoveSyncKey();
                        else if ($synckey !== false)
                            $spa->SetSyncKey($synckey);
                    }
                    catch (StateInvalidException $stie) {
                        $spa = new SyncParameters();
                        $status = SYNC_STATUS_INVALIDSYNCKEY;
                        self::$topCollector->AnnounceInformation("State invalid - Resync folder", true);
                        self::$deviceManager->ForceFolderResync($folderid);
                    }

                    // update folderid.. this might be a new object
                    $spa->SetFolderId($folderid);

                    if ($class !== false)
                        $spa->SetContentClass($class);

                    // Get class for as versions >= 12.0
                    if (! $spa->HasContentClass()) {
                        try {
                            $spa->SetContentClass(self::$deviceManager->GetFolderClassFromCacheByID($spa->GetFolderId()));
                            ZLog::Write(LOGLEVEL_DEBUG, sprintf("GetFolderClassFromCacheByID from Device Manager: '%s' for id:'%s'", $spa->GetContentClass(), $spa->GetFolderId()));
                        }
                        catch (NoHierarchyCacheAvailableException $nhca) {
                            $status = SYNC_STATUS_FOLDERHIERARCHYCHANGED;
                            self::$deviceManager->ForceFullResync();
                        }
                    }

                    // done basic SPA initialization/loading -> add to SyncCollection
                    $sc->AddCollection($spa);
                    $sc->AddParameter($spa, "requested", true);

                    if ($spa->HasContentClass())
                        self::$topCollector->AnnounceInformation(sprintf("%s request", $spa->GetContentClass()), true);
                    else
                        ZLog::Write(LOGLEVEL_WARN, "Not possible to determine class of request. Request did not contain class and apparently there is an issue with the HierarchyCache.");

                    // SUPPORTED properties
                    if(self::$decoder->getElementStartTag(SYNC_SUPPORTED)) {
                        $supfields = array();
                        while(1) {
                            $el = self::$decoder->getElement();

                            if($el[EN_TYPE] == EN_TYPE_ENDTAG)
                                break;
                            else
                                $supfields[] = $el[EN_TAG];
                        }
                        self::$deviceManager->SetSupportedFields($spa->GetFolderId(), $supfields);
                    }

                    // Deletes as moves can be an empty tag as well as have value
                    if(self::$decoder->getElementStartTag(SYNC_DELETESASMOVES)) {
                        $spa->SetDeletesAsMoves(true);
                        if (($dam = self::$decoder->getElementContent()) !== false) {
                            $spa->SetDeletesAsMoves((boolean)$dam);
                            if(!self::$decoder->getElementEndTag()) {
                                return false;
                            }
                        }
                    }

                    // Get changes can be an empty tag as well as have value
                    // code block partly contributed by dw2412
                    if(self::$decoder->getElementStartTag(SYNC_GETCHANGES)) {
                        $sc->AddParameter($spa, "getchanges", true);
                        if (($gc = self::$decoder->getElementContent()) !== false) {
                            $sc->AddParameter($spa, "getchanges", $gc);
                            if(!self::$decoder->getElementEndTag()) {
                                return false;
                            }
                        }
                    }

                    if(self::$decoder->getElementStartTag(SYNC_WINDOWSIZE)) {
                        $spa->SetWindowSize(self::$decoder->getElementContent());

                        // also announce the currently requested window size to the DeviceManager
                        self::$deviceManager->SetWindowSize($spa->GetFolderId(), $spa->GetWindowSize());

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

                    // Do not truncate by default
                    $spa->SetTruncation(SYNC_TRUNCATION_ALL);

                    while(self::$decoder->getElementStartTag(SYNC_OPTIONS)) {
                        $firstOption = true;
                        while(1) {
                            // foldertype definition
                            if(self::$decoder->getElementStartTag(SYNC_FOLDERTYPE)) {
                                $foldertype = self::$decoder->getElementContent();
                                ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): specified options block with foldertype '%s'", $foldertype));

                                // switch the foldertype for the next options
                                $spa->UseCPO($foldertype);

                                // set to synchronize all changes. The mobile could overwrite this value
                                $spa->SetFilterType(SYNC_FILTERTYPE_ALL);

                                if(!self::$decoder->getElementEndTag())
                                    return false;
                            }
                            // if no foldertype is defined, use default cpo
                            else if ($firstOption){
                                $spa->UseCPO();
                                // set to synchronize all changes. The mobile could overwrite this value
                                $spa->SetFilterType(SYNC_FILTERTYPE_ALL);
                            }
                            $firstOption = false;

                            if(self::$decoder->getElementStartTag(SYNC_FILTERTYPE)) {
                                $spa->SetFilterType(self::$decoder->getElementContent());
                                if(!self::$decoder->getElementEndTag())
                                    return false;
                            }
                            if(self::$decoder->getElementStartTag(SYNC_TRUNCATION)) {
                                $spa->SetTruncation(self::$decoder->getElementContent());
                                if(!self::$decoder->getElementEndTag())
                                    return false;
                            }
                            if(self::$decoder->getElementStartTag(SYNC_RTFTRUNCATION)) {
                                $spa->SetRTFTruncation(self::$decoder->getElementContent());
                                if(!self::$decoder->getElementEndTag())
                                    return false;
                            }

                            if(self::$decoder->getElementStartTag(SYNC_MIMESUPPORT)) {
                                $spa->SetMimeSupport(self::$decoder->getElementContent());
                                if(!self::$decoder->getElementEndTag())
                                    return false;
                            }

                            if(self::$decoder->getElementStartTag(SYNC_MIMETRUNCATION)) {
                                $spa->SetMimeTruncation(self::$decoder->getElementContent());
                                if(!self::$decoder->getElementEndTag())
                                    return false;
                            }

                            if(self::$decoder->getElementStartTag(SYNC_CONFLICT)) {
                                $spa->SetConflict(self::$decoder->getElementContent());
                                if(!self::$decoder->getElementEndTag())
                                    return false;
                            }

                            while (self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_BODYPREFERENCE)) {
                                if(self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_TYPE)) {
                                    $bptype = self::$decoder->getElementContent();
                                    $spa->BodyPreference($bptype);
                                    if(!self::$decoder->getElementEndTag()) {
                                        return false;
                                    }
                                }

                                if(self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_TRUNCATIONSIZE)) {
                                    $spa->BodyPreference($bptype)->SetTruncationSize(self::$decoder->getElementContent());
                                    if(!self::$decoder->getElementEndTag())
                                        return false;
                                }

                                if(self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_ALLORNONE)) {
                                    $spa->BodyPreference($bptype)->SetAllOrNone(self::$decoder->getElementContent());
                                    if(!self::$decoder->getElementEndTag())
                                        return false;
                                }

                                if(self::$decoder->getElementStartTag(SYNC_AIRSYNCBASE_PREVIEW)) {
                                    $spa->BodyPreference($bptype)->SetPreview(self::$decoder->getElementContent());
                                    if(!self::$decoder->getElementEndTag())
                                        return false;
                                }

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

                    // limit items to be synchronized to the mobiles if configured
                    if (defined('SYNC_FILTERTIME_MAX') && SYNC_FILTERTIME_MAX > SYNC_FILTERTYPE_ALL &&
                        (!$spa->HasFilterType() || $spa->GetFilterType() == SYNC_FILTERTYPE_ALL || $spa->GetFilterType() > SYNC_FILTERTIME_MAX)) {
                            ZLog::Write(LOGLEVEL_DEBUG, sprintf("SYNC_FILTERTIME_MAX defined. Filter set to value: %s", SYNC_FILTERTIME_MAX));
                            $spa->SetFilterType(SYNC_FILTERTIME_MAX);
                    }

                    // set default conflict behavior from config if the device doesn't send a conflict resolution parameter
                    if (! $spa->HasConflict()) {
                        $spa->SetConflict(SYNC_CONFLICT_DEFAULT);
                    }

                    // Check if the hierarchycache is available. If not, trigger a HierarchySync
                    if (self::$deviceManager->IsHierarchySyncRequired()) {
                        $status = SYNC_STATUS_FOLDERHIERARCHYCHANGED;
                        ZLog::Write(LOGLEVEL_DEBUG, "HierarchyCache is also not available. Triggering HierarchySync to device");
                    }

                    if(self::$decoder->getElementStartTag(SYNC_PERFORM)) {
                        // We can not proceed here as the content class is unknown
                        if ($status != SYNC_STATUS_SUCCESS) {
                            ZLog::Write(LOGLEVEL_WARN, "Ignoring all incoming actions as global status indicates problem.");
                            $wbxmlproblem = true;
                            break;
                        }

                        $performaction = true;

                        // unset the importer
                        $this->importer = false;

                        $nchanges = 0;
                        while(1) {
                            // ADD, MODIFY, REMOVE or FETCH
                            $element = self::$decoder->getElement();

                            if($element[EN_TYPE] != EN_TYPE_STARTTAG) {
                                self::$decoder->ungetElement($element);
                                break;
                            }

                            if ($status == SYNC_STATUS_SUCCESS)
                                $nchanges++;

                            // Foldertype sent when synching SMS
                            if(self::$decoder->getElementStartTag(SYNC_FOLDERTYPE)) {
                                $foldertype = self::$decoder->getElementContent();
                                ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): incoming data with foldertype '%s'", $foldertype));

                                if(!self::$decoder->getElementEndTag())
                                return false;
                            }
                            else
                                $foldertype = false;

                            if(self::$decoder->getElementStartTag(SYNC_SERVERENTRYID)) {
                                $serverid = self::$decoder->getElementContent();

                                if(!self::$decoder->getElementEndTag()) // end serverid
                                    return false;
                            }
                            else
                                $serverid = false;

                            if(self::$decoder->getElementStartTag(SYNC_CLIENTENTRYID)) {
                                $clientid = self::$decoder->getElementContent();

                                if(!self::$decoder->getElementEndTag()) // end clientid
                                    return false;
                            }
                            else
                                $clientid = false;

                            // Get the SyncMessage if sent
                            if(self::$decoder->getElementStartTag(SYNC_DATA)) {
                                $message = ZPush::getSyncObjectFromFolderClass($spa->GetContentClass());
                                $message->Decode(self::$decoder);

                                // set Ghosted fields
                                $message->emptySupported(self::$deviceManager->GetSupportedFields($spa->GetFolderId()));
                                if(!self::$decoder->getElementEndTag()) // end applicationdata
                                    return false;
                            }
                            else
                                $message = false;

                            switch($element[EN_TAG]) {
                                case SYNC_FETCH:
                                    array_push($actiondata["fetchids"], $serverid);
                                    break;
                                default:
                                    // get the importer
                                    if ($this->importer == false)
                                        $status = $this->getImporter($sc, $spa, $actiondata);

                                    if ($status == SYNC_STATUS_SUCCESS)
                                        $this->importMessage($spa, $actiondata, $element[EN_TAG], $message, $clientid, $serverid, $foldertype, $nchanges);
                                    else
                                        ZLog::Write(LOGLEVEL_WARN, "Ignored incoming change, global status indicates problem.");

                                    break;
                            }

                            if ($actiondata["fetchids"])
                                self::$topCollector->AnnounceInformation(sprintf("Fetching %d", $nchanges),true);
                            else
                                self::$topCollector->AnnounceInformation(sprintf("Incoming %d", $nchanges),($nchanges>0)?true:false);

                            if(!self::$decoder->getElementEndTag()) // end add/change/delete/move
                                return false;
                        }

                        if ($status == SYNC_STATUS_SUCCESS && $this->importer !== false) {
                            ZLog::Write(LOGLEVEL_INFO, sprintf("Processed '%d' incoming changes", $nchanges));
                            try {
                                // Save the updated state, which is used for the exporter later
                                $sc->AddParameter($spa, "state", $this->importer->GetState());
                            }
                            catch (StatusException $stex) {
                               $status = $stex->getCode();
                            }
                        }

                        if(!self::$decoder->getElementEndTag()) // end PERFORM
                            return false;
                    }

                    // save the failsave state
                    if (!empty($actiondata["statusids"])) {
                        unset($actiondata["failstate"]);
                        $actiondata["failedsyncstate"] = $sc->GetParameter($spa, "state");
                        self::$deviceManager->GetStateManager()->SetSyncFailState($actiondata);
                    }

                    // save actiondata
                    $sc->AddParameter($spa, "actiondata", $actiondata);

                    if(!self::$decoder->getElementEndTag()) // end collection
                        return false;

                    // AS14 does not send GetChanges anymore. We should do it if there were no incoming changes
                    if (!isset($performaction) && !$sc->GetParameter($spa, "getchanges") && $spa->HasSyncKey())
                        $sc->AddParameter($spa, "getchanges", true);
                } // END FOLDER

                if(!$wbxmlproblem && !self::$decoder->getElementEndTag()) // end collections
                    return false;
            } // end FOLDERS

            if (self::$decoder->getElementStartTag(SYNC_HEARTBEATINTERVAL)) {
                $hbinterval = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag()) // SYNC_HEARTBEATINTERVAL
                    return false;
            }

            if (self::$decoder->getElementStartTag(SYNC_WAIT)) {
                $wait = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag()) // SYNC_WAIT
                    return false;

                // internally the heartbeat interval and the wait time are the same
                // heartbeat is in seconds, wait in minutes
                $hbinterval = $wait * 60;
            }

            if (self::$decoder->getElementStartTag(SYNC_WINDOWSIZE)) {
                $sc->SetGlobalWindowSize(self::$decoder->getElementContent());
                if(!self::$decoder->getElementEndTag()) // SYNC_WINDOWSIZE
                    return false;
            }

            if(self::$decoder->getElementStartTag(SYNC_PARTIAL))
                $partial = true;
            else
                $partial = false;

            if(!$wbxmlproblem && !self::$decoder->getElementEndTag()) // end sync
                return false;
        }
        // we did not receive a SYNCHRONIZE block - assume empty sync
        else {
            $emptysync = true;
        }
        // END SYNCHRONIZE

        // check heartbeat/wait time
        if (isset($hbinterval)) {
            if ($hbinterval < 60 || $hbinterval > 3540) {
                $status = SYNC_STATUS_INVALIDWAITORHBVALUE;
                ZLog::Write(LOGLEVEL_WARN, sprintf("HandleSync(): Invalid heartbeat or wait value '%s'", $hbinterval));
            }
        }

        // Partial & Empty Syncs need saved data to proceed with synchronization
        if ($status == SYNC_STATUS_SUCCESS && ($emptysync === true || $partial === true) ) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): Partial or Empty sync requested. Retrieving data of synchronized folders."));

            // Load all collections - do not overwrite existing (received!), laod states and check permissions
            try {
                $sc->LoadAllCollections(false, true, true);
            }
            catch (StateNotFoundException $snfex) {
                $status = SYNC_STATUS_INVALIDSYNCKEY;
                self::$topCollector->AnnounceInformation("StateNotFoundException", true);
            }
            catch (StatusException $stex) {
               $status = SYNC_STATUS_FOLDERHIERARCHYCHANGED;
               self::$topCollector->AnnounceInformation(sprintf("StatusException code: %d", $status), true);
            }

            // update a few values
            foreach($sc as $folderid => $spa) {
                // manually set getchanges parameter for this collection
                $sc->AddParameter($spa, "getchanges", true);

                // set new global windowsize without marking the SPA as changed
                if ($sc->GetGlobalWindowSize())
                    $spa->SetWindowSize($sc->GetGlobalWindowSize(), false);

                // announce WindowSize to DeviceManager
                self::$deviceManager->SetWindowSize($folderid, $spa->GetWindowSize());
            }
            if (!$sc->HasCollections())
                $status = SYNC_STATUS_SYNCREQUESTINCOMPLETE;
        }

        // HEARTBEAT & Empty sync
        if ($status == SYNC_STATUS_SUCCESS && (isset($hbinterval) || $emptysync == true)) {
            $interval = (defined('PING_INTERVAL') && PING_INTERVAL > 0) ? PING_INTERVAL : 30;

            if (isset($hbinterval))
                $sc->SetLifetime($hbinterval);

            // states are lazy loaded - we have to make sure that they are there!
            foreach($sc as $folderid => $spa) {
                $fad = array();
                // if loading the states fails, we do not enter heartbeat, but we keep $status on SYNC_STATUS_SUCCESS
                // so when the changes are exported the correct folder gets an SYNC_STATUS_INVALIDSYNCKEY
                $loadstatus = $this->loadStates($sc, $spa, $fad);
            }

            if ($loadstatus = SYNC_STATUS_SUCCESS) {
                $foundchanges = false;

                // wait for changes
                try {
                    // if doing an empty sync, check only once for changes
                    if ($emptysync) {
                        $foundchanges = $sc->CountChanges();
                    }
                    // wait for changes
                    else {
                        ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): Entering Heartbeat mode"));
                        $foundchanges = $sc->CheckForChanges($sc->GetLifetime(), $interval);
                    }
                }
                catch (StatusException $stex) {
                   $status = SYNC_STATUS_FOLDERHIERARCHYCHANGED;
                   self::$topCollector->AnnounceInformation(sprintf("StatusException code: %d", $status), true);
                }

                // in case of an empty sync with no changes, we can reply with an empty response
                if ($emptysync && !$foundchanges){
                    ZLog::Write(LOGLEVEL_DEBUG, "No changes found for empty sync. Replying with empty response");
                    return true;
                }

                if ($foundchanges) {
                    foreach ($sc->GetChangedFolderIds() as $folderid => $changecount) {
                        // check if there were other sync requests for a folder during the heartbeat
                        $spa = $sc->GetCollection($folderid);
                        if ($changecount > 0 && $sc->WaitedForChanges() && self::$deviceManager->CheckHearbeatStateIntegrity($spa->GetFolderId(), $spa->GetUuid(), $spa->GetUuidCounter())) {
                            ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): heartbeat: found %d changes in '%s' which was already synchronized. Heartbeat aborted!", $changecount, $folderid));
                            $status = SYNC_COMMONSTATUS_SYNCSTATEVERSIONINVALID;
                        }
                        else
                            ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): heartbeat: found %d changes in '%s'", $changecount, $folderid));
                    }
                }
            }
        }

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): Start Output"));

        // Start the output
        self::$encoder->startWBXML();
        self::$encoder->startTag(SYNC_SYNCHRONIZE);
        {
            // global status
            // SYNC_COMMONSTATUS_* start with values from 101
            if ($status != SYNC_COMMONSTATUS_SUCCESS && $status > 100) {
                self::$encoder->startTag(SYNC_STATUS);
                    self::$encoder->content($status);
                self::$encoder->endTag();
            }
            else {
                self::$encoder->startTag(SYNC_FOLDERS);
                {
                    foreach($sc as $folderid => $spa) {
                        // get actiondata
                        $actiondata = $sc->GetParameter($spa, "actiondata");

                        if ($status == SYNC_STATUS_SUCCESS && (!$spa->GetContentClass() || !$spa->GetFolderId())) {
                            ZLog::Write(LOGLEVEL_ERROR, sprintf("HandleSync(): no content class or folderid found for collection."));
                            continue;
                        }

                        if (! $sc->GetParameter($spa, "requested"))
                            ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): partial sync for folder class '%s' with id '%s'", $spa->GetContentClass(), $spa->GetFolderId()));

                        // initialize exporter to get changecount
                        $changecount = 0;
                        if (isset($exporter))
                            unset($exporter);

                        // TODO we could check against $sc->GetChangedFolderIds() on heartbeat so we do not need to configure all exporter again
                        if($status == SYNC_STATUS_SUCCESS && ($sc->GetParameter($spa, "getchanges") || ! $spa->HasSyncKey())) {

                            //make sure the states are loaded
                            $status = $this->loadStates($sc, $spa, $actiondata);

                            if($status == SYNC_STATUS_SUCCESS) {
                                try {
                                    // Use the state from the importer, as changes may have already happened
                                    $exporter = self::$backend->GetExporter($spa->GetFolderId());

                                    if ($exporter === false)
                                        throw new StatusException(sprintf("HandleSync() could not get an exporter for folder id '%s'", $spa->GetFolderId()), SYNC_STATUS_FOLDERHIERARCHYCHANGED);
                                }
                                catch (StatusException $stex) {
                                   $status = $stex->getCode();
                                }
                                try {
                                    // Stream the messages directly to the PDA
                                    $streamimporter = new ImportChangesStream(self::$encoder, ZPush::getSyncObjectFromFolderClass($spa->GetContentClass()));

                                    if ($exporter !== false) {
                                        $exporter->Config($sc->GetParameter($spa, "state"));
                                        $exporter->ConfigContentParameters($spa->GetCPO());
                                        $exporter->InitializeExporter($streamimporter);

                                        $changecount = $exporter->GetChangeCount();
                                    }
                                }
                                catch (StatusException $stex) {
                                    if ($stex->getCode() === SYNC_FSSTATUS_CODEUNKNOWN && $spa->HasSyncKey())
                                        $status = SYNC_STATUS_INVALIDSYNCKEY;
                                    else
                                        $status = $stex->getCode();
                                }

                                if (! $spa->HasSyncKey())
                                    self::$topCollector->AnnounceInformation(sprintf("Exporter registered. %d objects queued.", $changecount), true);
                                else if ($status != SYNC_STATUS_SUCCESS)
                                    self::$topCollector->AnnounceInformation(sprintf("StatusException code: %d", $status), true);
                            }
                        }

                        if (isset($hbinterval) && $changecount == 0 && $status == SYNC_STATUS_SUCCESS) {
                            ZLog::Write(LOGLEVEL_DEBUG, "No changes found for heartbeat folder. Omitting empty output.");
                            continue;
                        }

                        // Get a new sync key to output to the client if any changes have been send or will are available
                        if (!empty($actiondata["modifyids"]) ||
                            !empty($actiondata["clientids"]) ||
                            !empty($actiondata["removeids"]) ||
                            $changecount > 0 || (! $spa->HasSyncKey() && $status == SYNC_STATUS_SUCCESS))
                                $spa->SetNewSyncKey(self::$deviceManager->GetStateManager()->GetNewSyncKey($spa->GetSyncKey()));

                        self::$encoder->startTag(SYNC_FOLDER);

                        if($spa->HasContentClass()) {
                            self::$encoder->startTag(SYNC_FOLDERTYPE);
                                self::$encoder->content($spa->GetContentClass());
                            self::$encoder->endTag();
                        }

                        self::$encoder->startTag(SYNC_SYNCKEY);
                        if($status == SYNC_STATUS_SUCCESS && $spa->HasNewSyncKey())
                            self::$encoder->content($spa->GetNewSyncKey());
                        else
                            self::$encoder->content($spa->GetSyncKey());
                        self::$encoder->endTag();

                        self::$encoder->startTag(SYNC_FOLDERID);
                            self::$encoder->content($spa->GetFolderId());
                        self::$encoder->endTag();

                        self::$encoder->startTag(SYNC_STATUS);
                            self::$encoder->content($status);
                        self::$encoder->endTag();

                        // announce failing status to the process loop detection
                        if ($status !== SYNC_STATUS_SUCCESS)
                            self::$deviceManager->AnnounceProcessStatus($spa->GetFolderId(), $status);

                        // Output IDs and status for incoming items & requests
                        if($status == SYNC_STATUS_SUCCESS && (
                            !empty($actiondata["clientids"]) ||
                            !empty($actiondata["modifyids"]) ||
                            !empty($actiondata["removeids"]) ||
                            !empty($actiondata["fetchids"]) )) {

                            self::$encoder->startTag(SYNC_REPLIES);
                            // output result of all new incoming items
                            foreach($actiondata["clientids"] as $clientid => $serverid) {
                                self::$encoder->startTag(SYNC_ADD);
                                    self::$encoder->startTag(SYNC_CLIENTENTRYID);
                                        self::$encoder->content($clientid);
                                    self::$encoder->endTag();
                                    if ($serverid) {
                                        self::$encoder->startTag(SYNC_SERVERENTRYID);
                                            self::$encoder->content($serverid);
                                        self::$encoder->endTag();
                                    }
                                    self::$encoder->startTag(SYNC_STATUS);
                                        self::$encoder->content((isset($actiondata["statusids"][$clientid])?$actiondata["statusids"][$clientid]:SYNC_STATUS_CLIENTSERVERCONVERSATIONERROR));
                                    self::$encoder->endTag();
                                self::$encoder->endTag();
                            }

                            // loop through modify operations which were not a success, send status
                            foreach($actiondata["modifyids"] as $serverid) {
                                if (isset($actiondata["statusids"][$serverid]) && $actiondata["statusids"][$serverid] !== SYNC_STATUS_SUCCESS) {
                                    self::$encoder->startTag(SYNC_MODIFY);
                                        self::$encoder->startTag(SYNC_SERVERENTRYID);
                                            self::$encoder->content($serverid);
                                        self::$encoder->endTag();
                                        self::$encoder->startTag(SYNC_STATUS);
                                            self::$encoder->content($actiondata["statusids"][$serverid]);
                                        self::$encoder->endTag();
                                    self::$encoder->endTag();
                                }
                            }

                            // loop through remove operations which were not a success, send status
                            foreach($actiondata["removeids"] as $serverid) {
                                if (isset($actiondata["statusids"][$serverid]) && $actiondata["statusids"][$serverid] !== SYNC_STATUS_SUCCESS) {
                                    self::$encoder->startTag(SYNC_REMOVE);
                                        self::$encoder->startTag(SYNC_SERVERENTRYID);
                                            self::$encoder->content($serverid);
                                        self::$encoder->endTag();
                                        self::$encoder->startTag(SYNC_STATUS);
                                            self::$encoder->content($actiondata["statusids"][$serverid]);
                                        self::$encoder->endTag();
                                    self::$encoder->endTag();
                                }
                            }

                            if (!empty($actiondata["fetchids"]))
                                self::$topCollector->AnnounceInformation(sprintf("Fetching %d objects ", count($actiondata["fetchids"])), true);

                            foreach($actiondata["fetchids"] as $id) {
                                $data = false;
                                try {
                                    $fetchstatus = SYNC_STATUS_SUCCESS;

                                    // if this is an additional folder the backend has to be setup correctly
                                    if (!self::$backend->Setup(ZPush::GetAdditionalSyncFolderStore($spa->GetFolderId())))
                                        throw new StatusException(sprintf("HandleSync(): could not Setup() the backend to fetch in folder id '%s'", $spa->GetFolderId()), SYNC_STATUS_OBJECTNOTFOUND);

                                    $data = self::$backend->Fetch($spa->GetFolderId(), $id, $spa->GetCPO());

                                    // check if the message is broken
                                    if (ZPush::GetDeviceManager(false) && ZPush::GetDeviceManager()->DoNotStreamMessage($id, $data)) {
                                        ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): message not to be streamed as requested by DeviceManager.", $id));
                                        $fetchstatus = SYNC_STATUS_CLIENTSERVERCONVERSATIONERROR;
                                    }
                                }
                                catch (StatusException $stex) {
                                   $fetchstatus = $stex->getCode();
                                }

                                self::$encoder->startTag(SYNC_FETCH);
                                    self::$encoder->startTag(SYNC_SERVERENTRYID);
                                        self::$encoder->content($id);
                                    self::$encoder->endTag();

                                    self::$encoder->startTag(SYNC_STATUS);
                                        self::$encoder->content($fetchstatus);
                                    self::$encoder->endTag();

                                    if($data !== false && $status == SYNC_STATUS_SUCCESS) {
                                        self::$encoder->startTag(SYNC_DATA);
                                            $data->Encode(self::$encoder);
                                        self::$encoder->endTag();
                                    }
                                    else
                                        ZLog::Write(LOGLEVEL_WARN, sprintf("Unable to Fetch '%s'", $id));
                                self::$encoder->endTag();

                            }
                            self::$encoder->endTag();
                        }

                        if($sc->GetParameter($spa, "getchanges") && $spa->HasFolderId() && $spa->HasContentClass() && $spa->HasSyncKey()) {
                            $windowSize = self::$deviceManager->GetWindowSize($spa->GetFolderId(), $spa->GetContentClass(), $spa->GetUuid(), $spa->GetUuidCounter(), $changecount);

                            if($changecount > $windowSize) {
                                self::$encoder->startTag(SYNC_MOREAVAILABLE, false, true);
                            }
                        }

                        // Stream outgoing changes
                        if($status == SYNC_STATUS_SUCCESS && $sc->GetParameter($spa, "getchanges") == true && $windowSize > 0) {
                            self::$topCollector->AnnounceInformation(sprintf("Streaming data of %d objects", (($changecount > $windowSize)?$windowSize:$changecount)));

                            // Output message changes per folder
                            self::$encoder->startTag(SYNC_PERFORM);

                            $n = 0;
                            while(1) {
                                try {
                                    $progress = $exporter->Synchronize();
                                    if(!is_array($progress))
                                        break;
                                    $n++;
                                }
                                catch (SyncObjectBrokenException $mbe) {
                                    $brokenSO = $mbe->GetSyncObject();
                                    if (!$brokenSO) {
                                        ZLog::Write(LOGLEVEL_ERROR, sprintf("HandleSync(): Catched SyncObjectBrokenException but broken SyncObject not available. This should be fixed in the backend."));
                                    }
                                    else {
                                        if (!isset($brokenSO->id)) {
                                            $brokenSO->id = "Unknown ID";
                                            ZLog::Write(LOGLEVEL_ERROR, sprintf("HandleSync(): Catched SyncObjectBrokenException but no ID of object set. This should be fixed in the backend."));
                                        }
                                        self::$deviceManager->AnnounceIgnoredMessage($spa->GetFolderId(), $brokenSO->id, $brokenSO);
                                    }
                                }

                                if($n >= $windowSize) {
                                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleSync(): Exported maxItems of messages: %d / %d", $n, $changecount));
                                    break;
                                }

                            }

                            // $progress is not an array when exporting the last message
                            // so we get the number to display from the streamimporter
                            if (isset($streamimporter)) {
                                $n = $streamimporter->GetImportedMessages();
                            }

                            self::$encoder->endTag();
                            self::$topCollector->AnnounceInformation(sprintf("Outgoing %d objects%s", $n, ($n >= $windowSize)?" of ".$changecount:""), true);
                        }

                        self::$encoder->endTag();

                        // Save the sync state for the next time
                        if($spa->HasNewSyncKey()) {
                            self::$topCollector->AnnounceInformation("Saving state");

                            try {
                                if (isset($exporter) && $exporter)
                                    $state = $exporter->GetState();

                                // nothing exported, but possibly imported - get the importer state
                                else if ($sc->GetParameter($spa, "state") !== null)
                                    $state = $sc->GetParameter($spa, "state");

                                // if a new request without state information (hierarchy) save an empty state
                                else if (! $spa->HasSyncKey())
                                    $state = "";
                            }
                            catch (StatusException $stex) {
                               $status = $stex->getCode();
                            }


                            if (isset($state) && $status == SYNC_STATUS_SUCCESS)
                                self::$deviceManager->GetStateManager()->SetSyncState($spa->GetNewSyncKey(), $state, $spa->GetFolderId());
                            else
                                ZLog::Write(LOGLEVEL_ERROR, sprintf("HandleSync(): error saving '%s' - no state information available", $spa->GetNewSyncKey()));
                        }

                        // save SyncParameters
                        if ($status == SYNC_STATUS_SUCCESS && empty($actiondata["fetchids"]))
                            $sc->SaveCollection($spa);

                    } // END foreach collection
                }
                self::$encoder->endTag(); //SYNC_FOLDERS
            }
        }
        self::$encoder->endTag(); //SYNC_SYNCHRONIZE

        return true;
    }

    /**
     * Loads the states and writes them into the SyncCollection Object and the actiondata failstate
     *
     * @param SyncCollection    $sc             SyncCollection object
     * @param SyncParameters    $spa            SyncParameters object
     * @param array             $actiondata     Actiondata array
     * @param boolean           $loadFailsave   (opt) default false - indicates if the failsave states should be loaded
     *
     * @access private
     * @return status           indicating if there were errors. If no errors, status is SYNC_STATUS_SUCCESS
     */
    private function loadStates($sc, $spa, &$actiondata, $loadFailsave = false) {
        $status = SYNC_STATUS_SUCCESS;

        if ($sc->GetParameter($spa, "state") == null) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("Sync->loadStates(): loading states for folder '%s'",$spa->GetFolderId()));

            try {
                $sc->AddParameter($spa, "state", self::$deviceManager->GetStateManager()->GetSyncState($spa->GetSyncKey()));

                if ($loadFailsave) {
                    // if this request was made before, there will be a failstate available
                    $actiondata["failstate"] = self::$deviceManager->GetStateManager()->GetSyncFailState();
                }

                // if this is an additional folder the backend has to be setup correctly
                if (!self::$backend->Setup(ZPush::GetAdditionalSyncFolderStore($spa->GetFolderId())))
                    throw new StatusException(sprintf("HandleSync() could not Setup() the backend for folder id '%s'", $spa->GetFolderId()), SYNC_STATUS_FOLDERHIERARCHYCHANGED);
            }
            catch (StateNotFoundException $snfex) {
                $status = SYNC_STATUS_INVALIDSYNCKEY;
                self::$topCollector->AnnounceInformation("StateNotFoundException", true);
            }
            catch (StatusException $stex) {
               $status = $stex->getCode();
               self::$topCollector->AnnounceInformation(sprintf("StatusException code: %d", $status), true);
            }
        }

        return $status;
    }

    /**
     * Initializes the importer for the SyncParameters folder, loads necessary
     * states (incl. failsave states) and initializes the conflict detection
     *
     * @param SyncCollection    $sc             SyncCollection object
     * @param SyncParameters    $spa            SyncParameters object
     * @param array             $actiondata     Actiondata array
     *
     * @access private
     * @return status           indicating if there were errors. If no errors, status is SYNC_STATUS_SUCCESS
     */
    private function getImporter($sc, $spa, &$actiondata) {
        ZLog::Write(LOGLEVEL_DEBUG, "Sync->getImporter(): initialize importer");
        $status = SYNC_STATUS_SUCCESS;

        // load the states with failsave data
        $status = $this->loadStates($sc, $spa, $actiondata, true);

        try {
            // Configure importer with last state
            $this->importer = self::$backend->GetImporter($spa->GetFolderId());

            // if something goes wrong, ask the mobile to resync the hierarchy
            if ($this->importer === false)
                throw new StatusException(sprintf("Sync->getImporter(): no importer for folder id '%s'", $spa->GetFolderId()), SYNC_STATUS_FOLDERHIERARCHYCHANGED);

            // if there is a valid state obtained after importing changes in a previous loop, we use that state
            if (isset($actiondata["failstate"]) && isset($actiondata["failstate"]["failedsyncstate"])) {
                $this->importer->Config($actiondata["failstate"]["failedsyncstate"], $spa->GetConflict());
            }
            else
                $this->importer->Config($sc->GetParameter($spa, "state"), $spa->GetConflict());
        }
        catch (StatusException $stex) {
           $status = $stex->getCode();
        }

        $this->importer->LoadConflicts($spa->GetCPO(), $sc->GetParameter($spa, "state"));

        return $status;
    }

    /**
     * Imports a message
     *
     * @param SyncParameters    $spa            SyncParameters object
     * @param array             $actiondata     Actiondata array
     * @param integer           $todo           WBXML flag indicating how message should be imported.
     *                                          Valid values: SYNC_ADD, SYNC_MODIFY, SYNC_REMOVE
     * @param SyncObject        $message        SyncObject message to be imported
     * @param string            $clientid       Client message identifier
     * @param string            $serverid       Server message identifier
     * @param string            $foldertype     On sms sync, this says "SMS", else false
     * @param integer           $messageCount   Counter of already imported messages
     *
     * @access private
     * @throws StatusException  in case the importer is not available
     * @return -                Message related status are returned in the actiondata.
     */
    private function importMessage($spa, &$actiondata, $todo, $message, $clientid, $serverid, $foldertype, $messageCount) {
        // the importer needs to be available!
        if ($this->importer == false)
            throw StatusException(sprintf("Sync->importMessage(): importer not available", SYNC_STATUS_SERVERERROR));

        // Detect incoming loop
        // messages which were created/removed before will not have the same action executed again
        // if a message is edited we perform this action "again", as the message could have been changed on the mobile in the meantime
        $ignoreMessage = false;
        if ($actiondata["failstate"]) {
            // message was ADDED before, do NOT add it again
            if ($todo == SYNC_ADD && $actiondata["failstate"]["clientids"][$clientid]) {
                $ignoreMessage = true;

                // make sure no messages are sent back
                self::$deviceManager->SetWindowSize($spa->GetFolderId(), 0);

                $actiondata["clientids"][$clientid] = $actiondata["failstate"]["clientids"][$clientid];
                $actiondata["statusids"][$clientid] = $actiondata["failstate"]["statusids"][$clientid];

                ZLog::Write(LOGLEVEL_WARN, sprintf("Mobile loop detected! Incoming new message '%s' was created on the server before. Replying with known new server id: %s", $clientid, $actiondata["clientids"][$clientid]));
            }

            // message was REMOVED before, do NOT attemp to remove it again
            if ($todo == SYNC_REMOVE && isset($actiondata["failstate"]["removeids"][$serverid])) {
                $ignoreMessage = true;

                // make sure no messages are sent back
                self::$deviceManager->SetWindowSize($spa->GetFolderId(), 0);

                $actiondata["removeids"][$serverid] = $actiondata["failstate"]["removeids"][$serverid];
                $actiondata["statusids"][$serverid] = $actiondata["failstate"]["statusids"][$serverid];

                ZLog::Write(LOGLEVEL_WARN, sprintf("Mobile loop detected! Message '%s' was deleted by the mobile before. Replying with known status: %s", $clientid, $actiondata["statusids"][$serverid]));
            }
        }

        if (!$ignoreMessage) {
            switch($todo) {
                case SYNC_MODIFY:
                    self::$topCollector->AnnounceInformation(sprintf("Saving modified message %d", $messageCount));
                    try {
                        $actiondata["modifyids"][] = $serverid;

                        // ignore sms messages
                        if ($foldertype == "SMS" || stripos($serverid, self::ZPUSHIGNORESMS) !== false) {
                            ZLog::Write(LOGLEVEL_DEBUG, "SMS sync are not supported. Ignoring message.");
                            // TODO we should update the SMS
                            $actiondata["statusids"][$serverid] = SYNC_STATUS_SUCCESS;
                        }
                        // check incoming message without logging WARN messages about errors
                        else if (!($message instanceof SyncObject) || !$message->Check(true)) {
                            $actiondata["statusids"][$serverid] = SYNC_STATUS_CLIENTSERVERCONVERSATIONERROR;
                        }
                        else {
                            if(isset($message->read)) {
                                // Currently, 'read' is only sent by the PDA when it is ONLY setting the read flag.
                                $this->importer->ImportMessageReadFlag($serverid, $message->read);
                            }
                            elseif (!isset($message->flag)) {
                                $this->importer->ImportMessageChange($serverid, $message);
                            }

                            // email todoflags - some devices send todos flags together with read flags,
                            // so they have to be handled separately
                            if (isset($message->flag)){
                                $this->importer->ImportMessageChange($serverid, $message);
                            }

                            $actiondata["statusids"][$serverid] = SYNC_STATUS_SUCCESS;
                        }
                    }
                    catch (StatusException $stex) {
                        $actiondata["statusids"][$serverid] = $stex->getCode();
                    }

                    break;
                case SYNC_ADD:
                    self::$topCollector->AnnounceInformation(sprintf("Creating new message from mobile %d", $messageCount));
                    try {
                        // ignore sms messages
                        if ($foldertype == "SMS") {
                            ZLog::Write(LOGLEVEL_DEBUG, "SMS sync are not supported. Ignoring message.");
                            // TODO we should create the SMS
                            // return a fake serverid which we can identify later
                            $actiondata["clientids"][$clientid] = self::ZPUSHIGNORESMS . $clientid;
                            $actiondata["statusids"][$clientid] = SYNC_STATUS_SUCCESS;
                        }
                        // check incoming message without logging WARN messages about errors
                        else if (!($message instanceof SyncObject) || !$message->Check(true)) {
                            $actiondata["clientids"][$clientid] = false;
                            $actiondata["statusids"][$clientid] = SYNC_STATUS_CLIENTSERVERCONVERSATIONERROR;
                        }
                        else {
                            $actiondata["clientids"][$clientid] = false;
                            $actiondata["clientids"][$clientid] = $this->importer->ImportMessageChange(false, $message);
                            $actiondata["statusids"][$clientid] = SYNC_STATUS_SUCCESS;
                        }
                    }
                    catch (StatusException $stex) {
                       $actiondata["statusids"][$clientid] = $stex->getCode();
                    }
                    break;
                case SYNC_REMOVE:
                    self::$topCollector->AnnounceInformation(sprintf("Deleting message removed on mobile %d", $messageCount));
                    try {
                        $actiondata["removeids"][] = $serverid;
                        // ignore sms messages
                        if ($foldertype == "SMS" || stripos($serverid, self::ZPUSHIGNORESMS) !== false) {
                            ZLog::Write(LOGLEVEL_DEBUG, "SMS sync are not supported. Ignoring message.");
                            // TODO we should delete the SMS
                            $actiondata["statusids"][$serverid] = SYNC_STATUS_SUCCESS;
                        }
                        else {
                            // if message deletions are to be moved, move them
                            if($spa->GetDeletesAsMoves()) {
                                $folderid = self::$backend->GetWasteBasket();

                                if($folderid) {
                                    $this->importer->ImportMessageMove($serverid, $folderid);
                                    $actiondata["statusids"][$serverid] = SYNC_STATUS_SUCCESS;
                                    break;
                                }
                                else
                                    ZLog::Write(LOGLEVEL_WARN, "Message should be moved to WasteBasket, but the Backend did not return a destination ID. Message is hard deleted now!");
                            }

                            $this->importer->ImportMessageDeletion($serverid);
                            $actiondata["statusids"][$serverid] = SYNC_STATUS_SUCCESS;
                        }
                    }
                    catch (StatusException $stex) {
                       $actiondata["statusids"][$serverid] = $stex->getCode();
                    }
                    break;
            }
            ZLog::Write(LOGLEVEL_DEBUG, "Sync->importMessage(): message imported");
        }
    }
}

?>