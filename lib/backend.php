<?php
/***********************************************
* File      :   backend.php
* Project   :   Z-Push
* Descr     :   This is what C++ people
*               (and PHP5) would call an
*               abstract class. The
*               backend module itself is
*               responsible for converting any
*               necessary types and formats.
*
*               If you wish to implement a new
*               backend, all you need to do is
*               to subclass the following class
*               (or implement an IBackend)
*               and place the subclassed file in
*               the backend/yourBackend directory. You can
*               then use your backend by
*               specifying it in the config.php file
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

abstract class Backend implements IBackend {
    /**
     * Constructor
     *
     * @access public
     */
    public function Backend() {
    }

    /**
     * Returns a IStateMachine implementation used to save states
     * The default StateMachine should be used here, so, false is fine
     *
     * @access public
     * @return boolean/object
     */
    public function GetStateMachine() {
        return false;
    }

    /**
     * Returns a ISearchProvider implementation used for searches
     * the SearchProvider is just a stub
     *
     * @access public
     * @return object       Implementation of ISearchProvider
     */
    public function GetSearchProvider() {
        return new SearchProvider();
    }

    /*********************************************************************
     * Methods to be implemented
     *
     * public function Logon($username, $domain, $password);
     * public function Setup($store, $checkACLonly = false, $folderid = false);
     * public function Logoff();
     * public function GetHierarchy();
     * public function GetImporter($folderid = false);
     * public function GetExporter($folderid = false);
     * public function SendMail($rfc822, $forward = false, $reply = false, $parent = false, $saveInSent = true);
     * public function Fetch($folderid, $id, $mimesupport = 0);
     * public function GetWasteBasket();
     * public function GetAttachmentData($attname);
     * public function MeetingResponse($requestid, $folderid, $response, &$calendarid);
     *
     */

    /**
     * Returns true if the Backend implementation supports an alternative PING mechanism
     *
     * @access public
     * @return boolean
     */
    public function AlterPing() {
        return false;
    }

    /**
     * Requests an indication if changes happened in a folder since the syncstate
     *
     * @param string        $folderid       id of the folder
     * @param string        &$syncstate     reference of the syncstate
     *
     * @access public
     * @return boolean
     */
    public function AlterPingChanges($folderid, &$syncstate) {
        return array();
    }
}
?>