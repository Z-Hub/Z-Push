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


    /**
     * Constructor of the PHPWrapper
     *
     * @param ressource         $session
     * @param ressource         $store
     * @param IImportChanges    $importer       incoming changes from ICS are forwarded here
     *
     * @access public
     * @return
     */
    public function PHPWrapper($session, $store, $importer) {
        $this->importer = &$importer;
        $this->store = $store;
        $this->mapiprovider = new MAPIProvider($session, $this->store);
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
    public function ImportMessageChange($props, $flags, &$retmapimessage) {
        $sourcekey = $props[PR_SOURCE_KEY];
        $parentsourcekey = $props[PR_PARENT_SOURCE_KEY];
        $entryid = mapi_msgstore_entryidfromsourcekey($this->store, $parentsourcekey, $sourcekey);

        if(!$entryid)
            return SYNC_E_IGNORE;

        $mapimessage = mapi_msgstore_openentry($this->store, $entryid);
        try {
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

        $this->importer->ImportMessageChange(bin2hex($sourcekey), $message);

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
        foreach($sourcekeys as $sourcekey) {
            $this->importer->ImportMessageDeletion(bin2hex($sourcekey));
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
            $this->importer->ImportMessageReadFlag(bin2hex($readstate["sourcekey"]), $readstate["flags"] & MSGFLAG_READ);
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
     * @param mixed         $props     sourcekey of the changed folder
     *
     * @access public
     * @return
     */
    function ImportFolderChange($props) {
        $sourcekey = $props[PR_SOURCE_KEY];
        $entryid = mapi_msgstore_entryidfromsourcekey($this->store, $sourcekey);
        $mapifolder = mapi_msgstore_openentry($this->store, $entryid);
        $folder = $this->mapiprovider->GetFolder($mapifolder);

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
            $this->importer->ImportFolderDeletion(bin2hex($sourcekey));
        }
        return 0;
    }
}

?>