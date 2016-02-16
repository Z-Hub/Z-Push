<?php
/***********************************************
* File      :   zarafa.php
* Project   :   Z-Push - tools - OL GAB sync
* Descr     :   Zarafa implementation of SyncWorker.
*
* Created   :   28.01.2016
*
* Copyright 2016 Zarafa Deutschland GmbH
* ************************************************/

include_once("syncworker.php");

include_once('mapi/mapi.util.php');
include_once('mapi/mapidefs.php');
include_once('mapi/mapitags.php');
include_once('mapi/mapicode.php');
include_once('mapi/mapiguid.php');

define('PR_EMS_AB_THUMBNAIL_PHOTO', mapi_prop_tag(PT_BINARY, 0x8C9E));

class Zarafa extends SyncWorker {
    const NAME = "Z-Push Zarafa GAB Sync";
    const VERSION = "1.0";
    private $session;
    private $store;
    private $mainUser;
    private $folderCache;
    private $mapiprops;

    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct();
        $this->session = mapi_logon_zarafa(USERNAME, PASSWORD, SERVER, CERTIFICATE, CERTIFICATE_PASSWORD, 0, self::VERSION, self::NAME. " ". self::VERSION);
        if (mapi_last_hresult()) {
            $this->Terminate(sprintf("Zarafa: login failed with error code: 0x%08X", mapi_last_hresult()));
        }
        $this->mainUser = USERNAME;
        $this->store = $this->openMessageStore(HIDDEN_FOLDERSTORE);
        $this->folderCache = array();

        $this->mapiprops = array(
                "chunktype"     => "PT_STRING8:PSETID_Appointment:0x6822",      // custom property
                "chunkCRC"      => "PT_STRING8:PSETID_Appointment:0x8208",      // location
                "reminderset"   => "PT_BOOLEAN:PSETID_Common:0x8503",
                "isrecurring"   => "PT_BOOLEAN:PSETID_Appointment:0x8223",
        );
        $this->mapiprops = getPropIdsFromStrings($this->store, $this->mapiprops);
    }

    /************************************************************************************
     * Implementing abstract methods from SyncWorker
     */

    /**
     * Creates the hidden folder.
     *
     * @access protected
     * @return string
     */
    protected function CreateHiddenFolder() {
        $parentfolder = $this->getRootFolder();

        // mapi_folder_createfolder() fails if a folder with this name already exists -> MAPI_E_COLLISION
        $newfolder = mapi_folder_createfolder($parentfolder, HIDDEN_FOLDERNAME, "");
        if (mapi_last_hresult())
            $this->Terminate(sprintf("Zarafa->CreateHiddenFolder(): Error, mapi_folder_createfolder() failed: 0x%08X", mapi_last_hresult()));

        // TODO: set PR_HIDDEN
        mapi_setprops($newfolder, array(PR_CONTAINER_CLASS => "IPF.Appointment"));

        $props =  mapi_getprops($newfolder, array(PR_SOURCE_KEY));
        if (isset($props[PR_SOURCE_KEY])) {
            $sourcekey = bin2hex($props[PR_SOURCE_KEY]);
            $this->Log(sprintf("Created hidden public folder with id: '%s'", $sourcekey));
            return $sourcekey;
        }
        else {
            $this->Terminate(sprintf("Zarafa->CreateHiddenFolder(): Error, folder created but PR_SOURCE_KEY not available: 0x%08X", mapi_last_hresult()));
        }
    }

    /**
     * Deletes the hidden folder.
     *
     * @param string $folderid
     *
     * @access protected
     * @return boolean
     */
    protected function DeleteHiddenFolder($folderid) {
        $parentfolder = $this->getRootFolder();

        $folderentryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin($folderid));
        if (mapi_last_hresult())
            $this->Terminate(sprintf("Zarafa->DeleteHiddenFolder(): Error, could not get PR_ENTRYID for hidden folder: 0x%08X", mapi_last_hresult()));

        mapi_folder_deletefolder($parentfolder, $folderentryid);
        if (mapi_last_hresult())
            $this->Terminate(sprintf("Zarafa->DeleteHiddenFolder(): Error, mapi_folder_deletefolder() failed: 0x%08X", mapi_last_hresult()));

        return true;
    }

    /**
     * Returns the internal identfier (folder-id) of the hidden folder.
     *
     * @access protected
     * @return string|boolean on error
    */
    protected function GetHiddenFolderId() {
        $parentfolder = $this->getRootFolder();
        $table = mapi_folder_gethierarchytable($parentfolder);

        $restriction = array(RES_PROPERTY, array(RELOP => RELOP_EQ, ULPROPTAG => PR_DISPLAY_NAME, VALUE => HIDDEN_FOLDERNAME));
        mapi_table_restrict($table, $restriction);
        $querycnt = mapi_table_getrowcount($table);
        if ($querycnt == 1) {
            $entry = mapi_table_queryallrows($table, array(PR_SOURCE_KEY));
            if (isset($entry[0]) && isset($entry[0][PR_SOURCE_KEY])) {
                return bin2hex($entry[0][PR_SOURCE_KEY]);
            }
        }

        return false;
    }

    /**
     * Removes all messages that have not the same chunkType (chunk configuration changed!).
     *
     * @param string $folderid
     *
     * @access protected
     * @return boolean
     */
    protected function ClearFolderContents($folderid) {
        $this->Log("Zarafa->ClearFolderContents: emptying folder");
        $folder = $this->getFolder($folderid);

        // empty folder!
        $flags = 0;
        mapi_folder_emptyfolder($folder, $flags);
        if (mapi_last_hresult())
            $this->Terminate("Zarafa->ClearFolderContents: Error, mapi_folder_emptyfolder() failed: 0x%08X");

        return true;
    }

    /**
     * Removes all messages that do not match the current ChunkType.
     *
     * @param string $folderid
     *
     * @access protected
     * @return boolean
     */
    protected function ClearAllNotCurrentChunkType($folderid) {
        $folder = $this->getFolder($folderid);
        $table = mapi_folder_getcontentstable($folder);
        if (!$table)
            $this->Terminate(sprintf("Zarafa->ClearAllNotCurrentChunkType: Error, unable to read contents table: 0x%08X", mapi_last_hresult()));

        $restriction = array(RES_PROPERTY, array(RELOP => RELOP_NE, ULPROPTAG => $this->mapiprops['chunktype'], VALUE => $this->chunkType));
        mapi_table_restrict($table, $restriction);
        $querycnt = mapi_table_getrowcount($table);
        if ($querycnt == 0) {
            $this->Log("Zarafa->ClearAllNotCurrentChunkType: no invalid items, done!");
        }
        else {
            $this->Log(sprintf("Zarafa->ClearAllNotCurrentChunkType: found %d invalid items, deleting", $querycnt));
            $entries = mapi_table_queryallrows($table, array(PR_ENTRYID, $this->mapiprops['chunktype']));
            $entry_ids = array_reduce($entries, function ($result, $item) {
                                                    $result[] = $item[PR_ENTRYID];
                                                    return $result;
                                                }, array());
            mapi_folder_deletemessages($folder, array_values($entry_ids));
            $this->Log("Zarafa->ClearAllNotCurrentChunkType: done");
        }
        $this->Log("");
        return true;
    }

    /**
     * Returns a list with all GAB entries or a single entry specified by $uniqueId.
     * The search for that single entry is done using the configured UNIQUEID parameter.
     * If no entry is found for a $uniqueId an empty array() must be returned.
     *
     * @param string $uniqueId      A value to be found in the configured UNIQUEID.
     *                              If set, only one item is returned. If false or not set, the entire GAB is returned.
     *                              Default: false
     *
     * @access protected
     * @return array of GABEntry
     */
    protected function GetGAB($uniqueId = false) {
        // get all the groups
        $groups = mapi_zarafa_getgrouplist($this->store);

        $data = array();

        $addrbook = mapi_openaddressbook($this->session);
        if (mapi_last_hresult())
            $this->Terminate(sprintf("Zarafa->GetGAB: Error opening addressbook 0x%08X", mapi_last_hresult()));
        $ab_entryid = mapi_ab_getdefaultdir($addrbook);
        if (mapi_last_hresult())
            $this->Terminate(sprintf("Zarafa->GetGAB: Error, could not get default address directory: 0x%08X", mapi_last_hresult()));
        $ab_dir = mapi_ab_openentry($addrbook, $ab_entryid);
        if (mapi_last_hresult())
            $this->Terminate(sprintf("Zarafa->GetGAB: Error, could not open default address directory: 0x%08X", mapi_last_hresult()));
        $table = mapi_folder_getcontentstable($ab_dir);
        if (mapi_last_hresult())
            $this->Terminate(sprintf("Zarafa->GetGAB: error, could not open addressbook content table: 0x%08X", mapi_last_hresult()));

        // restrict the table if we should only return one
        if ($uniqueId) {
            $prop = $this->getPropertyForGABvalue(UNIQUEID);
            $restriction = array(RES_PROPERTY, array(RELOP => RELOP_EQ, ULPROPTAG => $prop , VALUE => $uniqueId));
            mapi_table_restrict($table, $restriction);
            $querycnt = mapi_table_getrowcount($table);
            if ($querycnt == 0) {
                $this->Log(sprintf("Zarafa->GetGAB(): Single GAB entry '%s' requested but could not be found.", $uniqueId));
            }
            elseif ($querycnt > 1) {
                $this->Terminate(sprintf("Zarafa->GetGAB(): Single GAB entry '%s' requested but %d entries found. Aborting.", $uniqueId, $querycnt));
            }
        }


        $gabentries = mapi_table_queryallrows($table, array(PR_ENTRYID,
                                                            PR_ACCOUNT,
                                                            PR_DISPLAY_NAME,
                                                            PR_SMTP_ADDRESS,
                                                            PR_BUSINESS_TELEPHONE_NUMBER,
                                                            PR_GIVEN_NAME,
                                                            PR_SURNAME,
                                                            PR_MOBILE_TELEPHONE_NUMBER,
                                                            PR_HOME_TELEPHONE_NUMBER,
                                                            PR_TITLE, PR_COMPANY_NAME,
                                                            PR_OFFICE_LOCATION,
                                                            PR_BEEPER_TELEPHONE_NUMBER,
                                                            PR_PRIMARY_FAX_NUMBER,
                                                            PR_ORGANIZATIONAL_ID_NUMBER,
                                                            PR_POSTAL_ADDRESS,
                                                            PR_BUSINESS_ADDRESS_CITY,
                                                            PR_BUSINESS_ADDRESS_POSTAL_CODE,
                                                            PR_BUSINESS_ADDRESS_POST_OFFICE_BOX,
                                                            PR_INITIALS,
                                                            PR_LANGUAGE,
                                                            PR_EMS_AB_THUMBNAIL_PHOTO,
                                                            PR_DISPLAY_TYPE_EX
                                                    ));
        foreach ($gabentries as $entry) {
            $a = new GABEntry();
            $a->type = GABEntry::CONTACT;
            $a->memberOf = array();
            $memberOf = mapi_zarafa_getgrouplistofuser($this->store, $entry[PR_ENTRYID]);
            if (is_array($memberOf)) {
                $a->memberOf = array_keys($memberOf);
            }

            // is this a group?
            if (array_key_exists($entry[PR_ACCOUNT], $groups)) {
                $a->type = GABEntry::GROUP;
                $users = mapi_zarafa_getuserlistofgroup($this->store, $groups[$entry[PR_ACCOUNT]]['groupid']);
                if (isset($users[$entry[PR_ACCOUNT]]['emailaddress'])) {
                    $a->smtpAddress = $users[$entry[PR_ACCOUNT]]['emailaddress'];
                }
                $a->members = array();
                if (is_array($users)) {
                    $a->members = array_keys($users);
                    // remove the group from itself
                    $key = array_search($entry[PR_ACCOUNT], $a->members);
                    if ($key !== false) {
                        unset($a->members[$key]);
                    }
                }
            }
            else if (isset($entry[PR_DISPLAY_TYPE_EX]) && $entry[PR_DISPLAY_TYPE_EX] == DT_ROOM) {
                $a->type = GABEntry::ROOM;
            }
            else if (isset($entry[PR_DISPLAY_TYPE_EX]) && $entry[PR_DISPLAY_TYPE_EX] ==  DT_EQUIPMENT) {
                $a->type = GABEntry::EQUIPMENT;
            }

            if (isset($entry[PR_ACCOUNT]))                              $a->account                         = $entry[PR_ACCOUNT];
            if (isset($entry[PR_DISPLAY_NAME]))                         $a->displayName                     = $entry[PR_DISPLAY_NAME];
            if (isset($entry[PR_GIVEN_NAME]))                           $a->givenName                       = $entry[PR_GIVEN_NAME];
            if (isset($entry[PR_SURNAME]))                              $a->surname                         = $entry[PR_SURNAME];
            if (isset($entry[PR_SMTP_ADDRESS]))                         $a->smtpAddress                     = $entry[PR_SMTP_ADDRESS];
            if (isset($entry[PR_TITLE]))                                $a->title                           = $entry[PR_TITLE];
            if (isset($entry[PR_COMPANY_NAME]))                         $a->companyName                     = $entry[PR_COMPANY_NAME];
            if (isset($entry[PR_OFFICE_LOCATION]))                      $a->officeLocation                  = $entry[PR_OFFICE_LOCATION];
            if (isset($entry[PR_BUSINESS_TELEPHONE_NUMBER]))            $a->businessTelephoneNumber         = $entry[PR_BUSINESS_TELEPHONE_NUMBER];
            if (isset($entry[PR_MOBILE_TELEPHONE_NUMBER]))              $a->mobileTelephoneNumber           = $entry[PR_MOBILE_TELEPHONE_NUMBER];
            if (isset($entry[PR_HOME_TELEPHONE_NUMBER]))                $a->homeTelephoneNumber             = $entry[PR_HOME_TELEPHONE_NUMBER];
            if (isset($entry[PR_BEEPER_TELEPHONE_NUMBER]))              $a->beeperTelephoneNumber           = $entry[PR_BEEPER_TELEPHONE_NUMBER];
            if (isset($entry[PR_PRIMARY_FAX_NUMBER]))                   $a->primaryFaxNumber                = $entry[PR_PRIMARY_FAX_NUMBER];
            if (isset($entry[PR_ORGANIZATIONAL_ID_NUMBER]))             $a->organizationalIdNumber          = $entry[PR_ORGANIZATIONAL_ID_NUMBER];
            if (isset($entry[PR_POSTAL_ADDRESS]))                       $a->postalAddress                   = $entry[PR_POSTAL_ADDRESS];
            if (isset($entry[PR_BUSINESS_ADDRESS_CITY]))                $a->businessAddressCity             = $entry[PR_BUSINESS_ADDRESS_CITY];
            if (isset($entry[PR_BUSINESS_ADDRESS_POSTAL_CODE]))         $a->businessAddressPostalCode       = $entry[PR_BUSINESS_ADDRESS_POSTAL_CODE];
            if (isset($entry[PR_BUSINESS_ADDRESS_POST_OFFICE_BOX]))     $a->businessAddressPostOfficeBox    = $entry[PR_BUSINESS_ADDRESS_POST_OFFICE_BOX];
            if (isset($entry[PR_BUSINESS_ADDRESS_STATE_OR_PROVINCE]))   $a->businessAddressStateOrProvince  = $entry[PR_BUSINESS_ADDRESS_STATE_OR_PROVINCE];
            if (isset($entry[PR_INITIALS]))                             $a->initials                        = $entry[PR_INITIALS];
            if (isset($entry[PR_LANGUAGE]))                             $a->language                        = $entry[PR_LANGUAGE];
            if (isset($entry[PR_EMS_AB_THUMBNAIL_PHOTO]))               $a->thumbnailPhoto                  = base64_encode($entry[PR_EMS_AB_THUMBNAIL_PHOTO]);

            $data[] = $a;
        }

        return $data;
    }

    /**
     * Returns the chunk data of the chunkId of the hidden folder.
     *
     * @param string    $folderid
     * @param string    $chunkName      The name of the chunk (used to find the chunk message).
     *                                  The name is saved in the 'subject' of the chunk message.
     *
     * @access protected
     * @return json string
     */
    protected function GetChunkData($folderid, $chunkName) {
        // find the chunk message in the folder
        $chunkdata = $this->findChunk($folderid, $chunkName);

        if ($chunkdata[PR_ENTRYID]) {
            $message = mapi_msgstore_openentry($this->store, $chunkdata[PR_ENTRYID]);
            return $this->readPropStream($message, PR_BODY);
        }
        else {
            // return an empty array (in json)
            return "[]";
        }
    }

    /**
     * Updates the chunk data in the hidden folder if it changed.
     * If the chunkId is not available, it's created.
     *
     * @param string    $folderid
     * @param string    $chunkName      The name of the chunk (used to find/update the chunk message).
     *                                  The name is to be saved in the 'subject' of the chunk message.
     * @param int       $amountEntries  Amount of entries in the chunkdata.
     * @param string    $chunkData      The data containing all the data.
     * @param string    $chunkCRC       A checksum of the chunk data. To be saved in the 'location' of
     *                                  the chunk message. Used to identify changed chunks.
     *
     * @access protected
     * @return boolean
     */
    protected function SetChunkData($folderid, $chunkName, $amountEntries, $chunkData, $chunkCRC) {
        $log = sprintf("Zarafa->SetChunkData: %s\tEntries: %d\t Size: %d B\tCRC: %s  -  ", $chunkName, $amountEntries, strlen($chunkData), $chunkCRC);

            // find the chunk message in the folder
        $chunkdata = $this->findChunk($folderid, $chunkName);
        $message = false;

        // message not found, create it
        if (empty($chunkdata)) {
            $folder = $this->getFolder($folderid);
            $message = mapi_folder_createmessage($folder);
            mapi_setprops($message, array(PR_MESSAGE_CLASS => "IPM.Appointment", $this->mapiprops['chunktype'] => $this->chunkType, PR_SUBJECT => $chunkName));
            $log .= "creating - ";
        }
        // message there, open and compare
        else{
            // we need to update the chunk if the CRC does not match!
            if ($chunkdata[$this->mapiprops['chunkCRC']] != $chunkCRC) {
                $message = mapi_msgstore_openentry($this->store, $chunkdata[PR_ENTRYID]);
                $log .= "opening - ";
            }
            else {
                $log .= "unchanged";
            }
        }

        // update chunk if necessary
        if ($message) {
            mapi_setprops($message, array($this->mapiprops['chunkCRC'] => $chunkCRC, PR_BODY => $chunkData));
            mapi_savechanges($message);
            $log .= "saved";
        }

        // output log
        $this->log($log);
        return true;
    }


    /************************************************************************************
     * Private Zarafa stuff
     */

    /**
     * Finds the chunk and returns a property array.
     *
     * @param string $folderid
     * @param string $chunkName
     * @return array
     */
    private function findChunk($folderid, $chunkName) {
        // search for the chunk message
        $folder = $this->getFolder($folderid);
        $table = mapi_folder_getcontentstable($folder);
        if (!$table)
            $this->Log(sprintf("Zarafa->findChunk: Error, unable to read contents table to find chunk '%d': 0x%08X", $chunkId, mapi_last_hresult()));

        $restriction = array(RES_PROPERTY, array(RELOP => RELOP_EQ, ULPROPTAG => PR_SUBJECT, VALUE => $chunkName));
        mapi_table_restrict($table, $restriction);

        $entries = mapi_table_queryallrows($table, array(PR_ENTRYID, $this->mapiprops['chunkCRC']));
        if (isset($entries[0])) {
            return $entries[0];
        }
        return array();
    }

    /**
     * Open the store marked with PR_DEFAULT_STORE = TRUE
     * if $return_public is set, the public store is opened
     *
     * @param string    $user   User which store should be opened
     *
     * @access public
     * @return boolean
     */
    private function openMessageStore($user) {
        $entryid = false;
        $return_public = false;

        if (strtoupper($user) == 'SYSTEM')
            $return_public = true;

        // loop through the storestable if authenticated user of public folder
        if ($user == $this->mainUser || $return_public === true) {
            // Find the default store
            $storestables = mapi_getmsgstorestable($this->session);
            $result = mapi_last_hresult();

            if ($result == NOERROR){
                $rows = mapi_table_queryallrows($storestables, array(PR_ENTRYID, PR_DEFAULT_STORE, PR_MDB_PROVIDER));

                foreach($rows as $row) {
                    if(!$return_public && isset($row[PR_DEFAULT_STORE]) && $row[PR_DEFAULT_STORE] == true) {
                        $entryid = $row[PR_ENTRYID];
                        break;
                    }
                    if ($return_public && isset($row[PR_MDB_PROVIDER]) && $row[PR_MDB_PROVIDER] == ZARAFA_STORE_PUBLIC_GUID) {
                        $entryid = $row[PR_ENTRYID];
                        break;
                    }
                }
            }
        }
        else
            $entryid = @mapi_msgstore_createentryid($this->defaultstore, $user);

        if(!$entryid) {
            $this->Terminate(sprintf("Zarafa->openMessageStore(): No store found for user '%s': 0x%08X - Aborting.", $user, mapi_last_hresult));
        }

        $store = @mapi_openmsgstore($this->session, $entryid);
        if (!$store) {
            $this->Terminate(sprintf("Zarafa->openMessageStore(): Could not open store for '%s': 0x%08X - Aborting.", $user, mapi_last_hresult));
        }

        $this->Log(sprintf("Zarafa->openMessageStore(): Found '%s' store of user '%s': '%s'", (($return_public)?'PUBLIC':'DEFAULT'), $user, $store));
        return $store;
    }

    /**
     * Opens the root folder, either in a user's store or of the public folder.
     *
     * @access private
     * @return ressource
     */
    private function getRootFolder() {
        $rootId = "root";
        if (!isset($this->folderCache[$rootId])) {
            $parentfentryid = false;

            // the default store root
            if ($this->mainUser == HIDDEN_FOLDERSTORE) {
                $parentprops = mapi_getprops($this->store, array(PR_IPM_SUBTREE_ENTRYID));
                if (isset($parentprops[PR_IPM_SUBTREE_ENTRYID]))
                    $parentfentryid = $parentprops[PR_IPM_SUBTREE_ENTRYID];
            }
            // get the main public folder
            else {
                $parentprops = mapi_getprops($this->store, array(PR_IPM_PUBLIC_FOLDERS_ENTRYID));
                if (isset($parentprops[PR_IPM_PUBLIC_FOLDERS_ENTRYID]))
                    $parentfentryid = $parentprops[PR_IPM_PUBLIC_FOLDERS_ENTRYID];
            }

            if (!$parentfentryid)
                $this->Terminate(sprintf("Zarafa->getRootFolder(): Error, unable to open parent folder (no entry id): 0x%08X", mapi_last_hresult()));

            $parentfolder = mapi_msgstore_openentry($this->store, $parentfentryid);
            if (!$parentfolder)
                $this->Terminate(sprintf("Zarafa->CreateHiddenPublicFolder(): Error, unable to open parent folder (open entry): 0x%08X", mapi_last_hresult()));

            $this->folderCache[$rootId] = $parentfolder;
        }
        return $this->folderCache[$rootId];
    }

    /**
     * Opens the a folder.
     *
     * @param string $folderid
     * @access private
     * @return ressource
     */
    private function getFolder($folderid) {
        if (!isset($this->folderCache[$folderid])) {
            $folderentryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin($folderid));
            if (!$folderentryid)
                $this->Terminate(sprintf("Zarafa->getFolder: Error, unable to open folder (no entry id): 0x%08X", mapi_last_hresult()));

            $this->folderCache[$folderid] = mapi_msgstore_openentry($this->store, $folderentryid);
        }

        return $this->folderCache[$folderid];
    }

    /**
     * Returns a property for a UNIQUEID configured.
     *
     * @param string $value
     *
     * @access private
     * @return mapi property
     */
    private function getPropertyForGABvalue($value) {
        $prop = false;
        switch($value) {
            case 'account':
                $prop = PR_ACCOUNT;
                break;
            case 'smtpAddress':
                $prop = PR_SMTP_ADDRESS;
                break;
        }
        if (!$prop) {
            $this->Terminate(sprintf("Zarafa->getPropertyForGABvalue: Could not get find a property for '%s'. Unsupported field.", $value));
        }
        return $prop;
    }

    /**
     * Reads data of large properties from a stream.
     *
     * @param MAPIMessage $message
     * @param long $prop
     *
     * @access private
     * @return string
     */
    private function readPropStream($message, $prop) {
        $stream = mapi_openproperty($message, $prop, IID_IStream, 0, 0);
        $ret = mapi_last_hresult();
        if ($ret == MAPI_E_NOT_FOUND) {
            $this->Log(sprintf("Zarafa>readPropStream: property 0x%s not found. It is either empty or not set. It will be ignored.", str_pad(dechex($prop), 8, 0, STR_PAD_LEFT)));
            return "";
        }
        elseif ($ret) {
            $this->Log("Zarafa->readPropStream error opening stream: 0x%08X", $ret);
            return "";
        }
        $data = "";
        $string = "";
        while(1) {
            $data = mapi_stream_read($stream, 1024);
            if(strlen($data) == 0)
                break;
            $string .= $data;
        }

        return $string;
    }
}