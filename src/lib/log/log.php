<?php
/***********************************************
 * File      :   log.php
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
abstract class Log {

    /**
     * @var string
     */
    protected $user = '';
    /**
     * @var string
     */
    protected $devid = '';

    /**
     * @var string
     */
    protected $pidstr = '';

    /**
     * @var array
     */
    protected $specialLogUsers = array();

    /**
     * @var array
     */
    private $unauthMessageCache = array();

    /**
     * @access public
     * @return string
     */
    public function GetUser() {
        return $this->user;
    }

    /**
     * @param string $value
     *
     * @access public
     */
    public function SetUser($value) {
        $this->user = $value;
    }

    /**
     * @access public
     * @return string
     */
    public function GetDevid() {
        return $this->devid;
    }

    /**
     * @param string $value
     *
     * @access public
     */
    public function SetDevid($value) {
        $this->devid = $value;
    }

    /**
     * @access public
     * @return string
     */
    public function GetPidstr() {
        return $this->pidstr;
    }

    /**
     * @param string $value
     *
     * @access public
     */
    public function SetPidstr($value) {
        $this->pidstr = $value;
    }

    /**
     * @access public
     * @return bool True if we do have to log some specific user. False otherwise.
     */
    public function HasSpecialLogUsers() {
        return !empty($this->specialLogUsers);
    }

    /**
     * @param string $user
     *
     * @acces public
     * @return bool
     */
    public function IsUserInSpecialLogUsers($user) {
        if ($this->HasSpecialLogUsers()) {
            return in_array($user, $this->GetSpecialLogUsers());
        }
        return false;
    }

    /**
     * @access public
     * @return array
     */
    public function GetSpecialLogUsers() {
        return $this->specialLogUsers;
    }

    /**
     * @param array $value
     *
     * @access public
     */
    public function SetSpecialLogUsers(array $value) {
        $this->specialLogUsers = $value;
    }

    public function __construct() {
    }

    /**
     * @param int $loglevel
     * @param string $message
     */
    public function Log($loglevel, $message) {
        if ($loglevel <= LOGLEVEL) {
            $this->Write($loglevel, $message);
        }
        if ($loglevel <= LOGUSERLEVEL && $this->HasSpecialLogUsers()) {
            if (RequestProcessor::isUserAuthenticated() && $this->IsUserInSpecialLogUsers(Request::GetAuthUser())) {
                // something was logged before the user was authenticated, write this to the log
                if (!empty($this->unauthMessageCache)) {
                    foreach ($this->unauthMessageCache as $authcache) {
                        $this->WriteForUser($authcache[0], $authcache[1]);
                    }
                    self::$unAuthCache = array();
                }
                $this->WriteForUser($loglevel, $message);
            } else {
                $this->unauthMessageCache[] = array($loglevel, $message);
            }
        }

        $this->afterLog($loglevel, $message);
    }

    /**
     * This function is used as an event for log implementer.
     */
    protected function afterLog($loglevel, $message) {
    }

    /**
     * Returns the string representation of the given $loglevel.
     * String can be padded
     *
     * @param int $loglevel one of the LOGLEVELs
     * @param boolean $pad
     *
     * @access protected
     * @return string
     */
    protected function GetLogLevelString($loglevel, $pad = false) {
        if ($pad)
            $s = " ";
        else
            $s = "";
        switch($loglevel) {
            case LOGLEVEL_OFF:     return ""; break;
            case LOGLEVEL_FATAL: return "[FATAL]"; break;
            case LOGLEVEL_ERROR: return "[ERROR]"; break;
            case LOGLEVEL_WARN:    return "[".$s."WARN]"; break;
            case LOGLEVEL_INFO:    return "[".$s."INFO]"; break;
            case LOGLEVEL_DEBUG: return "[DEBUG]"; break;
            case LOGLEVEL_WBXML: return "[WBXML]"; break;
            case LOGLEVEL_DEVICEID: return "[DEVICEID]"; break;
            case LOGLEVEL_WBXMLSTACK: return "[WBXMLSTACK]"; break;
        }
    }

    /**
     * @param int $loglevel
     * @param string $message
     *
     * @access public
     * @return null
     */
    abstract protected function Write($loglevel, $message);

    /**
     * @param int $loglevel
     * @param string $message
     *
     * @access public
     * @return null
     */
    abstract public function WriteForUser($loglevel, $message);
}