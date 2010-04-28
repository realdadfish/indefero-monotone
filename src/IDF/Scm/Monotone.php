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
 * Monotone utils.
 *
 */
class IDF_Scm_Monotone extends IDF_Scm
{
    public static $MIN_INTERFACE_VERSION = 12.0;

    /* ============================================== *
     *                                                *
     *   Common Methods Implemented By All The SCMs   *
     *                                                *
     * ============================================== */

    public function __construct($repo, $project=null)
    {
        $this->repo = $repo;
        $this->project = $project;
    }

    public function getRepositorySize()
    {
        if (!file_exists($this->repo)) {
            return 0;
        }
        $cmd = Pluf::f('idf_exec_cmd_prefix', '').'du -sk '
            .escapeshellarg($this->repo);
        $out = explode(' ',
                       self::shell_exec('IDF_Scm_Monotone::getRepositorySize', $cmd),
                       2);
        return (int) $out[0]*1024;
    }

    public function isAvailable()
    {
        $out = array();
        try {
            $cmd = Pluf::f('idf_exec_cmd_prefix', '')
                .sprintf("%s -d %s automate interface_version",
                         Pluf::f('mtn_path', 'mtn'),
                         escapeshellarg($this->repo));
            self::exec('IDF_Scm_Monotone::isAvailable',
                   $cmd, $out, $return);
        } catch (IDF_Scm_Exception $e) {
            return false;
        }

        return count($out) > 0 && floatval($out[0]) >= self::$MIN_INTERFACE_VERSION;
    }

    public function getBranches()
    {
        if (isset($this->cache['branches'])) {
            return $this->cache['branches'];
        }
        // FIXME: introduce handling of suspended branches
        $cmd = Pluf::f('idf_exec_cmd_prefix', '')
            .sprintf("%s -d %s automate branches",
                     Pluf::f('mtn_path', 'mtn'),
                     escapeshellarg($this->repo));
        self::exec('IDF_Scm_Monotone::getBranches',
                   $cmd, $out, $return);
        if ($return != 0) {
            throw new IDF_Scm_Exception(sprintf($this->error_tpl,
                                                $cmd, $return,
                                                implode("\n", $out)));
        }
        $res = array();
        // FIXME: we could expand each branch with one of its head revisions
        // here, but these would soon become bogus anyway and we cannot
        // map multiple head revisions here either, so we just use the
        // selector as placeholder
        foreach ($out as $b) {
            $res["h:$b"] = $b;
        }
        $this->cache['branches'] = $res;
        return $res;
    }

    /**
     * monotone has no concept of a "main" branch, so just return
     * the first one (the branch list is already sorted)
     *
     * @return string
     */
    public function getMainBranch()
    {
        $branches = $this->getBranches();
        return key($branches);
    }

    /**
     * expands a selector or a partial revision id to zero, one or
     * multiple 40 byte revision ids
     *
     * @param string $selector
     * @return array
     */
    private function _resolveSelector($selector)
    {
        $cmd = Pluf::f('idf_exec_cmd_prefix', '')
            .sprintf("%s -d %s automate select %s",
                     Pluf::f('mtn_path', 'mtn'),
                     escapeshellarg($this->repo),
                     escapeshellarg($selector));
        self::exec('IDF_Scm_Monotone::_resolveSelector',
                   $cmd, $out, $return);
        return $out;
    }

    /**
     * Parses monotone's basic_io format
     *
     * @param string $in
     * @return array of arrays
     */
    private static function _parseBasicIO($in)
    {
        if (substr($in, -1) != "\n")
            $in .= "\n";

        $pos = 0;
        $stanzas = array();

        while ($pos < strlen($in))
        {
            $stanza = array();
            while ($pos < strlen($in))
            {
                if ($in[$pos] == "\n") break;

                $stanzaLine = array("key" => "", "values" => array(), "hash" => null);
                while ($pos < strlen($in))
                {
                    $ch = $in[$pos];
                    if ($ch == '"' || $ch == '[') break;
                    ++$pos;
                    if ($ch == ' ') continue;
                    $stanzaLine['key'] .= $ch;
                }

                if ($in[$pos] == '[')
                {
                    ++$pos; // opening square bracket
                    $stanzaLine['hash'] = substr($in, $pos, 40);
                    $pos += 40;
                    ++$pos; // closing square bracket
                }
                else
                {
                    $valCount = 0;
                    while ($in[$pos] == '"')
                    {
                        ++$pos; // opening quote
                        $stanzaLine['values'][$valCount] = "";
                        while ($pos < strlen($in))
                        {
                            $ch = $in[$pos]; $pr = $in[$pos-1];
                            if ($ch == '"' && $pr != '\\') break;
                            ++$pos;
                            $stanzaLine['values'][$valCount] .= $ch;
                        }
                        ++$pos; // closing quote

                        if ($in[$pos] == ' ')
                        {
                            ++$pos; // space
                            ++$valCount;
                        }
                    }
                }

                $stanza[] = $stanzaLine;
                ++$pos; // newline
            }
            $stanzas[] = $stanza;
            ++$pos; // newline
        }
        return $stanzas;
    }

    private function _getCerts($rev)
    {
        static $certCache = array();

        if (!array_key_exists($rev, $certCache))
        {
            $cmd = Pluf::f('idf_exec_cmd_prefix', '')
                .sprintf("%s -d %s automate certs %s",
                         Pluf::f('mtn_path', 'mtn'),
                         escapeshellarg($this->repo),
                         escapeshellarg($rev));
            self::exec('IDF_Scm_Monotone::_getCerts',
                       $cmd, $out, $return);

            $stanzas = self::_parseBasicIO(implode("\n", $out));
            $certs = array();
            foreach ($stanzas as $stanza)
            {
                $certname = null;
                foreach ($stanza as $stanzaline)
                {
                    // luckily, name always comes before value
                    if ($stanzaline['key'] == "name")
                    {
                        $certname = $stanzaline['values'][0];
                        continue;
                    }

                    if ($stanzaline['key'] == "value")
                    {
                        if (!array_key_exists($certname, $certs))
                        {
                            $certs[$certname] = array();
                        }

                        $certs[$certname][] = $stanzaline['values'][0];
                        break;
                    }
                }
            }
            $certCache[$rev] = $certs;
        }

        return $certCache[$rev];
    }

    private function _getUniqueCertValuesFor($revs, $certName)
    {
        $certValues = array();
        foreach ($revs as $rev)
        {
            $certs = $this->_getCerts($rev);
            if (!array_key_exists($certName, $certs))
                continue;

            $certValues = array_merge($certValues, $certs[$certName]);
        }
        return array_unique($certValues);
    }

    private function _getLastChangeFor($file, $startrev)
    {
        $cmd = Pluf::f('idf_exec_cmd_prefix', '')
            .sprintf("%s -d %s automate get_content_changed %s %s",
                     Pluf::f('mtn_path', 'mtn'),
                     escapeshellarg($this->repo),
                     escapeshellarg($startrev),
                     escapeshellarg($file));
        self::exec('IDF_Scm_Monotone::_getLastChangeFor',
                   $cmd, $out, $return);

        $stanzas = self::_parseBasicIO(implode("\n", $out));

        // FIXME: we only care about the first returned content mark
        // everything else seem to be very rare cases
        foreach ($stanzas as $stanza)
        {
            foreach ($stanza as $stanzaline)
            {
                if ($stanzaline['key'] == "content_mark")
                {
                    return $stanzaline['hash'];
                }
            }
        }
        return null;
    }

    /**
     * @see IDF_Scm::inBranches()
     **/
    public function inBranches($commit, $path)
    {
        $revs = $this->_resolveSelector($commit);
        if (count($revs) == 0) return array();
        return $this->_getUniqueCertValuesFor($revs, "branch");
    }

    /**
     * @see IDF_Scm::getTags()
     **/
    public function getTags()
    {
        if (isset($this->cache['tags'])) {
            return $this->cache['tags'];
        }
        $cmd = Pluf::f('idf_exec_cmd_prefix', '')
                .sprintf("%s -d %s automate tags",
                         Pluf::f('mtn_path', 'mtn'),
                         escapeshellarg($this->repo));
        self::exec('IDF_Scm_Monotone::getTags', $cmd, $out, $return);

        $tags = array();
        $stanzas = self::_parseBasicIO(implode("\n", $out));
        foreach ($stanzas as $stanza)
        {
            $tagname = null;
            foreach ($stanza as $stanzaline)
            {
                // revision comes directly after the tag stanza
                if ($stanzaline['key'] == "tag")
                {
                    $tagname = $stanzaline['values'][0];
                    continue;
                }
                if ($stanzaline['key'] == "revision")
                {
                    $tags[$stanzaline['hash']] = $tagname;
                    break;
                }
            }
        }

        $this->cache['tags'] = $tags;
        return $tags;
    }

    /**
     * @see IDF_Scm::inTags()
     **/
    public function inTags($commit, $path)
    {
        $revs = $this->_resolveSelector($commit);
        if (count($revs) == 0) return array();
        return $this->_getUniqueCertValuesFor($revs, "tag");
    }

    /**
     * @see IDF_Scm::getTree()
     */
    public function getTree($commit, $folder='/', $branch=null)
    {
        $revs = $this->_resolveSelector($commit);
        if (count($revs) == 0)
        {
            return array();
        }

        $cmd = Pluf::f('idf_exec_cmd_prefix', '')
                .sprintf("%s -d %s automate get_manifest_of %s",
                         Pluf::f('mtn_path', 'mtn'),
                         escapeshellarg($this->repo),
                         escapeshellarg($revs[0]));
        self::exec('IDF_Scm_Monotone::getTree', $cmd, $out, $return);

        $files = array();
        $stanzas = self::_parseBasicIO(implode("\n", $out));
        $folder = $folder == '/' || empty($folder) ? '' : $folder.'/';

        foreach ($stanzas as $stanza)
        {
            if ($stanza[0]['key'] == "format_version")
                continue;

            $path = $stanza[0]['values'][0];
            if (!preg_match('#^'.$folder.'([^/]+)$#', $path, $m))
                continue;

            $file = array();
            $file['file'] = $m[1];
            $file['fullpath'] = $path;
            $file['efullpath'] = self::smartEncode($path);

            if ($stanza[0]['key'] == "dir")
            {
                $file['type'] = "tree";
                $file['size'] = 0;
            }
            else
            {
                $file['type'] = "blob";
                $file['hash'] = $stanza[1]['hash'];
                $file['size'] = strlen($this->getFile((object)$file));
            }

            $rev = $this->_getLastChangeFor($file['fullpath'], $revs[0]);
            if ($rev !== null)
            {
                $file['rev'] = $rev;
                $certs = $this->_getCerts($rev);

                // FIXME: this assumes that author, date and changelog are always given
                $file['author'] = implode(", ", $certs['author']);

                $dates = array();
                foreach ($certs['date'] as $date)
                    $dates[] = gmdate('Y-m-d H:i:s', strtotime($date));
                $file['date'] = implode(', ', $dates);
                $file['log'] = substr(implode("; ", $certs['changelog']), 0, 80);
            }

            $files[] = (object) $file;
        }
        return $files;
    }

    /**
     * Given the string describing the author from the log find the
     * author in the database.
     *
     * @param string Author
     * @return mixed Pluf_User or null
     */
    public function findAuthor($author)
    {
        // We extract anything which looks like an email.
        $match = array();
        if (!preg_match('/([^ ]+@[^ ]+)/', $author, $match)) {
            return null;
        }
        foreach (array('email', 'login') as $what) {
            $sql = new Pluf_SQL($what.'=%s', array($match[1]));
            $users = Pluf::factory('Pluf_User')->getList(array('filter'=>$sql->gen()));
            if ($users->count() > 0) {
                return $users[0];
            }
        }
        return null;
    }

    private static function _getMasterBranch($project)
    {
        $conf = $project->getConf();
        if (false === ($branch = $conf->getVal('mtn_master_branch', false))
            || empty($branch)) {
            $branch = "*";
        }
        return $branch;
    }

    public static function getAnonymousAccessUrl($project)
    {
        return sprintf(
            Pluf::f('mtn_remote_url'),
            $project->shortname,
            self::_getMasterBranch($project)
        );
    }

    public static function getAuthAccessUrl($project, $user)
    {
        return self::getAnonymousAccessUrl($project);
    }

    /**
     * Returns this object correctly initialized for the project.
     *
     * @param IDF_Project
     * @return IDF_Scm_Monotone
     */
    public static function factory($project)
    {
        $rep = sprintf(Pluf::f('mtn_repositories'), $project->shortname);
        return new IDF_Scm_Monotone($rep, $project);
    }

    public function isValidRevision($commit)
    {
        $revs = $this->_resolveSelector($commit);
        return count($revs) == 1;
    }

    /**
     * Get the file info.
     *
     * @param string File
     * @param string Commit ('HEAD')
     * @return false Information
     */
    public function getPathInfo($file, $commit = null)
    {
        if ($commit === null) {
            $commit = 'h:' . self::_getMasterBranch($this->project);
        }

        $revs = $this->_resolveSelector($commit);
        if (count($revs) == 0)
            return false;

        $cmd = Pluf::f('idf_exec_cmd_prefix', '')
                .sprintf("%s -d %s automate get_manifest_of %s",
                         Pluf::f('mtn_path', 'mtn'),
                         escapeshellarg($this->repo),
                         escapeshellarg($revs[0]));
        self::exec('IDF_Scm_Monotone::getPathInfo', $cmd, $out, $return);

        $files = array();
        $stanzas = self::_parseBasicIO(implode("\n", $out));

        foreach ($stanzas as $stanza)
        {
            if ($stanza[0]['key'] == "format_version")
                continue;

            $path = $stanza[0]['values'][0];
            if (!preg_match('#^'.$file.'$#', $path, $m))
                continue;

            $file = array();
            $file['fullpath'] = $path;

            if ($stanza[0]['key'] == "dir")
            {
                $file['type'] = "tree";
                $file['hash'] = null;
                $file['size'] = 0;
            }
            else
            {
                $file['type'] = "blob";
                $file['hash'] = $stanza[1]['hash'];
                $file['size'] = strlen($this->getFile((object)$file));
            }

            $pathinfo = pathinfo($file['fullpath']);
            $file['file'] = $pathinfo['basename'];

            $rev = $this->_getLastChangeFor($file['fullpath'], $revs[0]);
            if ($rev !== null)
            {
                $file['rev'] = $rev;
                $certs = $this->_getCerts($rev);

                // FIXME: this assumes that author, date and changelog are always given
                $file['author'] = implode(", ", $certs['author']);

                $dates = array();
                foreach ($certs['date'] as $date)
                    $dates[] = gmdate('Y-m-d H:i:s', strtotime($date));
                $file['date'] = implode(', ', $dates);
                $file['log'] = substr(implode("; ", $certs['changelog']), 0, 80);
            }

            return (object) $file;
        }
        return false;
    }

    public function getFile($def, $cmd_only=false)
    {
        $cmd = Pluf::f('idf_exec_cmd_prefix', '')
                .sprintf("%s -d %s automate get_file %s",
                         Pluf::f('mtn_path', 'mtn'),
                         escapeshellarg($this->repo),
                         escapeshellarg($def->hash));
        return ($cmd_only)
            ? $cmd : self::shell_exec('IDF_Scm_Monotone::getFile', $cmd);
    }

    private function _getDiff($target, $source = null)
    {
        if (empty($source))
        {
            $source = "p:$target";
        }

        // FIXME: add real support for merge revisions here which have
        // two distinct diff sets
        $targets = $this->_resolveSelector($target);
        $sources = $this->_resolveSelector($source);

        if (count($targets) == 0 || count($sources) == 0)
        {
            return "";
        }

        // if target contains a root revision, we cannot produce a diff
        if (empty($sources[0]))
        {
            return "";
        }

        $cmd = Pluf::f('idf_exec_cmd_prefix', '')
            .sprintf("%s -d %s automate content_diff -r %s -r %s",
                     Pluf::f('mtn_path', 'mtn'),
                     escapeshellarg($this->repo),
                     escapeshellarg($sources[0]),
                     escapeshellarg($targets[0]));
        self::exec('IDF_Scm_Monotone::_getDiff',
                   $cmd, $out, $return);

        return implode("\n", $out);
    }

    /**
     * Get commit details.
     *
     * @param string Commit
     * @param bool Get commit diff (false)
     * @return array Changes
     */
    public function getCommit($commit, $getdiff=false)
    {
        $revs = $this->_resolveSelector($commit);
        if (count($revs) == 0)
            return array();

        $certs = $this->_getCerts($revs[0]);

        // FIXME: this assumes that author, date and changelog are always given
        $res['author'] = implode(", ", $certs['author']);

        $dates = array();
        foreach ($certs['date'] as $date)
            $dates[] = gmdate('Y-m-d H:i:s', strtotime($date));
        $res['date'] = implode(', ', $dates);

        $res['title'] = implode("\n---\n, ", $certs['changelog']);

        $res['commit'] = $revs[0];

        $res['changes'] = ($getdiff) ? $this->_getDiff($revs[0]) : '';

        return (object) $res;
    }

    /**
     * Check if a commit is big.
     *
     * @param string Commit ('HEAD')
     * @return bool The commit is big
     */
    public function isCommitLarge($commit=null)
    {
        if (empty($commit))
        {
            $commit = "h:"+self::_getMasterBranch($this->project);
        }

        $revs = $this->_resolveSelector($commit);
        if (count($revs) == 0)
            return false;

        $cmd = Pluf::f('idf_exec_cmd_prefix', '')
            .sprintf("%s -d %s automate get_revision %s",
                     Pluf::f('mtn_path', 'mtn'),
                     escapeshellarg($this->repo),
                     escapeshellarg($revs[0]));
        self::exec('IDF_Scm_Monotone::isCommitLarge',
                   $cmd, $out, $return);

        $newAndPatchedFiles = 0;
        $stanzas = self::_parseBasicIO(implode("\n", $out));

        foreach ($stanzas as $stanza)
        {
            if ($stanza[0]['key'] == "patch" || $stanza[0]['key'] == "add_file")
                $newAndPatchedFiles++;
        }

        return $newAndPatchedFiles > 100;
    }

    /**
     * Get latest changes.
     *
     * @param string Commit ('HEAD').
     * @param int Number of changes (10).
     * @return array Changes.
     */
    public function getChangeLog($commit='HEAD', $n=10)
    {
        if ($n === null) $n = '';
        else $n = ' -'.$n;
        $cmd = sprintf('GIT_DIR=%s '.Pluf::f('git_path', 'git').' log%s --date=iso --pretty=format:\'%s\' %s',
                       escapeshellarg($this->repo), $n, $this->mediumtree_fmt,
                       escapeshellarg($commit));
        $out = array();
        $cmd = Pluf::f('idf_exec_cmd_prefix', '').$cmd;
        self::exec('IDF_Scm_Monotone::getChangeLog', $cmd, $out);
        return self::parseLog($out);
    }
}
