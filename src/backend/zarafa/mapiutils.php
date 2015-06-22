<?php
/***********************************************
* File      :   mapiutils.php
* Project   :   Z-Push
* Descr     :
*
* Created   :   14.02.2011
*
* Copyright 2007 - 2013 Zarafa Deutschland GmbH
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

/**
 *
 * MAPI to AS mapping class
 *
 *
 */
class MAPIUtils {

    /**
     * Create a MAPI restriction to use within an email folder which will
     * return all messages since since $timestamp
     *
     * @param long       $timestamp     Timestamp since when to include messages
     *
     * @access public
     * @return array
     */
    public static function GetEmailRestriction($timestamp) {
        // ATTENTION: ON CHANGING THIS RESTRICTION, MAPIUtils::IsInEmailSyncInterval() also needs to be changed
        $restriction = array ( RES_PROPERTY,
                          array (   RELOP => RELOP_GE,
                                    ULPROPTAG => PR_MESSAGE_DELIVERY_TIME,
                                    VALUE => $timestamp
                          )
                      );

        return $restriction;
    }


    /**
     * Create a MAPI restriction to use in the calendar which will
     * return all future calendar items, plus those since $timestamp
     *
     * @param MAPIStore  $store         the MAPI store
     * @param long       $timestamp     Timestamp since when to include messages
     *
     * @access public
     * @return array
     */
    //TODO getting named properties
    public static function GetCalendarRestriction($store, $timestamp) {
        // This is our viewing window
        $start = $timestamp;
        $end = 0x7fffffff; // infinite end

        $props = MAPIMapping::GetAppointmentProperties();
        $props = getPropIdsFromStrings($store, $props);

        // ATTENTION: ON CHANGING THIS RESTRICTION, MAPIUtils::IsInCalendarSyncInterval() also needs to be changed
        $restriction = Array(RES_OR,
             Array(
                   // OR
                   // item.end > window.start && item.start < window.end
                   Array(RES_AND,
                         Array(
                               Array(RES_PROPERTY,
                                     Array(RELOP => RELOP_LE,
                                           ULPROPTAG => $props["starttime"],
                                           VALUE => $end
                                           )
                                     ),
                               Array(RES_PROPERTY,
                                     Array(RELOP => RELOP_GE,
                                           ULPROPTAG => $props["endtime"],
                                           VALUE => $start
                                           )
                                     )
                               )
                         ),
                   // OR
                   Array(RES_OR,
                         Array(
                               // OR
                               // (EXIST(recurrence_enddate_property) && item[isRecurring] == true && recurrence_enddate_property >= start)
                               Array(RES_AND,
                                     Array(
                                           Array(RES_EXIST,
                                                 Array(ULPROPTAG => $props["recurrenceend"],
                                                       )
                                                 ),
                                           Array(RES_PROPERTY,
                                                 Array(RELOP => RELOP_EQ,
                                                       ULPROPTAG => $props["isrecurring"],
                                                       VALUE => true
                                                       )
                                                 ),
                                           Array(RES_PROPERTY,
                                                 Array(RELOP => RELOP_GE,
                                                       ULPROPTAG => $props["recurrenceend"],
                                                       VALUE => $start
                                                       )
                                                 )
                                           )
                                     ),
                               // OR
                               // (!EXIST(recurrence_enddate_property) && item[isRecurring] == true && item[start] <= end)
                               Array(RES_AND,
                                     Array(
                                           Array(RES_NOT,
                                                 Array(
                                                       Array(RES_EXIST,
                                                             Array(ULPROPTAG => $props["recurrenceend"]
                                                                   )
                                                             )
                                                       )
                                                 ),
                                           Array(RES_PROPERTY,
                                                 Array(RELOP => RELOP_LE,
                                                       ULPROPTAG => $props["starttime"],
                                                       VALUE => $end
                                                       )
                                                 ),
                                           Array(RES_PROPERTY,
                                                 Array(RELOP => RELOP_EQ,
                                                       ULPROPTAG => $props["isrecurring"],
                                                       VALUE => true
                                                       )
                                                 )
                                           )
                                     )
                               )
                         ) // EXISTS OR
                   )
             );        // global OR

        return $restriction;
    }


    /**
     * Create a MAPI restriction in order to check if a contact has a picture
     *
     * @access public
     * @return array
     */
    public static function GetContactPicRestriction() {
        return array ( RES_PROPERTY,
                        array (
                            RELOP => RELOP_EQ,
                            ULPROPTAG => mapi_prop_tag(PT_BOOLEAN, 0x7FFF),
                            VALUE => true
                        )
        );
    }


    /**
     * Create a MAPI restriction for search
     *
     * @access public
     *
     * @param string $query
     * @return array
     */
    public static function GetSearchRestriction($query) {
        return array(RES_AND,
                    array(
                        array(RES_OR,
                            array(
                                array(RES_CONTENT, array(FUZZYLEVEL => FL_SUBSTRING | FL_IGNORECASE, ULPROPTAG => PR_DISPLAY_NAME, VALUE => $query)),
                                array(RES_CONTENT, array(FUZZYLEVEL => FL_SUBSTRING | FL_IGNORECASE, ULPROPTAG => PR_ACCOUNT, VALUE => $query)),
                                array(RES_CONTENT, array(FUZZYLEVEL => FL_SUBSTRING | FL_IGNORECASE, ULPROPTAG => PR_SMTP_ADDRESS, VALUE => $query)),
                            ), // RES_OR
                        ),
                        array(RES_OR,
                            array (
                                array(
                                        RES_PROPERTY,
                                        array(RELOP => RELOP_EQ, ULPROPTAG => PR_OBJECT_TYPE, VALUE => MAPI_MAILUSER)
                                ),
                                array(
                                        RES_PROPERTY,
                                        array(RELOP => RELOP_EQ, ULPROPTAG => PR_OBJECT_TYPE, VALUE => MAPI_DISTLIST)
                                )
                            )
                        ) // RES_OR
                    ) // RES_AND
        );
    }

    /**
     * Create a MAPI restriction for a certain email address
     *
     * @access public
     *
     * @param MAPIStore  $store         the MAPI store
     * @param string     $query         email address
     *
     * @return array
     */
    public static function GetEmailAddressRestriction($store, $email) {
        $props = MAPIMapping::GetContactProperties();
        $props = getPropIdsFromStrings($store, $props);

        return array(RES_OR,
                    array(
                        array(  RES_PROPERTY,
                                array(  RELOP => RELOP_EQ,
                                        ULPROPTAG => $props['emailaddress1'],
                                        VALUE => array($props['emailaddress1'] => $email),
                                ),
                        ),
                        array(  RES_PROPERTY,
                                array(  RELOP => RELOP_EQ,
                                        ULPROPTAG => $props['emailaddress2'],
                                        VALUE => array($props['emailaddress2'] => $email),
                                ),
                        ),
                        array(  RES_PROPERTY,
                                array(  RELOP => RELOP_EQ,
                                        ULPROPTAG => $props['emailaddress3'],
                                        VALUE => array($props['emailaddress3'] => $email),
                                ),
                        ),
                ),
        );
    }

    /**
     * Create a MAPI restriction for a certain folder type
     *
     * @access public
     *
     * @param string     $foldertype    folder type for restriction
     * @return array
     */
    public static function GetFolderTypeRestriction($foldertype) {
        return array(   RES_PROPERTY,
                        array(  RELOP => RELOP_EQ,
                                ULPROPTAG => PR_CONTAINER_CLASS,
                                VALUE => array(PR_CONTAINER_CLASS => $foldertype)
                        ),
                );
    }

    /**
     * Returns subfolders of given type for a folder or false if there are none.
     *
     * @access public
     *
     * @param MAPIFolder $folder
     * @param string $type
     *
     * @return MAPITable|boolean
     */
    public static function GetSubfoldersForType($folder, $type) {
        $subfolders = mapi_folder_gethierarchytable($folder, CONVENIENT_DEPTH);
        mapi_table_restrict($subfolders, MAPIUtils::GetFolderTypeRestriction($type));
        if (mapi_table_getrowcount($subfolders) > 0) {
            return mapi_table_queryallrows($subfolders, array(PR_ENTRYID));
        }
        return false;
    }

    /**
     * Checks if mapimessage is inside the synchronization interval
     * also defined by MAPIUtils::GetEmailRestriction()
     *
     * @param MAPIStore       $store           mapi store
     * @param MAPIMessage     $mapimessage     the mapi message to be checked
     * @param long            $timestamp       the lower time limit
     *
     * @access public
     * @return boolean
     */
    public static function IsInEmailSyncInterval($store, $mapimessage, $timestamp) {
        $p = mapi_getprops($mapimessage, array(PR_MESSAGE_DELIVERY_TIME));

        if (isset($p[PR_MESSAGE_DELIVERY_TIME]) && $p[PR_MESSAGE_DELIVERY_TIME] >= $timestamp) {
            ZLog::Write(LOGLEVEL_DEBUG, "MAPIUtils->IsInEmailSyncInterval: Message is in the synchronization interval");
            return true;
        }

        ZLog::Write(LOGLEVEL_WARN, "MAPIUtils->IsInEmailSyncInterval: Message is OUTSIDE the synchronization interval");
        return false;
    }

    /**
     * Checks if mapimessage is inside the synchronization interval
     * also defined by MAPIUtils::GetCalendarRestriction()
     *
     * @param MAPIStore       $store           mapi store
     * @param MAPIMessage     $mapimessage     the mapi message to be checked
     * @param long            $timestamp       the lower time limit
     *
     * @access public
     * @return boolean
     */
    public static function IsInCalendarSyncInterval($store, $mapimessage, $timestamp) {
        // This is our viewing window
        $start = $timestamp;
        $end = 0x7fffffff; // infinite end

        $props = MAPIMapping::GetAppointmentProperties();
        $props = getPropIdsFromStrings($store, $props);

        $p = mapi_getprops($mapimessage, array($props["starttime"], $props["endtime"], $props["recurrenceend"], $props["isrecurring"], $props["recurrenceend"]));

        if (
                (
                    isset($p[$props["endtime"]]) && isset($p[$props["starttime"]]) &&

                    //item.end > window.start && item.start < window.end
                    $p[$props["endtime"]] > $start && $p[$props["starttime"]] < $end
                )
            ||
                (
                    isset($p[$props["isrecurring"]]) &&

                    //(EXIST(recurrence_enddate_property) && item[isRecurring] == true && recurrence_enddate_property >= start)
                    isset($p[$props["recurrenceend"]]) && $p[$props["isrecurring"]] == true && $p[$props["recurrenceend"]] >= $start
                )
            ||
                (
                    isset($p[$props["isrecurring"]]) && isset($p[$props["starttime"]]) &&

                    //(!EXIST(recurrence_enddate_property) && item[isRecurring] == true && item[start] <= end)
                    !isset($p[$props["recurrenceend"]]) && $p[$props["isrecurring"]] == true && $p[$props["starttime"]] <= $end
                )
           ) {
            ZLog::Write(LOGLEVEL_DEBUG, "MAPIUtils->IsInCalendarSyncInterval: Message is in the synchronization interval");
            return true;
        }


        ZLog::Write(LOGLEVEL_WARN, "MAPIUtils->IsInCalendarSyncInterval: Message is OUTSIDE the synchronization interval");
        return false;
    }


    /**
     * Reads data of large properties from a stream
     *
     * @param MAPIMessage $message
     * @param long $prop
     *
     * @access public
     * @return string
     */
    public static function readPropStream($message, $prop) {
        $stream = mapi_openproperty($message, $prop, IID_IStream, 0, 0);
        $ret = mapi_last_hresult();
        if ($ret == MAPI_E_NOT_FOUND) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("MAPIUtils->readPropStream: property 0x%s not found. It is either empty or not set. It will be ignored.", str_pad(dechex($prop), 8, 0, STR_PAD_LEFT)));
            return "";
        }
        elseif ($ret) {
            ZLog::Write(LOGLEVEL_ERROR, "MAPIUtils->readPropStream error opening stream: 0X%X", $ret);
            return "";
        }
        $data = "";
        $string = "";
        while(1) {
            $data = mapi_stream_read($stream, 1024);
            if(strlen($data) == 0)
                break;
            $string .= $data;
        }

        return $string;
    }


    /**
     * Checks if a store supports properties containing unicode characters
     *
     * @param MAPIStore $store
     *
     * @access public
     * @return
     */
    public static function IsUnicodeStore($store) {
        $supportmask = mapi_getprops($store, array(PR_STORE_SUPPORT_MASK));
        if (isset($supportmask[PR_STORE_SUPPORT_MASK]) && ($supportmask[PR_STORE_SUPPORT_MASK] & STORE_UNICODE_OK)) {
            ZLog::Write(LOGLEVEL_DEBUG, "Store supports properties containing Unicode characters.");
            define('STORE_SUPPORTS_UNICODE', true);
            //setlocale to UTF-8 in order to support properties containing Unicode characters
            setlocale(LC_CTYPE, "en_US.UTF-8");
            define('STORE_INTERNET_CPID', INTERNET_CPID_UTF8);
        }
    }

    /**
     * Returns the MAPI PR_CONTAINER_CLASS string for an ActiveSync Foldertype
     *
     * @param int       $foldertype
     *
     * @access public
     * @return string
     */
    public static function GetContainerClassFromFolderType($foldertype) {
        switch ($foldertype) {
            case SYNC_FOLDER_TYPE_TASK:
            case SYNC_FOLDER_TYPE_USER_TASK:
                return "IPF.Task";
                break;

            case SYNC_FOLDER_TYPE_APPOINTMENT:
            case SYNC_FOLDER_TYPE_USER_APPOINTMENT:
                return "IPF.Appointment";
                break;

            case SYNC_FOLDER_TYPE_CONTACT:
            case SYNC_FOLDER_TYPE_USER_CONTACT:
                return "IPF.Contact";
                break;

            case SYNC_FOLDER_TYPE_NOTE:
            case SYNC_FOLDER_TYPE_USER_NOTE:
                return "IPF.StickyNote";
                break;

            case SYNC_FOLDER_TYPE_JOURNAL:
            case SYNC_FOLDER_TYPE_USER_JOURNAL:
                return "IPF.Journal";
                break;

            case SYNC_FOLDER_TYPE_INBOX:
            case SYNC_FOLDER_TYPE_DRAFTS:
            case SYNC_FOLDER_TYPE_WASTEBASKET:
            case SYNC_FOLDER_TYPE_SENTMAIL:
            case SYNC_FOLDER_TYPE_OUTBOX:
            case SYNC_FOLDER_TYPE_USER_MAIL:
            case SYNC_FOLDER_TYPE_OTHER:
            case SYNC_FOLDER_TYPE_UNKNOWN:
            default:
                return "IPF.Note";
                break;
        }
    }

    public static function GetSignedAttachmentRestriction() {
        return array(  RES_PROPERTY,
            array(  RELOP => RELOP_EQ,
                ULPROPTAG => PR_ATTACH_MIME_TAG,
                VALUE => array(PR_ATTACH_MIME_TAG => 'multipart/signed')
            ),
        );
    }

    /**
     * Calculates the native body type of a message using available properties. Refer to oxbbody.
     *
     * @param array             $messageprops
     *
     * @access public
     * @return int
     */
    public static function GetNativeBodyType($messageprops) {
        //check if the properties are set and get the error code if needed
        if (!isset($messageprops[PR_BODY]))             $messageprops[PR_BODY]              = self::getError(PR_BODY, $messageprops);
        if (!isset($messageprops[PR_RTF_COMPRESSED]))   $messageprops[PR_RTF_COMPRESSED]    = self::getError(PR_RTF_COMPRESSED, $messageprops);
        if (!isset($messageprops[PR_HTML]))             $messageprops[PR_HTML]              = self::getError(PR_HTML, $messageprops);
        if (!isset($messageprops[PR_RTF_IN_SYNC]))      $messageprops[PR_RTF_IN_SYNC]       = self::getError(PR_RTF_IN_SYNC, $messageprops);

        if ( // 1
                ($messageprops[PR_BODY]             == MAPI_E_NOT_FOUND) &&
                ($messageprops[PR_RTF_COMPRESSED]   == MAPI_E_NOT_FOUND) &&
                ($messageprops[PR_HTML]             == MAPI_E_NOT_FOUND))
                    return SYNC_BODYPREFERENCE_PLAIN;
                elseif ( // 2
                        ($messageprops[PR_BODY]             == MAPI_E_NOT_ENOUGH_MEMORY) &&
                        ($messageprops[PR_RTF_COMPRESSED]   == MAPI_E_NOT_FOUND) &&
                        ($messageprops[PR_HTML]             == MAPI_E_NOT_FOUND))
                        return SYNC_BODYPREFERENCE_PLAIN;
                elseif ( // 3
                        ($messageprops[PR_BODY]             == MAPI_E_NOT_ENOUGH_MEMORY) &&
                        ($messageprops[PR_RTF_COMPRESSED]   == MAPI_E_NOT_ENOUGH_MEMORY) &&
                        ($messageprops[PR_HTML]             == MAPI_E_NOT_FOUND))
                        return SYNC_BODYPREFERENCE_RTF;
                elseif ( // 4
                        ($messageprops[PR_BODY]             == MAPI_E_NOT_ENOUGH_MEMORY) &&
                        ($messageprops[PR_RTF_COMPRESSED]   == MAPI_E_NOT_ENOUGH_MEMORY) &&
                        ($messageprops[PR_HTML]             == MAPI_E_NOT_ENOUGH_MEMORY) &&
                        ($messageprops[PR_RTF_IN_SYNC]))
                        return SYNC_BODYPREFERENCE_RTF;
                elseif ( // 5
                        ($messageprops[PR_BODY]             == MAPI_E_NOT_ENOUGH_MEMORY) &&
                        ($messageprops[PR_RTF_COMPRESSED]   == MAPI_E_NOT_ENOUGH_MEMORY) &&
                        ($messageprops[PR_HTML]             == MAPI_E_NOT_ENOUGH_MEMORY) &&
                        (!$messageprops[PR_RTF_IN_SYNC]))
                        return SYNC_BODYPREFERENCE_HTML;
                elseif ( // 6
                        ($messageprops[PR_RTF_COMPRESSED]   != MAPI_E_NOT_FOUND   || $messageprops[PR_RTF_COMPRESSED]  == MAPI_E_NOT_ENOUGH_MEMORY) &&
                        ($messageprops[PR_HTML]             != MAPI_E_NOT_FOUND   || $messageprops[PR_HTML]            == MAPI_E_NOT_ENOUGH_MEMORY) &&
                        ($messageprops[PR_RTF_IN_SYNC]))
                        return SYNC_BODYPREFERENCE_RTF;
                elseif ( // 7
                        ($messageprops[PR_RTF_COMPRESSED]   != MAPI_E_NOT_FOUND   || $messageprops[PR_RTF_COMPRESSED]  == MAPI_E_NOT_ENOUGH_MEMORY) &&
                        ($messageprops[PR_HTML]             != MAPI_E_NOT_FOUND   || $messageprops[PR_HTML]            == MAPI_E_NOT_ENOUGH_MEMORY) &&
                        (!$messageprops[PR_RTF_IN_SYNC]))
                        return SYNC_BODYPREFERENCE_HTML;
                elseif ( // 8
                        ($messageprops[PR_BODY]             != MAPI_E_NOT_FOUND   || $messageprops[PR_BODY]            == MAPI_E_NOT_ENOUGH_MEMORY) &&
                        ($messageprops[PR_RTF_COMPRESSED]   != MAPI_E_NOT_FOUND   || $messageprops[PR_RTF_COMPRESSED]  == MAPI_E_NOT_ENOUGH_MEMORY) &&
                        ($messageprops[PR_RTF_IN_SYNC]))
                        return SYNC_BODYPREFERENCE_RTF;
                elseif ( // 9.1
                        ($messageprops[PR_BODY]             != MAPI_E_NOT_FOUND   || $messageprops[PR_BODY]            == MAPI_E_NOT_ENOUGH_MEMORY) &&
                        ($messageprops[PR_RTF_COMPRESSED]   != MAPI_E_NOT_FOUND   || $messageprops[PR_RTF_COMPRESSED]  == MAPI_E_NOT_ENOUGH_MEMORY) &&
                        (!$messageprops[PR_RTF_IN_SYNC]))
                        return SYNC_BODYPREFERENCE_PLAIN;
                elseif ( // 9.2
                        ($messageprops[PR_RTF_COMPRESSED]   != MAPI_E_NOT_FOUND   || $messageprops[PR_RTF_COMPRESSED]  == MAPI_E_NOT_ENOUGH_MEMORY) &&
                        ($messageprops[PR_BODY]             == MAPI_E_NOT_FOUND) &&
                        ($messageprops[PR_HTML]             == MAPI_E_NOT_FOUND))
                        return SYNC_BODYPREFERENCE_RTF;
                elseif ( // 9.3
                        ($messageprops[PR_BODY]             != MAPI_E_NOT_FOUND   || $messageprops[PR_BODY]            == MAPI_E_NOT_ENOUGH_MEMORY) &&
                        ($messageprops[PR_RTF_COMPRESSED]   == MAPI_E_NOT_FOUND) &&
                        ($messageprops[PR_HTML]             == MAPI_E_NOT_FOUND))
                        return SYNC_BODYPREFERENCE_PLAIN;
                elseif ( // 9.4
                        ($messageprops[PR_HTML]             != MAPI_E_NOT_FOUND   || $messageprops[PR_HTML]            == MAPI_E_NOT_ENOUGH_MEMORY) &&
                        ($messageprops[PR_BODY]             == MAPI_E_NOT_FOUND) &&
                        ($messageprops[PR_RTF_COMPRESSED]   == MAPI_E_NOT_FOUND))
                        return SYNC_BODYPREFERENCE_HTML;
                else // 10
                    return SYNC_BODYPREFERENCE_PLAIN;
    }

    /**
     * Returns the error code for a given property. Helper for getNativeBodyType function.
     *
     * @param int               $tag
     * @param array             $messageprops
     *
     * @access private
     * @return int (MAPI_ERROR_CODE)
     */
    private static function getError($tag, $messageprops) {
        $prBodyError = mapi_prop_tag(PT_ERROR, mapi_prop_id($tag));
        if(isset($messageprops[$prBodyError]) && mapi_is_error($messageprops[$prBodyError])) {
            if($messageprops[$prBodyError] == MAPI_E_NOT_ENOUGH_MEMORY_32BIT ||
                    $messageprops[$prBodyError] == MAPI_E_NOT_ENOUGH_MEMORY_64BIT) {
                        return MAPI_E_NOT_ENOUGH_MEMORY;
                    }
        }
        return MAPI_E_NOT_FOUND;
    }
}

?>