<?php
/***********************************************
* File      :   responsetrait.php
* Project   :   Z-Push
* Descr     :   A trait used in response objects to ensure there is always an
*               serverid to be responded to the client.
*
* Created   :   17.03.2023
*
* Copyright 2007 - 2016 Zarafa Deutschland GmbH
* Copyright 2023 grommunio GmbH
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
************************************************/

trait ResponseTrait {
    public $serverid;
    public $hasResponse;
    public function Check($logAsDebug = false) {
        return true;
    }
}