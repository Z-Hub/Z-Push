<?php
/*
 * Copyright 2005 - 2012  Zarafa B.V.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation with the following additional
 * term according to sec. 7:
 *
 * According to sec. 7 of the GNU Affero General Public License, version
 * 3, the terms of the AGPL are supplemented with the following terms:
 *
 * "Zarafa" is a registered trademark of Zarafa B.V. The licensing of
 * the Program under the AGPL does not imply a trademark license.
 * Therefore any rights, title and interest in our trademarks remain
 * entirely with us.
 *
 * However, if you propagate an unmodified version of the Program you are
 * allowed to use the term "Zarafa" to indicate that you distribute the
 * Program. Furthermore you may use our trademarks where it is necessary
 * to indicate the intended purpose of a product or service provided you
 * use it in accordance with honest practices in industrial or commercial
 * matters.  If you want to propagate modified versions of the Program
 * under the name "Zarafa" or "Zarafa Server", you may only do so if you
 * have a written permission by Zarafa B.V. (to acquire a permission
 * please contact Zarafa at trademark@zarafa.com).
 *
 * The interactive user interface of the software displays an attribution
 * notice containing the term "Zarafa" and/or the logo of Zarafa.
 * Interactive user interfaces of unmodified and modified versions must
 * display Appropriate Legal Notices according to sec. 5 of the GNU
 * Affero General Public License, version 3, when you propagate
 * unmodified or modified versions of the Program. In accordance with
 * sec. 7 b) of the GNU Affero General Public License, version 3, these
 * Appropriate Legal Notices must retain the logo of Zarafa or display
 * the words "Initial Development by Zarafa" if the display of the logo
 * is not reasonably feasible for technical reasons. The use of the logo
 * of Zarafa in Legal Notices is allowed for unmodified and modified
 * versions of the software.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

define('IID_IStream',                           makeguid("{0000000c-0000-0000-c000-000000000046}"));
define('IID_IMAPITable',                        makeguid("{00020301-0000-0000-c000-000000000046}"));
define('IID_IMessage',                          makeguid("{00020307-0000-0000-c000-000000000046}"));
define('IID_IExchangeExportChanges',            makeguid("{a3ea9cc0-d1b2-11cd-80fc-00aa004bba0b}"));
define('IID_IExchangeImportContentsChanges',    makeguid("{f75abfa0-d0e0-11cd-80fc-00aa004bba0b}"));
define('IID_IExchangeImportHierarchyChanges',   makeguid("{85a66cf0-d0e0-11cd-80fc-00aa004bba0b}"));

define('PSETID_Appointment',                    makeguid("{00062002-0000-0000-C000-000000000046}"));
define('PSETID_Task',                           makeguid("{00062003-0000-0000-C000-000000000046}"));
define('PSETID_Address',                        makeguid("{00062004-0000-0000-C000-000000000046}"));
define('PSETID_Common',                         makeguid("{00062008-0000-0000-C000-000000000046}"));
define('PSETID_Log',                            makeguid("{0006200A-0000-0000-C000-000000000046}"));
define('PSETID_Note',                           makeguid("{0006200E-0000-0000-C000-000000000046}"));
define('PSETID_Meeting',                        makeguid("{6ED8DA90-450B-101B-98DA-00AA003F1305}"));
define('PSETID_Archive',                        makeguid("{72E98EBC-57D2-4AB5-B0AA-D50A7B531CB9}"));

define('PS_MAPI',                               makeguid("{00020328-0000-0000-C000-000000000046}"));
define('PS_PUBLIC_STRINGS',                     makeguid("{00020329-0000-0000-C000-000000000046}"));
define('PS_INTERNET_HEADERS',                   makeguid("{00020386-0000-0000-c000-000000000046}"));

// sk added for Z-Push
define ('PSETID_AirSync',                       makeguid("{71035549-0739-4DCB-9163-00F0580DBBDF}"));

?>