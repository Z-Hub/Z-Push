<?php
/***********************************************
* File      :   synccontact.php
* Project   :   Z-Push
* Descr     :   WBXML contact entities that can be parsed
*               directly (as a stream) from WBXML.
*               It is automatically decoded
*               according to $mapping,
*               and the Sync WBXML mappings.
*
* Created   :   05.09.2011
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

class SyncContact extends SyncObject {
    public $anniversary;
    public $assistantname;
    public $assistnamephonenumber;
    public $birthday;
    public $body;
    public $bodysize;
    public $bodytruncated;
    public $business2phonenumber;
    public $businesscity;
    public $businesscountry;
    public $businesspostalcode;
    public $businessstate;
    public $businessstreet;
    public $businessfaxnumber;
    public $businessphonenumber;
    public $carphonenumber;
    public $children;
    public $companyname;
    public $department;
    public $email1address;
    public $email2address;
    public $email3address;
    public $fileas;
    public $firstname;
    public $home2phonenumber;
    public $homecity;
    public $homecountry;
    public $homepostalcode;
    public $homestate;
    public $homestreet;
    public $homefaxnumber;
    public $homephonenumber;
    public $jobtitle;
    public $lastname;
    public $middlename;
    public $mobilephonenumber;
    public $officelocation;
    public $othercity;
    public $othercountry;
    public $otherpostalcode;
    public $otherstate;
    public $otherstreet;
    public $pagernumber;
    public $radiophonenumber;
    public $spouse;
    public $suffix;
    public $title;
    public $webpage;
    public $yomicompanyname;
    public $yomifirstname;
    public $yomilastname;
    public $rtf;
    public $picture;
    public $categories;

    // AS 2.5 props
    public $customerid;
    public $governmentid;
    public $imaddress;
    public $imaddress2;
    public $imaddress3;
    public $managername;
    public $companymainphone;
    public $accountname;
    public $nickname;
    public $mms;

    function SyncContact() {
        $mapping = array (
                    SYNC_POOMCONTACTS_ANNIVERSARY                       => array (  self::STREAMER_VAR      => "anniversary",
                                                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE_DASHES  ),

                    SYNC_POOMCONTACTS_ASSISTANTNAME                     => array (  self::STREAMER_VAR      => "assistantname"),
                    SYNC_POOMCONTACTS_ASSISTNAMEPHONENUMBER             => array (  self::STREAMER_VAR      => "assistnamephonenumber"),
                    SYNC_POOMCONTACTS_BIRTHDAY                          => array (  self::STREAMER_VAR      => "birthday",
                                                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE_DASHES  ),

                    SYNC_POOMCONTACTS_BODY                              => array (  self::STREAMER_VAR      => "body"),
                    SYNC_POOMCONTACTS_BODYSIZE                          => array (  self::STREAMER_VAR      => "bodysize"),
                    SYNC_POOMCONTACTS_BODYTRUNCATED                     => array (  self::STREAMER_VAR      => "bodytruncated"),
                    SYNC_POOMCONTACTS_BUSINESS2PHONENUMBER              => array (  self::STREAMER_VAR      => "business2phonenumber"),
                    SYNC_POOMCONTACTS_BUSINESSCITY                      => array (  self::STREAMER_VAR      => "businesscity"),
                    SYNC_POOMCONTACTS_BUSINESSCOUNTRY                   => array (  self::STREAMER_VAR      => "businesscountry"),
                    SYNC_POOMCONTACTS_BUSINESSPOSTALCODE                => array (  self::STREAMER_VAR      => "businesspostalcode"),
                    SYNC_POOMCONTACTS_BUSINESSSTATE                     => array (  self::STREAMER_VAR      => "businessstate"),
                    SYNC_POOMCONTACTS_BUSINESSSTREET                    => array (  self::STREAMER_VAR      => "businessstreet"),
                    SYNC_POOMCONTACTS_BUSINESSFAXNUMBER                 => array (  self::STREAMER_VAR      => "businessfaxnumber"),
                    SYNC_POOMCONTACTS_BUSINESSPHONENUMBER               => array (  self::STREAMER_VAR      => "businessphonenumber"),
                    SYNC_POOMCONTACTS_CARPHONENUMBER                    => array (  self::STREAMER_VAR      => "carphonenumber"),
                    SYNC_POOMCONTACTS_CHILDREN                          => array (  self::STREAMER_VAR      => "children",
                                                                                    self::STREAMER_ARRAY    => SYNC_POOMCONTACTS_CHILD ),

                    SYNC_POOMCONTACTS_COMPANYNAME                       => array (  self::STREAMER_VAR      => "companyname"),
                    SYNC_POOMCONTACTS_DEPARTMENT                        => array (  self::STREAMER_VAR      => "department"),
                    SYNC_POOMCONTACTS_EMAIL1ADDRESS                     => array (  self::STREAMER_VAR      => "email1address"),
                    SYNC_POOMCONTACTS_EMAIL2ADDRESS                     => array (  self::STREAMER_VAR      => "email2address"),
                    SYNC_POOMCONTACTS_EMAIL3ADDRESS                     => array (  self::STREAMER_VAR      => "email3address"),
                    SYNC_POOMCONTACTS_FILEAS                            => array (  self::STREAMER_VAR      => "fileas"),
                    SYNC_POOMCONTACTS_FIRSTNAME                         => array (  self::STREAMER_VAR      => "firstname"),
                    SYNC_POOMCONTACTS_HOME2PHONENUMBER                  => array (  self::STREAMER_VAR      => "home2phonenumber"),
                    SYNC_POOMCONTACTS_HOMECITY                          => array (  self::STREAMER_VAR      => "homecity"),
                    SYNC_POOMCONTACTS_HOMECOUNTRY                       => array (  self::STREAMER_VAR      => "homecountry"),
                    SYNC_POOMCONTACTS_HOMEPOSTALCODE                    => array (  self::STREAMER_VAR      => "homepostalcode"),
                    SYNC_POOMCONTACTS_HOMESTATE                         => array (  self::STREAMER_VAR      => "homestate"),
                    SYNC_POOMCONTACTS_HOMESTREET                        => array (  self::STREAMER_VAR      => "homestreet"),
                    SYNC_POOMCONTACTS_HOMEFAXNUMBER                     => array (  self::STREAMER_VAR      => "homefaxnumber"),
                    SYNC_POOMCONTACTS_HOMEPHONENUMBER                   => array (  self::STREAMER_VAR      => "homephonenumber"),
                    SYNC_POOMCONTACTS_JOBTITLE                          => array (  self::STREAMER_VAR      => "jobtitle"),
                    SYNC_POOMCONTACTS_LASTNAME                          => array (  self::STREAMER_VAR      => "lastname"),
                    SYNC_POOMCONTACTS_MIDDLENAME                        => array (  self::STREAMER_VAR      => "middlename"),
                    SYNC_POOMCONTACTS_MOBILEPHONENUMBER                 => array (  self::STREAMER_VAR      => "mobilephonenumber"),
                    SYNC_POOMCONTACTS_OFFICELOCATION                    => array (  self::STREAMER_VAR      => "officelocation"),
                    SYNC_POOMCONTACTS_OTHERCITY                         => array (  self::STREAMER_VAR      => "othercity"),
                    SYNC_POOMCONTACTS_OTHERCOUNTRY                      => array (  self::STREAMER_VAR      => "othercountry"),
                    SYNC_POOMCONTACTS_OTHERPOSTALCODE                   => array (  self::STREAMER_VAR      => "otherpostalcode"),
                    SYNC_POOMCONTACTS_OTHERSTATE                        => array (  self::STREAMER_VAR      => "otherstate"),
                    SYNC_POOMCONTACTS_OTHERSTREET                       => array (  self::STREAMER_VAR      => "otherstreet"),
                    SYNC_POOMCONTACTS_PAGERNUMBER                       => array (  self::STREAMER_VAR      => "pagernumber"),
                    SYNC_POOMCONTACTS_RADIOPHONENUMBER                  => array (  self::STREAMER_VAR      => "radiophonenumber"),
                    SYNC_POOMCONTACTS_SPOUSE                            => array (  self::STREAMER_VAR      => "spouse"),
                    SYNC_POOMCONTACTS_SUFFIX                            => array (  self::STREAMER_VAR      => "suffix"),
                    SYNC_POOMCONTACTS_TITLE                             => array (  self::STREAMER_VAR      => "title"),
                    SYNC_POOMCONTACTS_WEBPAGE                           => array (  self::STREAMER_VAR      => "webpage"),
                    SYNC_POOMCONTACTS_YOMICOMPANYNAME                   => array (  self::STREAMER_VAR      => "yomicompanyname"),
                    SYNC_POOMCONTACTS_YOMIFIRSTNAME                     => array (  self::STREAMER_VAR      => "yomifirstname"),
                    SYNC_POOMCONTACTS_YOMILASTNAME                      => array (  self::STREAMER_VAR      => "yomilastname"),
                    SYNC_POOMCONTACTS_RTF                               => array (  self::STREAMER_VAR      => "rtf"),
                    SYNC_POOMCONTACTS_PICTURE                           => array (  self::STREAMER_VAR      => "picture",
                                                                                    self::STREAMER_CHECKS   => array(   self::STREAMER_CHECK_LENGTHMAX      => 49152 )),

                    SYNC_POOMCONTACTS_CATEGORIES                        => array (  self::STREAMER_VAR      => "categories",
                                                                                    self::STREAMER_ARRAY    => SYNC_POOMCONTACTS_CATEGORY ),
                );

        if (Request::GetProtocolVersion() >= 2.5) {
            $mapping[SYNC_POOMCONTACTS2_CUSTOMERID]                     = array (   self::STREAMER_VAR      => "customerid");
            $mapping[SYNC_POOMCONTACTS2_GOVERNMENTID]                   = array (   self::STREAMER_VAR      => "governmentid");
            $mapping[SYNC_POOMCONTACTS2_IMADDRESS]                      = array (   self::STREAMER_VAR      => "imaddress");
            $mapping[SYNC_POOMCONTACTS2_IMADDRESS2]                     = array (   self::STREAMER_VAR      => "imaddress2");
            $mapping[SYNC_POOMCONTACTS2_IMADDRESS3]                     = array (   self::STREAMER_VAR      => "imaddress3");
            $mapping[SYNC_POOMCONTACTS2_MANAGERNAME]                    = array (   self::STREAMER_VAR      => "managername");
            $mapping[SYNC_POOMCONTACTS2_COMPANYMAINPHONE]               = array (   self::STREAMER_VAR      => "companymainphone");
            $mapping[SYNC_POOMCONTACTS2_ACCOUNTNAME]                    = array (   self::STREAMER_VAR      => "accountname");
            $mapping[SYNC_POOMCONTACTS2_NICKNAME]                       = array (   self::STREAMER_VAR      => "nickname");
            $mapping[SYNC_POOMCONTACTS2_MMS]                            = array (   self::STREAMER_VAR      => "mms");
        }

        if (Request::GetProtocolVersion() >= 12.0) {
            $mapping[SYNC_AIRSYNCBASE_BODY]                             = array (   self::STREAMER_VAR      => "asbody",
                                                                                    self::STREAMER_TYPE     => "SyncBaseBody");

            //unset these properties because airsyncbase body and attachments will be used instead
            unset($mapping[SYNC_POOMCONTACTS_BODY], $mapping[SYNC_POOMCONTACTS_BODYTRUNCATED]);
        }

        parent::SyncObject($mapping);
    }
}

?>