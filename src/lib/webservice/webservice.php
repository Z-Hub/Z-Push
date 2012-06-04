<?php
/***********************************************
* File      :   webservice.php
* Project   :   Z-Push
* Descr     :   Provides an interface for administration
*               tasks over a webservice
*
* Created   :   29.12.2011
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

class Webservice {
    private $server;

    /**
     * Handles a webservice command
     *
     * @param int       $commandCode
     *
     * @access public
     * @return boolean
     * @throws SoapFault
     */
    public function Handle($commandCode) {
        if (Request::GetDeviceType() !== "webservice" || Request::GetDeviceID() !== "webservice")
            throw new FatalException("Invalid device id and type for webservice execution");

        if (Request::GetGETUser() != Request::GetAuthUser())
            ZLog::Write(LOGLEVEL_INFO, sprintf("Webservice::HandleWebservice('%s'): user '%s' executing action for user '%s'", $commandCode, Request::GetAuthUser(), Request::GetGETUser()));

        // initialize non-wsdl soap server
        $this->server = new SoapServer(null, array('uri' => "http://z-push.sf.net/webservice"));

        // the webservice command is handled by its class
        if ($commandCode == ZPush::COMMAND_WEBSERVICE_DEVICE) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("Webservice::HandleWebservice('%s'): executing WebserviceDevice service", $commandCode));

            include_once('webservicedevice.php');
            $this->server->setClass("WebserviceDevice");
        }
        $this->server->handle();

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("Webservice::HandleWebservice('%s'): sucessfully sent %d bytes", $commandCode, ob_get_length()));
        return true;
    }
}
?>