<?php
/***********************************************
* File      :   wbxmldecoder.php
* Project   :   Z-Push
* Descr     :   WBXMLDecoder decodes from Wap Binary XML
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


class WBXMLDecoder extends WBXMLDefs {
    private $in;

    private $version;
    private $publicid;
    private $publicstringid;
    private $charsetid;
    private $stringtable;

    private $tagcp = 0;
    private $attrcp = 0;

    private $ungetbuffer;

    private $logStack = array();

    /**
     * WBXML Decode Constructor
     *
     * @param  stream      $input          the incoming data stream
     *
     * @access public
     */
    public function WBXMLDecoder($input) {
        // make sure WBXML_DEBUG is defined. It should be at this point
        if (!defined('WBXML_DEBUG')) define('WBXML_DEBUG', false);

        $this->in = $input;

        $this->version = $this->getByte();
        $this->publicid = $this->getMBUInt();
        if($this->publicid == 0) {
            $this->publicstringid = $this->getMBUInt();
        }

        $this->charsetid = $this->getMBUInt();
        $this->stringtable = $this->getStringTable();
    }

    /**
     * Returns either start, content or end, and auto-concatenates successive content
     *
     * @access public
     * @return element/value
     */
    public function getElement() {
        $element = $this->getToken();

        switch($element[EN_TYPE]) {
            case EN_TYPE_STARTTAG:
                return $element;
            case EN_TYPE_ENDTAG:
                return $element;
            case EN_TYPE_CONTENT:
                while(1) {
                    $next = $this->getToken();
                    if($next == false)
                        return false;
                    else if($next[EN_TYPE] == EN_CONTENT) {
                        $element[EN_CONTENT] .= $next[EN_CONTENT];
                    } else {
                        $this->ungetElement($next);
                        break;
                    }
                }
                return $element;
        }

        return false;
    }

    /**
     * Get a peek at the next element
     *
     * @access public
     * @return element
     */
    public function peek() {
        $element = $this->getElement();
        $this->ungetElement($element);
        return $element;
    }

    /**
     * Get the element of a StartTag
     *
     * @param $tag
     *
     * @access public
     * @return element/boolean      returns false if not available
     */
    public function getElementStartTag($tag) {
        $element = $this->getToken();

        if($element[EN_TYPE] == EN_TYPE_STARTTAG && $element[EN_TAG] == $tag)
            return $element;
        else {
            ZLog::Write(LOGLEVEL_WBXMLSTACK, "Unmatched tag $tag:");
            ZLog::Write(LOGLEVEL_WBXMLSTACK, print_r($element,true));
            $this->ungetElement($element);
        }

        return false;
    }

    /**
     * Get the element of a EndTag
     *
     * @access public
     * @return element/boolean      returns false if not available
     */
    public function getElementEndTag() {
        $element = $this->getToken();

        if($element[EN_TYPE] == EN_TYPE_ENDTAG)
            return $element;
        else {
            ZLog::Write(LOGLEVEL_WBXMLSTACK, "Unmatched end tag:");
            ZLog::Write(LOGLEVEL_WBXMLSTACK, print_r($element,true));
            $bt = debug_backtrace();
            $c = count($bt);
            ZLog::Write(LOGLEVEL_WBXML, print_r($bt,true));
            ZLog::Write(LOGLEVEL_WBXMLSTACK, "From " . $bt[$c-2]["file"] . ":" . $bt[$c-2]["line"]);
            $this->ungetElement($element);
        }

        return false;
    }

    /**
     * Get the content of an element
     *
     * @access public
     * @return string/boolean       returns false if not available
     */
    public function getElementContent() {
        $element = $this->getToken();

        if($element[EN_TYPE] == EN_TYPE_CONTENT) {
            return $element[EN_CONTENT];
        }
        else {
            ZLog::Write(LOGLEVEL_WBXMLSTACK, "Unmatched content:");
            ZLog::Write(LOGLEVEL_WBXMLSTACK, print_r($element, true));
            $this->ungetElement($element);
        }

        return false;
    }

    /**
     * 'Ungets' an element writing it into a buffer to be 'get' again
     *
     * @param element       $element        the element to get ungetten
     *
     * @access public
     * @return
     */
    public function ungetElement($element) {
        if($this->ungetbuffer)
            ZLog::Write(LOGLEVEL_ERROR,"WBXML - Double unget!");

        $this->ungetbuffer = $element;
    }

    /**----------------------------------------------------------------------------------------------------------
     * Private WBXMLDecoder stuff
     */

    /**
     * Returns the next token
     *
     * @access private
     * @return token
     */
    private function getToken() {
        // See if there's something in the ungetBuffer
        if($this->ungetbuffer) {
            $element = $this->ungetbuffer;
            $this->ungetbuffer = false;
            return $element;
        }

        $el = $this->_getToken();
        $this->logToken($el);

        return $el;
    }

    /**
     * Log the a token to ZLog
     *
     * @param string    $el         token
     *
     * @access private
     * @return
     */
    private function logToken($el) {
        if(!WBXML_DEBUG)
            return;

        $spaces = str_repeat(" ", count($this->logStack));

        switch($el[EN_TYPE]) {
            case EN_TYPE_STARTTAG:
                if($el[EN_FLAGS] & EN_FLAGS_CONTENT) {
                    ZLog::Write(LOGLEVEL_WBXML,"I " . $spaces . " <". $el[EN_TAG] . ">");
                    array_push($this->logStack, $el[EN_TAG]);
                } else
                    ZLog::Write(LOGLEVEL_WBXML,"I " . $spaces . " <" . $el[EN_TAG] . "/>");

                break;
            case EN_TYPE_ENDTAG:
                $tag = array_pop($this->logStack);
                ZLog::Write(LOGLEVEL_WBXML,"I " . $spaces . "</" . $tag . ">");
                break;
            case EN_TYPE_CONTENT:
                ZLog::Write(LOGLEVEL_WBXML,"I " . $spaces . " " . $el[EN_CONTENT]);
                break;
        }
    }

    /**
     * Returns either a start tag, content or end tag
     *
     * @access private
     * @return
     */
    private function _getToken() {
        // Get the data from the input stream
        $element = array();

        while(1) {
            $byte = $this->getByte();

            if(!isset($byte))
                break;

            switch($byte) {
                case WBXML_SWITCH_PAGE:
                    $this->tagcp = $this->getByte();
                    continue;

                case WBXML_END:
                    $element[EN_TYPE] = EN_TYPE_ENDTAG;
                    return $element;

                case WBXML_ENTITY:
                    $entity = $this->getMBUInt();
                    $element[EN_TYPE] = EN_TYPE_CONTENT;
                    $element[EN_CONTENT] = $this->entityToCharset($entity);
                    return $element;

                case WBXML_STR_I:
                    $element[EN_TYPE] = EN_TYPE_CONTENT;
                    $element[EN_CONTENT] = $this->getTermStr();
                    return $element;

                case WBXML_LITERAL:
                    $element[EN_TYPE] = EN_TYPE_STARTTAG;
                    $element[EN_TAG] = $this->getStringTableEntry($this->getMBUInt());
                    $element[EN_FLAGS] = 0;
                    return $element;

                case WBXML_EXT_I_0:
                case WBXML_EXT_I_1:
                case WBXML_EXT_I_2:
                    $this->getTermStr();
                    // Ignore extensions
                    continue;

                case WBXML_PI:
                    // Ignore PI
                    $this->getAttributes();
                    continue;

                case WBXML_LITERAL_C:
                    $element[EN_TYPE] = EN_TYPE_STARTTAG;
                    $element[EN_TAG] = $this->getStringTableEntry($this->getMBUInt());
                    $element[EN_FLAGS] = EN_FLAGS_CONTENT;
                    return $element;

                case WBXML_EXT_T_0:
                case WBXML_EXT_T_1:
                case WBXML_EXT_T_2:
                    $this->getMBUInt();
                    // Ingore extensions;
                    continue;

                case WBXML_STR_T:
                    $element[EN_TYPE] = EN_TYPE_CONTENT;
                    $element[EN_CONTENT] = $this->getStringTableEntry($this->getMBUInt());
                    return $element;

                case WBXML_LITERAL_A:
                    $element[EN_TYPE] = EN_TYPE_STARTTAG;
                    $element[EN_TAG] = $this->getStringTableEntry($this->getMBUInt());
                    $element[EN_ATTRIBUTES] = $this->getAttributes();
                    $element[EN_FLAGS] = EN_FLAGS_ATTRIBUTES;
                    return $element;
                case WBXML_EXT_0:
                case WBXML_EXT_1:
                case WBXML_EXT_2:
                    continue;

                case WBXML_OPAQUE:
                    $length = $this->getMBUInt();
                    $element[EN_TYPE] = EN_TYPE_CONTENT;
                    $element[EN_CONTENT] = $this->getOpaque($length);
                    return $element;

                case WBXML_LITERAL_AC:
                    $element[EN_TYPE] = EN_TYPE_STARTTAG;
                    $element[EN_TAG] = $this->getStringTableEntry($this->getMBUInt());
                    $element[EN_ATTRIBUTES] = $this->getAttributes();
                    $element[EN_FLAGS] = EN_FLAGS_ATTRIBUTES | EN_FLAGS_CONTENT;
                    return $element;

                default:
                    $element[EN_TYPE] = EN_TYPE_STARTTAG;
                    $element[EN_TAG] = $this->getMapping($this->tagcp, $byte & 0x3f);
                    $element[EN_FLAGS] = ($byte & 0x80 ? EN_FLAGS_ATTRIBUTES : 0) | ($byte & 0x40 ? EN_FLAGS_CONTENT : 0);
                    if($byte & 0x80)
                        $element[EN_ATTRIBUTES] = $this->getAttributes();
                    return $element;
            }
        }
    }

    /**
     * Gets attributes
     *
     * @access private
     * @return
     */
    private function getAttributes() {
        $attributes = array();
        $attr = "";

        while(1) {
            $byte = $this->getByte();

            if(count($byte) == 0)
                break;

            switch($byte) {
                case WBXML_SWITCH_PAGE:
                    $this->attrcp = $this->getByte();
                    break;

                case WBXML_END:
                    if($attr != "")
                        $attributes += $this->splitAttribute($attr);

                    return $attributes;

                case WBXML_ENTITY:
                    $entity = $this->getMBUInt();
                    $attr .= $this->entityToCharset($entity);
                    return $element;

                case WBXML_STR_I:
                    $attr .= $this->getTermStr();
                    return $element;

                case WBXML_LITERAL:
                    if($attr != "")
                        $attributes += $this->splitAttribute($attr);

                    $attr = $this->getStringTableEntry($this->getMBUInt());
                    return $element;

                case WBXML_EXT_I_0:
                case WBXML_EXT_I_1:
                case WBXML_EXT_I_2:
                    $this->getTermStr();
                    continue;

                case WBXML_PI:
                case WBXML_LITERAL_C:
                    // Invalid
                    return false;

                case WBXML_EXT_T_0:
                case WBXML_EXT_T_1:
                case WBXML_EXT_T_2:
                    $this->getMBUInt();
                    continue;

                case WBXML_STR_T:
                    $attr .= $this->getStringTableEntry($this->getMBUInt());
                    return $element;

                case WBXML_LITERAL_A:
                    return false;

                case WBXML_EXT_0:
                case WBXML_EXT_1:
                case WBXML_EXT_2:
                    continue;

                case WBXML_OPAQUE:
                    $length = $this->getMBUInt();
                    $attr .= $this->getOpaque($length);
                    return $element;

                case WBXML_LITERAL_AC:
                    return false;

                default:
                    if($byte < 128) {
                        if($attr != "") {
                            $attributes += $this->splitAttribute($attr);
                            $attr = "";
                        }
                    }
                    $attr .= $this->getMapping($this->attrcp, $byte);
                    break;
            }
        }
    }

    /**
     * Splits an attribute
     *
     * @param string $attr     attribute to be splitted
     *
     * @access private
     * @return array
     */
    private function splitAttribute($attr) {
        $attributes = array();

        $pos = strpos($attr,chr(61)); // equals sign

        if($pos)
            $attributes[substr($attr, 0, $pos)] = substr($attr, $pos+1);
        else
            $attributes[$attr] = null;

        return $attributes;
    }

    /**
     * Reads from the stream until getting a string terminator
     *
     * @access private
     * @return string
     */
    private function getTermStr() {
        $str = "";
        while(1) {
            $in = $this->getByte();

            if($in == 0)
                break;
            else
                $str .= chr($in);
        }

        return $str;
    }

    /**
     * Reads $len from the input stream
     *
     * @param int   $len
     *
     * @access private
     * @return string
     */
    private function getOpaque($len) {
        return fread($this->in, $len);
    }

    /**
     * Reads one byte from the input stream
     *
     * @access private
     * @return int
     */
    private function getByte() {
        $ch = fread($this->in, 1);
        if(strlen($ch) > 0)
            return ord($ch);
        else
            return;
    }

    /**
     * Reads string length from the input stream
     *
     * @access private
     * @return
     */
    private function getMBUInt() {
        $uint = 0;

        while(1) {
          $byte = $this->getByte();

          $uint |= $byte & 0x7f;

          if($byte & 0x80)
              $uint = $uint << 7;
          else
              break;
        }

        return $uint;
    }

    /**
     * Reads string table from the input stream
     *
     * @access private
     * @return int
     */
    private function getStringTable() {
        $stringtable = "";

        $length = $this->getMBUInt();
        if($length > 0)
            $stringtable = fread($this->in, $length);

        return $stringtable;
    }

    /**
     * Returns the mapping for a specified codepage and id
     *
     * @param $cp   codepage
     * @param $id
     *
     * @access public
     * @return string
     */
    private function getMapping($cp, $id) {
        if(!isset($this->dtd["codes"][$cp]) || !isset($this->dtd["codes"][$cp][$id]))
            return false;
        else {
            if(isset($this->dtd["namespaces"][$cp])) {
                return $this->dtd["namespaces"][$cp] . ":" . $this->dtd["codes"][$cp][$id];
            } else
                return $this->dtd["codes"][$cp][$id];
        }
    }
}

?>