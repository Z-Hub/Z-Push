<?php
/***********************************************
* File      :   webserviceinfo.php
* Project   :   Z-Push
* Descr     :   Provides general information for an authenticated
*               user.
*
* Created   :   17.06.2016
*
* Copyright 2016 Zarafa Deutschland GmbH
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

class WebserviceInfo {

    /**
     * Returns a list of folders of the Request::GetGETUser().
     * If the user has not enough permissions an empty result is returned.
     *
     * @access public
     * @return array
     */
    public function ListUserFolders() {
        $user = Request::GetGETUser();
        $output = array();
        $hasRights = ZPush::GetBackend()->Setup($user);
        ZLog::Write(LOGLEVEL_INFO, sprintf("WebserviceInfo::ListUserFolders(): permissions to open store '%s': %s", $user, Utils::PrintAsString($hasRights)));

        if ($hasRights) {
            $folders = ZPush::GetBackend()->GetHierarchy();
            ZPush::GetTopCollector()->AnnounceInformation(sprintf("Retrieved details of %d folders", count($folders)), true);

            foreach ($folders as $folder) {
                $folder->StripData();
                unset($folder->Store, $folder->flags, $folder->content, $folder->NoBackendFolder);
                $output[] = $folder;
            }
        }

        return $output;
    }
}
