<?php
/***********************************************
* File      :   config.php
* Project   :   Z-Push - tools - GAB2Contacts
* Descr     :   Configuration file.
*
* Created   :   20.07.2016
*
* Copyright 2016 Zarafa Deutschland GmbH
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
* ************************************************/

// The field to be hashed that is unique and never changes
// in the entire lifetime of the GAB entry.
define('HASHFIELD', 'account');

// ContactWorker implementation to be used
define('CONTACTWORKER', 'Kopano');

// Server connection settings
// Depending on your setup, it might be advisable to change the lines below to one defined with your
// default socket location.
// Normally "default:" points to the default setting ("file:///var/run/kopano/server.sock")
// Examples: define("SERVER", "default:");
//           define("SERVER", "http://localhost:236/kopano");
//           define("SERVER", "https://localhost:237/kopano");
//           define("SERVER", "file:///var/run/kopano/server.sock");
// If you are using ZCP >= 7.2.0, set it to the zarafa location, e.g.
//           define("SERVER", "http://localhost:236/zarafa");
//           define("SERVER", "https://localhost:237/zarafa");
//           define("SERVER", "file:///var/run/zarafad/server.sock");
// For ZCP versions prior to 7.2.0 the socket location is different (http(s) sockets are the same):
//           define("SERVER", "file:///var/run/zarafa");

define('SERVER', 'default:');

define('USERNAME', 'SYSTEM');
define('PASSWORD', '');
define('CERTIFICATE', null);
define('CERTIFICATE_PASSWORD', null);

// The GAB to be used. This only needs to be set on a multi-tenant system.
// For standard installations, keep it at 'default'.
define('SOURCE_GAB', 'default');

// Store where the target contact folder is located.
// For the public folder, use SYSTEM.
// To use another store, use the same as USERNAME
// or another store where USERNAME has full access to.
define('CONTACT_FOLDERSTORE', 'SYSTEM');

// Set the target FolderId.
// You can find the id e.g. with the listfolders script of Kopano backend.
define('CONTACT_FOLDERID', '');

// Set the fileas (save as) order for contacts.
// Possible values are:
//   SYNC_FILEAS_FIRSTLAST    - fileas will be "Firstname Lastname"
//   SYNC_FILEAS_LASTFIRST    - fileas will be "Lastname, Firstname"
//   SYNC_FILEAS_COMPANYONLY  - fileas will be "Company"
//   SYNC_FILEAS_COMPANYLAST  - fileas will be "Company (Lastname, Firstname)"
//   SYNC_FILEAS_COMPANYFIRST - fileas will be "Company (Firstname Lastname)"
//   SYNC_FILEAS_LASTCOMPANY  - fileas will be "Lastname, Firstname (Company)"
//   SYNC_FILEAS_FIRSTCOMPANY - fileas will be "Firstname Lastname (Company)"
//
// The company-fileas will only be set if a contact has a company set. If one of
// company-fileas is selected and a contact doesn't have a company set, it will default
// to SYNC_FILEAS_FIRSTLAST or SYNC_FILEAS_LASTFIRST (depending on if last or first
// option is selected for company).
// If SYNC_FILEAS_COMPANYONLY is selected and company of the contact is not set
// SYNC_FILEAS_LASTFIRST will be used
define('FILEAS_ORDER', SYNC_FILEAS_LASTFIRST);
