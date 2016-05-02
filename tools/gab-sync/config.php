<?php
/***********************************************
* File      :   config.php
* Project   :   Z-Push - tools - OL GAB sync
* Descr     :   Configuration file.
*
* Created   :   28.01.2016
*
* Copyright 2016 Zarafa Deutschland GmbH
* ************************************************/

// The field to be hashed that is unique and never changes
// in the entire lifetime of the GAB entry.
define('HASHFIELD', 'account');
define('AMOUT_OF_CHUNKS', 10);

// SyncWorker implementation to be used
define('SYNCWORKER', 'Zarafa');

// unique id to find a contact from the GAB (value to be supplied by -u on the command line)
// Zarafa supports: 'account' and 'smtpAddress' (email)
define('UNIQUEID', 'account');

// server connection settings
define('SERVER', 'file:///var/run/zarafa');
define('USERNAME', '');
define('PASSWORD', '');
define('CERTIFICATE', null);
define('CERTIFICATE_PASSWORD', null);

// Store where the hidden folder is located.
// For the public folder, use SYSTEM
// to use another store, use the same as USERNAME
// or another store where USERNAME has full access to.
define('HIDDEN_FOLDERSTORE', 'SYSTEM');

/// Do not change (unless you know exactly what you do)
define('HIDDEN_FOLDERNAME', 'Z-Push-KOP-GAB');
