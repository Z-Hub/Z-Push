<?php
/***********************************************
* File      :   jmap.php
* Project   :   Z-Push
* Descr     :   JMAP backend for Z-Push. Implements the BackendDiff
*               pattern so no custom importer/exporter is needed.
*               Supports mail, contacts (JSContact) and calendars
*               (JSCalendar) via Stalwart or any RFC-compliant JMAP
*               server.
*
* Copyright 2024 - Z-Push Contributors
* AGPL-3.0 - see LICENSE
************************************************/

require_once 'backend/jmap/config.php';
require_once 'backend/jmap/jmap_client.php';
require_once 'backend/jmap/jmap_contacts.php';
require_once 'backend/jmap/jmap_calendar.php';

class BackendJmap extends BackendDiff {

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    const KW_SEEN      = '$seen';
    const KW_FLAGGED   = '$flagged';
    const KW_ANSWERED  = '$answered';
    const KW_FORWARDED = '$forwarded';
    const KW_DRAFT     = '$draft';

    // Folder-ID prefixes used to distinguish resource types.
    // Mail folders keep their raw JMAP IDs for backwards compatibility.
    const PFX_CONTACTS = 'ab_';
    const PFX_CALENDAR = 'cal_';

    private const ROLE_TO_AS_TYPE = [
        'inbox'   => SYNC_FOLDER_TYPE_INBOX,
        'drafts'  => SYNC_FOLDER_TYPE_DRAFTS,
        'trash'   => SYNC_FOLDER_TYPE_WASTEBASKET,
        'sent'    => SYNC_FOLDER_TYPE_SENTMAIL,
        'junk'    => SYNC_FOLDER_TYPE_USER_MAIL,
        'spam'    => SYNC_FOLDER_TYPE_USER_MAIL,
        'archive' => SYNC_FOLDER_TYPE_USER_MAIL,
    ];

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    private ?JmapClient $client     = null;
    private string      $accountId  = '';
    private string      $username   = '';
    private string      $identityId   = '';
    private string      $identityName = '';

    private array $sinkStates     = [];
    private bool  $sinkInitialized = false;

    // -------------------------------------------------------------------------
    // IBackend: Auth
    // -------------------------------------------------------------------------

    public function Logon($username, $domain, $password): bool {
        if (!function_exists('curl_init')) {
            throw new FatalException('BackendJmap->Logon(): php-curl is required', 0, null, LOGLEVEL_FATAL);
        }

        $this->username = $username;
        $this->client   = new JmapClient(JMAP_SESSION_URL, $username, $password);

        try {
            // One HTTP request — fetches session and verifies credentials.
            // identityId is lazy-loaded only when SendMail() needs it.
            $this->accountId = $this->client->getAccountId();
            ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                'BackendJmap->Logon(): user="%s" accountId="%s"',
                $username, $this->accountId
            ));
            return true;
        } catch (AuthenticationRequiredException $e) {
            ZLog::Write(LOGLEVEL_WARN, sprintf('BackendJmap->Logon(): auth failed for "%s"', $username));
            return false;
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf('BackendJmap->Logon(): %s', $e->getMessage()));
            return false;
        }
    }

    public function Logoff(): bool {
        $this->SaveStorages();
        $this->client    = null;
        $this->accountId = '';
        return true;
    }

    public function GetSupportedASVersion(): string {
        return ZPush::ASV_14;
    }

    // -------------------------------------------------------------------------
    // IBackend: Mail sending
    // -------------------------------------------------------------------------

    public function SendMail($sm): bool {
        ZLog::Write(LOGLEVEL_DEBUG, 'BackendJmap->SendMail()');

        if (empty($sm->mime)) {
            throw new StatusException('BackendJmap->SendMail(): empty MIME', SYNC_COMMONSTATUS_MAILSUBMISSIONFAILED);
        }

        try {
            $mime = $sm->mime;
            $identityName = $this->getIdentityName();
            if ($identityName !== '') {
                $mime = $this->rewriteFromHeader($mime, $identityName);
            }

            $blobId        = $this->client->uploadBlob($mime, 'message/rfc822');
            $sentMailboxId = $this->getSentMailboxId();
            $saveCopy      = !isset($sm->saveinsentitems) || $sm->saveinsentitems;

            $mailboxIds = $sentMailboxId ? [$sentMailboxId => true] : [];

            $importResponses = $this->client->call([
                ['Email/import', [
                    'accountId' => $this->accountId,
                    'emails'    => ['i1' => [
                        'blobId'     => $blobId,
                        'mailboxIds' => $mailboxIds ?: [$this->accountId => true],
                        'keywords'   => [self::KW_SEEN => true],
                        'receivedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                    ]],
                ], 'im'],
            ], [JmapClient::CAP_CORE, JmapClient::CAP_MAIL]);

            $importedId = $importResponses[0][1]['created']['i1']['id'] ?? null;
            if ($importedId === null) {
                $err = $importResponses[0][1]['notCreated']['i1'] ?? 'unknown';
                throw new StatusException(
                    sprintf('BackendJmap->SendMail(): import failed: %s', json_encode($err)),
                    SYNC_COMMONSTATUS_MAILSUBMISSIONFAILED
                );
            }

            $subResponses = $this->client->call([
                ['EmailSubmission/set', [
                    'accountId' => $this->accountId,
                    'create'    => ['s1' => [
                        'emailId'    => $importedId,
                        'identityId' => $this->getIdentityId(),
                        'envelope'   => null,
                    ]],
                ], 'sub'],
            ], [JmapClient::CAP_CORE, JmapClient::CAP_MAIL, JmapClient::CAP_SUBMIT]);

            $notCreated = $subResponses[0][1]['notCreated'] ?? [];
            if (!empty($notCreated)) {
                $err = reset($notCreated);
                throw new StatusException(
                    sprintf('BackendJmap->SendMail(): submission failed: %s', json_encode($err)),
                    SYNC_COMMONSTATUS_MAILSUBMISSIONFAILED
                );
            }

            if (isset($sm->source->itemid)) {
                $kw = [];
                if (!empty($sm->replyflag))   $kw[self::KW_ANSWERED]  = true;
                if (!empty($sm->forwardflag)) $kw[self::KW_FORWARDED] = true;
                if ($kw) $this->setKeywords($sm->source->itemid, $kw);
            }

            return true;
        } catch (StatusException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new StatusException(
                sprintf('BackendJmap->SendMail(): %s', $e->getMessage()),
                SYNC_COMMONSTATUS_MAILSUBMISSIONFAILED
            );
        }
    }

    // -------------------------------------------------------------------------
    // IBackend: Attachment download
    // -------------------------------------------------------------------------

    public function GetAttachmentData($attname): SyncItemOperationsAttachment {
        $parts  = explode('||', $attname, 3);
        $blobId = $parts[1] ?? '';
        $name   = $parts[2] ?? 'attachment';

        if (!$blobId) {
            throw new StatusException(
                sprintf('BackendJmap->GetAttachmentData(): bad ref "%s"', $attname),
                SYNC_ITEMOPERATIONSSTATUS_INVALIDATT
            );
        }

        try {
            $data = $this->client->downloadBlob($blobId, $name);
        } catch (\Throwable $e) {
            throw new StatusException(
                sprintf('BackendJmap->GetAttachmentData(): %s', $e->getMessage()),
                SYNC_ITEMOPERATIONSSTATUS_INVALIDATT
            );
        }

        $attachment              = new SyncItemOperationsAttachment();
        $attachment->data        = StringStreamWrapper::Open($data);
        $attachment->contenttype = $this->extToMime(strtolower(pathinfo($name, PATHINFO_EXTENSION)));
        return $attachment;
    }

    // -------------------------------------------------------------------------
    // IBackend: Waste basket & sink
    // -------------------------------------------------------------------------

    public function GetWasteBasket(): string|false {
        try {
            return $this->getMailboxIdByRole('trash') ?: false;
        } catch (\Throwable) {
            return false;
        }
    }

    public function HasChangesSink(): bool { return true; }

    public function ChangesSinkInitialize($folderid): bool {
        try {
            $this->sinkStates[$folderid] = $this->getFolderQueryState($folderid);
            $this->sinkInitialized = true;
            return true;
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf('BackendJmap->ChangesSinkInitialize(): %s', $e->getMessage()));
            return false;
        }
    }

    public function ChangesSink($timeout = 30): array {
        if (!$this->sinkInitialized || !$this->sinkStates) return [];

        $start   = time();
        $changed = [];

        while (time() - $start < $timeout) {
            try {
                foreach ($this->sinkStates as $folderid => $saved) {
                    $current = $this->getFolderQueryState($folderid);
                    if ($current !== $saved) {
                        $this->sinkStates[$folderid] = $current;
                        $changed[] = $folderid;
                    }
                }
            } catch (\Throwable $e) {
                ZLog::Write(LOGLEVEL_WARN, sprintf('BackendJmap->ChangesSink(): %s', $e->getMessage()));
            }

            if ($changed) return $changed;
            sleep(5);
        }
        return [];
    }

    // -------------------------------------------------------------------------
    // BackendDiff: Folder list / hierarchy
    // -------------------------------------------------------------------------

    public function GetFolderList(): array {
        try {
            $out = [];

            // Mail mailboxes
            foreach ($this->getAllMailboxes() as $mb) {
                $out[] = ['id' => $mb['id'], 'parent' => $mb['parentId'] ?? false, 'mod' => $mb['id']];
            }

            // Address books — prefixed with PFX_CONTACTS
            foreach ($this->getAllAddressBooks() as $ab) {
                $out[] = ['id' => self::PFX_CONTACTS . $ab['id'], 'parent' => false, 'mod' => $ab['id']];
            }

            // Calendars — prefixed with PFX_CALENDAR
            foreach ($this->getAllCalendars() as $cal) {
                $out[] = ['id' => self::PFX_CALENDAR . $cal['id'], 'parent' => false, 'mod' => $cal['id']];
            }

            return $out;
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf('BackendJmap->GetFolderList(): %s', $e->getMessage()));
            return [];
        }
    }

    public function GetFolder($id): SyncFolder|false {
        try {
            return match($this->folderType($id)) {
                'contacts' => $this->getAddressBookAsFolder($id),
                'calendar' => $this->getCalendarAsFolder($id),
                default    => $this->getMailboxAsFolder($id),
            };
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf('BackendJmap->GetFolder("%s"): %s', $id, $e->getMessage()));
            return false;
        }
    }

    public function StatFolder($id): array|false {
        try {
            $jid = $this->jmapId($id);
            return ['id' => $id, 'parent' => false, 'mod' => $jid];
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function ChangeFolder($folderid, $oldid, $displayname, $type): string|false {
        try {
            if (in_array($type, [SYNC_FOLDER_TYPE_CONTACT, SYNC_FOLDER_TYPE_USER_CONTACT], true)) {
                return $this->changeAddressBook($oldid, $displayname);
            }
            if (in_array($type, [SYNC_FOLDER_TYPE_APPOINTMENT, SYNC_FOLDER_TYPE_USER_APPOINTMENT], true)) {
                return $this->changeCalendar($oldid, $displayname);
            }
            return $this->changeMailbox($folderid, $oldid, $displayname);
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf('BackendJmap->ChangeFolder(): %s', $e->getMessage()));
            return false;
        }
    }

    public function DeleteFolder($id, $parentid): bool {
        try {
            return match($this->folderType($id)) {
                'contacts' => $this->deleteAddressBook($id),
                'calendar' => $this->deleteCalendar($id),
                default    => $this->deleteMailbox($id),
            };
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf('BackendJmap->DeleteFolder("%s"): %s', $id, $e->getMessage()));
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // BackendDiff: Message list & individual messages
    // -------------------------------------------------------------------------

    public function GetMessageList($folderid, $cutoffdate): array {
        try {
            return match($this->folderType($folderid)) {
                'contacts' => $this->listContacts($this->jmapId($folderid)),
                'calendar' => $this->listCalendarEvents($this->jmapId($folderid), $cutoffdate),
                default    => $this->listEmails($folderid, $cutoffdate),
            };
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf('BackendJmap->GetMessageList("%s"): %s', $folderid, $e->getMessage()));
            return [];
        }
    }

    public function GetMessage($folderid, $id, $contentparameters): SyncObject|false {
        try {
            return match($this->folderType($folderid)) {
                'contacts' => $this->getContact($id),
                'calendar' => $this->getCalendarEvent($id),
                default    => $this->getEmail($folderid, $id, $contentparameters),
            };
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf('BackendJmap->GetMessage("%s","%s"): %s', $folderid, $id, $e->getMessage()));
            return false;
        }
    }

    public function StatMessage($folderid, $id): array|false {
        try {
            return match($this->folderType($folderid)) {
                'contacts' => $this->statContact($id),
                'calendar' => $this->statCalendarEvent($id),
                default    => $this->statEmail($folderid, $id),
            };
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf('BackendJmap->StatMessage("%s","%s"): %s', $folderid, $id, $e->getMessage()));
            return false;
        }
    }

    public function ChangeMessage($folderid, $id, $message, $contentParameters): string|false {
        try {
            return match($this->folderType($folderid)) {
                'contacts' => $this->saveContact($this->jmapId($folderid), $id, $message),
                'calendar' => $this->saveCalendarEvent($this->jmapId($folderid), $id, $message),
                default    => $this->saveDraft($folderid, $id, $message, $contentParameters),
            };
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf('BackendJmap->ChangeMessage("%s","%s"): %s', $folderid, $id, $e->getMessage()));
            return false;
        }
    }

    public function SetReadFlag($folderid, $id, $flags, $contentParameters): bool {
        if ($this->folderType($folderid) !== 'mail') return true; // no-op for contacts/calendar
        try {
            return $this->setKeywords($id, [self::KW_SEEN => ($flags != 0)]);
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf('BackendJmap->SetReadFlag(): %s', $e->getMessage()));
            return false;
        }
    }

    public function DeleteMessage($folderid, $id, $contentParameters): bool {
        try {
            return match($this->folderType($folderid)) {
                'contacts' => $this->destroyContact($id),
                'calendar' => $this->destroyCalendarEvent($id),
                default    => $this->deleteEmail($folderid, $id, $contentParameters),
            };
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf('BackendJmap->DeleteMessage(): %s', $e->getMessage()));
            return false;
        }
    }

    public function MoveMessage($folderid, $id, $newfolderid, $contentParameters): string|false {
        try {
            return match($this->folderType($folderid)) {
                'contacts' => $this->moveContact($id, $this->jmapId($folderid), $this->jmapId($newfolderid)),
                'calendar' => $this->moveCalendarEvent($id, $this->jmapId($folderid), $this->jmapId($newfolderid)),
                default    => $this->moveEmail($folderid, $id, $newfolderid),
            };
        } catch (\Throwable $e) {
            ZLog::Write(LOGLEVEL_ERROR, sprintf('BackendJmap->MoveMessage(): %s', $e->getMessage()));
            return false;
        }
    }

    // =========================================================================
    // MAIL helpers
    // =========================================================================

    private function listEmails(string $folderid, int $cutoffdate): array {
        $filter = ['inMailbox' => $folderid];
        if ($cutoffdate > 0) $filter['after'] = gmdate('Y-m-d\TH:i:s\Z', $cutoffdate);

        $r = $this->client->call([
            ['Email/query', [
                'accountId' => $this->accountId,
                'filter'    => $filter,
                'sort'      => [['property' => 'receivedAt', 'isAscending' => false]],
                'limit'     => JMAP_MAX_OBJECTS_PER_REQUEST,
            ], 'q'],
            ['Email/get', [
                'accountId'  => $this->accountId,
                '#ids'       => ['resultOf' => 'q', 'name' => 'Email/query', 'path' => '/ids'],
                'properties' => ['id', 'keywords'],
            ], 'g'],
        ]);

        return array_map(
            fn($e) => $this->emailToStat($e),
            $r[1][1]['list'] ?? []
        );
    }

    private function getEmail(string $folderid, string $id, $contentparameters): SyncMail|false {
        $r = $this->client->call([
            ['Email/get', [
                'accountId'          => $this->accountId,
                'ids'                => [$id],
                'properties'         => [
                    'id', 'blobId', 'mailboxIds', 'keywords', 'size',
                    'receivedAt', 'subject', 'from', 'to', 'cc', 'bcc', 'replyTo',
                    'hasAttachment', 'textBody', 'htmlBody', 'attachments', 'bodyValues',
                ],
                'fetchAllBodyValues' => true,
                ...(JMAP_MAX_BODY_BYTES > 0 ? ['maxBodyValueBytes' => JMAP_MAX_BODY_BYTES] : []),
            ], '0'],
        ]);

        $email = $r[0][1]['list'][0] ?? null;
        return $email ? $this->buildSyncMail($email, $contentparameters) : false;
    }

    private function statEmail(string $folderid, string $id): array|false {
        $r = $this->client->call([
            ['Email/get', ['accountId' => $this->accountId, 'ids' => [$id], 'properties' => ['id', 'keywords']], '0'],
        ]);
        $email = $r[0][1]['list'][0] ?? null;
        return $email ? $this->emailToStat($email) : false;
    }

    private function saveDraft(string $folderid, ?string $id, $message, $contentParameters): string|false {
        if (empty($message->mime)) return false;

        $blobId = $this->client->uploadBlob($message->mime, 'message/rfc822');
        $folder = $this->GetFolder($folderid);
        $kw     = ($folder && $folder->type === SYNC_FOLDER_TYPE_DRAFTS) ? [self::KW_DRAFT => true] : [];

        $r = $this->client->call([
            ['Email/import', [
                'accountId' => $this->accountId,
                'emails'    => ['n1' => [
                    'blobId'     => $blobId,
                    'mailboxIds' => [$folderid => true],
                    'keywords'   => $kw,
                    'receivedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                ]],
            ], '0'],
        ]);

        $created = $r[0][1]['created']['n1'] ?? null;
        if (!$created) return false;

        if ($id) $this->deleteEmail($folderid, $id, $contentParameters);
        return $created['id'];
    }

    private function deleteEmail(string $folderid, string $id, $contentParameters): bool {
        $trashId = $this->GetWasteBasket();
        if ($trashId && $folderid !== $trashId) {
            return $this->moveEmail($folderid, $id, $trashId) !== false;
        }
        $r = $this->client->call([
            ['Email/set', ['accountId' => $this->accountId, 'destroy' => [$id]], '0'],
        ]);
        return in_array($id, $r[0][1]['destroyed'] ?? [], true);
    }

    private function moveEmail(string $folderid, string $id, string $newfolderid): string|false {
        $r = $this->client->call([
            ['Email/set', [
                'accountId' => $this->accountId,
                'update'    => [$id => [
                    "mailboxIds/$folderid"    => null,
                    "mailboxIds/$newfolderid" => true,
                ]],
            ], '0'],
        ]);
        return isset($r[0][1]['updated'][$id]) ? $id : false;
    }

    private function emailToStat(array $email): array {
        $kw = $email['keywords'] ?? [];
        return [
            'id'    => $email['id'],
            'flags' => isset($kw[self::KW_SEEN]) ? 1 : 0,
            'mod'   => $this->keywordsHash($kw),
        ];
    }

    private function buildSyncMail(array $email, $contentparameters): SyncMail {
        $output = new SyncMail();
        $output->subject      = $email['subject']  ?? '';
        $output->from         = $this->formatAddressList($email['from']    ?? []);
        $output->to           = $this->formatAddressList($email['to']      ?? []);
        $cc      = $this->formatAddressList($email['cc']      ?? []);
        $bcc     = $this->formatAddressList($email['bcc']     ?? []);
        $replyTo = $this->formatAddressList($email['replyTo'] ?? []);
        if ($cc)      $output->cc       = $cc;
        if ($bcc)     $output->bcc      = $bcc;
        if ($replyTo) $output->reply_to = $replyTo;
        $output->datereceived = $this->parseJmapDate($email['receivedAt']  ?? '');
        $output->messageclass = 'IPM.Note';
        $output->read         = isset($email['keywords'][self::KW_SEEN]) ? 1 : 0;

        $bodypreference = $contentparameters->GetBodyPreference() ?: [];
        [$bodyType, $bodyData] = $this->selectBody($email, $bodypreference);
        $truncsize = Utils::GetTruncSize($contentparameters->GetTruncation());

        $output->asbody = new SyncBaseBody();
        $output->asbody->type = $bodyType;
        if ($truncsize !== -1 && strlen($bodyData) > $truncsize) {
            $bodyData = substr($bodyData, 0, $truncsize);
            $output->asbody->truncated = 1;
        } else {
            $output->asbody->truncated = 0;
        }
        $output->asbody->estimatedDataSize = strlen($bodyData);
        $output->asbody->data              = StringStreamWrapper::Open($bodyData);

        foreach ($email['attachments'] ?? [] as $att) {
            $blobId   = $att['blobId'] ?? '';
            $filename = $att['name']   ?? ('attachment-' . substr($blobId, 0, 8));
            $inline   = strtolower($att['disposition'] ?? '') === 'inline';
            $sa = new SyncBaseAttachment();
            $sa->displayname       = $filename;
            $sa->filereference     = $email['id'] . '||' . $blobId . '||' . $filename;
            $sa->method            = 1;
            $sa->estimatedDataSize = $att['size'] ?? 0;
            $sa->isinline          = $inline ? 1 : 0;
            if (!empty($att['cid'])) $sa->contentid = $att['cid'];
            $output->asattachments[] = $sa;
        }

        return $output;
    }

    private function selectBody(array $email, array $bodypreference): array {
        $bodyValues = $email['bodyValues'] ?? [];

        if (in_array(SYNC_BODYPREFERENCE_MIME, $bodypreference, true) && !empty($email['blobId'])) {
            try {
                $mime = $this->client->downloadBlob($email['blobId'], 'message.eml', 'message/rfc822');
                if ($mime !== '') {
                    return [SYNC_BODYPREFERENCE_MIME, $mime];
                }
                ZLog::Write(LOGLEVEL_WARN, sprintf('BackendJmap->selectBody(): empty MIME blob for email %s, falling back to HTML/text', $email['id'] ?? '?'));
            } catch (\Throwable $e) {
                ZLog::Write(LOGLEVEL_WARN, 'BackendJmap->selectBody(): MIME download failed: ' . $e->getMessage());
            }
        }

        // Prefer HTML if the client wants it, or if no specific preference (also used as
        // fallback when MIME failed — we try HTML/text regardless of original preference).
        $wantHtml = !$bodypreference
            || in_array(SYNC_BODYPREFERENCE_HTML,  $bodypreference, true)
            || in_array(SYNC_BODYPREFERENCE_MIME,  $bodypreference, true);
        if ($wantHtml && !empty($email['htmlBody'])) {
            $partId = $email['htmlBody'][0]['partId'] ?? null;
            if ($partId && isset($bodyValues[$partId]['value'])) {
                return [SYNC_BODYPREFERENCE_HTML, $bodyValues[$partId]['value']];
            }
        }

        if (!empty($email['textBody'])) {
            $partId = $email['textBody'][0]['partId'] ?? null;
            if ($partId && isset($bodyValues[$partId]['value'])) {
                return [SYNC_BODYPREFERENCE_PLAIN, $bodyValues[$partId]['value']];
            }
        }

        // bodyValues was empty or partIds didn't match — last resort: download raw MIME
        if (!empty($email['blobId'])) {
            try {
                $mime = $this->client->downloadBlob($email['blobId'], 'message.eml', 'message/rfc822');
                if ($mime !== '') {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf('BackendJmap->selectBody(): used MIME fallback for email %s', $email['id'] ?? '?'));
                    return [SYNC_BODYPREFERENCE_MIME, $mime];
                }
            } catch (\Throwable $e) {
                ZLog::Write(LOGLEVEL_WARN, 'BackendJmap->selectBody(): MIME fallback failed: ' . $e->getMessage());
            }
        }

        ZLog::Write(LOGLEVEL_WARN, sprintf(
            'BackendJmap->selectBody(): no usable body for email %s (htmlBody=%d textBody=%d bodyValues=%d)',
            $email['id'] ?? '?',
            count($email['htmlBody'] ?? []),
            count($email['textBody'] ?? []),
            count($bodyValues)
        ));
        return [SYNC_BODYPREFERENCE_PLAIN, ''];
    }

    // =========================================================================
    // CONTACTS helpers
    // =========================================================================

    private function listContacts(string $abId): array {
        $r = $this->client->call([
            ['ContactCard/query', [
                'accountId' => $this->accountId,
                'filter'    => ['inAddressBook' => $abId],
                'limit'     => JMAP_MAX_OBJECTS_PER_REQUEST,
            ], 'q'],
            ['ContactCard/get', [
                'accountId'  => $this->accountId,
                '#ids'       => ['resultOf' => 'q', 'name' => 'ContactCard/query', 'path' => '/ids'],
                'properties' => ['id', 'name', 'emails', 'phones', 'updated'],
            ], 'g'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);

        return array_map(fn($c) => [
            'id'    => $c['id'],
            'flags' => 0,
            'mod'   => JmapContactConverter::cardModHash($c),
        ], $r[1][1]['list'] ?? []);
    }

    private function getContact(string $id): SyncContact|false {
        $r = $this->client->call([
            ['ContactCard/get', [
                'accountId'  => $this->accountId,
                'ids'        => [$id],
                'properties' => null,
            ], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);

        $card = $r[0][1]['list'][0] ?? null;
        return $card ? JmapContactConverter::cardToSyncContact($card) : false;
    }

    private function statContact(string $id): array|false {
        $r = $this->client->call([
            ['ContactCard/get', [
                'accountId'  => $this->accountId,
                'ids'        => [$id],
                'properties' => ['id', 'name', 'emails', 'phones', 'updated'],
            ], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);

        $card = $r[0][1]['list'][0] ?? null;
        if (!$card) return false;
        return ['id' => $card['id'], 'flags' => 0, 'mod' => JmapContactConverter::cardModHash($card)];
    }

    private function saveContact(string $abId, ?string $id, SyncContact $contact): string|false {
        $card = JmapContactConverter::syncContactToCard($contact, $abId);

        if ($id) {
            $r = $this->client->call([
                ['ContactCard/set', [
                    'accountId' => $this->accountId,
                    'update'    => [$id => $card],
                ], '0'],
            ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
            return isset($r[0][1]['updated'][$id]) ? $id : false;
        }

        $r = $this->client->call([
            ['ContactCard/set', [
                'accountId' => $this->accountId,
                'create'    => ['c1' => $card],
            ], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);

        return $r[0][1]['created']['c1']['id'] ?? false;
    }

    private function destroyContact(string $id): bool {
        $r = $this->client->call([
            ['ContactCard/set', ['accountId' => $this->accountId, 'destroy' => [$id]], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
        return in_array($id, $r[0][1]['destroyed'] ?? [], true);
    }

    private function moveContact(string $id, string $fromAbId, string $toAbId): string|false {
        $r = $this->client->call([
            ['ContactCard/set', [
                'accountId' => $this->accountId,
                'update'    => [$id => [
                    "addressBookIds/$fromAbId" => null,
                    "addressBookIds/$toAbId"   => true,
                ]],
            ], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
        return isset($r[0][1]['updated'][$id]) ? $id : false;
    }

    // =========================================================================
    // CALENDAR helpers
    // =========================================================================

    private function listCalendarEvents(string $calId, int $cutoffdate): array {
        $filter = ['inCalendar' => $calId];
        if ($cutoffdate > 0) {
            $filter['after'] = gmdate('Y-m-d\TH:i:s\Z', $cutoffdate);
        }

        $r = $this->client->call([
            ['CalendarEvent/query', [
                'accountId' => $this->accountId,
                'filter'    => $filter,
                'limit'     => JMAP_MAX_OBJECTS_PER_REQUEST,
            ], 'q'],
            ['CalendarEvent/get', [
                'accountId'  => $this->accountId,
                '#ids'       => ['resultOf' => 'q', 'name' => 'CalendarEvent/query', 'path' => '/ids'],
                'properties' => ['id', 'title', 'start', 'duration', 'updated'],
            ], 'g'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);

        return array_map(fn($e) => [
            'id'    => $e['id'],
            'flags' => 0,
            'mod'   => JmapCalendarConverter::eventModHash($e),
        ], $r[1][1]['list'] ?? []);
    }

    private function getCalendarEvent(string $id): SyncAppointment|false {
        $r = $this->client->call([
            ['CalendarEvent/get', [
                'accountId'  => $this->accountId,
                'ids'        => [$id],
                'properties' => null,
            ], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);

        $event = $r[0][1]['list'][0] ?? null;
        return $event ? JmapCalendarConverter::eventToSyncAppointment($event) : false;
    }

    private function statCalendarEvent(string $id): array|false {
        $r = $this->client->call([
            ['CalendarEvent/get', [
                'accountId'  => $this->accountId,
                'ids'        => [$id],
                'properties' => ['id', 'title', 'start', 'duration', 'updated'],
            ], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);

        $event = $r[0][1]['list'][0] ?? null;
        if (!$event) return false;
        return ['id' => $event['id'], 'flags' => 0, 'mod' => JmapCalendarConverter::eventModHash($event)];
    }

    private function saveCalendarEvent(string $calId, ?string $id, SyncAppointment $appt): string|false {
        $event = JmapCalendarConverter::syncAppointmentToEvent($appt, $calId);

        if ($id) {
            $r = $this->client->call([
                ['CalendarEvent/set', [
                    'accountId' => $this->accountId,
                    'update'    => [$id => $event],
                ], '0'],
            ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
            return isset($r[0][1]['updated'][$id]) ? $id : false;
        }

        $r = $this->client->call([
            ['CalendarEvent/set', [
                'accountId' => $this->accountId,
                'create'    => ['ev1' => $event],
            ], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);

        return $r[0][1]['created']['ev1']['id'] ?? false;
    }

    private function destroyCalendarEvent(string $id): bool {
        $r = $this->client->call([
            ['CalendarEvent/set', ['accountId' => $this->accountId, 'destroy' => [$id]], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
        return in_array($id, $r[0][1]['destroyed'] ?? [], true);
    }

    private function moveCalendarEvent(string $id, string $fromCalId, string $toCalId): string|false {
        $r = $this->client->call([
            ['CalendarEvent/set', [
                'accountId' => $this->accountId,
                'update'    => [$id => [
                    "calendarIds/$fromCalId" => null,
                    "calendarIds/$toCalId"   => true,
                ]],
            ], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
        return isset($r[0][1]['updated'][$id]) ? $id : false;
    }

    // =========================================================================
    // Folder CRUD helpers
    // =========================================================================

    private function getMailboxAsFolder(string $id): SyncFolder|false {
        $r = $this->client->call([
            ['Mailbox/get', ['accountId' => $this->accountId, 'ids' => [$id]], '0'],
        ]);
        $mb = $r[0][1]['list'][0] ?? null;
        if (!$mb) return false;
        $f = new SyncFolder();
        $f->serverid    = $mb['id'];
        $f->parentid    = $mb['parentId'] ?? '0';
        $f->displayname = $mb['name'];
        $f->type        = self::ROLE_TO_AS_TYPE[$mb['role'] ?? ''] ?? SYNC_FOLDER_TYPE_USER_MAIL;
        return $f;
    }

    private function getAddressBookAsFolder(string $id): SyncFolder|false {
        $jid = $this->jmapId($id);
        $r   = $this->client->call([
            ['AddressBook/get', ['accountId' => $this->accountId, 'ids' => [$jid]], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
        $ab = $r[0][1]['list'][0] ?? null;
        if (!$ab) return false;
        $f = new SyncFolder();
        $f->serverid    = $id;
        $f->parentid    = '0';
        $f->displayname = $ab['name'];
        $f->type        = !empty($ab['isDefault']) ? SYNC_FOLDER_TYPE_CONTACT : SYNC_FOLDER_TYPE_USER_CONTACT;
        return $f;
    }

    private function getCalendarAsFolder(string $id): SyncFolder|false {
        $jid = $this->jmapId($id);
        $r   = $this->client->call([
            ['Calendar/get', ['accountId' => $this->accountId, 'ids' => [$jid]], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
        $cal = $r[0][1]['list'][0] ?? null;
        if (!$cal) return false;
        $f = new SyncFolder();
        $f->serverid    = $id;
        $f->parentid    = '0';
        $f->displayname = $cal['name'];
        $f->type        = !empty($cal['isDefault']) ? SYNC_FOLDER_TYPE_APPOINTMENT : SYNC_FOLDER_TYPE_USER_APPOINTMENT;
        return $f;
    }

    private function changeMailbox(string $folderid, ?string $oldid, string $name): string|false {
        if ($oldid) {
            $r = $this->client->call([
                ['Mailbox/set', ['accountId' => $this->accountId, 'update' => [$oldid => ['name' => $name]]], '0'],
            ]);
            return isset($r[0][1]['updated'][$oldid]) ? $oldid : false;
        }
        $create = ['name' => $name];
        if ($folderid) $create['parentId'] = $folderid;
        $r = $this->client->call([
            ['Mailbox/set', ['accountId' => $this->accountId, 'create' => ['n1' => $create]], '0'],
        ]);
        return $r[0][1]['created']['n1']['id'] ?? false;
    }

    private function changeAddressBook(?string $oldid, string $name): string|false {
        if ($oldid) {
            $jid = $this->jmapId($oldid);
            $r   = $this->client->call([
                ['AddressBook/set', ['accountId' => $this->accountId, 'update' => [$jid => ['name' => $name]]], '0'],
            ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
            return isset($r[0][1]['updated'][$jid]) ? $oldid : false;
        }
        $r = $this->client->call([
            ['AddressBook/set', ['accountId' => $this->accountId, 'create' => ['ab1' => ['name' => $name]]], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
        $newId = $r[0][1]['created']['ab1']['id'] ?? null;
        return $newId ? self::PFX_CONTACTS . $newId : false;
    }

    private function changeCalendar(?string $oldid, string $name): string|false {
        if ($oldid) {
            $jid = $this->jmapId($oldid);
            $r   = $this->client->call([
                ['Calendar/set', ['accountId' => $this->accountId, 'update' => [$jid => ['name' => $name]]], '0'],
            ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
            return isset($r[0][1]['updated'][$jid]) ? $oldid : false;
        }
        $r = $this->client->call([
            ['Calendar/set', ['accountId' => $this->accountId, 'create' => ['cal1' => ['name' => $name]]], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
        $newId = $r[0][1]['created']['cal1']['id'] ?? null;
        return $newId ? self::PFX_CALENDAR . $newId : false;
    }

    private function deleteMailbox(string $id): bool {
        $r = $this->client->call([
            ['Mailbox/set', ['accountId' => $this->accountId, 'destroy' => [$id], 'onDestroyRemoveEmails' => true], '0'],
        ]);
        return in_array($id, $r[0][1]['destroyed'] ?? [], true);
    }

    private function deleteAddressBook(string $id): bool {
        $jid = $this->jmapId($id);
        $r   = $this->client->call([
            ['AddressBook/set', ['accountId' => $this->accountId, 'destroy' => [$jid]], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
        return in_array($jid, $r[0][1]['destroyed'] ?? [], true);
    }

    private function deleteCalendar(string $id): bool {
        $jid = $this->jmapId($id);
        $r   = $this->client->call([
            ['Calendar/set', ['accountId' => $this->accountId, 'destroy' => [$jid]], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
        return in_array($jid, $r[0][1]['destroyed'] ?? [], true);
    }

    // =========================================================================
    // Private utility helpers
    // =========================================================================

    private function folderType(string $folderid): string {
        if (str_starts_with($folderid, self::PFX_CONTACTS)) return 'contacts';
        if (str_starts_with($folderid, self::PFX_CALENDAR)) return 'calendar';
        return 'mail';
    }

    private function jmapId(string $folderid): string {
        foreach ([self::PFX_CONTACTS, self::PFX_CALENDAR] as $pfx) {
            if (str_starts_with($folderid, $pfx)) return substr($folderid, strlen($pfx));
        }
        return $folderid;
    }

    private function getAllMailboxes(): array {
        $r = $this->client->call([
            ['Mailbox/get', ['accountId' => $this->accountId, 'ids' => null], '0'],
        ]);
        return $r[0][1]['list'] ?? [];
    }

    private function getAllAddressBooks(): array {
        $r = $this->client->call([
            ['AddressBook/get', ['accountId' => $this->accountId, 'ids' => null], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
        return $r[0][1]['list'] ?? [];
    }

    private function getAllCalendars(): array {
        $r = $this->client->call([
            ['Calendar/get', ['accountId' => $this->accountId, 'ids' => null], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
        return $r[0][1]['list'] ?? [];
    }

    private function getFolderQueryState(string $folderid): string {
        return match($this->folderType($folderid)) {
            'contacts' => $this->contactQueryState($this->jmapId($folderid)),
            'calendar' => $this->calendarQueryState($this->jmapId($folderid)),
            default    => $this->emailQueryState($folderid),
        };
    }

    private function emailQueryState(string $folderid): string {
        $r = $this->client->call([
            ['Email/query', ['accountId' => $this->accountId, 'filter' => ['inMailbox' => $folderid], 'limit' => 1], '0'],
        ]);
        return $r[0][1]['queryState'] ?? '';
    }

    private function contactQueryState(string $abId): string {
        $r = $this->client->call([
            ['ContactCard/query', ['accountId' => $this->accountId, 'filter' => ['inAddressBook' => $abId], 'limit' => 1], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CONTACTS]);
        return $r[0][1]['queryState'] ?? '';
    }

    private function calendarQueryState(string $calId): string {
        $r = $this->client->call([
            ['CalendarEvent/query', ['accountId' => $this->accountId, 'filter' => ['inCalendar' => $calId], 'limit' => 1], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_CALENDARS]);
        return $r[0][1]['queryState'] ?? '';
    }

    private function getIdentityId(): string {
        if ($this->identityId === '') {
            $this->identityId = $this->fetchPrimaryIdentityId();
        }
        return $this->identityId;
    }

    private function fetchPrimaryIdentityId(): string {
        $r        = $this->client->call([
            ['Identity/get', ['accountId' => $this->accountId, 'ids' => null], '0'],
        ], [JmapClient::CAP_CORE, JmapClient::CAP_MAIL, JmapClient::CAP_SUBMIT]);
        $identity = $r[0][1]['list'][0] ?? [];
        $this->identityName = $identity['name'] ?? '';
        return $identity['id'] ?? '';
    }

    private function getIdentityName(): string {
        $this->getIdentityId(); // ensure lazy load
        return $this->identityName;
    }

    private function rewriteFromHeader(string $mime, string $name): string {
        $eol = str_contains($mime, "\r\n") ? "\r\n" : "\n";
        $pattern = '/^(From:[ \t]*)(.+?)(?=' . preg_quote($eol, '/') . '(?![ \t]))/ms';
        return preg_replace_callback($pattern, function ($m) use ($name, $eol) {
            $val = preg_replace('/' . preg_quote($eol, '/') . '[ \t]+/', ' ', $m[2]);
            if (preg_match('/<([^>]+)>/', $val, $em)) {
                $email = $em[1];
            } elseif (preg_match('/\S+@\S+/', $val, $em)) {
                $email = trim($em[0]);
            } else {
                return $m[0];
            }
            $quotedName = str_replace(['"', '\\'], ['\\"', '\\\\'], $name);
            return 'From: "' . $quotedName . '" <' . $email . '>';
        }, $mime);
    }

    private function getMailboxIdByRole(string $role): string|false {
        foreach ($this->getAllMailboxes() as $mb) {
            if (($mb['role'] ?? '') === $role) return $mb['id'];
        }
        return false;
    }

    private function getSentMailboxId(): string|false {
        return $this->getMailboxIdByRole('sent');
    }

    private function setKeywords(string $emailId, array $keywords): bool {
        $patch = [];
        foreach ($keywords as $kw => $value) {
            $patch["keywords/$kw"] = $value ?: null;
        }
        $r = $this->client->call([
            ['Email/set', ['accountId' => $this->accountId, 'update' => [$emailId => $patch]], '0'],
        ]);
        return isset($r[0][1]['updated'][$emailId]);
    }

    private function formatAddressList(array $addresses): string {
        $parts = [];
        foreach ($addresses as $addr) {
            $email = $addr['email'] ?? '';
            $name  = $addr['name']  ?? '';
            if (!$email && !$name) continue;
            $parts[] = $name ? '"' . addslashes($name) . '" <' . $email . '>' : $email;
        }
        return implode(', ', $parts);
    }

    private function parseJmapDate(string $date): int {
        if (!$date) return 0;
        $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, $date, new \DateTimeZone('UTC'));
        return $dt ? $dt->getTimestamp() : 0;
    }

    private function keywordsHash(array $keywords): string {
        $keys = array_keys($keywords);
        sort($keys);
        return sprintf('%08x', crc32(implode(',', $keys)));
    }

    private function extToMime(string $ext): string {
        static $map = [
            'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'gif' => 'image/gif', 'txt' => 'text/plain',
            'html' => 'text/html', 'htm' => 'text/html', 'zip' => 'application/zip',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ics' => 'text/calendar', 'vcf' => 'text/vcard', 'eml' => 'message/rfc822',
        ];
        return $map[$ext] ?? 'application/octet-stream';
    }
}
