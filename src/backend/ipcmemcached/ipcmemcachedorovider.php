<?php
/***********************************************
* File      :   ipcmemcachedorovider.php
* Project   :   Z-Push
* Descr     :   IPC provider using Memcached PHP extension
*               and memcached servers defined in
*               $zpush_ipc_memcached_servers
*
* Created   :   22.11.2015 by Ralf Becker <rb@stylite.de>
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

class IpcMemcachedProvider implements IIpcProvider {
    protected $type;
    private $maxWaitCycles;

    /**
     * Instance of memcached class
     *
     * @var memcached
     */
    protected $memcached;


    /**
     * Constructor
     *
     * @param int $type
     * @param int $allocate
     * @param string $class
     */
    public function __construct($type, $allocate, $class) {
        global $zpush_ipc_memcached_servers;
        $this->type = $type;
        $this->maxWaitCycles = round(MEMCACHED_MUTEX_TIMEOUT * 1000 * 1000 / MEMCACHED_BLOCK_USLEEP);

        // not used, but required by function signature
        unset($allocate, $class);

        if (!class_exists('Memcached')) {
            throw new FatalMisconfigurationException("IpcMemcachedProvider failure: can not find class Memcached. Please make sure the php memcached extension is installed.");
        }

        $this->memcached = new Memcached(md5(serialize($zpush_ipc_memcached_servers)));

        $this->memcached->setOptions(array(
            // setting a short timeout, to better kope with failed nodes
            Memcached::OPT_CONNECT_TIMEOUT => 5,
            Memcached::OPT_SEND_TIMEOUT => MEMCACHED_TIMEOUT * 1000,
            Memcached::OPT_RECV_TIMEOUT => MEMCACHED_TIMEOUT * 1000,
            Memcached::OPT_TCP_NODELAY => true,
            Memcached::OPT_NO_BLOCK => false,

            // use igbinary, if available
            Memcached::OPT_SERIALIZER => Memcached::HAVE_IGBINARY ? Memcached::SERIALIZER_IGBINARY : (Memcached::HAVE_JSON ? Memcached::SERIALIZER_JSON : Memcached::SERIALIZER_PHP),
            // use more efficient binary protocol (also required for consistent hashing)
            Memcached::OPT_BINARY_PROTOCOL => true,
            // enable Libketama compatible consistent hashing
            Memcached::OPT_LIBKETAMA_COMPATIBLE => true,
            // automatic failover and disabling of failed nodes
            Memcached::OPT_SERVER_FAILURE_LIMIT => 2,
            Memcached::OPT_AUTO_EJECT_HOSTS => true,
            // setting a prefix for all keys
            Memcached::OPT_PREFIX_KEY => MEMCACHED_PREFIX,
        ));

        // with persistent connections, only add servers, if they not already added!
        if (!count($this->memcached->getServerList())) {
            foreach($zpush_ipc_memcached_servers as $host_port) {
                $parts = explode(':', $host_port);
                $host = array_shift($parts);
                $port = $parts ? array_shift($parts) : 11211;    // default port

                $this->memcached->addServer($host, $port);
            }
        }
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
        return false;
    }

    /**
     * Indicates if the shared memory is active
     *
     * @access public
     * @return boolean
     */
    public function IsActive() {
        return true;
    }

    /**
     * Blocks the class mutex
     * Method blocks until mutex is available!
     * ATTENTION: make sure that you *always* release a blocked mutex!
     *
     * We try to add mutex to our cache, until we succeed.
     * It will fail as long other client has stored it or the
     * MEMCACHED_MUTEX_TIMEOUT is reached.
     *
     * @access protected
     * @return boolean
     */
    public function blockMutex() {
        $n = 0;
        while(!$this->memcached->add($this->type+10, true, MEMCACHED_MUTEX_TIMEOUT)) {
            if ($n++) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("IpcMemcachedProvider->BlockMutex() waiting to aquire mutex for type: %s", $this->type));
            }
            // wait before retrying
            usleep(MEMCACHED_BLOCK_USLEEP);
            if ($n > $this->maxWaitCycles) {
                ZLog::Write(LOGLEVEL_ERROR, sprintf("IpcMemcachedProvider->BlockMutex() could not aquire mutex for type: %s. Check memcache service!", $this->type));
                return false;
            }
        }
        if ($n) {
            ZLog::Write(LOGLEVEL_WARN, sprintf("IpcMemcachedProvider->BlockMutex() mutex aquired after waiting for %sms for type: %s", ($n*MEMCACHED_BLOCK_USLEEP/1000), $this->type));
        }
        return true;
    }

    /**
     * Releases the class mutex
     * After the release other processes are able to block the mutex themselfs
     *
     * @access protected
     * @return boolean
     */
    public function releaseMutex() {
        return $this->memcached->delete($this->type+10);
    }

    /**
     * Indicates if the requested variable is available in shared memory
     *
     * @param int   $id     int indicating the variable
     *
     * @access protected
     * @return boolean
     */
    public function hasData($id = 2) {
        $this->memcached->get($this->type.':'.$id);
        return $this->memcached->getResultCode() === Memcached::RES_SUCCESS;
    }

    /**
     * Returns the requested variable from shared memory
     *
     * @param int   $id     int indicating the variable
     *
     * @access protected
     * @return mixed
     */
    public function getData($id = 2) {
        return $this->memcached->get($this->type.':'.$id);
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
    public function setData($data, $id = 2) {
        return $this->memcached->set($this->type.':'.$id, $data);
   }

    /**
     * Sets the time when the shared memory block was created
     *
     * @access private
     * @return boolean
     */
    private function setInitialCleanTime() {
        return true;
    }
}
