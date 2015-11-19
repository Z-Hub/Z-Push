<?php
/***********************************************
 * File      :   filelog.php
 * Project   :   Z-Push
 * Descr     :   Logging functionalities
 *
 * Created   :   13.11.2015
 *
 * Copyright 2007 - 2015 Zarafa Deutschland GmbH
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
class FileLog extends Log {

    /**
     * @var string|bool
     */
    private $log_to_user_file = false;

    /**
     * Get the log user file. If the parameter is false, it tries to generate it.
     *
     * @access private
     * @return string|bool False if the log user file is not set and could not be generated otherwise string.
     */
    private function getLogToUserFile() {
        if ($this->log_to_user_file === false) {
            $this->setLogToUserFile(preg_replace('/[^a-z0-9]/', '_', strtolower(Request::GetAuthUser())) . '.log');
        }
        return $this->log_to_user_file;
    }

    /**
     * @param string $value
     */
    private function setLogToUserFile($value) {
        $this->log_to_user_file = $value;
    }

    public function __construct() {
    }

    /**
     * Returns the string to be logged
     *
     * @param int $loglevel
     * @param string $message
     *
     * @access public
     * @return string
     */
    public function BuildLogString($loglevel, $message) {
        $log = Utils::GetFormattedTime() . $this->GetPidstr() . $this->GetLogLevelString($loglevel, $loglevel >= LOGLEVEL_INFO) . ' ' . $this->GetUser();
        if ($loglevel >= LOGLEVEL_DEVICEID) {
            $log .= $this->GetDevid();
        }
        $log .= ' ' . $message;
        return $log;
    }

    //
    // Implementation of Log
    //

    protected function Write($loglevel, $message) {
        $data = $this->buildLogString($loglevel, $message) . PHP_EOL;
        @file_put_contents(LOGFILE, $data, FILE_APPEND);

        if (($loglevel & LOGLEVEL_FATAL) || ($loglevel & LOGLEVEL_ERROR)) {
            @file_put_contents(LOGERRORFILE, $data, FILE_APPEND);
        }
    }

    public function WriteForUser($loglevel, $message) {
        $data = $this->buildLogString($loglevel, $message) . PHP_EOL;
        @file_put_contents(LOGFILEDIR . $this->getLogToUserFile(), $data, FILE_APPEND);
    }

    protected function afterLog($loglevel, $message) {
        if (($loglevel & LOGLEVEL_FATAL) || ($loglevel & LOGLEVEL_ERROR)) {
            $data = $this->buildLogString($loglevel, $message) . PHP_EOL;
            @file_put_contents(LOGERRORFILE, $data, FILE_APPEND);
        }
    }
}
