<?php
/*
 * Copyright 2005 - 2016  Zarafa B.V. and its licensors
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
 */

class Meetingrequest {
    /*
     * NOTE
     *
     * This class is designed to modify and update meeting request properties
     * and to search for linked appointments in the calendar. It does not
     * - set standard properties like subject or location
     * - commit property changes through savechanges() (except in accept() and decline())
     *
     * To set all the other properties, just handle the item as any other appointment
     * item. You aren't even required to set those properties before or after using
     * this class. If you update properties before REsending a meeting request (ie with
     * a time change) you MUST first call updateMeetingRequest() so the internal counters
     * can be updated. You can then submit the message any way you like.
     *
     */

    /*
     * How to use
     * ----------
     *
     * Sending a meeting request:
     * - Create appointment item as normal, but as 'tentative'
     *   (this is the state of the item when the receiving user has received but
     *    not accepted the item)
     * - Set recipients as normally in e-mails
     * - Create Meetingrequest class instance
     * - Call setMeetingRequest(), this turns on all the meeting request properties in the
     *   calendar item
     * - Call sendMeetingRequest(), this sends a copy of the item with some extra properties
     *
     * Updating a meeting request:
     * - Create Meetingrequest class instance
     * - Call updateMeetingRequest(), this updates the counters
     * - Call sendMeetingRequest()
     *
     * Clicking on a an e-mail:
     * - Create Meetingrequest class instance
     * - Check isMeetingRequest(), if true:
     *   - Check isLocalOrganiser(), if true then ignore the message
     *   - Check isInCalendar(), if not call doAccept(true, false, false). This adds the item in your
     *     calendar as tentative without sending a response
     *   - Show Accept, Tentative, Decline buttons
     *   - When the user presses Accept, Tentative or Decline, call doAccept(false, true, true),
     *     doAccept(true, true, true) or doDecline(true) respectively to really accept or decline and
     *     send the response. This will remove the request from your inbox.
     * - Check isMeetingRequestResponse, if true:
     *   - Check isLocalOrganiser(), if not true then ignore the message
     *   - Call processMeetingRequestResponse()
     *     This will update the trackstatus of all recipients, and set the item to 'busy'
     *     when all the recipients have accepted.
     * - Check isMeetingCancellation(), if true:
     *   - Check isLocalOrganiser(), if true then ignore the message
     *   - Check isInCalendar(), if not, then ignore
     *     Call processMeetingCancellation()
     *   - Show 'Remove item' button to user
     *   - When userpresses button, call doCancel(), which removes the item from your
     *     calendar and deletes the message
     */

    // All properties for a recipient that are interesting
    var $recipprops = Array(PR_ENTRYID, PR_DISPLAY_NAME, PR_EMAIL_ADDRESS, PR_RECIPIENT_ENTRYID, PR_RECIPIENT_TYPE, PR_SEND_INTERNET_ENCODING, PR_SEND_RICH_INFO, PR_RECIPIENT_DISPLAY_NAME, PR_ADDRTYPE, PR_DISPLAY_TYPE, PR_RECIPIENT_TRACKSTATUS, PR_RECIPIENT_TRACKSTATUS_TIME, PR_RECIPIENT_FLAGS, PR_ROWID, PR_OBJECT_TYPE, PR_SEARCH_KEY);

    /**
     * Indication whether the setting of resources in a Meeting Request is success (false) or if it
     * has failed (integer).
     */
    var $errorSetResource;

    /**
     * Constructor
     *
     * Takes a store and a message. The message is an appointment item
     * that should be converted into a meeting request or an incoming
     * e-mail message that is a meeting request.
     *
     * The $session variable is optional, but required if the following features
     * are to be used:
     *
     * - Sending meeting requests for meetings that are not in your own store
     * - Sending meeting requests to resources, resource availability checking and resource freebusy updates
     */

    function __construct($store, $message, $session = false, $enableDirectBooking = true)
    {
        $this->store = $store;
        $this->message = $message;
        $this->session = $session;
        // This variable string saves time information for the MR.
        $this->meetingTimeInfo = false;
        $this->enableDirectBooking = $enableDirectBooking;

        $properties["goid"] = "PT_BINARY:PSETID_Meeting:0x3";
        $properties["goid2"] = "PT_BINARY:PSETID_Meeting:0x23";
        $properties["type"] = "PT_STRING8:PSETID_Meeting:0x24";
        $properties["meetingrecurring"] = "PT_BOOLEAN:PSETID_Meeting:0x5";
        $properties["unknown2"] = "PT_BOOLEAN:PSETID_Meeting:0xa";
        $properties["attendee_critical_change"] = "PT_SYSTIME:PSETID_Meeting:0x1";
        $properties["owner_critical_change"] = "PT_SYSTIME:PSETID_Meeting:0x1a";
        $properties["meetingstatus"] = "PT_LONG:PSETID_Appointment:0x8217";
        $properties["responsestatus"] = "PT_LONG:PSETID_Appointment:0x8218";
        $properties["unknown6"] = "PT_LONG:PSETID_Meeting:0x4";
        $properties["replytime"] = "PT_SYSTIME:PSETID_Appointment:0x8220";
        $properties["usetnef"] = "PT_BOOLEAN:PSETID_Common:0x8582";
        $properties["recurrence_data"] = "PT_BINARY:PSETID_Appointment:0x8216";
        $properties["reminderminutes"] = "PT_LONG:PSETID_Common:0x8501";
        $properties["reminderset"] = "PT_BOOLEAN:PSETID_Common:0x8503";
        $properties["sendasical"] = "PT_BOOLEAN:PSETID_Appointment:0x8200";
        $properties["updatecounter"] = "PT_LONG:PSETID_Appointment:0x8201";                    // AppointmentSequenceNumber
        $properties["last_updatecounter"] = "PT_LONG:PSETID_Appointment:0x8203";            // AppointmentLastSequence
        $properties["unknown7"] = "PT_LONG:PSETID_Appointment:0x8202";
        $properties["busystatus"] = "PT_LONG:PSETID_Appointment:0x8205";
        $properties["intendedbusystatus"] = "PT_LONG:PSETID_Appointment:0x8224";
        $properties["start"] = "PT_SYSTIME:PSETID_Appointment:0x820d";
        $properties["responselocation"] = "PT_STRING8:PSETID_Meeting:0x2";
        $properties["location"] = "PT_STRING8:PSETID_Appointment:0x8208";
        $properties["requestsent"] = "PT_BOOLEAN:PSETID_Appointment:0x8229";        // PidLidFInvited, MeetingRequestWasSent
        $properties["startdate"] = "PT_SYSTIME:PSETID_Appointment:0x820d";
        $properties["duedate"] = "PT_SYSTIME:PSETID_Appointment:0x820e";
        $properties["commonstart"] = "PT_SYSTIME:PSETID_Common:0x8516";
        $properties["commonend"] = "PT_SYSTIME:PSETID_Common:0x8517";
        $properties["recurring"] = "PT_BOOLEAN:PSETID_Appointment:0x8223";
        $properties["clipstart"] = "PT_SYSTIME:PSETID_Appointment:0x8235";
        $properties["clipend"] = "PT_SYSTIME:PSETID_Appointment:0x8236";
        $properties["start_recur_date"] = "PT_LONG:PSETID_Meeting:0xD";                // StartRecurTime
        $properties["start_recur_time"] = "PT_LONG:PSETID_Meeting:0xE";                // StartRecurTime
        $properties["end_recur_date"] = "PT_LONG:PSETID_Meeting:0xF";                // EndRecurDate
        $properties["end_recur_time"] = "PT_LONG:PSETID_Meeting:0x10";                // EndRecurTime
        $properties["is_exception"] = "PT_BOOLEAN:PSETID_Meeting:0xA";                // LID_IS_EXCEPTION
        $properties["apptreplyname"] = "PT_STRING8:PSETID_Appointment:0x8230";
        // Propose new time properties
        $properties["proposed_start_whole"] = "PT_SYSTIME:PSETID_Appointment:0x8250";
        $properties["proposed_end_whole"] = "PT_SYSTIME:PSETID_Appointment:0x8251";
        $properties["proposed_duration"] = "PT_LONG:PSETID_Appointment:0x8256";
        $properties["counter_proposal"] = "PT_BOOLEAN:PSETID_Appointment:0x8257";
        $properties["recurring_pattern"] = "PT_STRING8:PSETID_Appointment:0x8232";
        $properties["basedate"] = "PT_SYSTIME:PSETID_Appointment:0x8228";
        $properties["meetingtype"] = "PT_LONG:PSETID_Meeting:0x26";
        $properties["timezone_data"] = "PT_BINARY:PSETID_Appointment:0x8233";
        $properties["timezone"] = "PT_STRING8:PSETID_Appointment:0x8234";
        $properties["toattendeesstring"] = "PT_STRING8:PSETID_Appointment:0x823B";
        $properties["ccattendeesstring"] = "PT_STRING8:PSETID_Appointment:0x823C";
        $this->proptags = getPropIdsFromStrings($store, $properties);
    }

    /**
     * Sets the direct booking property. This is an alternative to the setting of the direct booking
     * property through the constructor. However, setting it in the constructor is prefered.
     * @param Boolean $directBookingSetting
     *
     */
    function setDirectBooking($directBookingSetting)
    {
        $this->enableDirectBooking = $directBookingSetting;
    }

    /**
     * Returns TRUE if the message pointed to is an incoming meeting request and should
     * therefore be replied to with doAccept or doDecline()
     */
    function isMeetingRequest()
    {
        $props = mapi_getprops($this->message, Array(PR_MESSAGE_CLASS));

        if(isset($props[PR_MESSAGE_CLASS]) && $props[PR_MESSAGE_CLASS] == "IPM.Schedule.Meeting.Request")
            return true;
    }

    /**
     * Returns TRUE if the message pointed to is a returning meeting request response
     */
    function isMeetingRequestResponse()
    {
        $props = mapi_getprops($this->message, Array(PR_MESSAGE_CLASS));

        if(isset($props[PR_MESSAGE_CLASS]) && strpos($props[PR_MESSAGE_CLASS], "IPM.Schedule.Meeting.Resp") === 0)
            return true;
    }

    /**
     * Returns TRUE if the message pointed to is a cancellation request
     */
    function isMeetingCancellation()
    {
        $props = mapi_getprops($this->message, Array(PR_MESSAGE_CLASS));

        if(isset($props[PR_MESSAGE_CLASS]) && $props[PR_MESSAGE_CLASS] == "IPM.Schedule.Meeting.Canceled")
            return true;
    }


    /**
     * Process an incoming meeting request response as Delegate. This will updates the appointment
     * in Organiser's calendar.
     * @returns the entryids(storeid, parententryid, entryid, also basedate if response is occurrence)
     * of corresponding meeting in Calendar
     */
    function processMeetingRequestResponseAsDelegate()
    {
        if(!$this->isMeetingRequestResponse())
            return;

        $messageprops = mapi_getprops($this->message);

        $goid2 = $messageprops[$this->proptags['goid2']];

        if(!isset($goid2) || !isset($messageprops[PR_SENT_REPRESENTING_EMAIL_ADDRESS]))
            return;

        // Find basedate in GlobalID(0x3), this can be a response for an occurrence
        $basedate = $this->getBasedateFromGlobalID($messageprops[$this->proptags['goid']]);

        if (isset($messageprops[PR_RCVD_REPRESENTING_NAME])) {
            $delegatorStore = $this->getDelegatorStore($messageprops);
            $userStore = $delegatorStore['store'];
            $calFolder = $delegatorStore['calFolder'];

            if($calFolder){
                $calendaritems = $this->findCalendarItems($goid2, $calFolder);

                // $calendaritems now contains the ENTRYID's of all the calendar items to which
                // this meeting request points.

                // Open the calendar items, and update all the recipients of the calendar item that match
                // the email address of the response.
                if (!empty($calendaritems)) {
                     return $this->processResponse($userStore, $calendaritems[0], $basedate, $messageprops);
                }else{
                    return false;
                }
            }
        }
    }


    /**
     * Process an incoming meeting request response. This updates the appointment
     * in your calendar to show whether the user has accepted or declined.
     * @returns the entryids(storeid, parententryid, entryid, also basedate if response is occurrence)
     * of corresponding meeting in Calendar
     */
    function processMeetingRequestResponse()
    {
        if(!$this->isLocalOrganiser())
            return;

        if(!$this->isMeetingRequestResponse())
            return;

        // Get information we need from the response message
        $messageprops = mapi_getprops($this->message, Array(
                                                    $this->proptags['goid'],
                                                    $this->proptags['goid2'],
                                                    PR_OWNER_APPT_ID,
                                                    PR_SENT_REPRESENTING_EMAIL_ADDRESS,
                                                    PR_SENT_REPRESENTING_NAME,
                                                    PR_SENT_REPRESENTING_ADDRTYPE,
                                                    PR_SENT_REPRESENTING_ENTRYID,
                                                    PR_MESSAGE_DELIVERY_TIME,
                                                    PR_MESSAGE_CLASS,
                                                    PR_PROCESSED,
                                                    $this->proptags['proposed_start_whole'],
                                                    $this->proptags['proposed_end_whole'],
                                                    $this->proptags['proposed_duration'],
                                                    $this->proptags['counter_proposal'],
                                                    $this->proptags['attendee_critical_change']));

        $goid2 = $messageprops[$this->proptags['goid2']];

        if(!isset($goid2) || !isset($messageprops[PR_SENT_REPRESENTING_EMAIL_ADDRESS]))
            return;

        // Find basedate in GlobalID(0x3), this can be a response for an occurrence
        $basedate = $this->getBasedateFromGlobalID($messageprops[$this->proptags['goid']]);

        $calendaritems = $this->findCalendarItems($goid2);

        // $calendaritems now contains the ENTRYID's of all the calendar items to which
        // this meeting request points.

        // Open the calendar items, and update all the recipients of the calendar item that match
        // the email address of the response.
        if (!empty($calendaritems)) {
            return $this->processResponse($this->store, $calendaritems[0], $basedate, $messageprops);
        }else{
            return false;
        }
    }

    /**
     * Process every incoming MeetingRequest response.This updates the appointment
     * in your calendar to show whether the user has accepted or declined.
     *@param resource $store contains the userStore in which the meeting is created
     *@param $entryid contains the ENTRYID of the calendar items to which this meeting request points.
     *@param boolean $basedate if present the create an exception
     *@param array $messageprops contains m3/17/2010essage properties.
     *@return entryids(storeid, parententryid, entryid, also basedate if response is occurrence) of corresponding meeting in Calendar
     */
    function processResponse($store, $entryid, $basedate, $messageprops)
    {
        $data = array();
        $senderentryid = $messageprops[PR_SENT_REPRESENTING_ENTRYID];
        $messageclass = $messageprops[PR_MESSAGE_CLASS];
        $deliverytime = $messageprops[PR_MESSAGE_DELIVERY_TIME];

        // Open the calendar item, find the sender in the recipient table and update all the recipients of the calendar item that match
        // the email address of the response.
        $calendaritem = mapi_msgstore_openentry($store, $entryid);
        $calendaritemProps = mapi_getprops($calendaritem, array($this->proptags['recurring'], PR_STORE_ENTRYID, PR_PARENT_ENTRYID, PR_ENTRYID, $this->proptags['updatecounter']));

        $data["storeid"] = bin2hex($calendaritemProps[PR_STORE_ENTRYID]);
        $data["parententryid"] = bin2hex($calendaritemProps[PR_PARENT_ENTRYID]);
        $data["entryid"] = bin2hex($calendaritemProps[PR_ENTRYID]);
        $data["basedate"] = $basedate;
        $data["updatecounter"] = isset($calendaritemProps[$this->proptags['updatecounter']]) ? $calendaritemProps[$this->proptags['updatecounter']] : 0;

        /**
         * Check if meeting is updated or not in organizer's calendar
         */
        $data["meeting_updated"] = $this->isMeetingUpdated();

        if(isset($messageprops[PR_PROCESSED]) && $messageprops[PR_PROCESSED] == true) {
            // meeting is already processed
            return $data;
        } else {
            mapi_setprops($this->message, Array(PR_PROCESSED => true));
            mapi_savechanges($this->message);
        }

        // if meeting is updated in organizer's calendar then we don't need to process
        // old response
        if($data['meeting_updated'] === true) {
            return $data;
        }

        // If basedate is found, then create/modify exception msg and do processing
        if ($basedate && $calendaritemProps[$this->proptags['recurring']]) {
            $recurr = new Recurrence($store, $calendaritem);

            // Copy properties from meeting request
            $exception_props = mapi_getprops($this->message, array(PR_OWNER_APPT_ID,
                                                $this->proptags['proposed_start_whole'],
                                                $this->proptags['proposed_end_whole'],
                                                $this->proptags['proposed_duration'],
                                                $this->proptags['counter_proposal']
                                            ));

            // Create/modify exception
            if($recurr->isException($basedate)) {
                $recurr->modifyException($exception_props, $basedate);
            } else {
                // When we are creating an exception we need copy recipients from main recurring item
                $recipTable =  mapi_message_getrecipienttable($calendaritem);
                $recips = mapi_table_queryallrows($recipTable, $this->recipprops);

                // Retrieve actual start/due dates from calendar item.
                $exception_props[$this->proptags['startdate']] = $recurr->getOccurrenceStart($basedate);
                $exception_props[$this->proptags['duedate']] = $recurr->getOccurrenceEnd($basedate);

                $recurr->createException($exception_props, $basedate, false, $recips);
            }

            mapi_message_savechanges($calendaritem);

            $attach = $recurr->getExceptionAttachment($basedate);
            if ($attach) {
                $recurringItem = $calendaritem;
                $calendaritem = mapi_attach_openobj($attach, MAPI_MODIFY);
            } else {
                return false;
            }
        }

        // Get the recipients of the calendar item
        $reciptable = mapi_message_getrecipienttable($calendaritem);
        $recipients = mapi_table_queryallrows($reciptable, $this->recipprops);

        // FIXME we should look at the updatecounter property and compare it
        // to the counter in the recipient to see if this update is actually
        // newer than the status in the calendar item
        $found = false;

        $totalrecips = 0;
        $acceptedrecips = 0;
        foreach($recipients as $recipient) {
            $totalrecips++;
            if(isset($recipient[PR_ENTRYID]) && $this->compareABEntryIDs($recipient[PR_ENTRYID],$senderentryid)) {
                $found = true;

                /**
                 * If value of attendee_critical_change on meeting response mail is less than PR_RECIPIENT_TRACKSTATUS_TIME
                 * on the corresponding recipientRow of meeting then we ignore this response mail.
                 */
                if (isset($recipient[PR_RECIPIENT_TRACKSTATUS_TIME]) && ($messageprops[$this->proptags['attendee_critical_change']] < $recipient[PR_RECIPIENT_TRACKSTATUS_TIME])) {
                    continue;
                }

                // The email address matches, update the row
                $recipient[PR_RECIPIENT_TRACKSTATUS] = $this->getTrackStatus($messageclass);
                $recipient[PR_RECIPIENT_TRACKSTATUS_TIME] = $messageprops[$this->proptags['attendee_critical_change']];

                // If this is a counter proposal, set the proposal properties in the recipient row
                if(isset($messageprops[$this->proptags['counter_proposal']]) && $messageprops[$this->proptags['counter_proposal']]){
                    $recipient[PR_PROPOSENEWTIME_START] = $messageprops[$this->proptags['proposed_start_whole']];
                    $recipient[PR_PROPOSENEWTIME_END] = $messageprops[$this->proptags['proposed_end_whole']];
                    $recipient[PR_PROPOSEDNEWTIME] = $messageprops[$this->proptags['counter_proposal']];
                }

                mapi_message_modifyrecipients($calendaritem, MODRECIP_MODIFY, Array($recipient));
            }
            if(isset($recipient[PR_RECIPIENT_TRACKSTATUS]) && $recipient[PR_RECIPIENT_TRACKSTATUS] == olRecipientTrackStatusAccepted)
                $acceptedrecips++;
        }

        // If the recipient was not found in the original calendar item,
        // then add the recpient as a new optional recipient
        if(!$found) {
            $recipient = Array();
            $recipient[PR_ENTRYID] = $messageprops[PR_SENT_REPRESENTING_ENTRYID];
            $recipient[PR_EMAIL_ADDRESS] = $messageprops[PR_SENT_REPRESENTING_EMAIL_ADDRESS];
            $recipient[PR_DISPLAY_NAME] = $messageprops[PR_SENT_REPRESENTING_NAME];
            $recipient[PR_ADDRTYPE] = $messageprops[PR_SENT_REPRESENTING_ADDRTYPE];
            $recipient[PR_RECIPIENT_TYPE] = MAPI_CC;
            $recipient[PR_RECIPIENT_TRACKSTATUS] = $this->getTrackStatus($messageclass);
            $recipient[PR_RECIPIENT_TRACKSTATUS_TIME] = $deliverytime;

            // If this is a counter proposal, set the proposal properties in the recipient row
            if(isset($messageprops[$this->proptags['counter_proposal']])){
                $recipient[PR_PROPOSENEWTIME_START] = $messageprops[$this->proptags['proposed_start_whole']];
                $recipient[PR_PROPOSENEWTIME_END] = $messageprops[$this->proptags['proposed_end_whole']];
                $recipient[PR_PROPOSEDNEWTIME] = $messageprops[$this->proptags['counter_proposal']];
            }

            mapi_message_modifyrecipients($calendaritem, MODRECIP_ADD, Array($recipient));
            $totalrecips++;
            if($recipient[PR_RECIPIENT_TRACKSTATUS] == olRecipientTrackStatusAccepted)
                $acceptedrecips++;
        }

//TODO: Upate counter proposal number property on message
/*
If it is the first time this attendee has proposed a new date/time, increment the value of the PidLidAppointmentProposalNumber property on the organizer�s meeting object, by 0x00000001. If this property did not previously exist on the organizer�s meeting object, it MUST be set with a value of 0x00000001.
*/
        // If this is a counter proposal, set the counter proposal indicator boolean
        if(isset($messageprops[$this->proptags['counter_proposal']])){
            $props = Array();
            if($messageprops[$this->proptags['counter_proposal']]){
                $props[$this->proptags['counter_proposal']] = true;
            }else{
                $props[$this->proptags['counter_proposal']] = false;
            }

            mapi_message_setprops($calendaritem, $props);
        }

        mapi_message_savechanges($calendaritem);
        if (isset($attach)) {
            mapi_message_savechanges($attach);
            mapi_message_savechanges($recurringItem);
        }

        return $data;
    }


    /**
     * Process an incoming meeting request cancellation. This updates the
     * appointment in your calendar to show that the meeting has been cancelled.
     */
    function processMeetingCancellation()
    {
        if($this->isLocalOrganiser())
            return;

        if(!$this->isMeetingCancellation())
            return;

        if(!$this->isInCalendar())
            return;

        $listProperties = $this->proptags;
        $listProperties['subject'] = PR_SUBJECT;
        $listProperties['sent_representing_name'] = PR_SENT_REPRESENTING_NAME;
        $listProperties['sent_representing_address_type'] = PR_SENT_REPRESENTING_ADDRTYPE;
        $listProperties['sent_representing_email_address'] = PR_SENT_REPRESENTING_EMAIL_ADDRESS;
        $listProperties['sent_representing_entryid'] = PR_SENT_REPRESENTING_ENTRYID;
        $listProperties['sent_representing_search_key'] = PR_SENT_REPRESENTING_SEARCH_KEY;
        $listProperties['rcvd_representing_name'] = PR_RCVD_REPRESENTING_NAME;
        $messageprops = mapi_getprops($this->message, $listProperties);
        $store = $this->store;

        $goid = $messageprops[$this->proptags['goid']];    //GlobalID (0x3)
        if(!isset($goid))
            return;

        if (isset($messageprops[PR_RCVD_REPRESENTING_NAME])){
            $delegatorStore = $this->getDelegatorStore($messageprops);
            $store = $delegatorStore['store'];
            $calFolder = $delegatorStore['calFolder'];
        } else {
            $calFolder = $this->openDefaultCalendar();
        }

        // First, find the items in the calendar by GOID
        $calendaritems = $this->findCalendarItems($goid, $calFolder);
        $basedate = $this->getBasedateFromGlobalID($goid);

        if ($basedate) {
            // Calendaritems with GlobalID were not found, so find main recurring item using CleanGlobalID(0x23)
            if (empty($calendaritems)) {
                // This meeting req is of an occurrance
                $goid2 = $messageprops[$this->proptags['goid2']];

                // First, find the items in the calendar by GOID
                $calendaritems = $this->findCalendarItems($goid2);
                foreach($calendaritems as $entryid) {
                    // Open each calendar item and set the properties of the cancellation object
                    $calendaritem = mapi_msgstore_openentry($store, $entryid);

                    if ($calendaritem){
                        $calendaritemProps = mapi_getprops($calendaritem, array($this->proptags['recurring']));
                        if ($calendaritemProps[$this->proptags['recurring']]){
                            $recurr = new Recurrence($store, $calendaritem);

                            // Set message class
                            $messageprops[PR_MESSAGE_CLASS] = 'IPM.Appointment';

                            if($recurr->isException($basedate))
                                $recurr->modifyException($messageprops, $basedate);
                            else
                                $recurr->createException($messageprops, $basedate);
                        }
                        mapi_savechanges($calendaritem);
                    }
                }
            }
        }

        if (!isset($calendaritem)) {
            foreach($calendaritems as $entryid) {
                // Open each calendar item and set the properties of the cancellation object
                $calendaritem = mapi_msgstore_openentry($store, $entryid);
                mapi_message_setprops($calendaritem, $messageprops);
                mapi_savechanges($calendaritem);
            }
        }
    }

    /**
     * Returns true if the item is already in the calendar
     */
    function isInCalendar() {
        $messageprops = mapi_getprops($this->message, Array($this->proptags['goid'], $this->proptags['goid2'], PR_RCVD_REPRESENTING_NAME));
        $goid = $messageprops[$this->proptags['goid']];
        if (isset($messageprops[$this->proptags['goid2']]))
            $goid2 = $messageprops[$this->proptags['goid2']];

        $basedate = $this->getBasedateFromGlobalID($goid);

        if (isset($messageprops[PR_RCVD_REPRESENTING_NAME])){
            $delegatorStore = $this->getDelegatorStore($messageprops);
            $calFolder = $delegatorStore['calFolder'];
        } else {
            $calFolder = $this->openDefaultCalendar();
        }
        /**
         * If basedate is found in globalID, then there are two possibilities.
         * case 1) User has only this occurrence OR
         * case 2) User has recurring item and has received an update for an occurrence
         */
        if ($basedate) {
            // First try with GlobalID(0x3) (case 1)
            $entryid = $this->findCalendarItems($goid, $calFolder);
            // If not found then try with CleanGlobalID(0x23) (case 2)
            if (!is_array($entryid) && isset($goid2))
                $entryid = $this->findCalendarItems($goid2, $calFolder);
        } else if (isset($goid2)) {
            $entryid = $this->findCalendarItems($goid2, $calFolder);
        }
        else
            return false;

        return is_array($entryid);
    }

    /**
     * Accepts the meeting request by moving the item to the calendar
     * and sending a confirmation message back to the sender. If $tentative
     * is TRUE, then the item is accepted tentatively. After accepting, you
     * can't use this class instance any more. The message is closed. If you
     * specify TRUE for 'move', then the item is actually moved (from your
     * inbox probably) to the calendar. If you don't, it is copied into
     * your calendar.
     *@param boolean $tentative true if user as tentative accepted the meeting
     *@param boolean $sendresponse true if a response has to be send to organizer
     *@param boolean $move true if the meeting request should be moved to the deleted items after processing
     *@param string $newProposedStartTime contains starttime if user has proposed other time
     *@param string $newProposedEndTime contains endtime if user has proposed other time
     *@param string $basedate start of day of occurrence for which user has accepted the recurrent meeting
     *@return string $entryid entryid of item which created/updated in calendar
     */
    function doAccept($tentative, $sendresponse, $move, $newProposedStartTime=false, $newProposedEndTime=false, $body=false, $userAction = false, $store=false, $basedate = false)
    {
        if($this->isLocalOrganiser())
            return false;

        // Remove any previous calendar items with this goid and appt id
        $messageprops = mapi_getprops($this->message, Array(PR_ENTRYID, PR_MESSAGE_CLASS, $this->proptags['goid'], $this->proptags['goid2'], PR_OWNER_APPT_ID, $this->proptags['updatecounter'], PR_PROCESSED, $this->proptags['recurring'], $this->proptags['intendedbusystatus'], PR_RCVD_REPRESENTING_NAME));

        /**
         *    if this function is called automatically with meeting request object then there will be
         *    two possibilitites
         *    1) meeting request is opened first time, in this case make a tentative appointment in
                recipient's calendar
         *    2) after this every subsequest request to open meeting request will not do any processing
         */
        if($messageprops[PR_MESSAGE_CLASS] == "IPM.Schedule.Meeting.Request" && $userAction == false) {
            if(isset($messageprops[PR_PROCESSED]) && $messageprops[PR_PROCESSED] == true) {
                // if meeting request is already processed then don't do anything
                return false;
            } else {
                mapi_setprops($this->message, Array(PR_PROCESSED => true));
                mapi_message_savechanges($this->message);
            }
        }

        // If this meeting request is received by a delegate then open delegator's store.
        if (isset($messageprops[PR_RCVD_REPRESENTING_NAME])) {
            $delegatorStore = $this->getDelegatorStore($messageprops);

            $store = $delegatorStore['store'];
            $calFolder = $delegatorStore['calFolder'];
        } else {
            $calFolder = $this->openDefaultCalendar();
            $store = $this->store;
        }

        return $this->accept($tentative, $sendresponse, $move, $newProposedStartTime, $newProposedEndTime, $body, $userAction, $store, $calFolder, $basedate);
    }

    function accept($tentative, $sendresponse, $move, $newProposedStartTime=false, $newProposedEndTime=false, $body=false, $userAction = false, $store, $calFolder, $basedate = false)
    {
        $messageprops = mapi_getprops($this->message);
        $isDelegate = false;

        if (isset($messageprops[PR_DELEGATED_BY_RULE]))
            $isDelegate = true;

        $goid = $messageprops[$this->proptags['goid2']];

        // Retrieve basedate from globalID, if it is not recieved as argument
        if (!$basedate)
            $basedate = $this->getBasedateFromGlobalID($messageprops[$this->proptags['goid']]);

        if ($sendresponse)
            $this->createResponse($tentative ? olResponseTentative : olResponseAccepted, $newProposedStartTime, $newProposedEndTime, $body, $store, $basedate, $calFolder);

        $entryids = $this->findCalendarItems($goid, $calFolder);

        if(is_array($entryids)) {
            // Only check the first, there should only be one anyway...
            $previtem = mapi_msgstore_openentry($store, $entryids[0]);
            $prevcounterprops = mapi_getprops($previtem, array($this->proptags['updatecounter']));

            // Check if the existing item has an updatecounter that is lower than the request we are processing. If not, then we ignore this call, since the
            // meeting request is out of date.
            /*
                if(message_counter < appointment_counter) do_nothing
                if(message_counter == appointment_counter) do_something_if_the_user_tells_us (userAction == true)
                if(message_counter > appointment_counter) do_something_even_automatically
            */
            if(isset($prevcounterprops[$this->proptags['updatecounter']]) && $messageprops[$this->proptags['updatecounter']] < $prevcounterprops[$this->proptags['updatecounter']]) {
                return false;
            } else if(isset($prevcounterprops[$this->proptags['updatecounter']]) && $messageprops[$this->proptags['updatecounter']] == $prevcounterprops[$this->proptags['updatecounter']]) {
                if($userAction == false && !$basedate) {
                    return false;
                }
            }
        }

        // set counter proposal properties in calendar item when proposing new time
        // @FIXME this can be moved before call to createResponse function so that function doesn't need to recalculate duration
        $proposeNewTimeProps = array();
        if($newProposedStartTime && $newProposedEndTime) {
            $proposeNewTimeProps[$this->proptags['proposed_start_whole']] = $newProposedStartTime;
            $proposeNewTimeProps[$this->proptags['proposed_end_whole']] = $newProposedEndTime;
            $proposeNewTimeProps[$this->proptags['proposed_duration']] = round($newProposedEndTime - $newProposedStartTime) / 60;
            $proposeNewTimeProps[$this->proptags['counter_proposal']] = true;
        }

        /**
         * Further processing depends on what user is receiving. User can receive recurring item, a single occurrence or a normal meeting.
         * 1) If meeting req is of recurrence then we find all the occurrence in calendar because in past user might have recivied one or few occurrences.
         * 2) If single occurrence then find occurrence itself using globalID and if item is not found then user cleanGlobalID to find main recurring item
         * 3) Normal meeting req are handled normally has they were handled previously.
         *
         * Also user can respond(accept/decline) to item either from previewpane or from calendar by opening the item. If user is responding the meeting from previewpane
         * and that item is not found in calendar then item is move else item is opened and all properties, attachments and recipient are copied from meeting request.
         * If user is responding from calendar then item is opened and properties are set such as meetingstatus, responsestatus, busystatus etc.
         */
        if ($messageprops[PR_MESSAGE_CLASS] == "IPM.Schedule.Meeting.Request") {
            // While processing the item mark it as read.
            mapi_message_setreadflag($this->message, SUPPRESS_RECEIPT);

            // This meeting request item is recurring, so find all occurrences and saves them all as exceptions to this meeting request item.
            if ($messageprops[$this->proptags['recurring']] == true) {
                $calendarItem = false;

                // Find main recurring item based on GlobalID (0x3)
                $items = $this->findCalendarItems($messageprops[$this->proptags['goid2']], $calFolder);
                if (is_array($items)) {
                    foreach($items as $key => $entryid)
                        $calendarItem = mapi_msgstore_openentry($store, $entryid);
                }

                // Recurring item not found, so create new meeting in Calendar
                if (!$calendarItem)
                    $calendarItem = mapi_folder_createmessage($calFolder);

                // Copy properties
                $props = mapi_getprops($this->message);
                $props[PR_MESSAGE_CLASS] = 'IPM.Appointment';
                $props[$this->proptags['meetingstatus']] = olMeetingReceived;
                // when we are automatically processing the meeting request set responsestatus to olResponseNotResponded
                $props[$this->proptags['responsestatus']] = $userAction ? ($tentative ? olResponseTentative : olResponseAccepted) : olResponseNotResponded;

                if (isset($props[$this->proptags['intendedbusystatus']])) {
                    if($tentative && $props[$this->proptags['intendedbusystatus']] !== fbFree) {
                        $props[$this->proptags['busystatus']] = $tentative;
                    } else {
                        $props[$this->proptags['busystatus']] = $props[$this->proptags['intendedbusystatus']];
                    }
                    // we already have intendedbusystatus value in $props so no need to copy it
                } else {
                    $props[$this->proptags['busystatus']] = $tentative ? fbTentative : fbBusy;
                }

                if($userAction) {
                    // if user has responded then set replytime
                    $props[$this->proptags['replytime']] = time();
                }

                mapi_setprops($calendarItem, $props);

                // Copy attachments too
                $this->replaceAttachments($this->message, $calendarItem);
                // Copy recipients too
                $this->replaceRecipients($this->message, $calendarItem, $isDelegate);

                // Find all occurrences based on CleanGlobalID (0x23)
                $items = $this->findCalendarItems($messageprops[$this->proptags['goid2']], $calFolder, true);
                if (is_array($items)) {
                    // Save all existing occurrence as exceptions
                    foreach($items as $entryid) {
                        // Open occurrence
                        $occurrenceItem = mapi_msgstore_openentry($store, $entryid);

                        // Save occurrence into main recurring item as exception
                        if ($occurrenceItem) {
                            $occurrenceItemProps = mapi_getprops($occurrenceItem, array($this->proptags['goid'], $this->proptags['recurring']));

                            // Find basedate of occurrence item
                            $basedate = $this->getBasedateFromGlobalID($occurrenceItemProps[$this->proptags['goid']]);
                            if ($basedate && $occurrenceItemProps[$this->proptags['recurring']] != true)
                                $this->acceptException($calendarItem, $occurrenceItem, $basedate, true, $tentative, $userAction, $store, $isDelegate);
                        }
                    }
                }
                mapi_savechanges($calendarItem);
                if ($move) {
                    $wastebasket = $this->openDefaultWastebasket();
                    mapi_folder_copymessages($calFolder, Array($props[PR_ENTRYID]), $wastebasket, MESSAGE_MOVE);
                }
                $entryid = $props[PR_ENTRYID];
            } else {
                /**
                 * This meeting request is not recurring, so can be an exception or normal meeting.
                 * If exception then find main recurring item and update exception
                 * If main recurring item is not found then put exception into Calendar as normal meeting.
                 */
                $calendarItem = false;

                // We found basedate in GlobalID of this meeting request, so this meeting request if for an occurrence.
                if ($basedate) {
                    // Find main recurring item from CleanGlobalID of this meeting request
                    $items = $this->findCalendarItems($messageprops[$this->proptags['goid2']], $calFolder);
                    if (is_array($items)) {
                        foreach($items as $key => $entryid) {
                            $calendarItem = mapi_msgstore_openentry($store, $entryid);
                        }
                    }

                    // Main recurring item is found, so now update exception
                    if ($calendarItem) {
                        $this->acceptException($calendarItem, $this->message, $basedate, $move, $tentative, $userAction, $store, $isDelegate);
                        $calendarItemProps = mapi_getprops($calendarItem, array(PR_ENTRYID));
                        $entryid = $calendarItemProps[PR_ENTRYID];
                    }
                }

                if (!$calendarItem) {
                    $items = $this->findCalendarItems($messageprops[$this->proptags['goid']], $calFolder);

                    if (is_array($items))
                        mapi_folder_deletemessages($calFolder, $items);

                    if ($move) {
                        // All we have to do is open the default calendar,
                        // set the mesage class correctly to be an appointment item
                        // and move it to the calendar folder
                        $sourcefolder = $this->openParentFolder();

                        /* create a new calendar message, and copy the message to there,
                           since we want to delete (move to wastebasket) the original message */
                        $old_entryid = mapi_getprops($this->message, Array(PR_ENTRYID));
                        $calmsg = mapi_folder_createmessage($calFolder);
                        mapi_copyto($this->message, array(), array(), $calmsg); /* includes attachments and recipients */
                        /* release old message */
                        $message = null;

                        $calItemProps = Array();
                        $calItemProps[PR_MESSAGE_CLASS] = "IPM.Appointment";

                        if (isset($messageprops[$this->proptags['intendedbusystatus']])) {
                            if($tentative && $messageprops[$this->proptags['intendedbusystatus']] !== fbFree) {
                                $calItemProps[$this->proptags['busystatus']] = $tentative;
                            } else {
                                $calItemProps[$this->proptags['busystatus']] = $messageprops[$this->proptags['intendedbusystatus']];
                            }
                            $calItemProps[$this->proptags['intendedbusystatus']] = $messageprops[$this->proptags['intendedbusystatus']];
                        } else {
                            $calItemProps[$this->proptags['busystatus']] = $tentative ? fbTentative : fbBusy;
                        }

                        // when we are automatically processing the meeting request set responsestatus to olResponseNotResponded
                        $calItemProps[$this->proptags['responsestatus']] = $userAction ? ($tentative ? olResponseTentative : olResponseAccepted) : olResponseNotResponded;
                        if($userAction) {
                            // if user has responded then set replytime
                            $calItemProps[$this->proptags['replytime']] = time();
                        }

                        mapi_setprops($calmsg, $proposeNewTimeProps + $calItemProps);

                        // get properties which stores owner information in meeting request mails
                        $props = mapi_getprops($calmsg, array(PR_SENT_REPRESENTING_ENTRYID, PR_SENT_REPRESENTING_NAME, PR_SENT_REPRESENTING_EMAIL_ADDRESS, PR_SENT_REPRESENTING_ADDRTYPE));

                        // add owner to recipient table
                        $recips = array();
                        $this->addOrganizer($props, $recips);

                        if($isDelegate) {
                            /**
                             * If user is delegate then remove that user from recipienttable of the MR.
                             * and delegate MR mail doesn't contain any of the attendees in recipient table.
                             * So, other required and optional attendees are added from
                             * toattendeesstring and ccattendeesstring properties.
                             */
                            $this->setRecipsFromString($recips, $messageprops[$this->proptags['toattendeesstring']], MAPI_TO);
                            $this->setRecipsFromString($recips, $messageprops[$this->proptags['ccattendeesstring']], MAPI_CC);
                            mapi_message_modifyrecipients($calmsg, 0, $recips);
                        } else {
                            mapi_message_modifyrecipients($calmsg, MODRECIP_ADD, $recips);
                        }

                        mapi_message_savechanges($calmsg);

                        // Move the message to the wastebasket
                        $wastebasket = $this->openDefaultWastebasket();
                        mapi_folder_copymessages($sourcefolder, array($old_entryid[PR_ENTRYID]), $wastebasket, MESSAGE_MOVE);

                        $messageprops = mapi_getprops($calmsg, array(PR_ENTRYID));
                        $entryid = $messageprops[PR_ENTRYID];
                    } else {
                        // Create a new appointment with duplicate properties and recipient, but as an IPM.Appointment
                        $new = mapi_folder_createmessage($calFolder);
                        $props = mapi_getprops($this->message);

                        $props[PR_MESSAGE_CLASS] = "IPM.Appointment";
                        // when we are automatically processing the meeting request set responsestatus to olResponseNotResponded
                        $props[$this->proptags['responsestatus']] = $userAction ? ($tentative ? olResponseTentative : olResponseAccepted) : olResponseNotResponded;

                        if (isset($props[$this->proptags['intendedbusystatus']])) {
                            if($tentative && $props[$this->proptags['intendedbusystatus']] !== fbFree) {
                                $props[$this->proptags['busystatus']] = $tentative;
                            } else {
                                $props[$this->proptags['busystatus']] = $props[$this->proptags['intendedbusystatus']];
                            }
                            // we already have intendedbusystatus value in $props so no need to copy it
                        } else {
                            $props[$this->proptags['busystatus']] = $tentative ? fbTentative : fbBusy;
                        }

                        // ZP-341 - we need to copy as well the attachments
                        // Copy attachments too
                        $this->replaceAttachments($this->message, $new);
                        // ZP-341 - end

                        if($userAction) {
                            // if user has responded then set replytime
                            $props[$this->proptags['replytime']] = time();
                        }

                        mapi_setprops($new, $proposeNewTimeProps + $props);

                        $reciptable = mapi_message_getrecipienttable($this->message);

                        $recips = array();
                        if(!$isDelegate)
                            $recips = mapi_table_queryallrows($reciptable, $this->recipprops);

                        $this->addOrganizer($props, $recips);

                        if($isDelegate) {
                            /**
                             * If user is delegate then remove that user from recipienttable of the MR.
                             * and delegate MR mail doesn't contain any of the attendees in recipient table.
                             * So, other required and optional attendees are added from
                             * toattendeesstring and ccattendeesstring properties.
                             */
                            $this->setRecipsFromString($recips, $messageprops[$this->proptags['toattendeesstring']], MAPI_TO);
                            $this->setRecipsFromString($recips, $messageprops[$this->proptags['ccattendeesstring']], MAPI_CC);
                            mapi_message_modifyrecipients($new, 0, $recips);
                        } else {
                            mapi_message_modifyrecipients($new, MODRECIP_ADD, $recips);
                        }
                        mapi_message_savechanges($new);

                        $props = mapi_getprops($new, array(PR_ENTRYID));
                        $entryid = $props[PR_ENTRYID];
                    }
                }
            }
        } else {
            // Here only properties are set on calendaritem, because user is responding from calendar.
            $props = array();
            $props[$this->proptags['responsestatus']] = $tentative ? olResponseTentative : olResponseAccepted;

            if (isset($messageprops[$this->proptags['intendedbusystatus']])) {
                if($tentative && $messageprops[$this->proptags['intendedbusystatus']] !== fbFree) {
                    $props[$this->proptags['busystatus']] = $tentative;
                } else {
                    $props[$this->proptags['busystatus']] = $messageprops[$this->proptags['intendedbusystatus']];
                }
                $props[$this->proptags['intendedbusystatus']] = $messageprops[$this->proptags['intendedbusystatus']];
            } else {
                $props[$this->proptags['busystatus']] = $tentative ? fbTentative : fbBusy;
            }

            $props[$this->proptags['meetingstatus']] = olMeetingReceived;
            $props[$this->proptags['replytime']] = time();

            if ($basedate) {
                $recurr = new Recurrence($store, $this->message);

                // Copy recipients list
                $reciptable = mapi_message_getrecipienttable($this->message);
                $recips = mapi_table_queryallrows($reciptable, $this->recipprops);

                if($recurr->isException($basedate)) {
                    $recurr->modifyException($proposeNewTimeProps + $props, $basedate, $recips);
                } else {
                    $props[$this->proptags['startdate']] = $recurr->getOccurrenceStart($basedate);
                    $props[$this->proptags['duedate']] = $recurr->getOccurrenceEnd($basedate);

                    $props[PR_SENT_REPRESENTING_EMAIL_ADDRESS] = $messageprops[PR_SENT_REPRESENTING_EMAIL_ADDRESS];
                    $props[PR_SENT_REPRESENTING_NAME] = $messageprops[PR_SENT_REPRESENTING_NAME];
                    $props[PR_SENT_REPRESENTING_ADDRTYPE] = $messageprops[PR_SENT_REPRESENTING_ADDRTYPE];
                    $props[PR_SENT_REPRESENTING_ENTRYID] = $messageprops[PR_SENT_REPRESENTING_ENTRYID];

                    $recurr->createException($proposeNewTimeProps + $props, $basedate, false, $recips);
                }
            } else {
                mapi_setprops($this->message, $proposeNewTimeProps + $props);
            }
            mapi_savechanges($this->message);

            $entryid = $messageprops[PR_ENTRYID];
        }

        return $entryid;
    }

    /**
     * Declines the meeting request by moving the item to the deleted
     * items folder and sending a decline message. After declining, you
     * can't use this class instance any more. The message is closed.
     * When an occurrence is decline then false is returned because that
     * occurrence is deleted not the recurring item.
     *
     *@param boolean $sendresponse true if a response has to be sent to organizer
     *@param resource $store MAPI_store of user
     *@param string $basedate if specified contains starttime of day of an occurrence
     *@return boolean true if item is deleted from Calendar else false
     */
    function doDecline($sendresponse, $store=false, $basedate = false, $body = false)
    {
        $result = true;
        $calendaritem = false;
        if($this->isLocalOrganiser())
            return;

        // Remove any previous calendar items with this goid and appt id
        $messageprops = mapi_getprops($this->message, Array($this->proptags['goid'], $this->proptags['goid2'], PR_RCVD_REPRESENTING_NAME));

        // If this meeting request is received by a delegate then open delegator's store.
        if (isset($messageprops[PR_RCVD_REPRESENTING_NAME])) {
            $delegatorStore = $this->getDelegatorStore($messageprops);

            $store = $delegatorStore['store'];
            $calFolder = $delegatorStore['calFolder'];
        } else {
            $calFolder = $this->openDefaultCalendar();
            $store = $this->store;
        }

        $goid = $messageprops[$this->proptags['goid']];

        // First, find the items in the calendar by GlobalObjid (0x3)
        $entryids = $this->findCalendarItems($goid, $calFolder);

        if (!$basedate)
            $basedate = $this->getBasedateFromGlobalID($goid);

        if($sendresponse)
            $this->createResponse(olResponseDeclined, false, false, $body, $store, $basedate, $calFolder);

        if ($basedate) {
            // use CleanGlobalObjid (0x23)
            $calendaritems = $this->findCalendarItems($messageprops[$this->proptags['goid2']], $calFolder);

            foreach($calendaritems as $entryid) {
                // Open each calendar item and set the properties of the cancellation object
                $calendaritem = mapi_msgstore_openentry($store, $entryid);

                // Recurring item is found, now delete exception
                if ($calendaritem)
                    $this->doRemoveExceptionFromCalendar($basedate, $calendaritem, $store);
            }

            if ($this->isMeetingRequest())
                $calendaritem = false;
            else
                $result = false;
        }

        if (!$calendaritem) {
            $calendar = $this->openDefaultCalendar();

            if(!empty($entryids)) {
                mapi_folder_deletemessages($calendar, $entryids);
            }

            // All we have to do to decline, is to move the item to the waste basket
            $wastebasket = $this->openDefaultWastebasket();
            $sourcefolder = $this->openParentFolder();

            $messageprops = mapi_getprops($this->message, Array(PR_ENTRYID));

            // Release the message
            $this->message = null;

            // Move the message to the waste basket
            mapi_folder_copymessages($sourcefolder, Array($messageprops[PR_ENTRYID]), $wastebasket, MESSAGE_MOVE);
        }
        return $result;
    }

    /**
     * Removes a meeting request from the calendar when the user presses the
     * 'remove from calendar' button in response to a meeting cancellation.
     * @param string $basedate if specified contains starttime of day of an occurrence
     */
    function doRemoveFromCalendar($basedate)
    {
        if($this->isLocalOrganiser())
            return false;

        $store = $this->store;
        $messageprops = mapi_getprops($this->message, Array(PR_ENTRYID, $this->proptags['goid'], PR_RCVD_REPRESENTING_NAME, PR_MESSAGE_CLASS));
        $goid = $messageprops[$this->proptags['goid']];

        if (isset($messageprops[PR_RCVD_REPRESENTING_NAME])) {
            $delegatorStore = $this->getDelegatorStore($messageprops);
            $store = $delegatorStore['store'];
            $calFolder = $delegatorStore['calFolder'];
        } else {
            $calFolder = $this->openDefaultCalendar();
        }

        $wastebasket = $this->openDefaultWastebasket();
        $sourcefolder = $this->openParentFolder();

        // Check if the message is a meeting request in the inbox or a calendaritem by checking the message class
        if (strpos($messageprops[PR_MESSAGE_CLASS], 'IPM.Schedule.Meeting') === 0) {
            /**
             * 'Remove from calendar' option from previewpane then we have to check GlobalID of this meeting request.
             * If basedate found then open meeting from calendar and delete that occurence.
             */
            $basedate = false;
            if ($goid) {
                // Retrieve GlobalID and find basedate in it.
                $basedate = $this->getBasedateFromGlobalID($goid);

                // Basedate found, Now find item.
                if ($basedate) {
                    $guid = $this->setBasedateInGlobalID($goid);

                    // First, find the items in the calendar by GOID
                    $calendaritems = $this->findCalendarItems($guid, $calFolder);
                    if(is_array($calendaritems)) {
                        foreach($calendaritems as $entryid) {
                            // Open each calendar item and set the properties of the cancellation object
                            $calendaritem = mapi_msgstore_openentry($store, $entryid);

                            if ($calendaritem){
                                $this->doRemoveExceptionFromCalendar($basedate, $calendaritem, $store);
                            }
                        }
                    }
                }
            }

            // It is normal/recurring meeting item.
            if (!$basedate) {
                if (!isset($calFolder)) $calFolder = $this->openDefaultCalendar();

                $entryids = $this->findCalendarItems($goid, $calFolder);

                if(is_array($entryids)){
                    // Move the calendaritem to the waste basket
                    mapi_folder_copymessages($sourcefolder, $entryids, $wastebasket, MESSAGE_MOVE);
                }
            }

            // Release the message
            $this->message = null;

            // Move the message to the waste basket
            mapi_folder_copymessages($sourcefolder, Array($messageprops[PR_ENTRYID]), $wastebasket, MESSAGE_MOVE);

        } else {
            // Here only properties are set on calendaritem, because user is responding from calendar.
            if ($basedate) { //remove the occurence
                $this->doRemoveExceptionFromCalendar($basedate, $this->message, $store);
            } else { //remove normal/recurring meeting item.
                // Move the message to the waste basket
                mapi_folder_copymessages($sourcefolder, Array($messageprops[PR_ENTRYID]), $wastebasket, MESSAGE_MOVE);
            }
        }
    }

    /**
     * Removes the meeting request by moving the item to the deleted
     * items folder. After canceling, youcan't use this class instance
     * any more. The message is closed.
     */
    function doCancel()
    {
        if($this->isLocalOrganiser())
            return;
        if(!$this->isMeetingCancellation())
            return;

        // Remove any previous calendar items with this goid and appt id
        $messageprops = mapi_getprops($this->message, Array($this->proptags['goid']));
        $goid = $messageprops[$this->proptags['goid']];

        $entryids = $this->findCalendarItems($goid);
        $calendar = $this->openDefaultCalendar();

        mapi_folder_deletemessages($calendar, $entryids);

        // All we have to do to decline, is to move the item to the waste basket

        $wastebasket = $this->openDefaultWastebasket();
        $sourcefolder = $this->openParentFolder();

        $messageprops = mapi_getprops($this->message, Array(PR_ENTRYID));

        // Release the message
        $this->message = null;

        // Move the message to the waste basket
        mapi_folder_copymessages($sourcefolder, Array($messageprops[PR_ENTRYID]), $wastebasket, MESSAGE_MOVE);
    }


    /**
     * Sets the properties in the message so that is can be sent
     * as a meeting request. The caller has to submit the message. This
     * is only used for new MeetingRequests. Pass the appointment item as $message
     * in the constructor to do this.
     */
    function setMeetingRequest($basedate = false)
    {
        $props = mapi_getprops($this->message, Array($this->proptags['updatecounter']));

        // Create a new global id for this item
        $goid = pack("H*", "040000008200E00074C5B7101A82E00800000000");
        for ($i=0; $i<36; $i++)
            $goid .= chr(rand(0, 255));

        // Create a new appointment id for this item
        $apptid = rand();

        $props[PR_OWNER_APPT_ID] = $apptid;
        $props[PR_ICON_INDEX] = 1026;
        $props[$this->proptags['goid']] = $goid;
        $props[$this->proptags['goid2']] = $goid;

        if (!isset($props[$this->proptags['updatecounter']])) {
            $props[$this->proptags['updatecounter']] = 0;            // OL also starts sequence no with zero.
            $props[$this->proptags['last_updatecounter']] = 0;
        }

        mapi_setprops($this->message, $props);
    }

    /**
     * Sends a meeting request by copying it to the outbox, converting
     * the message class, adding some properties that are required only
     * for sending the message and submitting the message. Set cancel to
     * true if you wish to completely cancel the meeting request. You can
     * specify an optional 'prefix' to prefix the sent message, which is normally
     * 'Canceled: '
     */
    function sendMeetingRequest($cancel, $prefix = false, $basedate = false, $deletedRecips = false)
    {
        $this->includesResources = false;
        $this->nonAcceptingResources = Array();

        // Get the properties of the message
        $messageprops = mapi_getprops($this->message, Array($this->proptags['recurring']));

        /*****************************************************************************************
         * Submit message to non-resource recipients
         */
        // Set BusyStatus to olTentative (1)
        // Set MeetingStatus to olMeetingReceived
        // Set ResponseStatus to olResponseNotResponded

        /**
         * While sending recurrence meeting exceptions are not send as attachments
         * because first all exceptions are send and then recurrence meeting is sent.
         */
        if (isset($messageprops[$this->proptags['recurring']]) && $messageprops[$this->proptags['recurring']] && !$basedate) {
            // Book resource
            $resourceRecipData = $this->bookResources($this->message, $cancel, $prefix);

            if (!$this->errorSetResource) {
                $recurr = new Recurrence($this->openDefaultStore(), $this->message);

                // First send meetingrequest for recurring item
                $this->submitMeetingRequest($this->message, $cancel, $prefix, false, $recurr, false, $deletedRecips);

                // Then send all meeting request for all exceptions
                $exceptions = $recurr->getAllExceptions();
                if ($exceptions) {
                    foreach($exceptions as $exceptionBasedate) {
                        $attach = $recurr->getExceptionAttachment($exceptionBasedate);

                        if ($attach) {
                            $occurrenceItem = mapi_attach_openobj($attach, MAPI_MODIFY);
                            $this->submitMeetingRequest($occurrenceItem, $cancel, false, $exceptionBasedate, $recurr, false, $deletedRecips);
                            mapi_savechanges($attach);
                        }
                    }
                }
            }
        } else {
            // Basedate found, an exception is to be send
            if ($basedate) {
                $recurr = new Recurrence($this->openDefaultStore(), $this->message);

                if ($cancel) {
                    //@TODO: remove occurrence from Resource's Calendar if resource was booked for whole series
                    $this->submitMeetingRequest($this->message, $cancel, $prefix, $basedate, $recurr, false);
                } else {
                    $attach = $recurr->getExceptionAttachment($basedate);

                    if ($attach) {
                        $occurrenceItem = mapi_attach_openobj($attach, MAPI_MODIFY);

                        // Book resource for this occurrence
                        $resourceRecipData = $this->bookResources($occurrenceItem, $cancel, $prefix, $basedate);

                        if (!$this->errorSetResource) {
                            // Save all previous changes
                            mapi_savechanges($this->message);

                            $this->submitMeetingRequest($occurrenceItem, $cancel, $prefix, $basedate, $recurr, true, $deletedRecips);
                            mapi_savechanges($occurrenceItem);
                            mapi_savechanges($attach);
                        }
                    }
                }
            } else {
                // This is normal meeting
                $resourceRecipData = $this->bookResources($this->message, $cancel, $prefix);

                if (!$this->errorSetResource) {
                    $this->submitMeetingRequest($this->message, $cancel, $prefix, false, false, false, $deletedRecips);
                }
            }
        }

        if(isset($this->errorSetResource) && $this->errorSetResource){
            return Array(
                'error' => $this->errorSetResource,
                'displayname' => $this->recipientDisplayname
            );
        }else{
            return true;
        }
    }


    function getFreeBusyInfo($entryID,$start,$end)
    {
        $result = array();
        $fbsupport = mapi_freebusysupport_open($this->session);

        if(mapi_last_hresult() != NOERROR) {
            if(function_exists("dump")) {
                dump("Error in opening freebusysupport object.");
            }
            return $result;
        }

        $fbDataArray = mapi_freebusysupport_loaddata($fbsupport, array($entryID));

        if($fbDataArray[0] != NULL){
            foreach($fbDataArray as $fbDataUser){
                $rangeuser1 = mapi_freebusydata_getpublishrange($fbDataUser);
                if($rangeuser1 == NULL){
                    return $result;
                }

                $enumblock = mapi_freebusydata_enumblocks($fbDataUser, $start, $end);
                mapi_freebusyenumblock_reset($enumblock);

                while(true){
                    $blocks = mapi_freebusyenumblock_next($enumblock, 100);
                    if(!$blocks){
                        break;
                    }
                    foreach($blocks as $blockItem){
                        $result[] = $blockItem;
                    }
                }
            }
        }

        mapi_freebusysupport_close($fbsupport);
        return $result;
    }

    /**
     * Updates the message after an update has been performed (for example,
     * changing the time of the meeting). This must be called before re-sending
     * the meeting request. You can also call this function instead of 'setMeetingRequest()'
     * as it will automatically call setMeetingRequest on this object if it is the first
     * call to this function.
     */
    function updateMeetingRequest($basedate = false)
    {
        $messageprops = mapi_getprops($this->message, Array($this->proptags['last_updatecounter'], $this->proptags['goid']));

        if(!isset($messageprops[$this->proptags['last_updatecounter']]) || !isset($messageprops[$this->proptags['goid']])) {
            $this->setMeetingRequest($basedate);
        } else {
            $counter = $messageprops[$this->proptags['last_updatecounter']] + 1;

            // increment value of last_updatecounter, last_updatecounter will be common for recurring series
            // so even if you sending an exception only you need to update the last_updatecounter in the recurring series message
            // this way we can make sure that everytime we will be using a uniwue number for every operation
            mapi_setprops($this->message, Array($this->proptags['last_updatecounter'] => $counter));
        }
    }

    /**
     * Returns TRUE if we are the organiser of the meeting.
     */
    function isLocalOrganiser()
    {
        if($this->isMeetingRequest() || $this->isMeetingRequestResponse()) {
            $messageid = $this->getAppointmentEntryID();

            if(!isset($messageid))
                return false;

            $message = mapi_msgstore_openentry($this->store, $messageid);

            $messageprops = mapi_getprops($this->message, Array($this->proptags['goid']));
            $basedate = $this->getBasedateFromGlobalID($messageprops[$this->proptags['goid']]);
            if ($basedate) {
                $recurr = new Recurrence($this->store, $message);
                $attach = $recurr->getExceptionAttachment($basedate);
                if ($attach) {
                    $occurItem = mapi_attach_openobj($attach);
                    $occurItemProps = mapi_getprops($occurItem, Array($this->proptags['responsestatus']));
                }
            }

            $messageprops = mapi_getprops($message, Array($this->proptags['responsestatus']));
        }

        /**
         * User can send recurring meeting or any occurrences from a recurring appointment so
         * to be organizer 'responseStatus' property should be 'olResponseOrganized' on either
         * of the recurring item or occurrence item.
         */
        if ((isset($messageprops[$this->proptags['responsestatus']]) && $messageprops[$this->proptags['responsestatus']] == olResponseOrganized)
            || (isset($occurItemProps[$this->proptags['responsestatus']]) && $occurItemProps[$this->proptags['responsestatus']] == olResponseOrganized))
            return true;
        else
            return false;
    }

    /**
     * Returns the entryid of the appointment that this message points at. This is
     * only used on messages that are not in the calendar.
     */
    function getAppointmentEntryID()
    {
        $messageprops = mapi_getprops($this->message, Array($this->proptags['goid2']));

        $goid2 = $messageprops[$this->proptags['goid2']];

        $items = $this->findCalendarItems($goid2);

        if(empty($items))
            return;

        // There should be just one item. If there are more, we just take the first one
        return $items[0];
    }

    /***************************************************************************************************
     * Support functions - INTERNAL ONLY
     ***************************************************************************************************
     */

    /**
     * Return the tracking status of a recipient based on the IPM class (passed)
     */
    function getTrackStatus($class) {
        $status = olRecipientTrackStatusNone;
        switch($class)
        {
            case "IPM.Schedule.Meeting.Resp.Pos":
                $status = olRecipientTrackStatusAccepted;
                break;

            case "IPM.Schedule.Meeting.Resp.Tent":
                $status = olRecipientTrackStatusTentative;
                break;

            case "IPM.Schedule.Meeting.Resp.Neg":
                $status = olRecipientTrackStatusDeclined;
                break;
        }
        return $status;
    }

    function openParentFolder() {
        $messageprops = mapi_getprops($this->message, Array(PR_PARENT_ENTRYID));

        $parentfolder = mapi_msgstore_openentry($this->store, $messageprops[PR_PARENT_ENTRYID]);
        return $parentfolder;
    }

    function openDefaultCalendar() {
        return $this->openDefaultFolder(PR_IPM_APPOINTMENT_ENTRYID);
    }

    function openDefaultOutbox($store=false) {
        return $this->openBaseFolder(PR_IPM_OUTBOX_ENTRYID, $store);
    }

    function openDefaultWastebasket() {
        return $this->openBaseFolder(PR_IPM_WASTEBASKET_ENTRYID);
    }

    function getDefaultWastebasketEntryID() {
        return $this->getBaseEntryID(PR_IPM_WASTEBASKET_ENTRYID);
    }

    function getDefaultSentmailEntryID($store=false) {
        return $this->getBaseEntryID(PR_IPM_SENTMAIL_ENTRYID, $store);
    }

    function getDefaultFolderEntryID($prop) {
        try {
            $inbox = mapi_msgstore_getreceivefolder($this->store);
        } catch (MAPIException $e) {
            // public store doesn't support this method
            if($e->getCode() == MAPI_E_NO_SUPPORT) {
                // don't propogate this error to parent handlers, if store doesn't support it
                $e->setHandled();
                return;
            }
        }

        $inboxprops = mapi_getprops($inbox, Array($prop));
        if(!isset($inboxprops[$prop]))
            return;

        return $inboxprops[$prop];
    }

    function openDefaultFolder($prop) {
        $entryid = $this->getDefaultFolderEntryID($prop);
        $folder = mapi_msgstore_openentry($this->store, $entryid);

        return $folder;
    }

    function getBaseEntryID($prop, $store=false) {
        $storeprops = mapi_getprops( (($store)?$store:$this->store) , Array($prop));
        if(!isset($storeprops[$prop]))
            return;

        return $storeprops[$prop];
    }

    function openBaseFolder($prop, $store=false) {
        $entryid = $this->getBaseEntryID($prop, $store);
        $folder = mapi_msgstore_openentry( (($store)?$store:$this->store) , $entryid);

        return $folder;
    }
    /**
     * Function which sends response to organizer when attendee accepts, declines or proposes new time to a received meeting request.
     *@param integer $status response status of attendee
     *@param integer $proposalStartTime proposed starttime by attendee
     *@param integer $proposalEndTime proposed endtime by attendee
     *@param integer $basedate date of occurrence which attendee has responded
     */
    function createResponse($status, $proposalStartTime=false, $proposalEndTime=false, $body=false, $store, $basedate = false, $calFolder) {
        $messageprops = mapi_getprops($this->message, Array(PR_SENT_REPRESENTING_ENTRYID,
                                                            PR_SENT_REPRESENTING_EMAIL_ADDRESS,
                                                            PR_SENT_REPRESENTING_ADDRTYPE,
                                                            PR_SENT_REPRESENTING_NAME,
                                                            $this->proptags['goid'],
                                                            $this->proptags['goid2'],
                                                            $this->proptags['location'],
                                                            $this->proptags['startdate'],
                                                            $this->proptags['duedate'],
                                                            $this->proptags['recurring'],
                                                            $this->proptags['recurring_pattern'],
                                                            $this->proptags['recurrence_data'],
                                                            $this->proptags['timezone_data'],
                                                            $this->proptags['timezone'],
                                                            $this->proptags['updatecounter'],
                                                            PR_SUBJECT,
                                                            PR_MESSAGE_CLASS,
                                                            PR_OWNER_APPT_ID,
                                                            $this->proptags['is_exception']
                                    ));

        if ($basedate && $messageprops[PR_MESSAGE_CLASS] != "IPM.Schedule.Meeting.Request" ){
            // we are creating response from a recurring calendar item object
            // We found basedate,so opened occurrence and get properties.
            $recurr = new Recurrence($store, $this->message);
            $exception = $recurr->getExceptionAttachment($basedate);

            if ($exception) {
                // Exception found, Now retrieve properties
                $imessage = mapi_attach_openobj($exception, 0);
                $imsgprops = mapi_getprops($imessage);

                // If location is provided, copy it to the response
                if (isset($imsgprops[$this->proptags['location']])) {
                    $messageprops[$this->proptags['location']] = $imsgprops[$this->proptags['location']];
                }

                // Update $messageprops with timings of occurrence
                $messageprops[$this->proptags['startdate']] = $imsgprops[$this->proptags['startdate']];
                $messageprops[$this->proptags['duedate']] = $imsgprops[$this->proptags['duedate']];

                // Meeting related properties
                $props[$this->proptags['meetingstatus']] = $imsgprops[$this->proptags['meetingstatus']];
                $props[$this->proptags['responsestatus']] = $imsgprops[$this->proptags['responsestatus']];
                $props[PR_SUBJECT] = $imsgprops[PR_SUBJECT];
            } else {
                // Exceptions is deleted.
                // Update $messageprops with timings of occurrence
                $messageprops[$this->proptags['startdate']] = $recurr->getOccurrenceStart($basedate);
                $messageprops[$this->proptags['duedate']] = $recurr->getOccurrenceEnd($basedate);

                $props[$this->proptags['meetingstatus']] = olNonMeeting;
                $props[$this->proptags['responsestatus']] = olResponseNone;
            }

            $props[$this->proptags['recurring']] = false;
            $props[$this->proptags['is_exception']] = true;
        } else {
            // we are creating a response from meeting request mail (it could be recurring or non-recurring)
            // Send all recurrence info in response, if this is a recurrence meeting.
            $isRecurring = isset($messageprops[$this->proptags['recurring']]) && $messageprops[$this->proptags['recurring']];
            $isException = isset($messageprops[$this->proptags['is_exception']]) && $messageprops[$this->proptags['is_exception']];
            if ($isRecurring || $isException) {
                if($isRecurring) {
                    $props[$this->proptags['recurring']] = $messageprops[$this->proptags['recurring']];
                }
                if($isException) {
                    $props[$this->proptags['is_exception']] = $messageprops[$this->proptags['is_exception']];
                }
                $calendaritems = $this->findCalendarItems($messageprops[$this->proptags['goid2']], $calFolder);

                $calendaritem = mapi_msgstore_openentry($this->store, $calendaritems[0]);
                $recurr = new Recurrence($store, $calendaritem);
            }
        }

        // we are sending a response for recurring meeting request (or exception), so set some required properties
        if(isset($recurr) && $recurr) {
            if(!empty($messageprops[$this->proptags['recurring_pattern']])) {
                $props[$this->proptags['recurring_pattern']] = $messageprops[$this->proptags['recurring_pattern']];
            }

            if(!empty($messageprops[$this->proptags['recurrence_data']])) {
                $props[$this->proptags['recurrence_data']] = $messageprops[$this->proptags['recurrence_data']];
            }

            $props[$this->proptags['timezone_data']] = $messageprops[$this->proptags['timezone_data']];
            $props[$this->proptags['timezone']] = $messageprops[$this->proptags['timezone']];

            $this->generateRecurDates($recurr, $messageprops, $props);
        }

        // Create a response message
        $recip = Array();
        $recip[PR_ENTRYID] = $messageprops[PR_SENT_REPRESENTING_ENTRYID];
        $recip[PR_EMAIL_ADDRESS] = $messageprops[PR_SENT_REPRESENTING_EMAIL_ADDRESS];
        $recip[PR_ADDRTYPE] = $messageprops[PR_SENT_REPRESENTING_ADDRTYPE];
        $recip[PR_DISPLAY_NAME] = $messageprops[PR_SENT_REPRESENTING_NAME];
        $recip[PR_RECIPIENT_TYPE] = MAPI_TO;

        switch($status) {
            case olResponseAccepted:
                $classpostfix = "Pos";
                $subjectprefix = _("Accepted");
                break;
            case olResponseDeclined:
                $classpostfix = "Neg";
                $subjectprefix = _("Declined");
                break;
            case olResponseTentative:
                $classpostfix = "Tent";
                $subjectprefix = _("Tentatively accepted");
                break;
        }

        if($proposalStartTime && $proposalEndTime){
            // if attendee has proposed new time then change subject prefix
            $subjectprefix = _("New Time Proposed");
        }

        $props[PR_SUBJECT] = $subjectprefix . ": " . $messageprops[PR_SUBJECT];

        $props[PR_MESSAGE_CLASS] = "IPM.Schedule.Meeting.Resp." . $classpostfix;
        if(isset($messageprops[PR_OWNER_APPT_ID]))
            $props[PR_OWNER_APPT_ID] = $messageprops[PR_OWNER_APPT_ID];

        // Set GLOBALID AND CLEANGLOBALID, if exception then also set basedate into GLOBALID(0x3).
        $props[$this->proptags['goid']] = $this->setBasedateInGlobalID($messageprops[$this->proptags['goid2']], $basedate);
        $props[$this->proptags['goid2']] = $messageprops[$this->proptags['goid2']];
        $props[$this->proptags['updatecounter']] = $messageprops[$this->proptags['updatecounter']];

        // get the default store, in which we have to store the accepted email by delegate or normal user.
        $defaultStore = $this->openDefaultStore();
        $props[PR_SENTMAIL_ENTRYID] = $this->getDefaultSentmailEntryID($defaultStore);

        if($proposalStartTime && $proposalEndTime){
            $props[$this->proptags['proposed_start_whole']] = $proposalStartTime;
            $props[$this->proptags['proposed_end_whole']] = $proposalEndTime;
            $props[$this->proptags['proposed_duration']] = round($proposalEndTime - $proposalStartTime)/60;
            $props[$this->proptags['counter_proposal']] = true;
        }

        //Set body message in Appointment
        if(isset($body)) {
            $props[PR_BODY] = $this->getMeetingTimeInfo() ? $this->getMeetingTimeInfo() : $body;
        }

        // PR_START_DATE/PR_END_DATE is used in the UI in Outlook on the response message
        $props[PR_START_DATE] = $messageprops[$this->proptags['startdate']];
        $props[PR_END_DATE] = $messageprops[$this->proptags['duedate']];

        // Set startdate and duedate in response mail.
        $props[$this->proptags['startdate']] = $messageprops[$this->proptags['startdate']];
        $props[$this->proptags['duedate']] = $messageprops[$this->proptags['duedate']];

        // responselocation is used in the UI in Outlook on the response message
        if (isset($messageprops[$this->proptags['location']])) {
            $props[$this->proptags['responselocation']] = $messageprops[$this->proptags['location']];
            $props[$this->proptags['location']] = $messageprops[$this->proptags['location']];
        }

        // check if $store is set and it is not equal to $defaultStore (means its the delegation case)
        if(isset($store) && isset($defaultStore)) {
            $storeProps = mapi_getprops($store, array(PR_ENTRYID));
            $defaultStoreProps = mapi_getprops($defaultStore, array(PR_ENTRYID));

            if($storeProps[PR_ENTRYID] !== $defaultStoreProps[PR_ENTRYID]){
                // get the properties of the other user (for which the logged in user is a delegate).
                $storeProps = mapi_getprops($store, array(PR_MAILBOX_OWNER_ENTRYID));
                $addrbook = mapi_openaddressbook($this->session);
                $addrbookitem = mapi_ab_openentry($addrbook, $storeProps[PR_MAILBOX_OWNER_ENTRYID]);
                $addrbookitemprops = mapi_getprops($addrbookitem, array(PR_DISPLAY_NAME, PR_EMAIL_ADDRESS));

                // setting the following properties will ensure that the delegation part of message.
                $props[PR_SENT_REPRESENTING_ENTRYID] = $storeProps[PR_MAILBOX_OWNER_ENTRYID];
                $props[PR_SENT_REPRESENTING_NAME] = $addrbookitemprops[PR_DISPLAY_NAME];
                $props[PR_SENT_REPRESENTING_EMAIL_ADDRESS] = $addrbookitemprops[PR_EMAIL_ADDRESS];
                $props[PR_SENT_REPRESENTING_ADDRTYPE] = "ZARAFA";

                // get the properties of default store and set it accordingly
                $defaultStoreProps = mapi_getprops($defaultStore, array(PR_MAILBOX_OWNER_ENTRYID));
                $addrbookitem = mapi_ab_openentry($addrbook, $defaultStoreProps[PR_MAILBOX_OWNER_ENTRYID]);
                $addrbookitemprops = mapi_getprops($addrbookitem, array(PR_DISPLAY_NAME, PR_EMAIL_ADDRESS));

                // set the following properties will ensure the sender's details, which will be the default user in this case.
                //the function returns array($name, $emailaddr, $addrtype, $entryid, $searchkey);
                $defaultUserDetails = $this->getOwnerAddress($defaultStore);
                $props[PR_SENDER_ENTRYID] = $defaultUserDetails[3];
                $props[PR_SENDER_EMAIL_ADDRESS] = $defaultUserDetails[1];
                $props[PR_SENDER_NAME] = $defaultUserDetails[0];
                $props[PR_SENDER_ADDRTYPE] = $defaultUserDetails[2];
            }
        }

        // pass the default store to get the required store.
        $outbox = $this->openDefaultOutbox($defaultStore);

        $message = mapi_folder_createmessage($outbox);
        mapi_setprops($message, $props);
        mapi_message_modifyrecipients($message, MODRECIP_ADD, Array($recip));
        mapi_message_savechanges($message);
        mapi_message_submitmessage($message);
    }

    /**
     * Function which finds items in calendar based on specified parameters.
     *@param binary $goid GlobalID(0x3) of item
     *@param resource $calendar MAPI_folder of user
     *@param boolean $use_cleanGlobalID if true then search should be performed on cleanGlobalID(0x23) else globalID(0x3)
     */
    function findCalendarItems($goid, $calendar = false, $use_cleanGlobalID = false) {
        if(!$calendar) {
            // Open the Calendar
            $calendar = $this->openDefaultCalendar();
        }

        // Find the item by restricting all items to the correct ID
        $restrict = Array(RES_AND, Array());

        array_push($restrict[1], Array(RES_PROPERTY,
                                                    Array(RELOP => RELOP_EQ,
                                                          ULPROPTAG => ($use_cleanGlobalID ? $this->proptags['goid2'] : $this->proptags['goid']),
                                                          VALUE => $goid
                                                    )
                                    ));

        $calendarcontents = mapi_folder_getcontentstable($calendar);

        $rows = mapi_table_queryallrows($calendarcontents, Array(PR_ENTRYID), $restrict);

        if(empty($rows))
            return;

        $calendaritems = Array();

        // In principle, there should only be one row, but we'll handle them all just in case
        foreach($rows as $row) {
            $calendaritems[] = $row[PR_ENTRYID];
        }

        return $calendaritems;
    }

    // Returns TRUE if both entryid's are equal. Equality is defined by both entryid's pointing at the
    // same SMTP address when converted to SMTP
    function compareABEntryIDs($entryid1, $entryid2) {
        // If the session was not passed, just do a 'normal' compare.
        if(!$this->session)
            return $entryid1 == $entryid2;

        $smtp1 = $this->getSMTPAddress($entryid1);
        $smtp2 = $this->getSMTPAddress($entryid2);

        if($smtp1 == $smtp2)
            return true;
        else
            return false;
    }

    // Gets the SMTP address of the passed addressbook entryid
    function getSMTPAddress($entryid) {
        if(!$this->session)
            return false;

        $ab = mapi_openaddressbook($this->session);

        $abitem = mapi_ab_openentry($ab, $entryid);

        if(!$abitem)
            return "";

        $props = mapi_getprops($abitem, array(PR_ADDRTYPE, PR_EMAIL_ADDRESS, PR_SMTP_ADDRESS));

        if($props[PR_ADDRTYPE] == "SMTP") {
            return $props[PR_EMAIL_ADDRESS];
        }
        else return $props[PR_SMTP_ADDRESS];
    }

    /**
     * Gets the properties associated with the owner of the passed store:
     * PR_DISPLAY_NAME, PR_EMAIL_ADDRESS, PR_ADDRTYPE, PR_ENTRYID, PR_SEARCH_KEY
     *
     * @param $store message store
     * @param $fallbackToLoggedInUser if true then return properties of logged in user instead of mailbox owner
     * not used when passed store is public store. for public store we are always returning logged in user's info.
     * @return properties of logged in user in an array in sequence of display_name, email address, address tyep,
     * entryid and search key.
     */
    function getOwnerAddress($store, $fallbackToLoggedInUser = true)
    {
        if(!$this->session)
            return false;

        $storeProps = mapi_getprops($store, array(PR_MAILBOX_OWNER_ENTRYID, PR_USER_ENTRYID));

        $ownerEntryId = false;
        if(isset($storeProps[PR_USER_ENTRYID]) && $storeProps[PR_USER_ENTRYID]) {
            $ownerEntryId = $storeProps[PR_USER_ENTRYID];
        }

        if(isset($storeProps[PR_MAILBOX_OWNER_ENTRYID]) && $storeProps[PR_MAILBOX_OWNER_ENTRYID] && !$fallbackToLoggedInUser) {
            $ownerEntryId = $storeProps[PR_MAILBOX_OWNER_ENTRYID];
        }

        if($ownerEntryId) {
            $ab = mapi_openaddressbook($this->session);

            $zarafaUser = mapi_ab_openentry($ab, $ownerEntryId);
            if(!$zarafaUser)
                return false;

            $ownerProps = mapi_getprops($zarafaUser, array(PR_ADDRTYPE, PR_DISPLAY_NAME, PR_EMAIL_ADDRESS));

            $addrType = $ownerProps[PR_ADDRTYPE];
            $name = $ownerProps[PR_DISPLAY_NAME];
            $emailAddr = $ownerProps[PR_EMAIL_ADDRESS];
            $searchKey = strtoupper($addrType) . ":" . strtoupper($emailAddr);
            $entryId = $ownerEntryId;

            return array($name, $emailAddr, $addrType, $entryId, $searchKey);
        }

        return false;
    }

    // Opens this session's default message store
    function openDefaultStore()
    {
        $storestable = mapi_getmsgstorestable($this->session);
        $rows = mapi_table_queryallrows($storestable, array(PR_ENTRYID, PR_DEFAULT_STORE));
        $entry = false;

        foreach($rows as $row) {
            if(isset($row[PR_DEFAULT_STORE]) && $row[PR_DEFAULT_STORE]) {
                $entryid = $row[PR_ENTRYID];
                break;
            }
        }

        if(!$entryid)
            return false;

        return mapi_openmsgstore($this->session, $entryid);
    }
    /**
     *  Function which adds organizer to recipient list which is passed.
     *  This function also checks if it has organizer.
     *
     * @param array $messageProps message properties
     * @param array $recipients    recipients list of message.
     * @param boolean $isException true if we are processing recipient of exception
     */
    function addOrganizer($messageProps, &$recipients, $isException = false){

        $hasOrganizer = false;
        // Check if meeting already has an organizer.
        foreach ($recipients as $key => $recipient){
            if (isset($recipient[PR_RECIPIENT_FLAGS]) && $recipient[PR_RECIPIENT_FLAGS] == (recipSendable | recipOrganizer)) {
                $hasOrganizer = true;
            } else if ($isException && !isset($recipient[PR_RECIPIENT_FLAGS])){
                // Recipients for an occurrence
                $recipients[$key][PR_RECIPIENT_FLAGS] = recipSendable | recipExceptionalResponse;
            }
        }

        if (!$hasOrganizer){
            // Create organizer.
            $organizer = array();
            $organizer[PR_ENTRYID] = $messageProps[PR_SENT_REPRESENTING_ENTRYID];
            $organizer[PR_DISPLAY_NAME] = $messageProps[PR_SENT_REPRESENTING_NAME];
            $organizer[PR_EMAIL_ADDRESS] = $messageProps[PR_SENT_REPRESENTING_EMAIL_ADDRESS];
            $organizer[PR_RECIPIENT_TYPE] = MAPI_TO;
            $organizer[PR_RECIPIENT_DISPLAY_NAME] = $messageProps[PR_SENT_REPRESENTING_NAME];
            $organizer[PR_ADDRTYPE] = empty($messageProps[PR_SENT_REPRESENTING_ADDRTYPE]) ? 'SMTP':$messageProps[PR_SENT_REPRESENTING_ADDRTYPE];
            $organizer[PR_RECIPIENT_TRACKSTATUS] = olRecipientTrackStatusNone;
            $organizer[PR_RECIPIENT_FLAGS] = recipSendable | recipOrganizer;

            // Add organizer to recipients list.
            array_unshift($recipients, $organizer);
        }
    }

    /**
     * Function adds recipients in recips array from the string.
     *
     * @param array $recips recipient array.
     * @param string $recipString recipient string attendees.
     * @param int $type type of the recipient, MAPI_TO/MAPI_CC.
     */
    function setRecipsFromString(&$recips, $recipString, $recipType = MAPI_TO)
    {
        $extraRecipient = array();
        $recipArray = explode(";", $recipString);

        foreach($recipArray as $recip) {
            $recip = trim($recip);
            if (!empty($recip)) {
                $extraRecipient[PR_RECIPIENT_TYPE] = $recipType;
                $extraRecipient[PR_DISPLAY_NAME] = $recip;
                array_push($recips, $extraRecipient);
            }
        }

    }

    /**
     * Function which removes an exception/occurrence from recurrencing meeting
     * when a meeting cancellation of an occurrence is processed.
     *@param string $basedate basedate of an occurrence
     *@param resource $message recurring item from which occurrence has to be deleted
     *@param resource $store MAPI_MSG_Store which contains the item
     */
    function doRemoveExceptionFromCalendar($basedate, $message, $store)
    {
        $recurr = new Recurrence($store, $message);
        $recurr->createException(array(), $basedate, true);
        mapi_savechanges($message);
    }

    /**
     * Function which returns basedate of an changed occurrance from globalID of meeting request.
     *@param binary $goid globalID
     *@return boolean true if basedate is found else false it not found
     */
    function getBasedateFromGlobalID($goid)
    {
        $hexguid = bin2hex($goid);
        $hexbase = substr($hexguid, 32, 8);
        $day = hexdec(substr($hexbase, 6, 2));
        $month = hexdec(substr($hexbase, 4, 2));
        $year = hexdec(substr($hexbase, 0, 4));

        if ($day && $month && $year)
            return gmmktime(0, 0, 0, $month, $day, $year);
        else
            return false;
    }

    /**
     * Function which sets basedate in globalID of changed occurrance which is to be send.
     *@param binary $goid globalID
     *@param string basedate of changed occurrance
     *@return binary globalID with basedate in it
     */
    function setBasedateInGlobalID($goid, $basedate = false)
    {
        $hexguid = bin2hex($goid);
        $year = $basedate ? sprintf('%04s', dechex(date('Y', $basedate))) : '0000';
        $month = $basedate ? sprintf('%02s', dechex(date('m', $basedate))) : '00';
        $day = $basedate ? sprintf('%02s', dechex(date('d', $basedate))) : '00';

        return hex2bin(strtoupper(substr($hexguid, 0, 32) . $year . $month . $day . substr($hexguid, 40)));
    }
    /**
     * Function which replaces attachments with copy_from in copy_to.
     *@param resource $copy_from MAPI_message from which attachments are to be copied.
     *@param resource $copy_to MAPI_message to which attachment are to be copied.
     *@param boolean $copyExceptions if true then all exceptions should also be sent as attachments
     */
    function replaceAttachments($copy_from, $copy_to, $copyExceptions = true)
    {
        /* remove all old attachments */
        $attachmentTable = mapi_message_getattachmenttable($copy_to);
        if($attachmentTable) {
            $attachments = mapi_table_queryallrows($attachmentTable, array(PR_ATTACH_NUM, PR_ATTACH_METHOD, PR_EXCEPTION_STARTTIME));

            foreach($attachments as $attach_props){
                /* remove exceptions too? */
                if (!$copyExceptions && $attach_props[PR_ATTACH_METHOD] == 5 && isset($attach_props[PR_EXCEPTION_STARTTIME]))
                    continue;
                mapi_message_deleteattach($copy_to, $attach_props[PR_ATTACH_NUM]);
            }
        }
        $attachmentTable = false;

        /* copy new attachments */
        $attachmentTable = mapi_message_getattachmenttable($copy_from);
        if($attachmentTable) {
            $attachments = mapi_table_queryallrows($attachmentTable, array(PR_ATTACH_NUM, PR_ATTACH_METHOD, PR_EXCEPTION_STARTTIME));

            foreach($attachments as $attach_props){
                if (!$copyExceptions && $attach_props[PR_ATTACH_METHOD] == 5 && isset($attach_props[PR_EXCEPTION_STARTTIME]))
                    continue;

                $attach_old = mapi_message_openattach($copy_from, (int) $attach_props[PR_ATTACH_NUM]);
                $attach_newResourceMsg = mapi_message_createattach($copy_to);
                mapi_copyto($attach_old, array(), array(), $attach_newResourceMsg, 0);
                mapi_savechanges($attach_newResourceMsg);
            }
        }
    }
    /**
     * Function which replaces recipients in copy_to with recipients from copy_from.
     *@param resource $copy_from MAPI_message from which recipients are to be copied.
     *@param resource $copy_to MAPI_message to which recipients are to be copied.
     */
    function replaceRecipients($copy_from, $copy_to, $isDelegate = false)
    {
        $recipienttable = mapi_message_getrecipienttable($copy_from);

        // If delegate, then do not add the delegate in recipients
        if ($isDelegate) {
            $delegate = mapi_getprops($copy_from, array(PR_RECEIVED_BY_EMAIL_ADDRESS));
            $res = array(RES_PROPERTY, array(RELOP => RELOP_NE, ULPROPTAG => PR_EMAIL_ADDRESS, VALUE => $delegate[PR_RECEIVED_BY_EMAIL_ADDRESS]));
            $recipients = mapi_table_queryallrows($recipienttable, $this->recipprops, $res);
        } else {
            $recipients = mapi_table_queryallrows($recipienttable, $this->recipprops);
        }

        $copy_to_recipientTable = mapi_message_getrecipienttable($copy_to);
        $copy_to_recipientRows = mapi_table_queryallrows($copy_to_recipientTable, array(PR_ROWID));
        foreach($copy_to_recipientRows as $recipient) {
            mapi_message_modifyrecipients($copy_to, MODRECIP_REMOVE, array($recipient));
        }

        mapi_message_modifyrecipients($copy_to, MODRECIP_ADD, $recipients);
    }
    /**
     * Function creates meeting item in resource's calendar.
     *@param resource $message MAPI_message which is to create in resource's calendar
     *@param boolean $cancel cancel meeting
     *@param string $prefix prefix for subject of meeting
     */
    function bookResources($message, $cancel, $prefix, $basedate = false)
    {
        if(!$this->enableDirectBooking)
            return array();

        // Get the properties of the message
        $messageprops = mapi_getprops($message);

        if ($basedate) {
            $recurrItemProps = mapi_getprops($this->message, array($this->proptags['goid'], $this->proptags['goid2'], $this->proptags['timezone_data'], $this->proptags['timezone'], PR_OWNER_APPT_ID));

            $messageprops[$this->proptags['goid']] = $this->setBasedateInGlobalID($recurrItemProps[$this->proptags['goid']], $basedate);
            $messageprops[$this->proptags['goid2']] = $recurrItemProps[$this->proptags['goid2']];

            // Delete properties which are not needed.
            $deleteProps = array($this->proptags['basedate'], PR_DISPLAY_NAME, PR_ATTACHMENT_FLAGS, PR_ATTACHMENT_HIDDEN, PR_ATTACHMENT_LINKID, PR_ATTACH_FLAGS, PR_ATTACH_METHOD);
            foreach ($deleteProps as $propID) {
                if (isset($messageprops[$propID])) {
                    unset($messageprops[$propID]);
                }
            }

            if (isset($messageprops[$this->proptags['recurring']])) $messageprops[$this->proptags['recurring']] = false;

            // Set Outlook properties
            $messageprops[$this->proptags['clipstart']] = $messageprops[$this->proptags['startdate']];
            $messageprops[$this->proptags['clipend']] = $messageprops[$this->proptags['duedate']];
            $messageprops[$this->proptags['timezone_data']] = $recurrItemProps[$this->proptags['timezone_data']];
            $messageprops[$this->proptags['timezone']] = $recurrItemProps[$this->proptags['timezone']];
            $messageprops[$this->proptags['attendee_critical_change']] = time();
            $messageprops[$this->proptags['owner_critical_change']] = time();
        }

        // Get resource recipients
        $getResourcesRestriction = Array(RES_AND,
            Array(Array(RES_PROPERTY,
                Array(RELOP => RELOP_EQ,    // Equals recipient type 3: Resource
                    ULPROPTAG => PR_RECIPIENT_TYPE,
                    VALUE => array(PR_RECIPIENT_TYPE =>MAPI_BCC)
                )
            ))
        );
        $recipienttable = mapi_message_getrecipienttable($message);
        $resourceRecipients = mapi_table_queryallrows($recipienttable, $this->recipprops, $getResourcesRestriction);

        $this->errorSetResource = false;
        $resourceRecipData = Array();

        // Put appointment into store resource users
        $i = 0;
        $len = count($resourceRecipients);
        while(!$this->errorSetResource && $i < $len){
            $request = array(array(PR_DISPLAY_NAME => $resourceRecipients[$i][PR_DISPLAY_NAME]));
            $ab = mapi_openaddressbook($this->session);
            $ret = mapi_ab_resolvename($ab, $request, EMS_AB_ADDRESS_LOOKUP);
            $result = mapi_last_hresult();
            if ($result == NOERROR){
                $result = $ret[0][PR_ENTRYID];
            }
            $resourceUsername = $ret[0][PR_EMAIL_ADDRESS];
            $resourceABEntryID = $ret[0][PR_ENTRYID];

            // Get StoreEntryID by username
            $user_entryid = mapi_msgstore_createentryid($this->store, $resourceUsername);

            // Open store of the user
            $userStore = mapi_openmsgstore($this->session, $user_entryid);
            // Open root folder
            $userRoot = mapi_msgstore_openentry($userStore, null);
            // Get calendar entryID
            $userRootProps = mapi_getprops($userRoot, array(PR_STORE_ENTRYID, PR_IPM_APPOINTMENT_ENTRYID, PR_FREEBUSY_ENTRYIDS));

            // Open Calendar folder   [check hresult==0]
            $accessToFolder = false;
            try {
                $calFolder = mapi_msgstore_openentry($userStore, $userRootProps[PR_IPM_APPOINTMENT_ENTRYID]);
                if($calFolder){
                    $calFolderProps = mapi_getProps($calFolder, Array(PR_ACCESS));
                    if(($calFolderProps[PR_ACCESS] & MAPI_ACCESS_CREATE_CONTENTS) !== 0){
                        $accessToFolder = true;
                    }
                }
            } catch (MAPIException $e) {
                $e->setHandled();
                $this->errorSetResource = 1; // No access
            }

            if($accessToFolder) {
                /**
                 * Get the LocalFreebusy message that contains the properties that
                 * are set to accept or decline resource meeting requests
                 */
                // Use PR_FREEBUSY_ENTRYIDS[1] to open folder the LocalFreeBusy msg
                $localFreebusyMsg = mapi_msgstore_openentry($userStore, $userRootProps[PR_FREEBUSY_ENTRYIDS][1]);
                if($localFreebusyMsg){
                    $props = mapi_getprops($localFreebusyMsg, array(PR_PROCESS_MEETING_REQUESTS, PR_DECLINE_RECURRING_MEETING_REQUESTS, PR_DECLINE_CONFLICTING_MEETING_REQUESTS));

                    $acceptMeetingRequests = ($props[PR_PROCESS_MEETING_REQUESTS])?1:0;
                    $declineRecurringMeetingRequests = ($props[PR_DECLINE_RECURRING_MEETING_REQUESTS])?1:0;
                    $declineConflictingMeetingRequests = ($props[PR_DECLINE_CONFLICTING_MEETING_REQUESTS])?1:0;
                    if(!$acceptMeetingRequests){
                        /**
                         * When a resource has not been set to automatically accept meeting requests,
                         * the meeting request has to be sent to him rather than being put directly into
                         * his calendar. No error should be returned.
                         */
                        //$errorSetResource = 2;
                        $this->nonAcceptingResources[] = $resourceRecipients[$i];
                    }else{
                        if($declineRecurringMeetingRequests && !$cancel){
                            // Check if appointment is recurring
                            if($messageprops[ $this->proptags['recurring'] ]){
                                $this->errorSetResource = 3;
                            }
                        }
                        if($declineConflictingMeetingRequests && !$cancel){
                            // Check for conflicting items
                            $conflicting = false;

                            // Open the calendar
                            $calFolder = mapi_msgstore_openentry($userStore, $userRootProps[PR_IPM_APPOINTMENT_ENTRYID]);

                            if($calFolder) {
                                if ($this->isMeetingConflicting($message, $userStore, $calFolder, $messageprops))
                                    $conflicting = true;
                            } else {
                                $this->errorSetResource = 1; // No access
                            }

                            if($conflicting){
                                $this->errorSetResource = 4; // Conflict
                            }
                        }
                    }
                }
            }

            if(!$this->errorSetResource && $accessToFolder){
                /**
                 * First search on GlobalID(0x3)
                 * If (recurring and occurrence) If Resource was booked for only this occurrence then Resource should have only this occurrence in Calendar and not whole series.
                 * If (normal meeting) then GlobalID(0x3) and CleanGlobalID(0x23) are same, so doesnt matter if search is based on GlobalID.
                 */
                $rows = $this->findCalendarItems($messageprops[$this->proptags['goid']], $calFolder);

                /**
                 * If no entry is found then
                 * 1) Resource doesnt have meeting in Calendar. Seriously!!
                 * OR
                 * 2) We were looking for occurrence item but Resource has whole series
                 */
                if(empty($rows)){
                    /**
                     * Now search on CleanGlobalID(0x23) WHY???
                     * Because we are looking recurring item
                     *
                     * Possible results of this search
                     * 1) If Resource was booked for more than one occurrences then this search will return all those occurrence because search is perform on CleanGlobalID
                     * 2) If Resource was booked for whole series then it should return series.
                     */
                    $rows = $this->findCalendarItems($messageprops[$this->proptags['goid2']], $calFolder, true);

                    $newResourceMsg = false;
                    if (!empty($rows)) {
                        // Since we are looking for recurring item, open every result and check for 'recurring' property.
                        foreach($rows as $row) {
                            $ResourceMsg = mapi_msgstore_openentry($userStore, $row);
                            $ResourceMsgProps = mapi_getprops($ResourceMsg, array($this->proptags['recurring']));

                            if (isset($ResourceMsgProps[$this->proptags['recurring']]) && $ResourceMsgProps[$this->proptags['recurring']]) {
                                $newResourceMsg = $ResourceMsg;
                                break;
                            }
                        }
                    }

                    // Still no results found. I giveup, create new message.
                    if (!$newResourceMsg)
                        $newResourceMsg = mapi_folder_createmessage($calFolder);
                }else{
                    $newResourceMsg = mapi_msgstore_openentry($userStore, $rows[0]);
                }

                // Prefix the subject if needed
                if($prefix && isset($messageprops[PR_SUBJECT])) {
                    $messageprops[PR_SUBJECT] = $prefix . $messageprops[PR_SUBJECT];
                }

                // Set status to cancelled if needed
                $messageprops[$this->proptags['busystatus']] = fbBusy; // The default status (Busy)
                if($cancel) {
                    $messageprops[$this->proptags['meetingstatus']] = olMeetingCanceled; // The meeting has been canceled
                    $messageprops[$this->proptags['busystatus']] = fbFree; // Free
                } else {
                    $messageprops[$this->proptags['meetingstatus']] = olMeetingReceived; // The recipient is receiving the request
                }
                $messageprops[$this->proptags['responsestatus']] = olResponseAccepted; // The resource autmatically accepts the appointment

                $messageprops[PR_MESSAGE_CLASS] = "IPM.Appointment";

                // Remove the PR_ICON_INDEX as it is not needed in the sent message and it also
                // confuses the Zarafa webaccess
                $messageprops[PR_ICON_INDEX] = null;
                $messageprops[PR_RESPONSE_REQUESTED] = true;

                $addrinfo = $this->getOwnerAddress($this->store);

                if($addrinfo) {
                    list($ownername, $owneremailaddr, $owneraddrtype, $ownerentryid, $ownersearchkey) = $addrinfo;

                    $messageprops[PR_SENT_REPRESENTING_EMAIL_ADDRESS] = $owneremailaddr;
                    $messageprops[PR_SENT_REPRESENTING_NAME] = $ownername;
                    $messageprops[PR_SENT_REPRESENTING_ADDRTYPE] = $owneraddrtype;
                    $messageprops[PR_SENT_REPRESENTING_ENTRYID] = $ownerentryid;
                    $messageprops[PR_SENT_REPRESENTING_SEARCH_KEY] = $ownersearchkey;

                    $messageprops[$this->proptags['apptreplyname']] = $ownername;
                    $messageprops[$this->proptags['replytime']] = time();
                }

                if ($basedate && isset($ResourceMsgProps[$this->proptags['recurring']]) && $ResourceMsgProps[$this->proptags['recurring']]) {
                    $recurr = new Recurrence($userStore, $newResourceMsg);

                    // Copy recipients list
                    $reciptable = mapi_message_getrecipienttable($message);
                    $recips = mapi_table_queryallrows($reciptable, $this->recipprops);
                    // add owner to recipient table
                    $this->addOrganizer($messageprops, $recips, true);

                    // Update occurrence
                    if($recurr->isException($basedate))
                        $recurr->modifyException($messageprops, $basedate, $recips);
                    else
                        $recurr->createException($messageprops, $basedate, false, $recips);
                } else {

                    mapi_setprops($newResourceMsg, $messageprops);

                    // Copy attachments
                    $this->replaceAttachments($message, $newResourceMsg);

                    // Copy all recipients too
                    $this->replaceRecipients($message, $newResourceMsg);

                    // Now add organizer also to recipient table
                    $recips = Array();
                    $this->addOrganizer($messageprops, $recips);
                    mapi_message_modifyrecipients($newResourceMsg, MODRECIP_ADD, $recips);
                }

                mapi_savechanges($newResourceMsg);

                $resourceRecipData[] = Array(
                    'store' => $userStore,
                    'folder' => $calFolder,
                    'msg' => $newResourceMsg,
                );
                $this->includesResources = true;
            }else{
                /**
                 * If no other errors occured and you have no access to the
                 * folder of the resource, throw an error=1.
                 */
                if(!$this->errorSetResource){
                    $this->errorSetResource = 1;
                }

                for($j = 0, $len = count($resourceRecipData); $j < $len; $j++){
                    // Get the EntryID
                    $props = mapi_message_getprops($resourceRecipData[$j]['msg']);

                    mapi_folder_deletemessages($resourceRecipData[$j]['folder'], Array($props[PR_ENTRYID]), DELETE_HARD_DELETE);
                }
                $this->recipientDisplayname = $resourceRecipients[$i][PR_DISPLAY_NAME];
            }
            $i++;
        }

        /**************************************************************
         * Set the BCC-recipients (resources) tackstatus to accepted.
         */
        // Get resource recipients
        $getResourcesRestriction = Array(RES_AND,
            Array(Array(RES_PROPERTY,
                Array(RELOP => RELOP_EQ,    // Equals recipient type 3: Resource
                    ULPROPTAG => PR_RECIPIENT_TYPE,
                    VALUE => array(PR_RECIPIENT_TYPE =>MAPI_BCC)
                )
            ))
        );
        $recipienttable = mapi_message_getrecipienttable($message);
        $resourceRecipients = mapi_table_queryallrows($recipienttable, $this->recipprops, $getResourcesRestriction);
        if(!empty($resourceRecipients)){
            // Set Tracking status of resource recipients to olResponseAccepted (3)
            for($i = 0, $len = count($resourceRecipients); $i < $len; $i++){
                $resourceRecipients[$i][PR_RECIPIENT_TRACKSTATUS] = olRecipientTrackStatusAccepted;
                $resourceRecipients[$i][PR_RECIPIENT_TRACKSTATUS_TIME] = time();
            }
            mapi_message_modifyrecipients($message, MODRECIP_MODIFY, $resourceRecipients);
        }

        // Publish updated free/busy information
        if(!$this->errorSetResource){
            for($i = 0, $len = count($resourceRecipData); $i < $len; $i++){
                $storeProps = mapi_msgstore_getprops($resourceRecipData[$i]['store'], array(PR_MAILBOX_OWNER_ENTRYID));
                if (isset($storeProps[PR_MAILBOX_OWNER_ENTRYID])){
                    $pub = new FreeBusyPublish($this->session, $resourceRecipData[$i]['store'], $resourceRecipData[$i]['folder'], $storeProps[PR_MAILBOX_OWNER_ENTRYID]);
                    $pub->publishFB(time() - (7 * 24 * 60 * 60), 6 * 30 * 24 * 60 * 60); // publish from one week ago, 6 months ahead
                }
            }
        }

        return $resourceRecipData;
    }
    /**
     * Function which save an exception into recurring item
     *
     * @param resource $recurringItem reference to MAPI_message of recurring item
     * @param resource $occurrenceItem reference to MAPI_message of occurrence
     * @param string $basedate basedate of occurrence
     * @param boolean $move if true then occurrence item is deleted
     * @param boolean $tentative true if user has tentatively accepted it or false if user has accepted it.
     * @param boolean $userAction true if user has manually responded to meeting request
     * @param resource $store user store
     * @param boolean $isDelegate true if delegate is processing this meeting request
     */
    function acceptException(&$recurringItem, &$occurrenceItem, $basedate, $move = false, $tentative, $userAction = false, $store, $isDelegate = false)
    {
        $recurr = new Recurrence($store, $recurringItem);

        // Copy properties from meeting request
        $exception_props = mapi_getprops($occurrenceItem);

        // Copy recipients list
        $reciptable = mapi_message_getrecipienttable($occurrenceItem);
        // If delegate, then do not add the delegate in recipients
        if ($isDelegate) {
            $delegate = mapi_getprops($this->message, array(PR_RECEIVED_BY_EMAIL_ADDRESS));
            $res = array(RES_PROPERTY, array(RELOP => RELOP_NE, ULPROPTAG => PR_EMAIL_ADDRESS, VALUE => $delegate[PR_RECEIVED_BY_EMAIL_ADDRESS]));
            $recips = mapi_table_queryallrows($reciptable, $this->recipprops, $res);
        } else {
            $recips = mapi_table_queryallrows($reciptable, $this->recipprops);
        }


        // add owner to recipient table
        $this->addOrganizer($exception_props, $recips, true);

        // add delegator to meetings
        if ($isDelegate) $this->addDelegator($exception_props, $recips);

        $exception_props[$this->proptags['meetingstatus']] = olMeetingReceived;
        $exception_props[$this->proptags['responsestatus']] = $userAction ? ($tentative ? olResponseTentative : olResponseAccepted) : olResponseNotResponded;
        // Set basedate property (ExceptionReplaceTime)

        if (isset($exception_props[$this->proptags['intendedbusystatus']])) {
            if($tentative && $exception_props[$this->proptags['intendedbusystatus']] !== fbFree) {
                $exception_props[$this->proptags['busystatus']] = $tentative;
            } else {
                $exception_props[$this->proptags['busystatus']] = $exception_props[$this->proptags['intendedbusystatus']];
            }
            // we already have intendedbusystatus value in $exception_props so no need to copy it
        } else {
            $exception_props[$this->proptags['busystatus']] = $tentative ? fbTentative : fbBusy;
        }

        if($userAction) {
            // if user has responded then set replytime
            $exception_props[$this->proptags['replytime']] = time();
        }

        if($recurr->isException($basedate))
            $recurr->modifyException($exception_props, $basedate, $recips, $occurrenceItem);
        else
            $recurr->createException($exception_props, $basedate, false, $recips, $occurrenceItem);

        // Move the occurrenceItem to the waste basket
        if ($move) {
            $wastebasket = $this->openDefaultWastebasket();
            $sourcefolder = mapi_msgstore_openentry($this->store, $exception_props[PR_PARENT_ENTRYID]);
            mapi_folder_copymessages($sourcefolder, Array($exception_props[PR_ENTRYID]), $wastebasket, MESSAGE_MOVE);
        }

        mapi_savechanges($recurringItem);
    }

    /**
     * Function which submits meeting request based on arguments passed to it.
     *@param resource $message MAPI_message whose meeting request is to be send
     *@param boolean $cancel if true send request, else send cancellation
     *@param string $prefix subject prefix
     *@param integer $basedate basedate for an occurrence
     *@param Object $recurObject recurrence object of mr
     *@param boolean $copyExceptions When sending update mail for recurring item then we dont send exceptions in attachments
     */
    function submitMeetingRequest($message, $cancel, $prefix, $basedate = false, $recurObject = false, $copyExceptions = true, $deletedRecips = false)
    {
        $newmessageprops = $messageprops = mapi_getprops($this->message);
        $new = $this->createOutgoingMessage();

        // Copy the entire message into the new meeting request message
        if ($basedate) {
            // messageprops contains properties of whole recurring series
            // and newmessageprops contains properties of exception item
            $newmessageprops = mapi_getprops($message);

            // Ensure that the correct basedate is set in the new message
            $newmessageprops[$this->proptags['basedate']] = $basedate;

            // Set isRecurring to false, because this is an exception
            $newmessageprops[$this->proptags['recurring']] = false;

            // set LID_IS_EXCEPTION to true
            $newmessageprops[$this->proptags['is_exception']] = true;

            // Set to high importance
            if($cancel) $newmessageprops[PR_IMPORTANCE] = IMPORTANCE_HIGH;

            // Set startdate and enddate of exception
            if ($cancel && $recurObject) {
                $newmessageprops[$this->proptags['startdate']] = $recurObject->getOccurrenceStart($basedate);
                $newmessageprops[$this->proptags['duedate']] = $recurObject->getOccurrenceEnd($basedate);
            }

            // Set basedate in guid (0x3)
            $newmessageprops[$this->proptags['goid']] = $this->setBasedateInGlobalID($messageprops[$this->proptags['goid2']], $basedate);
            $newmessageprops[$this->proptags['goid2']] = $messageprops[$this->proptags['goid2']];
            $newmessageprops[PR_OWNER_APPT_ID] = $messageprops[PR_OWNER_APPT_ID];

            // Get deleted recipiets from exception msg
            $restriction = Array(RES_AND,
                            Array(
                                Array(RES_BITMASK,
                                    Array(    ULTYPE        =>    BMR_NEZ,
                                            ULPROPTAG    =>    PR_RECIPIENT_FLAGS,
                                            ULMASK        =>    recipExceptionalDeleted
                                    )
                                ),
                                Array(RES_BITMASK,
                                    Array(    ULTYPE        =>    BMR_EQZ,
                                            ULPROPTAG    =>    PR_RECIPIENT_FLAGS,
                                            ULMASK        =>    recipOrganizer
                                    )
                                ),
                            )
            );

            // In direct-booking mode, we don't need to send cancellations to resources
            if($this->enableDirectBooking) {
                $restriction[1][] = Array(RES_PROPERTY,
                                        Array(RELOP => RELOP_NE,    // Does not equal recipient type: MAPI_BCC (Resource)
                                            ULPROPTAG => PR_RECIPIENT_TYPE,
                                            VALUE => array(PR_RECIPIENT_TYPE => MAPI_BCC)
                                        )
                                    );
            }

            $recipienttable = mapi_message_getrecipienttable($message);
            $recipients = mapi_table_queryallrows($recipienttable, $this->recipprops, $restriction);

            if (!$deletedRecips) {
                $deletedRecips = array_merge(array(), $recipients);
            } else {
                $deletedRecips = array_merge($deletedRecips, $recipients);
            }
        }

        // Remove the PR_ICON_INDEX as it is not needed in the sent message and it also
        // confuses the Zarafa webaccess
        $newmessageprops[PR_ICON_INDEX] = null;
        $newmessageprops[PR_RESPONSE_REQUESTED] = true;

        // PR_START_DATE and PR_END_DATE will be used by outlook to show the position in the calendar
        $newmessageprops[PR_START_DATE] = $newmessageprops[$this->proptags['startdate']];
        $newmessageprops[PR_END_DATE] = $newmessageprops[$this->proptags['duedate']];

        // Set updatecounter/AppointmentSequenceNumber
        // get the value of latest updatecounter for the whole series and use it
        $newmessageprops[$this->proptags['updatecounter']] = $messageprops[$this->proptags['last_updatecounter']];

        $meetingTimeInfo = $this->getMeetingTimeInfo();

        if($meetingTimeInfo)
            $newmessageprops[PR_BODY] = $meetingTimeInfo;

        // Send all recurrence info in mail, if this is a recurrence meeting.
        if (isset($messageprops[$this->proptags['recurring']]) && $messageprops[$this->proptags['recurring']]) {
            if(!empty($messageprops[$this->proptags['recurring_pattern']])) {
                $newmessageprops[$this->proptags['recurring_pattern']] = $messageprops[$this->proptags['recurring_pattern']];
            }
            $newmessageprops[$this->proptags['recurrence_data']] = $messageprops[$this->proptags['recurrence_data']];
            $newmessageprops[$this->proptags['timezone_data']] = $messageprops[$this->proptags['timezone_data']];
            $newmessageprops[$this->proptags['timezone']] = $messageprops[$this->proptags['timezone']];

            if($recurObject) {
                $this->generateRecurDates($recurObject, $messageprops, $newmessageprops);
            }
        }

        if (isset($newmessageprops[$this->proptags['counter_proposal']])) {
            unset($newmessageprops[$this->proptags['counter_proposal']]);
        }

        // Prefix the subject if needed
        if ($prefix && isset($newmessageprops[PR_SUBJECT]))
            $newmessageprops[PR_SUBJECT] = $prefix . $newmessageprops[PR_SUBJECT];

        mapi_setprops($new, $newmessageprops);

        // Copy attachments
        $this->replaceAttachments($message, $new, $copyExceptions);

        // Retrieve only those recipient who should receive this meeting request.
        $stripResourcesRestriction = Array(RES_AND,
                                Array(
                                    Array(RES_BITMASK,
                                        Array(    ULTYPE        =>    BMR_EQZ,
                                                ULPROPTAG    =>    PR_RECIPIENT_FLAGS,
                                                ULMASK        =>    recipExceptionalDeleted
                                        )
                                    ),
                                    Array(RES_BITMASK,
                                        Array(    ULTYPE        =>    BMR_EQZ,
                                                ULPROPTAG    =>    PR_RECIPIENT_FLAGS,
                                                ULMASK        =>    recipOrganizer
                                        )
                                    ),
                                )
        );

        // In direct-booking mode, resources do not receive a meeting request
        if($this->enableDirectBooking) {
            $stripResourcesRestriction[1][] =
                                    Array(RES_PROPERTY,
                                        Array(RELOP => RELOP_NE,    // Does not equal recipient type: MAPI_BCC (Resource)
                                            ULPROPTAG => PR_RECIPIENT_TYPE,
                                            VALUE => array(PR_RECIPIENT_TYPE => MAPI_BCC)
                                        )
                                    );
        }

        $recipienttable = mapi_message_getrecipienttable($message);
        $recipients = mapi_table_queryallrows($recipienttable, $this->recipprops, $stripResourcesRestriction);

        if ($basedate && empty($recipients)) {
            // Retrieve full list
            $recipienttable = mapi_message_getrecipienttable($this->message);
            $recipients = mapi_table_queryallrows($recipienttable, $this->recipprops);

            // Save recipients in exceptions
            mapi_message_modifyrecipients($message, MODRECIP_ADD, $recipients);

            // Now retrieve only those recipient who should receive this meeting request.
            $recipients = mapi_table_queryallrows($recipienttable, $this->recipprops, $stripResourcesRestriction);
        }

        //@TODO: handle nonAcceptingResources
        /**
         * Add resource recipients that did not automatically accept the meeting request.
         * (note: meaning that they did not decline the meeting request)
         *//*
        for($i=0;$i<count($this->nonAcceptingResources);$i++){
            $recipients[] = $this->nonAcceptingResources[$i];
        }*/

        if(!empty($recipients)) {
            // Strip out the sender/"owner" recipient
            mapi_message_modifyrecipients($new, MODRECIP_ADD, $recipients);

            // Set some properties that are different in the sent request than
            // in the item in our calendar

            // we should store busystatus value to intendedbusystatus property, because busystatus for outgoing meeting request
            // should always be fbTentative
            $newmessageprops[$this->proptags['intendedbusystatus']] = isset($newmessageprops[$this->proptags['busystatus']]) ? $newmessageprops[$this->proptags['busystatus']] : $messageprops[$this->proptags['busystatus']];
            $newmessageprops[$this->proptags['busystatus']] = fbTentative; // The default status when not accepted
            $newmessageprops[$this->proptags['responsestatus']] = olResponseNotResponded; // The recipient has not responded yet
            $newmessageprops[$this->proptags['attendee_critical_change']] = time();
            $newmessageprops[$this->proptags['owner_critical_change']] = time();
            $newmessageprops[$this->proptags['meetingtype']] = mtgRequest;

            if ($cancel) {
                $newmessageprops[PR_MESSAGE_CLASS] = "IPM.Schedule.Meeting.Canceled";
                $newmessageprops[$this->proptags['meetingstatus']] = olMeetingCanceled; // It's a cancel request
                $newmessageprops[$this->proptags['busystatus']] = fbFree; // set the busy status as free
            } else {
                $newmessageprops[PR_MESSAGE_CLASS] = "IPM.Schedule.Meeting.Request";
                $newmessageprops[$this->proptags['meetingstatus']] = olMeetingReceived; // The recipient is receiving the request
            }

            mapi_setprops($new, $newmessageprops);
            mapi_message_savechanges($new);

            // Submit message to non-resource recipients
            mapi_message_submitmessage($new);
        }

        // Send cancellation to deleted attendees
        if ($deletedRecips && !empty($deletedRecips)) {
            $new = $this->createOutgoingMessage();

            mapi_message_modifyrecipients($new, MODRECIP_ADD, $deletedRecips);

            $newmessageprops[PR_MESSAGE_CLASS] = "IPM.Schedule.Meeting.Canceled";
            $newmessageprops[$this->proptags['meetingstatus']] = olMeetingCanceled; // It's a cancel request
            $newmessageprops[$this->proptags['busystatus']] = fbFree; // set the busy status as free
            $newmessageprops[PR_IMPORTANCE] = IMPORTANCE_HIGH;    // HIGH Importance
            if (isset($newmessageprops[PR_SUBJECT])) {
                $newmessageprops[PR_SUBJECT] = _('Canceled: ') . $newmessageprops[PR_SUBJECT];
            }

            mapi_setprops($new, $newmessageprops);
            mapi_message_savechanges($new);

            // Submit message to non-resource recipients
            mapi_message_submitmessage($new);
        }

        // Set properties on meeting object in calendar
        // Set requestsent to 'true' (turns on 'tracking', etc)
        $props = array();
        $props[$this->proptags['meetingstatus']] = olMeeting;
        $props[$this->proptags['responsestatus']] = olResponseOrganized;
        $props[$this->proptags['requestsent']] = (!empty($recipients)) || ($this->includesResources && !$this->errorSetResource);
        $props[$this->proptags['attendee_critical_change']] = time();
        $props[$this->proptags['owner_critical_change']] = time();
        $props[$this->proptags['meetingtype']] = mtgRequest;
        // save the new updatecounter to exception/recurring series/normal meeting
        $props[$this->proptags['updatecounter']] = $newmessageprops[$this->proptags['updatecounter']];

        // PR_START_DATE and PR_END_DATE will be used by outlook to show the position in the calendar
        $props[PR_START_DATE] = $messageprops[$this->proptags['startdate']];
        $props[PR_END_DATE] = $messageprops[$this->proptags['duedate']];

        mapi_setprops($message, $props);

        // saving of these properties on calendar item should be handled by caller function
        // based on sending meeting request was successfull or not
    }

    /**
     * OL2007 uses these 4 properties to specify occurence that should be updated.
     * ical generates RECURRENCE-ID property based on exception's basedate (PidLidExceptionReplaceTime),
     * but OL07 doesn't send this property, so ical will generate RECURRENCE-ID property based on date
     * from GlobalObjId and time from StartRecurTime property, so we are sending basedate property and
     * also additionally we are sending these properties.
     * Ref: MS-OXCICAL 2.2.1.20.20 Property: RECURRENCE-ID
     * @param Object $recurObject instance of recurrence class for this message
     * @param Array $messageprops properties of meeting object that is going to be send
     * @param Array $newmessageprops properties of meeting request/response that is going to be send
     */
    function generateRecurDates($recurObject, $messageprops, &$newmessageprops)
    {
        if($messageprops[$this->proptags['startdate']] && $messageprops[$this->proptags['duedate']]) {
            $startDate = date("Y:n:j:G:i:s", $recurObject->fromGMT($recurObject->tz, $messageprops[$this->proptags['startdate']]));
            $endDate = date("Y:n:j:G:i:s", $recurObject->fromGMT($recurObject->tz, $messageprops[$this->proptags['duedate']]));

            $startDate = explode(":", $startDate);
            $endDate = explode(":", $endDate);

            // [0] => year, [1] => month, [2] => day, [3] => hour, [4] => minutes, [5] => seconds
            // RecurStartDate = year * 512 + month_number * 32 + day_number
            $newmessageprops[$this->proptags["start_recur_date"]] = (((int) $startDate[0]) * 512) + (((int) $startDate[1]) * 32) + ((int) $startDate[2]);
            // RecurStartTime = hour * 4096 + minutes * 64 + seconds
            $newmessageprops[$this->proptags["start_recur_time"]] = (((int) $startDate[3]) * 4096) + (((int) $startDate[4]) * 64) + ((int) $startDate[5]);

            $newmessageprops[$this->proptags["end_recur_date"]] = (((int) $endDate[0]) * 512) + (((int) $endDate[1]) * 32) + ((int) $endDate[2]);
            $newmessageprops[$this->proptags["end_recur_time"]] = (((int) $endDate[3]) * 4096) + (((int) $endDate[4]) * 64) + ((int) $endDate[5]);
        }
    }

    function createOutgoingMessage()
    {
        $sentprops = array();
        $outbox = $this->openDefaultOutbox($this->openDefaultStore());

        $outgoing = mapi_folder_createmessage($outbox);
        if(!$outgoing) return false;

        $addrinfo = $this->getOwnerAddress($this->store);
        if($addrinfo) {
            list($ownername, $owneremailaddr, $owneraddrtype, $ownerentryid, $ownersearchkey) = $addrinfo;
            $sentprops[PR_SENT_REPRESENTING_EMAIL_ADDRESS] = $owneremailaddr;
            $sentprops[PR_SENT_REPRESENTING_NAME] = $ownername;
            $sentprops[PR_SENT_REPRESENTING_ADDRTYPE] = $owneraddrtype;
            $sentprops[PR_SENT_REPRESENTING_ENTRYID] = $ownerentryid;
            $sentprops[PR_SENT_REPRESENTING_SEARCH_KEY] = $ownersearchkey;
        }

        $sentprops[PR_SENTMAIL_ENTRYID] = $this->getDefaultSentmailEntryID($this->openDefaultStore());

        mapi_setprops($outgoing, $sentprops);

        return $outgoing;
    }

    /**
     * Function which checks received meeting request is either old(outofdate) or new.
     * @return boolean true if meeting request is outofdate else false if it is new
     */
    function isMeetingOutOfDate()
    {
        $result = false;
        $store = $this->store;
        $props = mapi_getprops($this->message, array($this->proptags['goid'], $this->proptags['goid2'], $this->proptags['updatecounter'], $this->proptags['meetingtype'], $this->proptags['owner_critical_change']));

        if (isset($props[$this->proptags['meetingtype']]) && ($props[$this->proptags['meetingtype']] & mtgOutOfDate) == mtgOutOfDate) {
            return true;
        }

        // get the basedate to check for exception
        $basedate = $this->getBasedateFromGlobalID($props[$this->proptags['goid']]);

        $calendarItems = $this->getCorrespondedCalendarItems();

        foreach($calendarItems as $calendarItem) {
            if ($calendarItem) {
                $calendarItemProps = mapi_getprops($calendarItem, array(
                                                        $this->proptags['owner_critical_change'],
                                                        $this->proptags['updatecounter'],
                                                        $this->proptags['recurring']
                                        ));

                // If these items is recurring and basedate is found then open exception to compare it with meeting request
                if (isset($calendarItemProps[$this->proptags['recurring']]) && $calendarItemProps[$this->proptags['recurring']] && $basedate) {
                    $recurr = new Recurrence($store, $calendarItem);

                    if ($recurr->isException($basedate)) {
                        $attach = $recurr->getExceptionAttachment($basedate);
                        $exception = mapi_attach_openobj($attach, 0);
                        $occurrenceItemProps = mapi_getprops($exception, array(
                                                        $this->proptags['owner_critical_change'],
                                                        $this->proptags['updatecounter']
                                            ));
                    }

                    // we found the exception, compare with it
                    if(isset($occurrenceItemProps)) {
                        if ((isset($occurrenceItemProps[$this->proptags['updatecounter']]) && $props[$this->proptags['updatecounter']] < $occurrenceItemProps[$this->proptags['updatecounter']])
                            || (isset($occurrenceItemProps[$this->proptags['owner_critical_change']]) && $props[$this->proptags['owner_critical_change']] < $occurrenceItemProps[$this->proptags['owner_critical_change']])) {

                            mapi_setprops($this->message, array($this->proptags['meetingtype'] => mtgOutOfDate, PR_ICON_INDEX => 1033));
                            mapi_savechanges($this->message);
                            $result = true;
                        }
                    } else {
                        // we are not able to find exception, could mean that a significant change has occured on series
                        // and it deleted all exceptions, so compare with series
                        if ((isset($calendarItemProps[$this->proptags['updatecounter']]) && $props[$this->proptags['updatecounter']] < $calendarItemProps[$this->proptags['updatecounter']])
                            || (isset($calendarItemProps[$this->proptags['owner_critical_change']]) && $props[$this->proptags['owner_critical_change']] < $calendarItemProps[$this->proptags['owner_critical_change']])) {

                            mapi_setprops($this->message, array($this->proptags['meetingtype'] => mtgOutOfDate, PR_ICON_INDEX => 1033));
                            mapi_savechanges($this->message);
                            $result = true;
                        }
                    }
                } else {
                    // normal / recurring series
                    if ((isset($calendarItemProps[$this->proptags['updatecounter']]) && $props[$this->proptags['updatecounter']] < $calendarItemProps[$this->proptags['updatecounter']])
                            || (isset($calendarItemProps[$this->proptags['owner_critical_change']]) && $props[$this->proptags['owner_critical_change']] < $calendarItemProps[$this->proptags['owner_critical_change']])) {

                        mapi_setprops($this->message, array($this->proptags['meetingtype'] => mtgOutOfDate, PR_ICON_INDEX => 1033));
                        mapi_savechanges($this->message);
                        $result = true;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Function which checks received meeting request is updated later or not.
     * @return boolean true if meeting request is updated later.
     * @TODO: Implement handling for recurrings and exceptions.
     */
    function isMeetingUpdated()
    {
        $result = false;
        $store = $this->store;
        $props = mapi_getprops($this->message, array($this->proptags['goid'], $this->proptags['goid2'], $this->proptags['updatecounter'], $this->proptags['owner_critical_change'], $this->proptags['updatecounter']));

        $calendarItems = $this->getCorrespondedCalendarItems();

        foreach($calendarItems as $calendarItem) {
            if ($calendarItem) {
                $calendarItemProps = mapi_getprops($calendarItem, array(
                                                    $this->proptags['updatecounter'],
                                                    $this->proptags['recurring']
                                    ));

                if(isset($calendarItemProps[$this->proptags['updatecounter']]) && isset($props[$this->proptags['updatecounter']]) && $calendarItemProps[$this->proptags['updatecounter']] > $props[$this->proptags['updatecounter']]) {
                    $result = true;
                }
            }
        }

        return $result;
    }

    /**
     * Checks if there has been any significant changes on appointment/meeting item.
     * Significant changes be:
     * 1) startdate has been changed
     * 2) duedate has been changed OR
     * 3) recurrence pattern has been created, modified or removed
     *
     * @param Array oldProps old props before an update
     * @param Number basedate basedate
     * @param Boolean isRecurrenceChanged for change in recurrence pattern.
     * isRecurrenceChanged true means Recurrence pattern has been changed, so clear all attendees response
     */
    function checkSignificantChanges($oldProps, $basedate, $isRecurrenceChanged = false)
    {
        $message = null;
        $attach = null;

        // If basedate is specified then we need to open exception message to clear recipient responses
        if($basedate) {
            $recurrence = new Recurrence($this->store, $this->message);
            if($recurrence->isException($basedate)){
                $attach = $recurrence->getExceptionAttachment($basedate);
                if ($attach) {
                    $message = mapi_attach_openobj($attach, MAPI_MODIFY);
                }
            }
        } else {
            // use normal message or recurring series message
            $message = $this->message;
        }

        if(!$message) {
            return;
        }

        $newProps = mapi_getprops($message, array($this->proptags['startdate'], $this->proptags['duedate'], $this->proptags['updatecounter']));

        // Check whether message is updated or not.
        if(isset($newProps[$this->proptags['updatecounter']]) && $newProps[$this->proptags['updatecounter']] == 0) {
            return;
        }

        if (($newProps[$this->proptags['startdate']] != $oldProps[$this->proptags['startdate']])
            || ($newProps[$this->proptags['duedate']] != $oldProps[$this->proptags['duedate']])
            || $isRecurrenceChanged) {
            $this->clearRecipientResponse($message);

            mapi_setprops($message, array($this->proptags['owner_critical_change'] => time()));

            mapi_savechanges($message);
            if ($attach) { // Also save attachment Object.
                mapi_savechanges($attach);
            }
        }
    }

    /**
     * Clear responses of all attendees who have replied in past.
     * @param MAPI_MESSAGE $message on which responses should be cleared
     */
    function clearRecipientResponse($message)
    {
        $recipTable = mapi_message_getrecipienttable($message);
        $recipsRows = mapi_table_queryallrows($recipTable, $this->recipprops);

        foreach($recipsRows as $recipient) {
            if(($recipient[PR_RECIPIENT_FLAGS] & recipOrganizer) != recipOrganizer){
                // Recipient is attendee, set the trackstatus to "Not Responded"
                $recipient[PR_RECIPIENT_TRACKSTATUS] = olRecipientTrackStatusNone;
            } else {
                // Recipient is organizer, this is not possible, but for safety
                // it is best to clear the trackstatus for him as well by setting
                // the trackstatus to "Organized".
                $recipient[PR_RECIPIENT_TRACKSTATUS] = olRecipientTrackStatusNone;
            }
            mapi_message_modifyrecipients($message, MODRECIP_MODIFY, array($recipient));
        }
    }

    /**
     * Function returns corresponded calendar items attached with
     * the meeting request.
     * @return Array array of correlated calendar items.
     */
    function getCorrespondedCalendarItems()
    {
        $store = $this->store;
        $props = mapi_getprops($this->message, array($this->proptags['goid'], $this->proptags['goid2'], PR_RCVD_REPRESENTING_NAME));

        $basedate = $this->getBasedateFromGlobalID($props[$this->proptags['goid']]);

        // If Delegate is processing mr for Delegator then retrieve Delegator's store and calendar.
        if (isset($props[PR_RCVD_REPRESENTING_NAME])) {
            $delegatorStore = $this->getDelegatorStore($props);
            $store = $delegatorStore['store'];
            $calFolder = $delegatorStore['calFolder'];
        } else {
            $calFolder = $this->openDefaultCalendar();
        }

        // Finding item in calendar with GlobalID(0x3), not necessary that attendee is having recurring item, he/she can also have only a occurrence
        $entryids = $this->findCalendarItems($props[$this->proptags['goid']], $calFolder);

        // Basedate found, so this meeting request is an update of an occurrence.
        if ($basedate) {
            if (!$entryids) {
                // Find main recurring item in calendar with GlobalID(0x23)
                $entryids = $this->findCalendarItems($props[$this->proptags['goid2']], $calFolder);
            }
        }

        $calendarItems = array();
        if ($entryids) {
            foreach($entryids as $entryid) {
                $calendarItems[] = mapi_msgstore_openentry($store, $entryid);
            }
        }

        return $calendarItems;
    }

    /**
     * Function which checks whether received meeting request is either conflicting with other appointments or not.
     *@return mixed(boolean/integer) true if normal meeting is conflicting or an integer which specifies no of instances
     * conflict of recurring meeting and false if meeting is not conflicting.
     */
    function isMeetingConflicting($message = false, $userStore = false, $calFolder = false, $msgprops = false)
    {
        $returnValue = false;
        $conflicting = false;
        $noOfInstances = 0;

        if (!$message) $message = $this->message;

        if (!$userStore) $userStore = $this->store;

        if (!$calFolder) {
            $root = mapi_msgstore_openentry($userStore);
            $rootprops = mapi_getprops($root, array(PR_STORE_ENTRYID, PR_IPM_APPOINTMENT_ENTRYID, PR_FREEBUSY_ENTRYIDS));

            if(!isset($rootprops[PR_IPM_APPOINTMENT_ENTRYID])) {
                return;
            }

            $calFolder = mapi_msgstore_openentry($userStore, $rootprops[PR_IPM_APPOINTMENT_ENTRYID]);
        }

        if (!$msgprops) $msgprops = mapi_getprops($message, array($this->proptags['goid'], $this->proptags['goid2'], $this->proptags['startdate'], $this->proptags['duedate'], $this->proptags['recurring'], $this->proptags['clipstart'], $this->proptags['clipend']));

        if ($calFolder) {
            // Meeting request is recurring, so get all occurrence and check for each occurrence whether it conflicts with other appointments in Calendar.
            if (isset($msgprops[$this->proptags['recurring']]) && $msgprops[$this->proptags['recurring']]) {
                // Apply recurrence class and retrieve all occurrences(max: 30 occurrence because recurrence can also be set as 'no end date')
                $recurr = new Recurrence($userStore, $message);
                $items = $recurr->getItems($msgprops[$this->proptags['clipstart']], $msgprops[$this->proptags['clipend']] * (24*24*60), 30);

                foreach ($items as $item) {
                    // Get all items in the timeframe that we want to book, and get the goid and busystatus for each item
                    $calendarItems = $recurr->getCalendarItems($userStore, $calFolder, $item[$this->proptags['startdate']], $item[$this->proptags['duedate']], array($this->proptags['goid'], $this->proptags['busystatus'], PR_OWNER_APPT_ID));

                    foreach ($calendarItems as $calendarItem) {
                        if ($calendarItem[$this->proptags['busystatus']] != fbFree) {
                            /**
                             * Only meeting requests have globalID, normal appointments do not have globalID
                             * so if any normal appointment if found then it is assumed to be conflict.
                             */
                            if(isset($calendarItem[$this->proptags['goid']])) {
                                if ($calendarItem[$this->proptags['goid']] !== $msgprops[$this->proptags['goid']]) {
                                    $noOfInstances++;
                                    break;
                                }
                            } else {
                                $noOfInstances++;
                                break;
                            }
                        }
                    }
                }

                $returnValue = $noOfInstances;
            } else {
                // Get all items in the timeframe that we want to book, and get the goid and busystatus for each item
                $items = getCalendarItems($userStore, $calFolder, $msgprops[$this->proptags['startdate']], $msgprops[$this->proptags['duedate']], array($this->proptags['goid'], $this->proptags['busystatus'], PR_OWNER_APPT_ID));

                foreach($items as $item) {
                    if ($item[$this->proptags['busystatus']] != fbFree) {
                        if(isset($item[$this->proptags['goid']])) {
                            if (($item[$this->proptags['goid']] !== $msgprops[$this->proptags['goid']])
                                && ($item[$this->proptags['goid']] !== $msgprops[$this->proptags['goid2']])) {
                                $conflicting = true;
                                break;
                            }
                        } else {
                            $conflicting = true;
                            break;
                        }
                    }
                }

                if ($conflicting) $returnValue = true;
            }
        }
        return $returnValue;
    }

    /**
     *  Function which adds organizer to recipient list which is passed.
     *  This function also checks if it has organizer.
     *
     * @param array $messageProps message properties
     * @param array $recipients    recipients list of message.
     * @param boolean $isException true if we are processing recipient of exception
     */
    function addDelegator($messageProps, &$recipients)
    {
        $hasDelegator = false;
        // Check if meeting already has an organizer.
        foreach ($recipients as $key => $recipient){
            if (isset($messageProps[PR_RCVD_REPRESENTING_EMAIL_ADDRESS]) && $recipient[PR_EMAIL_ADDRESS] == $messageProps[PR_RCVD_REPRESENTING_EMAIL_ADDRESS])
                $hasDelegator = true;
        }

        if (!$hasDelegator){
            // Create delegator.
            $delegator = array();
            $delegator[PR_ENTRYID] = $messageProps[PR_RCVD_REPRESENTING_ENTRYID];
            $delegator[PR_DISPLAY_NAME] = $messageProps[PR_RCVD_REPRESENTING_NAME];
            $delegator[PR_EMAIL_ADDRESS] = $messageProps[PR_RCVD_REPRESENTING_EMAIL_ADDRESS];
            $delegator[PR_RECIPIENT_TYPE] = MAPI_TO;
            $delegator[PR_RECIPIENT_DISPLAY_NAME] = $messageProps[PR_RCVD_REPRESENTING_NAME];
            $delegator[PR_ADDRTYPE] = empty($messageProps[PR_RCVD_REPRESENTING_ADDRTYPE]) ? 'SMTP':$messageProps[PR_RCVD_REPRESENTING_ADDRTYPE];
            $delegator[PR_RECIPIENT_TRACKSTATUS] = olRecipientTrackStatusNone;
            $delegator[PR_RECIPIENT_FLAGS] = recipSendable;

            // Add organizer to recipients list.
            array_unshift($recipients, $delegator);
        }
    }

    function getDelegatorStore($messageprops)
    {
        // Find the organiser of appointment in addressbook
        $delegatorName = array(array(PR_DISPLAY_NAME => $messageprops[PR_RCVD_REPRESENTING_NAME]));
        $ab = mapi_openaddressbook($this->session);
        $user = mapi_ab_resolvename($ab, $delegatorName, EMS_AB_ADDRESS_LOOKUP);

        // Get StoreEntryID by username
        $delegatorEntryid = mapi_msgstore_createentryid($this->store, $user[0][PR_EMAIL_ADDRESS]);
        // Open store of the delegator
        $delegatorStore = mapi_openmsgstore($this->session, $delegatorEntryid);
        // Open root folder
        $delegatorRoot = mapi_msgstore_openentry($delegatorStore, null);
        // Get calendar entryID
        $delegatorRootProps = mapi_getprops($delegatorRoot, array(PR_IPM_APPOINTMENT_ENTRYID));
        // Open the calendar Folder
        $calFolder = mapi_msgstore_openentry($delegatorStore, $delegatorRootProps[PR_IPM_APPOINTMENT_ENTRYID]);

        return Array('store' => $delegatorStore, 'calFolder' => $calFolder);
    }

    /**
     * Function returns extra info about meeting timing along with message body
     * which will be included in body while sending meeting request/response.
     *
     * @return string $meetingTimeInfo info about meeting timing along with message body
     */
    function getMeetingTimeInfo()
    {
        return $this->meetingTimeInfo;
    }

    /**
     * Function sets extra info about meeting timing along with message body
     * which will be included in body while sending meeting request/response.
     *
     * @param string $meetingTimeInfo info about meeting timing along with message body
     */
    function setMeetingTimeInfo($meetingTimeInfo)
    {
        $this->meetingTimeInfo = $meetingTimeInfo;
    }
}
