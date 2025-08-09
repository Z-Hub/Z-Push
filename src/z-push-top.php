#!/usr/bin/env php
<?php
/***********************************************
* File      :   z-push-top.php
* Project   :   Z-Push
* Descr     :   Shows realtime information about
*               connected devices and active
*               connections in a top-style format.
*
* Created   :   07.09.2011
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

require_once 'vendor/autoload.php';

/************************************************
 * MAIN
 */
    declare(ticks = 1);
    define('BASE_PATH_CLI',  dirname(__FILE__) ."/");
    set_include_path(get_include_path() . PATH_SEPARATOR . BASE_PATH_CLI);

    if (!defined('ZPUSH_CONFIG')) define('ZPUSH_CONFIG', BASE_PATH_CLI . 'config.php');
    include_once(ZPUSH_CONFIG);

    try {
        ZPush::CheckConfig();
        if (!function_exists("pcntl_signal"))
            throw new FatalException("Function pcntl_signal() is not available. Please install package 'php5-pcntl' (or similar) on your system.");

        if (php_sapi_name() != "cli")
            throw new FatalException("This script can only be called from the CLI.");

        $zpt = new ZPushTop($argv);

        // check if help was requested from CLI
        if (in_array('-h', $argv) || in_array('--help', $argv)) {
            echo $zpt->UsageInstructions();
            exit(0);
        }

        if ($zpt->IsAvailable()) {
            pcntl_signal(SIGINT, array($zpt, "SignalHandler"));
            $zpt->run();
            $zpt->scrClear();
            system("stty sane");
        }
        else
            echo "Z-Push interprocess communication (IPC) is not available or TopCollector is disabled.\n";
    }
    catch (ZPushException $zpe) {
        fwrite(STDERR, get_class($zpe) . ": ". $zpe->getMessage() . "\n");
        exit(1);
    }

    echo "terminated\n";


/************************************************
 * Z-Push-Top
 */
class ZPushTop {
    // show options
    const SHOW_DEFAULT = 0;
    const SHOW_ACTIVE_ONLY = 1;
    const SHOW_UNKNOWN_ONLY = 2;
    const SHOW_TERM_DEFAULT_TIME = 5; // 5 secs
    const SHOW_PID_CHARS = 7; //The PID_MAX_LIMIT can be set to any value up to 2^22 (approximately 4 million)

	// Common ANSI codes:
	// 0 = Reset.
	// 1 = Bold.
	// 4 = Underline.
	// 30 (normal text), 90 (bright text), 40 (normal background), 100 (bright background) = Black
	// 31 (normal text), 91 (bright text), 41 (normal background), 101 (bright background) = Red
	// 32 (normal text), 92 (bright text), 42 (normal background), 102 (bright background) = Green
	// 33 (normal text), 93 (bright text), 43 (normal background), 103 (bright background) = Yellow
	// 34 (normal text), 94 (bright text), 44 (normal background), 104 (bright background) = Blue
	// 35 (normal text), 95 (bright text), 45 (normal background), 105 (bright background) = Magenta
	// 36 (normal text), 96 (bright text), 46 (normal background), 106 (bright background) = Cyan
	// 37 (normal text), 97 (bright text), 47 (normal background), 107 (bright background) = White
	const COLOR_LIGHT = "\033[0m";
	const COLOR_LIGHT_HEADER = "\033[0;4m";
	const COLOR_LIGHT_ACTION = "\033[0;32m";
	const COLOR_LIGHT_FILTER = "\033[0;32m";
	const COLOR_LIGHT_STATUS = "\033[0;31m";
	const COLOR_LIGHT_STATUS_BOLD = "\033[1;31m";
	const COLOR_LIGHT_ACTIVE = "\033[1m";
	const COLOR_LIGHT_OPEN = "\033[0m";
	const COLOR_LIGHT_PUSH = "\033[0;31m";
	const COLOR_LIGHT_UNKNOWN = "\033[1;31m";
	const COLOR_LIGHT_TERMINATED = "\033[1;30m";

	const COLOR_DARK = "\033[0m";
	const COLOR_DARK_HEADER = "\033[0;33;4m";
	const COLOR_DARK_ACTION = "\033[0;32m";
	const COLOR_DARK_FILTER = "\033[0;32m";
	const COLOR_DARK_STATUS = "\033[0;31m";
	const COLOR_DARK_STATUS_BOLD = "\033[1;31m";
	const COLOR_DARK_ACTIVE = "\033[1m";
	const COLOR_DARK_OPEN = "\033[0m";
	const COLOR_DARK_PUSH = "\033[0;31m";
	const COLOR_DARK_UNKNOWN = "\033[1;31m";
	const COLOR_DARK_TERMINATED = "\033[1;36m";

	private $colorMode = 'light';
	private $color = self::COLOR_LIGHT;
	private $colorHeader = self::COLOR_LIGHT_HEADER;
	private $colorAction = self::COLOR_LIGHT_ACTION;
	private $colorFilter = self::COLOR_LIGHT_FILTER;
	private $colorStatus = self::COLOR_LIGHT_STATUS;
	private $colorStatusBold = self::COLOR_LIGHT_STATUS_BOLD;
	private $colorActive = self::COLOR_LIGHT_ACTIVE;
	private $colorOpen = self::COLOR_LIGHT_OPEN;
	private $colorPush = self::COLOR_LIGHT_PUSH;
	private $colorUnknown = self::COLOR_LIGHT_UNKNOWN;
	private $colorTerminated = self::COLOR_LIGHT_TERMINATED;

    private $topCollector;
    private $starttime;
    private $action;
    private $filter;
    private $status;
    private $statusexpire;
    private $wide;
    private $wideDefault;
    private $wasEnabled;
    private $terminate;
    private $scrSize;
    private $pingInterval;
    private $showPush;
    private $showTermSec;

    private $linesActive = array();
    private $linesOpen = array();
    private $linesUnknown = array();
    private $linesTerm = array();
    private $pushConn = 0;
    private $activeConn = array();
    private $activeHosts = array();
    private $activeUsers = array();
    private $activeDevices = array();
    private $activeBackend;

    /**
     * Constructor
     *
     * @access public
     */
    public function __construct($argv) {
		$this->colorMode = 'light';
        $this->starttime = time();
        $this->currenttime = time();
        $this->action = "";
        $this->filter = false;
        $this->status = false;
        $this->statusexpire = 0;
        $this->helpexpire = 0;
        $this->doingTail = false;
        $this->wide = false;
        $this->wideDefault = $this->wide;
        $this->terminate = false;
        $this->showPush = true;
        $this->showOption = self::SHOW_DEFAULT;
        $this->showTermSec = self::SHOW_TERM_DEFAULT_TIME;
        $this->scrSize = array('width' => 80, 'height' => 24);
        $this->pingInterval = (defined('PING_INTERVAL') && PING_INTERVAL > 0) ? PING_INTERVAL : 12;

        // Identify the backend that will be loaded by z-push
        $this->activeBackend = get_class(ZPush::GetBackend());

        // get a TopCollector
        $this->topCollector = new TopCollector();

		// Set-up initial values, based on arguments.
		for ($i = 0; $i < count($argv); $i++) {
			if ($argv[$i] == 'wide') {
				$this->wideDefault = true;
			}
			else if ($argv[$i] == 'dark') {
				$this->colorMode = 'dark';
			}
			else if ($argv[$i] == 'light') {
				$this->colorMode = 'light';
			}
			else if (is_numeric($argv[$i])) {
				$this->showTermSec = $argv[$i];
			}
		}
    }

    /**
     * Requests data from the running Z-Push processes
     *
     * @access private
     * @return
     */
    private function initialize() {
        // request feedback from active processes
        $this->wasEnabled = $this->topCollector->CollectData();

        // remove obsolete data
        $this->topCollector->ClearLatest(true);

        // start with default colours
        $this->scrDefaultColors();
    }

    /**
     * Main loop of Z-Push-top
     * Runs until termination is requested
     *
     * @access public
     * @return
     */
    public function run() {
        $this->initialize();

        do {
            $this->currenttime = time();

            // see if shared memory is active
            if (!$this->IsAvailable())
                $this->terminate = true;

            // active processes should continue sending data
            $this->topCollector->CollectData();

            // get and process data from processes
            $this->topCollector->ClearLatest();
            $topdata = $this->topCollector->ReadLatest();
            $this->processData($topdata);

            // clear screen
            $this->scrClear();

            // check if screen size changed
            $s = $this->scrGetSize();
            if ($this->scrSize['width'] != $s['width']) {
                if ($s['width'] <= 80)
                    $this->wide = false;
                else if ($s['width'] > 180)
                    $this->wide = true;
                else
                    $this->wide = $this->wideDefault;
            }
            $this->scrSize = $s;

            // print overview
            $this->scrOverview();

            // wait for user input
            $this->readLineProcess();
        }
        while($this->terminate != true);
    }

    /**
     * Indicates if TopCollector is available collecting data
     *
     * @access public
     * @return boolean
     */
    public function IsAvailable() {
        if (defined('TOPCOLLECTOR_DISABLED') && constant('TOPCOLLECTOR_DISABLED') === true) {
            return false;
        }
        return $this->topCollector->IsActive();
    }

    /**
     * Processes data written by the running processes
     *
     * @param array $data
     *
     * @access private
     * @return
     */
    private function processData($data) {
        $this->linesActive = array();
        $this->linesOpen = array();
        $this->linesUnknown = array();
        $this->linesTerm = array();
        $this->pushConn = 0;
        $this->activeConn = array();
        $this->activeHosts = array();
        $this->activeUsers = array();
        $this->activeDevices = array();

        if (!is_array($data))
            return;

        foreach ($data as $devid=>$users) {
            foreach ($users as $user=>$pids) {
                foreach ($pids as $pid=>$line) {
                    if (!is_array($line))
                        continue;

                    $line['command'] = Utils::GetCommandFromCode($line['command']);

                    if ($line["ended"] == 0) {
                        $this->activeDevices[$devid] = 1;
                        $this->activeUsers[$user] = 1;
                        $this->activeConn[$pid] = 1;
                        $this->activeHosts[$line['ip']] = 1;

                        $line["time"] = $this->currenttime - $line['start'];
                        if ($line['push'] === true) $this->pushConn += 1;

                        // ignore push connections
                        if ($line['push'] === true && ! $this->showPush)
                            continue;

                        if ($this->filter !== false) {
                            $f = $this->filter;
                            if (!($line["pid"] == $f || $line["ip"] == $f || strtolower($line['command']) == strtolower($f) || preg_match("/.*?$f.*?/i", $line['user']) ||
                                preg_match("/.*?$f.*?/i", $line['devagent']) || preg_match("/.*?$f.*?/i", $line['devid']) || preg_match("/.*?$f.*?/i", $line['addinfo']) ))
                                continue;
                        }

                        $lastUpdate = $this->currenttime - $line["update"];
                        if ($this->currenttime - $line["update"] < 2)
                            $this->linesActive[$line["update"].$line["pid"]] = $line;
                        else if (($line['push'] === true  && $lastUpdate > ($this->pingInterval+2)) || ($line['push'] !== true  && $lastUpdate > 4))
                            $this->linesUnknown[$line["update"].$line["pid"]] = $line;
                        else
                            $this->linesOpen[$line["update"].$line["pid"]] = $line;
                    }
                    else {
                        // do not show terminated + expired connections
                        if ($line['ended'] + $this->showTermSec < $this->currenttime)
                            continue;

                        if ($this->filter !== false) {
                            $f = $this->filter;
                            if (!($line['pid'] == $f || $line['ip'] == $f || strtolower($line['command']) == strtolower($f) || preg_match("/.*?$f.*?/i", $line['user']) ||
                                preg_match("/.*?$f.*?/i", $line['devagent']) || preg_match("/.*?$f.*?/i", $line['devid']) || preg_match("/.*?$f.*?/i", $line['addinfo']) ))
                                continue;
                        }

                        $line['time'] = $line['ended'] - $line['start'];
                        $this->linesTerm[$line['update'].$line['pid']] = $line;
                    }
                }
            }
        }

        // sort by execution time
        krsort($this->linesActive);
        krsort($this->linesOpen);
        krsort($this->linesUnknown);
        krsort($this->linesTerm);
    }

    /**
     * Prints data to the terminal
     *
     * @access private
     * @return
     */
    private function scrOverview() {
        $linesAvail = $this->scrSize['height'] - 8;
        $lc = 1;
        $this->scrPrintAt($lc,0, $this->colorActive . "Z-Push top live statistics" . $this->color . "\t\t\t\t\t". @strftime("%d/%m/%Y %T")."\n"); $lc++;

        $this->scrPrintAt($lc,0, sprintf("Open connections: %d\t\t\t\tUsers:\t %d\tZ-Push:   %s ",count($this->activeConn),count($this->activeUsers), $this->getVersion())); $lc++;
        $this->scrPrintAt($lc,0, sprintf("Push connections: %d\t\t\t\tDevices: %d\tPHP-MAPI: %s", $this->pushConn, count($this->activeDevices), phpversion("mapi"))); $lc++;
        $this->scrPrintAt($lc,0, sprintf("                                                Hosts:\t %d\tBackend:  %s", count($this->activeHosts), $this->activeBackend)); $lc++;
        $lc++;

        $this->scrPrintAt($lc,0, $this->colorHeader . $this->getLine(array('pid'=>'PID', 'ip'=>'IP', 'user'=>'USER', 'command'=>'COMMAND', 'time'=>'TIME', 'devagent'=>'AGENT', 'devid'=>'DEVID', 'addinfo'=>'Additional Information')). str_repeat(" ",20) . $this->color); $lc++;

        // print help text if requested
        $hl = 0;
        if ($this->helpexpire > $this->currenttime) {
            $help = $this->scrHelp();
            $linesAvail -= count($help);
            $hl = $this->scrSize['height'] - count($help) -1;
            foreach ($help as $h) {
                $this->scrPrintAt($hl,0, $h);
                $hl++;
            }
        }

        $toPrintActive = $linesAvail;
        $toPrintOpen = $linesAvail;
        $toPrintUnknown = $linesAvail;
        $toPrintTerm = $linesAvail;

        // default view: show all unknown, no terminated and half active+open
        if (count($this->linesActive) + count($this->linesOpen) + count($this->linesUnknown) > $linesAvail) {
            $toPrintUnknown = count($this->linesUnknown);
            $toPrintActive = count($this->linesActive);
            $toPrintOpen = $linesAvail-$toPrintUnknown-$toPrintActive;
            $toPrintTerm = 0;
        }

        if ($this->showOption == self::SHOW_ACTIVE_ONLY) {
            $toPrintActive = $linesAvail;
            $toPrintOpen = 0;
            $toPrintUnknown = 0;
            $toPrintTerm = 0;
        }

        if ($this->showOption == self::SHOW_UNKNOWN_ONLY) {
            $toPrintActive = 0;
            $toPrintOpen = 0;
            $toPrintUnknown = $linesAvail;
            $toPrintTerm = 0;
        }

        $linesprinted = 0;
        foreach ($this->linesActive as $time=>$l) {
            if ($linesprinted >= $toPrintActive)
                break;

            $this->scrPrintAt($lc,0, $this->colorActive . $this->getLine($l) . $this->color);
            $lc++;
            $linesprinted++;
        }

        $linesprinted = 0;
        foreach ($this->linesOpen as $time=>$l) {
            if ($linesprinted >= $toPrintOpen)
                break;

            $this->scrPrintAt($lc,0, $this->colorOpen . $this->getLine($l) . $this->color);
            $lc++;
            $linesprinted++;
        }

        $linesprinted = 0;
        foreach ($this->linesUnknown as $time=>$l) {
            if ($linesprinted >= $toPrintUnknown)
                break;

            $color = $this->colorUnknown;
            if ($l['push'] == false && $time - $l["start"] > 30)
                $color = $this->colorPush;
            $this->scrPrintAt($lc,0, $color . $this->getLine($l) . $this->color);
            $lc++;
            $linesprinted++;
        }

        if ($toPrintTerm > 0)
            $toPrintTerm = $linesAvail - $lc +6;

        $linesprinted = 0;
        foreach ($this->linesTerm as $time=>$l){
            if ($linesprinted >= $toPrintTerm)
                break;

            $this->scrPrintAt($lc,0, $this->colorTerminated . $this->getLine($l) . $this->color);
            $lc++;
            $linesprinted++;
        }

        // add the lines used when displaying the help text
        $lc += $hl;
        $this->scrPrintAt($lc,0, "\033[K"); $lc++;
        $this->scrPrintAt($lc,0, "Colorscheme: " . $this->colorActive . "Active  " . $this->color . "Open  " . $this->colorUnknown . "Unknown  " . $this->colorTerminated . "Terminated" . $this->color);

        // remove old status
        if ($this->statusexpire < $this->currenttime)
            $this->status = false;

        // show request information and help command
        if ($this->starttime + 6 > $this->currenttime) {
            $this->status = $this->colorStatus . sprintf("Requesting information (takes up to %dsecs)", $this->pingInterval) . str_repeat(".", ($this->currenttime-$this->starttime)) . "  type " . $this->colorStatusBold . "h" . $this->colorStatus . " or " . $this->colorStatusBold . "help" . $this->colorStatus . " for usage instructions";
            $this->statusexpire = $this->currenttime+1;
        }

        $str = "";
        if (! $this->showPush)
            $str .= $this->colorFilter . "Push: " . $this->colorAction . "No" . $this->color . "   ";

        if ($this->showOption == self::SHOW_ACTIVE_ONLY)
            $str .= $this->colorAction . "Active only" . $this->color . "   ";

        if ($this->showOption == self::SHOW_UNKNOWN_ONLY)
            $str .= $this->colorAction . "Unknown only" . $this->color . "   ";

        if ($this->showTermSec != self::SHOW_TERM_DEFAULT_TIME)
            $str .= $this->colorAction . "Terminated: ". $this->showTermSec. "s" . $this->color . "   ";

        if ($this->filter !== false || ($this->status !== false && $this->statusexpire > $this->currenttime)) {
            // print filter in green
            if ($this->filter !== false)
                $str .= $this->colorFilter . "Filter: " . $this->colorAction . "$this->filter" . $this->color . "   ";
            // print status in red
            if ($this->status !== false)
                $str .= $this->colorStatus . "$this->status" . $this->color;
        }
        $this->scrPrintAt(5,0, $str);

        $this->scrPrintAt(4,0,"Action: " . $this->colorActive . $this->action . $this->color);
    }

    /**
     * Waits for a keystroke and processes the requested command
     *
     * @access private
     * @return
     */
    private function readLineProcess() {
        $ans = explode("^^", `bash -c "read -n 1 -t 1 ANS ; echo \\\$?^^\\\$ANS;"`);

        if ($ans[0] < 128) {
            if (isset($ans[1]) && bin2hex(trim($ans[1])) == "7f") {
                $this->action = substr($this->action,0,-1);
            }

            if (isset($ans[1]) && $ans[1] != "" ){
                $this->action .= trim(preg_replace("/[^A-Za-z0-9:]/","",$ans[1]));
            }

            if (bin2hex($ans[0]) == "30" && bin2hex($ans[1]) == "0a")  {
                $cmds = explode(':', $this->action);
                if ($cmds[0] == "quit" || $cmds[0] == "q" || (isset($cmds[1]) && $cmds[0] == "" && $cmds[1] == "q")) {
                    $this->topCollector->CollectData(true);
                    $this->topCollector->ClearLatest(true);

                    $this->terminate = true;
                }
                else if ($cmds[0] == "clear" ) {
                    $this->topCollector->ClearLatest(true);
                    $this->topCollector->CollectData(true);
                    $this->topCollector->ReInitIPC();
                }
                else if ($cmds[0] == "filter" || $cmds[0] == "f") {
                    if (!isset($cmds[1]) || $cmds[1] == "") {
                        $this->filter = false;
                        $this->status = "No filter";
                        $this->statusexpire = $this->currenttime+5;
                    }
                    else {
                        $this->filter = $cmds[1];
                        $this->status = false;
                    }
                }
                else if ($cmds[0] == "color" || $cmds[0] == "c") {
                	if ($this->colorMode == "dark") {
						$this->colorMode = 'light';
				        $this->scrDefaultColors();
                	}
                	else {
						$this->colorMode = 'dark';
				        $this->scrDefaultColors();
                	}
               	}
                else if ($cmds[0] == "option" || $cmds[0] == "o") {
                    if (!isset($cmds[1]) || $cmds[1] == "") {
                        $this->status = "Option value needs to be specified. See 'help' or 'h' for instructions";
                        $this->statusexpire = $this->currenttime+5;
                    }
                    else if ($cmds[1] == "p" || $cmds[1] == "push" || $cmds[1] == "ping")
                        $this->showPush = !$this->showPush;
                    else if ($cmds[1] == "a" || $cmds[1] == "active")
                        $this->showOption = self::SHOW_ACTIVE_ONLY;
                    else if ($cmds[1] == "u" || $cmds[1] == "unknown")
                        $this->showOption = self::SHOW_UNKNOWN_ONLY;
                    else if ($cmds[1] == "d" || $cmds[1] == "default") {
                        $this->showOption = self::SHOW_DEFAULT;
                        $this->showTermSec = self::SHOW_TERM_DEFAULT_TIME;
                        $this->showPush = true;
                    }
                    else if (is_numeric($cmds[1]))
                        $this->showTermSec = $cmds[1];
                    else {
                        $this->status = sprintf("Option '%s' unknown", $cmds[1]);
                        $this->statusexpire = $this->currenttime+5;
                    }
                }
                else if ($cmds[0] == "reset" || $cmds[0] == "r") {
                    $this->filter = false;
                    $this->wide = false;
                    $this->wideDefault = $this->wide;
                    $this->helpexpire = 0;
                    $this->status = "resetted";
                    $this->statusexpire = $this->currenttime+2;
                }
                // enable/disable wide view
                else if ($cmds[0] == "wide" || $cmds[0] == "w") {
                    $this->wide = ! $this->wide;
                    $this->wideDefault = $this->wide;
                    $this->status = ($this->wide)?"w i d e  view" : "normal view";
                    $this->statusexpire = $this->currenttime+2;
                }
                else if ($cmds[0] == "help" || $cmds[0] == "h") {
                    $this->helpexpire = $this->currenttime+20;
                }
                // grep the log file
                else if (($cmds[0] == "log" || $cmds[0] == "l") && isset($cmds[1]) ) {
                    if (!file_exists(LOGFILE)) {
                        $this->status = "Logfile can not be found: ". LOGFILE;
                    }
                    else {
                        system('bash -c "fgrep -a '.escapeshellarg($cmds[1]).' '. LOGFILE .' | less +G" > `tty`');
                        $this->status = "Returning from log, updating data";
                    }
                    $this->statusexpire = time()+5; // it might be much "later" now
                }
                // tail the log file
                else if (($cmds[0] == "tail" || $cmds[0] == "t")) {
                    if (!file_exists(LOGFILE)) {
                        $this->status = "Logfile can not be found: ". LOGFILE;
                    }
                    else {
                        $this->doingTail = true;
                        $this->scrClear();
                        $this->scrPrintAt(1,0,$this->scrAsBold("Press CTRL+C to return to Z-Push-Top\n\n"));
                        $secondary = "";
                        if (isset($cmds[1])) $secondary =  " -n 200 | grep ".escapeshellarg($cmds[1]);
                        system('bash -c "tail -f '. LOGFILE . $secondary . '" > `tty`');
                        $this->doingTail = false;
                        $this->status = "Returning from tail, updating data";
                    }
                    $this->statusexpire = time()+5; // it might be much "later" now
                }
                // tail the error log file
                else if (($cmds[0] == "error" || $cmds[0] == "e")) {
                    if (!file_exists(LOGERRORFILE)) {
                        $this->status = "Error logfile can not be found: ". LOGERRORFILE;
                    }
                    else {
                        $this->doingTail = true;
                        $this->scrClear();
                        $this->scrPrintAt(1,0,$this->scrAsBold("Press CTRL+C to return to Z-Push-Top\n\n"));
                        $secondary = "";
                        if (isset($cmds[1])) $secondary =  " -n 200 | grep ".escapeshellarg($cmds[1]);
                        system('bash -c "tail -f '. LOGERRORFILE . $secondary . '" > `tty`');
                        $this->doingTail = false;
                        $this->status = "Returning from tail, updating data";
                    }
                    $this->statusexpire = time()+5; // it might be much "later" now
                }

                else if ($cmds[0] != "") {
                    $this->status = sprintf("Command '%s' unknown", $cmds[0]);
                    $this->statusexpire = $this->currenttime+8;
                }
                $this->action = "";
            }
        }
    }

    /**
     * Signal handler function
     *
     * @param int   $signo      signal number
     *
     * @access public
     * @return
     */
    public function SignalHandler($signo) {
        // don't terminate if the signal was sent by terminating tail
        if (!$this->doingTail) {
            $this->topCollector->CollectData(true);
            $this->topCollector->ClearLatest(true);
            $this->terminate = true;
        }
    }

    /**
     * Returns usage instructions
     *
     * @return string
     * @access public
     */
    public function UsageInstructions() {
        $help = "  Usage:\t\tz-push-top.php  [[wide|dark|light|10]..]\n\n".
                "  Z-Push-Top is a live top-like overview of what Z-Push is doing.\n".
                "  The following arguments can be used to initialise Z-Push-Top in a specific way.\n\n".
				"  " .$this->scrAsBold("wide") ."\t\t\tTries not to truncate data. Automatically done if more than 180 columns available.\n".
				"  " .$this->scrAsBold("dark") ."\t\t\tUse dark colour mode.\n".
				"  " .$this->scrAsBold("light") ."\t\t\tUse light colour mode. Default.\n".
				"  " .$this->scrAsBold("10") ."\t\t\tLists terminated connections for 10 seconds. Any other number can be used.\n\n".
                "  When Z-Push-Top is running you can specify certain actions and options which can be executed (listed below).\n".
                "  This help information can also be shown inside Z-Push-Top by hitting 'help' or 'h'.\n\n";
        $scrhelp = $this->scrHelp();
        unset($scrhelp[0]);

        $help .= implode("\n", $scrhelp);
        $help .= "\n\n";
        return $help;
    }


    /**
     * Prints a 'help' text at the end of the page
     *
     * @access private
     * @return array        with help lines
     */
    private function scrHelp() {
        $h = array();
        $secs = $this->helpexpire - $this->currenttime;
        $h[] = "Actions supported by Z-Push-Top (help page still displayed for ".$secs."secs)";
        $h[] = "  ".$this->scrAsBold("Action")."\t\t".$this->scrAsBold("Comment");
        $h[] = "  ".$this->scrAsBold("h")." or ".$this->scrAsBold("help")."\t\tDisplays this information.";
        $h[] = "  ".$this->scrAsBold("q").", ".$this->scrAsBold("quit")." or ".$this->scrAsBold(":q")."\t\tExits Z-Push-Top.";
        $h[] = "  ".$this->scrAsBold("w")." or ".$this->scrAsBold("wide")."\t\tTries not to truncate data. Automatically done if more than 180 columns available.";
        $h[] = "  ".$this->scrAsBold("f:VAL")." or ".$this->scrAsBold("filter:VAL")."\tOnly display connections which contain VAL. This value is case-insensitive.";
        $h[] = "  ".$this->scrAsBold("f:")." or ".$this->scrAsBold("filter:")."\t\tWithout a search word: resets the filter.";
        $h[] = "  ".$this->scrAsBold("l:STR")." or ".$this->scrAsBold("log:STR")."\tIssues 'less +G' on the logfile, after grepping on the optional STR.";
        $h[] = "  ".$this->scrAsBold("t:STR")." or ".$this->scrAsBold("tail:STR")."\tIssues 'tail -f' on the logfile, grepping for optional STR.";
        $h[] = "  ".$this->scrAsBold("e:STR")." or ".$this->scrAsBold("error:STR")."\tIssues 'tail -f' on the error logfile, grepping for optional STR.";
        $h[] = "  ".$this->scrAsBold("c")." or ".$this->scrAsBold("color")."\t\tToggle between light and dark colour mode.";
        $h[] = "  ".$this->scrAsBold("r")." or ".$this->scrAsBold("reset")."\t\tResets 'wide' or 'filter'.";
        $h[] = "  ".$this->scrAsBold("o:")." or ".$this->scrAsBold("option:")."\t\tSets display options. Valid options specified below";
        $h[] = "  ".$this->scrAsBold("  p")." or ".$this->scrAsBold("push")."\t\tLists/not lists active and open push connections.";
        $h[] = "  ".$this->scrAsBold("  a")." or ".$this->scrAsBold("action")."\t\tLists only active connections.";
        $h[] = "  ".$this->scrAsBold("  u")." or ".$this->scrAsBold("unknown")."\tLists only unknown connections.";
        $h[] = "  ".$this->scrAsBold("  10")." or ".$this->scrAsBold("20")."\t\tLists terminated connections for 10 or 20 seconds. Any other number can be used.";
        $h[] = "  ".$this->scrAsBold("  d")." or ".$this->scrAsBold("default")."\tUses default options";

        return $h;
    }

    /**
     * Encapsulates string with different color escape characters
     *
     * @param string        $text
     *
     * @access private
     * @return string       same text as bold
     */
    private function scrAsBold($text) {
        return $this->colorActive . $text . $this->color;
    }

    /**
     * Prints one line of precessed data
     *
     * @param array     $l      line information
     *
     * @access private
     * @return string
     */
    private function getLine($l) {
        if ($this->wide === true)
            return sprintf("%s%s%s%s%s%s%s%s", $this->ptStr($l['pid'],self::SHOW_PID_CHARS+1), $this->ptStr($l['ip'],16), $this->ptStr($l['user'],24), $this->ptStr($l['command'],16), $this->ptStr($this->sec2min($l['time']),8), $this->ptStr($l['devagent'],28), $this->ptStr($l['devid'],33, true), $l['addinfo']);
        else
            return sprintf("%s%s%s%s%s%s%s%s", $this->ptStr($l['pid'],self::SHOW_PID_CHARS+1), $this->ptStr($l['ip'],16), $this->ptStr($l['user'],8), $this->ptStr($l['command'],8), $this->ptStr($this->sec2min($l['time']),6), $this->ptStr($l['devagent'],20), $this->ptStr($l['devid'],12, true), $l['addinfo']);
     }

    /**
     * Pads and trims string
     *
     * @param string    $str        to be trimmed/padded
     * @param int       $size       characters to be considered
     * @param boolean   $cutmiddle  (optional) indicates where to long information should
     *                              be trimmed of, false means at the end
     *
     * @access private
     * @return string
     */
    private function ptStr($str, $size, $cutmiddle = false) {
        if (strlen($str) < $size)
            return str_pad($str, $size);
        else if ($cutmiddle == true) {
            $cut = ($size-2)/2;
            return $this->ptStr(substr($str,0,$cut) ."..". substr($str,(-1)*($cut-1)), $size);
        }
        else {
            return substr($str,0,$size-3).".. ";
        }
    }

    /**
     * Tries to discover the size of the current terminal
     *
     * @access private
     * @return array        'width' and 'height' as keys
     */
    private function scrGetSize() {
        $tty = strtolower(exec('stty -a | fgrep columns'));
        if (preg_match_all("/rows.([0-9]+);.columns.([0-9]+);/", $tty, $output) ||
            preg_match_all("/([0-9]+).rows;.([0-9]+).columns;/", $tty, $output))
            return array('width' => $output[2][0], 'height' => $output[1][0]);

        return array('width' => 80, 'height' => 24);
    }

    /**
     * Returns the version of the current Z-Push installation
     *
     * @access private
     * @return string
     */
    private function getVersion() {
        if (ZPUSH_VERSION == "SVN checkout" && file_exists(REAL_BASE_PATH.".svn/entries")) {
            $svn = file(REAL_BASE_PATH.".svn/entries");
            return "SVN " . substr(trim($svn[4]),stripos($svn[4],"z-push")+7) ." r".trim($svn[3]);
        }
        return ZPUSH_VERSION;
    }

    /**
     * Converts seconds in MM:SS
     *
     * @param int   $s      seconds
     *
     * @access private
     * @return string
     */
    private function sec2min($s) {
        if (!is_int($s))
            return $s;
        return sprintf("%02.2d:%02.2d", floor($s/60), $s%60);
    }

    /**
     * Resets the default colors of the terminal
     *
     * @access private
     * @return
     */
    private function scrDefaultColors() {
    	// Set the colours.
		if ($this->colorMode == "dark") {
			$this->color = self::COLOR_DARK;
			$this->colorHeader = self::COLOR_DARK_HEADER;
			$this->colorAction = self::COLOR_DARK_ACTION;
			$this->colorFilter = self::COLOR_DARK_FILTER;
			$this->colorStatus = self::COLOR_DARK_STATUS;
			$this->colorStatusBold = self::COLOR_DARK_STATUS_BOLD;
			$this->colorActive = self::COLOR_DARK_ACTIVE;
			$this->colorOpen = self::COLOR_DARK_OPEN;
			$this->colorPush = self::COLOR_DARK_PUSH;
			$this->colorUnknown = self::COLOR_DARK_UNKNOWN;
			$this->colorTerminated = self::COLOR_DARK_TERMINATED;
		}
		else {
			$this->color = self::COLOR_LIGHT;
			$this->colorHeader = self::COLOR_LIGHT_HEADER;
			$this->colorAction = self::COLOR_LIGHT_ACTION;
			$this->colorFilter = self::COLOR_LIGHT_FILTER;
			$this->colorStatus = self::COLOR_LIGHT_STATUS;
			$this->colorStatusBold = self::COLOR_LIGHT_STATUS_BOLD;
			$this->colorActive = self::COLOR_LIGHT_ACTIVE;
			$this->colorOpen = self::COLOR_LIGHT_OPEN;
			$this->colorPush = self::COLOR_LIGHT_PUSH;
			$this->colorUnknown = self::COLOR_LIGHT_UNKNOWN;
			$this->colorTerminated = self::COLOR_LIGHT_TERMINATED;
		}

		// Set the default colour.
        echo $this->color;
    }

    /**
     * Clears screen of the terminal
     *
     * @param array $data
     *
     * @access private
     * @return
     */
    public function scrClear() {
        echo "\033[2J";
    }

    /**
     * Prints a text at a specific screen/terminal coordinates
     *
     * @param int       $row        row number
     * @param int       $col        column number
     * @param string    $text       to be printed
     *
     * @access private
     * @return
     */
    private function scrPrintAt($row, $col, $text="") {
        echo "\033[".$row.";".$col."H".$text;
    }

}
