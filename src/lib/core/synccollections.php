<?php
/***********************************************
* File      :   synccollections.php
* Project   :   Z-Push
* Descr     :   This is basically a list of synched folders with it's
*               respective SyncParameters, while some additional parameters
*               which are not stored there can be kept here.
*               The class also provides CheckForChanges which is basically
*               a loop through all collections checking for changes.
*               SyncCollections is used for Sync (with and without heartbeat)
*               and Ping connections.
*               To check for changes in Heartbeat and Ping requeste the same
*               sync states as for the default synchronization are used.
*
* Created   :   06.01.2012
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


class SyncCollections implements Iterator {
    const ERROR_NO_COLLECTIONS = 1;
    const ERROR_WRONG_HIERARCHY = 2;

    private $stateManager;

    private $collections = array();
    private $addparms = array();
    private $changes = array();
    private $saveData = true;

    private $refPolicyKey = false;
    private $refLifetime = false;

    private $globalWindowSize;
    private $lastSyncTime;

    private $waitingTime = 0;


    /**
     * Constructor
     */
    public function SyncCollections() {
    }

    /**
     * Sets the StateManager for this object
     * If this is not done and a method needs it, the StateManager will be
     * requested from the DeviceManager
     *
     * @param StateManager  $statemanager
     *
     * @access public
     * @return
     */
    public function SetStateManager($statemanager) {
        $this->stateManager = $statemanager;
    }

    /**
     * Loads all collections known for the current device
     *
     * @param boolean $overwriteLoaded          (opt) overwrites Collection with saved state if set to true
     * @param boolean $loadState                (opt) indicates if the collection sync state should be loaded, default true
     * @param boolean $checkPermissions         (opt) if set to true each folder will pass
     *                                          through a backend->Setup() to check permissions.
     *                                          If this fails a StatusException will be thrown.
     *
     * @access public
     * @throws StatusException                  with SyncCollections::ERROR_WRONG_HIERARCHY if permission check fails
     * @throws StateNotFoundException           if the sync state can not be found ($loadState = true)
     * @return boolean
     */
    public function LoadAllCollections($overwriteLoaded = false, $loadState = false, $checkPermissions = false) {
        $this->loadStateManager();

        $invalidStates = false;
        foreach($this->stateManager->GetSynchedFolders() as $folderid) {
            if ($overwriteLoaded === false && isset($this->collections[$folderid]))
                continue;

            // Load Collection!
            if (! $this->LoadCollection($folderid, $loadState, $checkPermissions))
                $invalidStates = true;
        }

        if ($invalidStates)
            throw new StateInvalidException("Invalid states found while loading collections. Forcing sync");

        return true;
    }

    /**
     * Loads all collections known for the current device
     *
     * @param boolean $folderid                 folder id to be loaded
     * @param boolean $loadState                (opt) indicates if the collection sync state should be loaded, default true
     * @param boolean $checkPermissions         (opt) if set to true each folder will pass
     *                                          through a backend->Setup() to check permissions.
     *                                          If this fails a StatusException will be thrown.
     *
     * @access public
     * @throws StatusException                  with SyncCollections::ERROR_WRONG_HIERARCHY if permission check fails
     * @throws StateNotFoundException           if the sync state can not be found ($loadState = true)
     * @return boolean
     */
    public function LoadCollection($folderid, $loadState = false, $checkPermissions = false) {
        $this->loadStateManager();

        try {
            // Get SyncParameters for the folder from the state
            $spa = $this->stateManager->GetSynchedFolderState($folderid);

            // TODO remove resync of folders for < Z-Push 2 beta4 users
            // this forces a resync of all states previous to Z-Push 2 beta4
            if (! $spa instanceof SyncParameters)
                throw new StateInvalidException("Saved state are not of type SyncParameters");
        }
        catch (StateInvalidException $sive) {
            // in case there is something wrong with the state, just stop here
            // later when trying to retrieve the SyncParameters nothing will be found

            // we also generate a fake change, so a sync on this folder is triggered
            $this->changes[$folderid] = 1;

            return false;
        }

        // if this is an additional folder the backend has to be setup correctly
        if ($checkPermissions === true && ! ZPush::GetBackend()->Setup(ZPush::GetAdditionalSyncFolderStore($spa->GetFolderId())))
            throw new StatusException(sprintf("SyncCollections->LoadCollection(): could not Setup() the backend for folder id '%s'", $spa->GetFolderId()), self::ERROR_WRONG_HIERARCHY);

        // add collection to object
        $this->AddCollection($spa);

        // load the latest known syncstate if requested
        if ($loadState === true)
            $this->addparms[$folderid]["state"] = $this->stateManager->GetSyncState($spa->GetLatestSyncKey());

        return true;
    }

    /**
     * Saves a SyncParameters Object
     *
     * @param SyncParamerts $spa
     *
     * @access public
     * @return boolean
     */
    public function SaveCollection($spa) {
        if (! $this->saveData)
            return false;

        if ($spa->IsDataChanged()) {
            $this->loadStateManager();
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("SyncCollections->SaveCollection(): Data of folder '%s' changed", $spa->GetFolderId()));

            // save new windowsize
            if (isset($this->globalWindowSize))
                $spa->SetWindowSize($this->globalWindowSize);

            // update latest lifetime
            if (isset($this->refLifetime))
                $spa->SetReferenceLifetime($this->refLifetime);

            return $this->stateManager->SetSynchedFolderState($spa);
        }
        return false;
    }

    /**
     * Adds a SyncParameters object to the current list of collections
     *
     * @param SyncParameters $spa
     *
     * @access public
     * @return boolean
     */
    public function AddCollection($spa) {
        $this->collections[$spa->GetFolderId()] = $spa;

        if ($spa->HasLastSyncTime() && $spa->GetLastSyncTime() > $this->lastSyncTime) {
            $this->lastSyncTime = $spa->GetLastSyncTime();

            // use SyncParameters PolicyKey as reference if available
            if ($spa->HasReferencePolicyKey())
                $this->refPolicyKey = $spa->GetReferencePolicyKey();

            // use SyncParameters LifeTime as reference if available
            if ($spa->HasReferenceLifetime())
                $this->refLifetime = $spa->GetReferenceLifetime();
        }

        return true;
    }

    /**
     * Returns a previousily added or loaded SyncParameters object for a folderid
     *
     * @param SyncParameters $spa
     *
     * @access public
     * @return SyncParameters / boolean      false if no SyncParameters object is found for folderid
     */
    public function GetCollection($folderid) {
        if (isset($this->collections[$folderid]))
            return $this->collections[$folderid];
        else
            return false;
    }

    /**
     * Indicates if there are any loaded CPOs
     *
     * @access public
     * @return boolean
     */
    public function HasCollections() {
        return ! empty($this->collections);
    }

    /**
     * Add a non-permanent key/value pair for a SyncParameters object
     *
     * @param SyncParameters    $spa    target SyncParameters
     * @param string            $key
     * @param mixed             $value
     *
     * @access public
     * @return boolean
     */
    public function AddParameter($spa, $key, $value) {
        if (!$spa->HasFolderId())
            return false;

        $folderid = $spa->GetFolderId();
        if (!isset($this->addparms[$folderid]))
            $this->addparms[$folderid] = array();

        $this->addparms[$folderid][$key] = $value;
        return true;
    }

    /**
     * Returns a previousily set non-permanent value for a SyncParameters object
     *
     * @param SyncParameters    $spa    target SyncParameters
     * @param string            $key
     *
     * @access public
     * @return mixed            returns 'null' if nothing set
     */
    public function GetParameter($spa, $key) {
        if (isset($this->addparms[$spa->GetFolderId()]) && isset($this->addparms[$spa->GetFolderId()][$key]))
            return $this->addparms[$spa->GetFolderId()][$key];
        else
            return null;
    }

    /**
     * Returns the latest known PolicyKey to be used as reference
     *
     * @access public
     * @return int/boolen       returns false if nothing found in collections
     */
    public function GetReferencePolicyKey() {
        return $this->refPolicyKey;
    }

    /**
     * Sets a global window size which should be used for all collections
     * in a case of a heartbeat and/or partial sync
     *
     * @param int   $windowsize
     *
     * @access public
     * @return boolean
     */
    public function SetGlobalWindowSize($windowsize) {
        $this->globalWindowSize = $windowsize;
        return true;
    }

    /**
     * Returns the global window size which should be used for all collections
     * in a case of a heartbeat and/or partial sync
     *
     * @access public
     * @return int/boolean          returns false if not set or not available
     */
    public function GetGlobalWindowSize() {
        if (!isset($this->globalWindowSize))
            return false;

        return $this->globalWindowSize;
    }

    /**
     * Sets the lifetime for heartbeat or ping connections
     *
     * @param int   $lifetime       time in seconds
     *
     * @access public
     * @return boolean
     */
    public function SetLifetime($lifetime) {
        $this->refLifetime = $lifetime;
        return true;
    }

    /**
     * Sets the lifetime for heartbeat or ping connections
     * previousily set or saved in a collection
     *
     * @access public
     * @return int                  returns 600 as default if nothing set or not available
     */
    public function GetLifetime() {
        if (!isset( $this->refLifetime) || $this->refLifetime === false)
            return 600;

        return $this->refLifetime;
    }

    /**
     * Returns the timestamp of the last synchronization for all
     * loaded collections
     *
     * @access public
     * @return int                  timestamp
     */
    public function GetLastSyncTime() {
        return $this->lastSyncTime;
    }

    /**
     * Returns the timestamp of the last synchronization of a device.
     *
     * @param $device       an ASDevice
     *
     * @access public
     * @return int                  timestamp
     */
    static public function GetLastSyncTimeOfDevice(&$device) {
        // we need a StateManager for this operation
        $stateManager = new StateManager();
        $stateManager->SetDevice($device);

        $sc = new SyncCollections();
        $sc->SetStateManager($stateManager);

        // load all collections of device without loading states or checking permissions
        $sc->LoadAllCollections(true, false, false);

        return $sc->GetLastSyncTime();
    }

    /**
     * Checks if the currently known collections for changes for $lifetime seconds.
     * If the backend provides a ChangesSink the sink will be used.
     * If not every $interval seconds an exporter will be configured for each
     * folder to perform GetChangeCount().
     *
     * @param int       $lifetime       (opt) total lifetime to wait for changes / default 600s
     * @param int       $interval       (opt) time between blocking operations of sink or polling / default 30s
     * @param boolean   $onlyPingable   (opt) only check for folders which have the PingableFlag
     *
     * @access public
     * @return boolean              indicating if changes were found
     * @throws StatusException      with code SyncCollections::ERROR_NO_COLLECTIONS if no collections available
     *                              with code SyncCollections::ERROR_WRONG_HIERARCHY if there were errors getting changes
     */
    public function CheckForChanges($lifetime = 600, $interval = 30, $onlyPingable = false) {
        $classes = array();
        foreach ($this->collections as $folderid => $spa){
            if ($onlyPingable && $spa->GetPingableFlag() !== true)
                continue;

            if (!isset($classes[$spa->GetContentClass()]))
                $classes[$spa->GetContentClass()] = 0;
            $classes[$spa->GetContentClass()] += 1;
        }
        if (empty($classes))
            $checkClasses = "policies only";
        else if (array_sum($classes) > 4) {
            $checkClasses = "";
            foreach($classes as $class=>$count) {
                if ($count == 1)
                    $checkClasses .= sprintf("%s ", $class);
                else
                    $checkClasses .= sprintf("%s(%d) ", $class, $count);
            }
        }
        else
            $checkClasses = implode("/", array_keys($classes));

        $pingTracking = new PingTracking();
        $this->changes = array();
        $changesAvailable = false;

        ZPush::GetTopCollector()->SetAsPushConnection();
        ZPush::GetTopCollector()->AnnounceInformation(sprintf("lifetime %ds", $lifetime), true);
        ZLog::Write(LOGLEVEL_INFO, sprintf("SyncCollections->CheckForChanges(): Waiting for %s changes... (lifetime %d seconds)", (empty($classes))?'policy':'store', $lifetime));

        // use changes sink where available
        $changesSink = false;
        $forceRealExport = 0;
        // do not create changessink if there are no folders
        if (!empty($classes) && ZPush::GetBackend()->HasChangesSink()) {
            $changesSink = true;

            // initialize all possible folders
            foreach ($this->collections as $folderid => $spa) {
                if ($onlyPingable && $spa->GetPingableFlag() !== true)
                    continue;

                // switch user store if this is a additional folder and initialize sink
                ZPush::GetBackend()->Setup(ZPush::GetAdditionalSyncFolderStore($folderid));
                if (! ZPush::GetBackend()->ChangesSinkInitialize($folderid))
                    throw new StatusException(sprintf("Error initializing ChangesSink for folder id '%s'", $folderid), self::ERROR_WRONG_HIERARCHY);
            }
        }

        // wait for changes
        $started = time();
        $endat = time() + $lifetime;
        while(($now = time()) < $endat) {
            // how long are we waiting for changes
            $this->waitingTime = $now-$started;

            $nextInterval = $interval;
            // we should not block longer than the lifetime
            if ($endat - $now < $nextInterval)
                $nextInterval = $endat - $now;

            // Check if provisioning is necessary
            // if a PolicyKey was sent use it. If not, compare with the ReferencePolicyKey
            if (PROVISIONING === true && $this->GetReferencePolicyKey() !== false && ZPush::GetDeviceManager()->ProvisioningRequired($this->GetReferencePolicyKey(), true))
                // the hierarchysync forces provisioning
                throw new StatusException("SyncCollections->CheckForChanges(): PolicyKey changed. Provisioning required.", self::ERROR_WRONG_HIERARCHY);

            // Check if a hierarchy sync is necessary
            if (ZPush::GetDeviceManager()->IsHierarchySyncRequired())
                throw new StatusException("SyncCollections->CheckForChanges(): HierarchySync required.", self::ERROR_WRONG_HIERARCHY);

            // Check if there are newer requests
            // If so, this process should be terminated if more than 60 secs to go
            if ($pingTracking->DoForcePingTimeout()) {
                // do not update CPOs because another process has already read them!
                $this->saveData = false;

                // more than 60 secs to go?
                if (($now + 60) < $endat) {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("SyncCollections->CheckForChanges(): Timeout forced after %ss from %ss due to other process", ($now-$started), $lifetime));
                    ZPush::GetTopCollector()->AnnounceInformation(sprintf("Forced timeout after %ds", ($now-$started)), true);
                    return false;
                }
            }

            // Use changes sink if available
            if ($changesSink) {
                // in some occasions we do realize a full export to see if there are pending changes
                // every 5 minutes this is also done to see if there were "missed" notifications
                if (SINK_FORCERECHECK !== false && $forceRealExport+SINK_FORCERECHECK <= $now) {
                    if ($this->CountChanges($onlyPingable)) {
                        ZLog::Write(LOGLEVEL_DEBUG, "SyncCollections->CheckForChanges(): Using ChangesSink but found relevant changes on regular export");
                        return true;
                    }
                    $forceRealExport = $now;
                }

                ZPush::GetTopCollector()->AnnounceInformation(sprintf("Sink %d/%ds on %s", ($now-$started), $lifetime, $checkClasses));
                $notifications = ZPush::GetBackend()->ChangesSink($nextInterval);

                $validNotifications = false;
                foreach ($notifications as $folderid) {
                    // check if the notification on the folder is within our filter
                    if ($this->CountChange($folderid)) {
                        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SyncCollections->CheckForChanges(): Notification received on folder '%s'", $folderid));
                        $validNotifications = true;
                    }
                    else {
                        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SyncCollections->CheckForChanges(): Notification received on folder '%s', but it is not relevant", $folderid));
                    }
                }
                if ($validNotifications)
                    return true;
            }
            // use polling mechanism
            else {
                ZPush::GetTopCollector()->AnnounceInformation(sprintf("Polling %d/%ds on %s", ($now-$started), $lifetime, $checkClasses));
                if ($this->CountChanges($onlyPingable)) {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("SyncCollections->CheckForChanges(): Found changes polling"));
                    return true;
                }
                else {
                    sleep($nextInterval);
                }
            } // end polling
        } // end wait for changes
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SyncCollections->CheckForChanges(): no changes found after %ds", time() - $started));

        return false;
    }

    /**
     * Checks if the currently known collections for
     * changes performing Exporter->GetChangeCount()
     *
     * @param boolean   $onlyPingable   (opt) only check for folders which have the PingableFlag
     *
     * @access public
     * @return boolean      indicating if changes were found or not
     */
    public function CountChanges($onlyPingable = false) {
        $changesAvailable = false;
        foreach ($this->collections as $folderid => $spa) {
            if ($onlyPingable && $spa->GetPingableFlag() !== true)
                continue;

            if (isset($this->addparms[$spa->GetFolderId()]["status"]) && $this->addparms[$spa->GetFolderId()]["status"] != SYNC_STATUS_SUCCESS)
                continue;

            if ($this->CountChange($folderid))
                $changesAvailable = true;
        }

        return $changesAvailable;
    }

    /**
     * Checks a folder for changes performing Exporter->GetChangeCount()
     *
     * @param string    $folderid   counts changes for a folder
     *
     * @access private
     * @return boolean      indicating if changes were found or not
     */
     private function CountChange($folderid) {
        $spa = $this->GetCollection($folderid);

        // switch user store if this is a additional folder (additional true -> do not debug)
        ZPush::GetBackend()->Setup(ZPush::GetAdditionalSyncFolderStore($folderid, true));
        $changecount = false;

        try {
            $exporter = ZPush::GetBackend()->GetExporter($folderid);
            if ($exporter !== false && isset($this->addparms[$folderid]["state"])) {
                $importer = false;

                $exporter->Config($this->addparms[$folderid]["state"], BACKEND_DISCARD_DATA);
                $exporter->ConfigContentParameters($spa->GetCPO());
                $ret = $exporter->InitializeExporter($importer);

                if ($ret !== false)
                    $changecount = $exporter->GetChangeCount();
            }
        }
        catch (StatusException $ste) {
            throw new StatusException("SyncCollections->CountChange(): exporter can not be re-configured.", self::ERROR_WRONG_HIERARCHY, null, LOGLEVEL_WARN);
        }

        // start over if exporter can not be configured atm
        if ($changecount === false )
            ZLog::Write(LOGLEVEL_WARN, "SyncCollections->CountChange(): no changes received from Exporter.");

        $this->changes[$folderid] = $changecount;

        if(isset($this->addparms[$folderid]['savestate'])) {
            try {
                // Discard any data
                while(is_array($exporter->Synchronize()));
                $this->addparms[$folderid]['savestate'] = $exporter->GetState();
            }
            catch (StatusException $ste) {
                throw new StatusException("SyncCollections->CountChange(): could not get new state from exporter", self::ERROR_WRONG_HIERARCHY, null, LOGLEVEL_WARN);
            }
        }

        return ($changecount > 0);
     }

    /**
     * Returns an array with all folderid and the amount of changes found
     *
     * @access public
     * @return array
     */
    public function GetChangedFolderIds() {
        return $this->changes;
    }

    /**
     * Indicates if the process did wait in a sink, polling or before running a
     * regular export to find changes
     *
     * @access public
     * @return array
     */
    public function WaitedForChanges() {
        return ($this->waitingTime > 1);
    }

    /**
     * Simple Iterator Interface implementation to traverse through collections
     */

    /**
     * Rewind the Iterator to the first element
     *
     * @access public
     * @return
     */
    public function rewind() {
        return reset($this->collections);
    }

    /**
     * Returns the current element
     *
     * @access public
     * @return mixed
     */
    public function current() {
        return current($this->collections);
    }

    /**
     * Return the key of the current element
     *
     * @access public
     * @return scalar on success, or NULL on failure.
     */
    public function key() {
        return key($this->collections);
    }

    /**
     * Move forward to next element
     *
     * @access public
     * @return
     */
    public function next() {
        return next($this->collections);
    }

    /**
     * Checks if current position is valid
     *
     * @access public
     * @return boolean
     */
    public function valid() {
        return (key($this->collections) !== null);
    }

    /**
     * Gets the StateManager from the DeviceManager
     * if it's not available
     *
     * @access private
     * @return
     */
     private function loadStateManager() {
         if (!isset($this->stateManager))
            $this->stateManager = ZPush::GetDeviceManager()->GetStateManager();
     }
}

?>