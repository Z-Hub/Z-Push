<?php
/***********************************************
* File      :   syncobjects.php
* Project   :   Z-Push
* Descr     :   Defines general behavoir of sub-WBXML
*               entities (Sync* objects) that can be parsed
*               directly (as a stream) from WBXML.
*               They are automatically decoded
*               according to $mapping by the Streamer,
*               and the Sync WBXML mappings.
*
* Created   :   01.10.2007
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


abstract class SyncObject extends Streamer {
    const STREAMER_CHECKS = 6;
    const STREAMER_CHECK_REQUIRED = 7;
    const STREAMER_CHECK_ZEROORONE = 8;
    const STREAMER_CHECK_NOTALLOWED = 9;
    const STREAMER_CHECK_ONEVALUEOF = 10;
    const STREAMER_CHECK_SETZERO = "setToValue0";
    const STREAMER_CHECK_SETONE = "setToValue1";
    const STREAMER_CHECK_SETTWO = "setToValue2";
    const STREAMER_CHECK_SETEMPTY = "setToValueEmpty";
    const STREAMER_CHECK_CMPLOWER = 13;
    const STREAMER_CHECK_CMPHIGHER = 14;
    const STREAMER_CHECK_LENGTHMAX = 15;
    const STREAMER_CHECK_EMAIL   = 16;

    protected $unsetVars;


    public function SyncObject($mapping) {
        $this->unsetVars = array();
        parent::Streamer($mapping);
    }

    /**
     * Sets all supported but not transmitted variables
     * of this SyncObject to an "empty" value, so they are deleted when being saved
     *
     * @param array     $supportedFields        array with all supported fields, if available
     *
     * @access public
     * @return boolean
     */
    public function emptySupported($supportedFields) {
        if ($supportedFields === false || !is_array($supportedFields))
            return false;

        foreach ($supportedFields as $field) {
            if (!isset($this->mapping[$field])) {
                ZLog::Write(LOGLEVEL_WARN, sprintf("Field '%s' is supposed to be emptied but is not defined for '%s'", $field, get_class($this)));
                continue;
            }
            $var = $this->mapping[$field][self::STREAMER_VAR];
            // add var to $this->unsetVars if $var is not set
            if (!isset($this->$var))
                $this->unsetVars[] = $var;
        }
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("Supported variables to be unset: %s", implode(',', $this->unsetVars)));
        return true;
    }


    /**
     * Compares this a SyncObject to another.
     * In case that all available mapped fields are exactly EQUAL, it returns true
     *
     * @see SyncObject
     * @param SyncObject $odo other SyncObject
     * @return boolean
     */
    public function equals($odo, $log = false) {
        if ($odo === false)
            return false;

        // check objecttype
        if (! ($odo instanceof SyncObject)) {
            ZLog::Write(LOGLEVEL_DEBUG, "SyncObject->equals() the target object is not a SyncObject");
            return false;
        }

        // check for mapped fields
        foreach ($this->mapping as $v) {
            $val = $v[self::STREAMER_VAR];
            // array of values?
            if (isset($v[self::STREAMER_ARRAY])) {
                // seek for differences in the arrays
                if (is_array($this->$val) && is_array($odo->$val)) {
                    if (count(array_diff($this->$val, $odo->$val)) + count(array_diff($odo->$val, $this->$val)) > 0) {
                        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SyncObject->equals() items in array '%s' differ", $val));
                        return false;
                    }
                }
                else {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("SyncObject->equals() array '%s' is set in one but not the other object", $val));
                    return false;
                }
            }
            else {
                if (isset($this->$val) && isset($odo->$val)) {
                    if ($this->$val != $odo->$val){
                        ZLog::Write(LOGLEVEL_DEBUG, sprintf("SyncObject->equals() false on field '%s': '%s' != '%s'", $val, Utils::PrintAsString($this->$val), Utils::PrintAsString($odo->$val)));
                        return false;
                    }
                }
                else if (!isset($this->$val) && !isset($odo->$val)) {
                    continue;
                }
                else {
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("SyncObject->equals() false because field '%s' is only defined at one obj: '%s' != '%s'", $val, Utils::PrintAsString(isset($this->$val)), Utils::PrintAsString(isset($odo->$val))));
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * String representation of the object
     *
     * @return String
     */
    public function __toString() {
        $str = get_class($this) . " (\n";

        $streamerVars = array();
        foreach ($this->mapping as $k=>$v)
            $streamerVars[$v[self::STREAMER_VAR]] = (isset($v[self::STREAMER_TYPE]))?$v[self::STREAMER_TYPE]:false;

        foreach (get_object_vars($this) as $k=>$v) {
            if ($k == "mapping") continue;

            if (array_key_exists($k, $streamerVars))
                $strV = "(S) ";
            else
                $strV = "";

            // self::STREAMER_ARRAY ?
            if (is_array($v)) {
                $str .= "\t". $strV . $k ."(Array) size: " . count($v) ."\n";
                foreach ($v as $value) $str .= "\t\t". Utils::PrintAsString($value) ."\n";
            }
            else if ($v instanceof SyncObject) {
                $str .= "\t". $strV .$k ." => ". str_replace("\n", "\n\t\t\t", $v->__toString()) . "\n";
            }
            else
                $str .= "\t". $strV .$k ." => " . (isset($this->$k)? Utils::PrintAsString($this->$k) :"null") . "\n";
        }
        $str .= ")";

        return $str;
    }

    /**
     * Returns the properties which have to be unset on the server
     *
     * @access public
     * @return array
     */
    public function getUnsetVars() {
        return $this->unsetVars;
    }

    /**
     * Method checks if the object has the minimum of required parameters
     * and fullfills semantic dependencies
     *
     * General checks:
     *     STREAMER_CHECK_REQUIRED      may have as value false (do not fix, ignore object!) or set-to-values: STREAMER_CHECK_SETZERO/ONE/TWO, STREAMER_CHECK_SETEMPTY
     *     STREAMER_CHECK_ZEROORONE     may be 0 or 1, if none of these, set-to-values: STREAMER_CHECK_SETZERO or STREAMER_CHECK_SETONE
     *     STREAMER_CHECK_NOTALLOWED    fails if is set
     *     STREAMER_CHECK_ONEVALUEOF    expects an array with accepted values, fails if value is not in array
     *
     * Comparison:
     *     STREAMER_CHECK_CMPLOWER      compares if the current parameter is lower as a literal or another parameter of the same object
     *     STREAMER_CHECK_CMPHIGHER     compares if the current parameter is higher as a literal or another parameter of the same object
     *
     * @param boolean   $logAsDebug     (opt) default is false, so messages are logged in WARN log level
     *
     * @access public
     * @return boolean
     */
    public function Check($logAsDebug = false) {
        // semantic checks general "turn off switch"
        if (defined("DO_SEMANTIC_CHECKS") && DO_SEMANTIC_CHECKS === false) {
            ZLog::Write(LOGLEVEL_DEBUG, "SyncObject->Check(): semantic checks disabled. Check your config for 'DO_SEMANTIC_CHECKS'.");
            return true;
        }

        $defaultLogLevel = LOGLEVEL_WARN;

        // in some cases non-false checks should not provoke a WARN log but only a DEBUG log
        if ($logAsDebug)
            $defaultLogLevel = LOGLEVEL_DEBUG;

        $objClass = get_class($this);
        foreach ($this->mapping as $k=>$v) {

            // check sub-objects recursively
            if (isset($v[self::STREAMER_TYPE]) && isset($this->$v[self::STREAMER_VAR])) {
                if ($this->$v[self::STREAMER_VAR] instanceof SyncObject) {
                    if (! $this->$v[self::STREAMER_VAR]->Check($logAsDebug))
                        return false;
                }
                else if (is_array($this->$v[self::STREAMER_VAR])) {
                    foreach ($this->$v[self::STREAMER_VAR] as $subobj)
                        if ($subobj instanceof SyncObject && !$subobj->Check($logAsDebug))
                            return false;
                }
            }

            if (isset($v[self::STREAMER_CHECKS])) {
                foreach ($v[self::STREAMER_CHECKS] as $rule => $condition) {
                    // check REQUIRED settings
                    if ($rule === self::STREAMER_CHECK_REQUIRED && (!isset($this->$v[self::STREAMER_VAR]) || $this->$v[self::STREAMER_VAR] === '' ) ) {
                        // parameter is not set but ..
                        // requested to set to 0
                        if ($condition === self::STREAMER_CHECK_SETZERO) {
                            $this->$v[self::STREAMER_VAR] = 0;
                            ZLog::Write($defaultLogLevel, sprintf("SyncObject->Check(): Fixed object from type %s: parameter '%s' is set to 0", $objClass, $v[self::STREAMER_VAR]));
                        }
                        // requested to be set to 1
                        else if ($condition === self::STREAMER_CHECK_SETONE) {
                            $this->$v[self::STREAMER_VAR] = 1;
                            ZLog::Write($defaultLogLevel, sprintf("SyncObject->Check(): Fixed object from type %s: parameter '%s' is set to 1", $objClass, $v[self::STREAMER_VAR]));
                        }
                        // requested to be set to 2
                        else if ($condition === self::STREAMER_CHECK_SETTWO) {
                            $this->$v[self::STREAMER_VAR] = 2;
                            ZLog::Write($defaultLogLevel, sprintf("SyncObject->Check(): Fixed object from type %s: parameter '%s' is set to 2", $objClass, $v[self::STREAMER_VAR]));
                        }
                        // requested to be set to ''
                        else if ($condition === self::STREAMER_CHECK_SETEMPTY) {
                            if (!isset($this->$v[self::STREAMER_VAR])) {
                                $this->$v[self::STREAMER_VAR] = '';
                                ZLog::Write($defaultLogLevel, sprintf("SyncObject->Check(): Fixed object from type %s: parameter '%s' is set to ''", $objClass, $v[self::STREAMER_VAR]));
                            }
                        }
                        // there is another value !== false
                        else if ($condition !== false) {
                            $this->$v[self::STREAMER_VAR] = $condition;
                            ZLog::Write($defaultLogLevel, sprintf("SyncObject->Check(): Fixed object from type %s: parameter '%s' is set to '%s'", $objClass, $v[self::STREAMER_VAR], $condition));

                        }
                        // no fix available!
                        else {
                            ZLog::Write($defaultLogLevel, sprintf("SyncObject->Check(): Unmet condition in object from type %s: parameter '%s' is required but not set. Check failed!", $objClass, $v[self::STREAMER_VAR]));
                            return false;
                        }
                    } // end STREAMER_CHECK_REQUIRED


                    // check STREAMER_CHECK_ZEROORONE
                    if ($rule === self::STREAMER_CHECK_ZEROORONE && isset($this->$v[self::STREAMER_VAR])) {
                        if ($this->$v[self::STREAMER_VAR] != 0 && $this->$v[self::STREAMER_VAR] != 1) {
                            $newval = $condition === self::STREAMER_CHECK_SETZERO ? 0:1;
                            $this->$v[self::STREAMER_VAR] = $newval;
                            ZLog::Write($defaultLogLevel, sprintf("SyncObject->Check(): Fixed object from type %s: parameter '%s' is set to '%s' as it was not 0 or 1", $objClass, $v[self::STREAMER_VAR], $newval));
                        }
                    }// end STREAMER_CHECK_ZEROORONE


                    // check STREAMER_CHECK_ONEVALUEOF
                    if ($rule === self::STREAMER_CHECK_ONEVALUEOF && isset($this->$v[self::STREAMER_VAR])) {
                        if (!in_array($this->$v[self::STREAMER_VAR], $condition)) {
                            ZLog::Write($defaultLogLevel, sprintf("SyncObject->Check(): object from type %s: parameter '%s'->'%s' is not in the range of allowed values.", $objClass, $v[self::STREAMER_VAR], $this->$v[self::STREAMER_VAR]));
                            return false;
                        }
                    }// end STREAMER_CHECK_ONEVALUEOF


                    // Check value compared to other value or literal
                    if ($rule === self::STREAMER_CHECK_CMPHIGHER || $rule === self::STREAMER_CHECK_CMPLOWER) {
                        if (isset($this->$v[self::STREAMER_VAR])) {
                            $cmp = false;
                            // directly compare against literals
                            if (is_int($condition)) {
                                $cmp = $condition;
                            }
                            // check for invalid compare-to
                            else if (!isset($this->mapping[$condition])) {
                                ZLog::Write(LOGLEVEL_ERROR, sprintf("SyncObject->Check(): Can not compare parameter '%s' against the other value '%s' as it is not defined object from type %s. Please report this! Check skipped!", $objClass, $v[self::STREAMER_VAR], $condition));
                                continue;
                            }
                            else {
                                $cmpPar = $this->mapping[$condition][self::STREAMER_VAR];
                                if (isset($this->$cmpPar))
                                    $cmp = $this->$cmpPar;
                            }

                            if ($cmp === false) {
                                ZLog::Write(LOGLEVEL_WARN, sprintf("SyncObject->Check(): Unmet condition in object from type %s: parameter '%s' can not be compared, as the comparable is not set. Check failed!", $objClass, $v[self::STREAMER_VAR]));
                                return false;
                            }
                            if ( ($rule == self::STREAMER_CHECK_CMPHIGHER && $this->$v[self::STREAMER_VAR] < $cmp) ||
                                 ($rule == self::STREAMER_CHECK_CMPLOWER  && $this->$v[self::STREAMER_VAR] > $cmp)
                                ) {

                                ZLog::Write(LOGLEVEL_WARN, sprintf("SyncObject->Check(): Unmet condition in object from type %s: parameter '%s' is %s than '%s'. Check failed!",
                                                                    $objClass,
                                                                    $v[self::STREAMER_VAR],
                                                                    (($rule === self::STREAMER_CHECK_CMPHIGHER)?'LOWER':'HIGHER'),
                                                                    ((isset($cmpPar)?$cmpPar:$condition))  ));
                                return false;
                            }
                        }
                    } // STREAMER_CHECK_CMP*


                    // check STREAMER_CHECK_LENGTHMAX
                    if ($rule === self::STREAMER_CHECK_LENGTHMAX && isset($this->$v[self::STREAMER_VAR])) {

                        if (is_array($this->$v[self::STREAMER_VAR])) {
                            // implosion takes 2bytes, so we just assume ", " here
                            $chkstr = implode(", ", $this->$v[self::STREAMER_VAR]);
                        }
                        else
                            $chkstr = $this->$v[self::STREAMER_VAR];

                        if (strlen($chkstr) > $condition) {
                            ZLog::Write(LOGLEVEL_WARN, sprintf("SyncObject->Check(): object from type %s: parameter '%s' is longer than %d. Check failed", $objClass, $v[self::STREAMER_VAR], $condition));
                            return false;
                        }
                    }// end STREAMER_CHECK_LENGTHMAX


                    // check STREAMER_CHECK_EMAIL
                    // if $condition is false then the check really fails. Otherwise invalid emails are removed.
                    // if nothing is left (all emails were false), the parameter is set to condition
                    if ($rule === self::STREAMER_CHECK_EMAIL && isset($this->$v[self::STREAMER_VAR])) {
                        if ($condition === false && ( (is_array($this->$v[self::STREAMER_VAR]) && empty($this->$v[self::STREAMER_VAR])) || strlen($this->$v[self::STREAMER_VAR]) == 0) )
                            continue;

                        $as_array = false;

                        if (is_array($this->$v[self::STREAMER_VAR])) {
                            $mails = $this->$v[self::STREAMER_VAR];
                            $as_array = true;
                        }
                        else {
                            $mails = array( $this->$v[self::STREAMER_VAR] );
                        }

                        $output = array();
                        foreach ($mails as $mail) {
                            if (! Utils::CheckEmail($mail)) {
                                ZLog::Write(LOGLEVEL_WARN, sprintf("SyncObject->Check(): object from type %s: parameter '%s' contains an invalid email address '%s'. Address is removed.", $objClass, $v[self::STREAMER_VAR], $mail));
                            }
                            else
                                $output[] = $mail;
                        }
                        if (count($mails) != count($output)) {
                            if ($condition === false)
                                return false;

                            // nothing left, use $condition as new value
                            if (count($output) == 0)
                                $output[] = $condition;

                            // if we are allowed to rewrite the attribute, we do that
                            if ($as_array)
                                $this->$v[self::STREAMER_VAR] = $output;
                            else
                                $this->$v[self::STREAMER_VAR] = $output[0];
                        }
                    }// end STREAMER_CHECK_EMAIL


                } // foreach CHECKS
            } // isset CHECKS
        } // foreach mapping

        return true;
    }
}

?>