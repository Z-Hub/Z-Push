<?php
/***********************************************
* File      :   ipcbackendmemcached.php
* Project   :   Z-Push
* Descr     :   IPC backend using Memcached PHP extension
*               and memcached servers defined in $zpush_ipc_memcached_servers
*
* Created   :   22.11.2015 by Ralf Becker <rb@stylite.de>
*
* Copyright 2007 - 2013 Zarafa Deutschland GmbH
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

include_once('lib/interface/iipcbackend.php');

class IpcBackendMemcached implements IIpcBackend
{
	protected $type;
	/**
	 * Instance of memcached class
	 *
	 * @var Memcached
	 */
	protected $memcached;

	/**
	 * Timeout in ms
	 */
	const TIMEOUT = 20;

	/**
	 * Prefix to use for keys
	 */
	const PREFIX = 'z-push-ipc';

	/**
     * Constructor
     *
	 * @param int $type
	 * @param int $allocate
	 * @param string $class
	 */
    public function __construct($type, $allocate, $class) {
		global $zpush_ipc_memcached_servers;
		unset($allocate, $class);	// not used, but required by function signature
		$this->type = $type;

		$this->memcached = new Memcached(md5(serialize($zpush_ipc_memcached_servers)));

		$this->memcache->setOptions(array(
			// setting a short timeout, to better kope with failed nodes
			Memcached::OPT_CONNECT_TIMEOUT => self::TIMEOUT,
			Memcached::OPT_SEND_TIMEOUT => self::TIMEOUT,
			Memcached::OPT_RECV_TIMEOUT => self::TIMEOUT,
			// use igbinary, if available
			Memcached::OPT_SERIALIZER => Memcached::HAVE_IGBINARY ? Memcached::SERIALIZER_IGBINARY : Memcached::SERIALIZER_JSON,
			// use more effician binary protocol (also required for consistent hashing
			Memcached::OPT_BINARY_PROTOCOL => true,
			// enable Libketama compatible consistent hashing
			Memcached::OPT_LIBKETAMA_COMPATIBLE => true,
			// automatic failover and disabling of failed nodes
			Memcached::OPT_SERVER_FAILURE_LIMIT => 2,
			Memcached::OPT_AUTO_EJECT_HOSTS => true,
			// setting a prefix for all keys
			Memcached::OPT_PREFIX_KEY => self::PREFIX,
		));

		// with persistent connections, only add servers, if they not already added!
		if (!count($this->memcache->getServerList()))
		{
			foreach($zpush_ipc_memcached_servers as $host_port)
			{
				$parts = explode(':',$host_port);
				$host = array_shift($parts);
				$port = $parts ? array_shift($parts) : 11211;	// default port

				$this->memcache->addServer($host,$port);
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
	 * How long to wait, before trying again to aquire mutext (in millionth of sec)
	 */
	const BLOCK_USLEEP=20000;

    /**
     * Blocks the class mutex
     * Method blocks until mutex is available!
     * ATTENTION: make sure that you *always* release a blocked mutex!
	 *
	 * We try to add mutex to our cache, until we sucseed.
	 * It will fail as long other client has stored it
     *
     * @access protected
     * @return boolean
     */
    public function blockMutex() {
		$n = 0;
		while(!$this->memcached->add($this->type+10, true, 10))
		{
			if (!$n++) error_log(__METHOD__."() waiting to aquire mutex (this->type=$this->type)");
			usleep(self::BLOCK_USLEEP);	// wait 20ms before retrying
		}
		if ($n) error_log(__METHOD__."() mutex aquired after waiting for ".($n*self::BLOCK_USLEEP/1000)."ms (this->type=$this->type)");
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
		//error_log(__METHOD__."() this->type=$this->type");
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

	}
}
