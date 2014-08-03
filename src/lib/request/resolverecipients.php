<?php
/***********************************************
* File      :   resolverecipients.php
* Project   :   Z-Push
* Descr     :   Provides the ResolveRecipients command
*
* Created   :   15.10.2012
*
* Copyright 2007 - 2013 Zarafa Deutschland GmbH
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

class ResolveRecipients extends RequestProcessor {

    /**
     * Handles the ResolveRecipients command
     *
     * @param int       $commandCode
     *
     * @access public
     * @return boolean
     */
    public function Handle($commandCode) {
        // Parse input
        if(!self::$decoder->getElementStartTag(SYNC_RESOLVERECIPIENTS_RESOLVERECIPIENTS))
            return false;

        $resolveRecipients = new SyncResolveRecipients();
        $resolveRecipients->Decode(self::$decoder);

        if(!self::$decoder->getElementEndTag())
            return false; // SYNC_RESOLVERECIPIENTS_RESOLVERECIPIENTS

        $resolveRecipients = self::$backend->ResolveRecipients($resolveRecipients);


        self::$encoder->startWBXML();
        self::$encoder->startTag(SYNC_RESOLVERECIPIENTS_RESOLVERECIPIENTS);

            self::$encoder->startTag(SYNC_RESOLVERECIPIENTS_STATUS);
            self::$encoder->content($resolveRecipients->status);
            self::$encoder->endTag(); // SYNC_RESOLVERECIPIENTS_STATUS


            foreach ($resolveRecipients->to as $i => $to) {
                self::$encoder->startTag(SYNC_RESOLVERECIPIENTS_RESPONSE);
                    self::$encoder->startTag(SYNC_RESOLVERECIPIENTS_TO);
                    self::$encoder->content($to);
                    self::$encoder->endTag(); // SYNC_RESOLVERECIPIENTS_TO

                    self::$encoder->startTag(SYNC_RESOLVERECIPIENTS_STATUS);
                    self::$encoder->content($resolveRecipients->status);
                    self::$encoder->endTag();

                    // do only if recipient is resolved
                    if ($resolveRecipients->status != SYNC_RESOLVERECIPSSTATUS_RESPONSE_UNRESOLVEDRECIP) {
                        self::$encoder->startTag(SYNC_RESOLVERECIPIENTS_RECIPIENTCOUNT);
                        self::$encoder->content(count($resolveRecipients->recipient));
                        self::$encoder->endTag(); // SYNC_RESOLVERECIPIENTS_RECIPIENTCOUNT

                        self::$encoder->startTag(SYNC_RESOLVERECIPIENTS_RECIPIENT);
                        $resolveRecipients->recipient[$i]->Encode(self::$encoder);
                        self::$encoder->endTag(); // SYNC_RESOLVERECIPIENTS_RECIPIENT
                    }

                self::$encoder->endTag(); // SYNC_RESOLVERECIPIENTS_RESPONSE
            }

        self::$encoder->endTag(); // SYNC_RESOLVERECIPIENTS_RESOLVERECIPIENTS
        return true;
    }
}
?>