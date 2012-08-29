<?php
/***********************************************
* File      :   imap.php
* Project   :   Z-Push
* Descr     :   This backend is based on
*               'BackendDiff' and implements an
*               IMAP interface
*
* Created   :   10.10.2007
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

include_once('lib/default/diffbackend/diffbackend.php');
include_once('include/mimeDecode.php');
require_once('include/z_RFC822.php');


class BackendIMAP extends BackendDiff {
    protected $wasteID;
    protected $sentID;
    protected $server;
    protected $mbox;
    protected $mboxFolder;
    protected $username;
    protected $domain;
    protected $serverdelimiter;
    protected $sinkfolders;
    protected $sinkstates;

    /**----------------------------------------------------------------------------------------------------------
     * default backend methods
     */

    /**
     * Authenticates the user
     *
     * @param string        $username
     * @param string        $domain
     * @param string        $password
     *
     * @access public
     * @return boolean
     * @throws FatalException   if php-imap module can not be found
     */
    public function Logon($username, $domain, $password) {
        $this->wasteID = false;
        $this->sentID = false;
        $this->server = "{" . IMAP_SERVER . ":" . IMAP_PORT . "/imap" . IMAP_OPTIONS . "}";

        if (!function_exists("imap_open"))
            throw new FatalException("BackendIMAP(): php-imap module is not installed", 0, null, LOGLEVEL_FATAL);

        // open the IMAP-mailbox
        $this->mbox = @imap_open($this->server , $username, $password, OP_HALFOPEN);
        $this->mboxFolder = "";

        if ($this->mbox) {
            ZLog::Write(LOGLEVEL_INFO, sprintf("BackendIMAP->Logon(): User '%s' is authenticated on IMAP",$username));
            $this->username = $username;
            $this->domain = $domain;
            // set serverdelimiter
            $this->serverdelimiter = $this->getServerDelimiter();
            return true;
        }
        else {
            ZLog::Write(LOGLEVEL_ERROR, "BackendIMAP->Logon(): can't connect: " . imap_last_error());
            return false;
        }
    }

    /**
     * Logs off
     * Called before shutting down the request to close the IMAP connection
     * writes errors to the log
     *
     * @access public
     * @return boolean
     */
    public function Logoff() {
        if ($this->mbox) {
            // list all errors
            $errors = imap_errors();
            if (is_array($errors)) {
                foreach ($errors as $e)
                    if (stripos($e, "fail") !== false)
                        $level = LOGLEVEL_WARN;
                    else
                        $level = LOGLEVEL_DEBUG;

                    ZLog::Write($level, "BackendIMAP->Logoff(): IMAP said: " . $e);
            }
            @imap_close($this->mbox);
            ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->Logoff(): IMAP connection closed");
        }
        $this->SaveStorages();
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
    // TODO implement , $saveInSent = true
    public function SendMail($sm) {
        $forward = $reply = (isset($sm->source->itemid) && $sm->source->itemid) ? $sm->source->itemid : false;
        $parent = false;

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("IMAPBackend->SendMail(): RFC822: %d bytes  forward-id: '%s' reply-id: '%s' parent-id: '%s' SaveInSent: '%s' ReplaceMIME: '%s'",
                                            strlen($sm->mime), Utils::PrintAsString($sm->forwardflag), Utils::PrintAsString($sm->replyflag),
                                            Utils::PrintAsString((isset($sm->source->folderid) ? $sm->source->folderid : false)),
                                            Utils::PrintAsString(($sm->saveinsent)), Utils::PrintAsString(isset($sm->replacemime)) ));

        if (isset($sm->source->folderid) && $sm->source->folderid)
            // convert parent folder id back to work on an imap-id
            $parent = $this->getImapIdFromFolderId($sm->source->folderid);


        // by splitting the message in several lines we can easily grep later
        foreach(preg_split("/((\r)?\n)/", $sm->mime) as $rfc822line)
            ZLog::Write(LOGLEVEL_WBXML, "RFC822: ". $rfc822line);

        $mobj = new Mail_mimeDecode($sm->mime);
        $message = $mobj->decode(array('decode_headers' => false, 'decode_bodies' => true, 'include_bodies' => true, 'charset' => 'utf-8'));

        $Mail_RFC822 = new Mail_RFC822();
        $toaddr = $ccaddr = $bccaddr = "";
        if(isset($message->headers["to"]))
            $toaddr = $this->parseAddr($Mail_RFC822->parseAddressList($message->headers["to"]));
        if(isset($message->headers["cc"]))
            $ccaddr = $this->parseAddr($Mail_RFC822->parseAddressList($message->headers["cc"]));
        if(isset($message->headers["bcc"]))
            $bccaddr = $this->parseAddr($Mail_RFC822->parseAddressList($message->headers["bcc"]));

        // save some headers when forwarding mails (content type & transfer-encoding)
        $headers = "";
        $forward_h_ct = "";
        $forward_h_cte = "";
        $envelopefrom = "";

        $use_orgbody = false;

        // clean up the transmitted headers
        // remove default headers because we are using imap_mail
        $changedfrom = false;
        $returnPathSet = false;
        $body_base64 = false;
        $org_charset = "";
        $org_boundary = false;
        $multipartmixed = false;
        foreach($message->headers as $k => $v) {
            if ($k == "subject" || $k == "to" || $k == "cc" || $k == "bcc")
                continue;

            if ($k == "content-type") {
                // if the message is a multipart message, then we should use the sent body
                if (preg_match("/multipart/i", $v)) {
                    $use_orgbody = true;
                    $org_boundary = $message->ctype_parameters["boundary"];
                }

                // save the original content-type header for the body part when forwarding
                if ($sm->forwardflag && !$use_orgbody) {
                    $forward_h_ct = $v;
                    continue;
                }

                // set charset always to utf-8
                $org_charset = $v;
                $v = preg_replace("/charset=([A-Za-z0-9-\"']+)/", "charset=\"utf-8\"", $v);
            }

            if ($k == "content-transfer-encoding") {
                // if the content was base64 encoded, encode the body again when sending
                if (trim($v) == "base64") $body_base64 = true;

                // save the original encoding header for the body part when forwarding
                if ($sm->forwardflag) {
                    $forward_h_cte = $v;
                    continue;
                }
            }

            // check if "from"-header is set, do nothing if it's set
            // else set it to IMAP_DEFAULTFROM
            if ($k == "from") {
                if (trim($v)) {
                    $changedfrom = true;
                } elseif (! trim($v) && IMAP_DEFAULTFROM) {
                    $changedfrom = true;
                    if      (IMAP_DEFAULTFROM == 'username') $v = $this->username;
                    else if (IMAP_DEFAULTFROM == 'domain')   $v = $this->domain;
                    else $v = $this->username . IMAP_DEFAULTFROM;
                    $envelopefrom = "-f$v";
                }
            }

            // check if "Return-Path"-header is set
            if ($k == "return-path") {
                $returnPathSet = true;
                if (! trim($v) && IMAP_DEFAULTFROM) {
                    if      (IMAP_DEFAULTFROM == 'username') $v = $this->username;
                    else if (IMAP_DEFAULTFROM == 'domain')   $v = $this->domain;
                    else $v = $this->username . IMAP_DEFAULTFROM;
                }
            }

            // all other headers stay
            if ($headers) $headers .= "\n";
            $headers .= ucfirst($k) . ": ". $v;
        }

        // set "From" header if not set on the device
        if(IMAP_DEFAULTFROM && !$changedfrom){
            if      (IMAP_DEFAULTFROM == 'username') $v = $this->username;
            else if (IMAP_DEFAULTFROM == 'domain')   $v = $this->domain;
            else $v = $this->username . IMAP_DEFAULTFROM;
            if ($headers) $headers .= "\n";
            $headers .= 'From: '.$v;
            $envelopefrom = "-f$v";
        }

        // set "Return-Path" header if not set on the device
        if(IMAP_DEFAULTFROM && !$returnPathSet){
            if      (IMAP_DEFAULTFROM == 'username') $v = $this->username;
            else if (IMAP_DEFAULTFROM == 'domain')   $v = $this->domain;
            else $v = $this->username . IMAP_DEFAULTFROM;
            if ($headers) $headers .= "\n";
            $headers .= 'Return-Path: '.$v;
        }

        // if this is a multipart message with a boundary, we must use the original body
        if ($use_orgbody) {
            list(,$body) = $mobj->_splitBodyHeader($sm->mime);
            $repl_body = $this->getBody($message);
        }
        else
            $body = $this->getBody($message);

        // reply
        if ($sm->replyflag && $parent) {
            $this->imap_reopenFolder($parent);
            // receive entire mail (header + body) to decode body correctly
            $origmail = @imap_fetchheader($this->mbox, $reply, FT_UID) . @imap_body($this->mbox, $reply, FT_PEEK | FT_UID);
            if (!$origmail)
                throw new StatusException(sprintf("BackendIMAP->SendMail(): Could not open message id '%s' in folder id '%s' to be replied: %s", $reply, $parent, imap_last_error()), SYNC_COMMONSTATUS_ITEMNOTFOUND);

            $mobj2 = new Mail_mimeDecode($origmail);
            // receive only body
            $body .= $this->getBody($mobj2->decode(array('decode_headers' => false, 'decode_bodies' => true, 'include_bodies' => true, 'charset' => 'utf-8')));
            // unset mimedecoder & origmail - free memory
            unset($mobj2);
            unset($origmail);
        }

        // encode the body to base64 if it was sent originally in base64 by the pda
        // contrib - chunk base64 encoded body
        if ($body_base64 && !$sm->forwardflag) $body = chunk_split(base64_encode($body));


        // forward
        if ($sm->forwardflag && $parent) {
            $this->imap_reopenFolder($parent);
            // receive entire mail (header + body)
            $origmail = @imap_fetchheader($this->mbox, $forward, FT_UID) . @imap_body($this->mbox, $forward, FT_PEEK | FT_UID);

            if (!$origmail)
                throw new StatusException(sprintf("BackendIMAP->SendMail(): Could not open message id '%s' in folder id '%s' to be forwarded: %s", $forward, $parent, imap_last_error()), SYNC_COMMONSTATUS_ITEMNOTFOUND);

            if (!defined('IMAP_INLINE_FORWARD') || IMAP_INLINE_FORWARD === false) {
                // contrib - chunk base64 encoded body
                if ($body_base64) $body = chunk_split(base64_encode($body));
                //use original boundary if it's set
                $boundary = ($org_boundary) ? $org_boundary : false;
                // build a new mime message, forward entire old mail as file
                list($aheader, $body) = $this->mail_attach("forwarded_message.eml",strlen($origmail),$origmail, $body, $forward_h_ct, $forward_h_cte,$boundary);
                // add boundary headers
                $headers .= "\n" . $aheader;

            }
            else {
                $mobj2 = new Mail_mimeDecode($origmail);
                $mess2 = $mobj2->decode(array('decode_headers' => true, 'decode_bodies' => true, 'include_bodies' => true, 'charset' => 'utf-8'));

                if (!$use_orgbody)
                    $nbody = $body;
                else
                    $nbody = $repl_body;

                $nbody .= "\r\n\r\n";
                $nbody .= "-----Original Message-----\r\n";
                if(isset($mess2->headers['from']))
                    $nbody .= "From: " . $mess2->headers['from'] . "\r\n";
                if(isset($mess2->headers['to']) && strlen($mess2->headers['to']) > 0)
                    $nbody .= "To: " . $mess2->headers['to'] . "\r\n";
                if(isset($mess2->headers['cc']) && strlen($mess2->headers['cc']) > 0)
                    $nbody .= "Cc: " . $mess2->headers['cc'] . "\r\n";
                if(isset($mess2->headers['date']))
                    $nbody .= "Sent: " . $mess2->headers['date'] . "\r\n";
                if(isset($mess2->headers['subject']))
                    $nbody .= "Subject: " . $mess2->headers['subject'] . "\r\n";
                $nbody .= "\r\n";
                $nbody .= $this->getBody($mess2);

                if ($body_base64) {
                    // contrib - chunk base64 encoded body
                    $nbody = chunk_split(base64_encode($nbody));
                    if ($use_orgbody)
                    // contrib - chunk base64 encoded body
                        $repl_body = chunk_split(base64_encode($repl_body));
                }

                if ($use_orgbody) {
                    ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): -------------------");
                    ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): old:\n'$repl_body'\nnew:\n'$nbody'\nund der body:\n'$body'");
                    //$body is quoted-printable encoded while $repl_body and $nbody are plain text,
                    //so we need to decode $body in order replace to take place
                    $body = str_replace($repl_body, $nbody, quoted_printable_decode($body));
                }
                else
                    $body = $nbody;


                if(isset($mess2->parts)) {
                    $attached = false;

                    if ($org_boundary) {
                        $att_boundary = $org_boundary;
                        // cut end boundary from body
                        $body = substr($body, 0, strrpos($body, "--$att_boundary--"));
                    }
                    else {
                        $att_boundary = strtoupper(md5(uniqid(time())));
                        // add boundary headers
                        $headers .= "\n" . "Content-Type: multipart/mixed; boundary=$att_boundary";
                        $multipartmixed = true;
                    }

                    foreach($mess2->parts as $part) {
                        if(isset($part->disposition) && ($part->disposition == "attachment" || $part->disposition == "inline")) {

                            if(isset($part->d_parameters['filename']))
                                $attname = $part->d_parameters['filename'];
                            else if(isset($part->ctype_parameters['name']))
                                $attname = $part->ctype_parameters['name'];
                            else if(isset($part->headers['content-description']))
                                $attname = $part->headers['content-description'];
                            else $attname = "unknown attachment";

                            // ignore html content
                            if ($part->ctype_primary == "text" && $part->ctype_secondary == "html") {
                                continue;
                            }
                            //
                            if ($use_orgbody || $attached) {
                                $body .= $this->enc_attach_file($att_boundary, $attname, strlen($part->body),$part->body, $part->ctype_primary ."/". $part->ctype_secondary);
                            }
                            // first attachment
                            else {
                                $encmail = $body;
                                $attached = true;
                                $body = $this->enc_multipart($att_boundary, $body, $forward_h_ct, $forward_h_cte);
                                $body .= $this->enc_attach_file($att_boundary, $attname, strlen($part->body),$part->body, $part->ctype_primary ."/". $part->ctype_secondary);
                            }
                        }
                    }
                    if ($multipartmixed && strpos(strtolower($mess2->headers['content-type']), "alternative") !== false) {
                        //this happens if a multipart/alternative message is forwarded
                        //then it's a multipart/mixed message which consists of:
                        //1. text/plain part which was written on the mobile
                        //2. multipart/alternative part which is the original message
                        $body = "This is a message with multiple parts in MIME format.\n--".
                                $att_boundary.
                                "\nContent-Type: $forward_h_ct\nContent-Transfer-Encoding: $forward_h_cte\n\n".
                                (($body_base64) ? chunk_split(base64_encode($message->body)) : rtrim($message->body)).
                                "\n--".$att_boundary.
                                "\nContent-Type: {$mess2->headers['content-type']}\n\n".
                                @imap_body($this->mbox, $forward, FT_PEEK | FT_UID)."\n\n";
                    }
                    $body .= "--$att_boundary--\n\n";
                }

                unset($mobj2);
            }

            // unset origmail - free memory
            unset($origmail);

        }

        // remove carriage-returns from body
        $body = str_replace("\r\n", "\n", $body);

        if (!$multipartmixed) {
            if (!empty($forward_h_ct)) $headers .= "\nContent-Type: $forward_h_ct";
            if (!empty($forward_h_cte)) $headers .= "\nContent-Transfer-Encoding: $forward_h_cte";
        //  if body was quoted-printable, convert it again
            if (isset($message->headers["content-transfer-encoding"]) && strtolower($message->headers["content-transfer-encoding"]) == "quoted-printable") {
                $body = quoted_printable_encode($body);
            }
        }

        // more debugging
        ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): parsed message: ". print_r($message,1));
        ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): headers: $headers");
        ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): subject: {$message->headers["subject"]}");
        ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): body: $body");

        if (!defined('IMAP_USE_IMAPMAIL') || IMAP_USE_IMAPMAIL == true) {
            $send =  @imap_mail ( $toaddr, $message->headers["subject"], $body, $headers, $ccaddr, $bccaddr);
        }
        else {
            if (!empty($ccaddr))  $headers .= "\nCc: $ccaddr";
            if (!empty($bccaddr)) $headers .= "\nBcc: $bccaddr";
            $send =  @mail ( $toaddr, $message->headers["subject"], $body, $headers, $envelopefrom );
        }

        // email sent?
        if (!$send)
            throw new StatusException(sprintf("BackendIMAP->SendMail(): The email could not be sent. Last IMAP-error: %s", imap_last_error()), SYNC_COMMONSTATUS_MAILSUBMISSIONFAILED);

        // add message to the sent folder
        // build complete headers
        $headers .= "\nTo: $toaddr";
        $headers .= "\nSubject: " . $message->headers["subject"];

        if (!defined('IMAP_USE_IMAPMAIL') || IMAP_USE_IMAPMAIL == true) {
            if (!empty($ccaddr))  $headers .= "\nCc: $ccaddr";
            if (!empty($bccaddr)) $headers .= "\nBcc: $bccaddr";
        }
        ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): complete headers: $headers");

        $asf = false;
        if ($this->sentID) {
            $asf = $this->addSentMessage($this->sentID, $headers, $body);
        }
        else if (IMAP_SENTFOLDER) {
            $asf = $this->addSentMessage(IMAP_SENTFOLDER, $headers, $body);
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->SendMail(): Outgoing mail saved in configured 'Sent' folder '%s': %s", IMAP_SENTFOLDER, Utils::PrintAsString($asf)));
        }
        // No Sent folder set, try defaults
        else {
            ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): No Sent mailbox set");
            if($this->addSentMessage("INBOX.Sent", $headers, $body)) {
                ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): Outgoing mail saved in 'INBOX.Sent'");
                $asf = true;
            }
            else if ($this->addSentMessage("Sent", $headers, $body)) {
                ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): Outgoing mail saved in 'Sent'");
                $asf = true;
            }
            else if ($this->addSentMessage("Sent Items", $headers, $body)) {
                ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail():IMAP-SendMail: Outgoing mail saved in 'Sent Items'");
                $asf = true;
            }
        }

        if (!$asf) {
            ZLog::Write(LOGLEVEL_ERROR, "BackendIMAP->SendMail(): The email could not be saved to Sent Items folder. Check your configuration.");
        }

        return $send;
    }

    /**
     * Returns the waste basket
     *
     * @access public
     * @return string
     */
    public function GetWasteBasket() {
        // TODO this could be retrieved from the DeviceFolderCache
        if ($this->wasteID == false) {
            //try to get the waste basket without doing complete hierarchy sync
            $wastebaskt = @imap_getmailboxes($this->mbox, $this->server, "Trash");
            if (isset($wastebaskt[0])) {
                $this->wasteID = $this->convertImapId(substr($wastebaskt[0]->name, strlen($this->server)));
                return $this->wasteID;
            }
            //try get waste id from hierarchy if it wasn't possible with above for some reason
            $this->GetHierarchy();
        }
        return $this->wasteID;
    }

    /**
     * Returns the content of the named attachment as stream. The passed attachment identifier is
     * the exact string that is returned in the 'AttName' property of an SyncAttachment.
     * Any information necessary to find the attachment must be encoded in that 'attname' property.
     * Data is written directly (with print $data;)
     *
     * @param string        $attname
     *
     * @access public
     * @return SyncItemOperationsAttachment
     * @throws StatusException
     */
    public function GetAttachmentData($attname) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetAttachmentData('%s')", $attname));

        list($folderid, $id, $part) = explode(":", $attname);

        if (!$folderid || !$id || !$part)
            throw new StatusException(sprintf("BackendIMAP->GetAttachmentData('%s'): Error, attachment name key can not be parsed", $attname), SYNC_ITEMOPERATIONSSTATUS_INVALIDATT);

        // convert back to work on an imap-id
        $folderImapid = $this->getImapIdFromFolderId($folderid);

        $this->imap_reopenFolder($folderImapid);
        $mail = @imap_fetchheader($this->mbox, $id, FT_UID) . @imap_body($this->mbox, $id, FT_PEEK | FT_UID);

        $mobj = new Mail_mimeDecode($mail);
        $message = $mobj->decode(array('decode_headers' => true, 'decode_bodies' => true, 'include_bodies' => true, 'charset' => 'utf-8'));

        if (!isset($message->parts[$part]->body))
            throw new StatusException(sprintf("BackendIMAP->GetAttachmentData('%s'): Error, requested part key can not be found: '%d'", $attname, $part), SYNC_ITEMOPERATIONSSTATUS_INVALIDATT);

        // unset mimedecoder & mail
        unset($mobj);
        unset($mail);

        include_once('include/stringstreamwrapper.php');
        $attachment = new SyncItemOperationsAttachment();
        $attachment->data = StringStreamWrapper::Open($message->parts[$part]->body);
        if (isset($message->parts[$part]->ctype_primary) && isset($message->parts[$part]->ctype_secondary))
            $attachment->contenttype = $message->parts[$part]->ctype_primary .'/'.$message->parts[$part]->ctype_secondary;

        return $attachment;
    }

    /**
     * Indicates if the backend has a ChangesSink.
     * A sink is an active notification mechanism which does not need polling.
     * The IMAP backend simulates a sink by polling status information of the folder
     *
     * @access public
     * @return boolean
     */
    public function HasChangesSink() {
        $this->sinkfolders = array();
        $this->sinkstates = array();
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
     * @return boolean      false if found can not be found
     */
    public function ChangesSinkInitialize($folderid) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("IMAPBackend->ChangesSinkInitialize(): folderid '%s'", $folderid));

        $imapid = $this->getImapIdFromFolderId($folderid);

        if ($imapid) {
            $this->sinkfolders[] = $imapid;
            return true;
        }

        return false;
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
        $stopat = time() + $timeout - 1;

        while($stopat > time() && empty($notifications)) {
            foreach ($this->sinkfolders as $imapid) {
                $this->imap_reopenFolder($imapid);

                // courier-imap only cleares the status cache after checking
                @imap_check($this->mbox);

                $status = @imap_status($this->mbox, $this->server . $imapid, SA_ALL);
                if (!$status) {
                    ZLog::Write(LOGLEVEL_WARN, sprintf("ChangesSink: could not stat folder '%s': %s ", $this->getFolderIdFromImapId($imapid), imap_last_error()));
                }
                else {
                    $newstate = "M:". $status->messages ."-R:". $status->recent ."-U:". $status->unseen;

                    if (! isset($this->sinkstates[$imapid]) )
                        $this->sinkstates[$imapid] = $newstate;

                    if ($this->sinkstates[$imapid] != $newstate) {
                        $notifications[] = $this->getFolderIdFromImapId($imapid);
                        $this->sinkstates[$imapid] = $newstate;
                    }
                }
            }

            if (empty($notifications))
                sleep(5);
        }

        return $notifications;
    }


    /**----------------------------------------------------------------------------------------------------------
     * implemented DiffBackend methods
     */


    /**
     * Returns a list (array) of folders.
     *
     * @access public
     * @return array/boolean        false if the list could not be retrieved
     */
    public function GetFolderList() {
        $folders = array();

        $list = @imap_getmailboxes($this->mbox, $this->server, "*");
        if (is_array($list)) {
            // reverse list to obtain folders in right order
            $list = array_reverse($list);

            foreach ($list as $val) {
                $box = array();
                // cut off serverstring
                $imapid = substr($val->name, strlen($this->server));
                $box["id"] = $this->convertImapId($imapid);

                $fhir = explode($val->delimiter, $imapid);
                if (count($fhir) > 1) {
                    $this->getModAndParentNames($fhir, $box["mod"], $imapparent);
                    $box["parent"] = $this->convertImapId($imapparent);
                }
                else {
                    $box["mod"] = $imapid;
                    $box["parent"] = "0";
                }
                $folders[]=$box;
            }
        }
        else {
            ZLog::Write(LOGLEVEL_WARN, "BackendIMAP->GetFolderList(): imap_list failed: " . imap_last_error());
            return false;
        }

        return $folders;
    }

    /**
     * Returns an actual SyncFolder object
     *
     * @param string        $id           id of the folder
     *
     * @access public
     * @return object       SyncFolder with information
     */
    public function GetFolder($id) {
        $folder = new SyncFolder();
        $folder->serverid = $id;

        // convert back to work on an imap-id
        $imapid = $this->getImapIdFromFolderId($id);

        // explode hierarchy
        $fhir = explode($this->serverdelimiter, $imapid);

        // compare on lowercase strings
        $lid = strtolower($imapid);
// TODO WasteID or SentID could be saved for later ussage
        if($lid == "inbox") {
            $folder->parentid = "0"; // Root
            $folder->displayname = "Inbox";
            $folder->type = SYNC_FOLDER_TYPE_INBOX;
        }
        // Zarafa IMAP-Gateway outputs
        else if($lid == "drafts") {
            $folder->parentid = "0";
            $folder->displayname = "Drafts";
            $folder->type = SYNC_FOLDER_TYPE_DRAFTS;
        }
        else if($lid == "trash") {
            $folder->parentid = "0";
            $folder->displayname = "Trash";
            $folder->type = SYNC_FOLDER_TYPE_WASTEBASKET;
            $this->wasteID = $id;
        }
        else if($lid == "sent" || $lid == "sent items" || $lid == IMAP_SENTFOLDER) {
            $folder->parentid = "0";
            $folder->displayname = "Sent";
            $folder->type = SYNC_FOLDER_TYPE_SENTMAIL;
            $this->sentID = $id;
        }
        // courier-imap outputs and cyrus-imapd outputs
        else if($lid == "inbox.drafts" || $lid == "inbox/drafts") {
            $folder->parentid = $this->convertImapId($fhir[0]);
            $folder->displayname = "Drafts";
            $folder->type = SYNC_FOLDER_TYPE_DRAFTS;
        }
        else if($lid == "inbox.trash" || $lid == "inbox/trash") {
            $folder->parentid = $this->convertImapId($fhir[0]);
            $folder->displayname = "Trash";
            $folder->type = SYNC_FOLDER_TYPE_WASTEBASKET;
            $this->wasteID = $id;
        }
        else if($lid == "inbox.sent" || $lid == "inbox/sent") {
            $folder->parentid = $this->convertImapId($fhir[0]);
            $folder->displayname = "Sent";
            $folder->type = SYNC_FOLDER_TYPE_SENTMAIL;
            $this->sentID = $id;
        }

        // define the rest as other-folders
        else {
            if (count($fhir) > 1) {
                $this->getModAndParentNames($fhir, $folder->displayname, $imapparent);
                $folder->parentid = $this->convertImapId($imapparent);
                $folder->displayname = Utils::Utf7_to_utf8(Utils::Utf7_iconv_decode($folder->displayname));
            }
            else {
                $folder->displayname = Utils::Utf7_to_utf8(Utils::Utf7_iconv_decode($imapid));
                $folder->parentid = "0";
            }
            $folder->type = SYNC_FOLDER_TYPE_OTHER;
        }

        //advanced debugging
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetFolder('%s'): '%s'", $id, $folder));

        return $folder;
    }

    /**
     * Returns folder stats. An associative array with properties is expected.
     *
     * @param string        $id             id of the folder
     *
     * @access public
     * @return array
     */
    public function StatFolder($id) {
        $folder = $this->GetFolder($id);

        $stat = array();
        $stat["id"] = $id;
        $stat["parent"] = $folder->parentid;
        $stat["mod"] = $folder->displayname;

        return $stat;
    }

    /**
     * Creates or modifies a folder
     * The folder type is ignored in IMAP, as all folders are Email folders
     *
     * @param string        $folderid       id of the parent folder
     * @param string        $oldid          if empty -> new folder created, else folder is to be renamed
     * @param string        $displayname    new folder name (to be created, or to be renamed to)
     * @param int           $type           folder type
     *
     * @access public
     * @return boolean                      status
     * @throws StatusException              could throw specific SYNC_FSSTATUS_* exceptions
     *
     */
    public function ChangeFolder($folderid, $oldid, $displayname, $type){
        ZLog::Write(LOGLEVEL_INFO, sprintf("BackendIMAP->ChangeFolder('%s','%s','%s','%s')", $folderid, $oldid, $displayname, $type));

        // go to parent mailbox
        $this->imap_reopenFolder($folderid);

        // build name for new mailboxBackendMaildir
        $displayname = Utils::Utf7_iconv_encode(Utils::Utf8_to_utf7($displayname));
        $newname = $this->server . $folderid . $this->serverdelimiter . $displayname;

        $csts = false;
        // if $id is set => rename mailbox, otherwise create
        if ($oldid) {
            // rename doesn't work properly with IMAP
            // the activesync client doesn't support a 'changing ID'
            // TODO this would be solved by implementing hex ids (Mantis #459)
            //$csts = imap_renamemailbox($this->mbox, $this->server . imap_utf7_encode(str_replace(".", $this->serverdelimiter, $oldid)), $newname);
        }
        else {
            $csts = @imap_createmailbox($this->mbox, $newname);
        }
        if ($csts) {
            return $this->StatFolder($folderid . $this->serverdelimiter . $displayname);
        }
        else
            return false;
    }

    /**
     * Deletes a folder
     *
     * @param string        $id
     * @param string        $parent         is normally false
     *
     * @access public
     * @return boolean                      status - false if e.g. does not exist
     * @throws StatusException              could throw specific SYNC_FSSTATUS_* exceptions
     *
     */
    public function DeleteFolder($id, $parentid){
        // TODO implement
        return false;
    }

    /**
     * Returns a list (array) of messages
     *
     * @param string        $folderid       id of the parent folder
     * @param long          $cutoffdate     timestamp in the past from which on messages should be returned
     *
     * @access public
     * @return array/false  array with messages or false if folder is not available
     */
    public function GetMessageList($folderid, $cutoffdate) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetMessageList('%s','%s')", $folderid, $cutoffdate));

        $folderid = $this->getImapIdFromFolderId($folderid);

        if ($folderid == false)
            throw new StatusException("Folderid not found in cache", SYNC_STATUS_FOLDERHIERARCHYCHANGED);

        $messages = array();
        $this->imap_reopenFolder($folderid, true);

        $sequence = "1:*";
        if ($cutoffdate > 0) {
            $search = @imap_search($this->mbox, "SINCE ". date("d-M-Y", $cutoffdate));
            if ($search !== false)
                $sequence = implode(",", $search);
        }
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetMessageList(): searching with sequence '%s'", $sequence));
        $overviews = @imap_fetch_overview($this->mbox, $sequence);

        if (!$overviews || !is_array($overviews)) {
            ZLog::Write(LOGLEVEL_WARN, sprintf("BackendIMAP->GetMessageList('%s','%s'): Failed to retrieve overview: %s",$folderid, $cutoffdate, imap_last_error()));
            return $messages;
        }

        foreach($overviews as $overview) {
            $date = "";
            $vars = get_object_vars($overview);
            if (array_key_exists( "date", $vars)) {
                // message is out of range for cutoffdate, ignore it
                if ($this->cleanupDate($overview->date) < $cutoffdate) continue;
                $date = $overview->date;
            }

            // cut of deleted messages
            if (array_key_exists( "deleted", $vars) && $overview->deleted)
                continue;

            if (array_key_exists( "uid", $vars)) {
                $message = array();
                $message["mod"] = $date;
                $message["id"] = $overview->uid;
                // 'seen' aka 'read' is the only flag we want to know about
                $message["flags"] = 0;

                if(array_key_exists( "seen", $vars) && $overview->seen)
                    $message["flags"] = 1;

                array_push($messages, $message);
            }
        }
        return $messages;
    }

    /**
     * Returns the actual SyncXXX object type.
     *
     * @param string            $folderid           id of the parent folder
     * @param string            $id                 id of the message
     * @param ContentParameters $contentparameters  parameters of the requested message (truncation, mimesupport etc)
     *
     * @access public
     * @return object/false     false if the message could not be retrieved
     */
    public function GetMessage($folderid, $id, $contentparameters) {
        $truncsize = Utils::GetTruncSize($contentparameters->GetTruncation());
        $mimesupport = $contentparameters->GetMimeSupport();
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetMessage('%s','%s')", $folderid,  $id));

        $folderImapid = $this->getImapIdFromFolderId($folderid);

        // Get flags, etc
        $stat = $this->StatMessage($folderid, $id);

        if ($stat) {
            $this->imap_reopenFolder($folderImapid);
            $mail = @imap_fetchheader($this->mbox, $id, FT_UID) . @imap_body($this->mbox, $id, FT_PEEK | FT_UID);

            $mobj = new Mail_mimeDecode($mail);
            $message = $mobj->decode(array('decode_headers' => true, 'decode_bodies' => true, 'include_bodies' => true, 'charset' => 'utf-8'));

            $output = new SyncMail();

            $body = $this->getBody($message);
            $output->bodysize = strlen($body);

            // truncate body, if requested
            if(strlen($body) > $truncsize) {
                $body = Utils::Utf8_truncate($body, $truncsize);
                $output->bodytruncated = 1;
            } else {
                $body = $body;
                $output->bodytruncated = 0;
            }
            $body = str_replace("\n","\r\n", str_replace("\r","",$body));

            $output->body = $body;
            $output->datereceived = isset($message->headers["date"]) ? $this->cleanupDate($message->headers["date"]) : null;
            $output->messageclass = "IPM.Note";
            $output->subject = isset($message->headers["subject"]) ? $message->headers["subject"] : "";
            $output->read = $stat["flags"];
            $output->from = isset($message->headers["from"]) ? $message->headers["from"] : null;

            $Mail_RFC822 = new Mail_RFC822();
            $toaddr = $ccaddr = $replytoaddr = array();
            if(isset($message->headers["to"]))
                $toaddr = $Mail_RFC822->parseAddressList($message->headers["to"]);
            if(isset($message->headers["cc"]))
                $ccaddr = $Mail_RFC822->parseAddressList($message->headers["cc"]);
            if(isset($message->headers["reply_to"]))
                $replytoaddr = $Mail_RFC822->parseAddressList($message->headers["reply_to"]);

            $output->to = array();
            $output->cc = array();
            $output->reply_to = array();
            foreach(array("to" => $toaddr, "cc" => $ccaddr, "reply_to" => $replytoaddr) as $type => $addrlist) {
                foreach($addrlist as $addr) {
                    $address = $addr->mailbox . "@" . $addr->host;
                    $name = $addr->personal;

                    if (!isset($output->displayto) && $name != "")
                        $output->displayto = $name;

                    if($name == "" || $name == $address)
                        $fulladdr = w2u($address);
                    else {
                        if (substr($name, 0, 1) != '"' && substr($name, -1) != '"') {
                            $fulladdr = "\"" . w2u($name) ."\" <" . w2u($address) . ">";
                        }
                        else {
                            $fulladdr = w2u($name) ." <" . w2u($address) . ">";
                        }
                    }

                    array_push($output->$type, $fulladdr);
                }
            }

            // convert mime-importance to AS-importance
            if (isset($message->headers["x-priority"])) {
                $mimeImportance =  preg_replace("/\D+/", "", $message->headers["x-priority"]);
                if ($mimeImportance > 3)
                    $output->importance = 0;
                if ($mimeImportance == 3)
                    $output->importance = 1;
                if ($mimeImportance < 3)
                    $output->importance = 2;
            }

            // Attachments are only searched in the top-level part
            if(isset($message->parts)) {
                $mparts = $message->parts;
                for ($i=0; $i<count($mparts); $i++) {
                    $part = $mparts[$i];
                    //recursively add parts
                    if($part->ctype_primary == "multipart" && ($part->ctype_secondary == "mixed" || $part->ctype_secondary == "alternative"  || $part->ctype_secondary == "related")) {
                        foreach($part->parts as $spart)
                            $mparts[] = $spart;
                        continue;
                    }
                    //add part as attachment if it's disposition indicates so or if it is not a text part
                    if ((isset($part->disposition) && ($part->disposition == "attachment" || $part->disposition == "inline")) ||
                        (isset($part->ctype_primary) && $part->ctype_primary != "text")) {

                        if (!isset($output->attachments) || !is_array($output->attachments))
                            $output->attachments = array();

                        $attachment = new SyncAttachment();

                        if (isset($part->body))
                            $attachment->attsize = strlen($part->body);

                        if(isset($part->d_parameters['filename']))
                            $attname = $part->d_parameters['filename'];
                        else if(isset($part->ctype_parameters['name']))
                            $attname = $part->ctype_parameters['name'];
                        else if(isset($part->headers['content-description']))
                            $attname = $part->headers['content-description'];
                        else $attname = "unknown attachment";

                        $attachment->displayname = $attname;
                        $attachment->attname = $folderid . ":" . $id . ":" . $i;
                        $attachment->attmethod = 1;
                        $attachment->attoid = isset($part->headers['content-id']) ? $part->headers['content-id'] : "";
                        array_push($output->attachments, $attachment);
                    }

                }
            }
            // unset mimedecoder & mail
            unset($mobj);
            unset($mail);
            return $output;
        }

        return false;
    }

    /**
     * Returns message stats, analogous to the folder stats from StatFolder().
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     *
     * @access public
     * @return array/boolean
     */
    public function StatMessage($folderid, $id) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->StatMessage('%s','%s')", $folderid,  $id));
        $folderImapid = $this->getImapIdFromFolderId($folderid);

        $this->imap_reopenFolder($folderImapid);
        $overview = @imap_fetch_overview( $this->mbox , $id , FT_UID);

        if (!$overview) {
            ZLog::Write(LOGLEVEL_WARN, sprintf("BackendIMAP->StatMessage('%s','%s'): Failed to retrieve overview: %s", $folderid,  $id, imap_last_error()));
            return false;
        }

        // check if variables for this overview object are available
        $vars = get_object_vars($overview[0]);

        // without uid it's not a valid message
        if (! array_key_exists( "uid", $vars)) return false;

        $entry = array();
        $entry["mod"] = (array_key_exists( "date", $vars)) ? $overview[0]->date : "";
        $entry["id"] = $overview[0]->uid;
        // 'seen' aka 'read' is the only flag we want to know about
        $entry["flags"] = 0;

        if(array_key_exists( "seen", $vars) && $overview[0]->seen)
            $entry["flags"] = 1;

        return $entry;
    }

    /**
     * Called when a message has been changed on the mobile.
     * This functionality is not available for emails.
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     * @param SyncXXX       $message        the SyncObject containing a message
     *
     * @access public
     * @return array                        same return value as StatMessage()
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function ChangeMessage($folderid, $id, $message) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->ChangeMessage('%s','%s','%s')", $folderid, $id, get_class($message)));
        // TODO recheck implementation
        // TODO this could throw several StatusExceptions like e.g. SYNC_STATUS_OBJECTNOTFOUND, SYNC_STATUS_SYNCCANNOTBECOMPLETED
        return false;
    }

    /**
     * Changes the 'read' flag of a message on disk
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     * @param int           $flags          read flag of the message
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function SetReadFlag($folderid, $id, $flags) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->SetReadFlag('%s','%s','%s')", $folderid, $id, $flags));
        $folderImapid = $this->getImapIdFromFolderId($folderid);

        $this->imap_reopenFolder($folderImapid);

        if ($flags == 0) {
            // set as "Unseen" (unread)
            $status = @imap_clearflag_full ( $this->mbox, $id, "\\Seen", ST_UID);
        } else {
            // set as "Seen" (read)
            $status = @imap_setflag_full($this->mbox, $id, "\\Seen",ST_UID);
        }

        return $status;
    }

    /**
     * Called when the user has requested to delete (really delete) a message
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function DeleteMessage($folderid, $id) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->DeleteMessage('%s','%s')", $folderid, $id));
        $folderImapid = $this->getImapIdFromFolderId($folderid);

        $this->imap_reopenFolder($folderImapid);
        $s1 = @imap_delete ($this->mbox, $id, FT_UID);
        $s11 = @imap_setflag_full($this->mbox, $id, "\\Deleted", FT_UID);
        $s2 = @imap_expunge($this->mbox);

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->DeleteMessage('%s','%s'): result: s-delete: '%s' s-expunge: '%s' setflag: '%s'", $folderid, $id, $s1, $s2, $s11));

        return ($s1 && $s2 && $s11);
    }

    /**
     * Called when the user moves an item on the PDA from one folder to another
     *
     * @param string        $folderid       id of the source folder
     * @param string        $id             id of the message
     * @param string        $newfolderid    id of the destination folder
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_MOVEITEMSSTATUS_* exceptions
     */
    public function MoveMessage($folderid, $id, $newfolderid) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->MoveMessage('%s','%s','%s')", $folderid, $id, $newfolderid));
        $folderImapid = $this->getImapIdFromFolderId($folderid);
        $newfolderImapid = $this->getImapIdFromFolderId($newfolderid);


        $this->imap_reopenFolder($folderImapid);

        // TODO this should throw a StatusExceptions on errors like SYNC_MOVEITEMSSTATUS_SAMESOURCEANDDEST,SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID,SYNC_MOVEITEMSSTATUS_CANNOTMOVE

        // read message flags
        $overview = @imap_fetch_overview ( $this->mbox , $id, FT_UID);

        if (!$overview)
            throw new StatusException(sprintf("BackendIMAP->MoveMessage('%s','%s','%s'): Error, unable to retrieve overview of source message: %s", $folderid, $id, $newfolderid, imap_last_error()), SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID);
        else {
            // get next UID for destination folder
            // when moving a message we have to announce through ActiveSync the new messageID in the
            // destination folder. This is a "guessing" mechanism as IMAP does not inform that value.
            // when lots of simultaneous operations happen in the destination folder this could fail.
            // in the worst case the moved message is displayed twice on the mobile.
            $destStatus = imap_status($this->mbox, $this->server . $newfolderImapid, SA_ALL);
            if (!$destStatus)
                throw new StatusException(sprintf("BackendIMAP->MoveMessage('%s','%s','%s'): Error, unable to open destination folder: %s", $folderid, $id, $newfolderid, imap_last_error()), SYNC_MOVEITEMSSTATUS_INVALIDDESTID);

            $newid = $destStatus->uidnext;

            // move message
            $s1 = imap_mail_move($this->mbox, $id, $newfolderImapid, CP_UID);
            if (! $s1)
                throw new StatusException(sprintf("BackendIMAP->MoveMessage('%s','%s','%s'): Error, copy to destination folder failed: %s", $folderid, $id, $newfolderid, imap_last_error()), SYNC_MOVEITEMSSTATUS_CANNOTMOVE);


            // delete message in from-folder
            $s2 = imap_expunge($this->mbox);

            // open new folder
            $stat = $this->imap_reopenFolder($newfolderImapid);
            if (! $s1)
                throw new StatusException(sprintf("BackendIMAP->MoveMessage('%s','%s','%s'): Error, openeing the destination folder: %s", $folderid, $id, $newfolderid, imap_last_error()), SYNC_MOVEITEMSSTATUS_CANNOTMOVE);


            // remove all flags
            $s3 = @imap_clearflag_full ($this->mbox, $newid, "\\Seen \\Answered \\Flagged \\Deleted \\Draft", FT_UID);
            $newflags = "";
            if ($overview[0]->seen) $newflags .= "\\Seen";
            if ($overview[0]->flagged) $newflags .= " \\Flagged";
            if ($overview[0]->answered) $newflags .= " \\Answered";
            $s4 = @imap_setflag_full ($this->mbox, $newid, $newflags, FT_UID);

            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->MoveMessage('%s','%s','%s'): result s-move: '%s' s-expunge: '%s' unset-Flags: '%s' set-Flags: '%s'", $folderid, $id, $newfolderid, Utils::PrintAsString($s1), Utils::PrintAsString($s2), Utils::PrintAsString($s3), Utils::PrintAsString($s4)));

            // return the new id "as string""
            return $newid . "";
        }
    }


    /**----------------------------------------------------------------------------------------------------------
     * protected IMAP methods
     */

    /**
     * Unmasks a hex folderid and returns the imap folder id
     *
     * @param string        $folderid       hex folderid generated by convertImapId()
     *
     * @access protected
     * @return string       imap folder id
     */
    protected function getImapIdFromFolderId($folderid) {
        $this->InitializePermanentStorage();

        if (isset($this->permanentStorage->fmFidFimap)) {
            if (isset($this->permanentStorage->fmFidFimap[$folderid])) {
                $imapId = $this->permanentStorage->fmFidFimap[$folderid];
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->getImapIdFromFolderId('%s') = %s", $folderid, $imapId));
                return $imapId;
            }
            else {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->getImapIdFromFolderId('%s') = %s", $folderid, 'not found'));
                return false;
            }
        }
        ZLog::Write(LOGLEVEL_WARN, sprintf("BackendIMAP->getImapIdFromFolderId('%s') = %s", $folderid, 'not initialized!'));
        return false;
    }

    /**
     * Retrieves a hex folderid previousily masked imap
     *
     * @param string        $imapid         Imap folder id
     *
     * @access protected
     * @return string       hex folder id
     */
    protected function getFolderIdFromImapId($imapid) {
        $this->InitializePermanentStorage();

        if (isset($this->permanentStorage->fmFimapFid)) {
            if (isset($this->permanentStorage->fmFimapFid[$imapid])) {
                $folderid = $this->permanentStorage->fmFimapFid[$imapid];
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->getFolderIdFromImapId('%s') = %s", $imapid, $folderid));
                return $folderid;
            }
            else {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->getFolderIdFromImapId('%s') = %s", $imapid, 'not found'));
                return false;
            }
        }
        ZLog::Write(LOGLEVEL_WARN, sprintf("BackendIMAP->getFolderIdFromImapId('%s') = %s", $imapid, 'not initialized!'));
        return false;
    }

    /**
     * Masks a imap folder id into a generated hex folderid
     * The method getFolderIdFromImapId() is consulted so that an
     * imapid always returns the same hex folder id
     *
     * @param string        $imapid         Imap folder id
     *
     * @access protected
     * @return string       hex folder id
     */
    protected function convertImapId($imapid) {
        $this->InitializePermanentStorage();

        // check if this imap id was converted before
        $folderid = $this->getFolderIdFromImapId($imapid);

        // nothing found, so generate a new id and put it in the cache
        if (!$folderid) {
            // generate folderid and add it to the mapping
            $folderid = sprintf('%04x%04x', mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ));

            // folderId to folderImap mapping
            if (!isset($this->permanentStorage->fmFidFimap))
                $this->permanentStorage->fmFidFimap = array();

            $a = $this->permanentStorage->fmFidFimap;
            $a[$folderid] = $imapid;
            $this->permanentStorage->fmFidFimap = $a;

            // folderImap to folderid mapping
            if (!isset($this->permanentStorage->fmFimapFid))
                $this->permanentStorage->fmFimapFid = array();

            $b = $this->permanentStorage->fmFimapFid;
            $b[$imapid] = $folderid;
            $this->permanentStorage->fmFimapFid = $b;
        }

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->convertImapId('%s') = %s", $imapid, $folderid));

        return $folderid;
    }


    /**
     * Parses the message and return only the plaintext body
     *
     * @param string        $message        html message
     *
     * @access protected
     * @return string       plaintext message
     */
    protected function getBody($message) {
        $body = "";
        $htmlbody = "";

        $this->getBodyRecursive($message, "plain", $body);

        if($body === "") {
            $this->getBodyRecursive($message, "html", $body);
            // remove css-style tags
            $body = preg_replace("/<style.*?<\/style>/is", "", $body);
            // remove all other html
            $body = strip_tags($body);
        }

        return $body;
    }

    /**
     * Get all parts in the message with specified type and concatenate them together, unless the
     * Content-Disposition is 'attachment', in which case the text is apparently an attachment
     *
     * @param string        $message        mimedecode message(part)
     * @param string        $message        message subtype
     * @param string        &$body          body reference
     *
     * @access protected
     * @return
     */
    protected function getBodyRecursive($message, $subtype, &$body) {
        if(!isset($message->ctype_primary)) return;
        if(strcasecmp($message->ctype_primary,"text")==0 && strcasecmp($message->ctype_secondary,$subtype)==0 && isset($message->body))
            $body .= $message->body;

        if(strcasecmp($message->ctype_primary,"multipart")==0 && isset($message->parts) && is_array($message->parts)) {
            foreach($message->parts as $part) {
                if(!isset($part->disposition) || strcasecmp($part->disposition,"attachment"))  {
                    $this->getBodyRecursive($part, $subtype, $body);
                }
            }
        }
    }

    /**
     * Returns the serverdelimiter for folder parsing
     *
     * @access protected
     * @return string       delimiter
     */
    protected function getServerDelimiter() {
        $list = @imap_getmailboxes($this->mbox, $this->server, "*");
        if (is_array($list)) {
            $val = $list[0];

            return $val->delimiter;
        }
        return "."; // default "."
    }

    /**
     * Helper to re-initialize the folder to speed things up
     * Remember what folder is currently open and only change if necessary
     *
     * @param string        $folderid       id of the folder
     * @param boolean       $force          re-open the folder even if currently opened
     *
     * @access protected
     * @return
     */
    protected function imap_reopenFolder($folderid, $force = false) {
        // to see changes, the folder has to be reopened!
           if ($this->mboxFolder != $folderid || $force) {
               $s = @imap_reopen($this->mbox, $this->server . $folderid);
               // TODO throw status exception
               if (!$s) {
                ZLog::Write(LOGLEVEL_WARN, "BackendIMAP->imap_reopenFolder('%s'): failed to change folder: ",$folderid, implode(", ", imap_errors()));
                return false;
               }
            $this->mboxFolder = $folderid;
        }
    }


    /**
     * Build a multipart RFC822, embedding body and one file (for attachments)
     *
     * @param string        $filenm         name of the file to be attached
     * @param long          $filesize       size of the file to be attached
     * @param string        $file_cont      content of the file
     * @param string        $body           current body
     * @param string        $body_ct        content-type
     * @param string        $body_cte       content-transfer-encoding
     * @param string        $boundary       optional existing boundary
     *
     * @access protected
     * @return array        with [0] => $mail_header and [1] => $mail_body
     */
    protected function mail_attach($filenm,$filesize,$file_cont,$body, $body_ct, $body_cte, $boundary = false) {
        if (!$boundary) $boundary = strtoupper(md5(uniqid(time())));

        //remove the ending boundary because we will add it at the end
        $body = str_replace("--$boundary--", "", $body);

        $mail_header = "Content-Type: multipart/mixed; boundary=$boundary\n";

        // build main body with the sumitted type & encoding from the pda
        $mail_body  = $this->enc_multipart($boundary, $body, $body_ct, $body_cte);
        $mail_body .= $this->enc_attach_file($boundary, $filenm, $filesize, $file_cont);

        $mail_body .= "--$boundary--\n\n";
        return array($mail_header, $mail_body);
    }

    /**
     * Helper for mail_attach()
     *
     * @param string        $boundary       boundary
     * @param string        $body           current body
     * @param string        $body_ct        content-type
     * @param string        $body_cte       content-transfer-encoding
     *
     * @access protected
     * @return string       message body
     */
    protected function enc_multipart($boundary, $body, $body_ct, $body_cte) {
        $mail_body = "This is a multi-part message in MIME format\n\n";
        $mail_body .= "--$boundary\n";
        $mail_body .= "Content-Type: $body_ct\n";
        $mail_body .= "Content-Transfer-Encoding: $body_cte\n\n";
        $mail_body .= "$body\n\n";

        return $mail_body;
    }

    /**
     * Helper for mail_attach()
     *
     * @param string        $boundary       boundary
     * @param string        $filenm         name of the file to be attached
     * @param long          $filesize       size of the file to be attached
     * @param string        $file_cont      content of the file
     * @param string        $content_type   optional content-type
     *
     * @access protected
     * @return string       message body
     */
    protected function enc_attach_file($boundary, $filenm, $filesize, $file_cont, $content_type = "") {
        if (!$content_type) $content_type = "text/plain";
        $mail_body = "--$boundary\n";
        $mail_body .= "Content-Type: $content_type; name=\"$filenm\"\n";
        $mail_body .= "Content-Transfer-Encoding: base64\n";
        $mail_body .= "Content-Disposition: attachment; filename=\"$filenm\"\n";
        $mail_body .= "Content-Description: $filenm\n\n";
        //contrib - chunk base64 encoded attachments
        $mail_body .= chunk_split(base64_encode($file_cont)) . "\n\n";

        return $mail_body;
    }

    /**
     * Adds a message with seen flag to a specified folder (used for saving sent items)
     *
     * @param string        $folderid       id of the folder
     * @param string        $header         header of the message
     * @param long          $body           body of the message
     *
     * @access protected
     * @return boolean      status
     */
    protected function addSentMessage($folderid, $header, $body) {
        $header_body = str_replace("\n", "\r\n", str_replace("\r", "", $header . "\n\n" . $body));

        return @imap_append($this->mbox, $this->server . $folderid, $header_body, "\\Seen");
    }

    /**
     * Parses an mimedecode address array back to a simple "," separated string
     *
     * @param array         $ad             addresses array
     *
     * @access protected
     * @return string       mail address(es) string
     */
    protected function parseAddr($ad) {
        $addr_string = "";
        if (isset($ad) && is_array($ad)) {
            foreach($ad as $addr) {
                if ($addr_string) $addr_string .= ",";
                    $addr_string .= $addr->mailbox . "@" . $addr->host;
            }
        }
        return $addr_string;
    }

    /**
     * Recursive way to get mod and parent - repeat until only one part is left
     * or the folder is identified as an IMAP folder
     *
     * @param string        $fhir           folder hierarchy string
     * @param string        &$displayname   reference of the displayname
     * @param long          &$parent        reference of the parent folder
     *
     * @access protected
     * @return
     */
    protected function getModAndParentNames($fhir, &$displayname, &$parent) {
        // if mod is already set add the previous part to it as it might be a folder which has
        // delimiter in its name
        $displayname = (isset($displayname) && strlen($displayname) > 0) ? $displayname = array_pop($fhir).$this->serverdelimiter.$displayname : array_pop($fhir);
        $parent = implode($this->serverdelimiter, $fhir);

        if (count($fhir) == 1 || $this->checkIfIMAPFolder($parent)) {
            return;
        }
        //recursion magic
        $this->getModAndParentNames($fhir, $displayname, $parent);
    }

    /**
     * Checks if a specified name is a folder in the IMAP store
     *
     * @param string        $foldername     a foldername
     *
     * @access protected
     * @return boolean
     */
    protected function checkIfIMAPFolder($folderName) {
        $parent = imap_list($this->mbox, $this->server, $folderName);
        if ($parent === false) return false;
        return true;
    }

    /**
     * Removes parenthesis (comments) from the date string because
     * strtotime returns false if received date has them
     *
     * @param string        $receiveddate   a date as a string
     *
     * @access protected
     * @return string
     */
    protected function cleanupDate($receiveddate) {
        $receiveddate = strtotime(preg_replace("/\(.*\)/", "", $receiveddate));
        if ($receiveddate == false || $receiveddate == -1) {
            debugLog("Received date is false. Message might be broken.");
            return null;
        }

        return $receiveddate;
    }

}

?>