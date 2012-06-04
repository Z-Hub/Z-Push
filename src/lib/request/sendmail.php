<?php
/***********************************************
* File      :   sendmail.php
* Project   :   Z-Push
* Descr     :   Provides the SENDMAIL, SMARTREPLY and SMARTFORWARD command
*
* Created   :   16.02.2012
*
* Copyright 2007 - 2012 Zarafa Deutschland GmbH
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

class SendMail extends RequestProcessor {

    /**
     * Handles the SendMail, SmartReply and SmartForward command
     *
     * @param int       $commandCode
     *
     * @access public
     * @return boolean
     */
    public function Handle($commandCode) {
        $status = SYNC_COMMONSTATUS_SUCCESS;
        $sm = new SyncSendMail();

        $reply = $forward = $parent = $sendmail = $smartreply = $smartforward = false;
        if (Request::GetGETCollectionId())
            $parent = Request::GetGETCollectionId();
        if ($commandCode == ZPush::COMMAND_SMARTFORWARD)
            $forward = Request::GetGETItemId();
        else if ($commandCode == ZPush::COMMAND_SMARTREPLY)
            $reply = Request::GetGETItemId();

        if (self::$decoder->IsWBXML()) {
            $el = self::$decoder->getElement();

            if($el[EN_TYPE] != EN_TYPE_STARTTAG)
                return false;


            if($el[EN_TAG] == SYNC_COMPOSEMAIL_SENDMAIL)
                $sendmail = true;
            else if($el[EN_TAG] == SYNC_COMPOSEMAIL_SMARTREPLY)
                $smartreply = true;
            else if($el[EN_TAG] == SYNC_COMPOSEMAIL_SMARTFORWARD)
                $smartforward = true;

            if(!$sendmail && !$smartreply && !$smartforward)
                return false;

            $sm->Decode(self::$decoder);
        }
        else {
            $sm->mime = self::$decoder->GetPlainInputStream();
            // no wbxml output is provided, only a http OK
            $sm->saveinsent = Request::GetGETSaveInSent();
        }
        // Check if it is a reply or forward. Two cases are possible:
        // 1. Either $smartreply or $smartforward are set after reading WBXML
        // 2. Either $reply or $forward are set after geting the request parameters
        if ($reply || $smartreply || $forward || $smartforward) {
            // If the mobile sends an email in WBXML data the variables below
            // should be set. If it is a RFC822 message, get the reply/forward message id
            // from the request as they are always available there
            if (!isset($sm->source)) $sm->source = new SyncSendMailSource();
            if (!isset($sm->source->itemid)) $sm->source->itemid = Request::GetGETItemId();
            if (!isset($sm->source->folderid)) $sm->source->folderid = Request::GetGETCollectionId();

            // replyflag and forward flags are actually only for the correct icon.
            // Even if they are a part of SyncSendMail object, they won't be streamed.
            if ($smartreply || $reply)
                $sm->replyflag = true;
            else
                $sm->forwardflag = true;

            if (!isset($sm->source->folderid))
                ZLog::Write(LOGLEVEL_ERROR, sprintf("No parent folder id while replying or forwarding message:'%s'", (($reply) ? $reply : $forward)));
        }

        self::$topCollector->AnnounceInformation(sprintf("Sending email with %d bytes", strlen($sm->mime)), true);

        try {
            $status = self::$backend->SendMail($sm);
        }
        catch (StatusException $se) {
            $status = $se->getCode();
            $statusMessage = $se->getMessage();
        }

        if ($status != SYNC_COMMONSTATUS_SUCCESS) {
            if (self::$decoder->IsWBXML()) {
                // TODO check no WBXML on SmartReply and SmartForward
                self::$encoder->StartWBXML();
                self::$encoder->startTag(SYNC_COMPOSEMAIL_SENDMAIL);
                self::$encoder->startTag(SYNC_COMPOSEMAIL_STATUS);
                self::$encoder->content($status); //TODO return the correct status
                self::$encoder->endTag();
                self::$encoder->endTag();
            }
            else
                throw new HTTPReturnCodeException($statusMessage, HTTP_CODE_500, null, LOGLEVEL_WARN);
        }

        return $status;
    }
}
?>