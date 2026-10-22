<?php
/***********************************************
 * File      :   nextcloudnotes.php
 * Project   :   Z-Push
 * Descr     :   BackendDiff for Nextcloud Notes (EAS Notes).
 *
 * Intended for BackendCombined together with BackendIMAP.
 * Exposes a single iOS-style Notes folder (SYNC_FOLDER_TYPE_NOTE)
 * and maps items to SyncNote / IPM.StickyNote via the Nextcloud
 * Notes REST API v1. No docker exec, no Notes internals.
 *
 * Install
 *   1. Copy this file to:
 *        <z-push>/backend/nextcloudnotes/nextcloudnotes.php
 *
 *   2. In backend/combined/config.php add a backend slot and
 *      route Notes folders to it, for example:
 *
 *        'backends' => array(
 *            'i' => array('name' => 'BackendIMAP'),
 *            'n' => array('name' => 'BackendNextcloudNotes'),
 *            // ... CardDAV / CalDAV ...
 *        ),
 *        'folderbackend' => array(
 *            SYNC_FOLDER_TYPE_INBOX => 'i',
 *            SYNC_FOLDER_TYPE_DRAFTS => 'i',
 *            SYNC_FOLDER_TYPE_WASTEBASKET => 'i',
 *            SYNC_FOLDER_TYPE_SENTMAIL => 'i',
 *            SYNC_FOLDER_TYPE_OUTBOX => 'i',
 *            SYNC_FOLDER_TYPE_USER_MAIL => 'i',
 *            SYNC_FOLDER_TYPE_OTHER => 'i',
 *            SYNC_FOLDER_TYPE_NOTE => 'n',
 *            SYNC_FOLDER_TYPE_USER_NOTE => 'n',
 *            // keep contacts/calendar/tasks on their backends
 *        ),
 *
 *   3. Set BACKEND_PROVIDER to BackendCombined (already the case
 *      if IMAP is combined with CalDAV/CardDAV).
 *
 *   4. Copy config.php next to it and set NCNOTES_URL. Do not put
 *      secrets only in nextcloudnotes.php – updates overwrite that file.
 *
 * Auth: HTTP Basic against Nextcloud. Combined login does not require
 * Nextcloud to accept the IMAP password (NCNOTES_REQUIRED defaults to
 * false). If the device password is not a Nextcloud password, set
 * NCNOTES_PASSWORD to an app password or Notes calls will 401.
 *
 * iOS: add the Exchange account and enable Notes. iOS does not sync
 * Nextcloud folders as separate EAS folders; all notes land in one
 * Notes mailbox. Nextcloud category/favorite are stored as EAS
 * categories so they round-trip.
 *
 * Copyright 2026
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 ***********************************************/

$configLocal = dirname(__FILE__) . '/config.php';
if (is_readable($configLocal)) {
    require_once $configLocal;
}

if (!defined('NCNOTES_URL')) {
    /** Nextcloud origin, no trailing slash. Docker: http://nextcloud */
    define('NCNOTES_URL', 'https://myhost/nextcloud');
}
if (!defined('NCNOTES_API')) {
    define('NCNOTES_API', '/index.php/apps/notes/api/v1');
}
if (!defined('NCNOTES_FOLDER_ID')) {
    define('NCNOTES_FOLDER_ID', 'notes');
}
if (!defined('NCNOTES_FOLDER_NAME')) {
    define('NCNOTES_FOLDER_NAME', 'Notes');
}
if (!defined('NCNOTES_USERNAME')) {
    /** %u is replaced with the ActiveSync user */
    define('NCNOTES_USERNAME', '%u');
}
if (!defined('NCNOTES_PASSWORD')) {
    /** Empty = use the ActiveSync password */
    define('NCNOTES_PASSWORD', '');
}
if (!defined('NCNOTES_STRIP_DOMAIN')) {
    define('NCNOTES_STRIP_DOMAIN', false);
}
if (!defined('NCNOTES_REQUIRED')) {
    /** false = Combined/IMAP login still succeeds if Nextcloud auth fails */
    define('NCNOTES_REQUIRED', false);
}
if (!defined('NCNOTES_VERIFY_SSL')) {
    define('NCNOTES_VERIFY_SSL', true);
}
if (!defined('NCNOTES_TIMEOUT')) {
    define('NCNOTES_TIMEOUT', 30);
}
if (!defined('NCNOTES_CHUNK_SIZE')) {
    /** 0 = one unchunked list request (preferred; avoids API id-only stubs) */
    define('NCNOTES_CHUNK_SIZE', 0);
}
if (!defined('NCNOTES_SINK_POLL')) {
    /** Seconds between Nextcloud polls inside ChangesSink */
    define('NCNOTES_SINK_POLL', 5);
}

class BackendNextcloudNotes extends BackendDiff {
    const FAVORITE_CATEGORY = 'Favorite';
    const OUTLOOK_COLOR_CATEGORIES = array(
        'Blue Category',
        'Green Category',
        'Red Category',
        'Yellow Category',
        'White Category',
        'Purple Category',
        'Orange Category',
    );

    private $username;
    private $password;
    private $notesCache;
    private $changessinkinit = false;
    private $sinkfolders = array();
    private $sinkstate = array();

    public function __construct() {
        if (!function_exists('curl_init')) {
            throw new FatalException('BackendNextcloudNotes(): php-curl is not installed', 0, null, LOGLEVEL_FATAL);
        }
    }

    /**
     * Store credentials and probe Nextcloud.
     *
     * Combined backends fail the whole device login if any backend returns
     * false. Notes must not take IMAP down: unless NCNOTES_REQUIRED is true,
     * probe failures still return true (same idea as the old docker backend
     * that ignored the password at Logon).
     */
    public function Logon($username, $domain, $password) {
        $this->password = (NCNOTES_PASSWORD !== '') ? NCNOTES_PASSWORD : $password;
        $this->notesCache = null;

        $candidates = array();
        $mapped = $this->mapUsername($username);
        $candidates[] = $mapped;
        if ($username !== $mapped) {
            $candidates[] = $username;
        }
        if ($domain !== '' && strpos($username, '@') === false) {
            $candidates[] = $username . '@' . $domain;
        }
        $candidates = array_values(array_unique($candidates));
        $this->username = $candidates[0];

        if (NCNOTES_URL === '' || NCNOTES_URL === 'https://nextcloud.example.com') {
            ZLog::Write(LOGLEVEL_ERROR, 'BackendNextcloudNotes->Logon(): NCNOTES_URL is not configured (edit backend/nextcloudnotes/config.php)');
            return $this->logonResult(false);
        }

        $lastStatus = 'n/a';
        foreach ($candidates as $candidate) {
            $this->username = $candidate;
            $response = $this->probeNotes();
            if ($response !== false && $response['status'] >= 200 && $response['status'] < 300) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                    "BackendNextcloudNotes->Logon(): authenticated as '%s' on '%s'",
                    $this->username,
                    NCNOTES_URL
                ));
                return true;
            }
            $lastStatus = ($response === false) ? 'n/a' : (string)$response['status'];
            ZLog::Write(LOGLEVEL_DEBUG, sprintf(
                "BackendNextcloudNotes->Logon(): probe as '%s' -> HTTP %s",
                $candidate,
                $lastStatus
            ));
        }

        $this->username = $candidates[0];
        ZLog::Write(LOGLEVEL_WARN, sprintf(
            "BackendNextcloudNotes->Logon(): Nextcloud probe failed for '%s' (HTTP %s). IMAP login continues unless NCNOTES_REQUIRED is true.",
            $this->username,
            $lastStatus
        ));
        return $this->logonResult(false);
    }

    /**
     * Lightweight auth/connectivity check. Do not use /settings: older Notes
     * apps return 400, which used to fail Combined login on iOS.
     */
    private function probeNotes() {
        return $this->api('GET', '/notes?exclude=content&chunkSize=1');
    }

    private function logonResult($ok) {
        if ($ok) {
            return true;
        }
        return NCNOTES_REQUIRED ? false : true;
    }

    public function Logoff() {
        $this->notesCache = null;
        $this->username = null;
        $this->password = null;
        if (method_exists($this, 'SaveStorages')) {
            $this->SaveStorages();
        }
        return true;
    }

    public function SendMail($sm) {
        return false;
    }

    public function GetWasteBasket() {
        return false;
    }

    public function GetAttachmentData($attname) {
        return false;
    }

    /**
     * Notes require EAS 14.0 (Exchange 2010 / iOS Notes).
     */
    public function GetSupportedASVersion() {
        return ZPush::ASV_14;
    }

    public function GetFolderList() {
        return array($this->StatFolder(NCNOTES_FOLDER_ID));
    }

    public function GetFolder($id) {
        if (!$this->isNotesFolder($id)) {
            return false;
        }

        $folder = new SyncFolder();
        $folder->serverid = NCNOTES_FOLDER_ID;
        $folder->parentid = '0';
        $folder->displayname = NCNOTES_FOLDER_NAME;
        $folder->type = SYNC_FOLDER_TYPE_NOTE;
        return $folder;
    }

    public function StatFolder($id) {
        $folder = $this->GetFolder($id);
        if ($folder === false) {
            return false;
        }

        return array(
            'id' => $folder->serverid,
            'parent' => $folder->parentid,
            'mod' => $folder->displayname,
        );
    }

    public function ChangeFolder($folderid, $oldid, $displayname, $type) {
        return false;
    }

    public function DeleteFolder($id, $parentid) {
        return false;
    }

    public function GetMessageList($folderid, $cutoffdate) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf(
            "BackendNextcloudNotes->GetMessageList('%s','%s')",
            $folderid,
            $cutoffdate
        ));

        if (!$this->isNotesFolder($folderid)) {
            return array();
        }

        $notes = $this->listNotesMeta();
        if ($notes === false) {
            return false;
        }

        // Notes are not mail: never drop items via cutoffdate. DiffEngine must
        // see the full id set or it treats missing notes as deletes.
        $messages = array();
        foreach ($notes as $note) {
            $stat = $this->statFromNote($note);
            if ($stat !== false) {
                $messages[] = $stat;
            }
        }
        return $messages;
    }

    public function GetMessage($folderid, $id, $contentparameters) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf(
            "BackendNextcloudNotes->GetMessage('%s','%s')",
            $folderid,
            $id
        ));

        if (!$this->isNotesFolder($folderid)) {
            return false;
        }

        $note = $this->getNote($id);
        if ($note === false) {
            return false;
        }

        $truncsize = Utils::GetTruncSize($contentparameters->GetTruncation());
        $body = isset($note['content']) ? (string)$note['content'] : '';
        $truncated = 0;
        if ($truncsize > 0 && strlen($body) > $truncsize) {
            $body = Utils::Utf8_truncate($body, $truncsize);
            $truncated = 1;
        }
        $body = str_replace("\n", "\r\n", str_replace("\r", '', $body));

        $message = new SyncNote();
        $message->messageclass = 'IPM.StickyNote';
        $message->subject = isset($note['title']) ? (string)$note['title'] : '';
        if (isset($note['modified'])) {
            $message->lastmodified = (int)$note['modified'];
        }
        $message->categories = $this->easCategoriesFromNote($note);

        $message->asbody = new SyncBaseBody();
        $message->asbody->type = SYNC_BODYPREFERENCE_PLAIN;
        $message->asbody->truncated = $truncated;
        $message->asbody->estimatedDataSize = strlen($body);
        if (class_exists('StringStreamWrapper')) {
            $message->asbody->data = StringStreamWrapper::Open($body);
        }
        else {
            $message->asbody->data = $body;
        }
        $message->nativebodytype = SYNC_BODYPREFERENCE_PLAIN;

        return $message;
    }

    public function StatMessage($folderid, $id) {
        if (!$this->isNotesFolder($folderid)) {
            return false;
        }

        $notes = $this->listNotesMeta();
        if (is_array($notes)) {
            foreach ($notes as $note) {
                if ((string)$note['id'] === (string)$id) {
                    return $this->statFromNote($note);
                }
            }
        }

        $note = $this->getNote($id);
        if ($note === false) {
            return false;
        }
        return $this->statFromNote($note);
    }

    public function ChangeMessage($folderid, $id, $message, $contentParameters) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf(
            "BackendNextcloudNotes->ChangeMessage('%s','%s')",
            $folderid,
            $id
        ));

        if (!$this->isNotesFolder($folderid)) {
            return false;
        }

        $payload = $this->notePayloadFromSyncNote($message);
        $isNew = ($id === false || $id === null || $id === '');

        if ($isNew) {
            $response = $this->api('POST', '/notes', $payload);
            $this->notesCache = null;
            if ($response === false || $response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json'])) {
                ZLog::Write(LOGLEVEL_WARN, 'BackendNextcloudNotes->ChangeMessage(): create failed');
                return false;
            }
            return $this->statFromNote($response['json']);
        }

        $existing = $this->getNote($id);
        if ($existing === false) {
            return false;
        }
        if (!empty($existing['readonly'])) {
            ZLog::Write(LOGLEVEL_WARN, sprintf(
                "BackendNextcloudNotes->ChangeMessage(): note %s is read-only",
                $id
            ));
            return false;
        }

        $headers = array();
        if (!empty($existing['etag'])) {
            $headers[] = 'If-Match: "' . trim($existing['etag'], '"') . '"';
        }

        $response = $this->api('PUT', '/notes/' . rawurlencode((string)$id), $payload, $headers);
        $this->notesCache = null;

        if ($response !== false && $response['status'] === 412 && is_array($response['json'])) {
            ZLog::Write(LOGLEVEL_INFO, sprintf(
                "BackendNextcloudNotes->ChangeMessage(): conflict on note %s",
                $id
            ));
            return $this->statFromNote($response['json']);
        }

        if ($response === false || $response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json'])) {
            ZLog::Write(LOGLEVEL_WARN, sprintf(
                "BackendNextcloudNotes->ChangeMessage(): update failed for note %s",
                $id
            ));
            return false;
        }

        return $this->statFromNote($response['json']);
    }

    public function SetReadFlag($folderid, $id, $flags, $contentParameters) {
        return false;
    }

    public function DeleteMessage($folderid, $id, $contentParameters) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf(
            "BackendNextcloudNotes->DeleteMessage('%s','%s')",
            $folderid,
            $id
        ));

        if (!$this->isNotesFolder($folderid)) {
            return false;
        }

        $response = $this->api('DELETE', '/notes/' . rawurlencode((string)$id));
        $this->notesCache = null;
        if ($response === false) {
            return false;
        }
        return ($response['status'] === 200 || $response['status'] === 204 || $response['status'] === 404);
    }

    public function MoveMessage($folderid, $id, $newfolderid, $contentParameters) {
        return false;
    }

    public function HasChangesSink() {
        return true;
    }

    public function ChangesSinkInitialize($folderid) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf(
            "BackendNextcloudNotes->ChangesSinkInitialize(): '%s'",
            $folderid
        ));

        if (!$this->isNotesFolder($folderid)) {
            return false;
        }

        // Only register the folder. Baseline is taken on the first ChangesSink
        // poll (same pattern as BackendIMAP) so we do not snapshot "already
        // changed" state and then miss it for the rest of this Ping.
        $this->sinkfolders[$folderid] = true;
        $this->changessinkinit = true;
        return true;
    }

    /**
     * Poll Nextcloud until $timeout. Combined splits this timeout across
     * sink backends, so re-check every NCNOTES_SINK_POLL seconds instead of
     * sleeping the whole slice after a single GET.
     *
     * @return array folder ids that need a sync
     */
    public function ChangesSink($timeout = 30) {
        $notifications = array();
        $stopat = time() + max(1, (int)$timeout) - 1;
        $poll = max(1, (int)NCNOTES_SINK_POLL);

        if (!$this->changessinkinit) {
            sleep(max(1, (int)$timeout));
            return $notifications;
        }

        do {
            $this->notesCache = null;
            foreach (array_keys($this->sinkfolders) as $folderid) {
                $fingerprint = $this->notesFingerprint();
                if ($fingerprint === false) {
                    continue;
                }
                if (!isset($this->sinkstate[$folderid])) {
                    $this->sinkstate[$folderid] = $fingerprint;
                    continue;
                }
                if ($fingerprint !== $this->sinkstate[$folderid]) {
                    $this->sinkstate[$folderid] = $fingerprint;
                    $notifications[] = $folderid;
                }
            }

            if (!empty($notifications)) {
                return array_values(array_unique($notifications));
            }

            $remaining = $stopat - time();
            if ($remaining > 0) {
                sleep(min($poll, $remaining));
            }
        } while (time() < $stopat);

        return $notifications;
    }

    // ------------------------------------------------------------------
    // Nextcloud API
    // ------------------------------------------------------------------

    /**
     * Full note metadata (no content). Returns false on API failure so
     * DiffEngine does not treat a failed list as "all notes deleted".
     *
     * The Notes API appends id-only stubs on the last chunk (prune placeholders).
     * Those must not overwrite real etag/modified values.
     *
     * @return array|false
     */
    private function listNotesMeta() {
        if (is_array($this->notesCache)) {
            return $this->notesCache;
        }

        $notes = array();
        $cursor = null;

        do {
            $query = array(
                'exclude' => 'content',
            );
            if ((int)NCNOTES_CHUNK_SIZE > 0) {
                $query['chunkSize'] = (int)NCNOTES_CHUNK_SIZE;
            }
            if ($cursor !== null && $cursor !== '') {
                $query['chunkCursor'] = $cursor;
            }

            $response = $this->api('GET', '/notes?' . http_build_query($query));
            if ($response === false || $response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json'])) {
                ZLog::Write(LOGLEVEL_WARN, 'BackendNextcloudNotes->listNotesMeta(): list failed');
                return false;
            }

            foreach ($response['json'] as $note) {
                if (!is_array($note) || !isset($note['id'])) {
                    continue;
                }
                if ($this->isPrunedNote($note)) {
                    continue;
                }
                $notes[(string)$note['id']] = $note;
            }

            $cursor = $this->headerValue($response['headers'], 'x-notes-chunk-cursor');
        } while ($cursor !== '');

        $this->notesCache = array_values($notes);
        return $this->notesCache;
    }

    private function getNote($id) {
        $response = $this->api('GET', '/notes/' . rawurlencode((string)$id));
        if ($response === false || $response['status'] === 404) {
            return false;
        }
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json'])) {
            return false;
        }
        return $response['json'];
    }

    /**
     * @return string|false
     */
    private function notesFingerprint() {
        $notes = $this->listNotesMeta();
        if ($notes === false) {
            return false;
        }

        $parts = array();
        foreach ($notes as $note) {
            $stat = $this->statFromNote($note);
            if ($stat === false) {
                continue;
            }
            $parts[] = $stat['id'] . ':' . $stat['mod'];
        }
        sort($parts);
        return sha1(implode('|', $parts));
    }

    /**
     * @param string $method
     * @param string $path    path under NCNOTES_API, starting with /
     * @param array|null $body
     * @param array $extraHeaders
     * @return array|false    array(status, headers, json, raw)
     */
    private function api($method, $path, $body = null, $extraHeaders = array()) {
        $url = rtrim(NCNOTES_URL, '/') . NCNOTES_API . $path;
        $headers = array(
            'Accept: application/json',
            'OCS-APIRequest: true',
        );
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        foreach ($extraHeaders as $header) {
            $headers[] = $header;
        }

        $ch = curl_init($url);
        $opts = array(
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => (int)NCNOTES_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => (bool)NCNOTES_VERIFY_SSL,
            CURLOPT_SSL_VERIFYHOST => NCNOTES_VERIFY_SSL ? 2 : 0,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'Z-Push-NextcloudNotes',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        );
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        if ($raw === false) {
            ZLog::Write(LOGLEVEL_WARN, sprintf(
                "BackendNextcloudNotes->api(): curl error for %s %s: %s",
                $method,
                $url,
                curl_error($ch)
            ));
            curl_close($ch);
            return false;
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headerBlob = substr($raw, 0, $headerSize);
        $rawBody = substr($raw, $headerSize);
        $headersOut = $this->parseHeaders($headerBlob);

        $json = null;
        $trim = trim($rawBody);
        if ($trim !== '') {
            $decoded = json_decode($trim, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $json = $decoded;
            }
            elseif ($status >= 200 && $status < 300 && $method !== 'DELETE') {
                ZLog::Write(LOGLEVEL_WARN, sprintf(
                    "BackendNextcloudNotes->api(): invalid JSON from %s %s",
                    $method,
                    $path
                ));
            }
        }

        ZLog::Write(LOGLEVEL_DEBUG, sprintf(
            "BackendNextcloudNotes->api(): %s %s -> HTTP %d",
            $method,
            $path,
            $status
        ));

        return array(
            'status' => $status,
            'headers' => $headersOut,
            'json' => $json,
            'raw' => $rawBody,
        );
    }

    // ------------------------------------------------------------------
    // Mapping
    // ------------------------------------------------------------------

    private function statFromNote(array $note) {
        if (!isset($note['id'])) {
            return false;
        }

        $etag = (isset($note['etag']) && $note['etag'] !== '') ? (string)$note['etag'] : '';
        $modified = isset($note['modified']) ? (string)$note['modified'] : '';
        $mod = $etag . ':' . $modified;
        if ($mod === ':') {
            $mod = 'id:' . $note['id'];
        }

        return array(
            'id' => (string)$note['id'],
            'mod' => $mod,
            'flags' => 1,
        );
    }

    /**
     * Last-chunk prune placeholders from the Notes API: only `id` is set.
     */
    private function isPrunedNote(array $note) {
        if (!isset($note['id'])) {
            return true;
        }
        $hasRev = (isset($note['etag']) && $note['etag'] !== '')
            || (isset($note['modified']) && $note['modified'] !== '' && $note['modified'] !== null);
        return !$hasRev;
    }

    private function easCategoriesFromNote(array $note) {
        $categories = array();
        if (!empty($note['category'])) {
            $categories[] = (string)$note['category'];
        }
        if (!empty($note['favorite'])) {
            $categories[] = self::FAVORITE_CATEGORY;
        }
        return $categories;
    }

    private function notePayloadFromSyncNote($message) {
        $content = $this->bodyFromSyncNote($message);
        $subject = '';
        if (isset($message->subject) && $message->subject !== '') {
            $subject = (string)$message->subject;
        }
        if ($subject === '' && $content !== '') {
            $first = strtok(str_replace("\r\n", "\n", $content), "\n");
            $subject = $first !== false ? $first : '';
        }
        if ($subject === '') {
            $subject = 'Untitled';
        }

        $payload = array(
            'title' => $subject,
            'content' => $content,
        );

        $category = '';
        $favorite = false;
        if (isset($message->categories) && is_array($message->categories)) {
            foreach ($message->categories as $cat) {
                $cat = (string)$cat;
                if ($cat === '' || in_array($cat, self::OUTLOOK_COLOR_CATEGORIES, true)) {
                    continue;
                }
                if (strcasecmp($cat, self::FAVORITE_CATEGORY) === 0) {
                    $favorite = true;
                    continue;
                }
                if ($category === '') {
                    $category = $cat;
                }
            }
        }
        $payload['category'] = $category;
        $payload['favorite'] = $favorite;

        if (isset($message->lastmodified) && is_numeric($message->lastmodified)) {
            $payload['modified'] = (int)$message->lastmodified;
        }

        return $payload;
    }

    private function bodyFromSyncNote($message) {
        $text = '';
        if (isset($message->asbody) && isset($message->asbody->data)) {
            $data = $message->asbody->data;
            if (is_resource($data)) {
                $pos = ftell($data);
                rewind($data);
                $text = stream_get_contents($data);
                if ($pos !== false) {
                    fseek($data, $pos);
                }
            }
            else {
                $text = (string)$data;
            }
            if (isset($message->asbody->type) && defined('SYNC_BODYPREFERENCE_HTML')
                    && $message->asbody->type == SYNC_BODYPREFERENCE_HTML
                    && method_exists('Utils', 'ConvertHtmlToText')) {
                $text = Utils::ConvertHtmlToText($text);
            }
        }
        elseif (isset($message->body)) {
            $text = (string)$message->body;
        }

        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace("\r", "\n", $text);
        return $text;
    }

    private function isNotesFolder($id) {
        if ($id === NCNOTES_FOLDER_ID) {
            return true;
        }
        // Combined backend may already have stripped the backend prefix;
        // still accept a trailing match if a delimiter leaked through.
        if (is_string($id) && substr($id, -strlen(NCNOTES_FOLDER_ID)) === NCNOTES_FOLDER_ID) {
            return true;
        }
        return false;
    }

    private function mapUsername($username) {
        $mapped = str_replace('%u', $username, NCNOTES_USERNAME);
        if (NCNOTES_STRIP_DOMAIN && strpos($mapped, '@') !== false) {
            $mapped = substr($mapped, 0, strpos($mapped, '@'));
        }
        return $mapped;
    }

    private function parseHeaders($blob) {
        $headers = array();
        foreach (preg_split("/\r\n|\n|\r/", $blob) as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $pos)));
            $value = trim(substr($line, $pos + 1));
            $headers[$name] = $value;
        }
        return $headers;
    }

    private function headerValue(array $headers, $name) {
        $name = strtolower($name);
        return isset($headers[$name]) ? $headers[$name] : '';
    }
}
