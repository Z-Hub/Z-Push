<?php
/***********************************************
* File      :   config.php
* Project   :   Z-Push
* Descr     :   JMAP backend configuration
*
* Copyright 2024 - Z-Push Contributors
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation.
*
* Consult LICENSE file for details
************************************************/

// JMAP session endpoint URL
if (!defined('JMAP_SESSION_URL')) {
    define('JMAP_SESSION_URL', 'https://stalwart.url/jmap/session');
}

// Whether to verify SSL certificates (disable only for development)
if (!defined('JMAP_SSL_VERIFY')) {
    define('JMAP_SSL_VERIFY', true);
}

// HTTP timeout in seconds for JMAP requests
if (!defined('JMAP_TIMEOUT')) {
    define('JMAP_TIMEOUT', 30);
}

// Maximum number of email IDs to fetch in one Email/get call
if (!defined('JMAP_MAX_OBJECTS_PER_REQUEST')) {
    define('JMAP_MAX_OBJECTS_PER_REQUEST', 500);
}

// How many bytes of body to fetch for the preview/truncation check (0 = unlimited)
if (!defined('JMAP_MAX_BODY_BYTES')) {
    define('JMAP_MAX_BODY_BYTES', 0);
}
