<?php
    /*
    Kolab Z-Push Backend

    Copyright (C) 2009-2010 Free Software Foundation Europe e.V.

    The main author of the Kolab Z-Push Backend is Alain Abbas, with
    contributions by .......

    This program is Free Software; you can redistribute it and/or
    modify it under the terms of version two of the GNU General Public
    License as published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful, but
    WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
    General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA
    02110-1301, USA.

    The licensor of the Kolab Z-Push Backend is the 
    Free Software Foundation Europe (FSFE), Fiduciary Program, 
    Linienstr. 141, 10115 Berlin, Germany, email:ftf@fsfeurope.org.
    */                                                                    
    //define('KOLABBACKEND_VERSION', 'SVN developpment 20100307');
    define('KOLABBACKEND_VERSION', '0.6');   
    include_once('diffbackend.php');

    // The is an improved version of mimeDecode from PEAR that correctly
    // handles charsets and charset conversion
    include_once('mimeDecode.php');
    include_once('z_RTF.php');
    include_once('z_RFC822.php');
    include_once('Horde/Kolab/Kolab_Zpush/lib/kolabActivesyncData.php');
    require_once 'Horde/Kolab/Format.php';

    class BackendKolab extends BackendDiff {
        private $_server = "";
        private $_username ="";
        private $_domain = "";
        private $_password = "";
        private $_cache;
        private $_deviceType;
        private $_deviceAgent;
        private $hasDefaultEventFolder =false;
        private $hasDefaultContactFolder= false;  
        private $hasDefaultTaskFolder = false;
        private $foMode = false;
        private $_mbox;
        private $_KolabHomeServer;
        private $_cn;
        private $_email;
        private $sentFolder="";
        /* Called to logon a user. These are the three authentication strings that you must
        * specify in ActiveSync on the PDA. Normally you would do some kind of password
        * check here. Alternatively, you could ignore the password here and have Apache
        * do authentication via mod_auth_*
        */ 
        function Logon($username, $domain, $password) {
            $this->_wasteID = false;
            $this->_sentID = false;
            $this->_username = $username;
            $this->_domain = $domain;
            $this->_password = $password; 
            if (!$this->getLdapAccount())
            {
                return false;
            }
            $this->_server = "{" . $this->_KolabHomeServer . ":" . KOLAB_IMAP_PORT . "/imap" . KOLAB_IMAP_OPTIONS . "}"; 
            $this->Log("Connecting to ". $this->_server);
            if (!function_exists("imap_open")) 
            {
                debugLog("ERROR BackendIMAP : PHP-IMAP module not installed!!!!!");
                $this->Log("module PHP imap not installed ")  ;
            }

            // open the IMAP-mailbox 
            $this->_mbox = @imap_open($this->_server , $username, $password, OP_HALFOPEN);
            $this->_mboxFolder = "";

            if ($this->_mbox) {
                debugLog("KolabBackend Version : " . KOLABBACKEND_VERSION);
                debugLog("KolabActiveSyndData Version : " .KOLABACTIVESYNCDATA_VERSION);
                $this->Log("KolabBackend Version : " . KOLABBACKEND_VERSION);
                $this->Log("KolabActiveSyndData Version : " .KOLABACTIVESYNCDATA_VERSION);
                $this->Log("IMAP connection opened sucessfully user : " . $username );
                // set serverdelimiter
                $this->_serverdelimiter = $this->getServerDelimiter();

                return true;
            }
            else {
                $this->Log("IMAP can't connect: " . imap_last_error() . "  user : " . $this->_user . " Mobile ID:" . $this->_devid);
                return false;
            }
        }

        /* Called before shutting down the request to close the IMAP connection
        */
        function Logoff() {
            if ($this->_mbox) {
                // list all errors             
                $errors = imap_errors();
                if (is_array($errors)) {
                    foreach ($errors as $e)    debugLog("IMAP-errors: $e");            
                }             
                @imap_close($this->_mbox);
                debugLog("IMAP connection closed");
                $this->Log("IMAP connection closed");
                unset($this->_cache);
            }
        }

        /* Called directly after the logon. This specifies the client's protocol version
        * and device id. The device ID can be used for various things, including saving
        * per-device state information.
        * The $user parameter here is normally equal to the $username parameter from the
        * Logon() call. In theory though, you could log on a 'foo', and then sync the emails
        * of user 'bar'. The $user here is the username specified in the request URL, while the
        * $username in the Logon() call is the username which was sent as a part of the HTTP 
        * authentication.
        */    
        function Setup($user, $devid, $protocolversion) {
            $this->_user = $user;
            $this->_devid = $devid;
            $this->_protocolversion = $protocolversion;
            if ($devid == "")
            {
                //occurs in the OPTION Command
                return true;
            }
            $this->_deviceType=$_REQUEST["DeviceType"];
            $this->_deviceAgent=$_SERVER["HTTP_USER_AGENT"];
            $this->Log("Setup : " . $user. " Mobile ID :" . $devid. " Proto Version : " . $protocolversion ." DeviceType : ". $this->_deviceType . " DeviceAgent : ". $this->_deviceAgent);
            $this->_cache=new userCache();
            $this->CacheCheckVersion();
            //read globalparam . 
            $gp=$this->kolabReadGlobalParam();

            $mode=KOLAB_MODE;
            if ($gp != false )
            {
                //search if serial already in it;
                if ( $gp->getDeviceType($devid))
                {
                    if ( $gp->getDeviceMode($devid) != -1)
                    {
                        $mode=$gp->getDeviceMode($devid);
                    }
                }
                else
                {
                    //no present we must write it;
                    $gp->setDevice($devid,$this->_deviceType) ;
                    if ( ! $this->kolabWriteGlobalParam($gp))
                    {
                        $this->Log("ERR cant write Globalparam");
                    }
                }
            }
            switch($mode)
            {
                case 0:$this->foMode = false;
                $this->Log("NOTICE : Forced to flatmode") ;
                break;
                case 1:$this->foMode = true;
                $this->Log("NOTICE : Forced to foldermode") ;
                break;  
                case 2:$this->foMode = $this->findMode();
                break;
            }
            return true;
        }

        /* Sends a message which is passed as rfc822. You basically can do two things
        * 1) Send the message to an SMTP server as-is
        * 2) Parse the message yourself, and send it some other way
        * It is up to you whether you want to put the message in the sent items folder. If you
        * want it in 'sent items', then the next sync on the 'sent items' folder should return
        * the new message as any other new message in a folder.
        */
        private function findMode()
        {   
            $type=explode(":",KOLAB_MOBILES_FOLDERMODE);
            if (in_array(strtolower($this->_deviceType),$type))
            {
                $this->Log("NOTICE : findMode Foldermode") ; 
                return 1;
            }
            $this->Log("NOTICE : findMode Flatmode") ;  
            return 0;
        }
        function SendMail($rfc822, $forward = false, $reply = false, $parent = false) {
            debugLog("IMAP-SendMail: " . $rfc822 . "for: $forward   reply: $reply   parent: $parent" );
            //
            $mobj = new Mail_mimeDecode($rfc822);
            $message = $mobj->decode(array('decode_headers' => false, 'decode_bodies' => true, 'include_bodies' => true, 'input' => $rfc822, 'crlf' => "\n", 'charset' => 'utf-8'));

            $toaddr = $ccaddr = $bccaddr = "";
            if(isset($message->headers["to"]))
            $toaddr = $this->parseAddr(Mail_RFC822::parseAddressList($message->headers["to"]));
            if(isset($message->headers["cc"]))
            $ccaddr = $this->parseAddr(Mail_RFC822::parseAddressList($message->headers["cc"]));
            if(isset($message->headers["bcc"]))
            $bccaddr = $this->parseAddr(Mail_RFC822::parseAddressList($message->headers["bcc"]));

            // save some headers when forwarding mails (content type & transfer-encoding)
            $headers = "";
            $forward_h_ct = "";
            $forward_h_cte = "";

            $use_orgbody = false;

            // clean up the transmitted headers
            // remove default headers because we are using imap_mail
            $changedfrom = false;
            $returnPathSet = false;
            $body_base64 = false;
            $org_charset = "";
            foreach($message->headers as $k => $v) {
                if ($k == "subject" || $k == "to" || $k == "cc" || $k == "bcc") 
                continue;

                if ($k == "content-type") {
                    // save the original content-type header for the body part when forwarding 
                    if ($forward) {
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
                    if ($forward) {
                        $forward_h_cte = $v;
                        continue;
                    }
                }

                // if the message is a multipart message, then we should use the sent body 
                if (!$forward && $k == "content-type" && preg_match("/multipart/i", $v)) {
                    $use_orgbody = true;
                }

                // check if "from"-header is set
                if ($k == "from"  ) {
                    $changedfrom = true;
                    if (! trim($v) )
                    {
                        $v = $this->_email;
                    }
                }

                // check if "Return-Path"-header is set
                if ($k == "return-path") {
                    $returnPathSet = true;
                    if (! trim($v) ) {
                        $v = $this->_email;

                    }
                }

                // all other headers stay                             
                if ($headers) $headers .= "\n";
                $headers .= ucfirst($k) . ": ". $v;
            }
            
            // set "From" header if not set on the device
            if( !$changedfrom){
                $v = $this->_email;    
                if ($headers) $headers .= "\n";
                $headers .= 'From: '.$v;
            }

            // set "Return-Path" header if not set on the device
            if(!$returnPathSet){
                $v = $this->_email;    
                if ($headers) $headers .= "\n";
                $headers .= 'Return-Path: '.$v;
            }
             
            // if this is a multipart message with a boundary, we must use the original body
            if ($use_orgbody) {
                list(,$body) = $mobj->_splitBodyHeader($rfc822);
            }    
            else
            $body = $this->getBody($message);

            // reply                
            if (isset($reply) && isset($parent) &&  $reply && $parent) {
                $this->imap_reopenFolder($parent);
                // receive entire mail (header + body) to decode body correctly
                $origmail = @imap_fetchheader($this->_mbox, $reply, FT_PREFETCHTEXT | FT_UID) . @imap_body($this->_mbox, $reply, FT_PEEK | FT_UID);
                $mobj2 = new Mail_mimeDecode($origmail);
                // receive only body
                $body .= $this->getBody($mobj2->decode(array('decode_headers' => false, 'decode_bodies' => true, 'include_bodies' => true, 'input' => $origmail, 'crlf' => "\n", 'charset' => 'utf-8')));
                // unset mimedecoder & origmail - free memory            
                unset($mobj2);
                unset($origmail);
            }

            // encode the body to base64 if it was sent originally in base64 by the pda 
            // the encoded body is included in the forward         
            if ($body_base64) $body = base64_encode($body);


            // forward                
            if (isset($forward) && isset($parent) && $forward && $parent) {
                $this->imap_reopenFolder($parent);
                // receive entire mail (header + body)
                $origmail = @imap_fetchheader($this->_mbox, $forward, FT_PREFETCHTEXT | FT_UID) . @imap_body($this->_mbox, $forward, FT_PEEK | FT_UID);

                // build a new mime message, forward entire old mail as file
                list($aheader, $body) = $this->mail_attach("forwarded_message.eml",strlen($origmail),$origmail, $body, $forward_h_ct, $forward_h_cte);

                // unset origmail - free memory            
                unset($origmail);

                // add boundary headers
                $headers .= "\n" . $aheader;
            }
            $headers .="\n";
            $send =  @imap_mail ( $toaddr, $message->headers["subject"], $body, $headers, $ccaddr, $bccaddr);
            $errors = imap_errors();
                if (is_array($errors)) {
                    foreach ($errors as $e)    debugLog("IMAP-errors: $e");            
                }             
            // email sent?
            if (!$send) {
                debugLog("The email could not be sent. Last-IMAP-error: ". imap_last_error());
            }
            // add message to the sent folder
            // build complete headers
            $cheaders  = "To: " . $toaddr. "\n";
            $cheaders .= $headers;
            $asf = false;  
            //try to see if there are a folder with the annotation 
            $sent=$this->readDefaultSentItemFolder();    
            $body=str_replace("\n","\r\n",$body); 
            $cheaders=str_replace(":  ",": ",$cheaders); 
            $cheaders=str_replace("\n","\r\n",$cheaders); 
            if ($sent) {
                $asf = $this->addSentMessage($sent, $cheaders, $body);
            }
            else if ($this->sentFolder) {
                $asf = $this->addSentMessage($this->sentFolder, $cheaders, $body);
                debugLog("IMAP-SendMail: Outgoing mail saved in configured 'Sent' folder '".$this->sentFolder."': ". (($asf)?"success":"failed"));
            }
            // No Sent folder set, try defaults
            else {
                debugLog("IMAP-SendMail: No Sent mailbox set");
                if($this->addSentMessage("INBOX/Sent", $cheaders, $body)) {
                    debugLog("IMAP-SendMail: Outgoing mail saved in 'INBOX/Sent'");
                    $asf = true;
                }
                else if ($this->addSentMessage("Sent", $cheaders, $body)) {
                    debugLog("IMAP-SendMail: Outgoing mail saved in 'Sent'");
                    $asf = true;
                }
                else if ($this->addSentMessage("Sent Items", $cheaders, $body)) {
                    debugLog("IMAP-SendMail: Outgoing mail saved in 'Sent Items'");
                    $asf = true;
                }
            }
            $errors = imap_errors();
                if (is_array($errors)) {
                    foreach ($errors as $e)    debugLog("IMAP-errors: $e");            
                }             
            // unset mimedecoder - free memory
            unset($mobj);
            return ($send && $asf);
        }

        /* Should return a wastebasket folder if there is one. This is used when deleting
        * items; if this function returns a valid folder ID, then all deletes are handled
        * as moves and are sent to your backend as a move. If it returns FALSE, then deletes
        * are always handled as real deletes and will be sent to your importer as a DELETE
        */
        function GetWasteBasket() {
            return $this->_wasteID;
        }
        private function GetMessagesListByType($foldertype,$cutoffdate)
        {
            $lastfolder="";
            $messages=array();
            $list = @imap_getmailboxes($this->_mbox, $this->_server, "*");
            if (is_array($list)) {  
                $list = array_reverse($list);
                foreach ($list as $val) {
                    //$folder=imap_utf7_decode(substr($val->name, strlen($this->_server)));
                    $folder=substr($val->name, strlen($this->_server));
                    //$this->saveFolderAnnotation($folder);
                    $ft=$this->kolabFolderType($folder);
                    if ($ft !=  $foldertype)
                    {
                        continue;
                    }
                    $isUser=false;
                    $isShared=false;
                    if (substr($folder,0,4) =="user"){$isUser=true;}
                    if (substr($folder,0,6) =="shared"){$isShared=true;} 
                    $fa=$this->kolabReadFolderParam($folder);
                    //here we must push theo object in the cache to 
                    //dont have to read it again at each message ( for the alarms)
                    $this->CacheWriteFolderParam($folder,$fa);
                    $fa->setFolder($folder);
                    if ( ! $fa->isForSync($this->_devid))
                    {
                        //not set to sync
                        continue;
                    }
                    //want user namespace ?
                    /*
                    if ( !KOLAB_USERFOLDER_DIARY && $foldertype == 2 && $isUser)
                    {
                    continue;
                    }
                    if ( !KOLAB_USERFOLDER_CONTACT && $foldertype == 1 && $isUser)
                    {
                    continue;
                    }
                    //want shared namespace ?
                    if ( !KOLAB_SHAREDFOLDER_DIARY && $foldertype == 2 && $isShared)
                    {
                    continue;
                    }
                    if ( !KOLAB_SHAREDFOLDER_CONTACT && $foldertype == 1 && $isShared)
                    {
                    continue;
                    }
                    */
                    if ( $this->CacheGetDefaultFolder($foldertype) == false)
                    {
                        //no default 
                        if (substr($folder,0,5) == "INBOX")
                        {
                            $n=array_pop(explode("/",$folder));
                            $result=false;
                            switch($foldertype)
                            {
                                case 1: $result=$this->isDefaultFolder($n,KOLAB_DEFAULTFOLDER_CONTACT);
                                break;
                                case 2: $result=$this->isDefaultFolder($n,KOLAB_DEFAULTFOLDER_DIARY);
                                break;
                                case 3: $result=$this->isDefaultFolder($n,KOLAB_DEFAULTFOLDER_TASK);
                                break;         
                            }
                            if ( $result == true)
                            {
                                $this->forceDefaultFolder($foldertype,$folder);
                            }
                            else 
                            {
                                $lastfolder=$folder;
                            }  
                        }
                    }

                    $this->imap_reopenFolder($folder);
                    
                    /*trying optimizing the reads*/
                    /*if ($this->isFolderModified($folder) == false )
                    {
                    $this->Log("NOTICE : folder not modified $folder");
                    $message_folder=$this->CacheReadMessageList($folder);
                    if (count($message)> 0)
                    {
                    $messages=array_merge($messages,$message_folder);
                    continue;
                    }     
                    }   */
                    $overviews = @imap_fetch_overview($this->_mbox, "1:*",FT_UID);
                    if (!$overviews) {
                        debugLog("IMAP-GetMessageList: $folder Failed to retrieve overview");
                    } else {
                        $message_infolder=array(); 
                        foreach($overviews as $overview) {
                            $date = "";                
                            $vars = get_object_vars($overview);

                            if (array_key_exists( "deleted", $vars) && $overview->deleted)                
                            continue;

                            $message=$this->KolabStat($folder,$overview);
                            if (! $message){continue;} 
                            //cutoffdate for appointment 
                            if ( $foldertype == 2)
                            {
                                //look for kolabuid 
                                $this->Log("try cutoffdate for message id ".$message["id"]);
                                $enddate= $this->CacheReadEndDate($folder,$message["id"]);
                                if ($enddate != - 1 && $cutoffdate > $enddate)
                                {
                                    //cuteoffdate
                                    $this->Log("cuteoffDate :" . $message["id"] ); 
                                    continue;
                                }
                                if ( substr($folder,0,5) != "INBOX")
                                {
                                    if ($this->CacheReadSensitivity($message["id"]))
                                    {
                                        //check if private for namespace <> INBOX 
                                        continue;
                                    }
                                }
                            }
                            //check if key is duplicated 
                            if (isset($checkId[$message["id"]]))
                            {
                                //uid exist
                                $this->Log("Key : " .$message["id"] ." duplicated folder :" . $folder ." Imap id : " . $checkId[$message["id"]]);
                                debugLog("Key : " .$message["id"] ." duplicated folder :" . $folder ." Imap id : " . $checkId[$message["id"]]);
                                //rewrite the index to have the good imapid 
                                $id=array_pop(explode("/",$checkId[$message["id"]]));
                                $this->CacheCreateIndex($folder,$message["id"],$id);  
                                continue;
                            }
                            else
                            {
                                $checkId[$message["id"]] = $message["mod"];
                            }
                            //here check the cutdate for appointments 
                            debugLog("ListMessage : " . $message["id"] . "->" . $message["mod"] ) ;
                            $messages[]=$message;
                            $message_infolder[]=$message;
                        }
                        $this->CacheStoreMessageList($folder,$message_infolder);
                    }
                } 
                //check if we found a default folder for this type
                if ( $this->CacheGetDefaultFolder($foldertype) == false)
                {
                    //no we pur the last folder found as default;
                    $this->forceDefaultFolder($foldertype,$lastfolder); 
                }
 
                unset($checkId);
                unset($overviews);
                return $messages;
            }
        }
        private function statImapFolder($folder)
        {
            $info=imap_status($this->_mbox, $this->_server .$folder, SA_ALL)  ; 
            return serialize($info);
        }
        /* Should return a list (array) of messages, each entry being an associative array
        * with the same entries as StatMessage(). This function should return stable information; ie
        * if nothing has changed, the items in the array must be exactly the same. The order of
        * the items within the array is not important though.
        *
        * The cutoffdate is a date in the past, representing the date since which items should be shown.
        * This cutoffdate is determined by the user's setting of getting 'Last 3 days' of e-mail, etc. If
        * you ignore the cutoffdate, the user will not be able to select their own cutoffdate, but all
        * will work OK apart from that.
        */
        function GetMessageList($folderid, $cutoffdate) 
        {
            $messages = array();
            $checkId = array();
            if ($folderid == "VIRTUAL/calendar")
            {
                //flat mode
                //search all folders of type calendar
                $messages=$this->GetMessagesListByType(2,$cutoffdate);

            }
            else if ($folderid == "VIRTUAL/contacts")  
            {
                $messages=$this->GetMessagesListByType(1,$cutoffdate);
            }
            else if ($folderid == "VIRTUAL/tasks")  
            {
                $messages=$this->GetMessagesListByType(3,$cutoffdate);
            }
            else
            {
                $this->imap_reopenFolder($folderid, true);
                //check if the folder as moved by imap stat
                /*
                if ($this->isFolderModified($folderid) == false )
                {
                $this->Log("NOTICE : folder not modified $folderid");
                $messages=$this->CacheReadMessageList($folderid);
                return $messages;      
                } */
                $overviews = @imap_fetch_overview($this->_mbox, "1:*",FT_UID);
                if (!$overviews) {
                    debugLog("IMAP-GetMessageList: $folderid Failed to retrieve overview");
                } else {
                    foreach($overviews as $overview) {
                        $date = "";                
                        $vars = get_object_vars($overview);
                        // cut of deleted messages
                        if (array_key_exists( "deleted", $vars) && $overview->deleted)                
                        continue;
                        $folderType=$this->kolabFolderType($folderid);
                        if ( $folderType> 0)
                        {
                            //kolab contacts and appointment special index
                            //mode is the imap uid because kolab delete the message and recreate a newone in case
                            //of modification
                            $message=$this->KolabStat($folderid,$overview);
                            if (! $message){continue;} 
                            //cutoffdate for appointment 
                            if ( $folderType == 2)
                            {
                                //look for kolabuid 
                                $this->Log("try cutoffdate for message id ".$message["id"]);
                                $enddate= $this->CacheReadEndDate($folderid,$message["id"]);
                                if ($enddate != - 1 &&  $cutoffdate > $enddate)
                                {
                                    //cuteoffdate
                                    $this->Log("cuteoffDate too old"); 
                                    continue;
                                }
                                if ( substr($folderid,0,5) != "INBOX")
                                {
                                    if ($this->CacheReadSensitivity($message["id"]))
                                    {
                                        //check if private for namespace <> INBOX 
                                        continue;
                                    }
                                }
                            }
                            //check if key is duplicated 
                            if (isset($checkId[$message["id"]]))
                            {
                                $this->Log("Key : " .$message["id"] ." duplicated folder :" . $folder ." Imap id : " . $checkId[$message["id"]]);
                                debugLog("Key : " .$message["id"] ." duplicated folder :" . $folder ." Imap id : " . $checkId[$message["id"]]);
                                //rewrite the index to have the good imapid 
                                $id=array_pop(explode("/",$checkId[$message["id"]]));
                                $this->CacheCreateIndex($folder,$message["id"],$id); 
                                continue; 
                            }
                            else
                            {
                                $checkId[$message["id"]] = $message["mod"];
                            }
                            //here check the cutdate for appointments 
                            debugLog("ListMessage : " . $message["id"] . "->" . $message["mod"] ) ;
                            $messages[]=$message;
                        }
                        else
                        {

                            if (array_key_exists( "date", $vars)) {               
                                // message is out of range for cutoffdate, ignore it

                                if(strtotime($overview->date) < $cutoffdate) continue;
                                $date = $overview->date;
                            }
                            if (array_key_exists( "uid", $vars)) 
                            {               
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
                    }
                }
                //clean the index before leave
                $this->CacheIndexClean($messages) ;
                //$this->Log("Get Message List : " . count($messages)) ;
                }

            debugLog("MEM GetmessageList End:" . memory_get_usage())  ; 
            $this->CacheStoreMessageList($folderid,$messages);
            return $messages;


        }
        private function isFolderModified($folder)
        {
            $newstatus=@imap_status($this->_mbox,$this->_server. $folder,SA_ALL);
            $oldstatus=$this->CacheReadImapStatus($folder);
            //found the old status;
            //we compare
            if ( $oldstatus->uidnext == $newstatus->uidnext && $oldstatus->messages == $newstatus->messages)
            {
                //the folder has not been modified 
                return False;
            }
            $this->CacheStoreImapStatus($folder,$newstatus);
            return true;
        }    
        /* This function is analogous to GetMessageList. 
        *
        */
        function GetFolderList()
        { 
 
            if ( $this->foMode == true)
            {
                return $this->GetFolderListFoMode();
            }
            else
            {
                return $this->GetFolderListFlMode(); 
            }
        }
        private function GetFolderListFlMode()
        {
            $folders = array();      
            $list = @imap_getmailboxes($this->_mbox, $this->_server, "*");
            //add the virtual folders for contacts calendars and tasks
            $virtual=array("VIRTUAL/calendar","VIRTUAL/contacts","VIRTUAL/tasks");   
            //$virtual=array("VIRTUAL/calendar","VIRTUAL/contacts");
            foreach ($virtual as $v)
            {
                $box=array();
                $box["id"]=$v;
                $box["mod"] =$v;
                $box["flags"]=0;
                $folders[]=$box;
            }           
            if (is_array($list)) {  
                $list = array_reverse($list);
                foreach ($list as $val) {
                    $box = array();
                    // cut off serverstring 
                    $box["flags"]=0;
                    //$box["id"] = imap_utf7_decode(substr($val->name, strlen($this->_server)));
                    $box["id"] =substr($val->name, strlen($this->_server));
                    //rerid the annotations
                    $this->saveFolderAnnotation($box["id"]); 
                    $foldertype=$this->readFolderAnnotation($box["id"]);
                    //if folder type > 0 escape
                    if ( substr($foldertype,0,5) == "event")
                    {
                        continue;
                    }
                    if ( substr($foldertype,0,7) == "contact")
                    {
                        continue;
                    }
                    if ( substr($foldertype,0,4) == "task")
                    {
                        continue;
                    }
                    //other folders (mails)
                    //$box["id"] = imap_utf7_encode( $box["id"]);  
                    $fhir = explode("/", $box["id"]);
                    if (count($fhir) > 1) {
                        $box["mod"] = imap_utf7_encode(array_pop($fhir)); // mod is last part of path
                        $box["parent"] = imap_utf7_encode(implode("/", $fhir)); // parent is all previous parts of path
                        }
                    else {
                        $box["mod"] = imap_utf7_encode($box["id"]);
                        $box["parent"] = "0";
                    }

                    $folders[]=$box;  
                }
            }
            else {
                debugLog("GetFolderList: imap_list failed: " . imap_last_error());
            }
            return $folders;   
        }
        private function GetFolderListFoMode() {
            $folders = array();      
            $list = @imap_getmailboxes($this->_mbox, $this->_server, "*");
            $this->hasDefaultEventFolder=false;
            $this->hasDefaultContactFolder=false;  
            $this->hasDefaultTaskFolder=false;  
            if (is_array($list)) {

                //create the 
                // reverse list to obtain folders in right order
                $list = array_reverse($list);
                foreach ($list as $val) {
                    $box = array();
                    // cut off serverstring 
                    $box["flags"]=0;
                    //$box["id"] = imap_utf7_decode(substr($val->name, strlen($this->_server)));
                    $box["id"]= substr($val->name, strlen($this->_server));
                    //determine the type en default folder
                    $isUser=false;
                    $isShared=false;
                    $isInbox=false;
                    //rerid the annotations
                    $this->saveFolderAnnotation($box["id"]); 
                    $foldertype=$this->readFolderAnnotation($box["id"]);
                    $defaultfolder = false;
                    //defaultfolder ? 
                    if ( $foldertype == "event.default")
                    {
                        $this->hasDefaultEventFolder=true;
                        $defaultfolder = true;
                    }
                    if ( $foldertype == "contact.default")
                    {
                        $this->hasDefaultContactFolder=true;
                        $defaultfolder = true;
                    }
                    if ( $foldertype == "task.default")
                    {
                        $this->hasDefaultTaskFolder=true;
                        $defaultfolder = true;
                    }
                    // workspace of the folder;
                    if (substr( $box["id"],0,6) == "shared")
                    {
                        //this is a shared folder  
                        $isShared=true;
                    }

                    if (substr( $box["id"],0,4) == "user")
                    {
                        //this is a User shared folder  
                        $isUser=true;
                    }
                    if (substr( $box["id"],0,5) == "INBOX")
                    {
                        $isInbox=true;
                    }
                    //selection of the folder depending to the setup
                    if (! $defaultfolder)
                    {
                                       
                        //test annotation
                        $fa=$this->kolabReadFolderParam($box["id"]);
                        //for later use (in getMessage)

                        $this->CacheWriteFolderParam($box["id"],$fa); 
                        $fa->setfolder($box["id"]);   
                        if ( ! $fa->isForSync($this->_devid))
                        {
                            //not set to sync
                            continue;
                        }
                    }
                    $this->Log("NOTICE SyncFolderList Add folder ".$box["id"]);
                    //$box["id"] = imap_utf7_encode( $box["id"]);
                    if ($isShared)
                    {
                        $fhir = explode(".", $box["id"]);
                        $box["mod"] = imap_utf7_encode($fhir[1]);
                        $box["parent"] = "shared"; 
                    }
                    elseif ($isUser)
                    {
                        $box["mod"] = imap_utf7_encode(array_pop($fhir));
                        $box["parent"] = "user";  
                    }
                    else
                    {

                        // explode hierarchies
                        $fhir = explode("/", $box["id"]);
                        $t=count($fhir);
                        if (count($fhir) > 1) {
                            $box["mod"] = imap_utf7_encode(array_pop($fhir)); // mod is last part of path
                            $box["parent"] = imap_utf7_encode(implode("/", $fhir)); // parent is all previous parts of path
                            }
                        else {
                            $box["mod"] = imap_utf7_encode($box["id"]);
                            $box["parent"] = "0";
                        }
                    }                    
                    $folders[]=$box;
                }
            } 
            else {
                debugLog("GetFolderList: imap_list failed: " . imap_last_error());
            }
            return $folders;
        }

        /* GetFolder should return an actual SyncFolder object with all the properties set. Folders
        * are pretty simple really, having only a type, a name, a parent and a server ID. 
        */

        function GetFolder($id) {
            $folder = new SyncFolder(); 
            $folder->serverid = $id;
            // explode hierarchy
            $fhir = explode("/", $id);
            if ( substr($id,0,6) == "shared")
            {
                $parent="shared";
            }
            else
            {
                $ftmp=$fhir;
                array_pop($ftmp);
                $parent=implode("/", $ftmp);
            }
            //get annotation type
            // compare on lowercase strings
            $lid = strtolower($id);
            $fimap=$id;
            if($lid == "inbox") {
                $folder->parentid = "0"; // Root
                $folder->displayname = "Inbox";
                $folder->type = SYNC_FOLDER_TYPE_INBOX;
            } 
            // courier-imap outputs
            else if($lid == "inbox/drafts") {
                $folder->parentid = $fhir[0];
                $folder->displayname = "Drafts";
                $folder->type = SYNC_FOLDER_TYPE_DRAFTS;
            }
            else if($lid == "inbox/trash") {
                $folder->parentid = $fhir[0];
                $folder->displayname = "Trash";
                $folder->type = SYNC_FOLDER_TYPE_WASTEBASKET;
                $this->_wasteID = $id;
            }
            else if($lid == "inbox/sent") {
                $folder->parentid = $fhir[0];
                $folder->displayname = "Sent";
                $this->sentFolder=$id;
                $folder->type = SYNC_FOLDER_TYPE_SENTMAIL;
                $this->_sentID = $id;
            }
            // define the rest as other-folders
            //check if flatmode 

            else if ( $this->foMode == False && $id == "VIRTUAL/calendar")
            {
                $folder->parentid ="VIRTUAL";
                $folder->displayname = $id;
                $folder->type = SYNC_FOLDER_TYPE_APPOINTMENT;
                $this->_sentID = $id;
            }
            else if ( $this->foMode == False && $id == "VIRTUAL/contacts")
            {
                $folder->parentid = "VIRTUAL";
                $folder->displayname = "Contacts";
                $folder->type = SYNC_FOLDER_TYPE_CONTACT;
                $this->_sentID = $id;
            }
            else if ( $this->foMode == False && $id == "VIRTUAL/tasks")
            {
                $folder->parentid = "VIRTUAL";
                $folder->displayname = $id;
                $folder->type = SYNC_FOLDER_TYPE_TASK;
                $this->_sentID = $id;
            }
            else if ( $this->kolabfolderType($id) == 1)
            {
                //contact kolab 
                $folder->parentid = $parent;
                $folder->displayname = $this->folderDisplayName($id);
                $folder->type = $this->ActiveSyncFolderSyncType($id);  
                $this->_sentID = $id;

            }
            else if ($this->kolabfolderType($id) == 2)
            {

                // shared folder in UPPER , 
                $folder->parentid = $parent;
                $folder->displayname =  $this->folderDisplayName($id);  
                $folder->type = $this->ActiveSyncFolderSyncType($id);
                $this->_sentID = $id;
            }
            else if ($this->kolabfolderType($id) == 3)
            {
                $folder->parentid = $parent;
                $folder->displayname =  $this->folderDisplayName($id);    
                $folder->type = $this->ActiveSyncFolderSyncType($id);
                $this->_sentID = $id;
            }
            else {
                if (count($fhir) > 1) {

                    $folder->displayname = windows1252_to_utf8(imap_utf7_decode(array_pop($fhir)));
                    $folder->parentid = implode("/", $fhir);
                }
                else {
                    $folder->displayname = windows1252_to_utf8(imap_utf7_decode($id));
                    $folder->parentid = "0";
                }
                $folder->type = SYNC_FOLDER_TYPE_OTHER;
            } 

            //advanced debugging
            //debugLog("IMAP-GetFolder(id: '$id') -> " . print_r($folder, 1));
            return $folder;
        }

        /* Return folder stats. This means you must return an associative array with the
        * following properties:
        * "id" => The server ID that will be used to identify the folder. It must be unique, and not too long
        *         How long exactly is not known, but try keeping it under 20 chars or so. It must be a string.
        * "parent" => The server ID of the parent of the folder. Same restrictions as 'id' apply.
        * "mod" => This is the modification signature. It is any arbitrary string which is constant as long as
        *          the folder has not changed. In practice this means that 'mod' can be equal to the folder name
        *          as this is the only thing that ever changes in folders. (the type is normally constant)
        */
        private function folderDisplayName($folder)
        {

            $f = explode("/", $folder);
            if (substr($f[0],0,6) == "shared" )
            {
                // shared folder in UPPER 
                $s=explode(".",$folder) ;
                return strtoupper(windows1252_to_utf8(imap_utf7_decode($s[1])));

            }
            if ($f[0] == "INBOX")
            {
                $type=$this->readFolderAnnotation($folder);
                if ($type =="contact.default" || $type =="event.default" || $type =="task.default")
                { 
                    //default folder all min lowaercase

                    $r=windows1252_to_utf8(imap_utf7_decode($f[1]));
                    return strtolower(windows1252_to_utf8(imap_utf7_decode(array_pop($f))));
                }
                else
                {
                    //others    AA problem when we have sub sub folder 
                    //must keep the last one 
                    return ucfirst(windows1252_to_utf8(imap_utf7_decode(array_pop($f))));
                }
            } 
            if ($f[0] == "user")
            {
                $type=$this->readFolderAnnotation($folder);
                $t=explode(".",$type);

                //find the user
                $fname=array_pop($f);
                $r=windows1252_to_utf8(imap_utf7_decode($fname."(".$f[1].")"));
                return windows1252_to_utf8($r);
            } 
        }
        function StatFolder($id) {

            $folder = $this->GetFolder($id);

            $stat = array();
            $stat["id"] = $id;
            $stat["parent"] = $folder->parentid;
            $stat["mod"] = $folder->displayname;

            return $stat;
        }

        /* Creates or modifies a folder
        * "folderid" => id of the parent folder
        * "oldid" => if empty -> new folder created, else folder is to be renamed
        * "displayname" => new folder name (to be created, or to be renamed to)
        * "type" => folder type, ignored in IMAP
        *
        */
        function ChangeFolder($folderid, $oldid, $displayname, $type){
            debugLog("ChangeFolder: (parent: '$folderid'  oldid: '$oldid'  displayname: '$displayname'  type: '$type')"); 

            // go to parent mailbox
            $this->imap_reopenFolder($folderid);

            // build name for new mailbox
            $newname = $this->_server . str_replace(".", $this->_serverdelimiter, $folderid) . $this->_serverdelimiter . $displayname;

            $csts = false;
            // if $id is set => rename mailbox, otherwise create
            if ($oldid) {
                // rename doesn't work properly with IMAP
                // the activesync client doesn't support a 'changing ID'
                //$csts = imap_renamemailbox($this->_mbox, $this->_server . imap_utf7_encode(str_replace(".", $this->_serverdelimiter, $oldid)), $newname);
                }
            else {
                $csts = @imap_createmailbox($this->_mbox, $newname);
            }
            if ($csts) {
                return $this->StatFolder($folderid . "." . $displayname);
            }
            else 
            return false;
        }

        /* Should return attachment data for the specified attachment. The passed attachment identifier is
        * the exact string that is returned in the 'AttName' property of an SyncAttachment. So, you should
        * encode any information you need to find the attachment in that 'attname' property.
        */    
        function GetAttachmentData($attname) {
            debugLog("getAttachmentDate: (attname: '$attname')");    

            list($folderid, $id, $part) = explode(":", $attname);

            $this->imap_reopenFolder($folderid);
            $mail = @imap_fetchheader($this->_mbox, $id, FT_PREFETCHTEXT | FT_UID) . @imap_body($this->_mbox, $id, FT_PEEK | FT_UID);

            $mobj = new Mail_mimeDecode($mail);
            $message = $mobj->decode(array('decode_headers' => true, 'decode_bodies' => true, 'include_bodies' => true, 'input' => $mail, 'crlf' => "\n", 'charset' => 'utf-8'));

            if (isset($message->parts[$part]->body))
            print $message->parts[$part]->body;

            // unset mimedecoder & mail
            unset($mobj);            
            unset($mail);    
            return true;
        }

        /* StatMessage should return message stats, analogous to the folder stats (StatFolder). Entries are:
        * 'id'     => Server unique identifier for the message. Again, try to keep this short (under 20 chars)
        * 'flags'     => simply '0' for unread, '1' for read
        * 'mod'    => modification signature. As soon as this signature changes, the item is assumed to be completely
        *             changed, and will be sent to the PDA as a whole. Normally you can use something like the modification
        *             time for this field, which will change as soon as the contents have changed.
        */

        function StatMessage($folderid, $id) {
            debugLog("IMAP-StatMessage: (fid: '$folderid'  id: '$id' )");    
            //search the imap id 
            if ( $this->kolabFolderType($folderid))
            {
                //in case of synchor app or contacts we work with kolab_uid
                
                if (substr($folderid,0,7) == "VIRTUAL")
                {
                    //must find the right folder
                    $folderid=$this->CacheIndexUid2FolderUid($id);
                    debugLog("StatMessage Flmode: $id - > $folderid");
                    $this->Log("NOTICE StatMessage Flmode: $id - > $folderid");
                }
                $imap_id=$this->CacheIndexUid2Id($folderid,$id);
                if ($imap_id)
                {
                    $entry=array();
                    $entry["mod"]=$folderid ."/".$imap_id;
                    $entry["id"]=$id;
                    $entry["flags"] = 0;
                    return $entry;
                }
                else
                {
                    //kolab_uid -> imap_id must be exist 
                    debugLog("StatMessage: Failed to retrieve imap_id from index: ". $id);      
                    return false;
                }
            }
            //normal case for imap mails synchro 

            $this->imap_reopenFolder($folderid);  
            $overview = @imap_fetch_overview( $this->_mbox , $id , FT_UID);

            if (!$overview) {
                debugLog("IMAP-StatMessage: Failed to retrieve overview: ". imap_last_error());
                return false;
            } 
            else {
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
            }
            //advanced debugging
            //debugLog("IMAP-StatMessage-parsed: ". print_r($entry,1));

            return $entry;

        }

        /* GetMessage should return the actual SyncXXX object type. You may or may not use the '$folderid' parent folder
        * identifier here.
        * Note that mixing item types is illegal and will be blocked by the engine; ie returning an Email object in a 
        * Tasks folder will not do anything. The SyncXXX objects should be filled with as much information as possible, 
        * but at least the subject, body, to, from, etc.
        */
        function GetMessage($folderid, $id, $truncsize) {
            debugLog("KOLAB-GetMessage: (fid: '$folderid'  id: '$id'  truncsize: $truncsize)");
            // Get flags, etc  
  
            $stat = $this->StatMessage($folderid, $id);
            if ($stat) {  
                if ( $this->kolabFolderType($folderid))
                {
                    //get the imap_id  
                    $imap_id=array_pop(explode("/",$stat['mod']));
                    //$imap_id=$stat['mod'];

                    if ( substr($folderid,0,7) == "VIRTUAL")
                    {

                        $folderid=$this->CacheIndexUid2FolderUid($id);
                        debugLog("GetMessage Flmode: $id - > $folderid");
                        $this->Log("NOTICE GetMessage Flmode: $id - > $folderid");
                    }
                }    
                else
                {
                    $imap_id=$id;
                }
                $this->imap_reopenFolder($folderid);
                $mail = @imap_fetchheader($this->_mbox, $imap_id, FT_PREFETCHTEXT | FT_UID) . @imap_body($this->_mbox, $imap_id, FT_PEEK | FT_UID);

                $mobj = new Mail_mimeDecode($mail);
                $message = $mobj->decode(array('decode_headers' => true, 'decode_bodies' => true, 'include_bodies' => true, 'input' => $mail, 'crlf' => "\n", 'charset' => 'utf-8'));

                if ($this->kolabFolderType($folderid) == 1)
                {
                    $output=$this->KolabReadContact($message,0);
                    $this->Log("Changed on Server C: $folderid /" .$id. "imap id : " .$imap_id );
                    $this->Log("                  : " . u2w($output->fileas));
                    $this->CacheCreateIndex($folderid,$id,$imap_id);
                    return $output;
                }
                elseif ($this->kolabFolderType($folderid) == 2 )
                {
                    //bug #9 we must test if we want alarms or not
                    // for the moment disable it if namespace <> INBOX 
                    $fa=$this->CacheReadFolderParam($folderid);
                    $fa->setFolder($folderid)  ;
                    if ( $fa->showAlarm($this->_devid)) 
                    {   
                        $output=$this->KolabReadEvent($message,$id,false)   ;    //alarm must be shown
                        }
                    else
                    {
                        $output=$this->KolabReadEvent($message,$id,true)   ;  
                    }
                    $this->Log("Changed on Server A: $folderid/" .$id );
                    $this->Log("                  : " . u2w($output->subject));
                    $this->CacheCreateIndex($folderid,$id,$imap_id)   ;
                    $this->CacheWriteSensitivity($id,$output->sensitivity);
                    return $output;
                }
                elseif ($this->kolabFolderType($folderid) == 3 )
                {   
                    
                    $output=$this->KolabReadTask($message,$id)   ;
                    $this->Log("Changed on Server T: $folderid /" .$id );
                    $this->Log("                  : " . u2w($output->subject));
                    $this->CacheCreateIndex($folderid,$id,$imap_id)   ;
                    //rewrite completion
                    $this->CacheWriteTaskCompleted($id,$output->completed);
                    $this->CacheWriteSensitivity($id,$output->sensitivity);
                    return $output;
                }
                else
                {
                    $output = new SyncMail();

                    // decode body to truncate it
                    $body = utf8_to_windows1252($this->getBody($message));
                    $truncsize=2048;
                    if(strlen($body) > $truncsize) {
                        $body = substr($body, 0, $truncsize);
                        $output->bodytruncated = 1;
                    } else {
                        $body = $body;
                        $output->bodytruncated = 0;
                    }
                    $body = str_replace("\n","\r\n", windows1252_to_utf8(str_replace("\r","",$body)));

                    $output->bodysize = strlen($body);
                    $output->body = $body;
                    $output->datereceived = isset($message->headers["date"]) ? strtotime($message->headers["date"]) : null;
                    $output->displayto = isset($message->headers["to"]) ? $message->headers["to"] : null;
                    $output->importance = isset($message->headers["x-priority"]) ? preg_replace("/\D+/", "", $message->headers["x-priority"]) : null;
                    $output->messageclass = "IPM.Note";
                    $output->subject = isset($message->headers["subject"]) ? $message->headers["subject"] : "";
                    $output->read = $stat["flags"];
                    $output->to = isset($message->headers["to"]) ? $message->headers["to"] : null;
                    $output->cc = isset($message->headers["cc"]) ? $message->headers["cc"] : null;
                    $output->from = isset($message->headers["from"]) ? $message->headers["from"] : null;
                    $output->reply_to = isset($message->headers["reply-to"]) ? $message->headers["reply-to"] : null;

                    // Attachments are only searched in the top-level part
                    $n = 0;
                    if(isset($message->parts)) {
                        foreach($message->parts as $part) {
                            if(isset($part->disposition) && ($part->disposition == "attachment" || $part->disposition == "inline")) {
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
                                $attachment->attname = $folderid . ":" . $id . ":" . $n;
                                $attachment->attmethod = 1;
                                $attachment->attoid = isset($part->headers['content-id']) ? $part->headers['content-id'] : "";
                                array_push($output->attachments, $attachment);
                            }
                            $n++;
                        }
                    }
                    // unset mimedecoder & mail
                    unset($mobj);
                    unset($mail);
                    return $output;
                }
            }
            return false;
        }

        /* This function is called when the user has requested to delete (really delete) a message. Usually
        * this means just unlinking the file its in or somesuch. After this call has succeeded, a call to
        * GetMessageList() should no longer list the message. If it does, the message will be re-sent to the PDA
        * as it will be seen as a 'new' item. This means that if you don't implement this function, you will
        * be able to delete messages on the PDA, but as soon as you sync, you'll get the item back
        */
        function DeleteMessage($folderid, $id) {
            debugLog("KOLAB-DeleteMessage: (fid: '$folderid'  id: '$id' )");
            if ( $this->kolabFolderType($folderid) >0 ) 
            {
                if (substr($folderid,0,7) == "VIRTUAL")
                {
                    $folderid=$this->CacheIndexUid2FolderUid($id);
                    debugLog("DeleteMessage Flmode: $id - > $folderid");
                    $this->Log("NOTICE DeleteMessage Flmode: $id - > $folderid");
                }

                //kolab_uid -> imap_id
                $imap_id=$this->CacheIndexUid2Id($folderid,$id);
            }   
            else
            {
                $imap_id=$id;
            }
            $this->imap_reopenFolder($folderid);
            $s1 = @imap_delete ($this->_mbox, $imap_id, FT_UID);
            $s11 = @imap_setflag_full($this->_mbox, $imap_id, "\\Deleted", FT_UID);
            $s2 = @imap_expunge($this->_mbox);
            $this->CacheIndexDeletebyId($folderid,$id);
            debugLog("IMAP-DeleteMessage: s-delete: $s1   s-expunge: $s2    setflag: $s11");

            return ($s1 && $s2 && $s11);
        }

        /* This should change the 'read' flag of a message on disk. The $flags                                                  r
        * parameter can only be '1' (read) or '0' (unread). After a call to
        * SetReadFlag(), GetMessageList() should return the message with the
        * new 'flags' but should not modify the 'mod' parameter. If you do
        * change 'mod', simply setting the message to 'read' on the PDA will trigger
        * a full resync of the item from the server
        */
        function SetReadFlag($folderid, $id, $flags) {
            debugLog("IMAP-SetReadFlag: (fid: '$folderid'  id: '$id'  flags: '$flags' )");

            $this->imap_reopenFolder($folderid);

            if ($flags == 0) {
                // set as "Unseen" (unread)
                $status = @imap_clearflag_full ( $this->_mbox, $id, "\\Seen", ST_UID);
            } else {
                // set as "Seen" (read)
                $status = @imap_setflag_full($this->_mbox, $id, "\\Seen",ST_UID);
            }

            debugLog("IMAP-SetReadFlag -> set as " . (($flags) ? "read" : "unread") . "-->". $status);

            return $status;
        }

        /* This function is called when a message has been changed on the PDA. You should parse the new
        * message here and save the changes to disk. The return value must be whatever would be returned
        * from StatMessage() after the message has been saved. This means that both the 'flags' and the 'mod'
        * properties of the StatMessage() item may change via ChangeMessage().
        * Note that this function will never be called on E-mail items as you can't change e-mail items, you
        * can only set them as 'read'.
        */
        function ChangeMessage($folderid, $id, $message) {
   
            $modify=false; 
            $this->Log("PDA Folder : " . $folderid .  "  object uid : " . $id);
            if (substr($folderid,0,6) == "shared" && KOLAB_SHAREDFOLDERS_RO  ==1 )
            {
                //shared folders are protected 
                $this->Log("PDA Folder : READ ONLY Cancel " . $folderid .  "  object uid : " . $id);
                return false;
            }
            if ( $id != FALSE )
            {                                                                              
                //finding the kolab_uid for this id
                if ( $this->kolabFolderType($folderid)> 0)
                {
                    if (substr($folderid,0,7) == "VIRTUAL")
                    {
                        $folderid=$this->CacheIndexUid2FolderUid($id);
                        debugLog("ChangeMessage Flmode: $id - > $folderid");
                        $this->Log("NOTICE ChangeMessage Flmode: $id - > $folderid"); 
                    }
                    //message exist on the server delete it 
                    $imap_id=$this->CacheIndexUid2Id($folderid,$id); 
                    $this->imap_reopenFolder($folderid);
                    $s1 = @imap_delete ($this->_mbox, $imap_id, FT_UID);
                    $s11 = @imap_setflag_full($this->_mbox, $imap_id, "\\Deleted", FT_UID);
                    $s2 = @imap_expunge($this->_mbox);
                    $this->Log("Change delete imap message : " . $folderid . " " . $imap_id)  ;
                    $kolab_uid=$id;
                    $modify=true;
                }
                else
                {
                    //delete du mail 
                    $this->DeleteMessage($folderid,$id);
                }
            }                                                                                
            // mail is an Array [uid,date,RFC822Message]      *
            if ($folderid == "VIRTUAL/calendar")
            {
                $folderid=$this->CacheGetDefaultFolder("event");
            }
            if ($folderid == "VIRTUAL/contacts")
            {
                $folderid=$this->CacheGetDefaultFolder("contact");
            }
            if ($folderid == "VIRTUAL/tasks")
            {
                $folderid=$this->CacheGetDefaultFolder("task");
            }

            if ( $this->kolabFolderType($folderid) == 1)
            {
                $mail=$this->KolabWriteContact($message,$kolab_uid);

            }
            elseif ($this->kolabFolderType($folderid) == 2)
            {

                $mail=$this->KolabWriteEvent($message,$kolab_uid);
            }
            elseif ($this->kolabFolderType($folderid) == 3)
            {
                $mail=$this->KolabWriteTask($message,$kolab_uid);
            }
            // now we can insert it again 
            $this->imap_reopenFolder($folderid);
            $info=imap_status($this->_mbox, $this->_server . $folderid, SA_ALL)  ;    
            $r=@imap_append($this->_mbox,$this->_server . $folderid,$mail[2] ,"\\Seen");
            $id=$info->uidnext;     
            if ( $r == TRUE)   
            {
                $this->Log("create message : " . $folderid . " " . $id)  ;    
                $this->CacheCreateIndex($folderid,$mail[0],$id);
                if ( $this->kolabFolderType($folderid) ==2)
                {
                    //cache the end date
                    $this->CacheWriteEndDate($folderid,$message) ;   

                }
                if ($this->kolabFolderType($folderid) == 3)
                {
                    $this->CacheWriteTaskCompleted($id,$message->completed);
                    $this->CacheWriteSensitivity($id,$message->sensitivity);
                }
                $entry["mod"] = $folderid ."/".$id;
                $entry["id"]=strtoupper($mail[0]);
                $entry["flags"]=0;
                return $entry;
            } 
            $this->Log("IMAP can't add mail : " . imap_last_error());
            return false;
        }

        /* This function is called when the user moves an item on the PDA. You should do whatever is needed
        * to move the message on disk. After this call, StatMessage() and GetMessageList() should show the items
        * to have a new parent. This means that it will disappear from GetMessageList() will not return the item
        * at all on the source folder, and the destination folder will show the new message
        *
        */
        function MoveMessage($folderid, $id, $newfolderid) {
            debugLog("IMAP-MoveMessage: (sfid: '$folderid'  id: '$id'  dfid: '$newfolderid' )");
            $this->imap_reopenFolder($folderid);

            // read message flags
            $overview = @imap_fetch_overview ( $this->_mbox , $id, FT_UID);

            if (!$overview) {
                debugLog("IMAP-MoveMessage: Failed to retrieve overview");
                return false;
            } 
            else {
                // move message                    
                $s1 = imap_mail_move($this->_mbox, $id, $newfolderid, FT_UID);

                // delete message in from-folder
                $s2 = imap_expunge($this->_mbox);

                // open new folder
                $this->imap_reopenFolder($newfolderid);

                // remove all flags
                $s3 = @imap_clearflag_full ($this->_mbox, $id, "\\Seen \\Answered \\Flagged \\Deleted \\Draft", FT_UID);
                $newflags = "";
                if ($overview[0]->seen) $newflags .= "\\Seen";   
                if ($overview[0]->flagged) $newflags .= " \\Flagged";
                if ($overview[0]->answered) $newflags .= " \\Answered";
                $s4 = @imap_setflag_full ($this->_mbox, $id, $newflags, FT_UID);

                debugLog("MoveMessage: (" . $folderid . "->" . $newfolderid . ") s-move: $s1   s-expunge: $s2    unset-Flags: $s3    set-Flags: $s4");

                return ($s1 && $s2 && $s3 && $s4);
            }
        }

        // ----------------------------------------
        // imap-specific internals

        /* Parse the message and return only the plaintext body
        */
        function getBody($message) {
            $body = "";
            $htmlbody = "";

            $this->getBodyRecursive($message, "plain", $body);

            if(!isset($body) || $body === "") {
                $this->getBodyRecursive($message, "html", $body);
                // remove css-style tags
                $body = preg_replace("/<style.*?<\/style>/is", "", $body);
                // remove all other html
                $body = strip_tags($body);
            }

            return $body;
        }

        // Get all parts in the message with specified type and concatenate them together, unless the
        // Content-Disposition is 'attachment', in which case the text is apparently an attachment
        function getBodyRecursive($message, $subtype, &$body) {
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

        // save the serverdelimiter for later folder (un)parsing
        function getServerDelimiter() {
            $list = @imap_getmailboxes($this->_mbox, $this->_server, "*");
            if (is_array($list)) {
                $val = $list[0];    

                return $val->delimiter;
            }        
            return "."; // default "."
            }

        // speed things up
        // remember what folder is currently open and only change if necessary
        function imap_reopenFolder($folderid, $force = false) {
            // to see changes, the folder has to be reopened!
            if ($this->_mboxFolder != $folderid || $force) {
                $s = @imap_reopen($this->_mbox, $this->_server . $folderid);
                if (!$s) debugLog("failed to change folder: ". implode(", ", imap_errors()));
                $this->_mboxFolder = $folderid;
            }
        }


        // build a multipart email, embedding body and one file (for attachments)
        function mail_attach($filenm,$filesize,$file_cont,$body, $body_ct, $body_cte,$file_ct,$picture=null) {

            $boundary = strtoupper(md5(uniqid(time())));
            if ( $file_ct == "")
            {
                $file_ct="text/plain"  ;
            }    
            $mail_header = "Content-Type: multipart/mixed; boundary=$boundary\r\n";

            // build main body with the sumitted type & encoding from the pda
            $mail_body  = "This is a multi-part message in MIME format\r\n\r\n";
            $mail_body .= "--$boundary\r\n";
            $mail_body .= "Content-Type:$body_ct\r\n";
            if ($body_cte != "")
            {
                $mail_body .= "Content-Transfer-Encoding:$body_cte\r\n\r\n";
            }
            $mail_body .= "$body\r\n\r\n";

            $mail_body .= "--$boundary\r\n";
            $mail_body .= "Content-Type: ".$file_ct."; name=\"$filenm\"\r\n";
            $mail_body .= "Content-Transfer-Encoding: base64\r\n";
            $mail_body .= "Content-Disposition: attachment; filename=\"$filenm\"\r\n";
            $mail_body .= "Content-Description: $filenm\r\n\r\n";
            $mail_body .= base64_encode($file_cont) . "\r\n\r\n";


            if ( $picture)
            {
                //add picture 
                $mail_body .= "--$boundary\r\n";  
                $mail_body .= "Content-Type: image/jpeg; name=\"photo.jpeg\"\r\n";
                $mail_body .= "Content-Transfer-Encoding: base64\r\n";
                $mail_body .= "Content-Disposition: attachment; filename=\"photo.jpeg\"\r\n\r\n";
                $mail_body .=$picture . "\r\n\r\n";  

            }
            $mail_body .= "--$boundary--\r\n\r\n";  
            return array($mail_header, $mail_body);
        }

        // adds a message as seen to a specified folder (used for saving sent mails)
        function addSentMessage($folderid, $header, $body) {
            return @imap_append($this->_mbox,$this->_server . $folderid, $header . "\r\n" . $body ,"\\Seen");
        }


        // parses address objects back to a simple "," separated string
        function parseAddr($ad) {
            $addr_string = "";
            if (isset($ad) && is_array($ad)) {
                foreach($ad as $addr) {
                    if ($addr_string) $addr_string .= ",";
                    $addr_string .= $addr->mailbox . "@" . $addr->host; 
                }
            }
            return $addr_string;
        }

        private function KolabReadContact($message,$with_uid)
        {
               
            $contact=NULL;  
            $kolabXml=NULL;
            $images=array();
            if(isset($message->parts)) 
            {
                $parts=$message->parts;
                foreach($parts as $part) 
                {
                    if(isset($part->disposition) && ($part->disposition == "attachment" || $part->disposition == "inline")) 
                    {
                        $type=$part->headers;
                        //kolab contact attachment ? 
                        $ctype=explode(";",$type["content-type"] )  ;
                        if ($ctype[0] == " application/x-vnd.kolab.contact")
                        { 
                            $kolabXml=$part->body;
                        }
                        if ($ctype[0] == " image/jpeg")
                        { 
                            $name=$part->ctype_parameters["name"];  
                            $images[$name]=$part->body;
                        }
                        $n++;
                    }
                }
                if (! $kolabXml)
                {
                    //nothing in the mail
                    return "";
                }
                //processing
                $factory=new Horde_Kolab_Format;
                $format = $factory->factory('XML', 'contact');  
                $kcontact=$format->load($kolabXml);
                unset($format); 
                unset($factory);
                if ($kcontact instanceof PEAR_Error)
                {
                    //parsing error 
                    debugLog("ERROR ".$kcontact->message);
                    debugLog("Xml kolab :     $body")  ;
                    $this->Log("ERROR ".$kcontact->message);
                    $this->Log("XML : $body")  ;   
                    unset($kcontact);
                    return ""; 

                }  
                //mappage
                $contact=new SyncContact();   
                if ( $with_uid != 0)
                {
                    $contact->uid= hex2bin($kcontact['uid']);
                }
                $contact->fileas= w2u($kcontact['last-name'].", " . $kcontact['given-name']); 
                $contact->firstname= w2u($kcontact['given-name'])   ;
                $contact->lastname= w2u($kcontact['last-name']);
                $contact->middlename=w2u($kcontact['middle-names']);
                $contact->webpage=$kcontact['web-page'] ;
                $contact->jobtitle=w2u($kcontact["job-title"]) ;
                $contact->title=w2u($kcontact["prefix"]) ;
                $contact->suffix=w2u($kcontact['suffix']);
                $contact->companyname =w2u($kcontact['organization']) ;
                $contact->email1address=$kcontact['emails']; 
                if ( isset($kcontact["picture"]))
                {
                    $contact->picture=base64_encode($images[$kcontact["picture"]]);
                    $this->CacheWritePicture($kcontact['uid'],$contact->picture);
                }
                if (isset($kcontact["phone-business1"]))
                {
                    if ( $this->checkPhoneNumber($kcontact["phone-business1"]))
                    {
                        $contact->businessphonenumber=$kcontact["phone-business1"] ;
                    }
                    else
                    {
                        $this->Log("ERR: ".$contact->fileas ." ---> " . $kcontact["phone-business1"] );
                    }
                }
                if (isset($kcontact["phone-business2"]))
                {
                    if ( $this->checkPhoneNumber($kcontact["phone-business2"]))
                    {
                        $contact->business2phonenumber=$kcontact["phone-business1"] ;
                    }
                    else
                    {
                        $this->Log("ERR: ".$contact->fileas ." ---> " . $kcontact["phone-business2"] );
                    }
                }
                if (isset($kcontact["phone-home1"]))
                {
                    if ( $this->checkPhoneNumber($kcontact["phone-home1"]))
                    {
                        $contact->homephonenumber=$kcontact["phone-home1"] ;
                    }
                    else
                    {
                        $this->Log("ERR: ".$contact->fileas ." ---> " . $kcontact["phone-home1"] );
                    }
                }
                if (isset($kcontact["phone-mobile"]))
                {
                    if ( $this->checkPhoneNumber($kcontact["phone-mobile"]))
                    {
                        $contact->mobilephonenumber=$kcontact["phone-mobile"] ;
                    }
                    else
                    {
                        $this->Log("ERR: ".$contact->fileas ." ---> " . $kcontact["phone-mobile"] );
                    }
                }
                if (isset($kcontact["phone-businessfax"]))
                {
                    if ( $this->checkPhoneNumber($kcontact["phone-businessfax"]))
                    {
                        $contact->businessfaxnumber=$kcontact["phone-businessfax"] ;
                    }
                    else
                    {
                        $this->Log("ERR: ".$contact->fileas ." ---> " . $kcontact["phone-businessfax"] );
                    }
                }
                $contact->otherstreet=w2u($kcontact["addr-other-street"]);
                $contact->othercity=w2u($kcontact["addr-other-locality"]);
                $contact->otherpostalcode=$kcontact["addr-other-postal-code"];
                $contact->otherstate=$kcontact["addr-other-region"]   ;   
                $contact->othercountry=w2u($kcontact["addr-other-country"]);
                $contact->businessstreet=w2u($kcontact["addr-business-street"]);
                $contact->businesscity=w2u($kcontact["addr-business-locality"]);
                $contact->businesspostalcode=$kcontact["addr-business-postal-code"];
                $contact->businessstate=$kcontact["addr-business-region"]   ;   
                $contact->businesscountry=w2u($kcontact["addr-business-country"]);
                $contact->homestreet=w2u($kcontact["addr-home-street"]);
                $contact->homecity=w2u($kcontact["addr-home-locality"]);
                $contact->homepostalcode=$kcontact["addr-home-postal-code"];
                $contact->homestate=$kcontact["addr-home-region"]   ;
                $contact->homecountry=w2u($kcontact["addr-home-country"]);
                $contact->body=w2u($kcontact['body'] );
                $contact->spouse=w2u($kcontact['spouse-name']);
                $contact->nickname=w2u($kcontact['nick-name']);
                $contact->pagernumber=w2u($kcontact['phone-pager']);
                $contact->assistantname=w2u($kcontact['assistant']);
                $contact->department=w2u($kcontact['department']);
                $contact->officelocation=w2u($kcontact{'office-location'});
                if (isset($kcontact['anniversary']))
                {
                    $contact->anniversary=$this->KolabDate2Unix($kcontact['anniversary']);
                }
                if (isset($kcontact['birthday']))
                {     
                    $contact->birthday=$this->KolabDate2Unix($kcontact['birthday']);
                }
                if ($kcontact["children"])
                {
                    $children=array();
                    $children[]= $kcontact["children"];
                    $contact->children=$children;
                }
                if ($contact->fileas == false)
                {
                    $contact->fileas =w2u($kcontact["organization"]);
                } 
                if ($contact->fileas == false)
                {
                    $contact->fileas =$kcontact["phone-mobile"];
                } 
                if ($contact->fileas == false)
                {
                    $contact->fileas =$kcontact["phone-business1"];
                } 
                if ($contact->fileas == false)
                {
                    $this->Log("ERR: fileAs empty" );
                }
                return $contact;
            }
            return ""     ;
        }
        private function checkPhoneNumber($phone)
        {
            if (preg_match( '/^[0-9,\+,\*,\#,\(,\),\s,\.\-]+$/', $phone))
            {
                return $phone;
            }    
            return "";
        }
        private function KolabWriteContact($message,$uid)
        {
            if ( $uid == '')
            {
                $uid =  strtoupper(md5(uniqid(time())));
            }
            $object = array(
            'uid' => $uid,
            'full-name' => u2w($message->asfile) ,
            'given-name' =>u2w($message->firstname),
            'last-name' => u2w($message->lastname),
            'middle-names' => u2w($message->middlename),
            'prefix' => u2w($message->title),
            'suffix' => u2w($message->suffix),
            'job-title' => u2w($message->jobtitle),
            'web-page' => $message->webpage,
            'emails' => $message->email1address,
            'phone-mobile' => $message->mobilephonenumber,
            'phone-business1' => $message->businessphonenumber,
            'phone-business2' => $message->business2phonenumber,
            'phone-home1' => $message->homephonenumber,
            'phone-pager' => $message->pagernumber,
            'phone-businessfax' => $message->businessfaxnumber,
            'addr-business-street' => u2w($message->businessstreet),
            'addr-business-locality' => u2w($message->businesscity),
            'addr-business-postal-code' => $message->businesspostalcode,
            'addr-business-region' => $message->businessstate,
            'addr-business-country' => $message->businesscountry,
            'addr-home-street'=> u2w($message->homestreet),
            'addr-home-locality'  => u2w($message->homecity)  ,
            'addr-home-postal-code' => $message->homepostalcode, 
            'addr-home-region' => $message->homesstate,  
            'addr-home-country' => $message->homecountry,  
            'addr-other-street'=> u2w($message->otherstreet),
            'addr-other-locality'  => u2w($message->othercity)  ,
            'addr-other-postal-code' => $message->otherpostalcode, 
            'addr-other-region' => $message->othersstate,  
            'addr-other-country' => $message->othercountry,              
            'organization' => u2w($message->companyname) ,
            'department' => u2w($message->department),  
            'spouse-name'=> u2w($message->spouse),
            'children' =>u2w($message->children),
            'nick-name'=> u2w($message->nickname),
            'assistant' => u2w($message->assistantname),
            'department' => u2w($message->department) ,
            'office-location' => u2w($message->officelocation)
            );
            if ($message->body != "")
            {
                $object['body']=u2w($message->body);
            }
            elseif ($message->rtf)
            {
                $object['body']=$this->rtf2text($message->rtf);
            }
            //bithday
            if (  isset($message->anniversary))
            {
                $object['anniversary'] =substr($this->KolabDateUnix2Kolab($message->anniversary),0,10);
            }
            if (  isset($message->birthday))
            {
                $object['birthday']  = substr($this->KolabDateUnix2Kolab($message->birthday),0,10);
            }
            //children
            $children=$message->children;
            if ($children != NULL)
            {
                $object['children']=join(",",$children);
            }
            //picture 
            if ( is_null($message->picture) )
            {
                //no image or not modified
                //check if picture has been modified 
                $message->picture=$this->CacheReadPicture($uid);
                if ($message->picture)
                {
                    $object['picture'] ="photo.jpeg";   
                }
            }
            else
            {
                if ( $message->picture == "")
                {
                    //erase the picture
                    $this->CacheDeletePicture($uid);      
                }
                else
                {
                    $object['picture'] ="photo.jpeg";   
                    $this->CacheWritePicture($uid,$message->picture);
                }
            }

            //check mail for android
            if (preg_match("/\<(.+)\>/",$object['emails'],$m))
            {
                $object['emails']=$m[1];
            }
            //fulname empty    (happen sometimes with iphone)
            if ( $object['full-name'] == "")
            {
                $object['full-name']= $object['given-name']. ' ' . $object['last-name'];
            }
            $format = Horde_Kolab_Format::factory('XML', 'contact');  
            $xml = $format->save($object);
            unset($format);
            // set the mail 
            // attach the XML file 
            $mail=$this->mail_attach("kolab.xml",0,$xml,"kolab message","text/plain", "plain","application/x-vnd.kolab.contact",$message->picture); 
            //add the picture if needed
            //add header
            $h["from"]=$this->_email;
            $h["to"]=$this->_email; 
            $h["X-Mailer"]="z-push-Kolab Backend";
            $h["subject"]= $object["uid"];
            $h["message-id"]= "<" . strtoupper(md5(uniqid(time()))) . ">";
            $h["date"]=date(DATE_RFC2822);
            foreach(array_keys($h) as $i)
            {
                $header= $header . $i . ": " . $h[$i] ."\r\n";
            }
            //return the mail formatted
            return array($uid,$h['date'],$header  .$mail[0]."\r\n" .$mail[1]);

        }

        private function KolabReadEvent($message,$id,$disableAlarm=false)
        {
            $event=NULL; 
            //searching the righ attachment Kolab XML 
            if(isset($message->parts)) 
            {
                foreach($message->parts as $part) 
                {
                    if(isset($part->disposition) && ($part->disposition == "attachment" || $part->disposition == "inline")) 
                    {
                        $type=$part->headers;
                        //kolab contact attachment ? 
                        $ctype=explode(";",$type["content-type"] )  ;
                        if ($ctype[0] == " application/x-vnd.kolab.event")
                        {
                            $format = Horde_Kolab_Format::factory('XML', 'event');  
                            $body=$part->body;  
                            $kevent=$format->load($body); 
                            unset($format);
                            if ($kevent instanceof PEAR_Error)
                            {
                                //parsing error 
                                debugLog("ERROR ".$kevent->message);
                                debugLog("Xml kolab :     $body")  ;
                                $this->Log("ERROR ".$kevent->message);
                                $this->Log("XML : $body")  ;
                                unset ($kevent);  
                                return "";
                            }

                            //mappage
                            $event=new SyncAppointment();
                            $event->uid = hex2bin($kevent['uid']);
                            $event->dtstamp = time();
                            $event->subject=w2u($kevent['summary']);
                            $event->starttime=$kevent['start-date'];
                            $event->endtime=$kevent['end-date'];

                            switch(strtolower($kevent['sensitivity']))
                            {
                                case "private":
                                $event->sensitivity="2";
                                break;
                                case "confidential":
                                $event->sensitivity="3"; 
                            }
                            //bug #9 Alarm mus not be shown for all folders
                            if ($disableAlarm == false)
                            {
                                if ($kevent['alarm'] > 0)
                                {
                                    $event->reminder=$kevent['alarm'];
                                }
                            }
                            else
                            {
                                $event->reminder=NULL;
                            }
                            $event->location=w2u($kevent['location']);
                            $event->busystatus="2";
                            if ($kevent['show-time-as'] == 'busy' )
                            {
                                $event->busystatus="2";
                            }
                            elseif ($kevent['show-time-as'] == 'free')
                            {
                                $event->busystatus="0"; 
                            }
                            elseif ($kevent['show-time-as'] == 'tentative')
                            {
                                $event->busystatus="1"; 
                            }
                            elseif ($kevent['show-time-as'] == 'outofoffice')
                            {
                                $event->busystatus="3"; 
                            } 
                            $event->body=w2u($kevent['body']);
                            //sensitivity 
                            $event->meetingstatus="0";
                            $event->alldayevent="0";
                            //timezone must be fixed
                            $event->timezone="xP///wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAoAAAAFAAMAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAMAAAAFAAIAAAAAAAAAxP///w==" ;
                            $event->bodytruncated = 0;  
                            if (isset($kevent["organizer"]["smtp-address"]))
                            { 
                                $event->organizername=w2u($kevent["organizer"]["display-name"]);
                                $event->organizeremail=w2u($kevent["organizer"]["smtp-address"]);
                            }
                            else
                            { 
                                $event->organizername=w2u($this->_cn);
                                $event->organizeremail=w2u($this->_email);
                            }

                            //reccurence process
                            if (isset($kevent["recurrence"]))
                            {
                                $event->reccurence=$this->kolabReadRecurrence($kevent);
                            }
                            return $event;
                        }
                        $n++;
                    }
                }
            }
            return ""     ;

        }
        private function kolabReadRecurrence($kevent,$type=0)
        {
            $numMonth=array(
            "january"   => 1,
            "february"  => 2,
            "march"     => 3,
            "april"     => 4,
            "may"       => 5,
            "june"      => 6,
            "july"      => 7,
            "august"    => 8,
            "september" => 9,
            "october"   => 10,
            "november"  => 11,
            "december"  => 12
            );
            if (isset($kevent["recurrence"]))
            {
                if ($type == 0)
                {
                    $recurrence = new SyncRecurrence;
                }
                else
                {
                    $recurrence= new SyncTaskRecurrence;
                } 
                $rec=$kevent["recurrence"];
                //cycle
                if ($rec["cycle"] == "daily")
                {
                    $recurrence->type =  0 ;
                }
                elseif($rec["cycle"] == "weekly")
                {
                    $recurrence->type =  1 ;
                    //dayofweek
                    //tableau jour 1=sunday 128 =saturday 
                    $nday=0;
                    $recurrence->dayofweek=$this->KolabDofW2pda($rec["day"]);
                }  
                elseif($rec["cycle"] == "monthly")  
                {

                    // sous type by day 
                    if ($rec["type"] == "daynumber")
                    {
                        $recurrence->type =  2 ;    
                        $recurrence->dayofmonth =   $rec["daynumber"] ;
                    } 
                    elseif ($rec["type"] == "weekday") 
                    {
                        $recurrence->type =  3 ;        
                        $recurrence->weekofmonth = $rec["daynumber"];
                        //day of week 
                        $recurrence->dayofweek=$this->KolabDofW2pda($rec["day"]); 
                    }       
                }
                // year
                elseif($rec["cycle"] == "yearly")   
                {
                    if ($rec["type"] == "monthday")
                    {

                        $recurrence->type =5   ;  
                        $recurrence->dayofmonth =   $rec["daynumber"] ;
                        $recurrence->monthofyear= $numMonth[$rec["month"]];
                    }
                    elseif ($rec["type"] == "weekday")
                    {
                        $recurrence->type =6   ;  
                        $recurrence->weekofmonth = $rec["daynumber"];
                        $recurrence->monthofyear= $numMonth[$rec["month"]];
                        $recurrence->dayofweek=$this->KolabDofW2pda($rec["day"]); 
                    }
                }
                //interval
                $recurrence->interval = $rec["interval"] ;
                //range
                if ($rec["range-type"] == "number")
                {
                    $recurrence->occurrences =$rec["range"];
                }  
                elseif ($rec["range-type"] == "date") 
                {
                    if ( strtolower($_GET["DeviceType"]) == "iphone")     
                    {
                        $recurrence->until =$rec["range"] + 93599;
                    }
                    else
                    {
                        $recurrence->until =$rec["range"];
                    }

                }    

                return $recurrence;
            }
            else
            {
                return NULL;
            }
        }
        private function KolabWriteEvent($message,$uid)
        {  


            $attendee = array(
            'display-name'  => $this->_cn,
            'smtp-address'  => $this->_email,
            'uid'           => ""  
            );
            $object = array(
            'uid' => bin2hex($message->uid),
            'start-date' => $message->starttime,
            'end-date'   => $message->endtime,
            'summary'   => u2w($message->subject),
            'reminder'  => $message->reminder,
            'location'  => $message->location,
            'alarm' => $message->reminder,
            'color-label' => "none"    ,
            'show-time-as' => "busy",
            'organizer' => $attendee,
            'location' => u2w($message->location)
            );
            if ($message->body != "")
            {
                $object['body']=u2w($message->body);
            }
            elseif ($message->rtf)
            {
                $object['body']=$this->rtf2text($message->rtf);
            }
            if ($message->alldayevent == 1)
            {
                $object['_is_all_day']=True;
            }
            switch($message->busystatus )
            {
                case 0:
                $object['show-time-as'] = "free";
                break;
                case 1:
                $object['show-time-as'] = "tentative";
                break;
                case 2:
                $object['show-time-as'] = "busy";
                break;
                case 3:
                $object['show-time-as'] = "outofoffice";
                break;

            }
            switch($message->sensitivity)
            {
                case 1:
                case 2: 
                $object["sensitivity"] = "private";
                break;
                case 3:
                $object["sensitivity"] = "confidential";
            }

            //recurence
            if(isset($message->recurrence)) 
            {
                $object["recurrence"]=$this->kolabWriteReccurence($message->reccurence);
            }
            $format = Horde_Kolab_Format::factory('XML', 'event');  
            $xml = $format->save($object);
            unset($format);
            // set the mail 
            // attach the XML file 
            $mail=$this->mail_attach("kolab.xml",0,$xml,"kolab message","text/plain", "plain","application/x-vnd.kolab.event"); 
            //add header
            $h["from"]=$this->_email;
            $h["to"]=$this->_email; 
            $h["X-Mailer"]="z-push-Kolab Backend";
            $h["subject"]= $object["uid"];
            $h["message-id"]= "<" . strtoupper(md5(uniqid(time()))) . ">";
            $h["date"]=date(DATE_RFC2822);
            foreach(array_keys($h) as $i)
            {
                $header= $header . $i . ": " . $h[$i] ."\r\n";
            }
            //return the mail formatted
            return array($object['uid'],$h['date'],$header  .$mail[0]."\r\n" .$mail[1]);

        }
        private function kolabWriteReccurence($reccurence)
        {
            $month=array("dummy","january","february","march","april","may","june","july","august","september","october","november","december");
            $rec=array();
            switch($recurrence->type) 
            {
                case 0:
                //repeat daily 
                $rec["cycle"] = "daily";
                break;
                case 1:
                //repeat weekly 
                $rec["cycle"] = "weekly";  
                $rec["day"] = $this->KolabPda2DofW($recurrence->dayofweek );
                break;
                case 2:
                //montly daynumber
                $rec["cycle"] = "monthly";
                $rec["type"] ="daynumber";
                $rec["daynumber"] =$recurrence->dayofmonth  ;
                break;
                case 3:
                //monthly day of week
                $rec["cycle"] = "monthly";
                $rec["type"] ="weekday";
                $rec["daynumber"] =$recurrence->weekofmonth  ;
                $rec["day"] = $this->KolabPda2DofW($recurrence->dayofweek ); 
                break;  
                case 5:
                //yearly 
                $rec["cycle"] = "yearly";
                $rec["type"] ="monthday";
                $rec["daynumber"] =$recurrence->dayofmonth  ;
                $rec["month"]=$month[$recurrence->monthofyear];
                break;  
                //   
                case 6:
                //yearly 
                $rec["cycle"] = "yearly";
                $rec["type"] ="weekday";
                $rec["daynumber"] =$recurrence->weekofmonth  ;
                $rec["month"]=$month[$recurrence->monthofyear];
                $rec["day"] = $this->KolabPda2DofW($recurrence->dayofweek ); 
                break;  
            }
            //interval
            if (isset($recurrence->interval))
            {
                $rec["interval"] = $recurrence->interval;
            }
            else
            {
                $rec["interval"] = 1;
            }
            if (isset($recurrence->occurrences))
            {
                //by ocurence
                $rec["range-type"] = "number";
                $rec["range"] =$recurrence->occurrences;
            }
            elseif (isset($recurrence->until))
            {
                //by end date
                $rec["range-type"] = "date";
                if ( strtolower($_GET["DeviceType"]) == "iphone" || strtolower($_GET["DeviceType"]) == "ipod")
                {
                    $rec["range"] =$recurrence->until  - 93599 ; 
                }
                else
                {
                    $rec["range"] =$recurrence->until;
                }
            } 
            else
            {
                $rec["range-type"] ="none";
            }
            return $rec;   
        } 
        private function KolabReadTask($message,$id,$disableAlarm=false,$with_uid=false)
        {
            $task=NULL; 
            
            if(isset($message->parts)) 
            {
                foreach($message->parts as $part) 
                {
                    if(isset($part->disposition) && ($part->disposition == "attachment" || $part->disposition == "inline")) 
                    {
                        $type=$part->headers;
                        //kolab contact attachment ? 
                        $ctype=explode(";",$type["content-type"] )  ;
                        if ($ctype[0] == " application/x-vnd.kolab.task")
                        {
                            $format = Horde_Kolab_Format::factory('XML', 'task');  
                            $body=$part->body;  
                            $ktask=$format->load($body); 
                            unset($format);
                            if ($ktask instanceof PEAR_Error)
                            {
                                //parsing error 
                                debugLog("ERROR ".$ktask->message);
                                debugLog("Xml kolab :     $body")  ;
                                $this->Log("ERROR ".$ktask->message);
                                $this->Log("XML : $body")  ;
                                unset ($ktask);  
                                return "";
                            }

                            //mappage
                            $task=new SyncTask();
                            if ( $with_uid != 0)
                            {
                                $task->uid= hex2bin($ktask['uid']);
                            }
                            $task->subject=w2u($ktask['name']);
                            if ($ktask['start'])
                            {
                                $offset=date('Z',$ktask['start']);
                                $task->utcstartdate=$kstart['start'];
                                $task->startdate=$ktask['start'] + $offset;
                            }
                            if($ktask['due'])
                            {
                                $offset=date('Z',$ktask['due']);
                                $task->utcduedate=$ktask['due'];
                                $task->duedate=$ktask['due'] + $offset;
                            }    
                            $task->complete=$ktask['completed'];
                            if (isset($ktask['completed_date']))
                            {
                                $task->datecompleted=$ktask['completed_date'];
                            }
                            //categories
                            if (isset($ktask['categories']))
                            {
                                $cat=split(',',w2u($ktask['categories']));
                                $task->categories=$cat;
                            }
                            switch($ktask['priority'])
                            {
                                case 1: $task->importance= 2;
                                break;
                                case 2:
                                case 3:
                                case 4: $task->importance=1;
                                break;
                                case 5: $task->importance=0; 
                            }
                            switch(strtolower($ktask['sensitivity']))
                            {
                                case "public":
                                $task->sensitivity=0;
                                break;
                                case "private":
                                $task->sensitivity=2;
                                break;
                                case "confidential":
                                $task->sensitivity=3; 
                            }
                            //bug #9 Alarm mus not be shown for all folders
                            if ($disableAlarm == false)
                            {
                                if ($ktask['alarm'] > 0)
                                {
                                    $task->remindertime=$ktask["start"] +($ktask['alarm'] * 60);
                                    $task->reminderset=1;
                                }
                            }
                            else
                            {
                                $task->reminderset=NULL;
                                $task->remindertime=NULL;
                            }
                            $task->body=w2u($ktask['body']);
                            //timezone must be fixed
                            $task->bodytruncated = 0;  
                            //reccurence process
                            if (isset($ktask["recurrence"]))
                            {
                                $task->reccurence=$this->kolabReadRecurrence($ktask,1);
                            }
                            return $task;
                        }
                        $n++;
                    }
                }
            }
            return ""     ;

        }
        private function KolabWriteTask($message,$id)
        {
            
            if ( ! $id )
            {
                $uid=strtoupper(md5(uniqid(time())));
            }
            else 
            {
                $uid=$id;
            }
            $object = array(
            'uid' => $uid,
            'start' => $message->utcstartdate,
            'due'   => $message->utcduedate,
            'name'   => u2w($message->subject),
            );
            if (isset($message->rtf))
            {
                $object['body']=$this->rtf2text($message->rtf);                  
            }
            if ($message->reminderset == 1)
            {
                $object['alarm']=($message->remindertime - $message->utcstartdate) / 60; 
            }
            //categories
            if (isset($message->categories))
            {
                $object['categories']=u2w(join(',',$message->categories));
            }
            switch($message->importance)
            {
                case 0: $object["priority"] = 5;
                break;
                case 1: $object["priority"] = 3;
                break;      
                case 2: $object["priority"] = 1;
                break;                 
            }
            if ( $message->complete == 1)
            {
                $object['completed'] = 100;
                $object['completed_date'] = $message->datecompleted;
            }
            else
            {
                $object['completed'] = 0;
            }
            switch($message->sensitivity)
            {
                case 1:
                case 2: 
                $object["sensitivity"] = "private";
                break;
                case 3:
                $object["sensitivity"] = "confidential";
            }

            //recurence
            if(isset($message->recurrence)) 
            {
                $object["recurrence"]=$this->kolabWriteReccurence($message->reccurence);
            }
            $format = Horde_Kolab_Format::factory('XML', 'task');  
            $xml = $format->save($object);
            unset($format);
            // set the mail 
            // attach the XML file 
            $mail=$this->mail_attach("kolab.xml",0,$xml,"kolab message","text/plain", "plain","application/x-vnd.kolab.task"); 
            //add header
            $h["from"]=$this->_email;
            $h["to"]=$this->_email; 
            $h["X-Mailer"]="z-push-Kolab Backend";
            $h["subject"]= $object["uid"];
            $h["message-id"]= "<" . strtoupper(md5(uniqid(time()))) . ">";
            $h["date"]=date(DATE_RFC2822);
            foreach(array_keys($h) as $i)
            {
                $header= $header . $i . ": " . $h[$i] ."\r\n";
            }
            //return the mail formatted
            return array($object['uid'],$h['date'],$header  .$mail[0]."\r\n" .$mail[1]);

        }
        //return the date for Kolab
        private function KolabDateUnix2Kolab($timestamp)
        {
            $d=date(DATE_W3C ,$timestamp);
            $d=substr($d,0,19) . "Z"  ;
            return $d;
        }
        private function KolabDate2Unix($kdate)
        {
            if (! $kdate)
            {
                return NULL;
            }
            else
            {
                $tm= gmmktime(0, 0, 0, substr($kdate,5,2), substr($kdate,8,2), substr($kdate,0,4)); 
                return $tm;
            }
        }
        /*CacheCreateIndex : create an index to retrieve easly the uid-> id and the id->uid 
        */
        private function CacheCreateIndex($folderid,$kolab_uid,$imap_uid)
        {

            $kolab_uid=strtoupper($kolab_uid);
            $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid);
            $this->_cache->write("IMAP:".$folderid."/".$imap_uid,$kolab_uid);
            $this->_cache->write("KOLAB:".$folderid."/".$kolab_uid,$imap_uid);
            //must another index to find the folder of the uid
            $this->_cache->write("FLMODE:".$kolab_uid, $folderid); 
            $this->_cache->close();
        }
        private function CacheIndexUid2FolderUid($uid)
        {
            $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid); 
            $result= $this->_cache->find("FLMODE:".$uid);
            $this->_cache->close();
            return $result; 
        }
        private function CacheStoreMessageList($folder,$mlist)
        {
            $stat=serialize($mlist);
            $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid);
            $this->_cache->write("MLIST:".$folder,$stat);
            $this->_cache->close(); 
        }
        private function CacheReadMessageList($folder)
        {
            $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid); 
            $result= $this->_cache->find("MLIST:".$folder);
            $this->_cache->close();
            return unserialize($result); 
        }
        private function CacheStoreImapStatus($folder,$stat)
        {

            $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid);
            $this->_cache->write("FIMAPSTAT:".$folder,serialize($stat));
            $this->_cache->close();
        }
        private function CacheReadImapStatus($folder)
        {
            $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid); 
            $result= $this->_cache->find("FIMAPSTAT:".$folder);
            $this->_cache->close();
            return unserialize($result); 
        }
        private function CacheIndexId2Uid($folderid,$id)
        {
            $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid); 
            $result= $this->_cache->find("IMAP:".$folderid."/".$id);
            $this->_cache->close();
            return $result;
        }
        private function CacheIndexUid2Id($folderid,$uid)
        {
            $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid);  
            $result= $this->_cache->find("KOLAB:".$folderid."/".$uid);
            $this->_cache->close();
            return $result;
        }
        private function CacheIndexDeletebyId($folderid,$id)
        {

            $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid);  
            $uid= $this->_cache->find("IMAP:".$folderid."/".$id);
            $this->_cache->delete("IMAP:".$folderid."/".$id);
            $this->_cache->delete("KOLAB:".$folderid."/".$uid);
            $this->_cache->delete("ENDDATE:".$folderid."/".$uid);   
            $this->_cache->delete("FLMODE:".$uid); 
            $this->_cache->close();
            return $result;
        }
        private function CacheCheckVersion()
        {

            $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid); 
            $version= $this->_cache->find("CACHEVERSION"); 
            if ( $version != KOLABBACKEND_VERSION)
            {
                //reinit cache
                $this->_cache->close();
                $this->_cache->purge();
                $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid);   
                $this->_cache->write("CACHEVERSION",KOLABBACKEND_VERSION);
            } 
            $this->_cache->close();     
        }
        private function CacheIndexClean($messagelist)
        {
            return;
        }
        private function CacheWriteFolderParam($folder,$fa)
        {
            $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid);
            $this->_cache->write("FOLDERPARAM:".$folder,$fa->serialize());
            $this->_cache->close();
        }
        private function CacheReadFolderParam($folder)
        {
            $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid); 
            $result= $this->_cache->find("FOLDERPARAM:".$folder);
            $this->_cache->close();
            $fa=new folderParam();
            $fa->unserialize($result);
            return $fa; 
        }
        private function KolabgetMail($username)
        {
            return  $username . "@localhost.localdomain";
        }
        private function KolabDofW2pda($recday)
        {
            foreach ($recday as $day)
            {
                if($day == "sunday")
                {
                    $nday=$nday +1;
                }
                elseif ($day == "monday")
                {
                    $nday=$nday +2; 
                }
                elseif ($day == "tuesday")
                {
                    $nday=$nday + 4; 
                }
                elseif ($day == "wednesday")
                {
                    $nday=$nday + 8; 
                }
                elseif ($day == "thursday")
                {
                    $nday=$nday + 16; 
                }
                elseif ($day == "friday")
                {
                    $nday=$nday + 32; 
                }
                elseif ($day == "saturday")
                {
                    $nday=$nday + 64; 
                }                           
            }
            return $nday;

        }
        private function KolabPda2DofW($value)
        {
            $days=array();
            $test = $value & 8;
            $value=$value *1; //conversion in long ...
            if ( ($value & 1) >0){$days[]="sunday";}   
            if ( ($value & 2) >0){$days[]="monday";}
            if ( ($value & 4) >0){$days[]="tuesday";}     
            if ( ($value & 8) >0)
            {
                $days[]="wednesday";
            }     
            if ( ($value & 16) >0){$days[]="thursday";}     
            if ( ($value & 32) >0){$days[]="friday";}     
            if ( ($value & 64) >0){$days[]="saturday";}     
            return $days ;     
        }  
        private function Log($message) {
            if (KOLAB_LOGFILE != ""  )
            {
                @$fp = fopen(KOLAB_LOGFILE ,"a+");
                @$date = strftime("%x %X");
                @fwrite($fp, "$date [". getmypid() ."] : " . $this->_username . " : $message\n");
                @fclose($fp);
            }
        }
        private function KolabStat($fid,$o)
        {

            if ( !$o)
            {
                return false;
            }
            $kolab_uid="";
            $m= array();
            $m["mod"] = $fid .'/'.$o->uid;
            //search the kolab uid in index if nofound read the mail to find it 
            $kolab_uid=$this->CacheIndexId2Uid($fid,$o->uid);
            if (! $kolab_uid)
            {
                //no found read the message 
                $mail = @imap_fetchheader($this->_mbox, $o->uid, FT_PREFETCHTEXT | FT_UID) . @imap_body($this->_mbox, $o->uid, FT_PEEK | FT_UID);
                $mobj = new Mail_mimeDecode($mail);
                $message = $mobj->decode(array('decode_headers' => true, 'decode_bodies' => true, 'include_bodies' => true, 'input' => $mail, 'crlf' => "\n", 'charset' => 'utf-8'));
                if ($this->kolabFolderType($fid) == 2)
                {

                    $ev=$this->KolabReadEvent($message,false) ;
                    if (! $ev)
                    {
                        return false ;
                    }
                    $kolab_uid=strtoupper(bin2hex($ev->uid));

                    //index
                    if ($kolab_uid){
                        
                        $this->CacheCreateIndex($fid,$kolab_uid,$o->uid);
                        //index of the endDate too 
                        $this->CacheWriteEndDate($fid,$ev);
                        if ( $ev->sensitivity != 0)
                        {
                            //add in cache the sensitivity
                            $this->CacheWriteSensitivity($kolab_uid,$ev->sensitivity);
                        }
                    }
                    else
                    {
                        return False;
                    }
                }
                if ($this->kolabFolderType($fid) == 1)
                {
                    $ev=$this->KolabReadContact($message,1) ;
                    $kolab_uid=strtoupper(bin2hex($ev->uid));
                    //index
                    if ($kolab_uid){
                        $this->CacheCreateIndex($fid,$kolab_uid,$o->uid);
                    }
                    else
                    {
                        return False;
                    }
                }
                if ($this->kolabFolderType($fid) == 3)
                {
                    $ev=$this->KolabReadTask($message,false,false,1) ;
                    $kolab_uid=strtoupper(bin2hex($ev->uid));
                    //index
                    if ($kolab_uid){
                        $this->CacheCreateIndex($fid,$kolab_uid,$o->uid);
                        if ( $ev->sensitivity != 0)
                        {
                            //add in cache the sensitivity
                            $this->CacheWriteSensitivity($kolab_uid,$ev->sensitivity);
                        }
                        if ( $ev->complete)
                        {
                            $this->CacheWriteTaskCompleted($kolab_uid,$ev->complete);
                        }
                    }
                    else
                    {
                        return False;
                    }
                }
            }
            $m["id"] = $kolab_uid;
            //$m["mod"]=  $o->uid;
            // 'seen' aka 'read' is the only flag we want to know about
            $m["flags"] = 0;
            return $m;            
        }
        private function getImapFolderType($folder)
        {
            
            if (function_exists("imap_getannotation"))
            {
                $result = imap_getannotation($this->_mbox, $folder, "/vendor/kolab/folder-type", "value.shared");
                if (isset($result["value.shared"]))
                {
                    $anno=$result["value.shared"];
                }
                else
                {
                    $anno="";
                }
            } 
            else
            {
                $rec="";
                $anno="";
                $fp = fsockopen(KOLAB_SERVER,KOLAB_IMAP_PORT, $errno, $errstr, 30);
                if (!$fp) 
                {
                    return false;
                } else {
                    //vidage greeting
                    $rec=$rec .  stream_get_line($fp,1024,"\r\n");
                    $rec="";
                    //envoi login ;
                    $out = "01 LOGIN " . $this->_username." ". $this->_password ."\r\n";
                    fwrite($fp, $out);   
                    $rec=$rec .  stream_get_line($fp,1024,"\r\n");
                    if (ereg("01 OK",$rec))
                    {
                        $r=array();
                        //envoi de la commande myrights
                        $out='ok getannotation "'.$folder.'" "/vendor/kolab/folder-type" "value"' ."\r\n";
                        fwrite($fp, $out);   
                        $rec=fread($fp,1024);
                        $r=split("\r\n",$rec);
                        $rec=$r[0];
                        if (ereg("ANNOTATION",$rec))
                        {
                            //bonne reponse
                            //* ANNOTATION "INBOX/Calendrier" "/vendor/kolab/folder-type" ("value.shared" "event.default")

                            $tab=array();
                            $reg=  "/value.shared\" \"(.+)\"/";
                            if (preg_match($reg,$rec,$tab))
                            {
                                $anno=$tab[1];  
                            }
                        }
                        $out="03 LOGOUT\r\n";
                        fwrite($fp, $out);   
                        fclose($fp);
                    }
                }
            }
            $tab=explode(".",$anno);
            $root=explode('/',$folder);
            if ( $root[0] != "INBOX")
            {
                if (count($tab) == 2)
                {
                    $anno = $tab[0];
                }
            }
            return $anno;


        }

        private function kolabFolderType($name)
        {
            if ( $name == "VIRTUAL/calendar")
            {
                return 2;
            }
            if ( $name == "VIRTUAL/contacts")
            {
                return 1;
            }
            if ( $name == "VIRTUAL/tasks")
            {
                return 3;
            }
            $type= $this->readFolderAnnotation($name)  ;
            if ( $type == false)
            {
                //not in the cache
                $this->saveFolderAnnotation($name);
                $type= $this->readFolderAnnotation($name)  ; 
            }
            if ($type == "task" || $type == "task.default")
            {
                return 3;
            }
            if ($type == "event" || $type == "event.default")
            {
                return 2;
            }
            if ($type == "contact" || $type == "contact.default") 
            {
                return 1;
            }
            return 0;
        }
        private function ActiveSyncFolderSyncType($name)
        {
            $type= $this->readFolderAnnotation($name)  ; 
            if ( $type == "task.default")
            {
                return SYNC_FOLDER_TYPE_TASK;
            }

            if ( $type == "event.default")
            {
                return SYNC_FOLDER_TYPE_APPOINTMENT;
            }
            if ( $type == "contact.default") 
            {
                return SYNC_FOLDER_TYPE_CONTACT;
            }
            if ( $type == "task")
            {
                //check if no default folder exist; 
                if ( $this->hasDefaultTaskFolder == false )
                {
                    if ($this->isDefaultFolder($name,KOLAB_DEFAULTFOLDER_TASK))
                    {
                        $this->hasDefaultTaskFolder= true;
                        $this->forceDefaultFolder("task",$name);
                        return SYNC_FOLDER_TYPE_TASK; 
                    }
                }
                return SYNC_FOLDER_TYPE_USER_TASK;
            }

            if ( $type == "event")
            {
                if ( $this->hasDefaultEventFolder == false )
                {
                    if ($this->isDefaultFolder($name,KOLAB_DEFAULTFOLDER_DIARY))
                    {
                        $this->Log("NOTICE no event default folder set as default: $name");
                        $this->forceDefaultFolder("event",$name); 
                        $this->hasDefaultEventFolder= true;
                        return SYNC_FOLDER_TYPE_APPOINTMENT;   
                    }
                }
                return SYNC_FOLDER_TYPE_USER_APPOINTMENT;
            }
            if ( $type == "contact") 
            {
                if ( $this->hasDefaultContactFolder == false )
                {
                    if ($this->isDefaultFolder($name,KOLAB_DEFAULTFOLDER_CONTACT))
                    {
                        $this->forceDefaultFolder("contact",$name); 
                        $this->hasDefaultContactFolder= true;
                        return SYNC_FOLDER_TYPE_CONTACT;   
                    }
                }
                return SYNC_FOLDER_TYPE_USER_CONTACT;
            }
        }
        private function isDefaultFolder($folder,$defaultchain)
        {
            $folder=strtolower($folder); 
            $f=split(":",strtolower($defaultchain));
            foreach($f as $value)
            {
                if ($value == $folder)
                {
                    return true;
                }
            }
            return false;
        }
        private function forceDefaultFolder($type,$folder)
        {
            switch ($type){
                case 1: $type="contact";
                break;
                case 2: $type="event";
                break;
                case 3: $type="task";
                break;
            }
            $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid); 
            $this->_cache->write("DEFAULT:".$type.".default",$folder); 
            $this->_cache->close();   
        }
        private function saveFolderAnnotation($foldera)
        {
            $anno=$this->getImapFolderType($foldera);
            if (!$anno)
            {
                $anno="0";
            }
            $default=explode(".",$anno);   
            //remove the default if this is not in INBOX folder 
            //we must detech just INBOX default folder
            $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid); 
            if ( isset($default[1]) && $default[1] == "default" )
            {
                if (substr($foldera,0,5) == "INBOX") 
                {

                    $this->_cache->write("DEFAULT:".$anno,$foldera);
                }
                else 
                {
                    $anno = $default[0];
                } 
            }  
            if ( $anno =="mail.sentitems")
            {
                $this->_cache->write("SENTFOLDER:",$foldera);
            }
            if ( ! $this->_cache->write("FA:".$foldera,$anno))
            {
                $this->Log("ERROR: ".KOLAB_INDEX."/".$this->_username);
            }
            $this->_cache->close();   
            $this->Log("Annotation $foldera : $anno") ;
        }
        private function readDefaultSentItemFolder()
        {
            $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid); 
            $sentf=$this->_cache->find("SENTFOLDER:");
            $this->_cache->close();
            return $sentf;
        }
        private function readFolderAnnotation($folder)
        {
            $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid); 
            $anno=$this->_cache->find("FA:".$folder);
            $this->_cache->close();
            return $anno;
        }
        private function CacheGetDefaultFolder($type)
        {
            switch ($type){
                case 1: $type="contact";
                break;
                case 2: $type="event";
                break;
                case 3: $type="task";
                break;
            }
            $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid);   
            $deffolder=$this->_cache->find("DEFAULT:".$type.".default");
            $this->_cache->close();
            return $deffolder;
        }

        private function CacheReadEndDate($folder,$uid)
        {
            $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid);    
            $deffolder=$this->_cache->find("ENDDATE:".$folder."/".$uid);
            $this->_cache->close();
            if ($deffolder == False)
            {
                $deffolder = "-1";
            }
            return $deffolder;
        }
        private function CacheWriteEndDate($folder,$event)
        {   
            $uid=strtoupper(bin2hex($event->uid));
            $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid); 
           
            $edate=-1;
            if ($event->recurrence)
            {
                //end date in the recurence ? 

                if (isset($event->recurrence->until))
                {
                    if ( strtolower($_GET["DeviceType"]) == "iphone" || strtolower($_GET["DeviceType"]) == "ipod")
                    {
                        $edate =$event->recurrence->until  - 93599 ; 
                    }
                    else
                    {
                        $edate = $event->recurrence->until;
                    }
                }
                elseif(isset($event->recurrence->occurrences))
                {
                    if ( isset($event->recurrence->interval))
                    {
                        $interval=$event->recurrence->interval;
                    }
                    else
                    {
                        $interval=1;
                    }
                    switch($event->recurrence->type) 
                    { 
                        case 0:
                        //repeat daily   
                        // enddate = startDate + (repeat +(86400 * interval))
                        $edate= $event->starttime + ($event->recurrence->occurrences *(86400* $interval)) ;
                        break;
                        case 2:    
                        //approche monts =31 to be sure to not cute it with a complex thing
                        $edate= $event->starttime + ($event->recurrence->occurrences *(2678400* $interval)) ;    
                        case 5:
                        //yearly 
                        $edate= $event->starttime + ($event->recurrence->occurrences *(31536000* $interval )) ;  
                        break; 
                    }
                }     

                //others stuffs
                }
            else
            {
                $edate=$event->endtime;
            }
            $this->_cache->write("ENDDATE:" . $folder."/".$uid,$edate);
            $this->_cache->close();         
        }
        private function CacheWriteSensitivity($uid,$sensitivity)
        {
            $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid);  
            $this->_cache->write("SENSITIVITY:" .$uid,$sensitivity);
            $this->_cache->close();         
        }
        private function CacheReadSensitivity($uid)
        {
            $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid);    
            $s=$this->_cache->find("SENSITIVITY:" .$uid);
            $this->_cache->close();
            return $s;
        }
        private function CacheWriteTaskCompleted($uid,$completed)
        {
            $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid);  
            $this->_cache->write("TCOMPLETED:" .$uid,$completed);
            $this->_cache->close();         
        }
        private function CacheReadTaskCompleted($uid)
        {
            $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid);    
            $s=$this->_cache->find("TCOMPLETED:" .$uid);
            $this->_cache->close();
            return $s;
        }
        private function CacheWritePicture($uid,$picture)
        {
            $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid);  
            $this->_cache->write("CPICTURE:" .$uid,$picture);
            $this->_cache->close();         
        }
        private function CacheReadPicture($uid)
        {
            $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid);    
            $s=$this->_cache->find("CPICTURE:" .$uid);
            $this->_cache->close();
            return $s;
        }
        private function CacheDeletePicture($uid)
        {
            $this->_cache->open(KOLAB_INDEX."/".$this->_username."_".$this->_devid);       
            $this->_cache->delete("CPICTURE:".$uid);
            $this->_cache->close(); 
        }
        private function kolabReadGlobalParam()
        {
            if (function_exists("imap_getannotation"))
            {
                $gp=new GlobalParam();
                $result = imap_getannotation($this->_mbox, "INBOX", "/vendor/kolab/activesync", "value.priv");
                if (isset($result["value.priv"])) 
                {
                    if ( ! $gp->unserialize($result["value.priv"]))
                    {
                        return $gp;
                    }
                }
                return $gp;
            }
        }
        private function kolabReadFolderParam($folder)
        {
            if (function_exists("imap_getannotation"))
            {
                $gp=new FolderParam(); 
                $result = imap_getannotation($this->_mbox, $folder, "/vendor/kolab/activesync", "value.priv");
                if (isset($result["value.priv"])) 
                {

                    if ( ! $gp->unserialize($result["value.priv"]))
                    {
                        return $gp;
                    }
                }
                return $gp;
            }
        }
        private function kolabWriteGlobalParam($gp)
        {
            if ( ! $gp)
            {
                return false ;
            }
            $anno=$gp->serialize();
            if (function_exists("imap_setannotation"))
            {
                //write annotation on the INBOX folder
                $result = @imap_setannotation($this->_mbox, "INBOX", "/vendor/kolab/activesync", "value.priv",$anno);
                if ( ! $result)
                {
                    $this->Log("write globalparam :".@imap_last_error());

                    return false;
                }
            }
            return true ;
        }
        private function getLdapAccount()
        {
            //chech if KOLAB_LDAP_SERVER is a URI or an IP
            $reg=  "/ldap:\/\/(.+):(.+)/";
            if (preg_match($reg,KOLAB_SERVER,$tab))
            {
                $addrip=$tab[1];
                $port=$tab[2];  
            }
            else
            {
                $addrip=KOLAB_SERVER;
                $port=389;
            }
            $conn=ldap_connect($addrip,$port) ;
            if ($conn == 0)
            {
                $this->Log("ERR LDAP connexion to server : " . KOLAB_SERVER . " failed");
                return 0;
            }
            if (!ldap_bind ($conn,"",""))
            {
                $this->Log("ERR LDAP Invalid credential") ;  
                return 0;
            }
            //recherche du DN a autentifier
            if ( ! $sr=ldap_search($conn,KOLAB_LDAP_BASE,"(uid=".$this->_username.")"))
            {
                $this->Log("ERR LDAP ". $this->_username ." not found")  ;
                return 0;
            }
            $entries=ldap_get_entries($conn,$sr);
            if ($entries['count'] == 1)
            {
                $this->_email=$entries[0]["mail"][0];
                $this->_cn=$entries[0]["cn"][0];
                $this->_KolabHomeServer=$entries[0]["kolabhomeserver"][0];
                $dn=$entries[0]["dn"];
                //check ACL if KOLAN_LDAP_ACL 
                if (defined("KOLAB_LDAP_ACL"))
                {
                    $grp=KOLAB_LDAP_ACL;
                }
                else
                {
                    $grp ="";
                }
                if ($grp  != "")
                {
                    //check if the dn is in the group as member
                    $r = ldap_compare($conn, $grp, "member", $dn) ;
                    if ( ! $r)
                    {
                        $this->Log("ACL member not present in $grp Access Denied");
                        return 0;
                    }
                    if ( $r == -1)
                    {
                        $this->Log("ACL group $gr not found (acces authorized)");
                    }
                }
                return 1;
            }
        }  
        private function rtf2text($data)
        {
            $rtf_body = new rtf ();
            $rtf_body->loadrtf(base64_decode($data));
            $rtf_body->output("ascii");
            $rtf_body->parse();
            $r=$rtf_body->out;
            unset($rtf_body);
            return $r;
        }     
    };  
    class userCache {
        private $_filename;
        private $_id;
        public $_lastError;
        function open($filename)
        {
            $this->_id = dba_open ($filename.".cache", "cl");

            if (!$this->_id) {
                $this->_lastError = "failed to open $filename";
                return false;
            }
            $this->_filename=$filename;
            return true;
        }
        function close()
        {
            dba_close($this->_id);
        }
        function write($key,$value)
        {
            $oldvalue=dba_fetch($key, $this->_id);
            if ( $oldvalue == $value)
            {
                //the key already exist and the value is the same we do nothing
                return 1;
            }
            if ($oldvalue) 
            {
                //the key exist but the value change
                dba_delete($key,$this->_id);
            }
            return dba_insert($key,$value, $this->_id);

        }
        function delete($key)
        {
            if (dba_exists ($key, $this->_id)) {
                return dba_delete ($key, $this->_id);
            }
            return 1;
        }
        function purge()
        {

            unlink($this->_filename."cache");

        }
        function find($key)
        {
            return dba_fetch($key,$this->_id);
        }


    }
?>
