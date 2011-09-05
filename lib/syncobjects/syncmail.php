<?php
/***********************************************
* File      :   syncmail.php
* Project   :   Z-Push
* Descr     :   WBXML mail entities that can be parsed
*               directly (as a stream) from WBXML.
*               It is automatically decoded
*               according to $mapping,
*               and the Sync WBXML mappings.
*
* Created   :   05.09.2011
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


// TODO define checks for SyncMail
class SyncMail extends SyncObject {
    public $to;
    public $cc;
    public $from;
    public $subject;
    public $threadtopic;
    public $datereceived;
    public $displayto;
    public $importance;
    public $read;
    public $attachments;
    public $mimetruncated;
    public $mimedata;
    public $mimesize;
    public $bodytruncated;
    public $bodysize;
    public $body;
    public $messageclass;
    public $meetingrequest;
    public $reply_to;

    // AS 2.5 prop
    public $internetcpid;

    function SyncMail() {
        $mapping = array (
                    SYNC_POOMMAIL_TO                                    => array (  self::STREAMER_VAR      => "to"),
                    SYNC_POOMMAIL_CC                                    => array (  self::STREAMER_VAR      => "cc"),
                    SYNC_POOMMAIL_FROM                                  => array (  self::STREAMER_VAR      => "from"),
                    SYNC_POOMMAIL_SUBJECT                               => array (  self::STREAMER_VAR      => "subject"),
                    SYNC_POOMMAIL_THREADTOPIC                           => array (  self::STREAMER_VAR      => "threadtopic"),
                    SYNC_POOMMAIL_DATERECEIVED                          => array (  self::STREAMER_VAR      => "datereceived",
                                                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_DATE_DASHES ),

                    SYNC_POOMMAIL_DISPLAYTO                             => array (  self::STREAMER_VAR      => "displayto"),
                    SYNC_POOMMAIL_IMPORTANCE                            => array (  self::STREAMER_VAR      => "importance"),
                    SYNC_POOMMAIL_READ                                  => array (  self::STREAMER_VAR      => "read"),
                    SYNC_POOMMAIL_ATTACHMENTS                           => array (  self::STREAMER_VAR      => "attachments",
                                                                                    self::STREAMER_TYPE     => "SyncAttachment",
                                                                                    self::STREAMER_ARRAY    => SYNC_POOMMAIL_ATTACHMENT ),

                    SYNC_POOMMAIL_MIMETRUNCATED                         => array (  self::STREAMER_VAR      => "mimetruncated" ),//
                    SYNC_POOMMAIL_MIMEDATA                              => array (  self::STREAMER_VAR      => "mimedata",
                                                                                    self::STREAMER_TYPE     => self::STREAMER_TYPE_MAPI_STREAM),

                    SYNC_POOMMAIL_MIMESIZE                              => array (  self::STREAMER_VAR      => "mimesize" ),//
                    SYNC_POOMMAIL_BODYTRUNCATED                         => array (  self::STREAMER_VAR      => "bodytruncated"),
                    SYNC_POOMMAIL_BODYSIZE                              => array (  self::STREAMER_VAR      => "bodysize"),
                    SYNC_POOMMAIL_BODY                                  => array (  self::STREAMER_VAR      => "body"),
                    SYNC_POOMMAIL_MESSAGECLASS                          => array (  self::STREAMER_VAR      => "messageclass"),
                    SYNC_POOMMAIL_MEETINGREQUEST                        => array (  self::STREAMER_VAR      => "meetingrequest",
                                                                                    self::STREAMER_TYPE     => "SyncMeetingRequest"),

                    SYNC_POOMMAIL_REPLY_TO                              => array (  self::STREAMER_VAR      => "reply_to"),
                );

        if(Request::GetProtocolVersion() >= 2.5) {
            $mapping += array(
                        SYNC_POOMMAIL_INTERNETCPID                      => array (  self::STREAMER_VAR      => "internetcpid"),
                    );
        }

        parent::SyncObject($mapping);
    }
}

?>