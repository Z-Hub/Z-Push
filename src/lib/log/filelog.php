<?php
/***********************************************
 * File      :   filelog.php
 * Project   :   Z-Push
 * Descr     :   Logging functionalities
 *
 * Created   :   13.11.2015
 *
 * Copyright 2007 - 2016 Zarafa Deutschland GmbH
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

class FileLog extends Log {

    /**
     * @var string|bool
     */
    private $log_to_user_file = false;

    /**
     * Constructor
     */
    public function __construct() {
    }

    /**
     * Get the log user file.
     *
     * @access private
     * @return string
     */
    private function getLogToUserFile() {
        if ($this->log_to_user_file === false) {
            $this->setLogToUserFile(preg_replace('/[^a-z0-9]/', '_', strtolower($this->GetAuthUser())) . '.log');
        }
        return $this->log_to_user_file;
    }

    /**
     * Set user log-file relative to log directory.
     *
     * @param string $value
     *
     * @access private
     * @return void
     */
    private function setLogToUserFile($value) {
        $this->log_to_user_file = $value;
    }

    /**
     * Returns the string to be logged.
     *
     * @param int $loglevel
     * @param string $message
     *
     * @access public
     * @return string
     */
    public function BuildLogString($loglevel, $message) {
        $log = Utils::GetFormattedTime() . ' ' . $this->GetPidstr() . ' ' . $this->GetLogLevelString($loglevel, $loglevel >= LOGLEVEL_INFO) . ' ' . $this->GetUser();
        if (LOGLEVEL >= LOGLEVEL_DEVICEID || (LOGUSERLEVEL >= LOGLEVEL_DEVICEID && $this->IsAuthUserInSpecialLogUsers())) {
            $log .= ' ' . $this->GetDevid();
        }
        $log .= ' ' . $message;
        return $log;
    }

    //
    // Implementation of Log
    //

    /**
     * Writes a log message to the general log.
     *
     * @param int $loglevel
     * @param string $message
     *
     * @access protected
     * @return void
     */
    protected function Write($loglevel, $message) {
        $data = $this->buildLogString($loglevel, $message) . PHP_EOL;
        @file_put_contents(LOGFILE, $data, FILE_APPEND);

        if (($loglevel & LOGLEVEL_FATAL) || ($loglevel & LOGLEVEL_ERROR)) {
            @file_put_contents(LOGERRORFILE, $data, FILE_APPEND);
        }
    }

    /**
     * Writes a log message to the user specific log.
     * @param int $loglevel
     * @param string $message
     *
     * @access public
     * @return void
     */
    public function WriteForUser($loglevel, $message) {
        $data = $this->buildLogString($loglevel, $message) . PHP_EOL;
        @file_put_contents(LOGFILEDIR . $this->getLogToUserFile(), $data, FILE_APPEND);
    }

    /**
     * This function is used as an event for log implementer.
     * It happens when the a call to the Log function is finished.
     *
     * @access protected
     * @return void
     */
    protected function afterLog($loglevel, $message) {
        if (($loglevel & LOGLEVEL_FATAL) || ($loglevel & LOGLEVEL_ERROR)) {
            $data = $this->buildLogString($loglevel, $message) . PHP_EOL;
            @file_put_contents(LOGERRORFILE, $data, FILE_APPEND);
        }
    }
}
