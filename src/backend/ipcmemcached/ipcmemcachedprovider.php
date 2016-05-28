<?php
/***********************************************
* File      :   ipcmemcachedprovider.php
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
//include own config file
require_once("backend/ipcmemcached/config.php");

class IpcMemcachedProvider implements IIpcProvider {
    protected $type;
    private $maxWaitCycles;
    private $logWaitCycles;
    private $isDownUntil;
    private $wasDown;
    private $reconnectCount;

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
        $this->type = $type;
        $this->maxWaitCycles = round(MEMCACHED_MUTEX_TIMEOUT * 1000 / MEMCACHED_BLOCK_WAIT)+1;
        $this->logWaitCycles = round($this->maxWaitCycles/5);

        // not used, but required by function signature
        unset($allocate, $class);

        if (!class_exists('Memcached')) {
            throw new FatalMisconfigurationException("IpcMemcachedProvider failure: can not find class Memcached. Please make sure the php memcached extension is installed.");
        }

        $this->reconnectCount = 0;
        $this->init();

        // check if memcached was down recently
        $this->isDownUntil = $this->getIsDownUntil();
        $this->wasDown = ! $this->IsActive();
    }

    /**
     * Initializes the Memcached object & connection.
     *
     * @access private
     * @return void
     */
    private function init() {
        $this->memcached = new Memcached(md5(MEMCACHED_SERVERS) . $this->reconnectCount++);
        $this->memcached->setOptions(array(
            // setting a short timeout, to better kope with failed nodes
            Memcached::OPT_CONNECT_TIMEOUT => MEMCACHED_TIMEOUT,
            Memcached::OPT_SEND_TIMEOUT => MEMCACHED_TIMEOUT * 1000,
            Memcached::OPT_RECV_TIMEOUT => MEMCACHED_TIMEOUT * 1000,

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
            foreach(explode(',', MEMCACHED_SERVERS) as $host_port) {
                list($host,$port) = explode(':', trim($host_port));
                $this->memcached->addServer($host, $port);
            }
        }
    }

    /**
     * Reinitializes the IPC data. If the provider has no way of performing
     * this action, it should return 'false'.
     *
     * @access public
     * @return boolean
     */
    public function ReInitIPC() {
        // this is not supported in memcache
        return false;
    }

    /**
     * Cleans up the IPC data block.
     *
     * @access public
     * @return boolean
     */
    public function Clean() {
        return false;
    }

    /**
     * Indicates if the IPC is active.
     *
     * @access public
     * @return boolean
     */
    public function IsActive() {
        $down = $this->isDownUntil > time();
        // reconnect if we were down but should retry now
        if (!$down && $this->wasDown) {
            ZLog::Write(LOGLEVEL_DEBUG, "IpcMemcachedProvider->IsActive(): memcache was down, trying to reconnect");
            $this->init();
            $this->wasDown = false;
        }
        return !$down;
    }

    /**
     * Blocks the class mutex.
     * Method blocks until mutex is available!
     * ATTENTION: make sure that you *always* release a blocked mutex!
     *
     * We try to add mutex to our cache, until we succeed.
     * It will fail as long other client has stored it or the
     * MEMCACHED_MUTEX_TIMEOUT is reached.
     *
     * @access public
     * @return boolean
     */
    public function BlockMutex() {
        if (!$this->IsActive()) {
            return false;
        }

        $n = 0;
        while(!$this->memcached->add($this->type+10, true, MEMCACHED_MUTEX_TIMEOUT)) {
            if (++$n % $this->logWaitCycles == 0) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("IpcMemcachedProvider->BlockMutex() waiting to aquire mutex for type: %s ", $this->type));
            }
            // wait before retrying
            usleep(MEMCACHED_BLOCK_WAIT * 1000);
            if ($n > $this->maxWaitCycles) {
                ZLog::Write(LOGLEVEL_ERROR, sprintf("IpcMemcachedProvider->BlockMutex() could not aquire mutex for type: %s. Check memcache service!", $this->type));
                $this->markAsDown();
                return false;
            }
        }
        if ($n*MEMCACHED_BLOCK_WAIT > 50) {
            ZLog::Write(LOGLEVEL_WARN, sprintf("IpcMemcachedProvider->BlockMutex() mutex aquired after waiting for %sms for type: %s", ($n*MEMCACHED_BLOCK_WAIT), $this->type));
        }
        return true;
    }

    /**
     * Releases the class mutex.
     * After the release other processes are able to block the mutex themselves.
     *
     * @access public
     * @return boolean
     */
    public function ReleaseMutex() {
        return $this->memcached->delete($this->type+10);
    }

    /**
     * Indicates if the requested variable is available in IPC data.
     *
     * @param int   $id     int indicating the variable
     *
     * @access public
     * @return boolean
     */
    public function HasData($id = 2) {
        $this->memcached->get($this->type.':'.$id);
        return $this->memcached->getResultCode() === Memcached::RES_SUCCESS;
    }

    /**
     * Returns the requested variable from IPC data.
     *
     * @param int   $id     int indicating the variable
     *
     * @access public
     * @return mixed
     */
    public function GetData($id = 2) {
        return $this->memcached->get($this->type.':'.$id);
    }

    /**
     * Writes the transmitted variable to IPC data.
     * Subclasses may never use an id < 2!
     *
     * @param mixed $data   data which should be saved into IPC data
     * @param int   $id     int indicating the variable (bigger than 2!)
     *
     * @access public
     * @return boolean
     */
    public function SetData($data, $id = 2) {
        return $this->memcached->set($this->type.':'.$id, $data);
    }

    /**
     * Gets the epoch time until the memcache server should not be retried.
     * If there is no data available, 0 is returned.
     *
     * @access private
     * @return long
     */
    private function getIsDownUntil() {
        if (file_exists(MEMCACHED_DOWN_LOCK_FILE)) {
            $timestamp = file_get_contents(MEMCACHED_DOWN_LOCK_FILE);
            // is the lock file expired?
            if ($timestamp > time()) {
                ZLog::Write(LOGLEVEL_WARN, sprintf("IpcMemcachedProvider(): Memcache service is marked as down until %s.", strftime("%d.%m.%Y %H:%M:%S", $timestamp)));
                return $timestamp;
            }
            else {
                unlink(MEMCACHED_DOWN_LOCK_FILE);
            }
        }
        return 0;
    }

    /**
     * Indicates that memcache is not available and that it should not be retried.
     *
     * @access private
     * @return boolean
     */
    private function markAsDown() {
        ZLog::Write(LOGLEVEL_WARN, sprintf("IpcMemcachedProvider(): Marking memcache service as down for %d seconds.", MEMCACHED_DOWN_LOCK_EXPIRATION));
        $downUntil = time() + MEMCACHED_DOWN_LOCK_EXPIRATION;
        $this->isDownUntil = $downUntil;
        $this->wasDown = true;
        return !!file_put_contents(MEMCACHED_DOWN_LOCK_FILE, $downUntil);
    }
}