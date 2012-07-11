<?php
/***********************************************
* File      :   devicemanager.php
* Project   :   Z-Push
* Descr     :   Manages device relevant data, provisioning,
*               loop detection and device states.
*               The DeviceManager uses a IStateMachine
*               implementation with IStateMachine::DEVICEDATA
*               to save device relevant data.
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

class DeviceManager {
    // stream up to 100 messages to the client by default
    const DEFAULTWINDOWSIZE = 100;

    // broken message indicators
    const MSG_BROKEN_UNKNOWN = 1;
    const MSG_BROKEN_CAUSINGLOOP = 2;
    const MSG_BROKEN_SEMANTICERR = 4;

    private $device;
    private $deviceHash;
    private $statemachine;
    private $stateManager;
    private $incomingData = 0;
    private $outgoingData = 0;

    private $windowSize;
    private $latestFolder;

    private $loopdetection;
    private $hierarchySyncRequired;

    /**
     * Constructor
     *
     * @access public
     */
    public function DeviceManager() {
        $this->statemachine = ZPush::GetStateMachine();
        $this->deviceHash = false;
        $this->devid = Request::GetDeviceID();
        $this->windowSize = array();
        $this->latestFolder = false;
        $this->hierarchySyncRequired = false;

        // only continue if deviceid is set
        if ($this->devid) {
            $this->device = new ASDevice($this->devid, Request::GetDeviceType(), Request::GetGETUser(), Request::GetUserAgent());
            $this->loadDeviceData();

            ZPush::GetTopCollector()->SetUserAgent($this->device->GetDeviceUserAgent());
        }
        else
            throw new FatalNotImplementedException("Can not proceed without a device id.");

        $this->loopdetection = new LoopDetection();
        $this->loopdetection->ProcessLoopDetectionInit();
        $this->loopdetection->ProcessLoopDetectionPreviousConnectionFailed();

        $this->stateManager = new StateManager();
        $this->stateManager->SetDevice($this->device);
    }

    /**
     * Returns the StateManager for the current device
     *
     * @access public
     * @return StateManager
     */
    public function GetStateManager() {
        return $this->stateManager;
    }

    /**----------------------------------------------------------------------------------------------------------
     * Device operations
     */

    /**
     * Announces amount of transmitted data to the DeviceManager
     *
     * @param int           $datacounter
     *
     * @access public
     * @return boolean
     */
    public function SentData($datacounter) {
        // TODO save this somewhere
        $this->incomingData = Request::GetContentLength();
        $this->outgoingData = $datacounter;
    }

    /**
     * Called at the end of the request
     * Statistics about received/sent data is saved here
     *
     * @access public
     * @return boolean
     */
    public function Save() {
        // TODO save other stuff

        // check if previousily ignored messages were synchronized for the current folder
        // on multifolder operations of AS14 this is done by setLatestFolder()
        if ($this->latestFolder !== false)
            $this->checkBrokenMessages($this->latestFolder);

        // update the user agent and AS version on the device
        $this->device->SetUserAgent(Request::GetUserAgent());
        $this->device->SetASVersion(Request::GetProtocolVersion());

        // data to be saved
        $data = $this->device->GetData();
        if ($data && Request::IsValidDeviceID()) {
            ZLog::Write(LOGLEVEL_DEBUG, "DeviceManager->Save(): Device data changed");

            try {
                // check if this is the first time the device data is saved and it is authenticated. If so, link the user to the device id
                if ($this->device->IsNewDevice() && RequestProcessor::isUserAuthenticated()) {
                    ZLog::Write(LOGLEVEL_INFO, sprintf("Linking device ID '%s' to user '%s'", $this->devid, $this->device->GetDeviceUser()));
                    $this->statemachine->LinkUserDevice($this->device->GetDeviceUser(), $this->devid);
                }

                if (RequestProcessor::isUserAuthenticated() || $this->device->GetForceSave() ) {
                    $this->statemachine->SetState($data, $this->devid, IStateMachine::DEVICEDATA);
                    ZLog::Write(LOGLEVEL_DEBUG, "DeviceManager->Save(): Device data saved");
                }
            }
            catch (StateNotFoundException $snfex) {
                ZLog::Write(LOGLEVEL_ERROR, "DeviceManager->Save(): Exception: ". $snfex->getMessage());
            }
        }

        // remove old search data
        $oldpid = $this->loopdetection->ProcessLoopDetectionGetOutdatedSearchPID();
        if ($oldpid) {
            ZPush::GetBackend()->GetSearchProvider()->TerminateSearch($oldpid);
        }

        // we terminated this process
        if ($this->loopdetection)
            $this->loopdetection->ProcessLoopDetectionTerminate();

        return true;
    }

    /**
     * Newer mobiles send extensive device informations with the Settings command
     * These informations are saved in the ASDevice
     *
     * @param SyncDeviceInformation     $deviceinformation
     *
     * @access public
     * @return boolean
     */
    public function SaveDeviceInformation($deviceinformation) {
        ZLog::Write(LOGLEVEL_DEBUG, "Saving submitted device information");

        // set the user agent
        if (isset($deviceinformation->useragent))
            $this->device->SetUserAgent($deviceinformation->useragent);

        // save other informations
        foreach (array("model", "imei", "friendlyname", "os", "oslanguage", "phonenumber", "mobileoperator", "enableoutboundsms") as $info) {
            if (isset($deviceinformation->$info) && $deviceinformation->$info != "") {
                $this->device->__set("device".$info, $deviceinformation->$info);
            }
        }
        return true;
    }

    /**----------------------------------------------------------------------------------------------------------
     * Provisioning operations
     */

    /**
     * Checks if the sent policykey matches the latest policykey
     * saved for the device
     *
     * @param string        $policykey
     * @param boolean       $noDebug        (opt) by default, debug message is shown
     *
     * @access public
     * @return boolean
     */
    public function ProvisioningRequired($policykey, $noDebug = false) {
        $this->loadDeviceData();

        // check if a remote wipe is required
        if ($this->device->GetWipeStatus() > SYNC_PROVISION_RWSTATUS_OK) {
            ZLog::Write(LOGLEVEL_INFO, sprintf("DeviceManager->ProvisioningRequired('%s'): YES, remote wipe requested", $policykey));
            return true;
        }

        $p = ( ($this->device->GetWipeStatus() != SYNC_PROVISION_RWSTATUS_NA && $policykey != $this->device->GetPolicyKey()) ||
              Request::WasPolicyKeySent() && $this->device->GetPolicyKey() == ASDevice::UNDEFINED );
        if (!$noDebug || $p)
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("DeviceManager->ProvisioningRequired('%s') saved device key '%s': %s", $policykey, $this->device->GetPolicyKey(), Utils::PrintAsString($p)));
        return $p;
    }

    /**
     * Generates a new Policykey
     *
     * @access public
     * @return int
     */
    public function GenerateProvisioningPolicyKey() {
        return mt_rand(100000000, 999999999);
    }

    /**
     * Attributes a provisioned policykey to a device
     *
     * @param int           $policykey
     *
     * @access public
     * @return boolean      status
     */
    public function SetProvisioningPolicyKey($policykey) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("DeviceManager->SetPolicyKey('%s')", $policykey));
        return $this->device->SetPolicyKey($policykey);
    }

    /**
     * Builds a Provisioning SyncObject with policies
     *
     * @access public
     * @return SyncProvisioning
     */
    public function GetProvisioningObject() {
        $p = new SyncProvisioning();
        // TODO load systemwide Policies
        $p->Load($this->device->GetPolicies());
        return $p;
    }

    /**
     * Returns the status of the remote wipe policy
     *
     * @access public
     * @return int          returns the current status of the device - SYNC_PROVISION_RWSTATUS_*
     */
    public function GetProvisioningWipeStatus() {
        return $this->device->GetWipeStatus();
    }

    /**
     * Updates the status of the remote wipe
     *
     * @param int           $status - SYNC_PROVISION_RWSTATUS_*
     *
     * @access public
     * @return boolean      could fail if trying to update status to a wipe status which was not requested before
     */
    public function SetProvisioningWipeStatus($status) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("DeviceManager->SetProvisioningWipeStatus() change from '%d' to '%d'",$this->device->GetWipeStatus(), $status));

        if ($status > SYNC_PROVISION_RWSTATUS_OK && !($this->device->GetWipeStatus() > SYNC_PROVISION_RWSTATUS_OK)) {
            ZLog::Write(LOGLEVEL_ERROR, "Not permitted to update remote wipe status to a higher value as remote wipe was not initiated!");
            return false;
        }
        $this->device->SetWipeStatus($status);
        return true;
    }


    /**----------------------------------------------------------------------------------------------------------
     * LEGACY AS 1.0 and WRAPPER operations
     */

    /**
     * Returns a wrapped Importer & Exporter to use the
     * HierarchyChache
     *
     * @see ChangesMemoryWrapper
     * @access public
     * @return object           HierarchyCache
     */
    public function GetHierarchyChangesWrapper() {
        return $this->device->GetHierarchyCache();
    }

    /**
     * Initializes the HierarchyCache for legacy syncs
     * this is for AS 1.0 compatibility:
     *      save folder information synched with GetHierarchy()
     *
     * @param string    $folders            Array with folder information
     *
     * @access public
     * @return boolean
     */
    public function InitializeFolderCache($folders) {
        $this->stateManager->SetDevice($this->device);
        return $this->stateManager->InitializeFolderCache($folders);
    }

    /**
     * Returns a FolderID of default classes
     * this is for AS 1.0 compatibility:
     *      this information was made available during GetHierarchy()
     *
     * @param string    $class              The class requested
     *
     * @access public
     * @return string
     * @throws NoHierarchyCacheAvailableException
     */
    public function GetFolderIdFromCacheByClass($class) {
        $folderidforClass = false;
        // look at the default foldertype for this class
        $type = ZPush::getDefaultFolderTypeFromFolderClass($class);

        if ($type && $type > SYNC_FOLDER_TYPE_OTHER && $type < SYNC_FOLDER_TYPE_USER_MAIL) {
            $folderids = $this->device->GetAllFolderIds();
            foreach ($folderids as $folderid) {
                if ($type == $this->device->GetFolderType($folderid)) {
                    $folderidforClass = $folderid;
                    break;
                }
            }

            // Old Palm Treos always do initial sync for calendar and contacts, even if they are not made available by the backend.
            // We need to fake these folderids, allowing a fake sync/ping, even if they are not supported by the backend
            // if the folderid would be available, they would already be returned in the above statement
            if ($folderidforClass == false && ($type == SYNC_FOLDER_TYPE_APPOINTMENT || $type == SYNC_FOLDER_TYPE_CONTACT))
                $folderidforClass = SYNC_FOLDER_TYPE_DUMMY;
        }

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("DeviceManager->GetFolderIdFromCacheByClass('%s'): '%s' => '%s'", $class, $type, $folderidforClass));
        return $folderidforClass;
    }

    /**
     * Returns a FolderClass for a FolderID which is known to the mobile
     *
     * @param string    $folderid
     *
     * @access public
     * @return int
     * @throws NoHierarchyCacheAvailableException, NotImplementedException
     */
    public function GetFolderClassFromCacheByID($folderid) {
        //TODO check if the parent folder exists and is also beeing synchronized
        $typeFromCache = $this->device->GetFolderType($folderid);
        if ($typeFromCache === false)
            throw new NoHierarchyCacheAvailableException(sprintf("Folderid '%s' is not fully synchronized on the device", $folderid));

        $class = ZPush::GetFolderClassFromFolderType($typeFromCache);
        if ($class === false)
            throw new NotImplementedException(sprintf("Folderid '%s' is saved to be of type '%d' but this type is not implemented", $folderid, $typeFromCache));

        return $class;
    }

    /**
     * Checks if the message should be streamed to a mobile
     * Should always be called before a message is sent to the mobile
     * Returns true if there is something wrong and the content could break the
     * synchronization
     *
     * @param string        $id         message id
     * @param SyncObject    &$message   the method could edit the message to change the flags
     *
     * @access public
     * @return boolean          returns true if the message should NOT be send!
     */
    public function DoNotStreamMessage($id, &$message) {
        $folderid = $this->getLatestFolder();

        if (isset($message->parentid))
            $folder = $message->parentid;

        // message was identified to be causing a loop
        if ($this->loopdetection->IgnoreNextMessage(true, $id, $folderid)) {
            $this->AnnounceIgnoredMessage($folderid, $id, $message, self::MSG_BROKEN_CAUSINGLOOP);
            return true;
        }

        // message is semantically incorrect
        if (!$message->Check(true)) {
            $this->AnnounceIgnoredMessage($folderid, $id, $message, self::MSG_BROKEN_SEMANTICERR);
            return true;
        }

        // check if this message is broken
        if ($this->device->HasIgnoredMessage($folderid, $id)) {
            // reset the flags so the message is always streamed with <Add>
            $message->flags = false;

            // track the broken message in the loop detection
            $this->loopdetection->SetBrokenMessage($folderid, $id);
        }
        return false;
    }

    /**
     * Removes device information about a broken message as it is been removed from the mobile.
     *
     * @param string        $id         message id
     *
     * @access public
     * @return boolean
     */
    public function RemoveBrokenMessage($id) {
        $folderid = $this->getLatestFolder();
        if ($this->device->RemoveIgnoredMessage($folderid, $id)) {
            ZLog::Write(LOGLEVEL_INFO, sprintf("DeviceManager->RemoveBrokenMessage('%s', '%s'): cleared data about previously ignored message", $folderid, $id));
            return true;
        }
        return false;
    }

    /**
     * Amount of items to me synchronized
     *
     * @param string    $folderid
     * @param string    $type
     * @param int       $queuedmessages;
     * @access public
     * @return int
     */
    public function GetWindowSize($folderid, $type, $uuid, $statecounter, $queuedmessages) {
        if (isset($this->windowSize[$folderid]))
            $items = $this->windowSize[$folderid];
        else
            $items = self::DEFAULTWINDOWSIZE;

        $this->setLatestFolder($folderid);

        // detect if this is a loop condition
        if ($this->loopdetection->Detect($folderid, $type, $uuid, $statecounter, $items, $queuedmessages))
            $items = ($items == 0) ? 0: 1+($this->loopdetection->IgnoreNextMessage(false)?1:0) ;

        if ($items >= 0 && $items <= 2)
            ZLog::Write(LOGLEVEL_WARN, sprintf("Mobile loop detected! Messages sent to the mobile will be restricted to %d items in order to identify the conflict", $items));

        return $items;
    }

    /**
     * Sets the amount of items the device is requesting
     *
     * @param string    $folderid
     * @param int       $maxItems
     *
     * @access public
     * @return boolean
     */
    public function SetWindowSize($folderid, $maxItems) {
        $this->windowSize[$folderid] = $maxItems;

        return true;
    }

     /**
     * Sets the supported fields transmitted by the device for a certain folder
     *
     * @param string    $folderid
     * @param array     $fieldlist          supported fields
     *
     * @access public
     * @return boolean
     */
    public function SetSupportedFields($folderid, $fieldlist) {
        return $this->device->SetSupportedFields($folderid, $fieldlist);
    }

    /**
     * Gets the supported fields transmitted previousely by the device
     * for a certain folder
     *
     * @param string    $folderid
     *
     * @access public
     * @return array/boolean
     */
    public function GetSupportedFields($folderid) {
        return $this->device->GetSupportedFields($folderid);
    }

    /**
     * Removes all linked states of a specific folder.
     * During next request the folder is resynchronized.
     *
     * @param string    $folderid
     *
     * @access public
     * @return boolean
     */
    public function ForceFolderResync($folderid) {
        ZLog::Write(LOGLEVEL_INFO, sprintf("DeviceManager->ForceFolderResync('%s'): folder resync", $folderid));

        // delete folder states
        StateManager::UnLinkState($this->device, $folderid);

        return true;
    }

    /**
     * Removes all linked states from a device.
     * During next requests a full resync is triggered.
     *
     * @access public
     * @return boolean
     */
    public function ForceFullResync() {
        ZLog::Write(LOGLEVEL_INFO, "Full device resync requested");

        // delete hierarchy states
        StateManager::UnLinkState($this->device, false);

        // delete all other uuids
        foreach ($this->device->GetAllFolderIds() as $folderid)
            $uuid = StateManager::UnLinkState($this->device, $folderid);

        return true;
    }

    /**
     * Indicates if the hierarchy should be resynchronized
     * e.g. during PING
     *
     * @access public
     * @return boolean
     */
    public function IsHierarchySyncRequired() {
        // check if a hierarchy sync might be necessary
        if ($this->device->GetFolderUUID(false) === false)
            $this->hierarchySyncRequired = true;

        return $this->hierarchySyncRequired;
    }

    /**
     * Indicates if a full hierarchy resync should be triggered due to loops
     *
     * @access public
     * @return boolean
     */
    public function IsHierarchyFullResyncRequired() {
        // check for potential process loops like described in ZP-5
        return $this->loopdetection->ProcessLoopDetectionIsHierarchyResyncRequired();
    }

    /**
     * Adds an Exceptions to the process tracking
     *
     * @param Exception     $exception
     *
     * @access public
     * @return boolean
     */
    public function AnnounceProcessException($exception) {
        return $this->loopdetection->ProcessLoopDetectionAddException($exception);
    }

    /**
     * Adds a non-ok status for a folderid to the process tracking.
     * On 'false' a hierarchy status is assumed
     *
     * @access public
     * @return boolean
     */
    public function AnnounceProcessStatus($folderid, $status) {
        return $this->loopdetection->ProcessLoopDetectionAddStatus($folderid, $status);
    }

    /**
     * Checks if the given counter for a certain uuid+folderid was exported before.
     * This is called when a heartbeat request found changes to make sure that the same
     * changes are not exported twice, as during the heartbeat there could have been a normal
     * sync request.
     *
     * @param string $folderid          folder id
     * @param string $uuid              synkkey
     * @param string $counter           synckey counter
     *
     * @access public
     * @return boolean                  indicating if an uuid+counter were exported (with changes) before
     */
    public function CheckHearbeatStateIntegrity($folderid, $uuid, $counter) {
        return $this->loopdetection->IsSyncStateObsolete($folderid, $uuid, $counter);
    }

    /**
     * Indicates if the device needs an AS version update
     *
     * @access public
     * @return boolean
     */
    public function AnnounceASVersion() {
        $latest = ZPush::GetSupportedASVersion();
        $announced = $this->device->GetAnnouncedASversion();
        $this->device->SetAnnouncedASversion($latest);

        return ($announced != $latest);
    }

    /**----------------------------------------------------------------------------------------------------------
     * private DeviceManager methods
     */

    /**
     * Loads devicedata from the StateMachine and loads it into the device
     *
     * @access public
     * @return boolean
     */
    private function loadDeviceData() {
        if (!Request::IsValidDeviceID())
            return false;
        try {
            $deviceHash = $this->statemachine->GetStateHash($this->devid, IStateMachine::DEVICEDATA);
            if ($deviceHash != $this->deviceHash) {
                if ($this->deviceHash)
                    ZLog::Write(LOGLEVEL_DEBUG, "DeviceManager->loadDeviceData(): Device data was changed, reloading");
                $this->device->SetData($this->statemachine->GetState($this->devid, IStateMachine::DEVICEDATA));
                $this->deviceHash = $deviceHash;
            }
        }
        catch (StateNotFoundException $snfex) {
            $this->hierarchySyncRequired = true;
        }
        return true;
    }

    /**
     * Called when a SyncObject is not being streamed to the mobile.
     * The user can be informed so he knows about this issue
     *
     * @param string        $folderid   id of the parent folder (may be false if unknown)
     * @param string        $id         message id
     * @param SyncObject    $message    the broken message
     * @param string        $reason     (self::MSG_BROKEN_UNKNOWN, self::MSG_BROKEN_CAUSINGLOOP, self::MSG_BROKEN_SEMANTICERR)
     *
     * @access public
     * @return boolean
     */
    public function AnnounceIgnoredMessage($folderid, $id, SyncObject $message, $reason = self::MSG_BROKEN_UNKNOWN) {
        if ($folderid === false)
            $folderid = $this->getLatestFolder();

        $class = get_class($message);

        $brokenMessage = new StateObject();
        $brokenMessage->id = $id;
        $brokenMessage->folderid = $folderid;
        $brokenMessage->ASClass = $class;
        $brokenMessage->folderid = $folderid;
        $brokenMessage->reasonCode = $reason;
        $brokenMessage->reasonString = 'unknown cause';
        $brokenMessage->timestamp = time();
        $brokenMessage->asobject = $message;
        $brokenMessage->reasonString = ZLog::GetLastMessage(LOGLEVEL_WARN);

        $this->device->AddIgnoredMessage($brokenMessage);

        ZLog::Write(LOGLEVEL_ERROR, sprintf("Ignored broken message (%s). Reason: '%s' Folderid: '%s' message id '%s'", $class, $reason, $folderid, $id));
        return true;
    }

    /**
     * Called when a SyncObject was streamed to the mobile.
     * If the message could not be sent before this data is obsolete
     *
     * @param string        $folderid   id of the parent folder
     * @param string        $id         message id
     *
     * @access public
     * @return boolean          returns true if the message was ignored before
     */
    private function announceAcceptedMessage($folderid, $id) {
        if ($this->device->RemoveIgnoredMessage($folderid, $id)) {
            ZLog::Write(LOGLEVEL_INFO, sprintf("DeviceManager->announceAcceptedMessage('%s', '%s'): cleared previously ignored message as message is sucessfully streamed",$folderid, $id));
            return true;
        }
        return false;
    }

    /**
     * Checks if there were broken messages streamed to the mobile.
     * If the sync completes/continues without further erros they are marked as accepted
     *
     * @param string    $folderid       folderid which is to be checked
     *
     * @access private
     * @return boolean
     */
    private function checkBrokenMessages($folderid) {
        // check for correctly synchronized messages of the folder
        foreach($this->loopdetection->GetSyncedButBeforeIgnoredMessages($folderid) as $okID) {
            $this->announceAcceptedMessage($folderid, $okID);
        }
        return true;
    }

    /**
     * Setter for the latest folder id
     * on multi-folder operations of AS 14 this is used to set the new current folder id
     *
     * @param string    $folderid       the current folder
     *
     * @access private
     * @return boolean
     */
    private function setLatestFolder($folderid) {
        // this is a multi folder operation
        // check on ignoredmessages before discaring the folderid
        if ($this->latestFolder !== false)
            $this->checkBrokenMessages($this->latestFolder);

        $this->latestFolder = $folderid;

        return true;
    }

    /**
     * Getter for the latest folder id
     *
     * @access private
     * @return string    $folderid       the current folder
     */
    private function getLatestFolder() {
        return $this->latestFolder;
    }
}

?>