<?php
/***********************************************
* File      :   mapiphpwrapper.php
* Project   :   Z-Push
* Descr     :   The ICS importer is very MAPI specific
*               and needs to be wrapped, because we
*               want all MAPI code to be separate from
*               the rest of z-push. To do so all
*               MAPI dependency are removed in this class.
*               All the other importers are based on
*               IChanges, not MAPI.
*
* Created   :   14.02.2011
*
* Copyright 2007 - 2016 Zarafa Deutschland GmbH
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

/**
 * This is the PHP wrapper which strips MAPI information from
 * the import interface of ICS. We get all the information about messages
 * from MAPI here which are sent to the next importer, which will
 * convert the data into WBXML which is streamed to the PDA
 */

class PHPWrapper {
    private $importer;
    private $mapiprovider;
    private $store;
    private $contentparameters;
    private $folderid;
    private $prefix;


    /**
     * Constructor of the PHPWrapper
     *
     * @param ressource         $session
     * @param ressource         $store
     * @param IImportChanges    $importer       incoming changes from ICS are forwarded here.
     * @param string            $folderid       the folder this wrapper was configured for.
     *
     * @access public
     * @return
     */
    public function __construct($session, $store, $importer, $folderid) {
        $this->importer = &$importer;
        $this->store = $store;
        $this->mapiprovider = new MAPIProvider($session, $this->store);
        $this->folderid = $folderid;
        $this->prefix = '';

        if ($folderid) {
            $folderidHex = bin2hex($folderid);
            $folderid = ZPush::GetDeviceManager()->GetFolderIdForBackendId($folderidHex);
            if ($folderid != $folderidHex) {
                $this->prefix = $folderid . ':';
            }
        }
    }

    /**
     * Configures additional parameters used for content synchronization
     *
     * @param ContentParameters         $contentparameters
     *
     * @access public
     * @return boolean
     * @throws StatusException
     */
    public function ConfigContentParameters($contentparameters) {
        $this->contentparameters = $contentparameters;
    }

    /**
     * Implement MAPI interface
     */
    public function Config($stream, $flags = 0) {}
    public function GetLastError($hresult, $ulflags, &$lpmapierror) {}
    public function UpdateState($stream) { }

    /**
     * Imports a single message
     *
     * @param array         $props
     * @param long          $flags
     * @param object        $retmapimessage
     *
     * @access public
     * @return long
     */
    public function ImportMessageChange($props, $flags, $retmapimessage) {
        $sourcekey = $props[PR_SOURCE_KEY];
        $parentsourcekey = $props[PR_PARENT_SOURCE_KEY];
        $entryid = mapi_msgstore_entryidfromsourcekey($this->store, $parentsourcekey, $sourcekey);

        if(!$entryid)
            return SYNC_E_IGNORE;

        $mapimessage = mapi_msgstore_openentry($this->store, $entryid);
        try {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("PHPWrapper->ImportMessageChange(): Getting message from MAPIProvider, sourcekey: '%s', parentsourcekey: '%s', entryid: '%s'", bin2hex($sourcekey), bin2hex($parentsourcekey), bin2hex($entryid)));

            // do not send private messages from shared folders to the device
            $sensitivity = mapi_getprops($mapimessage, array(PR_SENSITIVITY));
            $sharedUser = ZPush::GetAdditionalSyncFolderStore(bin2hex($this->folderid));
            if ($sharedUser != false && $sharedUser != 'SYSTEM' && isset($sensitivity[PR_SENSITIVITY]) && $sensitivity[PR_SENSITIVITY] >= SENSITIVITY_PRIVATE) {
                ZLog::Write(LOGLEVEL_DEBUG, "PHPWrapper->ImportMessageChange(): ignoring private message from a shared folder");
                return SYNC_E_IGNORE;
            }

            $message = $this->mapiprovider->GetMessage($mapimessage, $this->contentparameters);
        }
        catch (SyncObjectBrokenException $mbe) {
            $brokenSO = $mbe->GetSyncObject();
            if (!$brokenSO) {
                ZLog::Write(LOGLEVEL_ERROR, sprintf("PHPWrapper->ImportMessageChange(): Catched SyncObjectBrokenException but broken SyncObject available"));
            }
            else {
                if (!isset($brokenSO->id)) {
                    $brokenSO->id = "Unknown ID";
                    ZLog::Write(LOGLEVEL_ERROR, sprintf("PHPWrapper->ImportMessageChange(): Catched SyncObjectBrokenException but no ID of object set"));
                }
                ZPush::GetDeviceManager()->AnnounceIgnoredMessage(false, $brokenSO->id, $brokenSO);
            }
            // tell MAPI to ignore the message
            return SYNC_E_IGNORE;
        }


        // substitute the MAPI SYNC_NEW_MESSAGE flag by a z-push proprietary flag
        if ($flags == SYNC_NEW_MESSAGE) $message->flags = SYNC_NEWMESSAGE;
        else $message->flags = $flags;

        $this->importer->ImportMessageChange($this->prefix.bin2hex($sourcekey), $message);
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("PHPWrapper->ImportMessageChange(): change for: '%s'", $this->prefix.bin2hex($sourcekey)));

        // Tell MAPI it doesn't need to do anything itself, as we've done all the work already.
        return SYNC_E_IGNORE;
    }

    /**
     * Imports a list of messages to be deleted
     *
     * @param long          $flags
     * @param array         $sourcekeys     array with sourcekeys
     *
     * @access public
     * @return
     */
    public function ImportMessageDeletion($flags, $sourcekeys) {
        $amount = count($sourcekeys);
        if ($amount > 1000) {
            throw new StatusException(sprintf("PHPWrapper->ImportMessageDeletion(): Received %d remove requests from ICS for folder '%s' (max. 1000 allowed). Triggering folder re-sync.", $amount, bin2hex($this->folderid)), SYNC_STATUS_INVALIDSYNCKEY, null, LOGLEVEL_ERROR);
        }
        else {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("PHPWrapper->ImportMessageDeletion(): Received %d remove requests from ICS", $amount));
        }
        foreach($sourcekeys as $sourcekey) {
            // TODO if we would know that ICS is removing the message because it's outside the sync interval, we could send a $asSoftDelete = true to the importer. Could they pass that via $flags?
            $this->importer->ImportMessageDeletion($this->prefix.bin2hex($sourcekey));
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("PHPWrapper->ImportMessageDeletion(): delete for :'%s'", $this->prefix.bin2hex($sourcekey)));
        }
    }

    /**
     * Imports a list of messages to be deleted
     *
     * @param mixed         $readstates     sourcekeys and message flags
     *
     * @access public
     * @return
     */
    public function ImportPerUserReadStateChange($readstates) {
        foreach($readstates as $readstate) {
            $this->importer->ImportMessageReadFlag($this->prefix.bin2hex($readstate["sourcekey"]), $readstate["flags"] & MSGFLAG_READ);
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("PHPWrapper->ImportPerUserReadStateChange(): read for :'%s'", $this->prefix.bin2hex($readstate["sourcekey"])));
        }
    }

    /**
     * Imports a message move
     * this is never called by ICS
     *
     * @access public
     * @return
     */
    public function ImportMessageMove($sourcekeysrcfolder, $sourcekeysrcmessage, $message, $sourcekeydestmessage, $changenumdestmessage) {
        // Never called
    }

    /**
     * Imports a single folder change
     *
     * @param array         $props     properties of the changed folder
     *
     * @access public
     * @return
     */
    function ImportFolderChange($props) {
        $folder = $this->mapiprovider->GetFolder($props);

        // do not import folder if there is something "wrong" with it
        if ($folder === false)
            return 0;

        $this->importer->ImportFolderChange($folder);
        return 0;
    }

    /**
     * Imports a list of folders which are to be deleted
     *
     * @param long          $flags
     * @param mixed         $sourcekeys array with sourcekeys
     *
     * @access public
     * @return
     */
    function ImportFolderDeletion($flags, $sourcekeys) {
        foreach ($sourcekeys as $sourcekey) {
            $this->importer->ImportFolderDeletion(SyncFolder::GetObject(bin2hex($sourcekey)));
        }
        return 0;
    }
}
