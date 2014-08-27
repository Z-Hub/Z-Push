<?php
/***********************************************
* File      :   autodiscover.php
* Project   :   Z-Push
* Descr     :   The autodiscover service for Z-Push.
*
* Created   :   14.05.2014
*
* Copyright 2007 - 2014 Zarafa Deutschland GmbH
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

include_once('../lib/core/zpushdefs.php');
include_once('../lib/exceptions/exceptions.php');
include_once('../lib/utils/utils.php');
include_once('../lib/core/zpush.php');
include_once('../lib/core/zlog.php');
include_once('../lib/interface/ibackend.php');
include_once('../lib/interface/ichanges.php');
include_once('../lib/interface/iexportchanges.php');
include_once('../lib/interface/iimportchanges.php');
include_once('../lib/interface/isearchprovider.php');
include_once('../lib/interface/istatemachine.php');
include_once('../version.php');
include_once('config.php');

class ZPushAutodiscover {
    const ACCEPTABLERESPONSESCHEMA = 'http://schemas.microsoft.com/exchange/autodiscover/mobilesync/responseschema/2006';
    const MAXINPUTSIZE = 8192; // Bytes, the autodiscover request shouldn't exceed that value

    private static $instance;

    /**
     * Static method to start the autodiscover process.
     *
     * @access public
     *
     * @return void
     */
    public static function DoZPushAutodiscover() {
        ZLog::Write(LOGLEVEL_DEBUG, '-------- Start ZPushAutodiscover');
        // TODO use filterevilinput?
        if (stripos($_SERVER["REQUEST_METHOD"], "GET") !== false) {
            ZLog::Write(LOGLEVEL_WARN, "GET request for autodiscover. Exiting.");
            if (!headers_sent()) {
                ZPush::PrintZPushLegal('GET not supported');
            }
            ZLog::Write(LOGLEVEL_DEBUG, '-------- End ZPushAutodiscover');
            exit(1);
        }
        if (!isset(self::$instance)) {
            self::$instance = new ZPushAutodiscover();
        }
        self::$instance->DoAutodiscover();
        ZLog::Write(LOGLEVEL_DEBUG, '-------- End ZPushAutodiscover');
    }

    /**
     * Does the complete autodiscover.
     * @access public
     * @throws AuthenticationRequiredException if login to the backend failed.
     * @throws ZPushException if the incoming XML is invalid..
     *
     * @return void
     */
    public function DoAutodiscover() {
        if (!defined('REAL_BASE_PATH')) {
            define('REAL_BASE_PATH', str_replace('autodiscover/', '', BASE_PATH));
        }
        set_include_path(get_include_path() . PATH_SEPARATOR . REAL_BASE_PATH);
        $response = "";

        try {
            $incomingXml = $this->getIncomingXml();
            $backend = ZPush::GetBackend();
            $username = $this->login($backend, $incomingXml);
            $userFullname = $backend->GetUserFullname($username);
            ZLog::Write(LOGLEVEL_WBXML, sprintf("Resolved user's '%s' fullname to '%s'", $username, $userFullname));
            $response = $this->createResponse($incomingXml->Request->EMailAddress, $userFullname);
            setcookie("membername", $username);
        }

        catch (AuthenticationRequiredException $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("Unable to complete autodiscover because login failed for user with email '%s'", $incomingXml->Request->EMailAddress));
            header('HTTP/1.1 401 Unauthorized');
            header('WWW-Authenticate: Basic realm="ZPush"');
            http_response_code(401);
        }
        catch (ZPushException $ex) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("Unable to complete autodiscover because of ZPushException. Error: %s", $ex->getMessage()));
            if(!headers_sent()) {
                header('HTTP/1.1 '. $ex->getHTTPCodeString());
                foreach ($ex->getHTTPHeaders() as $h) {
                    header($h);
                }
            }
        }
        $this->sendResponse($response);
    }

    /**
     * Processes the incoming XML request and parses it to a SimpleXMLElement.
     *
     * @access private
     * @throws ZPushException if the XML is invalid.
     * @throws AuthenticationRequiredException if no login data was sent.
     *
     * @return SimpleXMLElement
     */
    private function getIncomingXml() {
        if ($_SERVER['CONTENT_LENGTH'] > ZPushAutodiscover::MAXINPUTSIZE) {
            throw new ZPushException('The request input size exceeds 8kb.');
        }

        if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
            throw new AuthenticationRequiredException();
        }

        $input = @file_get_contents('php://input');
        $xml = simplexml_load_string($input);

        if (!isset($xml->Request->EMailAddress)) {
            throw new FatalException('Invalid input XML: no email address.');
        }

        if (Utils::GetLocalPartFromEmail($xml->Request->EMailAddress) != Utils::GetLocalPartFromEmail($_SERVER['PHP_AUTH_USER'])) {
            ZLog::Write(LOGLEVEL_WARN, sprintf("The local part of the server auth user is different from the local part in the XML request ('%s' != '%s')",
                Utils::GetLocalPartFromEmail($xml->Request->EMailAddress), Utils::GetLocalPartFromEmail($_SERVER['PHP_AUTH_USER'])));
        }

        if (!isset($xml->Request->AcceptableResponseSchema)) {
            throw new FatalException('Invalid input XML: no AcceptableResponseSchema.');
        }

        if ($xml->Request->AcceptableResponseSchema != ZPushAutodiscover::ACCEPTABLERESPONSESCHEMA) {
            throw new FatalException('Invalid input XML: not a mobilesync responseschema.');
        }
        if (LOGLEVEL >= LOGLEVEL_WBXML) {
            ZLog::Write(LOGLEVEL_WBXML, sprintf("ZPushAutodiscover->getIncomingXml() incoming XML data:%s%s", PHP_EOL, $xml->asXML()));
        }
        return $xml;
    }

    /**
     * Logins using the backend's Logon function.
     *
     * @param IBackend $backend
     * @param String $incomingXml
     * @access private
     * @throws AuthenticationRequiredException if no login data was sent.
     *
     * @return string $username
     */
    private function login($backend, $incomingXml) {
        // Determine the login name depending on the configuration: complete email address or
        // the local part only.
        if (USE_FULLEMAIL_FOR_LOGIN) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("Using the complete email address for login."));
            $username = $incomingXml->Request->EMailAddress;
        }
        else{
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("Using the username only for login."));
            $username = Utils::GetLocalPartFromEmail($incomingXml->Request->EMailAddress);
        }

        if($backend->Logon($username, "", $_SERVER['PHP_AUTH_PW']) == false) {
            throw new AuthenticationRequiredException("Access denied. Username or password incorrect.");
        }
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("ZPushAutodiscover->login() Using '%s' as the username.", $username));
        return $username;
    }

    /**
     * Creates the XML response.
     *
     * @param string $email
     * @param string $userFullname
     * @access private
     *
     * @return string
     */
    private function createResponse($email, $userFullname) {
        $xml = file_get_contents('response.xml');
        $response = new SimpleXMLElement($xml);
        $response->Response->User->DisplayName = $userFullname;
        $response->Response->User->EMailAddress = $email;
        $response->Response->Action->Settings->Server->Url = SERVERURL;
        $response->Response->Action->Settings->Server->Name = SERVERURL;
        $response = $response->asXML();
        ZLog::Write(LOGLEVEL_WBXML, sprintf("ZPushAutodiscover->createResponse() XML response:%s%s", PHP_EOL, $response));
        return $response;
    }

    /**
     * Sends the response to the device.
     * @param string $response
     * @access private
     *
     * @return void
     */
    private function sendResponse($response) {
        ZLog::Write(LOGLEVEL_DEBUG, "ZPushAutodiscover->sendResponse() sending response...");
        header("Content-type: text/html");
        $output = fopen("php://output", "w+");
        fwrite($output, $response);
        fclose($output);
        ZLog::Write(LOGLEVEL_DEBUG, "ZPushAutodiscover->sendResponse() response sent.");
    }
}

ZPushAutodiscover::DoZPushAutodiscover();
?>