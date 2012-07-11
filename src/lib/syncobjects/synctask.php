<?php
/***********************************************
* File      :   synctask.php
* Project   :   Z-Push
* Descr     :   WBXML task entities that can be parsed
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


class SyncTask extends SyncObject {
    public $body;
    public $complete;
    public $datecompleted;
    public $duedate;
    public $utcduedate;
    public $importance;
    public $recurrence;
    public $regenerate;
    public $deadoccur;
    public $reminderset;
    public $remindertime;
    public $sensitivity;
    public $startdate;
    public $utcstartdate;
    public $subject;
    public $rtf;
    public $categories;

    function SyncTask() {
        $mapping = array (
                    SYNC_POOMTASKS_BODY                                 => array (  self::STREAMER_VAR      => "body"),
                    SYNC_POOMTASKS_COMPLETE                             => array (  self::STREAMER_VAR      => "complete",
                                                                                    self::STREAMER_CHECKS   => array(   self::STREAMER_CHECK_REQUIRED       => self::STREAMER_CHECK_SETZERO,
                                                                                                                        self::STREAMER_CHECK_ZEROORONE      => self::STREAMER_CHECK_SETZERO )),

                    SYNC_POOMTASKS_DATECOMPLETED                        => array (  self::STREAMER_VAR      => "datecompleted",
                                                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE_DASHES),

                    SYNC_POOMTASKS_DUEDATE                              => array (  self::STREAMER_VAR      => "duedate",
                                                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE_DASHES),

                    SYNC_POOMTASKS_UTCDUEDATE                           => array (  self::STREAMER_VAR      => "utcduedate",
                                                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE_DASHES),

                    // Importance values
                    // 0 = Low
                    // 1 = Normal
                    // 2 = High
                    // even the default value 1 is optional, the native android client 2.2 interprets a non-existing value as 0 (low)
                    SYNC_POOMTASKS_IMPORTANCE                           => array (  self::STREAMER_VAR      => "importance",
                                                                                    self::STREAMER_CHECKS   => array(   self::STREAMER_CHECK_REQUIRED       => self::STREAMER_CHECK_SETONE,
                                                                                                                        self::STREAMER_CHECK_ONEVALUEOF     => array(0,1,2) )),

                    SYNC_POOMTASKS_RECURRENCE                           => array (  self::STREAMER_VAR      => "recurrence",
                                                                                    self::STREAMER_TYPE     => "SyncTaskRecurrence"),

                    SYNC_POOMTASKS_REGENERATE                           => array (  self::STREAMER_VAR      => "regenerate"),
                    SYNC_POOMTASKS_DEADOCCUR                            => array (  self::STREAMER_VAR      => "deadoccur"),
                    SYNC_POOMTASKS_REMINDERSET                          => array (  self::STREAMER_VAR      => "reminderset",
                                                                                    self::STREAMER_CHECKS   => array(   self::STREAMER_CHECK_REQUIRED       => self::STREAMER_CHECK_SETZERO,
                                                                                                                        self::STREAMER_CHECK_ZEROORONE      => self::STREAMER_CHECK_SETZERO )),

                    SYNC_POOMTASKS_REMINDERTIME                         => array (  self::STREAMER_VAR      => "remindertime",
                                                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE_DASHES),

                    // Sensitivity values
                    // 0 = Normal
                    // 1 = Personal
                    // 2 = Private
                    // 3 = Confident
                    SYNC_POOMTASKS_SENSITIVITY                          => array (  self::STREAMER_VAR      => "sensitivity",
                                                                                    self::STREAMER_CHECKS   => array(   self::STREAMER_CHECK_ONEVALUEOF => array(0,1,2,3) )),

                    SYNC_POOMTASKS_STARTDATE                            => array (  self::STREAMER_VAR      => "startdate",
                                                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE_DASHES),

                    SYNC_POOMTASKS_UTCSTARTDATE                         => array (  self::STREAMER_VAR      => "utcstartdate",
                                                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE_DASHES),

                    SYNC_POOMTASKS_SUBJECT                              => array (  self::STREAMER_VAR      => "subject"),
                    SYNC_POOMTASKS_RTF                                  => array (  self::STREAMER_VAR      => "rtf"),
                    SYNC_POOMTASKS_CATEGORIES                           => array (  self::STREAMER_VAR      => "categories",
                                                                                    self::STREAMER_ARRAY    => SYNC_POOMTASKS_CATEGORY),
                );

            if (Request::GetProtocolVersion() >= 12.0) {
            $mapping[SYNC_AIRSYNCBASE_BODY]                             = array (   self::STREAMER_VAR      => "asbody",
                                                                                    self::STREAMER_TYPE     => "SyncBaseBody");

            //unset these properties because airsyncbase body and attachments will be used instead
            unset($mapping[SYNC_POOMTASKS_BODY]);
        }

        parent::SyncObject($mapping);
    }

    /**
     * Method checks if the object has the minimum of required parameters
     * and fullfills semantic dependencies
     *
     * This overloads the general check() with special checks to be executed
     *
     * @param boolean   $logAsDebug     (opt) default is false, so messages are logged in WARN log level
     *
     * @access public
     * @return boolean
     */
    public function Check($logAsDebug = false) {
        $ret = parent::Check($logAsDebug);

        // semantic checks general "turn off switch"
        if (defined("DO_SEMANTIC_CHECKS") && DO_SEMANTIC_CHECKS === false)
            return $ret;

        if (!$ret)
            return false;

        if (isset($this->startdate) && isset($this->duedate) && $this->duedate < $this->startdate) {
            ZLog::Write(LOGLEVEL_WARN, sprintf("SyncObject->Check(): Unmet condition in object from type %s: parameter 'startdate' is HIGHER than 'duedate'. Check failed!", get_class($this) ));
            return false;
        }

        if (isset($this->utcstartdate) && isset($this->utcduedate) && $this->utcduedate < $this->utcstartdate) {
            ZLog::Write(LOGLEVEL_WARN, sprintf("SyncObject->Check(): Unmet condition in object from type %s: parameter 'utcstartdate' is HIGHER than 'utcduedate'. Check failed!", get_class($this) ));
            return false;
        }

        if (isset($this->duedate) && $this->duedate != Utils::getDayStartOfTimestamp($this->duedate)) {
            $this->duedate = Utils::getDayStartOfTimestamp($this->duedate);
            ZLog::Write(LOGLEVEL_DEBUG, "Set the due time to the start of the day");
            if (isset($this->startdate) && $this->duedate < $this->startdate) {
                $this->startdate = Utils::getDayStartOfTimestamp($this->startdate);
                ZLog::Write(LOGLEVEL_DEBUG, "Set the start date to the start of the day");
            }
        }

        return true;
    }
}

?>