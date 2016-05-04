<?php
/***********************************************
* File      :   iipcprovider.php
* Project   :   Z-Push
* Descr     :   Interface for interprocess communication
*               providers for different purposes
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

interface IIpcProvider
{
    /**
     * Constructor
     *
	 * @param int $type
	 * @param int $allocate
	 * @param string $class
	 */
    public function __construct($type, $allocate, $class);

    /**
     * Cleans up the shared memory block
     *
     * @access public
     * @return boolean
     */
    public function Clean();

    /**
     * Indicates if the shared memory is active
     *
     * @access public
     * @return boolean
     */
    public function IsActive();

    /**
     * Blocks the class mutex
     * Method blocks until mutex is available!
     * ATTENTION: make sure that you *always* release a blocked mutex!
     *
     * @access protected
     * @return boolean
     */
    public function blockMutex();

    /**
     * Releases the class mutex
     * After the release other processes are able to block the mutex themselfs
     *
     * @access protected
     * @return boolean
     */
    public function releaseMutex();

    /**
     * Indicates if the requested variable is available in shared memory
     *
     * @param int   $id     int indicating the variable
     *
     * @access protected
     * @return boolean
     */
    public function hasData($id = 2);

    /**
     * Returns the requested variable from shared memory
     *
     * @param int   $id     int indicating the variable
     *
     * @access protected
     * @return mixed
     */
    public function getData($id = 2);

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
    public function setData($data, $id = 2);
}
