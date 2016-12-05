<?php
/***********************************************
* File      :   z_ical.php
* Project   :   Z-Push
* Descr     :   This is a very basic iCalendar parser
*               used to process incoming meeting requests
*               and responses.
*
* Created   :   01.12.2008
*
* Copyright 2007 - 2016 Zarafa Deutschland GmbH
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
************************************************/

class ICalParser{
    private $props;

    /**
     * Constructor
     *
     * @param mapistore     $store
     * @param array         &$props     properties to be set
     *
     * @access public
     */
    public function __construct(&$store, &$props){
         $this->props = $props;
    }

    /**
     * Function reads calendar part and puts mapi properties into an array.
     *
     * @param string        $ical       the ical data
     * @param array         &$mapiprops mapi properties
     *
     * @access public
     */
    public function ExtractProps($ical, &$mapiprops) {
        //mapping between partstat in ical and MAPI Meeting Response classes as well as icons
        $aClassMap = array(
            "ACCEPTED"          => array("class" => "IPM.Schedule.Meeting.Resp.Pos", "icon" => 0x405),
            "DECLINED"          => array("class" => "IPM.Schedule.Meeting.Resp.Neg", "icon" => 0x406),
            "TENTATIVE"         => array("class" => "IPM.Schedule.Meeting.Resp.Tent", "icon" => 0x407),
            "NEEDS-ACTION"      => array("class" => "IPM.Schedule.Meeting.Request", "icon" => 0x404), //iphone
            "REQ-PARTICIPANT"   => array("class" => "IPM.Schedule.Meeting.Request", "icon" => 0x404), //nokia
        );

        $aical = preg_split("/[\n]/", $ical);
        $elemcount = count($aical);
        $i=0;
        $nextline = $aical[0];

        //last element is empty
        while ($i < $elemcount - 1) {
            $line = $nextline;
            $nextline = $aical[$i+1];

            //if a line starts with a space or a tab it belongs to the previous line
            while (strlen($nextline) > 0 && ($nextline{0} == " " || $nextline{0} == "\t")) {
                $line = rtrim($line) . substr($nextline, 1);
                $nextline = $aical[++$i + 1];
            }
            $line = rtrim($line);

            switch (strtoupper($line)) {
                case "BEGIN:VCALENDAR":
                case "BEGIN:VEVENT":
                case "END:VEVENT":
                case "END:VCALENDAR":
                    break;
                default:
                    unset ($field, $data, $prop_pos, $property);
                    if (preg_match ("/([^:]+):(.*)/", $line, $line)){
                        $field = $line[1];
                        $data = $line[2];
                        $property = $field;
                        $prop_pos = strpos($property,';');
                        if ($prop_pos !== false) $property = substr($property, 0, $prop_pos);
                        $property = strtoupper($property);

                        switch ($property) {
                            case 'DTSTART':
                                $data = $this->getTimestampFromStreamerDate($data);
                                $mapiprops[$this->props["starttime"]] = $mapiprops[$this->props["commonstart"]] = $mapiprops[$this->props["clipstart"]] = $mapiprops[PR_START_DATE] = $data;
                                break;

                            case 'DTEND':
                                $data = $this->getTimestampFromStreamerDate($data);
                                $mapiprops[$this->props["endtime"]] = $mapiprops[$this->props["commonend"]] = $mapiprops[$this->props["recurrenceend"]] = $mapiprops[PR_END_DATE] = $data;
                                break;

                            case 'UID':
                                $mapiprops[$this->props["goidtag"]] = $mapiprops[$this->props["goid2tag"]] = Utils::GetOLUidFromICalUid($data);
                                break;

                            case 'ATTENDEE':
                                $fields = explode(";", $field);
                                foreach ($fields as $field) {
                                    $prop_pos     = strpos($field, '=');
                                    if ($prop_pos !== false) {
                                        switch (substr($field, 0, $prop_pos)) {
                                            case 'PARTSTAT'    : $partstat = substr($field, $prop_pos+1); break;
                                            case 'CN'        : $cn = substr($field, $prop_pos+1); break;
                                            case 'ROLE'        : $role = substr($field, $prop_pos+1); break;
                                            case 'RSVP'        : $rsvp = substr($field, $prop_pos+1); break;
                                        }
                                    }
                                }
                                if (isset($partstat) && isset($aClassMap[$partstat]) &&

                                   (!isset($mapiprops[PR_MESSAGE_CLASS]) || $mapiprops[PR_MESSAGE_CLASS] == "IPM.Schedule.Meeting.Request")) {
                                    $mapiprops[PR_MESSAGE_CLASS] = $aClassMap[$partstat]['class'];
                                    $mapiprops[PR_ICON_INDEX] = $aClassMap[$partstat]['icon'];
                                }
                                // START ADDED dw2412 to support meeting requests on HTC Android Mail App
                                elseif (isset($role) && isset($aClassMap[$role]) &&
                                   (!isset($mapiprops[PR_MESSAGE_CLASS]) || $mapiprops[PR_MESSAGE_CLASS] == "IPM.Schedule.Meeting.Request")) {
                                    $mapiprops[PR_MESSAGE_CLASS] = $aClassMap[$role]['class'];
                                    $mapiprops[PR_ICON_INDEX] = $aClassMap[$role]['icon'];
                                }
                                // END ADDED dw2412 to support meeting requests on HTC Android Mail App
                                if (!isset($cn)) $cn = "";
                                $data         = str_replace ("MAILTO:", "", $data);
                                $attendee[] = array ('name' => stripslashes($cn), 'email' => stripslashes($data));
                                break;

                            case 'ORGANIZER':
                                $field          = str_replace("ORGANIZER;CN=", "", $field);
                                $data          = str_replace ("MAILTO:", "", $data);
                                $organizer[] = array ('name' => stripslashes($field), 'email' => stripslashes($data));
                                break;

                            case 'LOCATION':
                                $data = str_replace("\\n", "<br />", $data);
                                $data = str_replace("\\t", "&nbsp;", $data);
                                $data = str_replace("\\r", "<br />", $data);
                                $data = stripslashes($data);
                                $mapiprops[$this->props["tneflocation"]] = $mapiprops[$this->props["location"]] = $data;
                                break;
                        }
                    }
                    break;
            }
            $i++;

        }
        $mapiprops[$this->props["usetnef"]] = true;
    }

    /**
     * Converts an YYYYMMDDTHHMMSSZ kind of string into an unixtimestamp
     *
     * @param string $data
     *
     * @access private
     * @return long
     */
    private function getTimestampFromStreamerDate ($data) {
        $data = str_replace('Z', '', $data);
        $data = str_replace('T', '', $data);

        preg_match ('/([0-9]{4})([0-9]{2})([0-9]{2})([0-9]{0,2})([0-9]{0,2})([0-9]{0,2})/', $data, $regs);
        if ($regs[1] < 1970) {
            $regs[1] = '1971';
        }
        return gmmktime($regs[4], $regs[5], $regs[6], $regs[2], $regs[3], $regs[1]);
    }
}
