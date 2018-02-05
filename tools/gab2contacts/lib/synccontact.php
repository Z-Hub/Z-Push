<?php
/***********************************************
* File      :   synccontact.php
* Project   :   Z-Push
* Descr     :   A simplified version of Z-Pushs SyncContact object.
*
* Created   :   05.09.2011
*
* Copyright 2007 - 2016 Zarafa Deutschland GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation.
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

class SyncContact {
    // ContactObject variable           MAPI Property                               Default LDAP parameter
    public $accountname;                // PR_ACCOUNT                               username
    public $firstname;                  // PR_GIVEN_NAME                            givenName
    public $lastname;                   // PR_SURNAME                               sn
    public $officelocation;             // PR_OFFICE_LOCATION                       physicalDeliveryOfficeName
    public $companyname;                // PR_COMPANY_NAME
    public $jobtitle;                   // PR_TITLE                                 title
    public $email1address;              // PR_SMTP_ADDRESS                          Email
    public $businessphonenumber;        // PR_BUSINESS_TELEPHONE_NUMBER
    public $businessfaxnumber;          // PR_PRIMARY_FAX_NUMBER                    Fax

    public $businessstreet;             // PR_POSTAL_ADDRESS                        postalAddress
    public $businesspostalcode;         // PR_BUSINESS_ADDRESS_POSTAL_CODE          postalCode
    public $businessstate;              // PR_BUSINESS_ADDRESS_STATE_OR_PROVINCE    st
    public $businesscity;               // PR_BUSINESS_ADDRESS_CITY                 location

    public $mobilephonenumber;          // PR_MOBILE_TELEPHONE_NUMBER               mobile
    public $homephonenumber;            // PR_HOME_TELEPHONE_NUMBER                 Telephone
    public $pagernumber;                // PR_BEEPER_TELEPHONE_NUMBER               pager
    public $picture;                    // PR_EMS_AB_THUMBNAIL_PHOTO                jpegPhoto                           // needs to be set base64_encoded

    public $customerid;                 // PR_ORGANIZATIONAL_ID_NUMBER              employeeNumber

    /* Not mappable GAB variables
     *
     * - PR_BUSINESS_ADDRESS_POST_OFFICE_BOX      postBoxOffice
     * - PR_INITIALS                              initials
     * - PR_LANGUAGE                              preferredLanguage
     */

    // hash of the object
    public $hash;

    // untouched SyncObject variables
    public $anniversary;
    public $assistantname;
    public $assistnamephonenumber;
    public $birthday;
    public $body;
    public $bodysize;
    public $bodytruncated;
    public $business2phonenumber;
    public $businesscountry;
    public $carphonenumber;
    public $children;
    public $email2address;
    public $email3address;
    public $fileas;
    public $home2phonenumber;
    public $homecity;
    public $homecountry;
    public $homepostalcode;
    public $homestate;
    public $homestreet;
    public $homefaxnumber;
    public $title;
    public $middlename;
    public $othercity;
    public $othercountry;
    public $otherpostalcode;
    public $otherstate;
    public $otherstreet;
    public $radiophonenumber;
    public $spouse;
    public $suffix;
    public $webpage;
    public $yomicompanyname;
    public $yomifirstname;
    public $yomilastname;
    public $rtf;
    public $categories;
    public $governmentid;
    public $imaddress;
    public $imaddress2;
    public $imaddress3;
    public $managername;
    public $companymainphone;
    public $nickname;
    public $mms;
    public $asbody;

    /**
     * Returns a hash of the data mapped from the GAB.
     *
     * @access public
     * @return string
     */
    public function GetHash() {
        if (!isset($this->hash) || $this->hash == "") {
            $this->hash = md5(serialize($this));
        }
        return $this->hash;
    }

    /**
     * Returns the properties which have to be unset on the server.
     *
     * @access public
     * @return array
     */
    public function getUnsetVars() {
        return array();
    }
}
