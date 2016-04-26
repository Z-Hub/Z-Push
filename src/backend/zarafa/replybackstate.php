<?php
/***********************************************
 * File      :   replybackstate.php
 * Project   :   Z-Push
 * Descr     :   Holds the state of the ReplyBackImExporter
 *               and also the ICS state to continue on later
 *
 * Created   :   25.04.2016
 *
 * Copyright 2016 Zarafa Deutschland GmbH
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

class ReplyBackState extends StateObject {
    protected $unsetdata = array(
            'replybackstate' => array(),
            'icsstate' => "",
    );

    static public function FromState($state) {
        if (strpos($state, 'ReplyBackState') !== false) {
            return unserialize($state);
        }
        else {
            $s = new ReplyBackState();
            $s->SetICSState($state);
            $s->SetReplyBackState(array());
            return $s;
        }
    }

    static public function ToState($state) {
        if (!empty($state->GetReplyBackState())) {
            return serialize($state);
        }
        else {
            return $state->GetICSState();
        }
    }
}