<?php
/***********************************************
* File      :   contentparameters.php
* Project   :   Z-Push
* Descr     :   Simple transportation class for
*               requested content parameters
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


class ContentParameters {
    private $contentclass = false;
    private $filtertype = false;
    private $truncation = false;
    private $rtftruncation = false;
    private $mimesupport = false;
    private $mimetruncation = false;


    /**
     * Gets the contentclass
     *
     * @access public
     * @return string/boolean       returns false if value is not defined
     */
    public function GetContentClass() {
        return $this->contentclass;
    }

    /**
     * Sets the contentclass
     *
     * @param string    $contentclass
     *
     * @access public
     * @return
     */
    public function SetContentClass($contentclass) {
        $this->contentclass = $contentclass;
    }

    /**
     * Gets the filtertype
     *
     * @access public
     * @return int/boolean          returns false if value is not defined
     */
    public function GetFilterType() {
        return $this->filtertype;
    }

    /**
     * Sets the filtertype
     *
     * @param int    $filtertype
     *
     * @access public
     * @return
     */
    public function SetFilterType($filtertype) {
        $this->filtertype = $filtertype;
    }

    /**
     * Gets the truncation
     *
     * @access public
     * @return int/boolean          returns false if value is not defined
     */
    public function GetTruncation() {
        return $this->truncation;
    }

    /**
     * Sets the truncation
     *
     * @param int    $truncation
     *
     * @access public
     * @return
     */
    public function SetTruncation($truncation) {
        $this->truncation = $truncation;
    }

    /**
     * Gets the RTF truncation
     *
     * @access public
     * @return int/boolean          returns false if value is not defined
     */
    public function GetRTFTruncation() {
        return $this->rtftruncation;
    }

    /**
     * Sets the RTF truncation
     *
     * @param int    $rtftruncation
     *
     * @access public
     * @return
     */
    public function SetRTFTruncation($rtftruncation) {
        $this->rtftruncation = $rtftruncation;
    }

    /**
     * Gets the mime support flag
     *
     * @access public
     * @return int/boolean          returns false if value is not defined
     */
    public function GetMimeSupport() {
        return $this->mimesupport;
    }

    /**
     * Sets the mime support flag
     *
     * @param int    $mimesupport
     *
     * @access public
     * @return
     */
    public function SetMimeSupport($mimesupport) {
        $this->mimesupport = $mimesupport;
    }

    /**
     * Gets the mime truncation flag
     *
     * @access public
     * @return int/boolean          returns false if value is not defined
     */
    public function GetMimeTruncation() {
        return $this->mimetruncation;
    }

    /**
     * Sets the mime truncation flag
     *
     * @param int    $mimetruncation
     *
     * @access public
     * @return
     */
    public function SetMimeTruncation($mimetruncation) {
        $this->mimetruncation = $mimetruncation;
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
            $this->bodypref[$type] = $asb;
            return $asb;
        }
    }
}


class BodyPreference {
    private $hasvalues = false;
    private $truncationsize = false;
    private $allornone = false;
    private $preview = false;

    /**
     * Indicates if this object has values
     *
     * @access public
     * @return boolean
     */
    public function HasValues() {
        return $this->hasvalues;
    }

    /**
     * Gets the air sync body truncation size
     *
     * @access public
     * @return int/boolean          returns false if value is not defined
     */
    public function GetTruncationSize() {
        return $this->truncationsize;
    }

    /**
     * Sets the air sync body truncation size
     *
     * @param int    $truncationsize
     *
     * @access public
     * @return
     */
    public function SetTruncationSize($truncationsize) {
        $this->truncationsize = $truncationsize;
        $this->hasvalues = true;
    }

    /**
     * Gets the air sync body all or none flag
     *
     * @access public
     * @return int/boolean          returns false if value is not defined
     */
    public function GetAllOrNone() {
        return $this->allornone;
    }

    /**
     * Sets the air sync body all or none flag
     *
     * @param int    $asballornone
     *
     * @access public
     * @return
     */
    public function SetAllOrNone($allornone) {
        $this->allornone = $allornone;
        $this->hasvalues = true;
    }

    /**
     * Gets the air sync body preview flag
     *
     * @access public
     * @return int/boolean          returns false if value is not defined
     */
    public function GetPreview() {
        return $this->preview;
    }

    /**
     * Sets the air sync body preview flag
     *
     * @param int    $asbpreview
     *
     * @access public
     * @return
     */
    public function SetPreview($preview) {
        $this->preview = $preview;
        $this->hasvalues = true;
    }
}
?>