<?php
/***********************************************
* File      :   interprocessdata.php
* Project   :   Z-Push
* Descr     :   Class takes care of interprocess
*               communicaton for different purposes
*
* Created   :   20.10.2011
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

abstract class InterProcessData {
    const CLEANUPTIME = 1;

    static protected $devid;
    static protected $pid;
    static protected $user;
    static protected $start;
    protected $type;
    protected $allocate;
    private $mutexid;
    private $memid;

    /**
     * Constructor
     *
     * @access public
     */
    public function InterProcessData() {
        if (!isset($this->type) || !isset($this->allocate))
            throw new FatalNotImplementedException(sprintf("Class InterProcessData can not be initialized. Subclass %s did not initialize type and allocable memory.", get_class($this)));

        if ($this->InitSharedMem())
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("%s(): Initialized mutexid %s and memid %s.", get_class($this), $this->mutexid, $this->memid));
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
     * Allocates shared memory
     *
     * @access private
     * @return boolean
     */
    private function InitSharedMem() {
        // shared mem general "turn off switch"
        if (defined("USE_SHARED_MEM") && USE_SHARED_MEM === false) {
            ZLog::Write(LOGLEVEL_INFO, "InterProcessData::InitSharedMem(): the usage of shared memory for Z-Push has been disabled. Check your config for 'USE_SHARED_MEM'.");
            return false;
        }

        if (!function_exists('sem_get') || !function_exists('shm_attach') || !function_exists('sem_acquire')|| !function_exists('shm_get_var')) {
            ZLog::Write(LOGLEVEL_INFO, "InterProcessData::InitSharedMem(): PHP libraries for the use shared memory are not available. Functionalities like z-push-top or loop detection are not available. Check your php packages.");
            return false;
        }

        // Create mutex
        $this->mutexid = @sem_get($this->type, 1);
        if ($this->mutexid === false) {
            ZLog::Write(LOGLEVEL_ERROR, "InterProcessData::InitSharedMem(): could not aquire semaphore");
            return false;
        }

        // Attach shared memory
        $this->memid = shm_attach($this->type+10, $this->allocate);
        if ($this->memid === false) {
            ZLog::Write(LOGLEVEL_ERROR, "InterProcessData::InitSharedMem(): could not attach shared memory");
            @sem_remove($this->mutexid);
            $this->mutexid = false;
            return false;
        }

        // TODO mem cleanup has to be implemented
        //$this->setInitialCleanTime();

        return true;
    }

    /**
     * Removes and detaches shared memory
     *
     * @access private
     * @return boolean
     */
    private function RemoveSharedMem() {
        if ((isset($this->mutexid) && $this->mutexid !== false) && (isset($this->memid) && $this->memid !== false)) {
            @sem_acquire($this->mutexid);
            $memid = $this->memid;
            $this->memid = false;
            @sem_release($this->mutexid);

            @sem_remove($this->mutexid);
            @shm_remove($memid);
            @shm_detach($memid);

            $this->mutexid = false;

            return true;
        }
        return false;
    }

    /**
     * Reinitializes shared memory by removing, detaching and re-allocating it
     *
     * @access public
     * @return boolean
     */
    public function ReInitSharedMem() {
        return ($this->RemoveSharedMem() && $this->InitSharedMem());
    }

    /**
     * Cleans up the shared memory block
     *
     * @access public
     * @return boolean
     */
    public function Clean() {
        $stat = false;

        // exclusive block
        if ($this->blockMutex()) {
            $cleanuptime = ($this->hasData(1)) ? $this->getData(1) : false;

            // TODO implement Shared Memory cleanup

            $this->releaseMutex();
        }
        // end exclusive block

        return $stat;
    }

    /**
     * Indicates if the shared memory is active
     *
     * @access public
     * @return boolean
     */
    public function IsActive() {
        return ((isset($this->mutexid) && $this->mutexid !== false) && (isset($this->memid) && $this->memid !== false));
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
        if ((isset($this->mutexid) && $this->mutexid !== false) && (isset($this->memid) && $this->memid !== false))
            return @sem_acquire($this->mutexid);

        return false;
    }

    /**
     * Releases the class mutex
     * After the release other processes are able to block the mutex themselfs
     *
     * @access protected
     * @return boolean
     */
    protected function releaseMutex() {
        if ((isset($this->mutexid) && $this->mutexid !== false) && (isset($this->memid) && $this->memid !== false))
            return @sem_release($this->mutexid);

        return false;
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
        if ((isset($this->mutexid) && $this->mutexid !== false) && (isset($this->memid) && $this->memid !== false)) {
            if (function_exists("shm_has_var"))
                return @shm_has_var($this->memid, $id);
            else {
                $some = $this->getData($id);
                return isset($some);
            }
        }
        return false;
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
        if ((isset($this->mutexid) && $this->mutexid !== false) && (isset($this->memid) && $this->memid !== false))
            return @shm_get_var($this->memid, $id);

        return ;
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
        if ((isset($this->mutexid) && $this->mutexid !== false) && (isset($this->memid) && $this->memid !== false))
            return @shm_put_var($this->memid, $id, $data);

        return false;
    }

    /**
     * Sets the time when the shared memory block was created
     *
     * @access private
     * @return boolean
     */
    private function setInitialCleanTime() {
        $stat = false;

        // exclusive block
        if ($this->blockMutex()) {

            if ($this->hasData(1) == false)
                $stat = $this->setData(time(), 1);

            $this->releaseMutex();
        }
        // end exclusive block

        return $stat;
    }

}

?>