<?php
/***********************************************
* File      :   userstoreinfo.php
* Project   :   Z-Push
* Descr     :   Contains information about user and his store.
*
* Created   :   14.05.2012
*
* Copyright 2007 - 2018 Zarafa Deutschland GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation.
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
*************************************************/

class UserStoreInfo {
    private $foldercount;
    private $storesize;
    private $fullname;
    private $emailaddress;

    /**
     * Constructor
     *
     * @access public
     */
    public function __construct() {
        $this->foldercount = 0;
        $this->storesize = 0;
        $this->fullname = null;
        $this->emailaddress = null;
    }

    /**
     * Sets data for the user's store.
     *
     * @param array $data
     * @access public
     *
     * @return void
     */
    public function SetData($data) {
        if (isset($data['foldercount'])) {
            $this->foldercount = $data['foldercount'];
        }
        if (isset($data['storesize'])) {
            $this->storesize = $data['storesize'];
        }
        if (isset($data['fullname'])) {
            $this->fullname = $data['fullname'];
        }
        if (isset($data['emailaddress'])) {
            $this->emailaddress = $data['emailaddress'];
        }
    }
}