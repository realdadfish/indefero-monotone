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
    public $mediumtree_fmt = 'commit %H%nAuthor: %an <%ae>%nTree: %T%nDate: %ai%n%n%s%n%n%b';

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
        try {
            $branches = $this->getBranches();
        } catch (IDF_Scm_Exception $e) {
            return false;
        }
        return (count($branches) > 0);
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
    private static function _resolveSelector($selector)
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

    private static function _getUniqueCertValuesFor($revs, $certName)
    {
        $certValues = array();
        foreach ($revs as $rev)
        {
            $cmd = Pluf::f('idf_exec_cmd_prefix', '')
                .sprintf("%s -d %s automate certs %s",
                         Pluf::f('mtn_path', 'mtn'),
                         escapeshellarg($this->repo),
                         escapeshellarg($rev));
            self::exec('IDF_Scm_Monotone::inBranches',
                       $cmd, $out, $return);

            $stanzas = self::_parseBasicIO(implode('\n', $out));
            foreach ($stanzas as $stanza)
            {
                foreach ($stanza as $stanzaline)
                {
                    // luckily, name always comes before value
                    if ($stanzaline['key'] == "name" &&
                        $stanzaline['values'][0] != $certName)
                    {
                        break;
                    }
                    if ($stanzaline['key'] == "value")
                    {
                        $certValues[] = $stanzaline['values'][0];
                        break;
                    }
                }
            }
        }
        return array_unique($certValues);
    }

    /**
     * @see IDF_Scm::inBranches()
     **/
    public function inBranches($commit, $path)
    {
        $revs = self::_resolveSelector($commit);
        if (count($revs) == 0) return array();
        return self::_getUniqueCertValuesFor($revs, "branch");
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
        $stanzas = self::parseBasicIO(implode('\n', $out));
        foreach ($stanzas as $stanza)
        {
            foreach ($stanza as $stanzaline)
            {
                if ($stanzaline['key'] == "tag")
                {
                    $tags[] = $stanzaline['values'][0];
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
        $revs = self::_resolveSelector($commit);
        if (count($revs) == 0) return array();
        return self::_getUniqueCertValuesFor($revs, "tag");
    }

    /**
     * @see IDF_Scm::getTree()
     */
    public function getTree($commit, $folder='/', $branch=null)
    {
        $revs = self::_resolveSelector($commit);
        if ($revs != 1)
        {
            throw new Exception(sprintf(
                __('Commit %1$s does not (uniquely) identify a revision.'),
                $commit
            ));
        }

        $cmd = Pluf::f('idf_exec_cmd_prefix', '')
                .sprintf("%s -d %s automate get_manifest_of %s",
                         Pluf::f('mtn_path', 'mtn'),
                         escapeshellarg($this->repo),
                         escapeshellarg($revs[0]));
        self::exec('IDF_Scm_Monotone::getTree', $cmd, $out, $return);

        $files = array();
        $stanzas = self::parseBasicIO(implode('\n', $out));
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
                $file['type'] == "tree";
            else
                $file['type'] == "blob";

            /*
            $file['date'] = gmdate('Y-m-d H:i:s',
                                   strtotime((string) $entry->commit->date));
            $file['rev'] = (string) $entry->commit['revision'];
            $file['log'] = $this->getCommitMessage($file['rev']);
            // Get the size if the type is blob
            if ($file['type'] == 'blob') {
                $file['size'] = (string) $entry->size;
            }
            $file['author'] = (string) $entry->commit->author;
            */
            $file['perm'] = '';
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
        // We extract the email.
        $match = array();
        if (!preg_match('/<(.*)>/', $author, $match)) {
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

    public static function getAnonymousAccessUrl($project)
    {
        $conf = $project->getConf();
        if (false === ($branch = $conf->getVal('mtn_master_branch', false))
            || empty($branch)) {
            $branch = "*";
        }
        return sprintf(
            Pluf::f('mtn_remote_url'),
            $project->shortname,
            $branch
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
        $rep = sprintf(Pluf::f('git_repositories'), $project->shortname);
        return new IDF_Scm_Monotone($rep, $project);
    }

    public function isValidRevision($commit)
    {
        $type = $this->testHash($commit);
        return ('commit' == $type || 'tag' == $type);
    }

    /**
     * Test a given object hash.
     *
     * @param string Object hash.
     * @return mixed false if not valid or 'blob', 'tree', 'commit', 'tag'
     */
    public function testHash($hash)
    {
        $cmd = sprintf('GIT_DIR=%s '.Pluf::f('git_path', 'git').' cat-file -t %s',
                       escapeshellarg($this->repo),
                       escapeshellarg($hash));
        $ret = 0; $out = array();
        $cmd = Pluf::f('idf_exec_cmd_prefix', '').$cmd;
        self::exec('IDF_Scm_Monotone::testHash', $cmd, $out, $ret);
        if ($ret != 0) return false;
        return trim($out[0]);
    }

    /**
     * Get the tree info.
     *
     * @param string Tree hash
     * @param bool Do we recurse in subtrees (true)
     * @param string Folder in which we want to get the info ('')
     * @return array Array of file information.
     */
    public function getTreeInfo($tree, $folder='')
    {
        if (!in_array($this->testHash($tree), array('tree', 'commit', 'tag'))) {
            throw new Exception(sprintf(__('Not a valid tree: %s.'), $tree));
        }
        $cmd_tmpl = 'GIT_DIR=%s '.Pluf::f('git_path', 'git').' ls-tree -l %s %s';
        $cmd = Pluf::f('idf_exec_cmd_prefix', '')
            .sprintf($cmd_tmpl, escapeshellarg($this->repo),
                     escapeshellarg($tree), escapeshellarg($folder));
        $out = array();
        $res = array();
        self::exec('IDF_Scm_Monotone::getTreeInfo', $cmd, $out);
        foreach ($out as $line) {
            list($perm, $type, $hash, $size, $file) = preg_split('/ |\t/', $line, 5, PREG_SPLIT_NO_EMPTY);
            $res[] = (object) array('perm' => $perm, 'type' => $type,
                                    'size' => $size, 'hash' => $hash,
                                    'file' => $file);
        }
        return $res;
    }

    /**
     * Get the file info.
     *
     * @param string File
     * @param string Commit ('HEAD')
     * @return false Information
     */
    public function getPathInfo($totest, $commit='HEAD')
    {
        $cmd_tmpl = 'GIT_DIR=%s '.Pluf::f('git_path', 'git').' ls-tree -r -t -l %s';
        $cmd = sprintf($cmd_tmpl,
                       escapeshellarg($this->repo),
                       escapeshellarg($commit));
        $out = array();
        $cmd = Pluf::f('idf_exec_cmd_prefix', '').$cmd;
        self::exec('IDF_Scm_Monotone::getPathInfo', $cmd, $out);
        foreach ($out as $line) {
            list($perm, $type, $hash, $size, $file) = preg_split('/ |\t/', $line, 5, PREG_SPLIT_NO_EMPTY);
            if ($totest == $file) {
                $pathinfo = pathinfo($file);
                return (object) array('perm' => $perm, 'type' => $type,
                                      'size' => $size, 'hash' => $hash,
                                      'fullpath' => $file,
                                      'file' => $pathinfo['basename']);
            }
        }
        return false;
    }

    public function getFile($def, $cmd_only=false)
    {
        $cmd = sprintf(Pluf::f('idf_exec_cmd_prefix', '').
                       'GIT_DIR=%s '.Pluf::f('git_path', 'git').' cat-file blob %s',
                       escapeshellarg($this->repo),
                       escapeshellarg($def->hash));
        return ($cmd_only)
            ? $cmd : self::shell_exec('IDF_Scm_Monotone::getFile', $cmd);
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
        if ($getdiff) {
            $cmd = sprintf('GIT_DIR=%s '.Pluf::f('git_path', 'git').' show --date=iso --pretty=format:%s %s',
                           escapeshellarg($this->repo),
                           "'".$this->mediumtree_fmt."'",
                           escapeshellarg($commit));
        } else {
            $cmd = sprintf('GIT_DIR=%s '.Pluf::f('git_path', 'git').' log -1 --date=iso --pretty=format:%s %s',
                           escapeshellarg($this->repo),
                           "'".$this->mediumtree_fmt."'",
                           escapeshellarg($commit));
        }
        $out = array();
        $cmd = Pluf::f('idf_exec_cmd_prefix', '').$cmd;
        self::exec('IDF_Scm_Monotone::getCommit', $cmd, $out, $ret);
        if ($ret != 0 or count($out) == 0) {
            return false;
        }
        if ($getdiff) {
            $log = array();
            $change = array();
            $inchange = false;
            foreach ($out as $line) {
                if (!$inchange and 0 === strpos($line, 'diff --git a')) {
                    $inchange = true;
                }
                if ($inchange) {
                    $change[] = $line;
                } else {
                    $log[] = $line;
                }
            }
            $out = self::parseLog($log);
            $out[0]->changes = implode("\n", $change);
        } else {
            $out = self::parseLog($out);
            $out[0]->changes = '';
        }
        return $out[0];
    }

    /**
     * Check if a commit is big.
     *
     * @param string Commit ('HEAD')
     * @return bool The commit is big
     */
    public function isCommitLarge($commit='HEAD')
    {
        $cmd = sprintf('GIT_DIR=%s '.Pluf::f('git_path', 'git').' log --numstat -1 --pretty=format:%s %s',
                       escapeshellarg($this->repo),
                       "'commit %H%n'",
                       escapeshellarg($commit));
        $out = array();
        $cmd = Pluf::f('idf_exec_cmd_prefix', '').$cmd;
        self::exec('IDF_Scm_Monotone::isCommitLarge', $cmd, $out);
        $affected = count($out) - 2;
        $added = 0;
        $removed = 0;
        $c=0;
        foreach ($out as $line) {
            $c++;
            if ($c < 3) {
                continue;
            }
            list($a, $r, $f) = preg_split("/[\s]+/", $line, 3, PREG_SPLIT_NO_EMPTY);
            $added+=$a;
            $removed+=$r;
        }
        return ($affected > 100 or ($added + $removed) > 20000);
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

    /**
     * Parse the log lines of a --pretty=medium log output.
     *
     * @param array Lines.
     * @return array Change log.
     */
    public static function parseLog($lines)
    {
        $res = array();
        $c = array();
        $inheads = true;
        $next_is_title = false;
        foreach ($lines as $line) {
            if (preg_match('/^commit (\w{40})$/', $line)) {
                if (count($c) > 0) {
                    $c['full_message'] = trim($c['full_message']);
                    $c['full_message'] = IDF_Commit::toUTF8($c['full_message']);
                    $c['title'] = IDF_Commit::toUTF8($c['title']);
                    $res[] = (object) $c;
                }
                $c = array();
                $c['commit'] = trim(substr($line, 7, 40));
                $c['full_message'] = '';
                $inheads = true;
                $next_is_title = false;
                continue;
            }
            if ($next_is_title) {
                $c['title'] = trim($line);
                $next_is_title = false;
                continue;
            }
            $match = array();
            if ($inheads and preg_match('/(\S+)\s*:\s*(.*)/', $line, $match)) {
                $match[1] = strtolower($match[1]);
                $c[$match[1]] = trim($match[2]);
                if ($match[1] == 'date') {
                    $c['date'] = gmdate('Y-m-d H:i:s', strtotime($match[2]));
                }
                continue;
            }
            if ($inheads and !$next_is_title and $line == '') {
                $next_is_title = true;
                $inheads = false;
            }
            if (!$inheads) {
                $c['full_message'] .= trim($line)."\n";
                continue;
            }
        }
        $c['full_message'] = !empty($c['full_message']) ? trim($c['full_message']) : '';
        $c['full_message'] = IDF_Commit::toUTF8($c['full_message']);
        $c['title'] = IDF_Commit::toUTF8($c['title']);
        $res[] = (object) $c;
        return $res;
    }

    public function getArchiveCommand($commit, $prefix='repository/')
    {
        return sprintf(Pluf::f('idf_exec_cmd_prefix', '').
                       'GIT_DIR=%s '.Pluf::f('git_path', 'git').' archive --format=zip --prefix=%s %s',
                       escapeshellarg($this->repo),
                       escapeshellarg($prefix),
                       escapeshellarg($commit));
    }
}