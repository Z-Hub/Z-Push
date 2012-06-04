<?php
/***********************************************
* File      :   provisioning.php
* Project   :   Z-Push
* Descr     :   Provides the PROVISIONING command
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

class Provisioning extends RequestProcessor {

    /**
     * Handles the Provisioning command
     *
     * @param int       $commandCode
     *
     * @access public
     * @return boolean
     */
    public function Handle($commandCode) {
        $status = SYNC_PROVISION_STATUS_SUCCESS;
        $policystatus = SYNC_PROVISION_POLICYSTATUS_SUCCESS;

        $rwstatus = self::$deviceManager->GetProvisioningWipeStatus();
        $rwstatusWiped = false;

        // if this is a regular provisioning require that an authenticated remote user
        if ($rwstatus < SYNC_PROVISION_RWSTATUS_PENDING) {
            ZLog::Write(LOGLEVEL_DEBUG, "RequestProcessor::HandleProvision(): Forcing delayed Authentication");
            self::Authenticate();
        }

        $phase2 = true;

        if(!self::$decoder->getElementStartTag(SYNC_PROVISION_PROVISION))
            return false;

        //handle android remote wipe.
        if (self::$decoder->getElementStartTag(SYNC_PROVISION_REMOTEWIPE)) {
            if(!self::$decoder->getElementStartTag(SYNC_PROVISION_STATUS))
                return false;

            $instatus = self::$decoder->getElementContent();

            if(!self::$decoder->getElementEndTag())
                return false;

            if(!self::$decoder->getElementEndTag())
                return false;

            $phase2 = false;
            $rwstatusWiped = true;
        }
        else {

            if(!self::$decoder->getElementStartTag(SYNC_PROVISION_POLICIES))
                return false;

            if(!self::$decoder->getElementStartTag(SYNC_PROVISION_POLICY))
                return false;

            if(!self::$decoder->getElementStartTag(SYNC_PROVISION_POLICYTYPE))
                return false;

            $policytype = self::$decoder->getElementContent();
            if ($policytype != 'MS-WAP-Provisioning-XML' && $policytype != 'MS-EAS-Provisioning-WBXML') {
                $status = SYNC_PROVISION_STATUS_SERVERERROR;
            }
            if(!self::$decoder->getElementEndTag()) //policytype
                return false;

            if (self::$decoder->getElementStartTag(SYNC_PROVISION_POLICYKEY)) {
                $devpolicykey = self::$decoder->getElementContent();

                if(!self::$decoder->getElementEndTag())
                    return false;

                if(!self::$decoder->getElementStartTag(SYNC_PROVISION_STATUS))
                    return false;

                $instatus = self::$decoder->getElementContent();

                if(!self::$decoder->getElementEndTag())
                    return false;

                $phase2 = false;
            }

            if(!self::$decoder->getElementEndTag()) //policy
                return false;

            if(!self::$decoder->getElementEndTag()) //policies
                return false;

            if (self::$decoder->getElementStartTag(SYNC_PROVISION_REMOTEWIPE)) {
                if(!self::$decoder->getElementStartTag(SYNC_PROVISION_STATUS))
                    return false;

                $status = self::$decoder->getElementContent();

                if(!self::$decoder->getElementEndTag())
                    return false;

                if(!self::$decoder->getElementEndTag())
                    return false;

                $rwstatusWiped = true;
            }
        }
        if(!self::$decoder->getElementEndTag()) //provision
            return false;

        if (PROVISIONING !== true) {
            ZLog::Write(LOGLEVEL_INFO, "No policies deployed to device");
            $policystatus = SYNC_PROVISION_POLICYSTATUS_NOPOLICY;
        }

        self::$encoder->StartWBXML();

        //set the new final policy key in the device manager
        // START ADDED dw2412 Android provisioning fix
        if (!$phase2) {
            $policykey = self::$deviceManager->GenerateProvisioningPolicyKey();
            self::$deviceManager->SetProvisioningPolicyKey($policykey);
            self::$topCollector->AnnounceInformation("Policies deployed", true);
        }
        else {
            // just create a temporary key (i.e. iPhone OS4 Beta does not like policykey 0 in response)
            $policykey = self::$deviceManager->GenerateProvisioningPolicyKey();
        }
        // END ADDED dw2412 Android provisioning fix

        self::$encoder->startTag(SYNC_PROVISION_PROVISION);
        {
            self::$encoder->startTag(SYNC_PROVISION_STATUS);
                self::$encoder->content($status);
            self::$encoder->endTag();

            self::$encoder->startTag(SYNC_PROVISION_POLICIES);
                self::$encoder->startTag(SYNC_PROVISION_POLICY);

                if(isset($policytype)) {
                    self::$encoder->startTag(SYNC_PROVISION_POLICYTYPE);
                        self::$encoder->content($policytype);
                    self::$encoder->endTag();
                }

                self::$encoder->startTag(SYNC_PROVISION_STATUS);
                    self::$encoder->content($policystatus);
                self::$encoder->endTag();

                self::$encoder->startTag(SYNC_PROVISION_POLICYKEY);
                       self::$encoder->content($policykey);
                self::$encoder->endTag();

                if ($phase2 && $policystatus === SYNC_PROVISION_POLICYSTATUS_SUCCESS) {
                    self::$encoder->startTag(SYNC_PROVISION_DATA);
                    if ($policytype == 'MS-WAP-Provisioning-XML') {
                        self::$encoder->content('<wap-provisioningdoc><characteristic type="SecurityPolicy"><parm name="4131" value="1"/><parm name="4133" value="1"/></characteristic></wap-provisioningdoc>');
                    }
                    elseif ($policytype == 'MS-EAS-Provisioning-WBXML') {
                        self::$encoder->startTag(SYNC_PROVISION_EASPROVISIONDOC);

                            $prov = self::$deviceManager->GetProvisioningObject();
                            if (!$prov->Check())
                                throw new FatalException("Invalid policies!");

                            $prov->Encode(self::$encoder);
                        self::$encoder->endTag();
                    }
                    else {
                        ZLog::Write(LOGLEVEL_WARN, "Wrong policy type");
                        self::$topCollector->AnnounceInformation("Policytype not supported", true);
                        return false;
                    }
                    self::$topCollector->AnnounceInformation("Updated provisiong", true);

                    self::$encoder->endTag();//data
                }
                self::$encoder->endTag();//policy
            self::$encoder->endTag(); //policies
        }

        //wipe data if a higher RWSTATUS is requested
        if ($rwstatus > SYNC_PROVISION_RWSTATUS_OK && $policystatus === SYNC_PROVISION_POLICYSTATUS_SUCCESS) {
            self::$encoder->startTag(SYNC_PROVISION_REMOTEWIPE, false, true);
            self::$deviceManager->SetProvisioningWipeStatus(($rwstatusWiped)?SYNC_PROVISION_RWSTATUS_WIPED:SYNC_PROVISION_RWSTATUS_REQUESTED);
            self::$topCollector->AnnounceInformation(sprintf("Remote wipe %s", ($rwstatusWiped)?"executed":"requested"), true);
        }

        self::$encoder->endTag();//provision

        return true;
    }
}
?>