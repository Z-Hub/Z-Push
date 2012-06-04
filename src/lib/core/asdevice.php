<?php
/***********************************************
* File      :   asdevice.php
* Project   :   Z-Push
* Descr     :   The ASDevice holds basic data about a device,
*               its users and the linked states
*
* Created   :   11.04.2011
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

class ASDevice extends StateObject {
    const UNDEFINED = -1;
    // content data
    const FOLDERUUID = 1;
    const FOLDERTYPE = 2;
    const FOLDERSUPPORTEDFIELDS = 3;

    // expected values for not set member variables
    protected $unsetdata = array(
                                    'useragenthistory' => array(),
                                    'hierarchyuuid' => false,
                                    'contentdata' => array(),
                                    'wipestatus' => SYNC_PROVISION_RWSTATUS_NA,
                                    'wiperequestedby' => false,
                                    'wiperequestedon' => false,
                                    'wipeactionon' => false,
                                    'lastupdatetime' => 0,
                                    'conversationmode' => false,
                                    'policies' => array(),
                                    'policykey' => self::UNDEFINED,
                                    'forcesave' => false,
                                    'asversion' => false,
                                    'ignoredmessages' => array(),
                                    'announcedASversion' => false,
                                );

    static private $loadedData;
    protected $newdevice;
    protected $hierarchyCache;
    protected $ignoredMessageIds;

    /**
     * AS Device constructor
     *
     * @param string        $devid
     * @param string        $devicetype
     * @param string        $getuser
     * @param string        $useragent
     *
     * @access public
     * @return
     */
    public function ASDevice($devid, $devicetype, $getuser, $useragent) {
        $this->deviceid = $devid;
        $this->devicetype = $devicetype;
        list ($this->deviceuser, $this->domain) =  Utils::SplitDomainUser($getuser);
        $this->useragent = $useragent;
        $this->firstsynctime = time();
        $this->newdevice = true;
        $this->ignoredMessageIds = array();
    }

    /**
     * initializes the ASDevice with previousily saved data
     *
     * @param mixed     $stateObject        the StateObject containing the device data
     * @param boolean   $semanticUpdate     indicates if data relevant for all users should be cross checked (e.g. wipe requests)
     *
     * @access public
     * @return
     */
    public function SetData($stateObject, $semanticUpdate = true) {
        if (!($stateObject instanceof StateObject) || !isset($stateObject->devices) || !is_array($stateObject->devices)) return;

        // is information about this device & user available?
        if (isset($stateObject->devices[$this->deviceuser]) && $stateObject->devices[$this->deviceuser] instanceof ASDevice) {
            // overwrite local data with data from the saved object
            $this->SetDataArray($stateObject->devices[$this->deviceuser]->GetDataArray());
            $this->newdevice = false;
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("ASDevice data loaded for user: '%s'", $this->deviceuser));
        }

        // check if RWStatus from another user on same device may require action
        if ($semanticUpdate && count($stateObject->devices) > 1) {
            foreach ($stateObject->devices as $user=>$asuserdata) {
                if ($user == $this->user) continue;

                // another user has a required action on this device
                if (isset($asuserdata->wipeStatus) && $asuserdata->wipeStatus > SYNC_PROVISION_RWSTATUS_OK) {
                    ZLog::Write(LOGLEVEL_INFO, sprintf("User '%s' has requested a remote wipe for this device on '%s'", $asuserdata->wipeRequestBy, strftime("%Y-%m-%d %H:%M", $asuserdata->wipeRequstOn)));

                    // reset status to PENDING if wipe was executed before
                    $this->wipeStatus =  ($asuserdata->wipeStatus & SYNC_PROVISION_RWSTATUS_WIPED)?SYNC_PROVISION_RWSTATUS_PENDING:$asuserdata->wipeStatus;
                    $this->wipeRequestBy =  $asuserdata->wipeRequestBy;
                    $this->wipeRequestOn =  $asuserdata->wipeRequestOn;
                    $this->wipeActionOn = $asuserdata->wipeActionOn;
                    break;
                }
            }
        }

        self::$loadedData = $stateObject;
        $this->changed = false;
    }

    /**
     * Returns the current AS Device in it's StateObject
     * If the data was not changed, it returns false (no need to update any data)
     *
     * @access public
     * @return array/boolean
     */
    public function GetData() {
        if (! $this->changed)
            return false;

        // device was updated
        $this->lastupdatetime = time();
        unset($this->ignoredMessageIds);

        if (!isset(self::$loadedData) || !isset(self::$loadedData->devices) || !is_array(self::$loadedData->devices)) {
            self::$loadedData = new StateObject();
            $devices = array();
        }
        else
            $devices = self::$loadedData->devices;

        $devices[$this->deviceuser] = $this;

        // check if RWStatus has to be updated so it can be updated for other users on same device
        if (isset($this->wipeStatus) && $this->wipeStatus > SYNC_PROVISION_RWSTATUS_OK) {
            foreach ($devices as $user=>$asuserdata) {
                if ($user == $this->deviceuser) continue;
                if (isset($this->wipeStatus))       $asuserdata->wipeStatus     = $this->wipeStatus;
                if (isset($this->wipeRequestBy))    $asuserdata->wipeRequestBy  = $this->wipeRequestBy;
                if (isset($this->wipeRequestOn))    $asuserdata->wipeRequestOn  = $this->wipeRequestOn;
                if (isset($this->wipeActionOn))     $asuserdata->wipeActionOn   = $this->wipeActionOn;
                $devices[$user] = $asuserdata;

                ZLog::Write(LOGLEVEL_DEBUG, sprintf("Updated remote wipe status for user '%s' on the same device", $user));
            }
        }
        self::$loadedData->devices = $devices;
        return self::$loadedData;
    }

   /**
     * Removes internal data from the object, so this data can not be exposed
     *
     * @access public
     * @return boolean
     */
    public function StripData() {
        unset($this->changed);
        unset($this->unsetdata);
        unset($this->hierarchyCache);
        unset($this->forceSave);
        unset($this->newdevice);
        unset($this->ignoredMessageIds);

        if (isset($this->ignoredmessages) && is_array($this->ignoredmessages)) {
            $imessages = $this->ignoredmessages;
            $unserializedMessage = array();
            foreach ($imessages as $im) {
                $im->asobject = unserialize($im->asobject);
                $im->asobject->StripData();
                $unserializedMessage[] = $im;
            }
            $this->ignoredmessages = $unserializedMessage;
        }

        return true;
    }

   /**
     * Indicates if the object was just created
     *
     * @access public
     * @return boolean
     */
    public function IsNewDevice() {
        return (isset($this->newdevice) && $this->newdevice === true);
    }


    /**----------------------------------------------------------------------------------------------------------
     * Non-standard Getter and Setter
     */

    /**
     * Returns the user agent of this device
     *
     * @access public
     * @return string
     */
    public function GetDeviceUserAgent() {
        if (!isset($this->useragent) || !$this->useragent)
            return "unknown";

        return $this->useragent;
    }

    /**
     * Returns the user agent history of this device
     *
     * @access public
     * @return string
     */
    public function GetDeviceUserAgentHistory() {
        return $this->useragentHistory;
    }

    /**
     * Sets the useragent of the current request
     * If this value is alreay available, no update is done
     *
     * @param string    $useragent
     *
     * @access public
     * @return boolean
     */
    public function SetUserAgent($useragent) {
        if ($useragent == $this->useragent || $useragent === false || $useragent === Request::UNKNOWN)
            return true;

        // save the old user agent, if available
        if ($this->useragent != "") {
            // [] = changedate, previous user agent
            $a = $this->useragentHistory;
            $a[] = array(time(), $this->useragent);
            $this->useragentHistory = $a;
        }
        $this->useragent = $useragent;
        return true;
    }

   /**
     * Sets the current remote wipe status
     *
     * @param int       $status
     * @param string    $requestedBy
     * @access public
     * @return int
     */
    public function SetWipeStatus($status, $requestedBy = false) {
        // force saving the updated information if there was a transition between the wiping status
        if ($this->wipeStatus > SYNC_PROVISION_RWSTATUS_OK && $status > SYNC_PROVISION_RWSTATUS_OK)
            $this->forceSave = true;

        if ($requestedBy != false) {
            $this->wipeRequestedBy = $requestedBy;
            $this->wipeRequestedOn = time();
        }
        else {
            $this->wipeActionOn = time();
        }

        $this->wipeStatus = $status;

        if ($this->wipeStatus > SYNC_PROVISION_RWSTATUS_PENDING)
            ZLog::Write(LOGLEVEL_INFO, sprintf("ASDevice id '%s' was %s remote wiped on %s. Action requested by user '%s' on %s",
                                        $this->deviceid, ($this->wipeStatus == SYNC_PROVISION_RWSTATUS_REQUESTED ? "requested to be": "sucessfully"),
                                        strftime("%Y-%m-%d %H:%M", $this->wipeActionOn), $this->wipeRequestedBy, strftime("%Y-%m-%d %H:%M", $this->wipeRequestedOn)));
    }

   /**
     * Sets the deployed policy key
     *
     * @param int       $policykey
     *
     * @access public
     * @return
     */
    public function SetPolicyKey($policykey) {
        $this->policykey = $policykey;
        if ($this->GetWipeStatus() == SYNC_PROVISION_RWSTATUS_NA)
            $this->wipeStatus = SYNC_PROVISION_RWSTATUS_OK;
    }

    /**
     * Adds a messages which was ignored to the device data
     *
     * @param StateObject   $ignoredMessage
     *
     * @access public
     * @return boolean
     */
    public function AddIgnoredMessage($ignoredMessage) {
        // we should have all previousily ignored messages in an id array
        if (count($this->ignoredMessages) != count($this->ignoredMessageIds)) {
            foreach($this->ignoredMessages as $oldMessage) {
                if (!isset($this->ignoredMessageIds[$oldMessage->folderid]))
                    $this->ignoredMessageIds[$oldMessage->folderid] = array();
                $this->ignoredMessageIds[$oldMessage->folderid][] = $oldMessage->id;
            }
        }

        // serialize the AS object - if available
        if (isset($ignoredMessage->asobject))
            $ignoredMessage->asobject = serialize($ignoredMessage->asobject);

        // try not to add the same message several times
        if (isset($ignoredMessage->folderid) && isset($ignoredMessage->id)) {
            if (!isset($this->ignoredMessageIds[$ignoredMessage->folderid]))
                $this->ignoredMessageIds[$ignoredMessage->folderid] = array();

            if (in_array($ignoredMessage->id, $this->ignoredMessageIds[$ignoredMessage->folderid]))
                $this->RemoveIgnoredMessage($ignoredMessage->folderid, $ignoredMessage->id);

            $this->ignoredMessageIds[$ignoredMessage->folderid][] = $ignoredMessage->id;
            $msges = $this->ignoredMessages;
            $msges[] = $ignoredMessage;
            $this->ignoredMessages = $msges;

            return true;
        }
        else {
            $msges = $this->ignoredMessages;
            $msges[] = $ignoredMessage;
            $this->ignoredMessages = $msges;
            ZLog::Write(LOGLEVEL_WARN, "ASDevice->AddIgnoredMessage(): added message has no folder/id");
            return true;
        }
    }

    /**
     * Removes message in the list of ignored messages
     *
     * @param string    $folderid       parent folder id of the message
     * @param string    $id             message id
     *
     * @access public
     * @return boolean
     */
    public function RemoveIgnoredMessage($folderid, $id) {
        // we should have all previousily ignored messages in an id array
        if (count($this->ignoredMessages) != count($this->ignoredMessageIds)) {
            foreach($this->ignoredMessages as $oldMessage) {
                if (!isset($this->ignoredMessageIds[$oldMessage->folderid]))
                    $this->ignoredMessageIds[$oldMessage->folderid] = array();
                $this->ignoredMessageIds[$oldMessage->folderid][] = $oldMessage->id;
            }
        }

        $foundMessage = false;
        // there are ignored messages in that folder
        if (isset($this->ignoredMessageIds[$folderid])) {
            // resync of a folder.. we should remove all previousily ignored messages
            if ($id === false || in_array($id, $this->ignoredMessageIds[$folderid], true)) {
                $ignored = $this->ignoredMessages;
                $newMessages = array();
                foreach ($ignored as $im) {
                    if ($im->folderid = $folderid) {
                        if ($id === false || $im->id === $id) {
                            $foundMessage = true;
                            if (count($this->ignoredMessageIds[$folderid]) == 1) {
                                unset($this->ignoredMessageIds[$folderid]);
                            }
                            else {
                                unset($this->ignoredMessageIds[$folderid][array_search($id, $this->ignoredMessageIds[$folderid])]);
                            }
                            continue;
                        }
                        else
                            $newMessages[] = $im;
                    }
                }
                $this->ignoredMessages = $newMessages;
            }
        }

        return $foundMessage;
    }

    /**
     * Indicates if a message is in the list of ignored messages
     *
     * @param string    $folderid       parent folder id of the message
     * @param string    $id             message id
     *
     * @access public
     * @return boolean
     */
    public function HasIgnoredMessage($folderid, $id) {
        // we should have all previousily ignored messages in an id array
        if (count($this->ignoredMessages) != count($this->ignoredMessageIds)) {
            foreach($this->ignoredMessages as $oldMessage) {
                if (!isset($this->ignoredMessageIds[$oldMessage->folderid]))
                    $this->ignoredMessageIds[$oldMessage->folderid] = array();
                $this->ignoredMessageIds[$oldMessage->folderid][] = $oldMessage->id;
            }
        }

        $foundMessage = false;
        // there are ignored messages in that folder
        if (isset($this->ignoredMessageIds[$folderid])) {
            // resync of a folder.. we should remove all previousily ignored messages
            if ($id === false || in_array($id, $this->ignoredMessageIds[$folderid], true)) {
                $foundMessage = true;
            }
        }

        return $foundMessage;
    }

    /**----------------------------------------------------------------------------------------------------------
     * HierarchyCache and ContentData operations
     */

    /**
     * Sets the HierarchyCache
     * The hierarchydata, can be:
     *  - false     a new HierarchyCache is initialized
     *  - array()   new HierarchyCache is initialized and data from GetHierarchy is loaded
     *  - string    previousely serialized data is loaded
     *
     * @param string    $hierarchydata      (opt)
     *
     * @access public
     * @return boolean
     */
    public function SetHierarchyCache($hierarchydata = false) {
        if ($hierarchydata !== false && $hierarchydata instanceof ChangesMemoryWrapper) {
            $this->hierarchyCache = $hierarchydata;
            $this->hierarchyCache->CopyOldState();
        }
        else
            $this->hierarchyCache = new ChangesMemoryWrapper();

        if (is_array($hierarchydata))
            return $this->hierarchyCache->ImportFolders($hierarchydata);
        return true;
    }

    /**
     * Returns serialized data of the HierarchyCache
     *
     * @access public
     * @return string
     */
    public function GetHierarchyCacheData() {
        if (isset($this->hierarchyCache))
            return $this->hierarchyCache;

        ZLog::Write(LOGLEVEL_WARN, "ASDevice->GetHierarchyCacheData() has no data! HierarchyCache probably never initialized.");
        return false;
    }

   /**
     * Returns the HierarchyCache Object
     *
     * @access public
     * @return object   HierarchyCache
     */
    public function GetHierarchyCache() {
        if (!isset($this->hierarchyCache))
            $this->SetHierarchyCache();

        ZLog::Write(LOGLEVEL_DEBUG, "ASDevice->GetHierarchyCache(): ". $this->hierarchyCache->GetStat());
        return $this->hierarchyCache;
    }

   /**
     * Returns all known folderids
     *
     * @access public
     * @return array
     */
    public function GetAllFolderIds() {
        if (isset($this->contentData) && is_array($this->contentData))
            return array_keys($this->contentData);
        return array();
    }

   /**
     * Returns a linked UUID for a folder id
     *
     * @param string        $folderid       (opt) if not set, Hierarchy UUID is returned
     *
     * @access public
     * @return string
     */
    public function GetFolderUUID($folderid = false) {
        if ($folderid === false)
            return (isset($this->hierarchyUuid) && $this->hierarchyUuid !== self::UNDEFINED) ? $this->hierarchyUuid : false;
        else if (isset($this->contentData) && isset($this->contentData[$folderid]) && isset($this->contentData[$folderid][self::FOLDERUUID]))
            return $this->contentData[$folderid][self::FOLDERUUID];
        return false;
    }

   /**
     * Link a UUID to a folder id
     * If a boolean false UUID is sent, the relation is removed
     *
     * @param string        $uuid
     * @param string        $folderid       (opt) if not set Hierarchy UUID is linked
     *
     * @access public
     * @return boolean
     */
    public function SetFolderUUID($uuid, $folderid = false) {
        if ($folderid === false) {
            $this->hierarchyUuid = $uuid;
            // when unsetting the hierarchycache, also remove saved contentdata and ignoredmessages
            if ($folderid === false) {
                $this->contentData = array();
                $this->ignoredMessageIds = array();
                $this->ignoredMessages = array();
            }
        }
        else {

            $contentData = $this->contentData;
            if (!isset($contentData[$folderid]) || !is_array($contentData[$folderid]))
                $contentData[$folderid] = array();

            // check if the foldertype is set. This has to be available at this point, as generated during the first HierarchySync
            if (!isset($contentData[$folderid][self::FOLDERTYPE]))
                return false;

            if ($uuid)
                $contentData[$folderid][self::FOLDERUUID] = $uuid;
            else
                $contentData[$folderid][self::FOLDERUUID] = false;

            $this->contentData = $contentData;
        }
    }

   /**
     * Returns a foldertype for a folder already known to the mobile
     *
     * @param string        $folderid
     *
     * @access public
     * @return int/boolean  returns false if the type is not set
     */
    public function GetFolderType($folderid) {
        if (isset($this->contentData) && isset($this->contentData[$folderid]) &&
            isset($this->contentData[$folderid][self::FOLDERTYPE]) )

            return $this->contentData[$folderid][self::FOLDERTYPE];
        return false;
    }

   /**
     * Sets the foldertype of a folder id
     *
     * @param string        $uuid
     * @param string        $folderid       (opt) if not set Hierarchy UUID is linked
     *
     * @access public
     * @return boolean      true if the type was set or updated
     */
    public function SetFolderType($folderid, $foldertype) {
        $contentData = $this->contentData;

        if (!isset($contentData[$folderid]) || !is_array($contentData[$folderid]))
            $contentData[$folderid] = array();
        if (!isset($contentData[$folderid][self::FOLDERTYPE]) || $contentData[$folderid][self::FOLDERTYPE] != $foldertype ) {
            $contentData[$folderid][self::FOLDERTYPE] = $foldertype;
            $this->contentData = $contentData;
            return true;
        }
        return false;
    }

    /**
     * Gets the supported fields transmitted previousely by the device
     * for a certain folder
     *
     * @param string    $folderid
     *
     * @access public
     * @return array/boolean        false means no supportedFields are available
     */
    public function GetSupportedFields($folderid) {
        if (isset($this->contentData) && isset($this->contentData[$folderid]) &&
            isset($this->contentData[$folderid][self::FOLDERUUID]) && $this->contentData[$folderid][self::FOLDERUUID] !== false &&
            isset($this->contentData[$folderid][self::FOLDERSUPPORTEDFIELDS]) )

            return $this->contentData[$folderid][self::FOLDERSUPPORTEDFIELDS];

        return false;
    }

    /**
     * Sets the set of supported fields transmitted by the device for a certain folder
     *
     * @param string    $folderid
     * @param array     $fieldlist          supported fields
     *
     * @access public
     * @return boolean
     */
    public function SetSupportedFields($folderid, $fieldlist) {
        $contentData = $this->contentData;
        if (!isset($contentData[$folderid]) || !is_array($contentData[$folderid]))
            $contentData[$folderid] = array();

        $contentData[$folderid][self::FOLDERSUPPORTEDFIELDS] = $fieldlist;
        $this->contentData = $contentData;
        return true;
    }
}

?>