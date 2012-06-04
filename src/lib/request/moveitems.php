<?php
/***********************************************
* File      :   moveitems.php
* Project   :   Z-Push
* Descr     :   Provides the MOVEITEMS command
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

class MoveItems extends RequestProcessor {

    /**
     * Handles the MoveItems command
     *
     * @param int       $commandCode
     *
     * @access public
     * @return boolean
     */
    public function Handle($commandCode) {
        if(!self::$decoder->getElementStartTag(SYNC_MOVE_MOVES))
            return false;

        $moves = array();
        while(self::$decoder->getElementStartTag(SYNC_MOVE_MOVE)) {
            $move = array();
            if(self::$decoder->getElementStartTag(SYNC_MOVE_SRCMSGID)) {
                $move["srcmsgid"] = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag())
                    break;
            }
            if(self::$decoder->getElementStartTag(SYNC_MOVE_SRCFLDID)) {
                $move["srcfldid"] = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag())
                    break;
            }
            if(self::$decoder->getElementStartTag(SYNC_MOVE_DSTFLDID)) {
                $move["dstfldid"] = self::$decoder->getElementContent();
                if(!self::$decoder->getElementEndTag())
                    break;
            }
            array_push($moves, $move);

            if(!self::$decoder->getElementEndTag())
                return false;
        }

        if(!self::$decoder->getElementEndTag())
            return false;

        self::$encoder->StartWBXML();

        self::$encoder->startTag(SYNC_MOVE_MOVES);

        foreach($moves as $move) {
            self::$encoder->startTag(SYNC_MOVE_RESPONSE);
            self::$encoder->startTag(SYNC_MOVE_SRCMSGID);
            self::$encoder->content($move["srcmsgid"]);
            self::$encoder->endTag();

            $status = SYNC_MOVEITEMSSTATUS_SUCCESS;
            $result = false;
            try {
                // if the source folder is an additional folder the backend has to be setup correctly
                if (!self::$backend->Setup(ZPush::GetAdditionalSyncFolderStore($move["srcfldid"])))
                    throw new StatusException(sprintf("HandleMoveItems() could not Setup() the backend for folder id '%s'", $move["srcfldid"]), SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID);

                $importer = self::$backend->GetImporter($move["srcfldid"]);
                if ($importer === false)
                    throw new StatusException(sprintf("HandleMoveItems() could not get an importer for folder id '%s'", $move["srcfldid"]), SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID);

                $result = $importer->ImportMessageMove($move["srcmsgid"], $move["dstfldid"]);
                // We discard the importer state for now.
            }
            catch (StatusException $stex) {
                if ($stex->getCode() == SYNC_STATUS_FOLDERHIERARCHYCHANGED) // same as SYNC_FSSTATUS_CODEUNKNOWN
                    $status = SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID;
                else
                    $status = $stex->getCode();
            }

            self::$topCollector->AnnounceInformation(sprintf("Operation status: %s", $status), true);

            self::$encoder->startTag(SYNC_MOVE_STATUS);
            self::$encoder->content($status);
            self::$encoder->endTag();

            self::$encoder->startTag(SYNC_MOVE_DSTMSGID);
            self::$encoder->content( (($result !== false ) ? $result : $move["srcmsgid"]));
            self::$encoder->endTag();
            self::$encoder->endTag();
        }

        self::$encoder->endTag();
        return true;
    }
}
?>