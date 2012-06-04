<?php
/***********************************************
* File      :   syncmailflags.php
* Project   :   Z-Push
* Descr     :   WBXML AirSyncBase body entities that can be parsed
*               directly (as a stream) from WBXML.
*               It is automatically decoded according to $mapping,
*               and the Sync WBXML mappings.
*
* Created   :   09.09.2011
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

class SyncMailFlags extends SyncObject {
    public $subject;
    public $flagstatus;
    public $flagtype; //Possible types are clear, complete, active
    public $datecompleted;
    public $completetime;
    public $startdate;
    public $duedate;
    public $utcstartdate;
    public $utcduedate;
    public $reminderset;
    public $remindertime;
    public $ordinaldate;
    public $subordinaldate;


    function SyncMailFlags() {
        $mapping = array(
                    SYNC_POOMTASKS_SUBJECT                              => array (  self::STREAMER_VAR      => "subject"),
                    SYNC_POOMMAIL_FLAGSTATUS                            => array (  self::STREAMER_VAR      => "flagstatus"),
                    SYNC_POOMMAIL_FLAGTYPE                              => array (  self::STREAMER_VAR      => "flagtype"),
                    SYNC_POOMTASKS_DATECOMPLETED                        => array (  self::STREAMER_VAR      => "datecompleted",
                                                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE_DASHES),

                    SYNC_POOMMAIL_COMPLETETIME                          => array (  self::STREAMER_VAR      => "completetime",
                                                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE_DASHES),

                    SYNC_POOMTASKS_STARTDATE                            => array (  self::STREAMER_VAR      => "startdate",
                                                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE_DASHES),

                    SYNC_POOMTASKS_DUEDATE                              => array (  self::STREAMER_VAR      => "duedate",
                                                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE_DASHES),

                    SYNC_POOMTASKS_UTCSTARTDATE                         => array (  self::STREAMER_VAR      => "utcstartdate",
                                                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE_DASHES),

                    SYNC_POOMTASKS_UTCDUEDATE                           => array (  self::STREAMER_VAR      => "utcduedate",
                                                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE_DASHES),

                    SYNC_POOMTASKS_REMINDERSET                          => array (  self::STREAMER_VAR      => "reminderset"),
                    SYNC_POOMTASKS_REMINDERTIME                         => array (  self::STREAMER_VAR      => "remindertime",
                                                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE_DASHES),

                    SYNC_POOMTASKS_ORDINALDATE                          => array (  self::STREAMER_VAR      => "ordinaldate",
                                                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE_DASHES),

                    SYNC_POOMTASKS_SUBORDINALDATE                       => array (  self::STREAMER_VAR      => "subordinaldate"),
        );

        parent::SyncObject($mapping);
    }
}
?>