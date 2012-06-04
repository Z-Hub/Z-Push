<?php
/***********************************************
* File      :   zpush.php
* Project   :   Z-Push
* Descr     :   Core functionalities
*
* Created   :   12.04.2011
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


class ZPush {
    const UNAUTHENTICATED = 1;
    const UNPROVISIONED = 2;
    const NOACTIVESYNCCOMMAND = 3;
    const WEBSERVICECOMMAND = 4;
    const HIERARCHYCOMMAND = 5;
    const PLAININPUT = 6;
    const REQUESTHANDLER = 7;
    const CLASS_NAME = 1;
    const CLASS_REQUIRESPROTOCOLVERSION = 2;
    const CLASS_DEFAULTTYPE = 3;
    const CLASS_OTHERTYPES = 4;

    // AS versions
    const ASV_1 = "1.0";
    const ASV_2 = "2.0";
    const ASV_21 = "2.1";
    const ASV_25 = "2.5";
    const ASV_12 = "12.0";
    const ASV_121 = "12.1";
    const ASV_14 = "14.0";

    /**
     * Command codes for base64 encoded requests (AS >= 12.1)
     */
    const COMMAND_SYNC = 0;
    const COMMAND_SENDMAIL = 1;
    const COMMAND_SMARTFORWARD = 2;
    const COMMAND_SMARTREPLY = 3;
    const COMMAND_GETATTACHMENT = 4;
    const COMMAND_FOLDERSYNC = 9;
    const COMMAND_FOLDERCREATE = 10;
    const COMMAND_FOLDERDELETE = 11;
    const COMMAND_FOLDERUPDATE = 12;
    const COMMAND_MOVEITEMS = 13;
    const COMMAND_GETITEMESTIMATE = 14;
    const COMMAND_MEETINGRESPONSE = 15;
    const COMMAND_SEARCH = 16;
    const COMMAND_SETTINGS = 17;
    const COMMAND_PING = 18;
    const COMMAND_ITEMOPERATIONS = 19;
    const COMMAND_PROVISION = 20;
    const COMMAND_RESOLVERECIPIENTS = 21;
    const COMMAND_VALIDATECERT = 22;

    // Deprecated commands
    const COMMAND_GETHIERARCHY = -1;
    const COMMAND_CREATECOLLECTION = -2;
    const COMMAND_DELETECOLLECTION = -3;
    const COMMAND_MOVECOLLECTION = -4;
    const COMMAND_NOTIFY = -5;

    // Webservice commands
    const COMMAND_WEBSERVICE_DEVICE = -100;

    static private $supportedASVersions = array(
                    self::ASV_1,
                    self::ASV_2,
                    self::ASV_21,
                    self::ASV_25,
                    self::ASV_12,
                    self::ASV_121,
                    self::ASV_14
                );

    static private $supportedCommands = array(
                    // COMMAND                             // AS VERSION   // REQUESTHANDLER                        // OTHER SETTINGS
                    self::COMMAND_SYNC              => array(self::ASV_1,  self::REQUESTHANDLER => "Sync"),
                    self::COMMAND_SENDMAIL          => array(self::ASV_1,  self::REQUESTHANDLER => "SendMail"),
                    self::COMMAND_SMARTFORWARD      => array(self::ASV_1,  self::REQUESTHANDLER => "SendMail"),
                    self::COMMAND_SMARTREPLY        => array(self::ASV_1,  self::REQUESTHANDLER => "SendMail"),
                    self::COMMAND_GETATTACHMENT     => array(self::ASV_1,  self::REQUESTHANDLER => "GetAttachment"),
                    self::COMMAND_GETHIERARCHY      => array(self::ASV_1,  self::REQUESTHANDLER => "GetHierarchy",  self::HIERARCHYCOMMAND),            // deprecated but implemented
                    self::COMMAND_CREATECOLLECTION  => array(self::ASV_1),                                                                              // deprecated & not implemented
                    self::COMMAND_DELETECOLLECTION  => array(self::ASV_1),                                                                              // deprecated & not implemented
                    self::COMMAND_MOVECOLLECTION    => array(self::ASV_1),                                                                              // deprecated & not implemented
                    self::COMMAND_FOLDERSYNC        => array(self::ASV_2,  self::REQUESTHANDLER => "FolderSync",    self::HIERARCHYCOMMAND),
                    self::COMMAND_FOLDERCREATE      => array(self::ASV_2,  self::REQUESTHANDLER => "FolderChange",  self::HIERARCHYCOMMAND),
                    self::COMMAND_FOLDERDELETE      => array(self::ASV_2,  self::REQUESTHANDLER => "FolderChange",  self::HIERARCHYCOMMAND),
                    self::COMMAND_FOLDERUPDATE      => array(self::ASV_2,  self::REQUESTHANDLER => "FolderChange",  self::HIERARCHYCOMMAND),
                    self::COMMAND_MOVEITEMS         => array(self::ASV_1,  self::REQUESTHANDLER => "MoveItems"),
                    self::COMMAND_GETITEMESTIMATE   => array(self::ASV_1,  self::REQUESTHANDLER => "GetItemEstimate"),
                    self::COMMAND_MEETINGRESPONSE   => array(self::ASV_1,  self::REQUESTHANDLER => "MeetingResponse"),
                    self::COMMAND_RESOLVERECIPIENTS => array(self::ASV_1,  self::REQUESTHANDLER => false),
                    self::COMMAND_VALIDATECERT      => array(self::ASV_1,  self::REQUESTHANDLER => false),
                    self::COMMAND_PROVISION         => array(self::ASV_25, self::REQUESTHANDLER => "Provisioning",  self::UNAUTHENTICATED, self::UNPROVISIONED),
                    self::COMMAND_SEARCH            => array(self::ASV_1,  self::REQUESTHANDLER => "Search"),
                    self::COMMAND_PING              => array(self::ASV_2,  self::REQUESTHANDLER => "Ping",          self::UNPROVISIONED),
                    self::COMMAND_NOTIFY            => array(self::ASV_1,  self::REQUESTHANDLER => "Notify"),                                           // deprecated & not implemented
                    self::COMMAND_ITEMOPERATIONS    => array(self::ASV_12, self::REQUESTHANDLER => "ItemOperations"),
                    self::COMMAND_SETTINGS          => array(self::ASV_12, self::REQUESTHANDLER => "Settings"),

                    self::COMMAND_WEBSERVICE_DEVICE => array(self::REQUESTHANDLER => "Webservice", self::PLAININPUT, self::NOACTIVESYNCCOMMAND, self::WEBSERVICECOMMAND),
                );



    static private $classes = array(
                    "Email"     => array(
                                        self::CLASS_NAME => "SyncMail",
                                        self::CLASS_REQUIRESPROTOCOLVERSION => false,
                                        self::CLASS_DEFAULTTYPE => SYNC_FOLDER_TYPE_INBOX,
                                        self::CLASS_OTHERTYPES => array(SYNC_FOLDER_TYPE_OTHER, SYNC_FOLDER_TYPE_DRAFTS, SYNC_FOLDER_TYPE_WASTEBASKET,
                                                                        SYNC_FOLDER_TYPE_SENTMAIL, SYNC_FOLDER_TYPE_OUTBOX, SYNC_FOLDER_TYPE_USER_MAIL,
                                                                        SYNC_FOLDER_TYPE_JOURNAL, SYNC_FOLDER_TYPE_USER_JOURNAL),
                                   ),
                    "Contacts"  => array(
                                        self::CLASS_NAME => "SyncContact",
                                        self::CLASS_REQUIRESPROTOCOLVERSION => true,
                                        self::CLASS_DEFAULTTYPE => SYNC_FOLDER_TYPE_CONTACT,
                                        self::CLASS_OTHERTYPES => array(SYNC_FOLDER_TYPE_USER_CONTACT),
                                   ),
                    "Calendar"  => array(
                                        self::CLASS_NAME => "SyncAppointment",
                                        self::CLASS_REQUIRESPROTOCOLVERSION => false,
                                        self::CLASS_DEFAULTTYPE => SYNC_FOLDER_TYPE_APPOINTMENT,
                                        self::CLASS_OTHERTYPES => array(SYNC_FOLDER_TYPE_USER_APPOINTMENT),
                                   ),
                    "Tasks"     => array(
                                        self::CLASS_NAME => "SyncTask",
                                        self::CLASS_REQUIRESPROTOCOLVERSION => false,
                                        self::CLASS_DEFAULTTYPE => SYNC_FOLDER_TYPE_TASK,
                                        self::CLASS_OTHERTYPES => array(SYNC_FOLDER_TYPE_USER_TASK),
                                   ),
                    "Notes" => array(
                                        self::CLASS_NAME => "SyncNote",
                                        self::CLASS_REQUIRESPROTOCOLVERSION => false,
                                        self::CLASS_DEFAULTTYPE => SYNC_FOLDER_TYPE_NOTE,
                                        self::CLASS_OTHERTYPES => array(SYNC_FOLDER_TYPE_USER_NOTE),
                                   ),
                );


    static private $stateMachine;
    static private $searchProvider;
    static private $deviceManager;
    static private $topCollector;
    static private $backend;
    static private $addSyncFolders;


    /**
     * Verifies configuration
     *
     * @access public
     * @return boolean
     * @throws FatalMisconfigurationException
     */
    static public function CheckConfig() {
        // check the php version
        if (version_compare(phpversion(),'5.1.0') < 0)
            throw new FatalException("The configured PHP version is too old. Please make sure at least PHP 5.1 is used.");

        // some basic checks
        if (!defined('BASE_PATH'))
            throw new FatalMisconfigurationException("The BASE_PATH is not configured. Check if the config.php file is in place.");

        if (substr(BASE_PATH, -1,1) != "/")
            throw new FatalMisconfigurationException("The BASE_PATH should terminate with a '/'");

        if (!file_exists(BASE_PATH))
            throw new FatalMisconfigurationException("The configured BASE_PATH does not exist or can not be accessed.");

        if (defined('BASE_PATH_CLI') && file_exists(BASE_PATH_CLI))
            define('REAL_BASE_PATH', BASE_PATH_CLI);
        else
            define('REAL_BASE_PATH', BASE_PATH);

        if (!defined('LOGFILEDIR'))
            throw new FatalMisconfigurationException("The LOGFILEDIR is not configured. Check if the config.php file is in place.");

        if (substr(LOGFILEDIR, -1,1) != "/")
            throw new FatalMisconfigurationException("The LOGFILEDIR should terminate with a '/'");

        if (!file_exists(LOGFILEDIR))
            throw new FatalMisconfigurationException("The configured LOGFILEDIR does not exist or can not be accessed.");

        if (!touch(LOGFILE))
            throw new FatalMisconfigurationException("The configured LOGFILE can not be modified.");

        if (!touch(LOGERRORFILE))
            throw new FatalMisconfigurationException("The configured LOGFILE can not be modified.");

        // set time zone
        // code contributed by Robert Scheck (rsc) - more information: https://developer.berlios.de/mantis/view.php?id=479
        if(function_exists("date_default_timezone_set")) {
            if(defined('TIMEZONE') ? constant('TIMEZONE') : false) {
                if (! @date_default_timezone_set(TIMEZONE))
                    throw new FatalMisconfigurationException(sprintf("The configured TIMEZONE '%s' is not valid. Please check supported timezones at http://www.php.net/manual/en/timezones.php", constant('TIMEZONE')));
            }
            else if(!ini_get('date.timezone')) {
                date_default_timezone_set('Europe/Amsterdam');
            }
        }

        return true;
    }

    /**
     * Verifies Timezone, StateMachine and Backend configuration
     *
     * @access public
     * @return boolean
     * @trows FatalMisconfigurationException
     */
    static public function CheckAdvancedConfig() {
        global $specialLogUsers, $additionalFolders;

        if (!is_array($specialLogUsers))
            throw new FatalMisconfigurationException("The WBXML log users is not an array.");

        if (!defined('SINK_FORCERECHECK')) {
            define('SINK_FORCERECHECK', 300);
        }
        else if (SINK_FORCERECHECK !== false && (!is_int(SINK_FORCERECHECK) || SINK_FORCERECHECK < 1))
            throw new FatalMisconfigurationException("The SINK_FORCERECHECK value must be 'false' or a number higher than 0.");

        // the check on additional folders will not throw hard errors, as this is probably changed on live systems
        if (isset($additionalFolders) && !is_array($additionalFolders))
            ZLog::Write(LOGLEVEL_ERROR, "ZPush::CheckConfig() : The additional folders synchronization not available as array.");
        else {
            self::$addSyncFolders = array();

            // process configured data
            foreach ($additionalFolders as $af) {

                if (!is_array($af) || !isset($af['store']) || !isset($af['folderid']) || !isset($af['name']) || !isset($af['type'])) {
                    ZLog::Write(LOGLEVEL_ERROR, "ZPush::CheckConfig() : the additional folder synchronization is not configured correctly. Missing parameters. Entry will be ignored.");
                    continue;
                }

                if ($af['store'] == "" || $af['folderid'] == "" || $af['name'] == "" || $af['type'] == "") {
                    ZLog::Write(LOGLEVEL_WARN, "ZPush::CheckConfig() : the additional folder synchronization is not configured correctly. Empty parameters. Entry will be ignored.");
                    continue;
                }

                if (!in_array($af['type'], array(SYNC_FOLDER_TYPE_USER_CONTACT, SYNC_FOLDER_TYPE_USER_APPOINTMENT, SYNC_FOLDER_TYPE_USER_TASK, SYNC_FOLDER_TYPE_USER_MAIL))) {
                    ZLog::Write(LOGLEVEL_ERROR, sprintf("ZPush::CheckConfig() : the type of the additional synchronization folder '%s is not permitted.", $af['name']));
                    continue;
                }

                $folder = new SyncFolder();
                $folder->serverid = $af['folderid'];
                $folder->parentid = 0;                  // only top folders are supported
                $folder->displayname = $af['name'];
                $folder->type = $af['type'];
                // save store as custom property which is not streamed directly to the device
                $folder->NoBackendFolder = true;
                $folder->Store = $af['store'];
                self::$addSyncFolders[$folder->serverid] = $folder;
            }

        }

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("Used timezone '%s'", date_default_timezone_get()));

        // get the statemachine, which will also try to load the backend.. This could throw errors
        self::GetStateMachine();
    }

    /**
     * Returns the StateMachine object
     * which has to be an IStateMachine implementation
     *
     * @access public
     * @return object   implementation of IStateMachine
     * @throws FatalNotImplementedException
     */
    static public function GetStateMachine() {
        if (!isset(ZPush::$stateMachine)) {
            // the backend could also return an own IStateMachine implementation
            $backendStateMachine = self::GetBackend()->GetStateMachine();

            // if false is returned, use the default StateMachine
            if ($backendStateMachine !== false) {
                ZLog::Write(LOGLEVEL_DEBUG, "Backend implementation of IStateMachine: ".get_class($backendStateMachine));
                if (in_array('IStateMachine', class_implements($backendStateMachine)))
                    ZPush::$stateMachine = $backendStateMachine;
                else
                    throw new FatalNotImplementedException("State machine returned by the backend does not implement the IStateMachine interface!");
            }
            else {
                // Initialize the default StateMachine
                include_once('lib/default/filestatemachine.php');
                ZPush::$stateMachine = new FileStateMachine();
            }
        }
        return ZPush::$stateMachine;
    }

    /**
     * Returns the DeviceManager object
     *
     * @param boolean   $initialize     (opt) default true: initializes the DeviceManager if not already done
     *
     * @access public
     * @return object DeviceManager
     */
    static public function GetDeviceManager($initialize = true) {
        if (!isset(ZPush::$deviceManager) && $initialize)
            ZPush::$deviceManager = new DeviceManager();

        return ZPush::$deviceManager;
    }

    /**
     * Returns the Top data collector object
     *
     * @access public
     * @return object TopCollector
     */
    static public function GetTopCollector() {
        if (!isset(ZPush::$topCollector))
            ZPush::$topCollector = new TopCollector();

        return ZPush::$topCollector;
    }

    /**
     * Loads a backend file
     *
     * @param string $backendname

     * @access public
     * @throws FatalNotImplementedException
     * @return boolean
     */
    static public function IncludeBackend($backendname) {
        if ($backendname == false) return false;

        $backendname = strtolower($backendname);
        if (substr($backendname, 0, 7) !== 'backend')
            throw new FatalNotImplementedException(sprintf("Backend '%s' is not allowed",$backendname));

        $rbn = substr($backendname, 7);

        $subdirbackend = REAL_BASE_PATH . "backend/" . $rbn . "/" . $rbn . ".php";
        $stdbackend = REAL_BASE_PATH . "backend/" . $rbn . ".php";

        if (is_file($subdirbackend))
            $toLoad = $subdirbackend;
        else if (is_file($stdbackend))
            $toLoad = $stdbackend;
        else
            return false;

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("Including backend file: '%s'", $toLoad));
        include_once($toLoad);
        return true;
    }

    /**
     * Returns the SearchProvider object
     * which has to be an ISearchProvider implementation
     *
     * @access public
     * @return object   implementation of ISearchProvider
     * @throws FatalMisconfigurationException, FatalNotImplementedException
     */
    static public function GetSearchProvider() {
        if (!isset(ZPush::$searchProvider)) {
            // is a global searchprovider configured ? It will  outrank the backend
            if (defined('SEARCH_PROVIDER') && @constant('SEARCH_PROVIDER') != "") {
                $searchClass = @constant('SEARCH_PROVIDER');

                if (! class_exists($searchClass))
                    self::IncludeBackend($searchClass);

                if (class_exists($searchClass))
                    $aSearchProvider = new $searchClass();
                else
                    throw new FatalMisconfigurationException(sprintf("Search provider '%s' can not be loaded. Check configuration!", $searchClass));
            }
            // get the searchprovider from the backend
            else
                $aSearchProvider = self::GetBackend()->GetSearchProvider();

            if (in_array('ISearchProvider', class_implements($aSearchProvider)))
                ZPush::$searchProvider = $aSearchProvider;
            else
                throw new FatalNotImplementedException("Instantiated SearchProvider does not implement the ISearchProvider interface!");
        }
        return ZPush::$searchProvider;
    }

    /**
     * Returns the Backend for this request
     * the backend has to be an IBackend implementation
     *
     * @access public
     * @return object     IBackend implementation
     */
    static public function GetBackend() {
        // if the backend is not yet loaded, load backend drivers and instantiate it
        if (!isset(ZPush::$backend)) {
            // Initialize our backend
            $ourBackend = @constant('BACKEND_PROVIDER');
            self::IncludeBackend($ourBackend);

            if (class_exists($ourBackend))
                ZPush::$backend = new $ourBackend();
            else
                throw new FatalMisconfigurationException(sprintf("Backend provider '%s' can not be loaded. Check configuration!", $ourBackend));
        }
        return ZPush::$backend;
    }

    /**
     * Returns additional folder objects which should be synchronized to the device
     *
     * @access public
     * @return array
     */
    static public function GetAdditionalSyncFolders() {
        // TODO if there are any user based folders which should be synchronized, they have to be returned here as well!!
        return self::$addSyncFolders;
    }

    /**
     * Returns additional folder objects which should be synchronized to the device
     *
     * @param string        $folderid
     * @param boolean       $noDebug        (opt) by default, debug message is shown
     *
     * @access public
     * @return string
     */
    static public function GetAdditionalSyncFolderStore($folderid, $noDebug = false) {
        $val = (isset(self::$addSyncFolders[$folderid]->Store))? self::$addSyncFolders[$folderid]->Store : false;
        if (!$noDebug)
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZPush::GetAdditionalSyncFolderStore('%s'): '%s'", $folderid, Utils::PrintAsString($val)));
        return $val;
    }

    /**
     * Returns a SyncObject class name for a folder class
     *
     * @param string $folderclass
     *
     * @access public
     * @return string
     * @throws FatalNotImplementedException
     */
    static public function getSyncObjectFromFolderClass($folderclass) {
        if (!isset(self::$classes[$folderclass]))
            throw new FatalNotImplementedException("Class '$folderclass' is not supported");

        $class = self::$classes[$folderclass][self::CLASS_NAME];
        if (self::$classes[$folderclass][self::CLASS_REQUIRESPROTOCOLVERSION])
            return new $class(Request::GetProtocolVersion());
        else
            return new $class();
    }

    /**
     * Returns the default foldertype for a folder class
     *
     * @param string $folderclass   folderclass sent by the mobile
     *
     * @access public
     * @return string
     */
    static public function getDefaultFolderTypeFromFolderClass($folderclass) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZPush::getDefaultFolderTypeFromFolderClass('%s'): '%d'", $folderclass, self::$classes[$folderclass][self::CLASS_DEFAULTTYPE]));
        return self::$classes[$folderclass][self::CLASS_DEFAULTTYPE];
    }

    /**
     * Returns the folder class for a foldertype
     *
     * @param string $foldertype
     *
     * @access public
     * @return string/false     false if no class for this type is available
     */
    static public function GetFolderClassFromFolderType($foldertype) {
        $class = false;
        foreach (self::$classes as $aClass => $cprops) {
            if ($cprops[self::CLASS_DEFAULTTYPE] == $foldertype || in_array($foldertype, $cprops[self::CLASS_OTHERTYPES])) {
                $class = $aClass;
                break;
            }
        }
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZPush::GetFolderClassFromFolderType('%s'): %s", $foldertype, Utils::PrintAsString($class)));
        return $class;
    }

    /**
     * Prints the Z-Push legal header to STDOUT
     * Using this breaks ActiveSync synchronization if wbxml is expected
     *
     * @param string $message               (opt) message to be displayed
     * @param string $additionalMessage     (opt) additional message to be displayed

     * @access public
     * @return
     *
     */
    static public function PrintZPushLegal($message = "", $additionalMessage = "") {
        ZLog::Write(LOGLEVEL_DEBUG,"ZPush::PrintZPushLegal()");
        $zpush_version = @constant('ZPUSH_VERSION');

        if ($message)
            $message = "<h3>". $message . "</h3>";
        if ($additionalMessage)
            $additionalMessage .= "<br>";

        header("Content-type: text/html");
        print <<<END
        <html>
        <header>
        <title>Z-Push ActiveSync</title>
        </header>
        <body>
        <font face="verdana">
        <h2>Z-Push - Open Source ActiveSync</h2>
        <b>Version $zpush_version</b><br>
        $message $additionalMessage
        <br><br>
        More information about Z-Push can be found at:<br>
        <a href="http://z-push.sf.net/">Z-Push homepage</a><br>
        <a href="http://z-push.sf.net/download">Z-Push download page at BerliOS</a><br>
        <a href="http://z-push.sf.net/tracker">Z-Push Bugtracker and Roadmap</a><br>
        <br>
        All modifications to this sourcecode must be published and returned to the community.<br>
        Please see <a href="http://www.gnu.org/licenses/agpl-3.0.html">AGPLv3 License</a> for details.<br>
        </font face="verdana">
        </body>
        </html>
END;
    }

    /**
     * Indicates the latest AS version supported by Z-Push
     *
     * @access public
     * @return string
     */
    static public function GetLatestSupportedASVersion() {
        return end(self::$supportedASVersions);
    }

    /**
     * Indicates which is the highest AS version supported by the backend
     *
     * @access public
     * @return string
     * @throws FatalNotImplementedException     if the backend returns an invalid version
     */
    static public function GetSupportedASVersion() {
        $version = self::GetBackend()->GetSupportedASVersion();
        if (!in_array($version, self::$supportedASVersions))
            throw new FatalNotImplementedException(sprintf("AS version '%s' reported by the backend is not supported", $version));

        return $version;
    }

    /**
     * Returns AS server header
     *
     * @access public
     * @return string
     */
    static public function GetServerHeader() {
        if (self::GetSupportedASVersion() == self::ASV_25)
            return "MS-Server-ActiveSync: 6.5.7638.1";
        else
            return "MS-Server-ActiveSync: ". self::GetSupportedASVersion();
    }

    /**
     * Returns AS protocol versions which are supported
     *
     * @param boolean   $valueOnly  (opt) default: false (also returns the header name)
     *
     * @access public
     * @return string
     */
    static public function GetSupportedProtocolVersions($valueOnly = false) {
        $versions = implode(',', array_slice(self::$supportedASVersions, 0, (array_search(self::GetSupportedASVersion(), self::$supportedASVersions)+1)));
        ZLog::Write(LOGLEVEL_DEBUG, "ZPush::GetSupportedProtocolVersions(): " . $versions);

        if ($valueOnly === true)
            return $versions;

        return "MS-ASProtocolVersions: " . $versions;
    }

    /**
     * Returns AS commands which are supported
     *
     * @access public
     * @return string
     */
    static public function GetSupportedCommands() {
        $asCommands = array();
        // filter all non-activesync commands
        foreach (self::$supportedCommands as $c=>$v)
            if (!self::checkCommandOptions($c, self::NOACTIVESYNCCOMMAND) &&
                self::checkCommandOptions($c, self::GetSupportedASVersion()))
                $asCommands[] = Utils::GetCommandFromCode($c);

        $commands = implode(',', $asCommands);
        ZLog::Write(LOGLEVEL_DEBUG, "ZPush::GetSupportedCommands(): " . $commands);
        return "MS-ASProtocolCommands: " . $commands;
    }

    /**
     * Loads and instantiates a request processor for a command
     *
     * @param int $commandCode
     *
     * @access public
     * @return RequestProcessor sub-class
     */
    static public function GetRequestHandlerForCommand($commandCode) {
        if (!array_key_exists($commandCode, self::$supportedCommands) ||
            !array_key_exists(self::REQUESTHANDLER, self::$supportedCommands[$commandCode]) )
            throw new FatalNotImplementedException(sprintf("Command '%s' has no request handler or class", Utils::GetCommandFromCode($commandCode)));

        $class = self::$supportedCommands[$commandCode][self::REQUESTHANDLER];
        if ($class == "Webservice")
            $handlerclass = REAL_BASE_PATH . "lib/webservice/webservice.php";
        else
            $handlerclass = REAL_BASE_PATH . "lib/request/" . strtolower($class) . ".php";

        if (is_file($handlerclass))
            include($handlerclass);

        if (class_exists($class))
            return new $class();
        else
            throw new FatalNotImplementedException(sprintf("Request handler '%s' can not be loaded", $class));
    }

    /**
     * Indicates if a commands requires authentication or not
     *
     * @param int $commandCode
     *
     * @access public
     * @return boolean
     */
    static public function CommandNeedsAuthentication($commandCode) {
        $stat = ! self::checkCommandOptions($commandCode, self::UNAUTHENTICATED);
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZPush::CommandNeedsAuthentication(%d): %s", $commandCode, Utils::PrintAsString($stat)));
        return $stat;
    }

    /**
     * Indicates if the Provisioning check has to be forced on these commands
     *
     * @param string $commandCode

     * @access public
     * @return boolean
     */
    static public function CommandNeedsProvisioning($commandCode) {
        $stat = ! self::checkCommandOptions($commandCode, self::UNPROVISIONED);
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZPush::CommandNeedsProvisioning(%s): %s", $commandCode, Utils::PrintAsString($stat)));
        return $stat;
    }

    /**
     * Indicates if these commands expect plain text input instead of wbxml
     *
     * @param string $commandCode
     *
     * @access public
     * @return boolean
     */
    static public function CommandNeedsPlainInput($commandCode) {
        $stat = self::checkCommandOptions($commandCode, self::PLAININPUT);
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZPush::CommandNeedsPlainInput(%d): %s", $commandCode, Utils::PrintAsString($stat)));
        return $stat;
    }

    /**
     * Indicates if the comand to be executed operates on the hierarchy
     *
     * @param int $commandCode

     * @access public
     * @return boolean
     */
    static public function HierarchyCommand($commandCode) {
        $stat = self::checkCommandOptions($commandCode, self::HIERARCHYCOMMAND);
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZPush::HierarchyCommand(%d): %s", $commandCode, Utils::PrintAsString($stat)));
        return $stat;
    }

    /**
     * Checks access types of a command
     *
     * @param string $commandCode   a commandCode
     * @param string $option        e.g. self::UNAUTHENTICATED

     * @access private
     * @throws FatalNotImplementedException
     * @return object StateMachine
     */
    static private function checkCommandOptions($commandCode, $option) {
        if ($commandCode === false) return false;

        if (!array_key_exists($commandCode, self::$supportedCommands))
            throw new FatalNotImplementedException(sprintf("Command '%s' is not supported", Utils::GetCommandFromCode($commandCode)));

        $capa = self::$supportedCommands[$commandCode];
        $defcapa = in_array($option, $capa, true);

        // if not looking for a default capability, check if the command is supported since a previous AS version
        if (!$defcapa) {
            $verkey = array_search($option, self::$supportedASVersions, true);
            if ($verkey !== false && ($verkey >= array_search($capa[0], self::$supportedASVersions))) {
                $defcapa = true;
            }
        }

        return $defcapa;
    }

}
?>