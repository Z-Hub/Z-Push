<?php
/***********************************************
* File      :   kopano.php
* Project   :   Z-Push - tools - GAB sync
* Descr     :   Kopano implementation of SyncWorker.
*
* Created   :   20.07.2016
*
* Copyright 2016 Zarafa Deutschland GmbH
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
* ************************************************/

include_once("contactworker.php");
include_once("synccontact.php");
include_once(PATH_TO_ZPUSH .'backend/kopano/mapi/mapi.util.php');
include_once(PATH_TO_ZPUSH .'backend/kopano/mapi/mapidefs.php');
include_once(PATH_TO_ZPUSH .'backend/kopano/mapi/mapitags.php');
include_once(PATH_TO_ZPUSH .'backend/kopano/mapi/mapicode.php');
include_once(PATH_TO_ZPUSH .'backend/kopano/mapi/mapiguid.php');
include_once(PATH_TO_ZPUSH .'lib/utils/utils.php');

if (!defined('PR_EMS_AB_THUMBNAIL_PHOTO')) {
    define('PR_EMS_AB_THUMBNAIL_PHOTO', mapi_prop_tag(PT_BINARY, 0x8C9E));
}
if (!defined('PR_EC_AB_HIDDEN')) {
    define('PR_EC_AB_HIDDEN', mapi_prop_tag(PT_BOOLEAN, 0x67A7));
}
if (!defined('STORE_SUPPORTS_UNICODE')) {
    define('STORE_SUPPORTS_UNICODE', true);
}

class Kopano extends ContactWorker {
    const NAME = "Z-Push GAB2Contacts";
    const VERSION = "1.0";
    private $session;
    private $defaultstore;
    private $store;
    private $mainUser;
    private $targetStore;
    private $storeCache;
    private $mapiprops;

    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct();
        // send Z-Push version and user agent to ZCP >7.2.0
        if ($this->checkMapiExtVersion('7.2.0')) {
            $this->session = mapi_logon_zarafa(USERNAME, PASSWORD, SERVER, CERTIFICATE, CERTIFICATE_PASSWORD, 0, self::VERSION, self::NAME. " ". self::VERSION);
        }
        else {
            $this->session = mapi_logon_zarafa(USERNAME, PASSWORD, SERVER, CERTIFICATE, CERTIFICATE_PASSWORD, 0);
        }

        if (mapi_last_hresult()) {
            $this->Terminate(sprintf("Kopano: login failed with error code: 0x%08X", mapi_last_hresult()));
        }
        $this->mainUser = USERNAME;
        $this->targetStore = CONTACT_FOLDERSTORE;
        $this->defaultstore = $this->openMessageStore($this->mainUser);
        $this->store = $this->openMessageStore(CONTACT_FOLDERSTORE);
        $this->storeCache = array();

        $this->mapiprops = array(
            "hash"    => "PT_STRING8:PSETID_Address:0x6825",      // custom property holding the contact hash
        );
        $this->mapiprops = getPropIdsFromStrings($this->store, $this->mapiprops);
    }

    /************************************************************************************
     * Implementing abstract methods from ContactWorker
     */

    /**
     * Returns a list of Global Address Books with their names and ids.
     *
     * @access protected
     * @return array
     */
    protected function getGABs() {
        $names = array();
        $companies = mapi_zarafa_getcompanylist($this->store);
        if (is_array($companies)) {
            foreach($companies as $c) {
                $names[trim($c['companyname'])] = bin2hex($c['companyid']);
            }
        }
        return $names;
    }

    /**
     * Performs the actual synchronization for a single GAB.
     *
     * @param string $targetFolderId    the id of the folder where the contacts should be stored.
     * @param string $gabId             the id of the gab to be synchronized. If not set (null) the default gab is synchronized.
     * @param string $gabName           the name of the gab to be synchronized. If not set (null) the default gab is synchronized.
     *
     * @access protected
     * @return void
     */
    protected function doSync($targetFolderId, $gabId = null, $gabName = 'default') {
        $store = $this->getStore($gabId, $gabName);
        $folder = $this->getFolder($store, $targetFolderId);

        $mapiprovider = new MAPIProvider($this->session, $store);

        // get a list of all contacts in the target folder
        $existingByAccount = $this->getExistingContacts($folder);

        $addrbook = mapi_openaddressbook($this->session);
        if (mapi_last_hresult()) {
            $this->Terminate(sprintf("Kopano->doSync: Error opening addressbook 0x%08X", mapi_last_hresult()));
        }

        if ($gabId == null) {
            $ab_entryid = mapi_ab_getdefaultdir($addrbook);
            if (mapi_last_hresult()) {
                $this->Terminate(sprintf("Kopano->doSync: Error, could not get '%s' address directory: 0x%08X", $gabName, mapi_last_hresult()));
            }
        }
        else {
            $ab_entryid = hex2bin($gabId);
        }

        // get all the groups
        $groups = mapi_zarafa_getgrouplist($this->store, $ab_entryid);

        $ab_dir = mapi_ab_openentry($addrbook, $ab_entryid);
        if (mapi_last_hresult()) {
            $this->Terminate(sprintf("Kopano->doSync: Error, could not open '%s' address directory: 0x%08X", $gabName, mapi_last_hresult()));
        }
        $table = mapi_folder_getcontentstable($ab_dir);
        if (mapi_last_hresult()) {
            $this->Terminate(sprintf("Kopano->doSync: error, could not open '%s' addressbook content table: 0x%08X", $gabName, mapi_last_hresult()));
        }
        $gabentries = mapi_table_queryallrows($table, array(PR_ENTRYID,
                PR_ACCOUNT,
                PR_GIVEN_NAME,
                PR_SURNAME,
                PR_OFFICE_LOCATION,
                PR_COMPANY_NAME,
                PR_TITLE,
                PR_SMTP_ADDRESS,
                PR_BUSINESS_TELEPHONE_NUMBER,
                PR_PRIMARY_FAX_NUMBER,
                PR_POSTAL_ADDRESS,
                PR_BUSINESS_ADDRESS_POSTAL_CODE,
                PR_BUSINESS_ADDRESS_STATE_OR_PROVINCE,
                PR_BUSINESS_ADDRESS_CITY,
                PR_MOBILE_TELEPHONE_NUMBER,
                PR_HOME_TELEPHONE_NUMBER,
                PR_BEEPER_TELEPHONE_NUMBER,
                PR_EMS_AB_THUMBNAIL_PHOTO,
                PR_ORGANIZATIONAL_ID_NUMBER,
                PR_DISPLAY_TYPE_EX,                     // fetch so we are able to ignore ROOM and EQUIPMENT
                PR_DISPLAY_NAME,                        // we want to look at it
                PR_EC_AB_HIDDEN,
                /* not mappable
                PR_BUSINESS_ADDRESS_POST_OFFICE_BOX,
                PR_INITIALS,
                PR_LANGUAGE,
                */
        ));

        $inGab = count($gabentries);
        $existingNr = count($existingByAccount);
        $ignored = 0;
        $created = 0;
        $updated = 0;
        $untouched = 0;
        $deleted = 0;

        // loop through all gab entries
        foreach ($gabentries as $entry) {
            // ignore SYSTEM user
            if (strtoupper($entry[PR_DISPLAY_NAME]) == "SYSTEM") {
                $ignored++;
                continue;
            }
            // ignore 'Everyone'
            if ($gabId != null && $entry[PR_DISPLAY_NAME] == $gabName) {
                $ignored++;
                continue;
            }
            // ignore other groups
            if (array_key_exists($entry[PR_ACCOUNT], $groups)) {
                $ignored++;
                continue;
            }
            // ignore ROOMS
            elseif (isset($entry[PR_DISPLAY_TYPE_EX]) && $entry[PR_DISPLAY_TYPE_EX] & DT_ROOM) {
                $ignored++;
                continue;
            }
            // ignore EQUIPMENT
            elseif (isset($entry[PR_DISPLAY_TYPE_EX]) && $entry[PR_DISPLAY_TYPE_EX] & DT_EQUIPMENT) {
                $ignored++;
                continue;
            }
            // ignore ORGANIZATION
            elseif (isset($entry[PR_DISPLAY_TYPE_EX]) && $entry[PR_DISPLAY_TYPE_EX] & DT_ORGANIZATION) {
                $ignored++;
                continue;
            }
            // ignore hidden entries
            elseif (isset($entry[PR_EC_AB_HIDDEN]) && $entry[PR_EC_AB_HIDDEN]) {
                $ignored++;
                continue;
            }

            // build a SyncContact for the GAB entry
            $contact = new SyncContact();
            if (isset($entry[PR_ACCOUNT]))                              $contact->accountname           = $entry[PR_ACCOUNT];
            if (isset($entry[PR_GIVEN_NAME]))                           $contact->firstname             = $entry[PR_GIVEN_NAME];
            if (isset($entry[PR_SURNAME]))                              $contact->lastname              = $entry[PR_SURNAME];
            if (isset($entry[PR_OFFICE_LOCATION]))                      $contact->officelocation        = $entry[PR_OFFICE_LOCATION];
            if (isset($entry[PR_COMPANY_NAME]))                         $contact->companyname           = $entry[PR_COMPANY_NAME];
            if (isset($entry[PR_TITLE]))                                $contact->jobtitle              = $entry[PR_TITLE];
            if (isset($entry[PR_SMTP_ADDRESS]))                         $contact->email1address         = $entry[PR_SMTP_ADDRESS];
            if (isset($entry[PR_BUSINESS_TELEPHONE_NUMBER]))            $contact->businessphonenumber   = $entry[PR_BUSINESS_TELEPHONE_NUMBER];
            if (isset($entry[PR_PRIMARY_FAX_NUMBER]))                   $contact->businessphonenumber   = $entry[PR_PRIMARY_FAX_NUMBER];
            if (isset($entry[PR_POSTAL_ADDRESS]))                       $contact->businessstreet        = $entry[PR_POSTAL_ADDRESS];
            if (isset($entry[PR_BUSINESS_ADDRESS_POSTAL_CODE]))         $contact->businesspostalcode    = $entry[PR_BUSINESS_ADDRESS_POSTAL_CODE];
            if (isset($entry[PR_BUSINESS_ADDRESS_STATE_OR_PROVINCE]))   $contact->businessstate         = $entry[PR_BUSINESS_ADDRESS_STATE_OR_PROVINCE];
            if (isset($entry[PR_BUSINESS_ADDRESS_CITY]))                $contact->businesscity          = $entry[PR_BUSINESS_ADDRESS_CITY];
            if (isset($entry[PR_MOBILE_TELEPHONE_NUMBER]))              $contact->mobilephonenumber     = $entry[PR_MOBILE_TELEPHONE_NUMBER];
            if (isset($entry[PR_HOME_TELEPHONE_NUMBER]))                $contact->homephonenumber       = $entry[PR_HOME_TELEPHONE_NUMBER];
            if (isset($entry[PR_BEEPER_TELEPHONE_NUMBER]))              $contact->pagernumber           = $entry[PR_BEEPER_TELEPHONE_NUMBER];
            if (isset($entry[PR_EMS_AB_THUMBNAIL_PHOTO]))               $contact->picture               = base64_encode($entry[PR_EMS_AB_THUMBNAIL_PHOTO]);
            if (isset($entry[PR_ORGANIZATIONAL_ID_NUMBER]))             $contact->customerid            = $entry[PR_ORGANIZATIONAL_ID_NUMBER];

            // check if the contact already exists in the folder
            if (isset($existingByAccount[$contact->accountname])) {
                // entry exists! Does the data match?
                if ($existingByAccount[$contact->accountname]['hash'] === $contact->GetHash()) {
                    // same hash -> unchanged!
                    unset($existingByAccount[$contact->accountname]);
                    $untouched++;
                    continue;
                }
                $mapimessage = mapi_msgstore_openentry($store, $existingByAccount[$contact->accountname]['entryid']);
                $updated++;
            }
            else {
                $mapimessage = mapi_folder_createmessage($folder);
                $created++;
            }
            // save the hash in the message as well
            mapi_setprops($mapimessage, array($this->mapiprops['hash'] => $contact->GetHash()));
            $mapiprovider->SetMessage($mapimessage, $contact);
            mapi_message_savechanges($mapimessage);
            unset($existingByAccount[$contact->accountname]);
        }

        // if there are entries left in the $existingByAccount array, they don't exist in the gab anymore and need to be removed
        if (!empty($existingByAccount)) {
            $entry_ids = array_reduce($existingByAccount, function ($result, $item) {
                $result[] = $item['entryid'];
                return $result;
            }, array());

            $deleted = count($entry_ids);
            mapi_folder_deletemessages($folder, $entry_ids, DELETE_HARD_DELETE);
        }
        $this->Log(sprintf("Sync - Objects in GAB: %d - in folder (before run): %d - created: %d - updated: %d - deleted: %d - untouched: %d - ignored: %d", $inGab, $existingNr, $created, $updated, $deleted, $untouched, $ignored));
    }

    /**
     * Deletes all contacts that were created by the script before.
     *
     * @param string $targetFolderId    the id of the folder where the contacts should be deleted.
     * @param string $gabId             the id of the gab to be synchronized. If not set (null) the default gab is synchronized.
     * @param string $gabName           the name of the gab to be synchronized. If not set (null) the default gab is synchronized.
     *
     * @access protected
     * @return boolean
     */
    protected function doDelete($targetFolderId, $gabId = null, $gabName = 'default') {
        $store = $this->getStore($gabId, $gabName);
        $folder = $this->getFolder($store, $targetFolderId);

        // get a list of all contacts in the target folder
        $existingByAccount = $this->getExistingContacts($folder);

        // entries in the $existingByAccount array must be deleted
        $entry_ids = array_reduce($existingByAccount, function ($result, $item) {
            $result[] = $item['entryid'];
            return $result;
        }, array());
        mapi_folder_deletemessages($folder, $entry_ids, DELETE_HARD_DELETE);

        $this->Log(sprintf("Delete - deleted contacts: %d", count($entry_ids)));
    }

    /************************************************************************************
     * Private Kopano stuff
     */

    /**
     * Open the store marked with PR_DEFAULT_STORE = TRUE.
     * If $return_public is set, the public store is opened.
     *
     * @param string    $user   User which store should be opened
     *
     * @access private
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
        else {
            $entryid = @mapi_msgstore_createentryid($this->defaultstore, $user);
        }

        if(!$entryid) {
            $this->Terminate(sprintf("Kopano->openMessageStore(): No store found for user '%s': 0x%08X - Aborting.", $user, mapi_last_hresult()));
        }

        $store = @mapi_openmsgstore($this->session, $entryid);
        if (!$store) {
            $this->Terminate(sprintf("Kopano->openMessageStore(): Could not open store for '%s': 0x%08X - Aborting.", $user, mapi_last_hresult()));
        }

        return $store;
    }

    /**
     * Returns the store for a gab id and name.
     *
     * @param string $gabId
     * @param string $gabName
     *
     * @access private
     * @return ressource
     */
    private function getStore($gabId, $gabName) {
        if (!$gabId) {
            return $this->store;
        }

        if (!isset($this->storeCache[$gabId])) {
            $user =  (strtoupper($this->targetStore) == 'SYSTEM') ? $gabName : $this->targetStore . "@" . $gabName;
            $store_entryid = mapi_msgstore_createentryid($this->store, $user);
            $store = mapi_openmsgstore($this->session, $store_entryid);
            $this->Log(sprintf("Kopano->getStore(): Found store of user '%s': '%s'", $user, $store));
            $this->storeCache[$gabId] = $store;
        }

        return $this->storeCache[$gabId];
    }

    /**
     * Opens a folder.
     *
     * @param ressource $store
     * @param string    $folderid
     *
     * @access private
     * @return ressource
     */
    private function getFolder($store, $folderid) {
        if (!isset($this->folderCache[$folderid])) {
            $folderentryid = mapi_msgstore_entryidfromsourcekey($store, hex2bin($folderid));
            if (!$folderentryid)
                $this->Terminate(sprintf("Kopano->getFolder(): Error, unable to open folder (no entry id): 0x%08X", mapi_last_hresult()));

            $this->folderCache[$folderid] = mapi_msgstore_openentry($store, $folderentryid);
        }

        return $this->folderCache[$folderid];
    }

    /**
     * Returns a list of contacts that have a hash (and therefore, were created by this script).
     *
     * @param ressource $folder
     *
     * @access private
     * @return array
     */
    private function getExistingContacts($folder) {
        $table = mapi_folder_getcontentstable($folder);
        if (!$table)
            $this->Log(sprintf("Kopano->getExistingContacts: Error, unable to read contents table to find contacts: 0x%08X", mapi_last_hresult()));

        $restriction = array(RES_EXIST,  Array(ULPROPTAG => $this->mapiprops['hash'] ));
        mapi_table_restrict($table, $restriction);

        $entries = mapi_table_queryallrows($table, array(PR_ENTRYID, PR_ACCOUNT, $this->mapiprops['hash']));

        $return = array();

        if (!empty($entries)) {
            foreach($entries as $e) {
                $return[$e[PR_ACCOUNT]] = array('entryid' => $e[PR_ENTRYID], 'hash' => $e[$this->mapiprops['hash']]);
            }
        }
        return $return;
    }

    /**
     * Checks if the PHP-MAPI extension is available and in a requested version.
     *
     * @param string    $version    the version to be checked ("6.30.10-18495", parts or build number)
     *
     * @access private
     * @return boolean installed version is superior to the checked string
     */
    private function checkMapiExtVersion($version = "") {
        // compare build number if requested
        if (preg_match('/^\d+$/', $version) && strlen($version) > 3) {
            $vs = preg_split('/-/', phpversion("mapi"));
            return ($version <= $vs[1]);
        }

        if (extension_loaded("mapi")){
            if (version_compare(phpversion("mapi"), $version) == -1){
                return false;
            }
        }
        else
            return false;

        return true;
    }

}