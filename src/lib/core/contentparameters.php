<?php
/***********************************************
* File      :   contentparameters.php
* Project   :   Z-Push
* Descr     :   Simple transportation class for
*               requested content parameter options
*
* Created   :   11.04.2011
*
* Copyright 2007 - 2011 Zarafa Deutschland GmbH
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


class ContentParameters extends StateObject {
    protected $unsetdata = array(   'contentclass' => false,
                                    'foldertype' => '',
                                    'conflict' => false,
                                    'deletesasmoves' => true,
                                    'filtertype' => false,
                                    'truncation' => false,
                                    'rtftruncation' => false,
                                    'mimesupport' => false,
                                    'conversationmode' => false,
                                );

    private $synckeyChanged = false;

    /**
     * Expected magic getters and setters
     *
     * GetContentClass() + SetContentClass()
     * GetConflict() + SetConflict()
     * GetDeletesAsMoves() + SetDeletesAsMoves()
     * GetFilterType() + SetFilterType()
     * GetTruncation() + SetTruncation
     * GetRTFTruncation() + SetRTFTruncation()
     * GetMimeSupport () + SetMimeSupport()
     * GetMimeTruncation() + SetMimeTruncation()
     * GetConversationMode() + SetConversationMode()
     */

    /**
     * Overwrite StateObject->__call so we are able to handle ContentParameters->BodyPreference()
     *
     * @access public
     * @return mixed
     */
    public function __call($name, $arguments) {
        if ($name === "BodyPreference")
            return $this->BodyPreference($arguments[0]);

        return parent::__call($name, $arguments);
    }


    /**
     * Instantiates/returns the bodypreference object for a type
     *
     * @param int   $type
     *
     * @access public
     * @return int/boolean          returns false if value is not defined
     */
    public function BodyPreference($type) {
        if (!isset($this->bodypref))
            $this->bodypref = array();

        if (isset($this->bodypref[$type]))
            return $this->bodypref[$type];
        else {
            $asb = new BodyPreference();
            $arr = (array)$this->bodypref;
            $arr[$type] = $asb;
            $this->bodypref = $arr;
            return $asb;
        }
    }

    /**
     * Returns available body preference objects
     *
     *  @access public
     *  @return array/boolean       returns false if the client's body preference is not available
     */
    public function GetBodyPreference() {
        if (!isset($this->bodypref) || !(is_array($this->bodypref) || empty($this->bodypref))) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("ContentParameters->GetBodyPreference(): bodypref is empty or not set"));
            return false;
        }
        return array_keys($this->bodypref);
    }

    /**
     * Called before the StateObject is serialized
     *
     * @access protected
     * @return boolean
     */
    protected function preSerialize() {
        parent::preSerialize();

        if ($this->changed === true && $this->synckeyChanged)
            $this->lastsynctime = time();

        return true;
    }
}

?>