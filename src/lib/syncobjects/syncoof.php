<?php
/***********************************************
* File      :   syncoof.php
* Project   :   Z-Push
* Descr     :   WBXML appointment entities that can be
*               parsed directly (as a stream) from WBXML.
*               It is automatically decoded
*               according to $mapping,
*               and the Sync WBXML mappings
*
* Created   :   08.11.2011
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

class SyncOOF extends SyncObject {
    public $oofstate;
    public $starttime;
    public $endtime;
    public $oofmessage = array();
    public $bodytype;
    public $Status;

    public function SyncOOF() {
        $mapping = array (
            SYNC_SETTINGS_OOFSTATE      => array (  self::STREAMER_VAR      => "oofstate",
                                                    self::STREAMER_CHECKS   => array(   array(   self::STREAMER_CHECK_ONEVALUEOF => array(0,1,2) ))),

            SYNC_SETTINGS_STARTTIME      => array (  self::STREAMER_VAR      => "starttime",
                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE_DASHES),

            SYNC_SETTINGS_ENDTIME       => array (  self::STREAMER_VAR      => "endtime",
                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE_DASHES),

            SYNC_SETTINGS_OOFMESSAGE    => array (  self::STREAMER_VAR      => "oofmessage",
                                                    self::STREAMER_TYPE     => "SyncOOFMessage",
                                                    self::STREAMER_PROP     => self::STREAMER_TYPE_NO_CONTAINER,
                                                    self::STREAMER_ARRAY    => SYNC_SETTINGS_OOFMESSAGE),

            SYNC_SETTINGS_BODYTYPE      => array (  self::STREAMER_VAR      => "bodytype",
                                                    self::STREAMER_CHECKS   => array(   self::STREAMER_CHECK_ONEVALUEOF => array(SYNC_SETTINGSOOF_BODYTYPE_HTML, ucfirst(strtolower(SYNC_SETTINGSOOF_BODYTYPE_TEXT))) )),

            SYNC_SETTINGS_PROP_STATUS   => array (  self::STREAMER_VAR      => "Status",
                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_IGNORE)
        );

        parent::SyncObject($mapping);
    }

}
?>