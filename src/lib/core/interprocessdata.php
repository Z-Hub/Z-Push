<?php
/***********************************************
* File      :   interprocessdata.php
* Project   :   Z-Push
* Descr     :   Class takes care of interprocess
*               communicaton for different purposes
*               using a backend implementing IIpcBackend
*
* Created   :   20.10.2011
*
* Copyright 2007 - 2016 Zarafa Deutschland GmbH
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

// TODO ZP-821 - remove when autoloading
include_once('backend/ipcsharedmemory/ipcsharedmemoryprovider.php');

abstract class InterProcessData {
    // Defines which IPC provider to laod, first has preference
    // if IPC_PROVIDER in the main config  is set, that class will be loaded
    const PROVIDER_LOAD_ORDER = array('IpcMemcachedProvider', 'IpcSharedMemoryProvider');
    const CLEANUPTIME = 1;

    static protected $devid;
    static protected $pid;
    static protected $user;
    static protected $start;
    protected $type;
    protected $allocate;
    protected $provider_class;

    /**
     * @var IIpcProvider
     */
    private $ipcProvider;

    /**
     * Constructor
     *
     * @access public
     */
    public function __construct() {
        if (!isset($this->type) || !isset($this->allocate))
            throw new FatalNotImplementedException(sprintf("Class InterProcessData can not be initialized. Subclass %s did not initialize type and allocable memory.", get_class($this)));

        $this->provider_class = defined('IPC_PROVIDER') ? IPC_PROVIDER : false;
        ZLog::Write(LOGLEVEL_DEBUG, "---------------------------------------------- provider class:". Utils::PrintAsString($this->provider_class));
        if (!$this->provider_class) {
            foreach(self::PROVIDER_LOAD_ORDER as $provider) {
                ZLog::Write(LOGLEVEL_DEBUG, "---------------------------------------------- provider $provider exists:". class_exists($provider));
                if (class_exists($provider)) {
                    $this->provider_class = $provider;
                    break;
                }
            }
        }

        try {
            if (!$this->provider_class) {
                throw new Exception("No IPC provider available");
            }
            $this->ipcProvider = new $this->provider_class($this->type, $this->allocate, get_class($this));
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("%s initialised with IPC provider '%s'", get_class($this), $this->provider_class));

        }
        catch (Exception $e) {
            // ipcProvider could not initialise
            ZLog::Write(LOGLEVEL_ERROR, sprintf("%s could not initialise IPC provider '%s': %s", get_class($this), $this->provider_class, $e->getMessage()));
        }

    }

    /**
     * Initializes internal parameters
     *
     * @access public
     * @return boolean
     */
    public function InitializeParams() {
        if (!isset(self::$devid)) {
            self::$devid = Request::GetDeviceID();
            self::$pid = @getmypid();
            self::$user = Request::GetAuthUser();
            self::$start = time();
        }
        return true;
    }

    /**
     * Cleans up the shared memory block
     *
     * @access public
     * @return boolean
     */
    public function Clean() {
        return $this->ipcProvider ? $this->ipcProvider->Clean() : false;
    }

    /**
     * Indicates if the shared memory is active
     *
     * @access public
     * @return boolean
     */
    public function IsActive() {
        return $this->ipcProvider ? $this->ipcProvider->IsActive() : false;
    }

    /**
     * Blocks the class mutex
     * Method blocks until mutex is available!
     * ATTENTION: make sure that you *always* release a blocked mutex!
     *
     * @access protected
     * @return boolean
     */
    protected function blockMutex() {
        return $this->ipcProvider ? $this->ipcProvider->blockMutex() : false;
    }

    /**
     * Releases the class mutex
     * After the release other processes are able to block the mutex themselfs
     *
     * @access protected
     * @return boolean
     */
    protected function releaseMutex() {
        return $this->ipcProvider ? $this->ipcProvider->releaseMutex() : false;
    }

    /**
     * Indicates if the requested variable is available in shared memory
     *
     * @param int   $id     int indicating the variable
     *
     * @access protected
     * @return boolean
     */
    protected function hasData($id = 2) {
        return $this->ipcProvider ? $this->ipcProvider->hasData($id) : false;
    }

    /**
     * Returns the requested variable from shared memory
     *
     * @param int   $id     int indicating the variable
     *
     * @access protected
     * @return mixed
     */
    protected function getData($id = 2) {
        return $this->ipcProvider ? $this->ipcProvider->getData($id) : null;
    }

    /**
     * Writes the transmitted variable to shared memory
     * Subclasses may never use an id < 2!
     *
     * @param mixed $data   data which should be saved into shared memory
     * @param int   $id     int indicating the variable (bigger than 2!)
     *
     * @access protected
     * @return boolean
     */
    protected function setData($data, $id = 2) {
        return $this->ipcProvider ? $this->ipcProvider->setData($data, $id) : false;
    }
}
