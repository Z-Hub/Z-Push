<?php
/***********************************************
* File      :   jmap_client.php
* Project   :   Z-Push
* Descr     :   Pure PHP JMAP HTTP client.
*               Handles session bootstrapping, API calls, blob
*               upload and download.
*
* Copyright 2024 - Z-Push Contributors
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation.
*
* Consult LICENSE file for details
************************************************/

class JmapClient {

    private string $sessionUrl;
    private string $username;
    private string $password;
    private ?array $session = null;

    // Capability URNs
    const CAP_CORE       = 'urn:ietf:params:jmap:core';
    const CAP_MAIL       = 'urn:ietf:params:jmap:mail';
    const CAP_SUBMIT     = 'urn:ietf:params:jmap:submission';
    const CAP_VACATION   = 'urn:ietf:params:jmap:vacationresponse';
    const CAP_CONTACTS   = 'urn:ietf:params:jmap:contacts';
    const CAP_CALENDARS  = 'urn:ietf:params:jmap:calendars';

    public function __construct(string $sessionUrl, string $username, string $password) {
        $this->sessionUrl = $sessionUrl;
        $this->username   = $username;
        $this->password   = $password;
    }

    /**
     * Fetch and cache the JMAP session object.
     * Throws FatalException if the server is unreachable or auth fails.
     */
    public function getSession(): array {
        if ($this->session === null) {
            $this->session = $this->httpRequest('GET', $this->sessionUrl);
        }
        return $this->session;
    }

    /**
     * Invalidate the cached session so the next call re-fetches it.
     */
    public function invalidateSession(): void {
        $this->session = null;
    }

    /**
     * Return the accountId for the mail capability.
     */
    public function getAccountId(): string {
        $session = $this->getSession();
        if (!isset($session['primaryAccounts'][self::CAP_MAIL])) {
            throw new FatalException('JmapClient: no mail account in JMAP session', 0, null, LOGLEVEL_FATAL);
        }
        return $session['primaryAccounts'][self::CAP_MAIL];
    }

    /**
     * Return the JMAP API URL from the session.
     */
    public function getApiUrl(): string {
        return $this->getSession()['apiUrl'];
    }

    /**
     * Return the session state string (used for change detection).
     */
    public function getSessionState(): string {
        return $this->getSession()['state'] ?? '';
    }

    /**
     * Execute a JMAP method-call batch.
     *
     * @param array $methodCalls  Array of [methodName, arguments, callId] triples.
     * @param array $using        Capability URNs. Defaults to core + mail.
     * @return array              methodResponses array from the JMAP response.
     */
    public function call(array $methodCalls, array $using = [self::CAP_CORE, self::CAP_MAIL]): array {
        $payload = json_encode([
            'using'       => array_values($using),
            'methodCalls' => $methodCalls,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $response = $this->httpRequest('POST', $this->getApiUrl(), $payload, 'application/json');

        if (!isset($response['methodResponses'])) {
            throw new StatusException('JmapClient: JMAP response missing methodResponses', SYNC_STATUS_SERVERERROR);
        }
        return $response['methodResponses'];
    }

    /**
     * Upload binary data and return the blobId.
     *
     * @param string $data         Raw binary data.
     * @param string $contentType  MIME type of the blob.
     * @return string              The blobId assigned by the server.
     */
    public function uploadBlob(string $data, string $contentType = 'message/rfc822'): string {
        $accountId = $this->getAccountId();
        $url       = str_replace('{accountId}', rawurlencode($accountId), $this->getSession()['uploadUrl']);
        $result    = $this->httpRequest('POST', $url, $data, $contentType);

        if (!isset($result['blobId'])) {
            throw new StatusException('JmapClient: upload response missing blobId', SYNC_STATUS_SERVERERROR);
        }
        return $result['blobId'];
    }

    /**
     * Download a blob and return its raw bytes.
     *
     * @param string $blobId    The blobId to download.
     * @param string $name      Suggested filename (used in URL template).
     * @param string $accept    Accepted content type.
     * @return string           Raw blob data.
     */
    public function downloadBlob(string $blobId, string $name = 'attachment', string $accept = 'application/octet-stream'): string {
        $accountId = $this->getAccountId();
        $url = $this->getSession()['downloadUrl'];
        $url = str_replace(
            ['{accountId}', '{blobId}', '{name}',           '{type}'],
            [rawurlencode($accountId), rawurlencode($blobId), rawurlencode($name), rawurlencode($accept)],
            $url
        );
        return $this->httpRequest('GET', $url, null, null, true);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Perform an HTTP request and return the decoded JSON body (or raw string).
     *
     * @param string      $method       HTTP verb.
     * @param string      $url          Full URL.
     * @param string|null $body         Request body (null for GET).
     * @param string|null $contentType  Content-Type header value.
     * @param bool        $rawResponse  Return raw bytes instead of decoded JSON.
     * @return mixed
     * @throws FatalException|StatusException on HTTP / decode errors.
     */
    private function httpRequest(
        string $method,
        string $url,
        ?string $body = null,
        ?string $contentType = null,
        bool $rawResponse = false
    ): mixed {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $this->username . ':' . $this->password,
            CURLOPT_TIMEOUT        => JMAP_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => JMAP_SSL_VERIFY,
            CURLOPT_SSL_VERIFYHOST => JMAP_SSL_VERIFY ? 2 : 0,
            CURLOPT_USERAGENT      => 'Z-Push JMAP Backend/1.0',
        ]);

        $headers = ['Accept: application/json'];
        if ($contentType !== null) {
            $headers[] = 'Content-Type: ' . $contentType;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        $responseBody = curl_exec($ch);
        $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new FatalException(
                sprintf('JmapClient: cURL error for %s %s: %s', $method, $url, $curlError),
                0, null, LOGLEVEL_ERROR
            );
        }

        if ($httpCode === 401) {
            throw new AuthenticationRequiredException('JmapClient: authentication failed (401)');
        }

        if ($httpCode >= 400) {
            throw new StatusException(
                sprintf('JmapClient: HTTP %d from %s %s: %s', $httpCode, $method, $url, substr($responseBody, 0, 200)),
                SYNC_STATUS_SERVERERROR
            );
        }

        if ($rawResponse) {
            return $responseBody;
        }

        $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        return $decoded;
    }
}
