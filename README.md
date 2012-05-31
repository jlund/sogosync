SOGoSync
========

Description
-----------

sogosync is Z-Push - Open Source ActiveSync - from SVN upstream with CalDAV and CardDAV backend support.

Features
--------

* CalDAV support with multiple calendars using a CalDAV server
* CardDAV support with multiple address books using a CardDAV server


Requirements
-----------
* A working CalDAV/CardDAV server (e.g. SOGo, ownCloud, SabreDAV)
  * I only tested SOGo, but it should work with any CalDAV/CardDAV groupware. Feedback is welcome.
* An ActiveSync compatible mobile device
	* [Comparison of Exchange ActiveSync clients](http://en.wikipedia.org/wiki/Comparison_of_Exchange_ActiveSync_clients)
* PHP5 with the following libraries for a Debian system

        $ apt-get install php5-curl php5-ldap php5-imap php-mail libawl-php


* PHP5 with the following libraries for a Redhat system

        $ yum install php-curl php-common php-ldap php-imap php-imap libawl-php



Thanks
------

SOGoSync is possible thanks to the following projects:

* [Open Groupware](http://www.sogo.nu/)
* [Open Source ActiveSync implementation](http://z-push.sourceforge.net/soswp)
* [CardDAV-PHP](https://github.com/graviox/CardDAV-PHP)


See also
-------

* CardDAV and CalDAV RFC:
  * http://tools.ietf.org/html/rfc6350
  * http://tools.ietf.org/html/rfc2425
  * http://tools.ietf.org/html/rfc4791
  * http://tools.ietf.org/html/rfc2426

* ActiveSync Contact and Calendar Protocol Specification
  * http://msdn.microsoft.com/en-us/library/cc425499%28EXCHG.80%29.aspx
  * http://msdn.microsoft.com/en-us/library/dd299451(v=exchg.80).aspx
  * http://msdn.microsoft.com/en-us/library/dd299440(v=exchg.80).aspx
  * http://msdn.microsoft.com/en-us/library/cc463911(v=exchg.80).aspx

* [s-push](https://github.com/dekkers/s-push)
	* Thanks to Jeroen Dekkers for the original CalDAV support


Libraries used
------------

* [CardDAV-Client](https://github.com/graviox/CardDAV-PHP/)
	* Thanks to Christian Putzke for updating his library
* [vCard-parser](https://github.com/nuovo/vCard-parser/)
* [CalDAV-Client](http://wiki.davical.org/w/Developer_Setup)

Installation
------------

Clone from github:

    $ cd /var/www
    $ git clone https://github.com/xbgmsharp/sogosync.git
    $ cd sogosync


Read the Z-Push install instructions in the INSTALL file, or this document: [Configure Z-Push (Remote ActiveSync for Mobile Devices)](http://doc.zarafa.com/7.0/Administrator_Manual/en-US/html/_zpush.html)


Configuration
-------------
File 'config.php' is the original file from Z-Push SVN repository:

    $ cp config.php config.php.org
    $ cp config.inc.php config.php

File 'backend/combined/config.php' is the original file from Z-Push SVN repository:

    $ cp backend/combined/config.php backend/combined/config.php.org
    $ cp backend/combined/config.inc.php backend/combined/config.php

### Edit config.php
 * Set TimeZone
 * Configure the BackendIMAP settings section
 * Configure the BackendCARDDAV setting section
 * Configure the BackendCALDAV setting section

### Edit backend/searchldap/config.php
 * This file allows you to enable GAL search support from your LDAP tree.

These files are pre-configured to work with the [SOGo Online Demo](http://www.sogo.nu/english/tour/online_demo.html)


Test
----
Using a browser, login to https://sogo.mydomain.com/Microsoft-Server-ActiveSync

You should see a webpage that says "Z-Push - Open Source ActiveSync" with the message "GET not supported."

If so, congratulations!

If not, please READ the FAQ on [the wiki](https://github.com/xbgmsharp/sogosync/wiki).

You can now configure your smartphone or tablet.


Update
------
To update to the latest version:

    $ cd /var/www/sogosync
    $ git pull


Contributing
------------

1. Fork it
2. Create a branch (`git checkout -b my_markup`)
3. Commit your changes (`git commit -am "Added Snarkdown"`)
4. Push to the branch (`git push origin my_markup`)
5. Create an [Issue][1] with a link to your branch
6. Or Send me a [Pull Request][2]

[1]: https://github.com/xbgmsharp/sogosync/issues
[2]: https://github.com/xbgmsharp/sogosync/pull/new/master
