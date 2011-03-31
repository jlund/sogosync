<?php
/***********************************************
* File      :   searchbackend.php
* Project   :   Z-Push
* Descr     :   The searchbackend can be used to
*               implement an alternative way to
*               use the GAL search funtionality.
*
* Created   :   03.08.2010
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

/**
 * The SearchBackend is a stub to implement own search funtionality
 * By default it just calls the getSearchResults of the initialized main backend
 *
 * If you wish to implement an alternative search method, you should extend this class
 * like the SearchLDAP backend
 */

class SearchBackend {
    protected $_backend;

    /**
     * Initializes the backend to perform the search
     *
     * @param object        $backend
     *
     * @access public
     * @return
     */
    public function initialize($backend) {
        $this->backend = $backend;
    }

    /**
     * Queries the backend to search
     * By default, the getSearchResults() of the main backend is called
     *
     * @param string        $searchquery        string to be searched for
     * @param string        $searchrange        specified searchrange
     *
     * @access public
     * @return array        search results
     */
    public function getSearchResults($searchquery, $searchrange) {
        if (isset($this->_backend))
            return $this->_backend->getSearchResults($searchquery, $searchrange);
        else
            return false;
    }

    /**
     * Disconnects from the current search engine
     *
     * @access public
     * @return boolean
     */
    public function disconnect() {
        return true;
    }
}
?>