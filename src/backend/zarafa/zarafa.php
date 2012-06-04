<?php
/***********************************************
* File      :   zarafa.php
* Project   :   Z-Push
* Descr     :   This is backend for the
*               Zarafa Collaboration Platform (ZCP).
*               It is an implementation of IBackend
*               and also implements the ISearchProvider
*               to search in the Zarafa system.
*
* Created   :   01.10.2011
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
*************************************************/

// include PHP-MAPI classes
include_once('backend/zarafa/mapi/mapi.util.php');
include_once('backend/zarafa/mapi/mapidefs.php');
include_once('backend/zarafa/mapi/mapitags.php');
include_once('backend/zarafa/mapi/mapicode.php');
include_once('backend/zarafa/mapi/mapiguid.php');
include_once('backend/zarafa/mapi/class.baseexception.php');
include_once('backend/zarafa/mapi/class.mapiexception.php');
include_once('backend/zarafa/mapi/class.baserecurrence.php');
include_once('backend/zarafa/mapi/class.taskrecurrence.php');
include_once('backend/zarafa/mapi/class.recurrence.php');
include_once('backend/zarafa/mapi/class.meetingrequest.php');
include_once('backend/zarafa/mapi/class.freebusypublish.php');

// processing of RFC822 messages
include_once('include/mimeDecode.php');
require_once('include/z_RFC822.php');

// components of Zarafa backend
include_once('backend/zarafa/mapiutils.php');
include_once('backend/zarafa/mapimapping.php');
include_once('backend/zarafa/mapiprovider.php');
include_once('backend/zarafa/mapiphpwrapper.php');
include_once('backend/zarafa/mapistreamwrapper.php');
include_once('backend/zarafa/importer.php');
include_once('backend/zarafa/exporter.php');


class BackendZarafa implements IBackend, ISearchProvider {
    private $mainUser;
    private $session;
    private $defaultstore;
    private $store;
    private $storeName;
    private $storeCache;
    private $importedFolders;
    private $notifications;
    private $changesSink;
    private $changesSinkFolders;
    private $changesSinkStores;
    private $wastebasket;

    /**
     * Constructor of the Zarafa Backend
     *
     * @access public
     */
    public function BackendZarafa() {
        $this->session = false;
        $this->store = false;
        $this->storeName = false;
        $this->storeCache = array();
        $this->importedFolders = array();
        $this->notifications = false;
        $this->changesSink = false;
        $this->changesSinkFolders = array();
        $this->changesSinkStores = array();
        $this->wastebasket = false;
    }

    /**
     * Indicates which StateMachine should be used
     *
     * @access public
     * @return boolean      ZarafaBackend uses the default FileStateMachine
     */
    public function GetStateMachine() {
        return false;
    }

    /**
     * Returns the ZarafaBackend as it implements the ISearchProvider interface
     * This could be overwritten by the global configuration
     *
     * @access public
     * @return object       Implementation of ISearchProvider
     */
    public function GetSearchProvider() {
        return $this;
    }

    /**
     * Indicates which AS version is supported by the backend.
     *
     * @access public
     * @return string       AS version constant
     */
    public function GetSupportedASVersion() {
        return ZPush::ASV_14;
    }

    /**
     * Authenticates the user with the configured Zarafa server
     *
     * @param string        $username
     * @param string        $domain
     * @param string        $password
     *
     * @access public
     * @return boolean
     * @throws AuthenticationRequiredException
     */
    public function Logon($user, $domain, $pass) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZarafaBackend->Logon(): Trying to authenticate user '%s'..", $user));
        $this->mainUser = $user;

        try {
            // check if notifications are available in php-mapi
            if(function_exists('mapi_feature') && mapi_feature('LOGONFLAGS')) {
                $this->session = @mapi_logon_zarafa($user, $pass, MAPI_SERVER, null, null, 0);
                $this->notifications = true;
            }
            // old fashioned session
            else {
                $this->session = @mapi_logon_zarafa($user, $pass, MAPI_SERVER);
                $this->notifications = false;
            }

            if (mapi_last_hresult())
                ZLog::Write(LOGLEVEL_ERROR, sprintf("ZarafaBackend->Logon(): login failed with error code: 0x%X", mapi_last_hresult()));

        }
        catch (MAPIException $ex) {
            throw new AuthenticationRequiredException($ex->getDisplayMessage());
        }

        if(!$this->session) {
            ZLog::Write(LOGLEVEL_WARN, sprintf("ZarafaBackend->Logon(): logon failed for user '%s'", $user));
            $this->defaultstore = false;
            return false;
        }

        // Get/open default store
        $this->defaultstore = $this->openMessageStore($user);

        if($this->defaultstore === false)
            throw new AuthenticationRequiredException(sprintf("ZarafaBackend->Logon(): User '%s' has no default store", $user));

        $this->store = $this->defaultstore;
        $this->storeName = $user;

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZarafaBackend->Logon(): User '%s' is authenticated",$user));

        // check if this is a Zarafa 7 store with unicode support
        MAPIUtils::IsUnicodeStore($this->store);
        return true;
    }

    /**
     * Setup the backend to work on a specific store or checks ACLs there.
     * If only the $store is submitted, all Import/Export/Fetch/Etc operations should be
     * performed on this store (switch operations store).
     * If the ACL check is enabled, this operation should just indicate the ACL status on
     * the submitted store, without changing the store for operations.
     * For the ACL status, the currently logged on user MUST have access rights on
     *  - the entire store - admin access if no folderid is sent, or
     *  - on a specific folderid in the store (secretary/full access rights)
     *
     * The ACLcheck MUST fail if a folder of the authenticated user is checked!
     *
     * @param string        $store              target store, could contain a "domain\user" value
     * @param boolean       $checkACLonly       if set to true, Setup() should just check ACLs
     * @param string        $folderid           if set, only ACLs on this folderid are relevant
     *
     * @access public
     * @return boolean
     */
    public function Setup($store, $checkACLonly = false, $folderid = false) {
        list($user, $domain) = Utils::SplitDomainUser($store);

        if (!isset($this->mainUser))
            return false;

        if ($user === false)
            $user = $this->mainUser;

        // This is a special case. A user will get it's entire folder structure by the foldersync by default.
        // The ACL check is executed when an additional folder is going to be sent to the mobile.
        // Configured that way the user could receive the same folderid twice, with two different names.
        if ($this->mainUser == $user && $checkACLonly && $folderid) {
            ZLog::Write(LOGLEVEL_DEBUG, "ZarafaBackend->Setup(): Checking ACLs for folder of the users defaultstore. Fail is forced to avoid folder duplications on mobile.");
            return false;
        }

        // get the users store
        $userstore = $this->openMessageStore($user);

        // only proceed if a store was found, else return false
        if ($userstore) {
            // only check permissions
            if ($checkACLonly == true) {
                // check for admin rights
                if (!$folderid) {
                    if ($user != $this->mainUser) {
                        $zarafauserinfo = @mapi_zarafa_getuser_by_name($this->defaultstore, $this->mainUser);
                        $admin = (isset($zarafauserinfo['admin']) && $zarafauserinfo['admin'])?true:false;
                    }
                    // the user has always full access to his own store
                    else
                        $admin = true;

                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZarafaBackend->Setup(): Checking for admin ACLs on store '%s': '%s'", $user, Utils::PrintAsString($admin)));
                    return $admin;
                }
                // check 'secretary' permissions on this folder
                else {
                    $rights = $this->hasSecretaryACLs($userstore, $folderid);
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZarafaBackend->Setup(): Checking for secretary ACLs on '%s' of store '%s': '%s'", $folderid, $user, Utils::PrintAsString($rights)));
                    return $rights;
                }
            }

            // switch operations store
            // this should also be done if called with user = mainuser or user = false
            // which means to switch back to the default store
            else {
                // switch active store
                $this->store = $userstore;
                $this->storeName = $user;
                return true;
            }
        }
        return false;
    }

    /**
     * Logs off
     * Free/Busy information is updated for modified calendars
     * This is done after the synchronization process is completed
     *
     * @access public
     * @return boolean
     */
    public function Logoff() {
        // update if the calendar which received incoming changes
        foreach($this->importedFolders as $folderid => $store) {
            // open the root of the store
            $storeprops = mapi_getprops($store, array(PR_USER_ENTRYID));
            $root = mapi_msgstore_openentry($store);
            if (!$root)
                continue;

            // get the entryid of the main calendar of the store and the calendar to be published
            $rootprops = mapi_getprops($root, array(PR_IPM_APPOINTMENT_ENTRYID));
            $entryid = mapi_msgstore_entryidfromsourcekey($store, hex2bin($folderid));

            // only publish free busy for the main calendar
            if(isset($rootprops[PR_IPM_APPOINTMENT_ENTRYID]) && $rootprops[PR_IPM_APPOINTMENT_ENTRYID] == $entryid) {
                ZLog::Write(LOGLEVEL_INFO, sprintf("ZarafaBackend->Logoff(): Updating freebusy information on folder id '%s'", $folderid));
                $calendar = mapi_msgstore_openentry($store, $entryid);

                $pub = new FreeBusyPublish($this->session, $store, $calendar, $storeprops[PR_USER_ENTRYID]);
                $pub->publishFB(time() - (7 * 24 * 60 * 60), 6 * 30 * 24 * 60 * 60); // publish from one week ago, 6 months ahead
            }
        }

        return true;
    }

    /**
     * Returns an array of SyncFolder types with the entire folder hierarchy
     * on the server (the array itself is flat, but refers to parents via the 'parent' property
     *
     * provides AS 1.0 compatibility
     *
     * @access public
     * @return array SYNC_FOLDER
     */
    public function GetHierarchy() {
        $folders = array();
        $importer = false;
        $mapiprovider = new MAPIProvider($this->session, $this->store);

        $rootfolder = mapi_msgstore_openentry($this->store);
        $rootfolderprops = mapi_getprops($rootfolder, array(PR_SOURCE_KEY));
        $rootfoldersourcekey = bin2hex($rootfolderprops[PR_SOURCE_KEY]);

        $hierarchy =  mapi_folder_gethierarchytable($rootfolder, CONVENIENT_DEPTH);
        $rows = mapi_table_queryallrows($hierarchy, array(PR_ENTRYID));

        foreach ($rows as $row) {
            $mapifolder = mapi_msgstore_openentry($this->store, $row[PR_ENTRYID]);
            $folder = $mapiprovider->GetFolder($mapifolder);

            if (isset($folder->parentid) && $folder->parentid != $rootfoldersourcekey)
                $folders[] = $folder;
        }

        return $folders;
    }

    /**
     * Returns the importer to process changes from the mobile
     * If no $folderid is given, hierarchy importer is expected
     *
     * @param string        $folderid (opt)
     *
     * @access public
     * @return object(ImportChanges)
     */
    public function GetImporter($folderid = false) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendZarafa->GetImporter() folderid: '%s'", Utils::PrintAsString($folderid)));
        if($folderid !== false) {
            // check if the user of the current store has permissions to import to this folderid
            if ($this->storeName != $this->mainUser && !$this->hasSecretaryACLs($this->store, $folderid)) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendZarafa->GetImporter(): missing permissions on folderid: '%s'.", Utils::PrintAsString($folderid)));
                return false;
            }
            $this->importedFolders[$folderid] = $this->store;
            return new ImportChangesICS($this->session, $this->store, hex2bin($folderid));
        }
        else
            return new ImportChangesICS($this->session, $this->store);
    }

    /**
     * Returns the exporter to send changes to the mobile
     * If no $folderid is given, hierarchy exporter is expected
     *
     * @param string        $folderid (opt)
     *
     * @access public
     * @return object(ExportChanges)
     * @throws StatusException
     */
    public function GetExporter($folderid = false) {
        if($folderid !== false) {
            // check if the user of the current store has permissions to export from this folderid
            if ($this->storeName != $this->mainUser && !$this->hasSecretaryACLs($this->store, $folderid)) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendZarafa->GetExporter(): missing permissions on folderid: '%s'.", Utils::PrintAsString($folderid)));
                return false;
            }
            return new ExportChangesICS($this->session, $this->store, hex2bin($folderid));
        }
        else
            return new ExportChangesICS($this->session, $this->store);
    }

    /**
     * Sends an e-mail
     * This messages needs to be saved into the 'sent items' folder
     *
     * @param SyncSendMail  $sm     SyncSendMail object
     *
     * @access public
     * @return boolean
     * @throws StatusException
     */
    public function SendMail($sm) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZarafaBackend->SendMail(): RFC822: %d bytes  forward-id: '%s' reply-id: '%s' parent-id: '%s' SaveInSent: '%s' ReplaceMIME: '%s'",
                                            strlen($sm->mime), Utils::PrintAsString($sm->forwardflag), Utils::PrintAsString($sm->replyflag),
                                            Utils::PrintAsString((isset($sm->source->folderid) ? $sm->source->folderid : false)),
                                            Utils::PrintAsString(($sm->saveinsent)), Utils::PrintAsString(isset($sm->replacemime)) ));

        // by splitting the message in several lines we can easily grep later
        foreach(preg_split("/((\r)?\n)/", $sm->mime) as $rfc822line)
            ZLog::Write(LOGLEVEL_WBXML, "RFC822: ". $rfc822line);

        $mimeParams = array('decode_headers' => true,
                            'decode_bodies' => true,
                            'include_bodies' => true,
                            'charset' => 'utf-8');

        $mimeObject = new Mail_mimeDecode($sm->mime);
        $message = $mimeObject->decode($mimeParams);

        $sendMailProps = MAPIMapping::GetSendMailProperties();
        $sendMailProps = getPropIdsFromStrings($this->store, $sendMailProps);

        // Open the outbox and create the message there
        $storeprops = mapi_getprops($this->store, array($sendMailProps["outboxentryid"], $sendMailProps["ipmsentmailentryid"]));
        if(isset($storeprops[$sendMailProps["outboxentryid"]]))
            $outbox = mapi_msgstore_openentry($this->store, $storeprops[$sendMailProps["outboxentryid"]]);

        if(!$outbox)
            throw new StatusException(sprintf("ZarafaBackend->SendMail(): No Outbox found or unable to create message: 0x%X", mapi_last_hresult()), SYNC_COMMONSTATUS_SERVERERROR);

        $mapimessage = mapi_folder_createmessage($outbox);

        //message properties to be set
        $mapiprops = array();
        // only save the outgoing in sent items folder if the mobile requests it
        $mapiprops[$sendMailProps["sentmailentryid"]] = $storeprops[$sendMailProps["ipmsentmailentryid"]];

        // Check if imtomapi function is available and use it to send the mime message.
        // It is available since ZCP 7.0.6
        // @see http://jira.zarafa.com/browse/ZCP-9508
        if(function_exists('mapi_feature') && mapi_feature('INETMAPI_IMTOMAPI')) {
            ZLog::Write(LOGLEVEL_DEBUG, "Use the mapi_inetmapi_imtomapi function");
            $ab = mapi_openaddressbook($this->session);
            mapi_inetmapi_imtomapi($this->session, $this->store, $ab, $mapimessage, $sm->mime, array());

            mapi_setprops($mapimessage, $mapiprops);

            $this->addRecipients($message->headers, $mapimessage);

            // Delete the PR_SENT_REPRESENTING_* properties because some android devices
            // do not send neither From nor Sender header causing empty PR_SENT_REPRESENTING_NAME and
            // PR_SENT_REPRESENTING_EMAIL_ADDRESS properties and "broken" PR_SENT_REPRESENTING_ENTRYID
            // which results in spooler not being able to send the message.
            // @see http://jira.zarafa.com/browse/ZP-85
            mapi_deleteprops($mapimessage,
                array(  $sendMailProps["sentrepresentingname"], $sendMailProps["sentrepresentingemail"], $sendMailProps["representingentryid"],
                        $sendMailProps["sentrepresentingaddt"], $sendMailProps["sentrepresentinsrchk"]));

            mapi_message_savechanges($mapimessage);
            mapi_message_submitmessage($mapimessage);
            $hr = mapi_last_hresult();

            if ($hr)
                throw new StatusException(sprintf("ZarafaBackend->SendMail(): Error saving/submitting the message to the Outbox: 0x%X", mapi_last_hresult()), SYNC_COMMONSTATUS_MAILSUBMISSIONFAILED);

            ZLog::Write(LOGLEVEL_DEBUG, "ZarafaBackend->SendMail(): email submitted");
            return true;
        }

        $mapiprops[$sendMailProps["subject"]] = u2wi(isset($message->headers["subject"])?$message->headers["subject"]:"");
        $mapiprops[$sendMailProps["messageclass"]] = "IPM.Note";
        $mapiprops[$sendMailProps["deliverytime"]] = time();

        if(isset($message->headers["x-priority"])) {
            $this->getImportanceAndPriority($message->headers["x-priority"], $mapiprops, $sendMailProps);
        }

        $this->addRecipients($message->headers, $mapimessage);

        // Loop through message subparts.
        $body = "";
        $body_html = "";
        if($message->ctype_primary == "multipart" && ($message->ctype_secondary == "mixed" || $message->ctype_secondary == "alternative")) {
            $mparts = $message->parts;
            for($i=0; $i<count($mparts); $i++) {
                $part = $mparts[$i];

                // palm pre & iPhone send forwarded messages in another subpart which are also parsed
                if($part->ctype_primary == "multipart" && ($part->ctype_secondary == "mixed" || $part->ctype_secondary == "alternative"  || $part->ctype_secondary == "related")) {
                    foreach($part->parts as $spart)
                        $mparts[] = $spart;
                    continue;
                }

                // standard body
                if($part->ctype_primary == "text" && $part->ctype_secondary == "plain" && isset($part->body) && (!isset($part->disposition) || $part->disposition != "attachment")) {
                    $body .= u2wi($part->body); // assume only one text body
                }
                // html body
                elseif($part->ctype_primary == "text" && $part->ctype_secondary == "html") {
                    $body_html .= u2wi($part->body);
                }
                // TNEF
                elseif($part->ctype_primary == "ms-tnef" || $part->ctype_secondary == "ms-tnef") {
                    if (!isset($tnefAndIcalProps)) {
                        $tnefAndIcalProps = MAPIMapping::GetTnefAndIcalProperties();
                        $tnefAndIcalProps = getPropIdsFromStrings($this->store, $tnefAndIcalProps);
                    }

                    require_once('tnefparser.php');
                    $zptnef = new TNEFParser($this->store, $tnefAndIcalProps);

                    $zptnef->ExtractProps($part->body, $mapiprops);
                    if (is_array($mapiprops) && !empty($mapiprops)) {
                        //check if it is a recurring item
                        if (isset($mapiprops[$tnefAndIcalProps["tnefrecurr"]])) {
                            MAPIUtils::handleRecurringItem($mapiprops, $tnefAndIcalProps);
                        }
                    }
                    else ZLog::Write(LOGLEVEL_WARN, "ZarafaBackend->Sendmail(): TNEFParser: Mapi property array was empty");
                }
                // iCalendar
                elseif($part->ctype_primary == "text" && $part->ctype_secondary == "calendar") {
                    if (!isset($tnefAndIcalProps)) {
                        $tnefAndIcalProps = MAPIMapping::GetTnefAndIcalProperties();
                        $tnefAndIcalProps = getPropIdsFromStrings($this->store, $tnefAndIcalProps);
                    }

                    require_once('icalparser.php');
                    $zpical = new ICalParser($this->store, $tnefAndIcalProps);
                    $zpical->ExtractProps($part->body, $mapiprops);

                    // iPhone sends a second ICS which we ignore if we can
                    if (!isset($mapiprops[PR_MESSAGE_CLASS]) && strlen(trim($body)) == 0) {
                        ZLog::Write(LOGLEVEL_WARN, "ZarafaBackend->Sendmail(): Secondary iPhone response is being ignored!! Mail dropped!");
                        return true;
                    }

                    if (! Utils::CheckMapiExtVersion("6.30") && is_array($mapiprops) && !empty($mapiprops)) {
                        mapi_setprops($mapimessage, $mapiprops);
                    }
                    else {
                        // store ics as attachment
                        //see Utils::IcalTimezoneFix() in utils.php for more information
                        $part->body = Utils::IcalTimezoneFix($part->body);
                        MAPIUtils::StoreAttachment($mapimessage, $part);
                        ZLog::Write(LOGLEVEL_INFO, "ZarafaBackend->Sendmail(): Sending ICS file as attachment");
                    }
                }
                // any other type, store as attachment
                else
                    MAPIUtils::StoreAttachment($mapimessage, $part);
            }
        }
        // html main body
        else if($message->ctype_primary == "text" && $message->ctype_secondary == "html") {
            $body_html .= u2wi($message->body);
        }
        // standard body
        else {
            $body = u2wi($message->body);
        }

        // some devices only transmit a html body
        if (strlen($body) == 0 && strlen($body_html) > 0) {
            ZLog::Write(LOGLEVEL_WARN, "ZarafaBackend->SendMail(): only html body sent, transformed into plain text");
            $body = strip_tags($body_html);
        }

        if(isset($sm->source->itemid) && $sm->source->itemid) {
            // Append the original text body for reply/forward

            $entryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin($sm->source->folderid), hex2bin($sm->source->itemid));
            if ($entryid)
                $fwmessage = mapi_msgstore_openentry($this->store, $entryid);

            if(!isset($fwmessage) || !$fwmessage)
                throw new StatusException(sprintf("ZarafaBackend->SendMail(): Could not open message id '%s' in folder id '%s' to be replied/forwarded: 0x%X", $sm->source->itemid, $sm->source->folderid, mapi_last_hresult()), SYNC_COMMONSTATUS_ITEMNOTFOUND);

            //update icon when forwarding or replying message
            if ($sm->forwardflag) mapi_setprops($fwmessage, array(PR_ICON_INDEX=>262));
            elseif ($sm->replyflag) mapi_setprops($fwmessage, array(PR_ICON_INDEX=>261));
            mapi_savechanges($fwmessage);

            // only attach the original message if the mobile does not send it itself
            if (!isset($sm->replacemime)) {
                $stream = mapi_openproperty($fwmessage, PR_BODY, IID_IStream, 0, 0);
                $fwbody = "";

                while(1) {
                    $data = mapi_stream_read($stream, 1024);
                    if(strlen($data) == 0)
                        break;
                    $fwbody .= $data;
                }

                $stream = mapi_openproperty($fwmessage, PR_HTML, IID_IStream, 0, 0);
                $fwbody_html = "";

                while(1) {
                    $data = mapi_stream_read($stream, 1024);
                    if(strlen($data) == 0)
                        break;
                    $fwbody_html .= $data;
                }

                if($sm->forwardflag) {
                    // During a forward, we have to add the forward header ourselves. This is because
                    // normally the forwarded message is added as an attachment. However, we don't want this
                    // because it would be rather complicated to copy over the entire original message due
                    // to the lack of IMessage::CopyTo ..

                    $fwmessageprops = mapi_getprops($fwmessage, array(PR_SENT_REPRESENTING_NAME, PR_DISPLAY_TO, PR_DISPLAY_CC, PR_SUBJECT, PR_CLIENT_SUBMIT_TIME));

                    $fwheader = "\r\n\r\n";
                    $fwheader .= "-----Original Message-----\r\n";
                    if(isset($fwmessageprops[PR_SENT_REPRESENTING_NAME]))
                        $fwheader .= "From: " . $fwmessageprops[PR_SENT_REPRESENTING_NAME] . "\r\n";
                    if(isset($fwmessageprops[PR_DISPLAY_TO]) && strlen($fwmessageprops[PR_DISPLAY_TO]) > 0)
                        $fwheader .= "To: " . $fwmessageprops[PR_DISPLAY_TO] . "\r\n";
                    if(isset($fwmessageprops[PR_DISPLAY_CC]) && strlen($fwmessageprops[PR_DISPLAY_CC]) > 0)
                        $fwheader .= "Cc: " . $fwmessageprops[PR_DISPLAY_CC] . "\r\n";
                    if(isset($fwmessageprops[PR_CLIENT_SUBMIT_TIME]))
                        $fwheader .= "Sent: " . strftime("%x %X", $fwmessageprops[PR_CLIENT_SUBMIT_TIME]) . "\r\n";
                    if(isset($fwmessageprops[PR_SUBJECT]))
                        $fwheader .= "Subject: " . $fwmessageprops[PR_SUBJECT] . "\r\n";
                    $fwheader .= "\r\n";


                    // add fwheader to body and body_html
                    $body .= $fwheader;
                    if (strlen($body_html) > 0)
                        $body_html .= str_ireplace("\r\n", "<br>", $fwheader);

                    // attach the original attachments to the outgoing message
                    $attachtable = mapi_message_getattachmenttable($fwmessage);
                    $rows = mapi_table_queryallrows($attachtable, array(PR_ATTACH_NUM));

                    foreach($rows as $row) {
                        if(isset($row[PR_ATTACH_NUM])) {
                            $attach = mapi_message_openattach($fwmessage, $row[PR_ATTACH_NUM]);

                            $newattach = mapi_message_createattach($mapimessage);

                            // Copy all attachments from old to new attachment
                            $attachprops = mapi_getprops($attach);
                            mapi_setprops($newattach, $attachprops);

                            if(isset($attachprops[mapi_prop_tag(PT_ERROR, mapi_prop_id(PR_ATTACH_DATA_BIN))])) {
                                // Data is in a stream
                                $srcstream = mapi_openpropertytostream($attach, PR_ATTACH_DATA_BIN);
                                $dststream = mapi_openpropertytostream($newattach, PR_ATTACH_DATA_BIN, MAPI_MODIFY | MAPI_CREATE);

                                while(1) {
                                    $data = mapi_stream_read($srcstream, 4096);
                                    if(strlen($data) == 0)
                                        break;

                                    mapi_stream_write($dststream, $data);
                                }

                                mapi_stream_commit($dststream);
                            }
                            mapi_savechanges($newattach);
                        }
                    }
                }

                if(strlen($body) > 0)
                    $body .= $fwbody;

                if (strlen($body_html) > 0)
                    $body_html .= $fwbody_html;
            }
        }

        //set PR_INTERNET_CPID to 65001 (utf-8) if store supports it and to 1252 otherwise
        $internetcpid = INTERNET_CPID_WINDOWS1252;
        if (defined('STORE_SUPPORTS_UNICODE') && STORE_SUPPORTS_UNICODE == true) {
            $internetcpid = INTERNET_CPID_UTF8;
        }

        $mapiprops[$sendMailProps["body"]] = $body;
        $mapiprops[$sendMailProps["internetcpid"]] = $internetcpid;


        if(strlen($body_html) > 0){
            $mapiprops[$sendMailProps["html"]] = $body_html;
        }

        //TODO if setting all properties fails, try setting them infividually like in mapiprovider
        mapi_setprops($mapimessage, $mapiprops);

        mapi_savechanges($mapimessage);
        mapi_message_submitmessage($mapimessage);

        if(mapi_last_hresult())
            throw new StatusException(sprintf("ZarafaBackend->SendMail(): Error saving/submitting the message to the Outbox: 0x%X", mapi_last_hresult()), SYNC_COMMONSTATUS_MAILSUBMISSIONFAILED);

        ZLog::Write(LOGLEVEL_DEBUG, "ZarafaBackend->SendMail(): email submitted");
        return true;
    }

    /**
     * Returns all available data of a single message
     *
     * @param string            $folderid
     * @param string            $id
     * @param ContentParameters $contentparameters flag
     *
     * @access public
     * @return object(SyncObject)
     * @throws StatusException
     */
    public function Fetch($folderid, $id, $contentparameters) {
        // get the entry id of the message
        $entryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin($folderid), hex2bin($id));
        if(!$entryid)
            throw new StatusException(sprintf("BackendZarafa->Fetch('%s','%s'): Error getting entryid: 0x%X", $folderid, $id, mapi_last_hresult()), SYNC_STATUS_OBJECTNOTFOUND);

        // open the message
        $message = mapi_msgstore_openentry($this->store, $entryid);
        if(!$message)
            throw new StatusException(sprintf("BackendZarafa->Fetch('%s','%s'): Error, unable to open message: 0x%X", $folderid, $id, mapi_last_hresult()), SYNC_STATUS_OBJECTNOTFOUND);

        // convert the mapi message into a SyncObject and return it
        $mapiprovider = new MAPIProvider($this->session, $this->store);

        // override truncation
        $contentparameters->SetTruncation(SYNC_TRUNCATION_ALL);
        // TODO check for body preferences
        return $mapiprovider->GetMessage($message, $contentparameters);
    }

    /**
     * Returns the waste basket
     *
     * @access public
     * @return string
     */
    public function GetWasteBasket() {
        if ($this->wastebasket) {
            return $this->wastebasket;
        }

        $storeprops = mapi_getprops($this->defaultstore, array(PR_IPM_WASTEBASKET_ENTRYID));
        if (isset($storeprops[PR_IPM_WASTEBASKET_ENTRYID])) {
            $wastebasket = mapi_msgstore_openentry($this->store, $storeprops[PR_IPM_WASTEBASKET_ENTRYID]);
            $wastebasketprops = mapi_getprops($wastebasket, array(PR_SOURCE_KEY));
            if (isset($wastebasketprops[PR_SOURCE_KEY])) {
                $this->wastebasket = bin2hex($wastebasketprops[PR_SOURCE_KEY]);
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("Got waste basket with id '%s'", $this->wastebasket));
                return $this->wastebasket;
            }
        }
        return false;
    }

    /**
     * Returns the content of the named attachment as stream
     *
     * @param string        $attname
     * @access public
     * @return SyncItemOperationsAttachment
     * @throws StatusException
     */
    public function GetAttachmentData($attname) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZarafaBackend->GetAttachmentData('%s')", $attname));
        list($id, $attachnum) = explode(":", $attname);

        if(!isset($id) || !isset($attachnum))
            throw new StatusException(sprintf("ZarafaBackend->GetAttachmentData('%s'): Error, attachment requested for non-existing item", $attname), SYNC_ITEMOPERATIONSSTATUS_INVALIDATT);

        $entryid = hex2bin($id);
        $message = mapi_msgstore_openentry($this->store, $entryid);
        if(!$message)
            throw new StatusException(sprintf("ZarafaBackend->GetAttachmentData('%s'): Error, unable to open item for attachment data for id '%s' with: 0x%X", $attname, $id, mapi_last_hresult()), SYNC_ITEMOPERATIONSSTATUS_INVALIDATT);

        $attach = mapi_message_openattach($message, $attachnum);
        if(!$attach)
            throw new StatusException(sprintf("ZarafaBackend->GetAttachmentData('%s'): Error, unable to open attachment number '%s' with: 0x%X", $attname, $attachnum, mapi_last_hresult()), SYNC_ITEMOPERATIONSSTATUS_INVALIDATT);

        $stream = mapi_openpropertytostream($attach, PR_ATTACH_DATA_BIN);
        if(!$stream)
            throw new StatusException(sprintf("ZarafaBackend->GetAttachmentData('%s'): Error, unable to open attachment data stream: 0x%X", $attname, mapi_last_hresult()), SYNC_ITEMOPERATIONSSTATUS_INVALIDATT);

        //get the mime type of the attachment
        $contenttype = mapi_getprops($attach, array(PR_ATTACH_MIME_TAG, PR_ATTACH_MIME_TAG_W));
        $attachment = new SyncItemOperationsAttachment();
        // put the mapi stream into a wrapper to get a standard stream
        $attachment->data = MapiStreamWrapper::Open($stream);
        if (isset($contenttype[PR_ATTACH_MIME_TAG]))
            $attachment->contenttype = $contenttype[PR_ATTACH_MIME_TAG];
        elseif (isset($contenttype[PR_ATTACH_MIME_TAG_W]))
            $attachment->contenttype = $contenttype[PR_ATTACH_MIME_TAG_W];
            //TODO default contenttype
        return $attachment;
    }


    /**
     * Deletes all contents of the specified folder.
     * This is generally used to empty the trash (wastebasked), but could also be used on any
     * other folder.
     *
     * @param string        $folderid
     * @param boolean       $includeSubfolders      (opt) also delete sub folders, default true
     *
     * @access public
     * @return boolean
     * @throws StatusException
     */
    public function EmptyFolder($folderid, $includeSubfolders = true) {
        $folderentryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin($folderid));
        if (!$folderentryid)
            throw new StatusException(sprintf("BackendZarafa->EmptyFolder('%s','%s'): Error, unable to open folder (no entry id)", $folderid, Utils::PrintAsString($includeSubfolders)), SYNC_ITEMOPERATIONSSTATUS_SERVERERROR);
        $folder = mapi_msgstore_openentry($this->store, $folderentryid);

        if (!$folder)
            throw new StatusException(sprintf("BackendZarafa->EmptyFolder('%s','%s'): Error, unable to open parent folder (open entry)", $folderid, Utils::PrintAsString($includeSubfolders)), SYNC_ITEMOPERATIONSSTATUS_SERVERERROR);

        $flags = 0;
        if ($includeSubfolders)
            $flags = DEL_ASSOCIATED;

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendZarafa->EmptyFolder('%s','%s'): emptying folder",$folderid, Utils::PrintAsString($includeSubfolders)));

        // empty folder!
        mapi_folder_emptyfolder($folder, $flags);
        if (mapi_last_hresult())
            throw new StatusException(sprintf("BackendZarafa->EmptyFolder('%s','%s'): Error, mapi_folder_emptyfolder() failed: 0x%X", $folderid, Utils::PrintAsString($includeSubfolders), mapi_last_hresult()), SYNC_ITEMOPERATIONSSTATUS_SERVERERROR);

        return true;
    }

    /**
     * Processes a response to a meeting request.
     * CalendarID is a reference and has to be set if a new calendar item is created
     *
     * @param string        $requestid      id of the object containing the request
     * @param string        $folderid       id of the parent folder of $requestid
     * @param string        $response
     *
     * @access public
     * @return string       id of the created/updated calendar obj
     * @throws StatusException
     */
    public function MeetingResponse($requestid, $folderid, $response) {
        // Use standard meeting response code to process meeting request
        $reqentryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin($folderid), hex2bin($requestid));
        if ($reqentryid)
            $mapimessage = mapi_msgstore_openentry($this->store, $reqentryid);

        if(!$mapimessage)
            throw new StatusException(sprintf("BackendZarafa->MeetingResponse('%s','%s', '%s'): Error, unable to open request message for response 0x%X", $requestid, $folderid, $response, mapi_last_hresult()), SYNC_MEETRESPSTATUS_MAILBOXERROR);

        $meetingrequest = new Meetingrequest($this->store, $mapimessage);

        if(!$meetingrequest->isMeetingRequest())
            throw new StatusException(sprintf("BackendZarafa->MeetingResponse('%s','%s', '%s'): Error, attempt to respond to non-meeting request", $requestid, $folderid, $response), SYNC_MEETRESPSTATUS_INVALIDMEETREQ);

        if($meetingrequest->isLocalOrganiser())
            throw new StatusException(sprintf("BackendZarafa->MeetingResponse('%s','%s', '%s'): Error, attempt to response to meeting request that we organized", $requestid, $folderid, $response), SYNC_MEETRESPSTATUS_INVALIDMEETREQ);

        // Process the meeting response. We don't have to send the actual meeting response
        // e-mail, because the device will send it itself.
        switch($response) {
            case 1:     // accept
            default:
                $entryid = $meetingrequest->doAccept(false, false, false, false, false, false, true); // last true is the $userAction
                break;
            case 2:        // tentative
                $entryid = $meetingrequest->doAccept(true, false, false, false, false, false, true); // last true is the $userAction
                break;
            case 3:        // decline
                $meetingrequest->doDecline(false);
                break;
        }

        // F/B will be updated on logoff

        // We have to return the ID of the new calendar item, so do that here
        if (isset($entryid)) {
            $newitem = mapi_msgstore_openentry($this->store, $entryid);
            $newprops = mapi_getprops($newitem, array(PR_SOURCE_KEY));
            $calendarid = bin2hex($newprops[PR_SOURCE_KEY]);
        }

        // on recurring items, the MeetingRequest class responds with a wrong entryid
        if ($requestid == $calendarid) {
               ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendZarafa->MeetingResponse('%s','%s', '%s'): returned calender id is the same as the requestid - re-searching", $requestid, $folderid, $response));

            $props = MAPIMapping::GetMeetingRequestProperties();
            $props = getPropIdsFromStrings($this->store, $props);

            $messageprops = mapi_getprops($mapimessage, Array($props["goidtag"]));
            $goid = $messageprops[$props["goidtag"]];

            $items = $meetingrequest->findCalendarItems($goid);

            if (is_array($items)) {
               $newitem = mapi_msgstore_openentry($this->store, $items[0]);
               $newprops = mapi_getprops($newitem, array(PR_SOURCE_KEY));
               $calendarid = bin2hex($newprops[PR_SOURCE_KEY]);
               ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendZarafa->MeetingResponse('%s','%s', '%s'): found other calendar entryid", $requestid, $folderid, $response));
            }
        }

        if ($calendarid == "" || $requestid == $calendarid)
            throw new StatusException(sprintf("BackendZarafa->MeetingResponse('%s','%s', '%s'): Error finding the accepted meeting response in the calendar", $requestid, $folderid, $response), SYNC_MEETRESPSTATUS_INVALIDMEETREQ);

        // delete meeting request from Inbox
        $folderentryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin($folderid));
        $folder = mapi_msgstore_openentry($this->store, $folderentryid);
        mapi_folder_deletemessages($folder, array($reqentryid), 0);

        return $calendarid;
    }

    /**
     * Indicates if the backend has a ChangesSink.
     * A sink is an active notification mechanism which does not need polling.
     * Since Zarafa 7.0.5 such a sink is available.
     * The Zarafa backend uses this method to initialize the sink with mapi.
     *
     * @access public
     * @return boolean
     */
    public function HasChangesSink() {
        if (!$this->notifications) {
            ZLog::Write(LOGLEVEL_DEBUG, "ZarafaBackend->HasChangesSink(): sink is not available");
            return false;
        }

        $this->changesSink = @mapi_sink_create();

        if (! $this->changesSink) {
            ZLog::Write(LOGLEVEL_DEBUG, "ZarafaBackend->HasChangesSink(): sink could not be created");
            return false;
        }

        ZLog::Write(LOGLEVEL_DEBUG, "ZarafaBackend->HasChangesSink(): created");
        return true;
    }

    /**
     * The folder should be considered by the sink.
     * Folders which were not initialized should not result in a notification
     * of IBacken->ChangesSink().
     *
     * @param string        $folderid
     *
     * @access public
     * @return boolean      false if entryid can not be found for that folder
     */
    public function ChangesSinkInitialize($folderid) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZarafaBackend->ChangesSinkInitialize(): folderid '%s'", $folderid));

        $entryid = mapi_msgstore_entryidfromsourcekey($this->store, hex2bin($folderid));
        if (!$entryid)
            return false;

        // add entryid to the monitored folders
        $this->changesSinkFolders[$entryid] = $folderid;

        // check if this store is already monitores, else advise it
        if (!in_array($this->store, $this->changesSinkStores)) {
            mapi_msgstore_advise($this->store, null, fnevObjectModified | fnevObjectCreated | fnevObjectMoved | fnevObjectDeleted, $this->changesSink);
            $this->changesSinkStores[] = $this->store;
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZarafaBackend->ChangesSinkInitialize(): advised store '%s'", $this->store));
        }
        return true;
    }

    /**
     * The actual ChangesSink.
     * For max. the $timeout value this method should block and if no changes
     * are available return an empty array.
     * If changes are available a list of folderids is expected.
     *
     * @param int           $timeout        max. amount of seconds to block
     *
     * @access public
     * @return array
     */
    public function ChangesSink($timeout = 30) {
        $notifications = array();
        $sinkresult = @mapi_sink_timedwait($this->changesSink, $timeout * 1000);
        foreach ($sinkresult as $sinknotif) {
            // check if something in the monitored folders changed
            if (isset($sinknotif['parentid']) && array_key_exists($sinknotif['parentid'], $this->changesSinkFolders)) {
                $notifications[] = $this->changesSinkFolders[$sinknotif['parentid']];
            }
            // deletes and moves
            else if (isset($sinknotif['oldparentid']) && array_key_exists($sinknotif['oldparentid'], $this->changesSinkFolders)) {
                $notifications[] = $this->changesSinkFolders[$sinknotif['oldparentid']];
            }
        }
        return $notifications;
    }

    /**
     * Applies settings to and gets informations from the device
     *
     * @param SyncObject        $settings (SyncOOF or SyncUserInformation possible)
     *
     * @access public
     * @return SyncObject       $settings
     */
    public function Settings($settings) {
        if ($settings instanceof SyncOOF) {
            $this->settingsOOF($settings);
        }

        if ($settings instanceof SyncUserInformation) {
            $this->settingsUserInformation($settings);
        }

        return $settings;
    }


    /**----------------------------------------------------------------------------------------------------------
     * Implementation of the ISearchProvider interface
     */

    /**
     * Indicates if a search type is supported by this SearchProvider
     * Currently only the type ISearchProvider::SEARCH_GAL (Global Address List) is implemented
     *
     * @param string        $searchtype
     *
     * @access public
     * @return boolean
     */
    public function SupportsType($searchtype) {
        return ($searchtype == ISearchProvider::SEARCH_GAL) || ($searchtype == ISearchProvider::SEARCH_MAILBOX);
    }

    /**
     * Searches the GAB of Zarafa
     * Can be overwitten globally by configuring a SearchBackend
     *
     * @param string        $searchquery
     * @param string        $searchrange
     *
     * @access public
     * @return array
     * @throws StatusException
     */
    public function GetGALSearchResults($searchquery, $searchrange){
        // only return users from who the displayName or the username starts with $name
        //TODO: use PR_ANR for this restriction instead of PR_DISPLAY_NAME and PR_ACCOUNT
        $addrbook = mapi_openaddressbook($this->session);
        if ($addrbook)
            $ab_entryid = mapi_ab_getdefaultdir($addrbook);
        if ($ab_entryid)
            $ab_dir = mapi_ab_openentry($addrbook, $ab_entryid);
        if ($ab_dir)
            $table = mapi_folder_getcontentstable($ab_dir);

        if (!$table)
            throw new StatusException(sprintf("ZarafaBackend->GetGALSearchResults(): could not open addressbook: 0x%X", mapi_last_hresult()), SYNC_SEARCHSTATUS_STORE_CONNECTIONFAILED);

        $restriction = MAPIUtils::GetSearchRestriction(u2w($searchquery));
        mapi_table_restrict($table, $restriction);
        mapi_table_sort($table, array(PR_DISPLAY_NAME => TABLE_SORT_ASCEND));

        if (mapi_last_hresult())
            throw new StatusException(sprintf("ZarafaBackend->GetGALSearchResults(): could not apply restriction: 0x%X", mapi_last_hresult()), SYNC_SEARCHSTATUS_STORE_TOOCOMPLEX);

        //range for the search results, default symbian range end is 50, wm 99,
        //so we'll use that of nokia
        $rangestart = 0;
        $rangeend = 50;

        if ($searchrange != '0') {
            $pos = strpos($searchrange, '-');
            $rangestart = substr($searchrange, 0, $pos);
            $rangeend = substr($searchrange, ($pos + 1));
        }
        $items = array();

        $querycnt = mapi_table_getrowcount($table);
        //do not return more results as requested in range
        $querylimit = (($rangeend + 1) < $querycnt) ? ($rangeend + 1) : $querycnt;
        $items['range'] = $rangestart.'-'.($querylimit - 1);
        $items['searchtotal'] = $querycnt;

        if ($querycnt > 0)
            $abentries = mapi_table_queryrows($table, array(PR_ACCOUNT, PR_DISPLAY_NAME, PR_SMTP_ADDRESS, PR_BUSINESS_TELEPHONE_NUMBER, PR_GIVEN_NAME, PR_SURNAME, PR_MOBILE_TELEPHONE_NUMBER, PR_HOME_TELEPHONE_NUMBER, PR_TITLE, PR_COMPANY_NAME, PR_OFFICE_LOCATION), $rangestart, $querylimit);

        for ($i = 0; $i < $querylimit; $i++) {
            $items[$i][SYNC_GAL_DISPLAYNAME] = w2u($abentries[$i][PR_DISPLAY_NAME]);

            if (strlen(trim($items[$i][SYNC_GAL_DISPLAYNAME])) == 0)
                $items[$i][SYNC_GAL_DISPLAYNAME] = w2u($abentries[$i][PR_ACCOUNT]);

            $items[$i][SYNC_GAL_ALIAS] = w2u($abentries[$i][PR_ACCOUNT]);
            //it's not possible not get first and last name of an user
            //from the gab and user functions, so we just set lastname
            //to displayname and leave firstname unset
            //this was changed in Zarafa 6.40, so we try to get first and
            //last name and fall back to the old behaviour if these values are not set
            if (isset($abentries[$i][PR_GIVEN_NAME]))
                $items[$i][SYNC_GAL_FIRSTNAME] = w2u($abentries[$i][PR_GIVEN_NAME]);
            if (isset($abentries[$i][PR_SURNAME]))
                $items[$i][SYNC_GAL_LASTNAME] = w2u($abentries[$i][PR_SURNAME]);

            if (!isset($items[$i][SYNC_GAL_LASTNAME])) $items[$i][SYNC_GAL_LASTNAME] = $items[$i][SYNC_GAL_DISPLAYNAME];

            $items[$i][SYNC_GAL_EMAILADDRESS] = w2u($abentries[$i][PR_SMTP_ADDRESS]);
            //check if an user has an office number or it might produce warnings in the log
            if (isset($abentries[$i][PR_BUSINESS_TELEPHONE_NUMBER]))
                $items[$i][SYNC_GAL_PHONE] = w2u($abentries[$i][PR_BUSINESS_TELEPHONE_NUMBER]);
            //check if an user has a mobile number or it might produce warnings in the log
            if (isset($abentries[$i][PR_MOBILE_TELEPHONE_NUMBER]))
                $items[$i][SYNC_GAL_MOBILEPHONE] = w2u($abentries[$i][PR_MOBILE_TELEPHONE_NUMBER]);
            //check if an user has a home number or it might produce warnings in the log
            if (isset($abentries[$i][PR_HOME_TELEPHONE_NUMBER]))
                $items[$i][SYNC_GAL_HOMEPHONE] = w2u($abentries[$i][PR_HOME_TELEPHONE_NUMBER]);

            if (isset($abentries[$i][PR_COMPANY_NAME]))
                $items[$i][SYNC_GAL_COMPANY] = w2u($abentries[$i][PR_COMPANY_NAME]);

            if (isset($abentries[$i][PR_TITLE]))
                $items[$i][SYNC_GAL_TITLE] = w2u($abentries[$i][PR_TITLE]);

            if (isset($abentries[$i][PR_OFFICE_LOCATION]))
                $items[$i][SYNC_GAL_OFFICE] = w2u($abentries[$i][PR_OFFICE_LOCATION]);
        }
        return $items;
    }

    /**
     * Searches for the emails on the server
     *
     * @param ContentParameter $cpo
     *
     * @return array
     */
    public function GetMailboxSearchResults($cpo) {
        $searchFolder = $this->getSearchFolder();
        $searchRestriction = $this->getSearchRestriction($cpo->GetSearchFreeText());
        $searchRange = explode('-', $cpo->GetSearchRange());
        $searchFolderId = $cpo->GetSearchFolderid();
        $items = array();

        $tmp = mapi_getprops($this->store, array(PR_ENTRYID,PR_DISPLAY_NAME,PR_IPM_SUBTREE_ENTRYID));
        mapi_folder_setsearchcriteria($searchFolder, $searchRestriction, array($tmp[PR_IPM_SUBTREE_ENTRYID]), RECURSIVE_SEARCH);

        $table = mapi_folder_getcontentstable($searchFolder);
        $searchStart = time();
        // do the search and wait for all the results available
        while (time() - $searchStart < SEARCH_WAIT) {
            $searchcriteria = mapi_folder_getsearchcriteria($searchFolder);
            if(($searchcriteria["searchstate"] & SEARCH_REBUILD) == 0)
                break; // Search is done
            sleep(1);
        }

        // if the search range is set limit the result to it, otherwise return all found messages
        $rows = (is_array($searchRange) && isset($searchRange[0]) && isset($searchRange[1])) ?
            mapi_table_queryrows($table, array(PR_SOURCE_KEY, PR_PARENT_SOURCE_KEY), $searchRange[0], $searchRange[1] - $searchRange[0] + 1) :
            mapi_table_queryrows($table, array(PR_SOURCE_KEY, PR_PARENT_SOURCE_KEY), 0, SEARCH_MAXRESULTS);

        $cnt = count($rows);
        $items['searchtotal'] = $cnt;
        for ($i = 0; $i < $cnt; $i++) {
            $items[$i]['class'] = 'Email';
            $items[$i]['longid'] = bin2hex($rows[$i][PR_PARENT_SOURCE_KEY]) . ":" . bin2hex($rows[$i][PR_SOURCE_KEY]);
            $items[$i]['folderid'] = bin2hex($rows[$i][PR_PARENT_SOURCE_KEY]);
        }
        return $items;
    }

    /**
     * Disconnects from the current search provider
     *
     * @access public
     * @return boolean
     */
    public function Disconnect() {
        return true;
    }

    /**
     * Returns the MAPI store ressource for a folderid
     * This is not part of IBackend but necessary for the ImportChangesICS->MoveMessage() operation if
     * the destination folder is not in the default store
     * Note: The current backend store might be changed as IBackend->Setup() is executed
     *
     * @param string        $store              target store, could contain a "domain\user" value - if emtpy default store is returned
     * @param string        $folderid
     *
     * @access public
     * @return Ressource/boolean
     */
    public function GetMAPIStoreForFolderId($store, $folderid) {
        if ($store == false) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZarafaBackend->GetMAPIStoreForFolderId('%s', '%s'): no store specified, returning default store", $store, $folderid));
            return $this->defaultstore;
        }

        // setup the correct store
        if ($this->Setup($store, false, $folderid)) {
            return $this->store;
        }
        else {
            ZLog::Write(LOGLEVEL_WARN, sprintf("ZarafaBackend->GetMAPIStoreForFolderId('%s', '%s'): store is not available", $store, $folderid));
            return false;
        }
    }


    /**----------------------------------------------------------------------------------------------------------
     * Private methods
     */

    /**
     * Open the store marked with PR_DEFAULT_STORE = TRUE
     * if $return_public is set, the public store is opened
     *
     * @param string    $user               User which store should be opened
     *
     * @access public
     * @return boolean
     */
    private function openMessageStore($user) {
        // During PING requests the operations store has to be switched constantly
        // the cache prevents the same store opened several times
        if (isset($this->storeCache[$user]))
           return  $this->storeCache[$user];

        $entryid = false;
        $return_public = false;

        if (strtoupper($user) == 'SYSTEM')
            $return_public = true;

        // loop through the storestable if authenticated user of public folder
        if ($user == $this->mainUser || $return_public === true) {
            // Find the default store
            $storestables = mapi_getmsgstorestable($this->session);
            $result = mapi_last_hresult();

            if ($result == NOERROR){
                $rows = mapi_table_queryallrows($storestables, array(PR_ENTRYID, PR_DEFAULT_STORE, PR_MDB_PROVIDER));

                foreach($rows as $row) {
                    if(!$return_public && isset($row[PR_DEFAULT_STORE]) && $row[PR_DEFAULT_STORE] == true) {
                        $entryid = $row[PR_ENTRYID];
                        break;
                    }
                    if ($return_public && isset($row[PR_MDB_PROVIDER]) && $row[PR_MDB_PROVIDER] == ZARAFA_STORE_PUBLIC_GUID) {
                        $entryid = $row[PR_ENTRYID];
                        break;
                    }
                }
            }
        }
        else
            $entryid = @mapi_msgstore_createentryid($this->defaultstore, $user);

        if($entryid) {
            $store = @mapi_openmsgstore($this->session, $entryid);

            if (!$store) {
                ZLog::Write(LOGLEVEL_WARN, sprintf("ZarafaBackend->openMessageStore('%s'): Could not open store", $user));
                return false;
            }

            // add this store to the cache
            if (!isset($this->storeCache[$user]))
                $this->storeCache[$user] = $store;

            ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZarafaBackend->openMessageStore('%s'): Found '%s' store: '%s'", $user, (($return_public)?'PUBLIC':'DEFAULT'),$store));
            return $store;
        }
        else {
            ZLog::Write(LOGLEVEL_WARN, sprintf("ZarafaBackend->openMessageStore('%s'): No store found for this user", $user));
            return false;
        }
    }

    private function hasSecretaryACLs($store, $folderid) {
        $entryid = mapi_msgstore_entryidfromsourcekey($store, hex2bin($folderid));
        if (!$entryid)  return false;

        $folder = mapi_msgstore_openentry($store, $entryid);
        if (!$folder) return false;

        $props = mapi_getprops($folder, array(PR_RIGHTS));
        if (isset($props[PR_RIGHTS]) &&
            ($props[PR_RIGHTS] & ecRightsReadAny) &&
            ($props[PR_RIGHTS] & ecRightsCreate) &&
            ($props[PR_RIGHTS] & ecRightsEditOwned) &&
            ($props[PR_RIGHTS] & ecRightsDeleteOwned) &&
            ($props[PR_RIGHTS] & ecRightsEditAny) &&
            ($props[PR_RIGHTS] & ecRightsDeleteAny) &&
            ($props[PR_RIGHTS] & ecRightsFolderVisible) ) {
            return true;
        }
        return false;
    }

    /**
     * The meta function for out of office settings.
     *
     * @param SyncObject $oof
     *
     * @access private
     * @return void
     */
    private function settingsOOF(&$oof) {
        //if oof state is set it must be set of oof and get otherwise
        if (isset($oof->oofstate)) {
            $this->settingsOOFSEt($oof);
        }
        else {
            $this->settingsOOFGEt($oof);
        }
    }

    /**
     * Gets the out of office settings
     *
     * @param SyncObject $oof
     *
     * @access private
     * @return void
     */
    private function settingsOOFGEt(&$oof) {
        $oofprops = mapi_getprops($this->defaultstore, array(PR_EC_OUTOFOFFICE, PR_EC_OUTOFOFFICE_MSG, PR_EC_OUTOFOFFICE_SUBJECT));
        $oof->oofstate = SYNC_SETTINGSOOF_DISABLED;
        $oof->Status = SYNC_SETTINGSSTATUS_SUCCESS;
        if ($oofprops != false) {
            $oof->oofstate = isset($oofprops[PR_EC_OUTOFOFFICE]) ? ($oofprops[PR_EC_OUTOFOFFICE] ? SYNC_SETTINGSOOF_GLOBAL : SYNC_SETTINGSOOF_DISABLED) : SYNC_SETTINGSOOF_DISABLED;
            //TODO external and external unknown
            $oofmessage = new SyncOOFMessage();
            $oofmessage->appliesToInternal = "";
            $oofmessage->enabled = $oof->oofstate;
            $oofmessage->replymessage = w2u(isset($oofprops[PR_EC_OUTOFOFFICE_MSG]) ? $oofprops[PR_EC_OUTOFOFFICE_MSG] : "");
            $oofmessage->bodytype = $oof->bodytype;
            unset($oofmessage->appliesToExternal, $oofmessage->appliesToExternalUnknown);
            $oof->oofmessage[] = $oofmessage;
        }
        else {
            ZLog::Write(LOGLEVEL_WARN, "Unable to get out of office information");
        }

        //unset body type for oof in order not to stream it
        unset($oof->bodyType);

    }

    /**
     * Sets the out of office settings.
     *
     * @param SyncObject $oof
     *
     * @access private
     * @return void
     */
    private function settingsOOFSEt(&$oof) {
        $oof->Status = SYNC_SETTINGSSTATUS_SUCCESS;
        $props = array();
        if ($oof->oofstate == SYNC_SETTINGSOOF_GLOBAL || $oof->oofstate == SYNC_SETTINGSOOF_TIMEBASED) {
            $props[PR_EC_OUTOFOFFICE] = true;
            foreach ($oof->oofmessage as $oofmessage) {
                if (isset($oofmessage->appliesToInternal)) {
                    $props[PR_EC_OUTOFOFFICE_MSG] = isset($oofmessage->replymessage) ? u2w($oofmessage->replymessage) : "";
                    $props[PR_EC_OUTOFOFFICE_SUBJECT] = "Out of office";
                }
            }
        }
        elseif($oof->oofstate == SYNC_SETTINGSOOF_DISABLED) {
            $props[PR_EC_OUTOFOFFICE] = false;
        }

        if (!empty($props)) {
            @mapi_setprops($this->defaultstore, $props);
            $result = mapi_last_hresult();
            if ($result != NOERROR) {
                ZLog::Write(LOGLEVEL_ERROR, sprintf("Setting oof information failed (%X)", $result));
                return false;
            }
        }

        return true;
    }

    /**
     * Gets the user's email address from server
     *
     * @param SyncObject $userinformation
     *
     * @access private
     * @return void
     */
    private function settingsUserInformation(&$userinformation) {
        if (!isset($this->defaultstore) || !isset($this->mainUser)) {
            ZLog::Write(LOGLEVEL_ERROR, "The store or user are not available for getting user information");
            return false;
        }
        $user = mapi_zarafa_getuser($this->defaultstore, $this->mainUser);
        if ($user != false) {
            $userinformation->Status = SYNC_SETTINGSSTATUS_USERINFO_SUCCESS;
            $userinformation->emailaddresses[] = $user["emailaddress"];
            return true;
        }
        ZLog::Write(LOGLEVEL_ERROR, sprintf("Getting user information failed: mapi_zarafa_getuser(%X)", mapi_last_hresult()));
        return false;
    }

    /**
     * Sets the importance and priority of a message from a RFC822 message headers.
     *
     * @param int $xPriority
     * @param array $mapiprops
     *
     * @return void
     */
    private function getImportanceAndPriority($xPriority, &$mapiprops, $sendMailProps) {
        switch($xPriority) {
            case 1:
            case 2:
                $priority = PRIO_URGENT;
                $importance = IMPORTANCE_HIGH;
                break;
            case 4:
            case 5:
                $priority = PRIO_NONURGENT;
                $importance = IMPORTANCE_LOW;
                break;
            case 3:
            default:
                $priority = PRIO_NORMAL;
                $importance = IMPORTANCE_NORMAL;
                break;
        }
        $mapiprops[$sendMailProps["importance"]] = $importance;
        $mapiprops[$sendMailProps["priority"]] = $priority;
    }

    /**
     * Adds the recipients to an email message from a RFC822 message headers.
     *
     * Enter description here ...
     * @param MIMEMessageHeader $headers
     * @param MAPIMessage $mapimessage
     */
    private function addRecipients($headers, &$mapimessage) {
        $toaddr = $ccaddr = $bccaddr = array();

        $Mail_RFC822 = new Mail_RFC822();
        if(isset($headers["to"]))
            $toaddr = $Mail_RFC822->parseAddressList($headers["to"]);
        if(isset($headers["cc"]))
            $ccaddr = $Mail_RFC822->parseAddressList($headers["cc"]);
        if(isset($headers["bcc"]))
            $bccaddr = $Mail_RFC822->parseAddressList($headers["bcc"]);

        if(empty($toaddr))
            throw new StatusException(sprintf("ZarafaBackend->SendMail(): 'To' address in RFC822 message not found or unparsable. To header: '%s'", ((isset($headers["to"]))?$headers["to"]:'')), SYNC_COMMONSTATUS_MESSHASNORECIP);

        // Add recipients
        $recips = array();
        foreach(array(MAPI_TO => $toaddr, MAPI_CC => $ccaddr, MAPI_BCC => $bccaddr) as $type => $addrlist) {
            foreach($addrlist as $addr) {
                $mapirecip[PR_ADDRTYPE] = "SMTP";
                $mapirecip[PR_EMAIL_ADDRESS] = $addr->mailbox . "@" . $addr->host;
                if(isset($addr->personal) && strlen($addr->personal) > 0)
                    $mapirecip[PR_DISPLAY_NAME] = u2wi($addr->personal);
                else
                    $mapirecip[PR_DISPLAY_NAME] = $mapirecip[PR_EMAIL_ADDRESS];

                $mapirecip[PR_RECIPIENT_TYPE] = $type;
                $mapirecip[PR_ENTRYID] = mapi_createoneoff($mapirecip[PR_DISPLAY_NAME], $mapirecip[PR_ADDRTYPE], $mapirecip[PR_EMAIL_ADDRESS]);

                array_push($recips, $mapirecip);
            }
        }

        mapi_message_modifyrecipients($mapimessage, 0, $recips);
    }

   /**
    * Function will create a search folder in FINDER_ROOT folder
    * if folder exists then it will open it
    *
    * @see createSearchFolder($store, $openIfExists = true) function in the webaccess
    *
    * @return mapiFolderObject $folder created search folder
    */
    private function getSearchFolder() {
        // create new or open existing search folder
        $searchFolderRoot = $this->getSearchFoldersRoot($this->store);
        if($searchFolderRoot === false) {
            // error in finding search root folder
            // or store doesn't support search folders
            return false;
        }

        $searchFolder = $this->createSearchFolder($searchFolderRoot);

        if($searchFolder !== false && mapi_last_hresult() == NOERROR) {
            return $searchFolder;
        }
        return false;
    }

   /**
    * Function will open FINDER_ROOT folder in root container
    * public folder's don't have FINDER_ROOT folder
    *
    * @see getSearchFoldersRoot($store) function in the webaccess
    *
    * @return mapiFolderObject root folder for search folders
    */
    private function getSearchFoldersRoot() {
        // check if we can create search folders
        $storeProps = mapi_getprops($this->store, array(PR_STORE_SUPPORT_MASK, PR_FINDER_ENTRYID));
        if(($storeProps[PR_STORE_SUPPORT_MASK] & STORE_SEARCH_OK) != STORE_SEARCH_OK) {
            ZLog::Write(LOGLEVEL_WARN, "Store doesn't support search folders. Public store doesn't have FINDER_ROOT folder");
            return false;
        }

        // open search folders root
        $searchRootFolder = mapi_msgstore_openentry($this->store, $storeProps[PR_FINDER_ENTRYID]);
        if(mapi_last_hresult() != NOERROR) {
            ZLog::Write(LOGLEVEL_WARN, sprintf("Unable to open search folder (0x%X)", mapi_last_hresult()));
            return false;
        }

        return $searchRootFolder;
    }


    /**
     * Creates a search folder if it not exists or opens an existing one
     * and returns it.
     *
     * @param mapiFolderObject $searchFolderRoot
     *
     * @return mapiFolderObject
     */
    private function createSearchFolder($searchFolderRoot) {
        $folderName = "Z-Push Search Folder";
        $searchFolders = mapi_folder_gethierarchytable($searchFolderRoot);
        $restriction = array(
            RES_CONTENT,
            array(
                    FUZZYLEVEL      => FL_PREFIX,
                    ULPROPTAG       => PR_DISPLAY_NAME,
                    VALUE           => array(PR_DISPLAY_NAME=>$folderName)
            )
        );
        //restrict the hierarchy to the z-push search folder only
        mapi_table_restrict($searchFolders, $restriction);
        if (mapi_table_getrowcount($searchFolders)) {
            $searchFolder = mapi_table_queryrows($searchFolders, array(PR_ENTRYID), 0, 1);

            return mapi_msgstore_openentry($this->store, $searchFolder[0][PR_ENTRYID]);
        }
        return mapi_folder_createfolder($searchFolderRoot, $folderName, null, 0, FOLDER_SEARCH);
    }

    /**
     * Creates a search restriction
     *
     * @param string $searchText
     * @return array
     */
    private function getSearchRestriction($searchText) {
        // only search emails
        $mapiquery =
//             array (RES_AND,
//                 array(
//                     array (RES_AND,
//                          array(
//                             array(RES_EXIST, array(ULPROPTAG => PR_MESSAGE_CLASS)),
//                             array(RES_CONTENT, array(FUZZYLEVEL => (FL_SUBSTRING | FL_IGNORECASE), ULPROPTAG => PR_MESSAGE_CLASS, VALUE => array(PR_MESSAGE_CLASS => "IPM.Note"))),
//                         ),
//                     ), // RES_AND
//                 ),
                array (RES_OR,
                    array (
                        array (RES_AND,
                             array(
                                array(RES_EXIST, array(ULPROPTAG => PR_BODY)),
                                array(RES_CONTENT, array(FUZZYLEVEL => (FL_SUBSTRING | FL_IGNORECASE), ULPROPTAG => PR_BODY, VALUE => array(PR_BODY => u2w($searchText)))),
                            ),
                        ), // RES_AND
                        array (RES_AND,
                            array(
                                array(RES_EXIST, array(ULPROPTAG => PR_SUBJECT)),
                                array(RES_CONTENT, array(FUZZYLEVEL => (FL_SUBSTRING | FL_IGNORECASE), ULPROPTAG => PR_SUBJECT, VALUE => array(PR_SUBJECT => u2w($searchText)))),
                            ),
                        ), // RES_AND
                        array (RES_AND,
                            array(
                                array(RES_EXIST, array(ULPROPTAG => PR_DISPLAY_TO)),
                                array(RES_CONTENT, array(FUZZYLEVEL => (FL_SUBSTRING | FL_IGNORECASE), ULPROPTAG => PR_DISPLAY_TO, VALUE => array(PR_DISPLAY_TO => u2w($searchText)))),
                            ),
                        ), // RES_AND
                        array (RES_AND,
                            array(
                                array(RES_EXIST, array(ULPROPTAG => PR_DISPLAY_CC)),
                                array(RES_CONTENT, array(FUZZYLEVEL => (FL_SUBSTRING | FL_IGNORECASE), ULPROPTAG => PR_DISPLAY_CC, VALUE => array(PR_DISPLAY_CC => u2w($searchText)))),
                            ),
                        ), // RES_AND
                        array (RES_AND,
                            array(
                                array(RES_EXIST, array(ULPROPTAG => PR_SENDER_NAME)),
                                array(RES_CONTENT, array(FUZZYLEVEL => (FL_SUBSTRING | FL_IGNORECASE), ULPROPTAG => PR_SENDER_NAME, VALUE => array(PR_SENDER_NAME => u2w($searchText)))),
                            ),
                        ), // RES_AND
                        array (RES_AND,
                            array(
                                array(RES_EXIST, array(ULPROPTAG => PR_SENDER_EMAIL_ADDRESS)),
                                array(RES_CONTENT, array(FUZZYLEVEL => (FL_SUBSTRING | FL_IGNORECASE), ULPROPTAG => PR_SENDER_EMAIL_ADDRESS, VALUE => array(PR_SENDER_EMAIL_ADDRESS => u2w($searchText)))),
                            ),
                        ), // RES_AND
                        array (RES_AND,
                            array(
                                array(RES_EXIST, array(ULPROPTAG => PR_SENT_REPRESENTING_NAME)),
                                array(RES_CONTENT, array(FUZZYLEVEL => (FL_SUBSTRING | FL_IGNORECASE), ULPROPTAG => PR_SENT_REPRESENTING_NAME, VALUE => array(PR_SENT_REPRESENTING_NAME => u2w($searchText)))),
                            ),
                        ), // RES_AND
                        array (RES_AND,
                            array(
                                array(RES_EXIST, array(ULPROPTAG => PR_SENT_REPRESENTING_EMAIL_ADDRESS)),
                                array(RES_CONTENT, array(FUZZYLEVEL => (FL_SUBSTRING | FL_IGNORECASE), ULPROPTAG => PR_SENT_REPRESENTING_EMAIL_ADDRESS, VALUE => array(PR_SENT_REPRESENTING_EMAIL_ADDRESS => u2w($searchText)))),
                            ),
                        ), // RES_AND
                    )
//                 ) // RES_OR
            );// RES_AND

        return $mapiquery;
    }
}

/**
 * DEPRECATED legacy class
 */
class BackendICS extends BackendZarafa {}

?>