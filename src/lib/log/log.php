<?php
abstract class Log{

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
     * @param string$value
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

    public function __construct() {}

    /**
     * @param int $loglevel
     * @param string $message
     */
    public function Log($loglevel, $message) {
        if ($loglevel <= LOGLEVEL) {
            $this->Write($loglevel, $message);
        }
        if ($loglevel <= LOGUSERLEVEL && $this->HasSpecialLogUsers()) {
            if(RequestProcessor::isUserAuthenticated() && $this->IsUserInSpecialLogUsers(Request::GetAuthUser())) {
                // something was logged before the user was authenticated, write this to the log
                if (!empty($this->unauthMessageCache)) {
                    foreach ($this->unauthMessageCache as $authcache) {
                        $this->WriteForUser($authcache[0], $authcache[1]);
                    }
                    self::$unAuthCache = array();
                }
                $this->WriteForUser($loglevel, $message);
            }
            else {
                $this->unauthMessageCache[] = [$loglevel, $message];
            }
        }

        $this->afterLog($loglevel, $message);
    }

    /**
     * This function is used as an event for log implementer.
     */
    protected function afterLog($loglevel, $message) {}

    /**
     * Returns the string representation of the given $loglevel.
     * String can be padded
     *
     * @param int             $loglevel                     one of the LOGLEVELs
     * @param boolean     $pad
     *
     * @access protected
     * @return string
     */
    protected function GetLogLevelString($loglevel, $pad = false) {
        if ($pad) $s = " ";
        else            $s = "";
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