<?php
/***********************************************
* File      :   wbxmldefs.php
* Project   :   Z-Push
* Descr     :   WBXML definitions
*
* Created   :   01.10.2007
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


define('WBXML_SWITCH_PAGE',     0x00);
define('WBXML_END',             0x01);
define('WBXML_ENTITY',          0x02);
define('WBXML_STR_I',           0x03);
define('WBXML_LITERAL',         0x04);
define('WBXML_EXT_I_0',         0x40);
define('WBXML_EXT_I_1',         0x41);
define('WBXML_EXT_I_2',         0x42);
define('WBXML_PI',              0x43);
define('WBXML_LITERAL_C',       0x44);
define('WBXML_EXT_T_0',         0x80);
define('WBXML_EXT_T_1',         0x81);
define('WBXML_EXT_T_2',         0x82);
define('WBXML_STR_T',           0x83);
define('WBXML_LITERAL_A',       0x84);
define('WBXML_EXT_0',           0xC0);
define('WBXML_EXT_1',           0xC1);
define('WBXML_EXT_2',           0xC2);
define('WBXML_OPAQUE',          0xC3);
define('WBXML_LITERAL_AC',      0xC4);

define('EN_TYPE',               1);
define('EN_TAG',                2);
define('EN_CONTENT',            3);
define('EN_FLAGS',              4);
define('EN_ATTRIBUTES',         5);

define('EN_TYPE_STARTTAG',      1);
define('EN_TYPE_ENDTAG',        2);
define('EN_TYPE_CONTENT',       3);

define('EN_FLAGS_CONTENT',      1);
define('EN_FLAGS_ATTRIBUTES',   2);

class WBXMLDefs {
    /**
     * The WBXML DTDs
     */
    protected $dtd = array(
                "codes" => array (
                    0 => array (
                        0x05 => "Synchronize",
                        0x06 => "Replies", //Responses
                        0x07 => "Add",
                        0x08 => "Modify", //Change
                        0x09 => "Remove", //Delete
                        0x0a => "Fetch",
                        0x0b => "SyncKey",
                        0x0c => "ClientEntryId", //ClientId
                        0x0d => "ServerEntryId", //ServerId
                        0x0e => "Status",
                        0x0f => "Folder", //collection
                        0x10 => "FolderType", //class
                        0x11 => "Version",
                        0x12 => "FolderId", //CollectionId
                        0x13 => "GetChanges",
                        0x14 => "MoreAvailable",
                        0x15 => "WindowSize", //WindowSize - MaxItems before z-push 2
                        0x16 => "Perform", //Commands
                        0x17 => "Options",
                        0x18 => "FilterType",
                        0x19 => "Truncation", //2.0 and 2.5
                        0x1a => "RtfTruncation", //2.0 and 2.5
                        0x1b => "Conflict",
                        0x1c => "Folders", //Collections
                        0x1d => "Data",
                        0x1e => "DeletesAsMoves",
                        0x1f => "NotifyGUID", //2.0 and 2.5
                        0x20 => "Supported",
                        0x21 => "SoftDelete",
                        0x22 => "MIMESupport",
                        0x23 => "MIMETruncation",
                        0x24 => "Wait", //12.1 and 14.0
                        0x25 => "Limit", //12.1 and 14.0
                        0x26 => "Partial", //12.1 and 14.0
                        0x27 => "ConversationMode", //14.0
                        0x28 => "MaxItems", //14.0
                        0x29 => "HeartbeatInterval", //14.0 Either this tag or the Wait tag can be present, but not both.
                    ),
                    1 => array (
                        0x05 => "Anniversary",
                        0x06 => "AssistantName",
                        0x07 => "AssistnamePhoneNumber", //AssistantTelephoneNumber
                        0x08 => "Birthday",
                        0x09 => "Body", // 2.5, but is in code page 17 in ActiveSync versions 12.0, 12.1, and 14.0.
                        0x0a => "BodySize", //2.0 and 2.5
                        0x0b => "BodyTruncated", //2.0 and 2.5
                        0x0c => "Business2PhoneNumber",
                        0x0d => "BusinessCity",
                        0x0e => "BusinessCountry",
                        0x0f => "BusinessPostalCode",
                        0x10 => "BusinessState",
                        0x11 => "BusinessStreet",
                        0x12 => "BusinessFaxNumber",
                        0x13 => "BusinessPhoneNumber",
                        0x14 => "CarPhoneNumber",
                        0x15 => "Categories",
                        0x16 => "Category",
                        0x17 => "Children",
                        0x18 => "Child",
                        0x19 => "CompanyName",
                        0x1a => "Department",
                        0x1b => "Email1Address",
                        0x1c => "Email2Address",
                        0x1d => "Email3Address",
                        0x1e => "FileAs",
                        0x1f => "FirstName",
                        0x20 => "Home2PhoneNumber",
                        0x21 => "HomeCity",
                        0x22 => "HomeCountry",
                        0x23 => "HomePostalCode",
                        0x24 => "HomeState",
                        0x25 => "HomeStreet",
                        0x26 => "HomeFaxNumber",
                        0x27 => "HomePhoneNumber",
                        0x28 => "JobTitle",
                        0x29 => "LastName",
                        0x2a => "MiddleName",
                        0x2b => "MobilePhoneNumber",
                        0x2c => "OfficeLocation",
                        0x2d => "OtherCity",
                        0x2e => "OtherCountry",
                        0x2f => "OtherPostalCode",
                        0x30 => "OtherState",
                        0x31 => "OtherStreet",
                        0x32 => "PagerNumber",
                        0x33 => "RadioPhoneNumber",
                        0x34 => "Spouse",
                        0x35 => "Suffix",
                        0x36 => "Title",
                        0x37 => "WebPage",
                        0x38 => "YomiCompanyName",
                        0x39 => "YomiFirstName",
                        0x3a => "YomiLastName",
                        0x3b => "Rtf", //CompressedRTF - 2.5
                        0x3c => "Picture",
                        0x3d => "Alias", //14.0
                        0x3e => "WeightedRank" //14.0
                    ),
                    2 => array (
                        0x05 => "Attachment", //2.5, 12.0, 12.1 and 14.0
                        0x06 => "Attachments", //2.5, 12.0, 12.1 and 14.0
                        0x07 => "AttName", //2.5, 12.0, 12.1 and 14.0
                        0x08 => "AttSize",  //2.5, 12.0, 12.1 and 14.0
                        0x09 => "AttOid",  //2.5, 12.0, 12.1 and 14.0
                        0x0a => "AttMethod", //2.5, 12.0, 12.1 and 14.0
                        0x0b => "AttRemoved", //2.5, 12.0, 12.1 and 14.0
                        0x0c => "Body", // 2.5, but is in code page 17 in ActiveSync versions 12.0, 12.1, and 14.0.
                        0x0d => "BodySize", //2.5, 12.0, 12.1 and 14.0
                        0x0e => "BodyTruncated", //2.5, 12.0, 12.1 and 14.0
                        0x0f => "DateReceived", //2.5, 12.0, 12.1 and 14.0
                        0x10 => "DisplayName", //2.5, 12.0, 12.1 and 14.0
                        0x11 => "DisplayTo", //2.5, 12.0, 12.1 and 14.0
                        0x12 => "Importance", //2.5, 12.0, 12.1 and 14.0
                        0x13 => "MessageClass", //2.5, 12.0, 12.1 and 14.0
                        0x14 => "Subject", //2.5, 12.0, 12.1 and 14.0
                        0x15 => "Read", //2.5, 12.0, 12.1 and 14.0
                        0x16 => "To", //2.5, 12.0, 12.1 and 14.0
                        0x17 => "Cc", //2.5, 12.0, 12.1 and 14.0
                        0x18 => "From", //2.5, 12.0, 12.1 and 14.0
                        0x19 => "Reply-To", //ReplyTo 2.5, 12.0, 12.1 and 14.0
                        0x1a => "AllDayEvent", //2.5, 12.0, 12.1 and 14.0
                        0x1b => "Categories", //2.5, 12.0, 12.1 and 14.0
                        0x1c => "Category", //2.5, 12.0, 12.1 and 14.0
                        0x1d => "DtStamp", //2.5, 12.0, 12.1 and 14.0
                        0x1e => "EndTime", //2.5, 12.0, 12.1 and 14.0
                        0x1f => "InstanceType", //2.5, 12.0, 12.1 and 14.0
                        0x20 => "BusyStatus", //2.5, 12.0, 12.1 and 14.0
                        0x21 => "Location", //2.5, 12.0, 12.1 and 14.0
                        0x22 => "MeetingRequest", //2.5, 12.0, 12.1 and 14.0
                        0x23 => "Organizer", //2.5, 12.0, 12.1 and 14.0
                        0x24 => "RecurrenceId", //2.5, 12.0, 12.1 and 14.0
                        0x25 => "Reminder", //2.5, 12.0, 12.1 and 14.0
                        0x26 => "ResponseRequested", //2.5, 12.0, 12.1 and 14.0
                        0x27 => "Recurrences", //2.5, 12.0, 12.1 and 14.0
                        0x28 => "Recurrence", //2.5, 12.0, 12.1 and 14.0
                        0x29 => "Type", //Recurrence_Type //2.5, 12.0, 12.1 and 14.0
                        0x2a => "Until", //Recurrence_Until //2.5, 12.0, 12.1 and 14.0
                        0x2b => "Occurrences", //Recurrence_Occurrences //2.5, 12.0, 12.1 and 14.0
                        0x2c => "Interval", //Recurrence_Interval //2.5, 12.0, 12.1 and 14.0
                        0x2d => "DayOfWeek", //Recurrence_DayOfWeek //2.5, 12.0, 12.1 and 14.0
                        0x2e => "DayOfMonth", //Recurrence_DayOfMonth //2.5, 12.0, 12.1 and 14.0
                        0x2f => "WeekOfMonth", //Recurrence_WeekOfMonth //2.5, 12.0, 12.1 and 14.0
                        0x30 => "MonthOfYear", //Recurrence_MonthOfYear //2.5, 12.0, 12.1 and 14.0
                        0x31 => "StartTime", //2.5, 12.0, 12.1 and 14.0
                        0x32 => "Sensitivity", //2.5, 12.0, 12.1 and 14.0
                        0x33 => "TimeZone", //2.5, 12.0, 12.1 and 14.0
                        0x34 => "GlobalObjId", //2.5, 12.0, 12.1 and 14.0
                        0x35 => "ThreadTopic", //2.5, 12.0, 12.1 and 14.0
                        0x36 => "MIMEData", //2.5
                        0x37 => "MIMETruncated", //2.5
                        0x38 => "MIMESize", //2.5
                        0x39 => "InternetCPID", //2.5, 12.0, 12.1 and 14.0
                        0x3a => "Flag", //12.0, 12.1 and 14.0
                        0x3b => "FlagStatus", //12.0, 12.1 and 14.0
                        0x3c => "ContentClass", //12.0, 12.1 and 14.0
                        0x3d => "FlagType", //12.0, 12.1 and 14.0
                        0x3e => "CompleteTime", //14.0
                        0x3f => "DisallowNewTimeProposal", //14.0
                    ),
                    3 => array ( //Code page 3 is no longer in use, however, tokens 05 through 17 have been defined. 20100501
                        0x05 => "Notify",
                        0x06 => "Notification",
                        0x07 => "Version",
                        0x08 => "Lifetime",
                        0x09 => "DeviceInfo",
                        0x0a => "Enable",
                        0x0b => "Folder",
                        0x0c => "ServerEntryId",
                        0x0d => "DeviceAddress",
                        0x0e => "ValidCarrierProfiles",
                        0x0f => "CarrierProfile",
                        0x10 => "Status",
                        0x11 => "Replies",
//                        0x05 => "Version='1.1'",
                        0x12 => "Devices",
                        0x13 => "Device",
                        0x14 => "Id",
                        0x15 => "Expiry",
                        0x16 => "NotifyGUID",
                    ),
                    4 => array (
                        0x05 => "Timezone", //2.5, 12.0, 12.1 and 14.0
                        0x06 => "AllDayEvent", //2.5, 12.0, 12.1 and 14.0
                        0x07 => "Attendees", //2.5, 12.0, 12.1 and 14.0
                        0x08 => "Attendee", //2.5, 12.0, 12.1 and 14.0
                        0x09 => "Email", //Attendee_Email //2.5, 12.0, 12.1 and 14.0
                        0x0a => "Name", //Attendee_Name //2.5, 12.0, 12.1 and 14.0
                        0x0b => "Body", //2.5, but is in code page 17 in ActiveSync versions 12.0, 12.1, and 14.0
                        0x0c => "BodyTruncated", //2.5, 12.0, 12.1 and 14.0
                        0x0d => "BusyStatus", //2.5, 12.0, 12.1 and 14.0
                        0x0e => "Categories", //2.5, 12.0, 12.1 and 14.0
                        0x0f => "Category", //2.5, 12.0, 12.1 and 14.0
                        0x10 => "Rtf", //2.5
                        0x11 => "DtStamp", //2.5, 12.0, 12.1 and 14.0
                        0x12 => "EndTime", //2.5, 12.0, 12.1 and 14.0
                        0x13 => "Exception", //2.5, 12.0, 12.1 and 14.0
                        0x14 => "Exceptions", //2.5, 12.0, 12.1 and 14.0
                        0x15 => "Deleted", //Exception_Deleted //2.5, 12.0, 12.1 and 14.0
                        0x16 => "ExceptionStartTime", //Exception_StartTime //2.5, 12.0, 12.1 and 14.0
                        0x17 => "Location", //2.5, 12.0, 12.1 and 14.0
                        0x18 => "MeetingStatus", //2.5, 12.0, 12.1 and 14.0
                        0x19 => "OrganizerEmail", //Organizer_Email //2.5, 12.0, 12.1 and 14.0
                        0x1a => "OrganizerName", //Organizer_Name //2.5, 12.0, 12.1 and 14.0
                        0x1b => "Recurrence", //2.5, 12.0, 12.1 and 14.0
                        0x1c => "Type", //Recurrence_Type //2.5, 12.0, 12.1 and 14.0
                        0x1d => "Until", //Recurrence_Until //2.5, 12.0, 12.1 and 14.0
                        0x1e => "Occurrences", //Recurrence_Occurrences //2.5, 12.0, 12.1 and 14.0
                        0x1f => "Interval", //Recurrence_Interval //2.5, 12.0, 12.1 and 14.0
                        0x20 => "DayOfWeek", //Recurrence_DayOfWeek //2.5, 12.0, 12.1 and 14.0
                        0x21 => "DayOfMonth", //Recurrence_DayOfMonth //2.5, 12.0, 12.1 and 14.0
                        0x22 => "WeekOfMonth", //Recurrence_WeekOfMonth //2.5, 12.0, 12.1 and 14.0
                        0x23 => "MonthOfYear", //Recurrence_MonthOfYear //2.5, 12.0, 12.1 and 14.0
                        0x24 => "Reminder", //Reminder_MinsBefore //2.5, 12.0, 12.1 and 14.0
                        0x25 => "Sensitivity", //2.5, 12.0, 12.1 and 14.0
                        0x26 => "Subject", //2.5, 12.0, 12.1 and 14.0
                        0x27 => "StartTime", //2.5, 12.0, 12.1 and 14.0
                        0x28 => "UID", //2.5, 12.0, 12.1 and 14.0
                        0x29 => "Attendee_Status", //12.0, 12.1 and 14.0
                        0x2a => "Attendee_Type", //12.0, 12.1 and 14.0
                        0x2b => "Attachment", //12.0, 12.1 and 14.0
                        0x2c => "Attachments", //12.0, 12.1 and 14.0
                        0x2d => "AttName", //12.0, 12.1 and 14.0
                        0x2e => "AttSize", //12.0, 12.1 and 14.0
                        0x2f => "AttOid", //12.0, 12.1 and 14.0
                        0x30 => "AttMethod", //12.0, 12.1 and 14.0
                        0x31 => "AttRemoved", //12.0, 12.1 and 14.0
                        0x32 => "DisplayName", //12.0, 12.1 and 14.0
                        0x33 => "DisallowNewTimeProposal", //14.0
                        0x34 => "ResponseRequested", //14.0
                        0x35 => "AppointmentReplyTime", //14.0
                        0x36 => "ResponseType", //14.0
                        0x37 => "CalendarType", //14.0
                        0x38 => "IsLeapMonth", //14.0
                        0x39 => "FirstDayOfWeek", //post 14.0 20100501
                        0x3a => "OnlineMeetingInternalLink", //post 14.0 20100501
                        0x3b => "OnlineMeetingExternalLink", //post 14.0 20120630
                    ),
                    5 => array (
                        0x05 => "Moves",
                        0x06 => "Move",
                        0x07 => "SrcMsgId",
                        0x08 => "SrcFldId",
                        0x09 => "DstFldId",
                        0x0a => "Response",
                        0x0b => "Status",
                        0x0c => "DstMsgId",
                    ),
                    6 => array (
                        0x05 => "GetItemEstimate",
                        0x06 => "Version", //only 12.1 20100501
                        0x07 => "Folders", //Collections
                        0x08 => "Folder", //Collection
                        0x09 => "FolderType",   //Class //only 12.1 //The <Class> tag defined in code page 0 should be used in all other instances. 20100501
                        0x0a => "FolderId", //CollectionId
                        0x0b => "DateTime", //not supported by 14. only supported 12.1. 20100501
                        0x0c => "Estimate",
                        0x0d => "Response",
                        0x0e => "Status",
                    ),
                    7 => array (
                        0x05 => "Folders", //2.0
                        0x06 => "Folder", //2.0
                        0x07 => "DisplayName",
                        0x08 => "ServerEntryId", //ServerId
                        0x09 => "ParentId",
                        0x0a => "Type",
                        0x0b => "Response", //2.0
                        0x0c => "Status",
                        0x0d => "ContentClass", //2.0
                        0x0e => "Changes",
                        0x0f => "Add",
                        0x10 => "Remove",
                        0x11 => "Update",
                        0x12 => "SyncKey",
                        0x13 => "FolderCreate",
                        0x14 => "FolderDelete",
                        0x15 => "FolderUpdate",
                        0x16 => "FolderSync",
                        0x17 => "Count",
                        0x18 => "Version", //2.0 - not defined in 20100501
                    ),
                    8 => array (
                        0x05 => "CalendarId",
                        0x06 => "FolderId", //CollectionId
                        0x07 => "MeetingResponse",
                        0x08 => "RequestId",
                        0x09 => "Request",
                        0x0a => "Result",
                        0x0b => "Status",
                        0x0c => "UserResponse",
                        0x0d => "Version", //2.0 - not defined in 20100501
                        0x0e => "InstanceId" // first in 20100501
                    ),
                    9 => array (
                        0x05 => "Body", //2.5, but is in code page 17 in ActiveSync versions 12.0, 12.1, and 14.0
                        0x06 => "BodySize", //2.5, but is in code page 17 as the EstimatedDataSize tag in ActiveSync versions 12.0, 12.1 and 14.0
                        0x07 => "BodyTruncated", //2.5, but is in code page 17 as the Truncated tag in ActiveSync versions 12.0, 12.1, and 14.0
                        0x08 => "Categories", //2.5, 12.0, 12.1 and 14.0
                        0x09 => "Category", //2.5, 12.0, 12.1 and 14.0
                        0x0a => "Complete", //2.5, 12.0, 12.1 and 14.0
                        0x0b => "DateCompleted", //2.5, 12.0, 12.1 and 14.0
                        0x0c => "DueDate", //2.5, 12.0, 12.1 and 14.0
                        0x0d => "UtcDueDate", //2.5, 12.0, 12.1 and 14.0
                        0x0e => "Importance", //2.5, 12.0, 12.1 and 14.0
                        0x0f => "Recurrence", //2.5, 12.0, 12.1 and 14.0
                        0x10 => "Type", //Recurrence_Type //2.5, 12.0, 12.1 and 14.0
                        0x11 => "Start", //Recurrence_Start //2.5, 12.0, 12.1 and 14.0
                        0x12 => "Until", //Recurrence_Until //2.5, 12.0, 12.1 and 14.0
                        0x13 => "Occurrences", //Recurrence_Occurrences //2.5, 12.0, 12.1 and 14.0
                        0x14 => "Interval", //Recurrence_Interval //2.5, 12.0, 12.1 and 14.0
                        0x16 => "DayOfWeek", //Recurrence_DayOfMonth //2.5, 12.0, 12.1 and 14.0
                        0x15 => "DayOfMonth", //Recurrence_DayOfWeek //2.5, 12.0, 12.1 and 14.0
                        0x17 => "WeekOfMonth", //Recurrence_WeekOfMonth //2.5, 12.0, 12.1 and 14.0
                        0x18 => "MonthOfYear", //Recurrence_MonthOfYear //2.5, 12.0, 12.1 and 14.0
                        0x19 => "Regenerate", //Recurrence_Regenerate //2.5, 12.0, 12.1 and 14.0
                        0x1a => "DeadOccur", //Recurrence_DeadOccur //2.5, 12.0, 12.1 and 14.0
                        0x1b => "ReminderSet", //2.5, 12.0, 12.1 and 14.0
                        0x1c => "ReminderTime", //2.5, 12.0, 12.1 and 14.0
                        0x1d => "Sensitivity", //2.5, 12.0, 12.1 and 14.0
                        0x1e => "StartDate", //2.5, 12.0, 12.1 and 14.0
                        0x1f => "UtcStartDate", //2.5, 12.0, 12.1 and 14.0
                        0x20 => "Subject", //2.5, 12.0, 12.1 and 14.0
                        0x21 => "Rtf", //CompressedRTF //2.5, but is in code page 17 as the Type tag in Active Sync versions 12.0, 12.1, and 14.0
                        0x22 => "OrdinalDate", //12.0, 12.1 and 14.0
                        0x23 => "SubOrdinalDate", //12.0, 12.1 and 14.0
                        0x24 => "CalendarType", //14.0
                        0x25 => "IsLeapMonth", //14.0
                        0x26 => "FirstDayOfWeek", // first in 20100501 post 14.0
                    ),
                    0xa => array (
                        0x05 => "ResolveRecipients",
                        0x06 => "Response",
                        0x07 => "Status",
                        0x08 => "Type",
                        0x09 => "Recipient",
                        0x0a => "DisplayName",
                        0x0b => "EmailAddress",
                        0x0c => "Certificates",
                        0x0d => "Certificate",
                        0x0e => "MiniCertificate",
                        0x0f => "Options",
                        0x10 => "To",
                        0x11 => "CertificateRetrieval",
                        0x12 => "RecipientCount",
                        0x13 => "MaxCertificates",
                        0x14 => "MaxAmbiguousRecipients",
                        0x15 => "CertificateCount",
                        0x16 => "Availability", //14.0
                        0x17 => "StartTime", //14.0
                        0x18 => "EndTime", //14.0
                        0x19 => "MergedFreeBusy", //14.0
                        0x1A => "Picture", // first in 20100501 post 14.0
                        0x1B => "MaxSize", // first in 20100501 post 14.0
                        0x1C => "Data", // first in 20100501 post 14.0
                        0x1D => "MaxPictures", // first in 20100501 post 14.0
                    ),
                    0xb => array (
                        0x05 => "ValidateCert",
                        0x06 => "Certificates",
                        0x07 => "Certificate",
                        0x08 => "CertificateChain",
                        0x09 => "CheckCRL",
                        0x0a => "Status",
                    ),
                    0xc => array (
                        0x05 => "CustomerId",
                        0x06 => "GovernmentId",
                        0x07 => "IMAddress",
                        0x08 => "IMAddress2",
                        0x09 => "IMAddress3",
                        0x0a => "ManagerName",
                        0x0b => "CompanyMainPhone",
                        0x0c => "AccountName",
                        0x0d => "NickName",
                        0x0e => "MMS",
                    ),
                    0xd => array (
                        0x05 => "Ping",
                        0x06 => "AutdState", //(Not used by protocol)
                        0x07 => "Status",
                        0x08 => "LifeTime", //HeartbeatInterval
                        0x09 => "Folders",
                        0x0a => "Folder",
                        0x0b => "ServerEntryId", //Id
                        0x0c => "FolderType", //Class
                        0x0d => "MaxFolders",
                        0x0e => "Version" //not defined in 20100501
                    ),
                    0xe => array (
                        0x05 => "Provision", //2.5, 12.0, 12.1 and 14.0
                        0x06 => "Policies", //2.5, 12.0, 12.1 and 14.0
                        0x07 => "Policy", //2.5, 12.0, 12.1 and 14.0
                        0x08 => "PolicyType", //2.5, 12.0, 12.1 and 14.0
                        0x09 => "PolicyKey", //2.5, 12.0, 12.1 and 14.0
                        0x0A => "Data", //2.5, 12.0, 12.1 and 14.0
                        0x0B => "Status", //2.5, 12.0, 12.1 and 14.0
                        0x0C => "RemoteWipe", //2.5, 12.0, 12.1 and 14.0
                        0x0D => "EASProvisionDoc", //12.0, 12.1 and 14.0
                        0x0E => "DevicePasswordEnabled", //12.0, 12.1 and 14.0
                        0x0F => "AlphanumericDevicePasswordRequired", //12.0, 12.1 and 14.0
                        0x10 => "DeviceEncryptionEnabled", //12.0, 12.1 and 14.0
                        //0x10 => "RequireStorageCardEncryption", //12.1 and 14.0
                        0x11 => "PasswordRecoveryEnabled", //12.0, 12.1 and 14.0
                        0x12 => "DocumentBrowseEnabled", //2.0 and 2.5.
                        0x13 => "AttachmentsEnabled", //12.0, 12.1 and 14.0
                        0x14 => "MinDevicePasswordLength", //12.0, 12.1 and 14.0
                        0x15 => "MaxInactivityTimeDeviceLock", //12.0, 12.1 and 14.0
                        0x16 => "MaxDevicePasswordFailedAttempts", //12.0, 12.1 and 14.0
                        0x17 => "MaxAttachmentSize", //12.0, 12.1 and 14.0
                        0x18 => "AllowSimpleDevicePassword", //12.0, 12.1 and 14.0
                        0x19 => "DevicePasswordExpiration", //12.0, 12.1 and 14.0
                        0x1A => "DevicePasswordHistory", //12.0, 12.1 and 14.0
                        0x1B => "AllowStorageCard", //12.1 and 14.0
                        0x1C => "AllowCamera", //12.1 and 14.0
                        0x1D => "RequireDeviceEncryption", //12.1 and 14.0
                        0x1E => "AllowUnsignedApplications", //12.1 and 14.0
                        0x1F => "AllowUnsignedInstallationPackages", //12.1 and 14.0
                        0x20 => "MinDevicePasswordComplexCharacters", //12.1 and 14.0
                        0x21 => "AllowWiFi", //12.1 and 14.0
                        0x22 => "AllowTextMessaging", //12.1 and 14.0
                        0x23 => "AllowPOPIMAPEmail", //12.1 and 14.0
                        0x24 => "AllowBluetooth", //12.1 and 14.0
                        0x25 => "AllowIrDA", //12.1 and 14.0
                        0x26 => "RequireManualSyncWhenRoaming", //12.1 and 14.0
                        0x27 => "AllowDesktopSync", //12.1 and 14.0
                        0x28 => "MaxCalendarAgeFilter", //12.1 and 14.0
                        0x29 => "AllowHTMLEmail", //12.1 and 14.0
                        0x2A => "MaxEmailAgeFilter", //12.1 and 14.0
                        0x2B => "MaxEmailBodyTruncationSize", //12.1 and 14.0
                        0x2C => "MaxEmailHTMLBodyTruncationSize", //12.1 and 14.0
                        0x2D => "RequireSignedSMIMEMessages", //12.1 and 14.0
                        0x2E => "RequireEncryptedSMIMEMessages", //12.1 and 14.0
                        0x2F => "RequireSignedSMIMEAlgorithm", //12.1 and 14.0
                        0x30 => "RequireEncryptionSMIMEAlgorithm", //12.1 and 14.0
                        0x31 => "AllowSMIMEEncryptionAlgorithmNegotiation", //12.1 and 14.0
                        0x32 => "AllowSMIMESoftCerts", //12.1 and 14.0
                        0x33 => "AllowBrowser", //12.1 and 14.0
                        0x34 => "AllowConsumerEmail", //12.1 and 14.0
                        0x35 => "AllowRemoteDesktop", //12.1 and 14.0
                        0x36 => "AllowInternetSharing", //12.1 and 14.0
                        0x37 => "UnapprovedInROMApplicationList", //12.1 and 14.0
                        0x38 => "ApplicationName", //12.1 and 14.0
                        0x39 => "ApprovedApplicationList", //12.1 and 14.0
                        0x3A => "Hash", //12.1 and 14.0
                    ),
                    0xf => array(
                        0x05 => "Search", //12.0, 12.1 and 14.0
                        0x07 => "Store", //12.0, 12.1 and 14.0
                        0x08 => "Name", //12.0, 12.1 and 14.0
                        0x09 => "Query", //12.0, 12.1 and 14.0
                        0x0A => "Options", //12.0, 12.1 and 14.0
                        0x0B => "Range", //12.0, 12.1 and 14.0
                        0x0C => "Status", //12.0, 12.1 and 14.0
                        0x0D => "Response", //12.0, 12.1 and 14.0
                        0x0E => "Result", //12.0, 12.1 and 14.0
                        0x0F => "Properties", //12.0, 12.1 and 14.0
                        0x10 => "Total", //12.0, 12.1 and 14.0
                        0x11 => "EqualTo", //12.0, 12.1 and 14.0
                        0x12 => "Value", //12.0, 12.1 and 14.0
                        0x13 => "And", //12.0, 12.1 and 14.0
                        0x14 => "Or", //14.0
                        0x15 => "FreeText", //12.0, 12.1 and 14.0
                        0x17 => "DeepTraversal", //12.0, 12.1 and 14.0
                        0x18 => "LongId", //12.0, 12.1 and 14.0
                        0x19 => "RebuildResults", //12.0, 12.1 and 14.0
                        0x1A => "LessThan", //12.0, 12.1 and 14.0
                        0x1B => "GreaterThan", //12.0, 12.1 and 14.0
                        0x1C => "Schema", //12.0, 12.1 and 14.0
                        0x1D => "Supported", //12.0, 12.1 and 14.0
                        0x1E => "UserName", //12.1 and 14.0
                        0x1F => "Password", //12.1 and 14.0
                        0x20 => "ConversationId", //14.0
                        0x21 => "Picture", // first in 20100501 post 14.0
                        0x22 => "MaxSize", // first in 20100501 post 14.0
                        0x23 => "MaxPictures", // first in 20100501 post 14.0
                    ),
                    0x10 => array(
                        0x05 => "DisplayName",
                        0x06 => "Phone",
                        0x07 => "Office",
                        0x08 => "Title",
                        0x09 => "Company",
                        0x0A => "Alias",
                        0x0B => "FirstName",
                        0x0C => "LastName",
                        0x0D => "HomePhone",
                        0x0E => "MobilePhone",
                        0x0F => "EmailAddress",
                        0x10 => "Picture", // first in 20100501 post 14.0
                        0x11 => "Status", // first in 20100501 post 14.0
                        0x12 => "Data", // first in 20100501 post 14.0
                    ),
                    0x11 => array( //12.0, 12.1 and 14.0
                        0x05 => "BodyPreference",
                        0x06 => "Type",
                        0x07 => "TruncationSize",
                        0x08 => "AllOrNone",
                        0x0A => "Body",
                        0x0B => "Data",
                        0x0C => "EstimatedDataSize",
                        0x0D => "Truncated",
                        0x0E => "Attachments",
                        0x0F => "Attachment",
                        0x10 => "DisplayName",
                        0x11 => "FileReference",
                        0x12 => "Method",
                        0x13 => "ContentId",
                        0x14 => "ContentLocation", //not used
                        0x15 => "IsInline",
                        0x16 => "NativeBodyType",
                        0x17 => "ContentType",
                        0x18 => "Preview", //14.0
                        0x19 => "BodyPartPreference", // first in 20100501 post 14.0
                        0x1A => "BodyPart", // first in 20100501 post 14.0
                        0x1B => "Status", // first in 20100501 post 14.0
                    ),
                    0x12 => array( //12.0, 12.1 and 14.0
                        0x05 => "Settings", //12.0, 12.1 and 14.0
                        0x06 => "Status", //12.0, 12.1 and 14.0
                        0x07 => "Get", //12.0, 12.1 and 14.0
                        0x08 => "Set", //12.0, 12.1 and 14.0
                        0x09 => "Oof", //12.0, 12.1 and 14.0
                        0x0A => "OofState", //12.0, 12.1 and 14.0
                        0x0B => "StartTime", //12.0, 12.1 and 14.0
                        0x0C => "EndTime", //12.0, 12.1 and 14.0
                        0x0D => "OofMessage", //12.0, 12.1 and 14.0
                        0x0E => "AppliesToInternal", //12.0, 12.1 and 14.0
                        0x0F => "AppliesToExternalKnown", //12.0, 12.1 and 14.0
                        0x10 => "AppliesToExternalUnknown", //12.0, 12.1 and 14.0
                        0x11 => "Enabled", //12.0, 12.1 and 14.0
                        0x12 => "ReplyMessage", //12.0, 12.1 and 14.0
                        0x13 => "BodyType", //12.0, 12.1 and 14.0
                        0x14 => "DevicePassword", //12.0, 12.1 and 14.0
                        0x15 => "Password", //12.0, 12.1 and 14.0
                        0x16 => "DeviceInformaton", //12.0, 12.1 and 14.0
                        0x17 => "Model", //12.0, 12.1 and 14.0
                        0x18 => "IMEI", //12.0, 12.1 and 14.0
                        0x19 => "FriendlyName", //12.0, 12.1 and 14.0
                        0x1A => "OS", //12.0, 12.1 and 14.0
                        0x1B => "OSLanguage", //12.0, 12.1 and 14.0
                        0x1C => "PhoneNumber", //12.0, 12.1 and 14.0
                        0x1D => "UserInformation", //12.0, 12.1 and 14.0
                        0x1E => "EmailAddresses", //12.0, 12.1 and 14.0
                        0x1F => "SmtpAddress", //12.0, 12.1 and 14.0
                        0x20 => "UserAgent", //12.1 and 14.0
                        0x21 => "EnableOutboundSMS", //14.0
                        0x22 => "MobileOperator", //14.0
                        0x23 => "PrimarySmtpAddress", // first in 20100501 post 14.0
                        0x24 => "Accounts", // first in 20100501 post 14.0
                        0x25 => "Account", // first in 20100501 post 14.0
                        0x26 => "AccountId", // first in 20100501 post 14.0
                        0x27 => "AccountName", // first in 20100501 post 14.0
                        0x28 => "UserDisplayName", // first in 20100501 post 14.0
                        0x29 => "SendDisabled", // first in 20100501 post 14.0
                        0x2B => "ihsManagementInformation", // first in 20100501 post 14.0
                    ),
                    0x13 => array( //12.0, 12.1 and 14.0
                        0x05 => "LinkId",
                        0x06 => "DisplayName",
                        0x07 => "IsFolder",
                        0x08 => "CreationDate",
                        0x09 => "LastModifiedDate",
                        0x0A => "IsHidden",
                        0x0B => "ContentLength",
                        0x0C => "ContentType",
                    ),
                    0x14 => array( //12.0, 12.1 and 14.0
                        0x05 => "ItemOperations",
                        0x06 => "Fetch",
                        0x07 => "Store",
                        0x08 => "Options",
                        0x09 => "Range",
                        0x0A => "Total",
                        0x0B => "Properties",
                        0x0C => "Data",
                        0x0D => "Status",
                        0x0E => "Response",
                        0x0F => "Version",
                        0x10 => "Schema",
                        0x11 => "Part",
                        0x12 => "EmptyFolderContents",
                        0x13 => "DeleteSubFolders",
                        0x14 => "UserName", //12.1 and 14.0
                        0x15 => "Password", //12.1 and 14.0
                        0x16 => "Move", //14.0
                        0x17 => "DstFldId", //14.0
                        0x18 => "ConversationId", //14.0
                        0x19 => "MoveAlways", //14.0
                    ),
                    0x15 => array( //14.0
                        0x05 => "SendMail",
                        0x06 => "SmartForward",
                        0x07 => "SmartReply",
                        0x08 => "SaveInSentItems",
                        0x09 => "ReplaceMime",
                        0x0A => "Type",
                        0x0B => "Source",
                        0x0C => "FolderId",
                        0x0D => "ItemId",
                        0x0E => "LongId",
                        0x0F => "InstanceId",
                        0x10 => "MIME",
                        0x11 => "ClientId",
                        0x12 => "Status",
                        0x13 => "AccountId", // first in 20100501 post 14.0
                    ),
                    0x16 => array( // 14.0
                        0x05 => "UmCallerId",
                        0x06 => "UmUserNotes",
                        0x07 => "UmAttDuration",
                        0x08 => "UmAttOrder",
                        0x09 => "ConversationId",
                        0x0A => "ConversationIndex",
                        0x0B => "LastVerbExecuted",
                        0x0C => "LastVerbExecutionTime",
                        0x0D => "ReceivedAsBcc",
                        0x0E => "Sender",
                        0x0F => "CalendarType",
                        0x10 => "IsLeapMonth",
                        0x11 => "AccountId", // first in 20100501 post 14.0
                        0x12 => "FirstDayOfWeek", // first in 20100501 post 14.0
                        0x13 => "MeetingMessageType", // first in 20100501 post 14.0
                    ),
                    0x17 => array( //14.0
                        0x05 => "Subject",
                        0x06 => "MessageClass",
                        0x07 => "LastModifiedDate",
                        0x08 => "Categories",
                        0x09 => "Category",
                    ),
                    0x18 => array( // post 14.0
                        0x05 => "RightsManagementSupport",
                        0x06 => "RightsManagementTemplates",
                        0x07 => "RightsManagementTemplate",
                        0x08 => "RightsManagementLicense",
                        0x09 => "EditAllowed",
                        0x0A => "ReplyAllowed",
                        0x0B => "ReplyAllAllowed",
                        0x0C => "ForwardAllowed",
                        0x0D => "ModifyRecipientsAllowed",
                        0x0E => "ExtractAllowed",
                        0x0F => "PrintAllowed",
                        0x10 => "ExportAllowed",
                        0x11 => "ProgrammaticAccessAllowed",
                        0x12 => "RMOwner",
                        0x13 => "ContentExpiryDate",
                        0x14 => "TemplateID",
                        0x15 => "TemplateName",
                        0x16 => "TemplateDescription",
                        0x17 => "ContentOwner",
                        0x18 => "RemoveRightsManagementDistribution",
                    ),
              ),
              "namespaces" => array(
                  //0 => "AirSync", //
                  1 => "POOMCONTACTS",
                  2 => "POOMMAIL",
                  3 => "AirNotify", //no longer used
                  4 => "POOMCAL",
                  5 => "Move",
                  6 => "GetItemEstimate",
                  7 => "FolderHierarchy",
                  8 => "MeetingResponse",
                  9 => "POOMTASKS",
                  0xA => "ResolveRecipients",
                  0xB => "ValidateCerts",
                  0xC => "POOMCONTACTS2",
                  0xD => "Ping",
                  0xE => "Provision",//
                  0xF => "Search",//
                  0x10 => "GAL",
                  0x11 => "AirSyncBase", //12.0, 12.1 and 14.0
                  0x12 => "Settings", //12.0, 12.1 and 14.0.
                  0x13 => "DocumentLibrary", //12.0, 12.1 and 14.0
                  0x14 => "ItemOperations", //12.0, 12.1 and 14.0
                  0x15 => "ComposeMail", //14.0
                  0x16 => "POOMMAIL2", //14.0
                  0x17 => "Notes", //14.0
                  0x18 => "RightsManagement",
              )
          );
}

?>