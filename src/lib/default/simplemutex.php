<?php
/***********************************************
* File      :   simplemutex.php
* Project   :   Z-Push
* Descr     :   Implements a simple mutex using InterProcessData
*
* Created   :   29.02.2012
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

class SimpleMutex extends InterProcessData {
    /**
     * Constructor
     */
    public function SimpleMutex() {
        // initialize super parameters
        $this->allocate = 64;
        $this->type = 5173;
        parent::__construct();

        if (!$this->IsActive()) {
            ZLog::Write(LOGLEVEL_ERROR, "SimpleMutex not available as InterProcessData is not available. This is not recommended on duty systems and may result in corrupt user/device linking.");
        }
    }

    /**
     * Blocks the mutex
     * Method blocks until mutex is available!
     * ATTENTION: make sure that you *always* release a blocked mutex!
     *
     * @access public
     * @return boolean
     */
    public function Block() {
        if ($this->IsActive())
            return $this->blockMutex();

        ZLog::Write(LOGLEVEL_WARN, "Could not enter mutex as InterProcessData is not available. This is not recommended on duty systems and may result in corrupt user/device linking!");
        return true;
    }

    /**
     * Releases the mutex
     * After the release other processes are able to block the mutex themselfs
     *
     * @access public
     * @return boolean
     */
    public function Release() {
        if ($this->IsActive())
            return $this->releaseMutex();

        return true;
    }
}
?>