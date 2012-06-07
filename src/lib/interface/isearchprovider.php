<?php
/***********************************************
* File      :   isearchprovider.php
* Project   :   Z-Push
* Descr     :   The ISearchProvider interface for searching
*               functionalities on the mobile.
*
* Created   :   02.01.2012
*
* Copyright 2007 - 2012 Zarafa Deutschland GmbH
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

interface ISearchProvider {
    const SEARCH_GAL = "GAL";
    const SEARCH_MAILBOX = "MAILBOX";
    const SEARCH_DOCUMENTLIBRARY = "DOCUMENTLIBRARY";

    /**
     * Constructor
     *
     * @throws StatusException, FatalException
     */

    /**
     * Indicates if a search type is supported by this SearchProvider
     * Currently only the type SEARCH_GAL (Global Address List) is implemented
     *
     * @param string        $searchtype
     *
     * @access public
     * @return boolean
     */
    public function SupportsType($searchtype);

    /**
     * Searches the GAL
     *
     * @param string        $searchquery
     * @param string        $searchrange
     *
     * @access public
     * @return array
     * @throws StatusException
     */
    public function GetGALSearchResults($searchquery, $searchrange);

    /**
    * Searches for the emails on the server
    *
    * @param ContentParameter $cpo
    *
    * @return array
    */
    public function GetMailboxSearchResults($cpo);

    /**
    * Terminates a search for a given PID
    *
    * @param int $pid
    *
    * @return boolean
    */
    public function TerminateSearch($pid);


    /**
     * Disconnects from the current search provider
     *
     * @access public
     * @return boolean
     */
    public function Disconnect();
}

?>