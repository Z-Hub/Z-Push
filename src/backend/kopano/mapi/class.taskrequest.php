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

    /*
    * In general
    *
    * This class never actually modifies a task item unless we receive a task request update. This means
    * that setting all the properties to make the task item itself behave like a task request is up to the
    * caller.
    *
    * The only exception to this is the generation of the TaskGlobalObjId, the unique identifier identifying
    * this task request to both the organizer and the assignee. The globalobjectid is generated when the
    * task request is sent via sendTaskRequest.
    */

    /* The TaskMode value is only used for the IPM.TaskRequest items. It must 0 (tdmtNothing) on IPM.Task items.
    *
    * It is used to indicate the type of change that is being carried in the IPM.TaskRequest item (although this
    * information seems redundant due to that information already being available in PR_MESSAGE_CLASS).
    */
    define('tdmtNothing', 0);            // Value in IPM.Task items
    define('tdmtTaskReq', 1);            // Assigner -> Assignee
    define('tdmtTaskAcc', 2);            // Assignee -> Assigner
    define('tdmtTaskDec', 3);            // Assignee -> Assigner
    define('tdmtTaskUpd', 4);            // Assignee -> Assigner
    define('tdmtTaskSELF', 5);            // Assigner -> Assigner (?)

    /* The TaskHistory is used to show the last action on the task on both the assigner and the assignee's side.
    *
    * It is used in combination with 'AssignedTime' and 'tasklastdelegate' or 'tasklastuser' to show the information
    * at the top of the task request in the format 'Accepted by <user> on 01-01-2010 11:00'.
    */
    define('thNone', 0);
    define('thAccepted', 1);            // Set by assignee
    define('thDeclined', 2);            // Set by assignee
    define('thUpdated', 3);                // Set by assignee
    define('thDueDateChanged', 4);
    define('thAssigned', 5);            // Set by assigner

    /* The TaskState value is used to differentiate the version of a task in the assigner's folder and the version in the
    * assignee's folder. The buttons shown depend on this and the 'taskaccepted' boolean (for the assignee)
    */
    define('tdsNOM', 0);        // Got a response to a deleted task, and re-created the task for the assigner
    define('tdsOWNNEW', 1);        // Not assigned
    define('tdsOWN', 2);        // Assignee version
    define('tdsACC', 3);        // Assigner version
    define('tdsDEC', 4);        // Assigner version, but assignee declined

    /* The delegationstate is used for the assigner to indicate state
    */
    define('olTaskNotDelegated', 0);
    define('olTaskDelegationUnknown', 1); // After sending req
    define('olTaskDelegationAccepted', 2); // After receiving accept
    define('olTaskDelegationDeclined', 3); // After receiving decline

    /* The task ownership indicates the role of the current user relative to the task.
    */
    define('olNewTask', 0);
    define('olDelegatedTask', 1);    // Task has been assigned
    define('olOwnTask', 2);            // Task owned

    /* taskmultrecips indicates whether the task request sent or received has multiple assignees or not.
    */
    define('tmrNone', 0);
    define('tmrSent', 1);        // Task has been sent to multiple assignee
    define('tmrReceived', 2);    // Task Request received has multiple assignee

    class TaskRequest {

        // All recipient properties
        var $recipprops = Array(PR_ENTRYID, PR_DISPLAY_NAME, PR_EMAIL_ADDRESS, PR_RECIPIENT_ENTRYID, PR_RECIPIENT_TYPE, PR_SEND_INTERNET_ENCODING, PR_SEND_RICH_INFO, PR_RECIPIENT_DISPLAY_NAME, PR_ADDRTYPE, PR_DISPLAY_TYPE, PR_RECIPIENT_TRACKSTATUS, PR_RECIPIENT_TRACKSTATUS_TIME, PR_RECIPIENT_FLAGS, PR_ROWID, PR_SEARCH_KEY);

        /* Constructor
         *
         * Constructs a TaskRequest object for the specified message. This can be either the task request
         * message itself (in the inbox) or the task in the tasks folder, depending on the action to be performed.
         *
         * As a general rule, the object message passed is the object 'in view' when the user performs one of the
         * actions in this class.
         *
         * @param $store store MAPI Store in which $message resides. This is also the store where the tasks folder is assumed to be in
         * @param $message message MAPI Message to which the task request referes (can be an email or a task)
         * @param $session session MAPI Session which is used to open tasks folders for delegated task requests or responses
         */
        function __construct($store, $message, $session) {
            $this->store = $store;
            $this->message = $message;
            $this->session = $session;

            $properties["owner"] = "PT_STRING8:PSETID_Task:0x811f";
            $properties["updatecount"] = "PT_LONG:PSETID_Task:0x8112";
            $properties["taskstate"] = "PT_LONG:PSETID_Task:0x8113";
            $properties["taskmultrecips"] = "PT_LONG:PSETID_Task:0x8120";
            $properties["taskupdates"] = "PT_BOOLEAN:PSETID_Task:0x811b";
            $properties["tasksoc"] = "PT_BOOLEAN:PSETID_Task:0x8119";
            $properties["taskhistory"] = "PT_LONG:PSETID_Task:0x811a";
            $properties["taskmode"] = "PT_LONG:PSETID_Common:0x8518";
            $properties["taskglobalobjid"] = "PT_BINARY:PSETID_Common:0x8519";
            $properties["complete"] = "PT_BOOLEAN:PSETID_Common:0x811c";
            $properties["assignedtime"] = "PT_SYSTIME:PSETID_Task:0x8115";
            $properties["taskfcreator"] = "PT_BOOLEAN:PSETID_Task:0x0x811e";
            $properties["tasklastuser"] = "PT_STRING8:PSETID_Task:0x8122";
            $properties["tasklastdelegate"] = "PT_STRING8:PSETID_Task:0x8125";
            $properties["taskaccepted"] = "PT_BOOLEAN:PSETID_Task:0x8108";
            $properties["delegationstate"] = "PT_LONG:PSETID_Task:0x812a";
            $properties["ownership"] = "PT_LONG:PSETID_Task:0x8129";

            $properties["complete"] = "PT_BOOLEAN:PSETID_Task:0x811c";
            $properties["datecompleted"] = "PT_SYSTIME:PSETID_Task:0x810f";
            $properties["recurring"] = "PT_BOOLEAN:PSETID_Task:0x8126";
            $properties["startdate"] = "PT_SYSTIME:PSETID_Task:0x8104";
            $properties["duedate"] = "PT_SYSTIME:PSETID_Task:0x8105";
            $properties["status"] = "PT_LONG:PSETID_Task:0x8101";
            $properties["percent_complete"] = "PT_DOUBLE:PSETID_Task:0x8102";
            $properties["totalwork"] = "PT_LONG:PSETID_Task:0x8111";
            $properties["actualwork"] = "PT_LONG:PSETID_Task:0x8110";
            $properties["categories"] = "PT_MV_STRING8:PS_PUBLIC_STRINGS:Keywords";
            $properties["companies"] = "PT_MV_STRING8:PSETID_Common:0x8539";
            $properties["mileage"] = "PT_STRING8:PSETID_Common:0x8534";
            $properties["billinginformation"] = "PT_STRING8:PSETID_Common:0x8535";

            $this->props = getPropIdsFromStrings($store, $properties);
        }

        // General functions

        /* Return TRUE if the item is a task request message
         */
        function isTaskRequest()
        {
            $props = mapi_getprops($this->message, Array(PR_MESSAGE_CLASS));

            if(isset($props[PR_MESSAGE_CLASS]) && $props[PR_MESSAGE_CLASS] == "IPM.TaskRequest") {
                return true;
            }
        }

        /* Return TRUE if the item is a task response message
         */
        function isTaskRequestResponse() {
            $props = mapi_getprops($this->message, Array(PR_MESSAGE_CLASS));

            if(isset($props[PR_MESSAGE_CLASS]) && strpos($props[PR_MESSAGE_CLASS], "IPM.TaskRequest.") === 0) {
                return true;
            }
        }

        /*
         * Gets the task associated with an IPM.TaskRequest message
         *
         * If the task does not exist yet, it is created, using the attachment object in the
         * task request item.
         */
        function getAssociatedTask($create)
        {
            $props = mapi_getprops($this->message, array(PR_MESSAGE_CLASS, $this->props['taskglobalobjid']));

            if($props[PR_MESSAGE_CLASS] == "IPM.Task")
                return $this->message; // Message itself is task, so return that

            $tfolder = $this->getDefaultTasksFolder();
            $globalobjid = $props[$this->props['taskglobalobjid']];

            // Find the task by looking for the taskglobalobjid
            $restriction = array(RES_PROPERTY, array(RELOP => RELOP_EQ, ULPROPTAG => $this->props['taskglobalobjid'], VALUE => $globalobjid));

            $contents = mapi_folder_getcontentstable($tfolder);

            $rows = mapi_table_queryallrows($contents, array(PR_ENTRYID), $restriction);

            if(empty($rows)) {
                // None found, create one if possible
                if(!$create)
                    return false;

                $task = mapi_folder_createmessage($tfolder);

                $sub = $this->getEmbeddedTask($this->message);
                mapi_copyto($sub, array(), array(), $task);

                // Copy sender information from the e-mail
                $senderprops = mapi_getprops($this->message, array(PR_SENT_REPRESENTING_NAME, PR_SENT_REPRESENTING_EMAIL_ADDRESS, PR_SENT_REPRESENTING_ENTRYID, PR_SENT_REPRESENTING_ADDRTYPE, PR_SENT_REPRESENTING_SEARCH_KEY));
                mapi_setprops($task, $senderprops);

                $senderprops = mapi_getprops($this->message, array(PR_SENDER_NAME, PR_SENDER_EMAIL_ADDRESS, PR_SENDER_ENTRYID, PR_SENDER_ADDRTYPE, PR_SENDER_SEARCH_KEY));
                mapi_setprops($task, $senderprops);

            } else {
                // If there are multiple, just use the first
                $entryid = $rows[0][PR_ENTRYID];

                $store = $this->getTaskFolderStore();
                $task = mapi_msgstore_openentry($store, $entryid);
            }

            return $task;
        }



        // Organizer functions (called by the organizer)

        /* Processes a task request response, which can be any of the following:
         * - Task accept (task history is marked as accepted)
         * - Task decline (task history is marked as declined)
         * - Task update (updates completion %, etc)
         */
        function processTaskResponse() {
            $messageprops = mapi_getprops($this->message, array(PR_PROCESSED));
            if(isset($messageprops[PR_PROCESSED]) && $messageprops[PR_PROCESSED])
                return true;

            // Get the task for this response
            $task = $this->getAssociatedTask(false);

            if(!$task) {
                // Got a response for a task that has been deleted, create a new one and mark it as such
                $task = $this->getAssociatedTask(true);

                // tdsNOM indicates a task request that had gone missing
                mapi_setprops($task, array($this->props['taskstate'] => tdsNOM ));
            }

            // Get the embedded task information and copy it into our task
            $sub = $this->getEmbeddedTask($this->message);
            mapi_copyto($sub, array(), array($this->props['taskstate'], $this->props['taskhistory'], $this->props['taskmode'], $this->props['taskfcreator']), $task);

            $props = mapi_getprops($this->message, array(PR_MESSAGE_CLASS));

            // Set correct taskmode and taskhistory depending on response type
            switch($props[PR_MESSAGE_CLASS]) {
                case 'IPM.TaskRequest.Accept':
                    $taskhistory = thAccepted;
                    $taskstate = tdsACC;
                    $delegationstate =  olTaskDelegationAccepted;
                    break;
                case 'IPM.TaskRequest.Decline':
                    $taskhistory = thDeclined;
                    $taskstate = tdsDEC;
                    $delegationstate =  olTaskDelegationDeclined;
                    break;
                case 'IPM.TaskRequest.Update':
                    $taskhistory = thUpdated;
                    $taskstate = tdsACC; // Doesn't actually change anything
                    $delegationstate =  olTaskDelegationAccepted;
                    break;
            }

            // Update taskstate (what the task looks like) and task history (last action done by the assignee)
            mapi_setprops($task, array($this->props['taskhistory'] => $taskhistory, $this->props['taskstate'] => $taskstate, $this->props['delegationstate'] => $delegationstate, $this->props['ownership'] => olDelegatedTask));

            mapi_setprops($this->message, array(PR_PROCESSED => true));
            mapi_savechanges($task);

            return true;
        }

        /* Create a new message in the current user's outbox and submit it
         *
         * Takes the task passed in the constructor as the task to be sent; recipient should
         * be pre-existing. The task request will be sent to all recipients.
         */
        function sendTaskRequest($prefix) {
            // Generate a TaskGlobalObjectId
            $taskid = $this->createTGOID();
            $messageprops = mapi_getprops($this->message, array(PR_SUBJECT));

            // Set properties on Task Request
            mapi_setprops($this->message, array(
                $this->props['taskglobalobjid'] => $taskid, /* our new taskglobalobjid */
                $this->props['taskstate'] => tdsACC,         /* state for our outgoing request */
                $this->props['taskmode'] => tdmtNothing,     /* we're not sending a change */
                $this->props['updatecount'] => 2,            /* version 2 (no idea) */
                $this->props['delegationstate'] => olTaskDelegationUnknown, /* no reply yet */
                $this->props['ownership'] => olDelegatedTask, /* Task has been assigned */
                $this->props['taskhistory'] => thAssigned,    /* Task has been assigned */
                PR_ICON_INDEX => 1283                        /* Task request icon*/
            ));
            $this->setLastUser();
            $this->setOwnerForAssignor();
            mapi_savechanges($this->message);

            // Create outgoing task request message
            $outgoing = $this->createOutgoingMessage();
            // No need to copy attachments as task will be attached as embedded message.
            mapi_copyto($this->message, array(), array(PR_MESSAGE_ATTACHMENTS), $outgoing);

            // Make it a task request, and put it in sent items after it is sent
            mapi_setprops($outgoing, array(
                PR_MESSAGE_CLASS => "IPM.TaskRequest",         /* class is task request */
                $this->props['taskstate'] => tdsOWNNEW,     /* for the recipient the task is new */
                $this->props['taskmode'] => tdmtTaskReq,    /* for the recipient it's a request */
                $this->props['updatecount'] => 1,            /* version 2 is in the attachment */
                PR_SUBJECT => $prefix . $messageprops[PR_SUBJECT],
                PR_ICON_INDEX => 0xFFFFFFFF,                /* show assigned icon */
            ));

            // Set Body
            $body = $this->getBody();
            $stream = mapi_openproperty($outgoing, PR_BODY, IID_IStream, 0, MAPI_MODIFY | MAPI_CREATE);
            mapi_stream_setsize($stream, strlen($body));
            mapi_stream_write($stream, $body);
            mapi_stream_commit($stream);

            $attach = mapi_message_createattach($outgoing);
            mapi_setprops($attach, array(PR_ATTACH_METHOD => ATTACH_EMBEDDED_MSG, PR_DISPLAY_NAME => $messageprops[PR_SUBJECT]));

            $sub = mapi_attach_openproperty($attach, PR_ATTACH_DATA_OBJ, IID_IMessage, 0, MAPI_MODIFY | MAPI_CREATE);

            mapi_copyto($this->message, array(), array(), $sub);
            mapi_savechanges($sub);

            mapi_savechanges($attach);

            mapi_savechanges($outgoing);
            mapi_message_submitmessage($outgoing);
            return true;
        }

        // Assignee functions (called by the assignee)

        /* Update task version counter
         *
         * Must be called before each update to increase counter
         */
        function updateTaskRequest() {
            $messageprops = mapi_getprops($this->message, array($this->props['updatecount']));

            if(isset($messageprops)) {
                $messageprops[$this->props['updatecount']]++;
            } else {
                $messageprops[$this->props['updatecount']] = 1;
            }

            mapi_setprops($this->message, $messageprops);
        }

        /* Process a task request
         *
         * Message passed should be an IPM.TaskRequest message. The task request is then processed to create
         * the task in the tasks folder if needed.
         */
        function processTaskRequest() {
            if(!$this->isTaskRequest())
                return false;

            $messageprops = mapi_getprops($this->message, array(PR_PROCESSED));
            if (isset($messageprops[PR_PROCESSED]) && $messageprops[PR_PROCESSED])
                return true;

            $task = $this->getAssociatedTask(true);
            $taskProps = mapi_getprops($task, array($this->props['taskmultrecips']));

            // Set the task state to say that we're the attendee receiving the message, that we have not yet responded and that this message represents no change
            $taskProps[$this->props["taskstate"]] = tdsOWN;
            $taskProps[$this->props["taskhistory"]] = thAssigned;
            $taskProps[$this->props["taskmode"]] = tdmtNothing;
            $taskProps[$this->props["taskaccepted"]] = false;
            $taskProps[$this->props["taskfcreator"]] = false;
            $taskProps[$this->props["ownership"]] = olOwnTask;
            $taskProps[$this->props["delegationstate"]] = olTaskNotDelegated;
            $taskProps[PR_ICON_INDEX] = 1282;

            // This task was assigned to multiple recips, so set this user as owner
            if (isset($taskProps[$this->props['taskmultrecips']]) && $taskProps[$this->props['taskmultrecips']] == tmrSent) {
                $loginUserData = $this->retrieveUserData();

                if ($loginUserData) {
                    $taskProps[$this->props['owner']] = $loginUserData[PR_DISPLAY_NAME];
                    $taskProps[$this->props['taskmultrecips']] = tmrReceived;
                }
            }
            mapi_setprops($task, $taskProps);

            $this->setAssignorInRecipients($task);

            mapi_savechanges($task);

            $taskprops = mapi_getprops($task, array(PR_ENTRYID));

            mapi_setprops($this->message, array(PR_PROCESSED => true));
            mapi_savechanges($this->message);

            return $taskprops[PR_ENTRYID];
        }

        /* Accept a task request and send the response.
         *
         * Message passed should be an IPM.Task (eg the task from getAssociatedTask())
         *
         * Copies the task to the user's task folder, sets it to accepted, and sends the acceptation
         * message back to the organizer. The caller is responsible for removing the message.
         *
         * @return entryid EntryID of the accepted task
         */
        function doAccept($prefix) {
            $messageprops = mapi_getprops($this->message, array($this->props['taskstate']));

            if(!isset($messageprops[$this->props['taskstate']]) || $messageprops[$this->props['taskstate']] != tdsOWN)
                return false; // Can only accept assignee task

            $this->setLastUser();
            $this->updateTaskRequest();

            // Set as accepted
            mapi_setprops($this->message, array($this->props['taskhistory'] => thAccepted, $this->props['assignedtime'] => time(), $this->props['taskaccepted'] => true,  $this->props['delegationstate'] => olTaskNotDelegated));

            mapi_savechanges($this->message);

            $this->sendResponse(tdmtTaskAcc, $prefix);

            //@TODO: delete received task request from Inbox
            return $this->deleteReceivedTR();
        }

        /* Decline a task request and send the response.
         *
         * Passed message must be a task request message, ie isTaskRequest() must return TRUE.
         *
         * Sends the decline message back to the organizer. The caller is responsible for removing the message.
         *
         * @return boolean TRUE on success, FALSE on failure
         */
        function doDecline($prefix) {
            $messageprops = mapi_getprops($this->message, array($this->props['taskstate']));

            if(!isset($messageprops[$this->props['taskstate']]) || $messageprops[$this->props['taskstate']] != tdsOWN)
                return false; // Can only decline assignee task

            $this->setLastUser();
            $this->updateTaskRequest();

            // Set as declined
            mapi_setprops($this->message, array($this->props['taskhistory'] => thDeclined,  $this->props['delegationstate'] => olTaskDelegationDeclined));

            mapi_savechanges($this->message);

            $this->sendResponse(tdmtTaskDec, $prefix);

            return $this->deleteReceivedTR();
        }

        /* Send an update of the task if requested, and send the Status-On-Completion report if complete and requested
         *
         * If no updates were requested from the organizer, this function does nothing.
         *
         * @return boolean TRUE if the update succeeded, FALSE otherwise.
         */
        function doUpdate($prefix, $prefixComplete) {
            $messageprops = mapi_getprops($this->message, array($this->props['taskstate'], PR_SUBJECT));

            if(!isset($messageprops[$this->props['taskstate']]) || $messageprops[$this->props['taskstate']] != tdsOWN)
                return false; // Can only update assignee task

            $this->setLastUser();
            $this->updateTaskRequest();

            // Set as updated
            mapi_setprops($this->message, array($this->props['taskhistory'] => thUpdated));

            mapi_savechanges($this->message);

            $props = mapi_getprops($this->message, array($this->props['taskupdates'], $this->props['tasksoc'], $this->props['recurring'], $this->props['complete']));
            if ($props[$this->props['taskupdates']] && !(isset($props[$this->props['recurring']]) && $props[$this->props['recurring']]))
                $this->sendResponse(tdmtTaskUpd, $prefix);

            if($props[$this->props['tasksoc']]  && $props[$this->props['complete']] ) {
                $outgoing = $this->createOutgoingMessage();

                mapi_setprops($outgoing, array(PR_SUBJECT => $prefixComplete . $messageprops[PR_SUBJECT]));

                $this->setRecipientsForResponse($outgoing, tdmtTaskUpd, true);
                $body = $this->getBody();
                $stream = mapi_openproperty($outgoing, PR_BODY, IID_IStream, 0, MAPI_CREATE | MAPI_MODIFY);
                mapi_stream_setsize($stream, strlen($body));
                mapi_stream_write($stream, $body);
                mapi_stream_commit($stream);

                mapi_savechanges($outgoing);
                mapi_message_submitmessage($outgoing);
            }
        }

        // Internal functions

        /* Get the store associated with the task
         *
         * Normally this will just open the store that the processed message is in. However, if the message is opened
         * by a delegate, this function opens the store that the message was delegated from.
         */
        function getTaskFolderStore()
        {
            $ownerentryid = false;

            $rcvdprops = mapi_getprops($this->message, array(PR_RCVD_REPRESENTING_ENTRYID));
            if(isset($rcvdprops[PR_RCVD_REPRESENTING_ENTRYID])) {
                $ownerentryid = $rcvdprops;
            }

            if(!$ownerentryid) {
                $store = $this->store;
            } else {
                $ab = mapi_openaddressbook($this->session); // seb changed from $session to $this->session
                if(!$ab) return false; // manni $ before ab was missing

                $mailuser = mapi_ab_openentry($ab, $ownerentryid);
                if(!$mailuser) return false;

                $mailuserprops = mapi_getprops($mailuser, array(PR_EMAIL_ADDRESS));
                if(!isset($mailuserprops[PR_EMAIL_ADDRESS])) return false;

                $storeid = mapi_msgstore_createentryid($this->store, $mailuserprops[PR_EMAIL_ADDRESS]);

                $store = mapi_openmsgstore($this->session, $storeid);

            }
            return $store;
        }

        /* Open the default task folder for the current user, or the specified user if passed
         *
         * @param $ownerentryid (Optional)EntryID of user for which we are opening the task folder
         */
        function getDefaultTasksFolder()
        {
            $store = $this->getTaskFolderStore();

            $inbox = mapi_msgstore_getreceivefolder($store);
            $inboxprops = mapi_getprops($inbox, Array(PR_IPM_TASK_ENTRYID));
            if(!isset($inboxprops[PR_IPM_TASK_ENTRYID]))
                return false;

            return mapi_msgstore_openentry($store, $inboxprops[PR_IPM_TASK_ENTRYID]);
        }

        function getSentReprProps($store)
        {
            $storeprops = mapi_getprops($store, array(PR_MAILBOX_OWNER_ENTRYID));
            if(!isset($storeprops[PR_MAILBOX_OWNER_ENTRYID])) return false;

            $ab = mapi_openaddressbook($this->session);
            $mailuser = mapi_ab_openentry($ab, $storeprops[PR_MAILBOX_OWNER_ENTRYID]);
            $mailuserprops = mapi_getprops($mailuser, array(PR_ADDRTYPE, PR_EMAIL_ADDRESS, PR_DISPLAY_NAME, PR_SEARCH_KEY, PR_ENTRYID));

            $props = array();
            $props[PR_SENT_REPRESENTING_ADDRTYPE] = $mailuserprops[PR_ADDRTYPE];
            $props[PR_SENT_REPRESENTING_EMAIL_ADDRESS] = $mailuserprops[PR_EMAIL_ADDRESS];
            $props[PR_SENT_REPRESENTING_NAME] = $mailuserprops[PR_DISPLAY_NAME];
            $props[PR_SENT_REPRESENTING_SEARCH_KEY] = $mailuserprops[PR_SEARCH_KEY];
            $props[PR_SENT_REPRESENTING_ENTRYID] = $mailuserprops[PR_ENTRYID];

            return $props;
        }

        /*
         * Creates an outgoing message based on the passed message - will set delegate information
         * and sentmail folder
         */
        function createOutgoingMessage()
        {
            // Open our default store for this user (that's the only store we can submit in)
            $store = $this->getDefaultStore();
            $storeprops = mapi_getprops($store, array(PR_IPM_OUTBOX_ENTRYID, PR_IPM_SENTMAIL_ENTRYID));

            $outbox = mapi_msgstore_openentry($store, $storeprops[PR_IPM_OUTBOX_ENTRYID]);
            if(!$outbox) return false;

            $outgoing = mapi_folder_createmessage($outbox);
            if(!$outgoing) return false;

            // Set SENT_REPRESENTING in case we're sending as a delegate
            $ownerstore = $this->getTaskFolderStore();
            $sentreprprops = $this->getSentReprProps($ownerstore);
            mapi_setprops($outgoing, $sentreprprops);

            mapi_setprops($outgoing, array(PR_SENTMAIL_ENTRYID => $storeprops[PR_IPM_SENTMAIL_ENTRYID]));

            return $outgoing;
        }

        /*
         * Send a response message (from assignee back to organizer).
         *
         * @param $type int Type of response (tdmtTaskAcc, tdmtTaskDec, tdmtTaskUpd);
         * @return boolean TRUE on success
         */
        function sendResponse($type, $prefix)
        {
            // Create a message in our outbox
            $outgoing = $this->createOutgoingMessage();

            $messageprops = mapi_getprops($this->message, array(PR_SUBJECT));

            $attach = mapi_message_createattach($outgoing);
            mapi_setprops($attach, array(PR_ATTACH_METHOD => ATTACH_EMBEDDED_MSG, PR_DISPLAY_NAME => $messageprops[PR_SUBJECT], PR_ATTACHMENT_HIDDEN => true));
            $sub = mapi_attach_openproperty($attach, PR_ATTACH_DATA_OBJ, IID_IMessage, 0, MAPI_CREATE | MAPI_MODIFY);

            mapi_copyto($this->message, array(), array(PR_SENT_REPRESENTING_NAME, PR_SENT_REPRESENTING_EMAIL_ADDRESS, PR_SENT_REPRESENTING_ADDRTYPE, PR_SENT_REPRESENTING_ENTRYID, PR_SENT_REPRESENTING_SEARCH_KEY), $outgoing);
            mapi_copyto($this->message, array(), array(), $sub);

            if (!$this->setRecipientsForResponse($outgoing, $type)) return false;

            switch($type) {
                case tdmtTaskAcc:
                    $messageclass = "IPM.TaskRequest.Accept";
                    break;
                case tdmtTaskDec:
                    $messageclass = "IPM.TaskRequest.Decline";
                    break;
                case tdmtTaskUpd:
                    $messageclass = "IPM.TaskRequest.Update";
                    break;
            };

            mapi_savechanges($sub);
            mapi_savechanges($attach);

            // Set Body
            $body = $this->getBody();
            $stream = mapi_openproperty($outgoing, PR_BODY, IID_IStream, 0, MAPI_CREATE | MAPI_MODIFY);
            mapi_stream_setsize($stream, strlen($body));
            mapi_stream_write($stream, $body);
            mapi_stream_commit($stream);

            // Set subject, taskmode, message class, icon index, response time
            mapi_setprops($outgoing, array(PR_SUBJECT => $prefix . $messageprops[PR_SUBJECT],
                                            $this->props['taskmode'] => $type,
                                            PR_MESSAGE_CLASS => $messageclass,
                                            PR_ICON_INDEX => 0xFFFFFFFF,
                                            $this->props['assignedtime'] => time()));

            mapi_savechanges($outgoing);
            mapi_message_submitmessage($outgoing);

            return true;
        }

        function getDefaultStore()
        {
            $table = mapi_getmsgstorestable($this->session);
            $rows = mapi_table_queryallrows($table, array(PR_DEFAULT_STORE, PR_ENTRYID));

            foreach($rows as $row) {
                if($row[PR_DEFAULT_STORE])
                    return mapi_openmsgstore($this->session, $row[PR_ENTRYID]);
            }

            return false;
        }

        /* Creates a new TaskGlobalObjId
         *
         * Just 16 bytes of random data
         */
        function createTGOID()
        {
            $goid = "";
            for($i=0;$i<16;$i++) {
                $goid .= chr(rand(0, 255));
            }
            return $goid;
        }

        function getEmbeddedTask($message) {
            $table = mapi_message_getattachmenttable($message);
            $rows = mapi_table_queryallrows($table, array(PR_ATTACH_NUM));

            // Assume only one attachment
            if(empty($rows))
                return false;

            $attach = mapi_message_openattach($message, $rows[0][PR_ATTACH_NUM]);
            $message = mapi_openproperty($attach, PR_ATTACH_DATA_OBJ, IID_IMessage, 0, 0);

            return $message;
        }

        function setLastUser() {
            $delegatestore = $this->getDefaultStore();
            $taskstore = $this->getTaskFolderStore();

            $delegateprops = mapi_getprops($delegatestore, array(PR_MAILBOX_OWNER_NAME));
            $taskprops = mapi_getprops($taskstore, array(PR_MAILBOX_OWNER_NAME));

            // The owner of the task
            $username = $delegateprops[PR_MAILBOX_OWNER_NAME];
            // This is me (the one calling the script)
            $delegate = $taskprops[PR_MAILBOX_OWNER_NAME];

            mapi_setprops($this->message, array($this->props["tasklastuser"] => $username, $this->props["tasklastdelegate"] => $delegate, $this->props['assignedtime'] => time()));
        }

        /** Assignee becomes the owner when a user/assignor assigns any task to someone. Also there can be more than one assignee.
         * This function sets assignee as owner in the assignor's copy of task.
         */
        function setOwnerForAssignor()
        {
            $recipTable = mapi_message_getrecipienttable($this->message);
            $recips = mapi_table_queryallrows($recipTable, array(PR_DISPLAY_NAME));

            if (!empty($recips)) {
                $owner = array();
                foreach ($recips as $value) {
                    $owner[] = $value[PR_DISPLAY_NAME];
                }

                $props = array($this->props['owner'] => implode("; ", $owner));
                mapi_setprops($this->message, $props);
            }
        }

        /** Sets assignor as recipients in assignee's copy of task.
         *
         * If assignor has requested task updates then the assignor is added as recipient type MAPI_CC.
         *
         * Also if assignor has request SOC then the assignor is also add as recipient type MAPI_BCC
         *
         * @param $task message MAPI message which assignee's copy of task
         */
        function setAssignorInRecipients($task)
        {
            $recipTable = mapi_message_getrecipienttable($task);

            // Delete all MAPI_TO recipients
            $recips = mapi_table_queryallrows($recipTable, array(PR_ROWID), array(RES_PROPERTY,
                                                                                array(    RELOP => RELOP_EQ,
                                                                                        ULPROPTAG => PR_RECIPIENT_TYPE,
                                                                                        VALUE => MAPI_TO
                                                                                )));
            foreach($recips as $recip)
                mapi_message_modifyrecipients($task, MODRECIP_REMOVE, array($recip));

            $recips = array();
            $taskReqProps = mapi_getprops($this->message, array(PR_SENT_REPRESENTING_NAME, PR_SENT_REPRESENTING_EMAIL_ADDRESS, PR_SENT_REPRESENTING_ENTRYID, PR_SENT_REPRESENTING_ADDRTYPE));
            $associatedTaskProps = mapi_getprops($task, array($this->props['taskupdates'], $this->props['tasksoc'], $this->props['taskmultrecips']));

            // Build assignor info
            $assignor = array(    PR_ENTRYID => $taskReqProps[PR_SENT_REPRESENTING_ENTRYID],
                                PR_DISPLAY_NAME => $taskReqProps[PR_SENT_REPRESENTING_NAME],
                                PR_EMAIL_ADDRESS => $taskReqProps[PR_SENT_REPRESENTING_EMAIL_ADDRESS],
                                PR_RECIPIENT_DISPLAY_NAME => $taskReqProps[PR_SENT_REPRESENTING_NAME],
                                PR_ADDRTYPE => empty($taskReqProps[PR_SENT_REPRESENTING_ADDRTYPE]) ? 'SMTP' : $taskReqProps[PR_SENT_REPRESENTING_ADDRTYPE],
                                PR_RECIPIENT_FLAGS => recipSendable
                        );

            // Assignor has requested task updates, so set him/her as MAPI_CC in recipienttable.
            if ((isset($associatedTaskProps[$this->props['taskupdates']]) && $associatedTaskProps[$this->props['taskupdates']])
                && !(isset($associatedTaskProps[$this->props['taskmultrecips']]) && $associatedTaskProps[$this->props['taskmultrecips']] == tmrReceived)) {
                $assignor[PR_RECIPIENT_TYPE] = MAPI_CC;
                $recips[] = $assignor;
            }

            // Assignor wants to receive an email report when task is mark as 'Complete', so in recipients as MAPI_BCC
            if (isset($associatedTaskProps[$this->props['taskupdates']]) && $associatedTaskProps[$this->props['tasksoc']]) {
                $assignor[PR_RECIPIENT_TYPE] = MAPI_BCC;
                $recips[] = $assignor;
            }

            if (!empty($recips))
                mapi_message_modifyrecipients($task, MODRECIP_ADD, $recips);
        }

        /** Returns user information who has task request
         */
        function retrieveUserData()
        {
            // get user entryid
            $storeProps = mapi_getprops($this->store, array(PR_USER_ENTRYID));
            if (!$storeProps[PR_USER_ENTRYID])    return false;

            $ab = mapi_openaddressbook($this->session);
            // open the user entry
            $user = mapi_ab_openentry($ab, $storeProps[PR_USER_ENTRYID]);
            if (!$user) return false;

            // receive userdata
            $userProps = mapi_getprops($user, array(PR_DISPLAY_NAME));
            if (!$userProps[PR_DISPLAY_NAME]) return false;

            return $userProps;
        }

        /** Deletes incoming task request from Inbox
         *
         * @returns array returns PR_ENTRYID, PR_STORE_ENTRYID and PR_PARENT_ENTRYID of the deleted task request
         */
        function deleteReceivedTR()
        {
            $store = $this->getTaskFolderStore();
            $inbox = mapi_msgstore_getreceivefolder($store);

            $storeProps = mapi_getprops($store, array(PR_IPM_WASTEBASKET_ENTRYID));
            $props = mapi_getprops($this->message, array($this->props['taskglobalobjid']));
            $globalobjid = $props[$this->props['taskglobalobjid']];

            // Find the task by looking for the taskglobalobjid
            $restriction = array(RES_PROPERTY, array(RELOP => RELOP_EQ, ULPROPTAG => $this->props['taskglobalobjid'], VALUE => $globalobjid));

            $contents = mapi_folder_getcontentstable($inbox);

            $rows = mapi_table_queryallrows($contents, array(PR_ENTRYID, PR_PARENT_ENTRYID, PR_STORE_ENTRYID), $restriction);

            $taskrequest = false;
            if(!empty($rows)) {
                // If there are multiple, just use the first
                $entryid = $rows[0][PR_ENTRYID];
                $wastebasket = mapi_msgstore_openentry($store, $storeProps[PR_IPM_WASTEBASKET_ENTRYID]);
                mapi_folder_copymessages($inbox, Array($entryid), $wastebasket, MESSAGE_MOVE);

                return array(PR_ENTRYID => $entryid, PR_PARENT_ENTRYID => $rows[0][PR_PARENT_ENTRYID], PR_STORE_ENTRYID => $rows[0][PR_STORE_ENTRYID]);
            }

            return false;
        }

        /** Converts already sent task request to normal task
         */
        function createUnassignedCopy()
        {
            mapi_deleteprops($this->message, array($this->props['taskglobalobjid']));
            mapi_setprops($this->message, array($this->props['updatecount'] => 1));

            // Remove all recipents
            $this->deleteAllRecipients($this->message);
        }

        /** Sets recipients for the outgoing message according to type of the response.
         *
         * If it is a task update, then only recipient type MAPI_CC are taken from the task message.
         *
         * If it is accept/decline response, then PR_SENT_REPRESENTATING_XXXX are taken as recipient.
         *
         *@param $outgoing MAPI_message outgoing mapi message
         *@param $responseType String response type
         *@param $sendSOC Boolean true if sending complete response else false.
         */
        function setRecipientsForResponse($outgoing, $responseType, $sendSOC = false)
        {
            // Clear recipients from outgoing msg
            $this->deleteAllRecipients($outgoing);

            // If it is a task update then get MAPI_CC recipients which are assignors who has asked for task update.
            if ($responseType == tdmtTaskUpd) {
                $recipTable = mapi_message_getrecipienttable($this->message);
                $recips = mapi_table_queryallrows($recipTable, $this->recipprops, array(RES_PROPERTY,
                                                                                        array(    RELOP => RELOP_EQ,
                                                                                                ULPROPTAG => PR_RECIPIENT_TYPE,
                                                                                                VALUE => ($sendSOC ? MAPI_BCC : MAPI_CC)
                                                                                        )
                                                ));

                // No recipients found, return error
                if (empty($recips))
                    return false;

                foreach($recips as $recip) {
                    $recip[PR_RECIPIENT_TYPE] = MAPI_TO;    // Change recipient type to MAPI_TO
                    mapi_message_modifyrecipients($outgoing, MODRECIP_ADD, array($recip));
                }
                return true;
            }

            $orgprops = mapi_getprops($this->message, array(PR_SENT_REPRESENTING_NAME, PR_SENT_REPRESENTING_EMAIL_ADDRESS, PR_SENT_REPRESENTING_ADDRTYPE, PR_SENT_REPRESENTING_ENTRYID, PR_SUBJECT));
            $recip = array(PR_DISPLAY_NAME => $orgprops[PR_SENT_REPRESENTING_NAME], PR_EMAIL_ADDRESS => $orgprops[PR_SENT_REPRESENTING_EMAIL_ADDRESS], PR_ADDRTYPE => $orgprops[PR_SENT_REPRESENTING_ADDRTYPE], PR_ENTRYID => $orgprops[PR_SENT_REPRESENTING_ENTRYID], PR_RECIPIENT_TYPE => MAPI_TO);

            mapi_message_modifyrecipients($outgoing, MODRECIP_ADD, array($recip));

            return true;
        }

        /** Adds task details to message body and returns body.
         *
         *@return string contructed body with task details.
         */
        function getBody()
        {
            //@TODO: Fix translations

            $msgProps = mapi_getprops($this->message);
            $body = "";

            if (isset($msgProps[PR_SUBJECT])) $body .= "\n" . _("Subject") . ":\t". $msgProps[PR_SUBJECT];
            if (isset($msgProps[$this->props['startdate']])) $body .= "\n" . _("Start Date") . ":\t". strftime(_("%A, %B %d, %Y"),$msgProps[$this->props['startdate']]);
            if (isset($msgProps[$this->props['duedate']])) $body .= "\n" . _("Due Date") . ":\t". strftime(_("%A, %B %d, %Y"),$msgProps[$this->props['duedate']]);
            $body .= "\n";

            if (isset($msgProps[$this->props['status']])) {
                $body .= "\n" . _("Status") . ":\t";
                if ($msgProps[$this->props['status']] == 0) $body .= _("Not Started");
                else if ($msgProps[$this->props['status']] == 1) $body .= _("In Progress");
                else if ($msgProps[$this->props['status']] == 2) $body .= _("Complete");
                else if ($msgProps[$this->props['status']] == 3) $body .= _("Wait for other person");
                else if ($msgProps[$this->props['status']] == 4) $body .= _("Deferred");
            }

            if (isset($msgProps[$this->props['percent_complete']])) {
                $body .= "\n" . _("Percent Complete") . ":\t". ($msgProps[$this->props['percent_complete']] * 100).'%';

                if ($msgProps[$this->props['percent_complete']] == 1 && isset($msgProps[$this->props['datecompleted']]))
                    $body .= "\n" . _("Date Completed") . ":\t". strftime("%A, %B %d, %Y",$msgProps[$this->props['datecompleted']]);
            }
            $body .= "\n";

            if (isset($msgProps[$this->props['totalwork']])) $body .= "\n" . _("Total Work") . ":\t". ($msgProps[$this->props['totalwork']]/60) ." " . _("hours");
            if (isset($msgProps[$this->props['actualwork']])) $body .= "\n" . _("Actual Work") . ":\t". ($msgProps[$this->props['actualwork']]/60) ." " . _("hours");
            $body .="\n";

            if (isset($msgProps[$this->props['owner']])) $body .= "\n" . _("Owner") . ":\t". $msgProps[$this->props['owner']];
            $body .="\n";

            if (isset($msgProps[$this->props['categories']]) && !empty($msgProps[$this->props['categories']])) $body .= "\nCategories:\t". implode(', ', $msgProps[$this->props['categories']]);
            if (isset($msgProps[$this->props['companies']]) && !empty($msgProps[$this->props['companies']])) $body .= "\nCompany:\t". implode(', ', $msgProps[$this->props['companies']]);
            if (isset($msgProps[$this->props['billinginformation']])) $body .= "\n" . _("Billing Information") . ":\t". $msgProps[$this->props['billinginformation']];
            if (isset($msgProps[$this->props['mileage']])) $body .= "\n" . _("Mileage") . ":\t". $msgProps[$this->props['mileage']];
            $body .="\n";

            $content = mapi_message_openproperty($this->message, PR_BODY);
            $body .= "\n". trim($content, "\0");

            return $body;
        }

        /**
        * Convert from windows-1252 encoded string to UTF-8 string
        *
        * The same conversion rules as utf8_to_windows1252 apply.
        *
        * @see Conversion::utf8_to_windows1252()
        *
        * @param string $string the Windows-1252 string to convert
        * @return string UTF-8 representation of the string
        */
        function windows1252_to_utf8($string)
        {
            if (function_exists("iconv")){
                return iconv("Windows-1252", "UTF-8//TRANSLIT", $string);
            }else{
                return utf8_encode($string); // no euro support here
            }
        }

        /** Reclaims ownership of a decline task
         *
         * Deletes taskrequest properties and recipients from the task message.
         */
        function reclaimownership()
        {
            // Delete task request properties
            mapi_deleteprops($this->message, array($this->props['taskglobalobjid'],
                                                    $this->props['tasklastuser'],
                                                    $this->props['tasklastdelegate']));

            mapi_setprops($this->message, array($this->props['updatecount'] => 2,
                                                $this->props['taskfcreator'] => true));

            // Delete recipients
            $this->deleteAllRecipients($this->message);
        }

        /** Deletes all recipients from given message object
         *
         *@param $message MAPI message from which recipients are to be removed.
         */
        function deleteAllRecipients($message)
        {
            $recipTable = mapi_message_getrecipienttable($message);
            $recipRows = mapi_table_queryallrows($recipTable, array(PR_ROWID));

            foreach($recipRows as $recipient)
                mapi_message_modifyrecipients($message, MODRECIP_REMOVE, array($recipient));
        }

        function sendCompleteUpdate($prefix, $action, $prefixComplete)
        {
            $messageprops = mapi_getprops($this->message, array($this->props['taskstate']));

            if(!isset($messageprops[$this->props['taskstate']]) || $messageprops[$this->props['taskstate']] != tdsOWN)
                return false; // Can only decline assignee task

            mapi_setprops($this->message, array($this->props['complete'] => true,
                                                $this->props['datecompleted'] => $action["dateCompleted"],
                                                $this->props['status'] => 2,
                                                $this->props['percent_complete'] => 1));

            $this->doUpdate($prefix, $prefixComplete);
        }
    }
