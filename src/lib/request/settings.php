<?php
/***********************************************
* File      :   settings.php
* Project   :   Z-Push
* Descr     :   Provides the SETTINGS command
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

class Settings extends RequestProcessor {

    /**
     * Handles the Settings command
     *
     * @param int       $commandCode
     *
     * @access public
     * @return boolean
     */
    public function Handle($commandCode) {
        if (!self::$decoder->getElementStartTag(SYNC_SETTINGS_SETTINGS))
            return false;

        //save the request parameters
        $request = array();

        // Loop through properties. Possible are:
        // - Out of office
        // - DevicePassword
        // - DeviceInformation
        // - UserInformation
        // Each of them should only be once per request. Each property must be processed in order.
        while (1) {
            $propertyName = "";
            if (self::$decoder->getElementStartTag(SYNC_SETTINGS_OOF)) {
                $propertyName = SYNC_SETTINGS_OOF;
            }
            if (self::$decoder->getElementStartTag(SYNC_SETTINGS_DEVICEPW)) {
                $propertyName = SYNC_SETTINGS_DEVICEPW;
            }
            if (self::$decoder->getElementStartTag(SYNC_SETTINGS_DEVICEINFORMATION)) {
                $propertyName = SYNC_SETTINGS_DEVICEINFORMATION;
            }
            if (self::$decoder->getElementStartTag(SYNC_SETTINGS_USERINFORMATION)) {
                $propertyName = SYNC_SETTINGS_USERINFORMATION;
            }
            //TODO - check if it is necessary
            //no property name available - break
            if (!$propertyName)
                break;

            //the property name is followed by either get or set
            if (self::$decoder->getElementStartTag(SYNC_SETTINGS_GET)) {
                //get is only available for OOF and user information
                switch ($propertyName) {
                    case SYNC_SETTINGS_OOF:
                        $oofGet = new SyncOOF();
                        $oofGet->Decode(self::$decoder);
                        if(!self::$decoder->getElementEndTag())
                            return false; // SYNC_SETTINGS_GET
                        break;

                    case SYNC_SETTINGS_USERINFORMATION:
                        $userInformation = new SyncUserInformation();
                        break;

                    default:
                        //TODO: a special status code needed?
                        ZLog::Write(LOGLEVEL_WARN, sprintf ("This property ('%s') is not allowed to use get in request", $propertyName));
                }
            }
            elseif (self::$decoder->getElementStartTag(SYNC_SETTINGS_SET)) {
                //set is available for OOF, device password and device information
                switch ($propertyName) {
                    case SYNC_SETTINGS_OOF:
                        $oofSet = new SyncOOF();
                        $oofSet->Decode(self::$decoder);
                        //TODO check - do it after while(1) finished?
                        break;

                    case SYNC_SETTINGS_DEVICEPW:
                        //TODO device password
                        $devicepassword = new SyncDevicePassword();
                        $devicepassword->Decode(self::$decoder);
                        break;

                    case SYNC_SETTINGS_DEVICEINFORMATION:
                        $deviceinformation = new SyncDeviceInformation();
                        $deviceinformation->Decode(self::$decoder);
                        self::$deviceManager->SaveDeviceInformation($deviceinformation);
                        break;

                    default:
                        //TODO: a special status code needed?
                        ZLog::Write(LOGLEVEL_WARN, sprintf ("This property ('%s') is not allowed to use set in request", $propertyName));
                }

                if(!self::$decoder->getElementEndTag())
                    return false; // SYNC_SETTINGS_SET
            }
            else {
                ZLog::Write(LOGLEVEL_WARN, sprintf("Neither get nor set found for property '%s'", $propertyName));
                return false;
            }

            if(!self::$decoder->getElementEndTag())
                return false; // SYNC_SETTINGS_OOF or SYNC_SETTINGS_DEVICEPW or SYNC_SETTINGS_DEVICEINFORMATION or SYNC_SETTINGS_USERINFORMATION

            //break if it reached the endtag
            $e = self::$decoder->peek();
            if($e[EN_TYPE] == EN_TYPE_ENDTAG) {
                self::$decoder->getElementEndTag(); //SYNC_SETTINGS_SETTINGS
                break;
            }
        }

        $status = SYNC_SETTINGSSTATUS_SUCCESS;

        //TODO put it in try catch block
        //TODO implement Settings in the backend
        //TODO save device information in device manager
        //TODO status handling
//        $data = self::$backend->Settings($request);

        self::$encoder->startWBXML();
        self::$encoder->startTag(SYNC_SETTINGS_SETTINGS);

            self::$encoder->startTag(SYNC_SETTINGS_STATUS);
            self::$encoder->content($status);
            self::$encoder->endTag(); //SYNC_SETTINGS_STATUS

            //get oof settings
            if (isset($oofGet)) {
                $oofGet = self::$backend->Settings($oofGet);
                self::$encoder->startTag(SYNC_SETTINGS_OOF);
                    self::$encoder->startTag(SYNC_SETTINGS_STATUS);
                    self::$encoder->content($oofGet->Status);
                    self::$encoder->endTag(); //SYNC_SETTINGS_STATUS

                    self::$encoder->startTag(SYNC_SETTINGS_GET);
                        $oofGet->Encode(self::$encoder);
                    self::$encoder->endTag(); //SYNC_SETTINGS_GET
                self::$encoder->endTag(); //SYNC_SETTINGS_OOF
            }

            //get user information
            //TODO none email address found
            if (isset($userInformation)) {
                self::$backend->Settings($userInformation);
                self::$encoder->startTag(SYNC_SETTINGS_USERINFORMATION);
                    self::$encoder->startTag(SYNC_SETTINGS_STATUS);
                    self::$encoder->content($userInformation->Status);
                    self::$encoder->endTag(); //SYNC_SETTINGS_STATUS

                    self::$encoder->startTag(SYNC_SETTINGS_GET);
                        $userInformation->Encode(self::$encoder);
                    self::$encoder->endTag(); //SYNC_SETTINGS_GET
                self::$encoder->endTag(); //SYNC_SETTINGS_USERINFORMATION
            }

            //set out of office
            if (isset($oofSet)) {
                $oofSet = self::$backend->Settings($oofSet);
                self::$encoder->startTag(SYNC_SETTINGS_OOF);
                    self::$encoder->startTag(SYNC_SETTINGS_STATUS);
                    self::$encoder->content($oofSet->Status);
                    self::$encoder->endTag(); //SYNC_SETTINGS_STATUS
                self::$encoder->endTag(); //SYNC_SETTINGS_OOF
            }

            //set device passwort
            if (isset($devicepassword)) {
                self::$encoder->startTag(SYNC_SETTINGS_DEVICEPW);
                    self::$encoder->startTag(SYNC_SETTINGS_SET);
                        self::$encoder->startTag(SYNC_SETTINGS_STATUS);
                        self::$encoder->content($devicepassword->Status);
                        self::$encoder->endTag(); //SYNC_SETTINGS_STATUS
                    self::$encoder->endTag(); //SYNC_SETTINGS_SET
                self::$encoder->endTag(); //SYNC_SETTINGS_DEVICEPW
            }

            //set device information
            if (isset($deviceinformation)) {
                self::$encoder->startTag(SYNC_SETTINGS_DEVICEINFORMATION);
                    self::$encoder->startTag(SYNC_SETTINGS_SET);
                        self::$encoder->startTag(SYNC_SETTINGS_STATUS);
                        self::$encoder->content($deviceinformation->Status);
                        self::$encoder->endTag(); //SYNC_SETTINGS_STATUS
                    self::$encoder->endTag(); //SYNC_SETTINGS_SET
                self::$encoder->endTag(); //SYNC_SETTINGS_DEVICEINFORMATION
            }


        self::$encoder->endTag(); //SYNC_SETTINGS_SETTINGS

        return true;
    }
}
?>