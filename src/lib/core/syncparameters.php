<?php
/***********************************************
* File      :   syncparameters.php
* Project   :   Z-Push
* Descr     :   Transportation container for
*               requested content parameters and information
*               about the container and states
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

class SyncParameters extends StateObject {
    const DEFAULTOPTIONS = "DEFAULT";
    const SMSOPTIONS = "SMS";

    private $synckeyChanged = false;
    private $currentCPO = self::DEFAULTOPTIONS;

    protected $unsetdata = array(
                                    'uuid' => false,
                                    'uuidcounter' => false,
                                    'uuidnewcounter' => false,
                                    'folderid' => false,
                                    'referencelifetime' => 10,
                                    'lastsynctime' => false,
                                    'referencepolicykey' => true,
                                    'pingableflag' => false,
                                    'contentclass' => false,
                                    'deletesasmoves' => false,
                                    'conversationmode' => false,
                                    'windowsize' => 5,
                                    'contentparameters' => array()
                                );

    /**
     * SyncParameters constructor
     */
    public function SyncParameters() {
        // initialize ContentParameters for the current option
        $this->checkCPO();
    }


    /**
     * SyncKey methods
     *
     * The current and next synckey is saved as uuid and counter
     * so partial and ping can access the latest states.
     */

    /**
     * Returns the latest SyncKey of this folder
     *
     * @access public
     * @return string/boolean       false if no uuid/counter available
     */
    public function GetSyncKey() {
        if (isset($this->uuid) && isset($this->uuidCounter))
            return StateManager::BuildStateKey($this->uuid, $this->uuidCounter);

        return false;
    }

    /**
     * Sets the the current synckey.
     * This is done by parsing it and saving uuid and counter.
     * By setting the current key, the "next" key is obsolete
     *
     * @param string    $synckey
     *
     * @access public
     * @return boolean
     */
    public function SetSyncKey($synckey) {
        list($this->uuid, $this->uuidCounter) = StateManager::ParseStateKey($synckey);

        // remove newSyncKey
        unset($this->uuidNewCounter);

        return true;
    }

    /**
     * Indicates if this folder has a synckey
     *
     * @access public
     * @return booleans
     */
    public function HasSyncKey() {
        return (isset($this->uuid) && isset($this->uuidCounter));
    }

    /**
     * Sets the the next synckey.
     * This is done by parsing it and saving uuid and next counter.
     * if the folder has no synckey until now (new sync), the next counter becomes current asl well.
     *
     * @param string    $synckey
     *
     * @access public
     * @throws FatalException       if the uuids of current and next do not match
     * @return boolean
     */
    public function SetNewSyncKey($synckey) {
        list($uuid, $uuidNewCounter) = StateManager::ParseStateKey($synckey);
        if (!$this->HasSyncKey()) {
            $this->uuid = $uuid;
            $this->uuidCounter = $uuidNewCounter;
        }
        else if ($uuid !== $this->uuid)
            throw new FatalException("SyncParameters->SetNewSyncKey(): new SyncKey must have the same UUID as current SyncKey");

        $this->uuidNewCounter = $uuidNewCounter;
        $this->synckeyChanged = true;
    }

    /**
     * Returns the next synckey
     *
     * @access public
     * @return string/boolean       returns false if uuid or counter are not available
     */
    public function GetNewSyncKey() {
        if (isset($this->uuid) && isset($this->uuidNewCounter))
            return StateManager::BuildStateKey($this->uuid, $this->uuidNewCounter);

        return false;
    }

    /**
     * Indicates if the folder has a next synckey
     *
     * @access public
     * @return boolean
     */
    public function HasNewSyncKey() {
        return (isset($this->uuid) && isset($this->uuidNewCounter));
    }

    /**
     * Return the latest synckey.
     * When this is called the new key becomes the current key (if a new key is available).
     * The current key is then returned.
     *
     * @access public
     * @return string
     */
    public function GetLatestSyncKey() {
        // New becomes old
        if ($this->HasUuidNewCounter()) {
            $this->uuidCounter = $this->uuidNewCounter;
            unset($this->uuidNewCounter);
        }

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SyncParameters->GetLastestSyncKey(): '%s'", $this->GetSyncKey()));
        return $this->GetSyncKey();
    }

    /**
     * Removes the saved SyncKey of this folder
     *
     * @access public
     * @return boolean
     */
    public function RemoveSyncKey() {
        if (isset($this->uuid))
            unset($this->uuid);

        if (isset($this->uuidCounter))
            unset($this->uuidCounter);

        if (isset($this->uuidNewCounter))
            unset($this->uuidNewCounter);

        ZLog::Write(LOGLEVEL_DEBUG, "SyncParameters->RemoveSyncKey(): saved sync key removed");
        return true;
    }


    /**
     * CPO methods
     *
     * A sync request can have several options blocks. Each block is saved into an own CPO object
     *
     */

    /**
     * Returns the a specified CPO
     *
     * @param string    $options    (opt) If not specified, the default Options (CPO) will be used
     *                              Valid option SyncParameters::SMSOPTIONS (string "SMS")
     *
     * @access public
     * @return ContentParameters object
     */
    public function GetCPO($options = self::DEFAULTOPTIONS) {
        if ($options !== self::DEFAULTOPTIONS && $options !== self::SMSOPTIONS)
            throw new FatalNotImplementedException(sprintf("SyncParameters->GetCPO('%s') ContentParameters is invalid. Such type is not available.", $options));

        $this->checkCPO($options);

        // copy contentclass and conversationmode to the CPO
        $this->contentParameters[$options]->SetContentClass($this->contentclass);
        $this->contentParameters[$options]->SetConversationMode($this->conversationmode);

        return $this->contentParameters[$options];
    }

    /**
     * Use the submitted CPO type for next setters/getters
     *
     * @param string    $options    (opt) If not specified, the default Options (CPO) will be used
     *                              Valid option SyncParameters::SMSOPTIONS (string "SMS")
     *
     * @access public
     * @return
     */
    public function UseCPO($options = self::DEFAULTOPTIONS) {
        if ($options !== self::DEFAULTOPTIONS && $options !== self::SMSOPTIONS)
            throw new FatalNotImplementedException(sprintf("SyncParameters->UseCPO('%s') ContentParameters is invalid. Such type is not available.", $options));

        ZLOG::Write(LOGLEVEL_DEBUG, sprintf("SyncParameters->UseCPO('%s')", $options));
        $this->currentCPO = $options;
        $this->checkCPO($this->currentCPO);
    }

    /**
     * Checks if a CPO is correctly inicialized and inicializes it if necessary
     *
     * @param string    $options    (opt) If not specified, the default Options (CPO) will be used
     *                              Valid option SyncParameters::SMSOPTIONS (string "SMS")
     *
     * @access private
     * @return boolean
     */
    private function checkCPO($options = self::DEFAULTOPTIONS) {
        if (!isset($this->contentParameters[$options])) {
            $a = $this->contentParameters;
            $a[$options] = new ContentParameters();
            $this->contentParameters = $a;
        }

        return true;
    }

    /**
     * PHP magic to implement any getter, setter, has and delete operations
     * on an instance variable.
     *
     * NOTICE: All magic getters and setters of this object which are not defined in the unsetdata array are passed to the current CPO.
     *
     * Methods like e.g. "SetVariableName($x)" and "GetVariableName()" are supported
     *
     * @access public
     * @return mixed
     */
    public function __call($name, $arguments) {
        $lowname = strtolower($name);
        $operator = substr($lowname, 0,3);
        $var = substr($lowname,3);

        if (array_key_exists($var, $this->unsetdata)) {
            return parent::__call($name, $arguments);
        }

        return $this->contentParameters[$this->currentCPO]->__call($name, $arguments);
    }


    /**
     * un/serialization methods
     */

    /**
     * Called before the StateObject is serialized
     *
     * @access protected
     * @return boolean
     */
    protected function preSerialize() {
        parent::preSerialize();

        if ($this->changed === true && $this->synckeyChanged)
            $this->lastsynctime = time();

        return true;
    }

    /**
     * Called after the StateObject was unserialized
     *
     * @access protected
     * @return boolean
     */
    protected function postUnserialize() {
        // init with default options
        $this->UseCPO();

        return true;
    }
}

?>