<?php
/***********************************************
* File      :   searchLDAP.php
* Project   :   Z-Push
* Descr     :   A ISearchProvider implementation to
*               query a ldap server for GAL
*               information.
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

require_once("backend/searchldap/config.php");

class SearchLDAP implements ISearchProvider {
    private $connection;

    /**
     * Initializes the backend to perform the search
     * Connects to the LDAP server using the values from the configuration
     *
     *
     * @access public
     * @return
     */
    public function SearchLDAP() {
        if (!function_exists("ldap_connect")) {
            // TODO throw status exception
            ZLog::Write(LOGLEVEL_FATAL, "SearchLDAP: php-ldap is not installed. Search aborted.");
            return false;
        }

        // connect to LDAP
        $this->connection = @ldap_connect(LDAP_HOST, LDAP_PORT);
        @ldap_set_option($this->connection, LDAP_OPT_PROTOCOL_VERSION, 3);

        // Authenticate
        if (constant('ANONYMOUS_BIND') === true) {
            if(! @ldap_bind($this->connection)) {
                // TODO throw status exception
                ZLog::Write(LOGLEVEL_ERROR, "SearchLDAP: Could not bind anonymously to server! Search aborted.");
                $this->connection = false;
                return false;
            }
        }
        else if (constant('LDAP_BIND_USER') != "") {
            if(! @ldap_bind($this->connection, LDAP_BIND_USER, LDAP_BIND_PASSWORD)) {
                // TODO throw status exception
                ZLog::Write(LOGLEVEL_ERROR, "SearchLDAP: Could not bind to server with user '".LDAP_BIND_USER."' and given password! Search aborted.");
                $this->connection = false;
                return false;
            }
        }
        else {
            // TODO throw status exception
            ZLog::Write(LOGLEVEL_ERROR, "SearchLDAP: neither anonymous nor default bind enabled. Other options not implemented.");
            // it would be possible to use the users login and password to authenticate on the LDAP server
            // the main $backend has to keep these values so they could be used here
            $this->connection = false;
            return false;
        }
    }

    /**
     * Indicates if a search type is supported by this SearchProvider
     * Currently only the type "GAL" (Global Address List) is implemented
     *
     * @param string        $searchtype
     *
     * @access public
     * @return boolean
     */
    public function SupportsType($searchtype) {
        return ($searchtype == "GAL");
    }


    /**
     * Queries the LDAP backend
     *
     * @param string        $searchquery        string to be searched for
     * @param string        $searchrange        specified searchrange
     *
     * @access public
     * @return array        search results
     */
    public function GetGALSearchResults($searchquery, $searchrange) {
        global $ldap_field_map;
        if (isset($this->connection) && $this->connection !== false) {
            $searchfilter = str_replace("SEARCHVALUE", $searchquery, LDAP_SEARCH_FILTER);
            $result = @ldap_search($this->connection, LDAP_SEARCH_BASE, $searchfilter);
            if (!$result) {
                ZLog::Write(LOGLEVEL_ERROR, "SearchLDAP: Error in search query. Search aborted");
                return false;
            }

            // get entry data as array
            $searchresult = ldap_get_entries($this->connection, $result);

            // range for the search results, default symbian range end is 50, wm 99,
            // so we'll use that of nokia
            $rangestart = 0;
            $rangeend = 50;

            if ($searchrange != '0') {
                $pos = strpos($searchrange, '-');
                $rangestart = substr($searchrange, 0, $pos);
                $rangeend = substr($searchrange, ($pos + 1));
            }
            $items = array();

            // TODO the limiting of the searchresults could be refactored into Utils as it's probably used more than once
            $querycnt = $searchresult['count'];
            //do not return more results as requested in range
            $querylimit = (($rangeend + 1) < $querycnt) ? ($rangeend + 1) : $querycnt;
            $items['range'] = $rangestart.'-'.($querycnt-1);
            $items['searchtotal'] = $querycnt;

            $rc = 0;
            for ($i = $rangestart; $i < $querylimit; $i++) {
                foreach ($ldap_field_map as $key=>$value ) {
                    if (isset($searchresult[$i][$value])) {
                        if (is_array($searchresult[$i][$value]))
                            $items[$rc][$key] = $searchresult[$i][$value][0];
                        else
                            $items[$rc][$key] = $searchresult[$i][$value];
                    }
                }
                $rc++;
            }

            return $items;
        }
        else
            return false;
    }

    /**
     * Disconnects from LDAP
     *
     * @access public
     * @return boolean
     */
    public function Disconnect() {
        if ($this->connection)
            @ldap_close($this->connection);

        return true;
    }
}
?>