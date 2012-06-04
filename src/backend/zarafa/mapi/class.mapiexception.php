<?php
/*
 * Copyright 2005 - 2012  Zarafa B.V.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation with the following additional
 * term according to sec. 7:
 *
 * According to sec. 7 of the GNU Affero General Public License, version
 * 3, the terms of the AGPL are supplemented with the following terms:
 *
 * "Zarafa" is a registered trademark of Zarafa B.V. The licensing of
 * the Program under the AGPL does not imply a trademark license.
 * Therefore any rights, title and interest in our trademarks remain
 * entirely with us.
 *
 * However, if you propagate an unmodified version of the Program you are
 * allowed to use the term "Zarafa" to indicate that you distribute the
 * Program. Furthermore you may use our trademarks where it is necessary
 * to indicate the intended purpose of a product or service provided you
 * use it in accordance with honest practices in industrial or commercial
 * matters.  If you want to propagate modified versions of the Program
 * under the name "Zarafa" or "Zarafa Server", you may only do so if you
 * have a written permission by Zarafa B.V. (to acquire a permission
 * please contact Zarafa at trademark@zarafa.com).
 *
 * The interactive user interface of the software displays an attribution
 * notice containing the term "Zarafa" and/or the logo of Zarafa.
 * Interactive user interfaces of unmodified and modified versions must
 * display Appropriate Legal Notices according to sec. 5 of the GNU
 * Affero General Public License, version 3, when you propagate
 * unmodified or modified versions of the Program. In accordance with
 * sec. 7 b) of the GNU Affero General Public License, version 3, these
 * Appropriate Legal Notices must retain the logo of Zarafa or display
 * the words "Initial Development by Zarafa" if the display of the logo
 * is not reasonably feasible for technical reasons. The use of the logo
 * of Zarafa in Legal Notices is allowed for unmodified and modified
 * versions of the software.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


    /**
     * MAPIException
     * if enabled using mapi_enable_exceptions then php-ext can throw exceptions when
     * any error occurs in mapi calls. this exception will only be thrown when severity bit is set in
     * error code that means it will be thrown only for mapi errors not for mapi warnings.
     */
    // FatalException will trigger a HTTP return code 500 to the mobile
    class MAPIException extends FatalException
    {
        /**
         * Function will return display message of exception if its set by the calle.
         * if it is not set then we are generating some default display messages based
         * on mapi error code.
         * @return string returns error-message that should be sent to client to display.
         */
        public function getDisplayMessage()
        {
            if(!empty($this->displayMessage))
                return $this->displayMessage;

            switch($this->getCode())
            {
                case MAPI_E_NO_ACCESS:
                    return _("You have insufficient privileges to open this object.");
                case MAPI_E_LOGON_FAILED:
                case MAPI_E_UNCONFIGURED:
                    return _("Logon Failed. Please check your username/password.");
                case MAPI_E_NETWORK_ERROR:
                    return _("Can not connect to Zarafa server.");
                case MAPI_E_UNKNOWN_ENTRYID:
                    return _("Can not open object with provided id.");
                case MAPI_E_NO_RECIPIENTS:
                    return _("There are no recipients in the message.");
                case MAPI_E_NOT_FOUND:
                    return _("Can not find object.");
                case MAPI_E_INTERFACE_NOT_SUPPORTED:
                case MAPI_E_INVALID_PARAMETER:
                case MAPI_E_INVALID_ENTRYID:
                case MAPI_E_INVALID_OBJECT:
                case MAPI_E_TOO_COMPLEX:
                case MAPI_E_CORRUPT_DATA:
                case MAPI_E_END_OF_SESSION:
                case MAPI_E_AMBIGUOUS_RECIP:
                case MAPI_E_COLLISION:
                case MAPI_E_UNCONFIGURED:
                default :
                    return sprintf(_("Unknown MAPI Error: %s"), get_mapi_error_name($this->getCode()));
            }
        }
    }

    // Tell the PHP extension which exception class to instantiate
    if (function_exists('mapi_enable_exceptions')) {
       //mapi_enable_exceptions("mapiexception");
    }
?>