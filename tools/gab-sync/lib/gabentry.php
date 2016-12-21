<?php
/***********************************************
* File      :   gabentry.php
* Project   :   Z-Push - tools - GAB sync
* Descr     :   Data class for a GAB entry.
*
* Created   :   28.01.2016
*
* Copyright 2016 Zarafa Deutschland GmbH
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
* ************************************************/


class GABEntry {
    const CONTACT = "contact";
    const GROUP = "group";
    const ROOM = "room";
    const EQUIPMENT = "equipment";

    public $type;                           // contact, group, room or equipment
    public $memberOf;                       // groups the item is a member of
    public $members;                        // if this is a group, a list of its members

    // GABEntry variable                    MAPI Property                               Default LDAP parameter
    public $account;                        // PR_ACCOUNT                               username
    public $displayName;                    // PR_DISPLAY_NAME
    public $givenName;                      // PR_GIVEN_NAME                            givenName
    public $surname;                        // PR_SURNAME                               sn
    public $smtpAddress;                    // PR_SMTP_ADDRESS                          Email
    public $title;                          // PR_TITLE                                 title
    public $companyName;                    // PR_COMPANY_NAME
    public $officeLocation;                 // PR_OFFICE_LOCATION                       physicalDeliveryOfficeName
    public $businessTelephoneNumber;        // PR_BUSINESS_TELEPHONE_NUMBER
    public $mobileTelephoneNumber;          // PR_MOBILE_TELEPHONE_NUMBER               mobile
    public $homeTelephoneNumber;            // PR_HOME_TELEPHONE_NUMBER                 Telephone
    public $beeperTelephoneNumber;          // PR_BEEPER_TELEPHONE_NUMBER               pager
    public $primaryFaxNumber;               // PR_PRIMARY_FAX_NUMBER                    Fax
    public $organizationalIdNumber;         // PR_ORGANIZATIONAL_ID_NUMBER              employeeNumber
    public $postalAddress;                  // PR_POSTAL_ADDRESS                        postalAddress
    public $businessAddressCity;            // PR_BUSINESS_ADDRESS_CITY                 location
    public $businessAddressPostalCode;      // PR_BUSINESS_ADDRESS_POSTAL_CODE          postalCode
    public $businessAddressPostOfficeBox;   // PR_BUSINESS_ADDRESS_POST_OFFICE_BOX      postBoxOffice
    public $businessAddressStateOrProvince; // PR_BUSINESS_ADDRESS_STATE_OR_PROVINCE    st
    public $initials;                       // PR_INITIALS                              initials
    public $language;                       // PR_LANGUAGE                              preferredLanguage
    public $thumbnailPhoto;                 // PR_EMS_AB_THUMBNAIL_PHOTO                jpegPhoto

    // TODO
    //PR_CHILDRENS_NAMES		                PT_MV_UNICODE	o
}