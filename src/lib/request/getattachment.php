<?php
/***********************************************
* File      :   getattachment.php
* Project   :   Z-Push
* Descr     :   Provides the GETATTACHMENT command
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

class GetAttachment extends RequestProcessor {

    /**
     * Handles the GetAttachment command
     *
     * @param int       $commandCode
     *
     * @access public
     * @return boolean
     */
    public function Handle($commandCode) {
        $attname = Request::GetGETAttachmentName();
        if(!$attname)
            return false;

        try {
            $attachment = self::$backend->GetAttachmentData($attname);
            $stream = $attachment->data;
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleGetAttachment(): attachment stream from backend: %s", $stream));

            header("Content-Type: application/octet-stream");
            $l = 0;
            while (!feof($stream)) {
                $d = fgets($stream, 4096);
                $l += strlen($d);
                echo $d;

                // announce an update every 100K
                if (($l/1024) % 100 == 0)
                    self::$topCollector->AnnounceInformation(sprintf("Streaming attachment: %d KB sent", round($l/1024)));
            }
            fclose($stream);
            self::$topCollector->AnnounceInformation(sprintf("Streamed %d KB attachment", $l/1024), true);
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("HandleGetAttachment(): attachment with %d KB sent to mobile", $l/1024));

        }
        catch (StatusException $s) {
            // StatusException already logged so we just need to pass it upwards to send a HTTP error
            throw new HTTPReturnCodeException($s->getMessage(), HTTP_CODE_500, null, LOGLEVEL_DEBUG);
        }

        return true;
    }
}
?>