<?php
/* -*- tab-width: 4; indent-tabs-mode: nil; c-basic-offset: 4 -*- */
/*
# ***** BEGIN LICENSE BLOCK *****
# This file is part of InDefero, an open source project management application.
# Copyright (C) 2008 Céondo Ltd and contributors.
#
# InDefero is free software; you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation; either version 2 of the License, or
# (at your option) any later version.
#
# InDefero is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program; if not, write to the Free Software
# Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
#
# ***** END LICENSE BLOCK ***** */

/**
 * Storage of the public keys (ssh or monotone).
 *
 */
class IDF_Key extends Pluf_Model
{
    public $_model = __CLASS__;

    function init()
    {
        $this->_a['table'] = 'idf_keys';
        $this->_a['model'] = __CLASS__;
        $this->_a['cols'] = array(
                             // It is mandatory to have an "id" column.
                            'id' =>
                            array(
                                  'type' => 'Pluf_DB_Field_Sequence',
                                  //It is automatically added.
                                  'blank' => true,
                                  ),
                            'user' =>
                            array(
                                  'type' => 'Pluf_DB_Field_Foreignkey',
                                  'model' => 'Pluf_User',
                                  'blank' => false,
                                  'verbose' => __('user'),
                                  ),
                            'content' =>
                            array(
                                  'type' => 'Pluf_DB_Field_Text',
                                  'blank' => false,
                                  'verbose' => __('public key'),
                                  ),
                            'type' =>
                            array(
                                  'type' => 'Pluf_DB_Field_Varchar',
                                  'size' => 3,
                                  'blank' => false,
                                  'verbose' => __('key type'),
                                  ),
                            );
        // WARNING: Not using getSqlTable on the Pluf_User object to
        // avoid recursion.
        $t_users = $this->_con->pfx.'users';
        $this->_a['views'] = array(
                              'join_user' =>
                              array(
                                    'join' => 'LEFT JOIN '.$t_users
                                    .' ON '.$t_users.'.id='.$this->_con->qn('user'),
                                    'select' => $this->getSelect().', '
                                    .$t_users.'.login AS login',
                                    'props' => array('login' => 'login'),
                                    )
                                   );
    }

    function showCompact()
    {
        return Pluf_Template::markSafe(Pluf_esc(substr($this->content, 0, 25)).' [...] '.Pluf_esc(substr($this->content, -55)));
    }

    private function parseMonotoneKeyData()
    {
        if ($this->type != "mtn")
            throw new IDF_Exception("key is not a monotone key type");

        preg_match("#^\[pubkey ([^\]]+)\]\s*(\S+)\s*\[end\]$#", $this->content, $m);
        if (count($m) != 3)
            throw new IDF_Exception("invalid key data detected");

        return array($m[1], $m[2]);
    }

    /**
     * Returns the key name of the key, i.e. most of the time the email
     * address, which not neccessarily has to be unique across a project.
     *
     * @return string
     */
    function getMonotoneKeyName()
    {
        list($keyName, ) = $this->parseMonotoneKeyData();
        return $keyName;
    }

    /**
     * This function should be used to calculate the key id from the
     * public key hash for authentication purposes. This avoids clashes
     * in case the key name is not unique across the project
     *
     * And yes, this is actually how monotone itself calculates the key
     * id...
     *
     * @return string
     */
    function getMonotoneKeyId()
    {
        list($keyName, $keyData) = $this->parseMonotoneKeyData();
        return sha1($keyName.":".$keyData);
    }

    function postSave($create=false)
    {
        /**
         * [signal]
         *
         * IDF_Key::postSave
         *
         * [sender]
         *
         * IDF_Key
         *
         * [description]
         *
         * This signal allows an application to perform special
         * operations after the saving of a public Key.
         *
         * [parameters]
         *
         * array('key' => $key,
         *       'created' => true/false)
         *
         */
        $params = array('key' => $this, 'created' => $create);
        Pluf_Signal::send('IDF_Key::postSave',
                          'IDF_Key', $params);
    }

    function preDelete()
    {
        /**
         * [signal]
         *
         * IDF_Key::preDelete
         *
         * [sender]
         *
         * IDF_Key
         *
         * [description]
         *
         * This signal allows an application to perform special
         * operations before a key is deleted.
         *
         * [parameters]
         *
         * array('key' => $key)
         *
         */
        $params = array('key' => $this);
        Pluf_Signal::send('IDF_Key::preDelete',
                          'IDF_Key', $params);
    }

    /**
     * Returns an associative array with available key types for this
     * idf installation, ready for consumption for a <select> widget
     *
     * @return array
     */
    public static function getAvailableKeyTypes()
    {
        $key_types = array(__("SSH") => 'ssh');
        if (array_key_exists('mtn', Pluf::f('allowed_scm', array())))
        {
            $key_types[__("monotone")] = 'mtn';
        }
        return $key_types;
    }
}
