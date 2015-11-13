<?php
class FileLog extends Log{

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

    public function __construct(){}

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
        $log = Utils::GetFormattedTime() . $this->GetPidstr() . $this->GetLogLevelString($loglevel, $loglevel >= LOGLEVEL_INFO) .' '. $this->GetUser();
        if ($loglevel >= LOGLEVEL_DEVICEID) {
            $log .= $this->GetDevid();
        }
        $log .= ' ' . $message;
        return $log;
    }

    //
    // Implementation of Log
    //

    protected function Write($loglevel, $message){
        $data = $this->buildLogString($loglevel, $message) . "\n";
        @file_put_contents(LOGFILE, $data, FILE_APPEND);

        if (($loglevel & LOGLEVEL_FATAL) || ($loglevel & LOGLEVEL_ERROR)) {
            @file_put_contents(LOGERRORFILE, $data, FILE_APPEND);
        }
    }

    public function WriteForUser($loglevel, $message){
        $data = $this->buildLogString($loglevel, $message) . "\n";
        @file_put_contents(LOGFILEDIR . $this->getLogToUserFile(), $data, FILE_APPEND);
    }

    protected function afterLog($loglevel, $message){
      if (($loglevel & LOGLEVEL_FATAL) || ($loglevel & LOGLEVEL_ERROR)) {
        $data = $this->buildLogString($loglevel, $message) . "\n";
        @file_put_contents(LOGERRORFILE, $data, FILE_APPEND);
      }
    }
}
