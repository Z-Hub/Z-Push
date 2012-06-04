<?php
/***********************************************
* File      :   syncmeetingrequest.php
* Project   :   Z-Push
* Descr     :   WBXML folder entities that can be parsed
*               directly (as a stream) from WBXML.
*               It is automatically decoded
*               according to $mapping,
*               and the Sync WBXML mappings.
*
* Created   :   05.09.2011
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


class SyncMeetingRequest extends SyncObject {
    public $alldayevent;
    public $starttime;
    public $dtstamp;
    public $endtime;
    public $instancetype;
    public $location;
    public $organizer;
    public $recurrenceid;
    public $reminder;
    public $responserequested;
    public $recurrences;
    public $sensitivity;
    public $busystatus;
    public $timezone;
    public $globalobjid;

    function SyncMeetingRequest() {
        $mapping = array (
                    SYNC_POOMMAIL_ALLDAYEVENT                           => array (  self::STREAMER_VAR      => "alldayevent",
                                                                                    self::STREAMER_CHECKS   => array(   self::STREAMER_CHECK_ZEROORONE  => self::STREAMER_CHECK_SETZERO)),

                    SYNC_POOMMAIL_STARTTIME                             => array (  self::STREAMER_VAR      => "starttime",
                                                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE_DASHES,
                                                                                    self::STREAMER_CHECKS   => array(   self::STREAMER_CHECK_REQUIRED   => self::STREAMER_CHECK_SETZERO,
                                                                                                                        self::STREAMER_CHECK_CMPLOWER   => SYNC_POOMMAIL_ENDTIME ) ),

                    SYNC_POOMMAIL_DTSTAMP                               => array (  self::STREAMER_VAR      => "dtstamp",
                                                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE_DASHES,
                                                                                    self::STREAMER_CHECKS   => array(   self::STREAMER_CHECK_REQUIRED   => self::STREAMER_CHECK_SETZERO) ),

                    SYNC_POOMMAIL_ENDTIME                               => array (  self::STREAMER_VAR      => "endtime",
                                                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE_DASHES,
                                                                                    self::STREAMER_CHECKS   => array(   self::STREAMER_CHECK_REQUIRED   => self::STREAMER_CHECK_SETONE,
                                                                                                                        self::STREAMER_CHECK_CMPHIGHER  => SYNC_POOMMAIL_STARTTIME ) ),
                    // Instancetype values
                    // 0 = single appointment
                    // 1 = master recurring appointment
                    // 2 = single instance of recurring appointment
                    // 3 = exception of recurring appointment
                    SYNC_POOMMAIL_INSTANCETYPE                          => array (  self::STREAMER_VAR      => "instancetype",
                                                                                    self::STREAMER_CHECKS   => array(   self::STREAMER_CHECK_REQUIRED   => self::STREAMER_CHECK_SETZERO,
                                                                                                                        self::STREAMER_CHECK_ONEVALUEOF => array(0,1,2,3) )),

                    SYNC_POOMMAIL_LOCATION                              => array (  self::STREAMER_VAR      => "location"),
                    SYNC_POOMMAIL_ORGANIZER                             => array (  self::STREAMER_VAR      => "organizer",
                                                                                    self::STREAMER_CHECKS   => array(   self::STREAMER_CHECK_REQUIRED   => self::STREAMER_CHECK_SETEMPTY ) ),

                    SYNC_POOMMAIL_RECURRENCEID                          => array (  self::STREAMER_VAR      => "recurrenceid",
                                                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE_DASHES),

                    SYNC_POOMMAIL_REMINDER                              => array (  self::STREAMER_VAR      => "reminder",
                                                                                    self::STREAMER_CHECKS   => array(   self::STREAMER_CHECK_CMPHIGHER      => -1)),

                    SYNC_POOMMAIL_RESPONSEREQUESTED                     => array (  self::STREAMER_VAR      => "responserequested"),
                    SYNC_POOMMAIL_RECURRENCES                           => array (  self::STREAMER_VAR      => "recurrences",
                                                                                    self::STREAMER_TYPE     => "SyncMeetingRequestRecurrence",
                                                                                    self::STREAMER_ARRAY    => SYNC_POOMMAIL_RECURRENCE),
                    // Sensitivity values
                    // 0 = Normal
                    // 1 = Personal
                    // 2 = Private
                    // 3 = Confident
                    SYNC_POOMMAIL_SENSITIVITY                           => array (  self::STREAMER_VAR      => "sensitivity",
                                                                                    self::STREAMER_CHECKS   => array(   self::STREAMER_CHECK_REQUIRED   => self::STREAMER_CHECK_SETZERO,
                                                                                                                        self::STREAMER_CHECK_ONEVALUEOF => array(0,1,2,3) )),

                    // Busystatus values
                    // 0 = Free
                    // 1 = Tentative
                    // 2 = Busy
                    // 3 = Out of office
                    SYNC_POOMMAIL_BUSYSTATUS                            => array (  self::STREAMER_VAR      => "busystatus",
                                                                                    self::STREAMER_CHECKS   => array(   self::STREAMER_CHECK_REQUIRED   => self::STREAMER_CHECK_SETTWO,
                                                                                                                        self::STREAMER_CHECK_ONEVALUEOF => array(0,1,2,3)  )),

                    SYNC_POOMMAIL_TIMEZONE                              => array (  self::STREAMER_VAR      => "timezone",
                                                                                    self::STREAMER_CHECKS   => array(   self::STREAMER_CHECK_REQUIRED   => base64_encode(pack("la64vvvvvvvv"."la64vvvvvvvv"."l",0,"",0,0,0,0,0,0,0,0,0,"",0,0,0,0,0,0,0,0,0)) )),

                    SYNC_POOMMAIL_GLOBALOBJID                           => array (  self::STREAMER_VAR      => "globalobjid"),
                );

        parent::SyncObject($mapping);
    }
}
?>