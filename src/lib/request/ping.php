<?php
/***********************************************
* File      :   ping.php
* Project   :   Z-Push
* Descr     :   Provides the PING command
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

class Ping extends RequestProcessor {

    /**
     * Handles the Ping command
     *
     * @param int       $commandCode
     *
     * @access public
     * @return boolean
     */
    public function Handle($commandCode) {
        $interval = (defined('PING_INTERVAL') && PING_INTERVAL > 0) ? PING_INTERVAL : 30;
        $pingstatus = false;
        $fakechanges = array();
        $foundchanges = false;

        // Contains all requested folders (containers)
        $sc = new SyncCollections();

        // Load all collections - do load states and check permissions
        try {
            $sc->LoadAllCollections(true, true, true);
        }
        catch (StateNotFoundException $snfex) {
            $pingstatus = SYNC_PINGSTATUS_FOLDERHIERSYNCREQUIRED;
            self::$topCollector->AnnounceInformation("StateNotFoundException: require HierarchySync", true);
        }
        catch (StateInvalidException $snfex) {
            // we do not have a ping status for this, but SyncCollections should have generated fake changes for the folders which are broken
            $fakechanges = $sc->GetChangedFolderIds();
            $foundchanges = true;

            self::$topCollector->AnnounceInformation("StateInvalidException: force sync", true);
        }
        catch (StatusException $stex) {
            $pingstatus = SYNC_PINGSTATUS_FOLDERHIERSYNCREQUIRED;
            self::$topCollector->AnnounceInformation("StatusException: require HierarchySync", true);
        }

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandlePing(): reference PolicyKey for PING: %s", $sc->GetReferencePolicyKey()));

        // receive PING initialization data
        if(self::$decoder->getElementStartTag(SYNC_PING_PING)) {
            self::$topCollector->AnnounceInformation("Processing PING data");
            ZLog::Write(LOGLEVEL_DEBUG, "HandlePing(): initialization data received");

            if(self::$decoder->getElementStartTag(SYNC_PING_LIFETIME)) {
                $sc->SetLifetime(self::$decoder->getElementContent());
                self::$decoder->getElementEndTag();
            }

            if(($el = self::$decoder->getElementStartTag(SYNC_PING_FOLDERS)) && $el[EN_FLAGS] & EN_FLAGS_CONTENT) {
                // remove PingableFlag from all collections
                foreach ($sc as $folderid => $spa)
                    $spa->DelPingableFlag();

                while(self::$decoder->getElementStartTag(SYNC_PING_FOLDER)) {
                    while(1) {
                        if(self::$decoder->getElementStartTag(SYNC_PING_SERVERENTRYID)) {
                            $folderid = self::$decoder->getElementContent();
                            self::$decoder->getElementEndTag();
                        }
                        if(self::$decoder->getElementStartTag(SYNC_PING_FOLDERTYPE)) {
                            $class = self::$decoder->getElementContent();
                            self::$decoder->getElementEndTag();
                        }

                        $e = self::$decoder->peek();
                        if($e[EN_TYPE] == EN_TYPE_ENDTAG) {
                            self::$decoder->getElementEndTag();
                            break;
                        }
                    }

                    $spa = $sc->GetCollection($folderid);
                    if (! $spa) {
                        // The requested collection is not synchronized.
                        // check if the HierarchyCache is available, if not, trigger a HierarchySync
                        try {
                            self::$deviceManager->GetFolderClassFromCacheByID($folderid);
                        }
                        catch (NoHierarchyCacheAvailableException $nhca) {
                            ZLog::Write(LOGLEVEL_INFO, sprintf("HandlePing(): unknown collection '%s', triggering HierarchySync", $folderid));
                            $pingstatus = SYNC_PINGSTATUS_FOLDERHIERSYNCREQUIRED;
                        }

                        // Trigger a Sync request because then the device will be forced to resync this folder.
                        $fakechanges[$folderid] = 1;
                        $foundchanges = true;
                    }
                    else if ($class == $spa->GetContentClass()) {
                        $spa->SetPingableFlag(true);
                        ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandlePing(): using saved sync state for '%s' id '%s'", $spa->GetContentClass(), $folderid));
                    }

                }
                if(!self::$decoder->getElementEndTag())
                    return false;
            }
            if(!self::$decoder->getElementEndTag())
                return false;

            // save changed data
            foreach ($sc as $folderid => $spa)
                $sc->SaveCollection($spa);
        } // END SYNC_PING_PING

        // Check for changes on the default LifeTime, set interval and ONLY on pingable collections
        try {
            if (empty($fakechanges)) {
                $foundchanges = $sc->CheckForChanges($sc->GetLifetime(), $interval, true);
            }
        }
        catch (StatusException $ste) {
            switch($ste->getCode()) {
                case SyncCollections::ERROR_NO_COLLECTIONS:
                    $pingstatus = SYNC_PINGSTATUS_FAILINGPARAMS;
                    break;
                case SyncCollections::ERROR_WRONG_HIERARCHY:
                    $pingstatus = SYNC_PINGSTATUS_FOLDERHIERSYNCREQUIRED;
                    self::$deviceManager->AnnounceProcessStatus(false, $pingstatus);
                    break;

            }
        }

        self::$encoder->StartWBXML();
        self::$encoder->startTag(SYNC_PING_PING);
        {
            self::$encoder->startTag(SYNC_PING_STATUS);
            if (isset($pingstatus) && $pingstatus)
                self::$encoder->content($pingstatus);
            else
                self::$encoder->content($foundchanges ? SYNC_PINGSTATUS_CHANGES : SYNC_PINGSTATUS_HBEXPIRED);
            self::$encoder->endTag();

            if (! $pingstatus) {
                self::$encoder->startTag(SYNC_PING_FOLDERS);

                if (empty($fakechanges))
                    $changes = $sc->GetChangedFolderIds();
                else
                    $changes = $fakechanges;

                foreach ($changes as $folderid => $changecount) {
                    if ($changecount > 0) {
                        self::$encoder->startTag(SYNC_PING_FOLDER);
                        self::$encoder->content($folderid);
                        self::$encoder->endTag();
                        if (empty($fakechanges))
                            self::$topCollector->AnnounceInformation(sprintf("Found change in %s", $sc->GetCollection($folderid)->GetContentClass()), true);
                    }
                }
                self::$encoder->endTag();
            }
        }
        self::$encoder->endTag();

        return true;
    }
}
?>