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

class IDF_Scm_Monotone_Stdio
{
    public static $SUPPORTED_STDIO_VERSION = 2;

    private $repo;
    private $proc;
    private $pipes;
    private $oob;
    private $cmdnum;
    private $lastcmd;

    public function __construct($repo)
    {
        $this->repo = $repo;
        $this->start();
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function start()
    {
        if (is_resource($this->proc))
            $this->stop();

        $cmd = Pluf::f('idf_exec_cmd_prefix', '')
            .sprintf("%s -d %s automate stdio --no-workspace --norc",
                         Pluf::f('mtn_path', 'mtn'),
                         escapeshellarg($this->repo));

        $descriptors = array(
            0 => array("pipe", "r"),
            1 => array("pipe", "w"),
        );

        $this->proc = proc_open($cmd, $descriptors, $this->pipes);

        if (!is_resource($this->proc))
        {
            throw new IDF_Scm_Exception("could not start stdio process");
        }

        $this->_checkVersion();

        $this->cmdnum = -1;
    }

    public function stop()
    {
        if (!is_resource($this->proc))
            return;

        fclose($this->pipes[0]);
        fclose($this->pipes[1]);

        proc_close($this->proc);
        $this->proc = null;
    }

    private function _waitForReadyRead()
    {
        if (!is_resource($this->pipes[1]))
            return false;

        $read = array($this->pipes[1]);
        $write = null;
        $except = null;

        $streamsChanged = stream_select(
            $read, $write, $except, 0, 20000
        );

        if ($streamsChanged === false)
        {
            throw new IDF_Scm_Exception(
                "Could not select() on read pipe"
            );
        }

        if ($streamsChanged == 0)
        {
            return false;
        }

        return true;
    }

    private function _checkVersion()
    {
        $this->_waitForReadyRead();

        $version = fgets($this->pipes[1]);
        if (!preg_match('/^format-version: (\d+)$/', $version, $m) ||
            $m[1] != self::$SUPPORTED_STDIO_VERSION)
        {
            throw new IDF_Scm_Exception(
                "stdio format version mismatch, expected '".
                self::$SUPPORTED_STDIO_VERSION."', got '".@$m[1]."'"
            );
        }

        fgets($this->pipes[1]);
    }

    private function _write($args, $options = array())
    {
        $cmd = "";
        if (count($options) > 0)
        {
            $cmd = "o";
            foreach ($options as $k => $vals)
            {
                if (!is_array($vals))
                    $vals = array($vals);

                foreach ($vals as $v)
                {
                    $cmd .= strlen((string)$k) . ":" . (string)$k;
                    $cmd .= strlen((string)$v) . ":" . (string)$v;
                }
            }
            $cmd .= "e ";
        }

        $cmd .= "l";
        foreach ($args as $arg)
        {
            $cmd .= strlen((string)$arg) . ":" . (string)$arg;
        }
        $cmd .= "e\n";

        if (!fwrite($this->pipes[0], $cmd))
        {
            throw new IDF_Scm_Exception("could not write '$cmd' to process");
        }

        $this->lastcmd = $cmd;
        $this->cmdnum++;
    }

    private function _read()
    {
        $this->oob = array('w' => array(),
                           'p' => array(),
                           't' => array(),
                           'e' => array());

        $output = "";
        $errcode = 0;

        while (true)
        {
            if (!$this->_waitForReadyRead())
                continue;

            $data = array(0,"",0);
            $idx = 0;
            while (true)
            {
                $c = fgetc($this->pipes[1]);
                if ($c == ':')
                {
                    if ($idx == 2)
                        break;

                    ++$idx;
                    continue;
                }

                if (is_numeric($c))
                    $data[$idx] = $data[$idx] * 10 + $c;
                else
                    $data[$idx] .= $c;
            }

            // sanity
            if ($this->cmdnum != $data[0])
            {
                throw new IDF_Scm_Exception(
                    "command numbers out of sync; ".
                    "expected {$this->cmdnum}, got {$data[0]}"
                );
            }

            $toRead = $data[2];
            $buffer = "";
            while ($toRead > 0)
            {
                $buffer .= fread($this->pipes[1], $toRead);
                $toRead = $data[2] - strlen($buffer);
            }

            switch ($data[1])
            {
                case 'w':
                case 'p':
                case 't':
                case 'e':
                    $this->oob[$data[1]][] = $buffer;
                    continue;
                case 'm':
                    $output .= $buffer;
                    continue;
                case 'l':
                    $errcode = $buffer;
                    break 2;
            }
        }

        if ($errcode != 0)
        {
            throw new IDF_Scm_Exception(
                "command '{$this->lastcmd}' returned error code $errcode: ".
                implode(" ", $this->oob['e'])
            );
        }

        return $output;
    }

    public function exec($args, $options = array())
    {
        $this->_write($args, $options);
        return $this->_read();
    }

    public function getLastWarnings()
    {
        return array_key_exists('w', $this->oob) ?
            $this->oob['w'] : array();
    }

    public function getLastProgress()
    {
        return array_key_exists('p', $this->oob) ?
            $this->oob['p'] : array();
    }

    public function getLastTickers()
    {
        return array_key_exists('t', $this->oob) ?
            $this->oob['t'] : array();
    }

    public function getLastErrors()
    {
        return array_key_exists('e', $this->oob) ?
            $this->oob['e'] : array();
    }
}

class IDF_Scm_Monotone extends IDF_Scm
{
    public static $MIN_INTERFACE_VERSION = 12.0;

    private $stdio;

    /* ============================================== *
     *                                                *
     *   Common Methods Implemented By All The SCMs   *
     *                                                *
     * ============================================== */

    public function __construct($repo, $project=null)
    {
        $this->repo = $repo;
        $this->project = $project;
        $this->stdio = new IDF_Scm_Monotone_Stdio($repo);
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
        try
        {
            $out = $this->stdio->exec(array("interface_version"));
            return floatval($out) >= self::$MIN_INTERFACE_VERSION;
        }
        catch (IDF_Scm_Exception $e) {}

        return false;
    }

    public function getBranches()
    {
        if (isset($this->cache['branches'])) {
            return $this->cache['branches'];
        }
        // FIXME: introduce handling of suspended branches
        $out = $this->stdio->exec(array("branches"));

        // FIXME: we could expand each branch with one of its head revisions
        // here, but these would soon become bogus anyway and we cannot
        // map multiple head revisions here either, so we just use the
        // selector as placeholder
        $res = array();
        foreach (preg_split("/\n/", $out, -1, PREG_SPLIT_NO_EMPTY) as $b)
        {
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
        $out = $this->stdio->exec(array("select", $selector));
        return preg_split("/\n/", $out, -1, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * Parses monotone's basic_io format
     *
     * @param string $in
     * @return array of arrays
     */
    private static function _parseBasicIO($in)
    {
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

                    for ($i = 0; $i <= $valCount; $i++)
                    {
                        $stanzaLine['values'][$i] = str_replace(
                            array("\\\\", "\\\""),
                            array("\\", "\""),
                            $stanzaLine['values'][$i]
                        );
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
            $out = $this->stdio->exec(array("certs", $rev));

            $stanzas = self::_parseBasicIO($out);
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
        $out = $this->stdio->exec(array(
            "get_content_changed", $startrev, $file
        ));

        $stanzas = self::_parseBasicIO($out);

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
        if (isset($this->cache['tags']))
        {
            return $this->cache['tags'];
        }

        $out = $this->stdio->exec(array("tags"));

        $tags = array();
        $stanzas = self::_parseBasicIO($out);
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

        $out = $this->stdio->exec(array(
            "get_manifest_of", $revs[0]
        ));

        $files = array();
        $stanzas = self::_parseBasicIO($out);
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
                $file['log'] = implode("\n---\n", $certs['changelog']);
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

    public static function getAnonymousAccessUrl($project, $commit = null)
    {
        $branch = self::_getMasterBranch($project);
        if (!empty($commit))
        {
            $scm = IDF_Scm::get($project);
            $revs = $scm->_resolveSelector($commit);
            if (count($revs) > 0)
            {
                $certs = $scm->_getCerts($revs[0]);
                // for the very seldom case that a revision
                // has no branch certificate
                if (count($certs['branch']) == 0)
                {
                    $branch = "*";
                }
                else
                {
                    $branch = $certs['branch'][0];
                }
            }
        }

        $protocol = Pluf::f('mtn_remote_protocol', 'netsync');

        if ($protocol == "ssh")
        {
            // ssh is protocol + host + db-path + branch
            return "ssh://" .
                sprintf(Pluf::f('mtn_remote_host'), $project->shortname) .
                sprintf(Pluf::f('mtn_repositories'), $project->shortname) .
                " " . $branch;
        }

        // netsync is the default
        return sprintf(
            Pluf::f('mtn_remote_host'),
            $project->shortname
        )." ".$branch;
    }

    public static function getAuthAccessUrl($project, $user, $commit = null)
    {
        return self::getAnonymousAccessUrl($project, $commit);
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

        $out = $this->stdio->exec(array(
            "get_manifest_of", $revs[0]
        ));

        $files = array();
        $stanzas = self::_parseBasicIO($out);

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
                $file['log'] = implode("\n---\n", $certs['changelog']);
            }

            return (object) $file;
        }
        return false;
    }

    public function getFile($def, $cmd_only=false)
    {
        // this won't work with remote databases
        if ($cmd_only)
        {
            throw new Pluf_Exception_NotImplemented();
        }

        return $this->stdio->exec(array("get_file", $def->hash));
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

        return $this->stdio->exec(
            array("content_diff"),
            array("r" => array($sources[0], $targets[0]))
        );
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

        $res['title'] = implode("\n---\n", $certs['changelog']);

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

        $out = $this->stdio->exec(array(
            "get_revision", $revs[0]
        ));

        $newAndPatchedFiles = 0;
        $stanzas = self::_parseBasicIO($out);

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
    public function getChangeLog($commit=null, $n=10)
    {
        $horizont = $this->_resolveSelector($commit);
        $initialBranches = array();
        $logs = array();

        while (!empty($horizont) && $n > 0)
        {
            if (count($horizont) > 1)
            {
                $out = $this->stdio->exec(array("toposort") + $horizont);
                $horizont = preg_split("/\n/", $out, -1, PREG_SPLIT_NO_EMPTY);
            }

            $rev = array_shift($horizont);
            $certs = $this->_getCerts($rev);

            // read in the initial branches we should follow
            if (count($initialBranches) == 0)
            {
                $initialBranches = $certs['branch'];
            }

            // only add it to our log if it is on one of the initial branches
            if (count(array_intersect($initialBranches, $certs['branch'])) > 0)
            {
                --$n;

                $log = array();
                $log['author'] = implode(", ", $certs['author']);

                $dates = array();
                foreach ($certs['date'] as $date)
                    $dates[] = gmdate('Y-m-d H:i:s', strtotime($date));
                $log['date'] = implode(', ', $dates);

                $combinedChangelog = implode("\n---\n", $certs['changelog']);
                $split = preg_split("/[\n\r]/", $combinedChangelog, 2);
                $log['title'] = $split[0];
                $log['full_message'] = (isset($split[1])) ? trim($split[1]) : '';

                $log['commit'] = $rev;

                $logs[] = (object)$log;
            }

            $out = $this->stdio->exec(array("parents", $rev));
            $horizont += preg_split("/\n/", $out, -1, PREG_SPLIT_NO_EMPTY);
        }

        return $logs;
    }
}
