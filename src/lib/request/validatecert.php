<?php
/***********************************************
* File      :   validatecert.php
* Project   :   Z-Push
* Descr     :   Provides the ValidateCert command
*
* Created   :   15.10.2012
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

class ValidateCert extends RequestProcessor {

    /**
     * Handles the ValidateCert command
     *
     * @param int       $commandCode
     *
     * @access public
     * @return boolean
     */
    public function Handle($commandCode) {
        // Parse input
        if(!self::$decoder->getElementStartTag(SYNC_VALIDATECERT_VALIDATECERT))
            return false;

        $validateCert = new SyncValidateCert();
        $validateCert->Decode(self::$decoder);
        $cert_der = base64_decode($validateCert->certificates[0]);
        $cert_pem = "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($cert_der), 64, "\n")."-----END CERTIFICATE-----\n";

        $checkpurpose = (defined('CAINFO') && CAINFO) ? openssl_x509_checkpurpose($cert_pem, X509_PURPOSE_SMIME_SIGN, array(CAINFO)) : openssl_x509_checkpurpose($cert_pem, X509_PURPOSE_SMIME_SIGN);
        if ($checkpurpose === true)
            $status = SYNC_VALIDATECERTSTATUS_SUCCESS;
        else
            $status = SYNC_VALIDATECERTSTATUS_CANTVALIDATESIG;

        if(!self::$decoder->getElementEndTag())
                return false; // SYNC_VALIDATECERT_VALIDATECERT

        self::$encoder->startWBXML();
        self::$encoder->startTag(SYNC_VALIDATECERT_VALIDATECERT);

            self::$encoder->startTag(SYNC_VALIDATECERT_STATUS);
            self::$encoder->content($status);
            self::$encoder->endTag(); // SYNC_VALIDATECERT_STATUS

            self::$encoder->startTag(SYNC_VALIDATECERT_CERTIFICATE);
                self::$encoder->startTag(SYNC_VALIDATECERT_STATUS);
                self::$encoder->content($status);
                self::$encoder->endTag(); // SYNC_VALIDATECERT_STATUS
            self::$encoder->endTag(); // SYNC_VALIDATECERT_CERTIFICATE

        self::$encoder->endTag(); // SYNC_VALIDATECERT_VALIDATECERT
        return true;
    }
}
?>