<?php
/***********************************************
* File      :   synclocation.php
* Project   :   Z-Push
* Descr     :   WBXML location entities that can be parsed directly (as a stream)
*               from WBXML. It is automatically decoded according to $mapping and the
*               Sync WBXML mappings.
*
* Created   :   17.03.2023
*
* Copyright 2007 - 2016 Zarafa Deutschland GmbH
* Copyright 2020 - 2023 grommunio GmbH
* Copyright 2024 Mat Wilson
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

class SyncLocation extends SyncObject {
    public $displayname;
    public $annotation;
    public $street;
    public $city;
    public $state;
    public $country;
    public $postalcode;
    public $latitude;
    public $longitude;
    public $accuracy;
    public $altitude;
    public $altitudeaccuracy;
    public $locationuri;
    public function __construct() {
        $mapping = [
            SYNC_AIRSYNCBASE_DISPLAYNAME => [
                self::STREAMER_VAR => "displayname",
                self::STREAMER_RONOTIFY => true,
            ],
            SYNC_AIRSYNCBASE_ANNOTATION => [
                self::STREAMER_VAR => "annotation",
                self::STREAMER_RONOTIFY => true,
            ],
            SYNC_AIRSYNCBASE_STREET => [
                self::STREAMER_VAR => "street",
                self::STREAMER_RONOTIFY => true,
            ],
            SYNC_AIRSYNCBASE_CITY => [
                self::STREAMER_VAR => "city",
                self::STREAMER_RONOTIFY => true,
            ],
            SYNC_AIRSYNCBASE_STATE => [
                self::STREAMER_VAR => "state",
                self::STREAMER_RONOTIFY => true,
            ],
            SYNC_AIRSYNCBASE_COUNTRY => [
                self::STREAMER_VAR => "country",
                self::STREAMER_RONOTIFY => true,
            ],
            SYNC_AIRSYNCBASE_POSTALCODE => [
                self::STREAMER_VAR => "postalcode",
                self::STREAMER_RONOTIFY => true,
            ],
            SYNC_AIRSYNCBASE_LATITUDE => [
                self::STREAMER_VAR => "latitude",
                self::STREAMER_RONOTIFY => true,
            ],
            SYNC_AIRSYNCBASE_LONGITUDE => [
                self::STREAMER_VAR => "longitude",
                self::STREAMER_RONOTIFY => true,
            ],
            SYNC_AIRSYNCBASE_ACCURACY => [
                self::STREAMER_VAR => "accuracy",
                self::STREAMER_RONOTIFY => true,
            ],
            SYNC_AIRSYNCBASE_ALTITUDE => [
                self::STREAMER_VAR => "altitude",
                self::STREAMER_RONOTIFY => true,
            ],
            SYNC_AIRSYNCBASE_ALTITUDEACCURACY => [
                self::STREAMER_VAR => "altitudeaccuracy",
                self::STREAMER_RONOTIFY => true,
            ],
            SYNC_AIRSYNCBASE_LOCATIONURI => [
                self::STREAMER_VAR => "locationuri",
                self::STREAMER_RONOTIFY => true,
            ],
        ];
        parent::__construct($mapping);
    }
}