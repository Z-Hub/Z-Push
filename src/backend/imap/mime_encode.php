<?php

/***********************************************
* File      :   mime_encode.php
* Project   :   Z-Push
* Descr     :   Functions for using within the IMAP backend
*
* Created   :   2014
*
* Copyright 2014 - 2016 Zarafa Deutschland GmbH
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

/**
 * Add extra parts (not text; inlined or attached parts) to a mimepart object.
 *
 * @param Mail_mimePart $email reference to the object
 * @param array $parts array of parts
 *
 * @return void
 */
/**
 * Recursively adds extra sub-parts to the email.
 * * @param Mail_mime $email The email object
 * @param array $parts The parts to add
 * @param bool $inside_container Whether we are currently inside a nested container (e.g. .eml)
 */
function add_extra_sub_parts(&$email, $parts, $inside_container = false) {
    if (isset($parts) && is_array($parts)) {
        foreach ($parts as $part) {
            $new_part = null;
            $should_add = false;

            // Normalize types for checking
            $ctype_primary = isset($part->ctype_primary) ? strtolower($part->ctype_primary) : '';
            
            $is_text = ($ctype_primary == 'text');
            $is_multipart = ($ctype_primary == 'multipart');
            $is_message = ($ctype_primary == 'message'); 
            $is_attachment = (isset($part->disposition) && strtolower($part->disposition) == 'attachment');

            // Decision logic
            if ($inside_container) {
                // Inside a container (e.g. .eml), we must add everything to preserve the structure
                $should_add = true;
            } 
            else {
                // At the root level:
                // We skip 'multipart' containers to avoid duplicating the structure in the main body.
                // We add messages (.eml), attachments, and anything that isn't plain text.
                if ($is_multipart) {
                    $should_add = false;
                }
                elseif ($is_message) {
                    $should_add = true;
                }
                elseif ($is_attachment || !$is_text) {
                    $should_add = true;
                }
            }

            // Add the part if applicable
            if ($should_add) {
                $new_part = add_sub_part($email, $part);
            }

            // Recursive step
            if (isset($part->parts)) {
                if ($new_part !== null) {
                    // New container created; dive in with $inside_container = true
                    add_extra_sub_parts($new_part, $part->parts, true);
                } 
                else {
                    // Container skipped (flattening); continue with current context
                    add_extra_sub_parts($email, $part->parts, $inside_container);
                }
            }
        }
    }
}

/**
 * Add a subpart to a mimepart object.
 *
 * @param Mail_mimePart $email reference to the object
 * @param object $part message part
 *
 * @return mixed The new part object or null
 */
function add_sub_part(&$email, $part) {
    // http://tools.ietf.org/html/rfc4021
    $new_part = null;
    $params = array();
    
    // Normalize types
    $ctype_p = isset($part->ctype_primary) ? strtolower($part->ctype_primary) : '';
    $ctype_s = isset($part->ctype_secondary) ? strtolower($part->ctype_secondary) : '';

    // Identify containers (multipart or message/rfc822)
    $is_container = (
        $ctype_p == 'multipart' || 
        ($ctype_p == 'message' && $ctype_s == 'rfc822')
    );

    if (isset($part) && isset($email)) {
        
        // 1. Build Content-Type
        $params['content_type'] = '';
        if (isset($part->ctype_primary)) {
            $params['content_type'] = $part->ctype_primary;
            if (isset($part->ctype_secondary)) {
                $params['content_type'] .= '/' . $part->ctype_secondary;
            }
        }
        
        // 2. Parameters (Boundary, Charset, etc.)
        if (isset($part->ctype_parameters)) {
            foreach ($part->ctype_parameters as $k => $v) {
                // Skip old boundary; lib will generate a new one
                if(strcasecmp($k, 'boundary') != 0) {
                    $params['content_type'] .= '; ' . $k . '=' . $v;
                }
            }
        }
        
        if (isset($part->disposition)) {
            $params['disposition'] = $part->disposition;
        }
        
        if (isset($part->d_parameters)) {
            $params['headers_charset'] = 'utf-8';
            foreach ($part->d_parameters as $k => $v) {
                $params[$k] = $v;
            }
        }
        
        // 3. Header Copying with Filtering
        foreach ($part->headers as $k => $v) {
            $key = strtolower($k);
            switch($key) {
                // Blacklist headers that cause data corruption or conflicts
                case "content-type":
                case "content-disposition":
                case "content-transfer-encoding": 
                case "content-length": // Critical: Prevents 0-byte attachments
                case "mime-version":
                    break;
                
                case "content-id":
                    $params['cid'] = str_replace(array('<', '>'), '', $v);
                    break;
                    
                case "content-description":
                    $params['description'] = $v;
                    break;

                default:
                    $params[$k] = $v;
                    break;
            }
        }

        // 4. Body and Encoding
        if ($is_container) {
            // Containers have empty bodies and should not force encoding
            $body_content = "";
            if (isset($params['encoding'])) unset($params['encoding']);
        } else {
            // Files/Leaves: Use body and force base64 for safety
            $body_content = isset($part->body) ? $part->body : "";
            $params['encoding'] = 'base64';
        }

        // Add the subpart
        $new_part = $email->addSubPart($body_content, $params);
        
        unset($params);
    }

    return $new_part;
}

/**
 * Add a subpart to a mimepart object.
 *
 * @param Mail_mimePart $email reference to the object
 * @param object $part message part
 *
 * @return void
 */
function change_charset_and_add_subparts(&$email, $part) {
    if (isset($part)) {
        $new_part = null;
        if (isset($part->ctype_parameters['charset'])) {
            $part->ctype_parameters['charset'] = 'UTF-8';

            Utils::CheckAndFixEncoding($part->body);

            $new_part = add_sub_part($email, $part);
        }
        else {
            // We don't add the charset because it could be a non-text part
            $new_part = add_sub_part($email, $part);
        }

        if (isset($part->parts)) {
            foreach ($part->parts as $subpart) {
                // Subparts are added to the part, not the main message
                change_charset_and_add_subparts($new_part, $subpart);
            }
        }
    }
}

/**
 * Creates a MIME message from a decoded MIME message, reencoding and fixing the text.
 *
 * @param array $message array returned from Mail_mimeDecode->decode
 *
 * @return string MIME message
 */
function build_mime_message($message) {
    $finalEmail = new Mail_mimePart(isset($message->body) ? $message->body : "", array('headers' => $message->headers));
    if (isset($message->parts)) {
        foreach ($message->parts as $part) {
            change_charset_and_add_subparts($finalEmail, $part);
        }
    }

    $mimeHeaders = Array();
    $mimeHeaders['headers'] = Array();
    $is_mime = false;
    foreach ($message->headers as $key => $value) {
        switch($key) {
            case 'content-type':
                $new_value = $message->ctype_primary . "/" . $message->ctype_secondary;
                $is_mime = (strcasecmp($message->ctype_primary, 'multipart') == 0);

                if (isset($message->ctype_parameters)) {
                    foreach ($message->ctype_parameters as $ckey => $cvalue) {
                        switch($ckey) {
                            case 'charset':
                                $new_value .= '; charset="UTF-8"';
                                break;
                            case 'boundary':
                                // Do nothing, we are encoding also the headers
                                break;
                            default:
                                $new_value .= '; ' . $ckey . '="' . $cvalue . '"';
                                break;
                        }
                    }
                }

                $mimeHeaders['content_type'] = $new_value;
                break;
            case 'content-transfer-encoding':
                if (is_string($value) && (strcasecmp($value, "base64") == 0 || strcasecmp($value, "binary") == 0)) {
                    $mimeHeaders['encoding'] = "base64";
                }
                else {
                    $mimeHeaders['encoding'] = "8bit";
                }
                break;
            case 'content-id':
                $mimeHeaders['cid'] = $value;
                break;
            case 'content-location':
                $mimeHeaders['location'] = $value;
                break;
            case 'content-disposition':
                $mimeHeaders['disposition'] = $value;
                break;
            case 'content-description':
                $mimeHeaders['description'] = $value;
                break;
            default:
                if (is_array($value)) {
                    foreach($value as $v) {
                        $mimeHeaders['headers'][$key] = $v;
                    }
                }
                else {
                    $mimeHeaders['headers'][$key] = $value;
                }
                break;
        }
    }

    Utils::CheckAndFixEncoding($message->body);

    $finalEmail = new Mail_mimePart(isset($message->body) ? $message->body : "", $mimeHeaders);
    unset($mimeHeaders);

    if (isset($message->parts)) {
        foreach ($message->parts as $part) {
            change_charset_and_add_subparts($finalEmail, $part);
        }
    }

    $boundary = '=_' . md5(rand() . microtime());
    $finalEmail = $finalEmail->encode($boundary);

    $headers = "";
    $mimePart = new Mail_mimePart();
    foreach ($finalEmail['headers'] as $key => $value) {
        if (is_array($value)) {
            foreach ($values as $ikey => $ivalue) {
                $headers .= $key . ": " . $mimePart->encodeHeader($key, $ivalue, "utf-8", "base64") . "\n";
            }
        }
        else {
            $headers .= $key . ": " . $mimePart->encodeHeader($key, $value, "utf-8", "base64") . "\n";
        }
    }
    unset($mimePart);


    if ($is_mime) {
        $built_message = "$headers\nThis is a multi-part message in MIME format.\n".$finalEmail['body'];
    }
    else {
        $built_message = "$headers\n".$finalEmail['body'];
    }
    unset($headers);
    unset($finalEmail);

    return $built_message;
}


/**
 * Detect if the message-part is SMIME
 *
 * @param Mail_mimeDecode $message
 * @return boolean
 */
function is_smime($message) {
    $res = false;

    if (isset($message->ctype_primary) && isset($message->ctype_secondary)) {
        $smime_types = array(array("multipart", "signed"), array("application", "pkcs7-mime"), array("application", "x-pkcs7-mime"), array("multipart", "encrypted"));
        for ($i = 0; $i < count($smime_types) && !$res; $i++) {
            $res = ($message->ctype_primary == $smime_types[$i][0] && $message->ctype_secondary == $smime_types[$i][1]);
        }
    }

    return $res;
}


/**
 * Detect if the message-part is SMIME, encrypted but not signed
 * #190, KD 2015-06-04
 *
 * @param Mail_mimeDecode $message
 * @return boolean
 */
function is_encrypted($message) {
    $res = false;

    if (is_smime($message) && !($message->ctype_primary == "multipart" && $message->ctype_secondary == "signed")) {
        $res = true;
    }

    return $res;
}


/**
 * Detect if the message is multipart.
 * #198, KD 2015-06-15
 *
 * @param Mail_mimeDecode $message
 * @return boolean
 */
function is_multipart($message) {
    return isset($message->ctype_primary) && $message->ctype_primary == "multipart";
}
