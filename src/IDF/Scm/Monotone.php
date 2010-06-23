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
 * Monotone stdio class
 *
 * Connects to a monotone process and executes commands via its
 * stdio interface
 *
 * @author Thomas Keller <me@thomaskeller.biz>
 */
class IDF_Scm_Monotone_Stdio
{
    /** this is the most recent STDIO version. The number is output
        at the protocol start. Older versions of monotone (prior 0.47)
        do not output it and are therefor incompatible */
    public static $SUPPORTED_STDIO_VERSION = 2;

    private $project;
    private $proc;
    private $pipes;
    private $oob;
    private $cmdnum;
    private $lastcmd;

    /**
     * Constructor - starts the stdio process
     *
     * @param IDF_Project
     */
    public function __construct(IDF_Project $project)
    {
        $this->project = $project;
        $this->start();
    }

    /**
     * Destructor - stops the stdio process
     */
    public function __destruct()
    {
        $this->stop();
    }

    /**
     * Starts the stdio process and resets the command counter
     */
    public function start()
    {
        if (is_resource($this->proc))
            $this->stop();

        $remote_db_access = Pluf::f('mtn_db_access', 'remote') == "remote";

        $cmd = Pluf::f('idf_exec_cmd_prefix', '') .
               Pluf::f('mtn_path', 'mtn') . ' ';

        $opts = Pluf::f('mtn_opts', array());
        foreach ($opts as $opt)
        {
            $cmd .= sprintf('%s ', escapeshellarg($opt));
        }

        // FIXME: we might want to add an option for anonymous / no key
        // access, but upstream bug #30237 prevents that for now
        if ($remote_db_access)
        {
            $host = sprintf(Pluf::f('mtn_remote_url'), $this->project->shortname);
            $cmd .= sprintf('automate remote_stdio %s', escapeshellarg($host));
        }
        else
        {
            $repo = sprintf(Pluf::f('mtn_repositories'), $this->project->shortname);
            if (!file_exists($repo))
            {
                throw new IDF_Scm_Exception(
                    "repository file '$repo' does not exist"
                );
            }
            $cmd .= sprintf('--db %s automate stdio', escapeshellarg($repo));
        }

        $descriptors = array(
            0 => array("pipe", "r"),
            1 => array("pipe", "w"),
            2 => array("pipe", "w"),
        );

        $env = array("LANG" => "en_US.UTF-8");

        $this->proc = proc_open($cmd, $descriptors, $this->pipes,
                                null, $env);

        if (!is_resource($this->proc))
        {
            throw new IDF_Scm_Exception("could not start stdio process");
        }

        $this->_checkVersion();

        $this->cmdnum = -1;
    }

    /**
     * Stops the stdio process and closes all pipes
     */
    public function stop()
    {
        if (!is_resource($this->proc))
            return;

        fclose($this->pipes[0]);
        fclose($this->pipes[1]);
        fclose($this->pipes[2]);

        proc_close($this->proc);
        $this->proc = null;
    }

    /**
     * select()'s on stdout and returns true as soon as we got new
     * data to read, false if the select() timed out
     *
     * @return boolean
     * @throws IDF_Scm_Exception
     */
    private function _waitForReadyRead()
    {
        if (!is_resource($this->pipes[1]))
            return false;

        $read = array($this->pipes[1], $this->pipes[2]);
        $streamsChanged = stream_select(
            $read, $write = null, $except = null, 0, 20000
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

    /**
     * Checks the version of the used stdio protocol
     *
     * @throws IDF_Scm_Exception
     */
    private function _checkVersion()
    {
        $this->_waitForReadyRead();

        $version = fgets($this->pipes[1]);
        if ($version === false)
        {
            throw new IDF_Scm_Exception(
                "Could not determine stdio version, stderr is:\n".
                $this->_readStderr()
            );
        }

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

    /**
     * Writes a command to stdio
     *
     * @param array
     * @param array
     * @throws IDF_Scm_Exception
     */
    private function _write(array $args, array $options = array())
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

    /**
     * Reads all output from stderr and returns it
     *
     * @return string
     */
    private function _readStderr()
    {
        $err = "";
        while (($line = fgets($this->pipes[2])) !== false)
        {
            $err .= $line;
        }
        return empty($err) ? "<empty>" : $err;
    }

    /**
     * Reads the last output from the stdio process, parses and returns it
     *
     * @return string
     * @throws IDF_Scm_Exception
     */
    private function _readStdout()
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
                if ($c === false)
                {
                    throw new IDF_Scm_Exception(
                        "No data on stdin, stderr is:\n".
                        $this->_readStderr()
                    );
                }

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

    /**
     * Executes a command over stdio and returns its result
     *
     * @param array Array of arguments
     * @param array Array of options as key-value pairs. Multiple options
     *              can be defined in sub-arrays, like
     *              "r" => array("123...", "456...")
     * @return string
     */
    public function exec(array $args, array $options = array())
    {
        $this->_write($args, $options);
        return $this->_readStdout();
    }

    /**
     * Returns the last out-of-band output for a previously executed
     * command as associative array with 'e' (error), 'w' (warning),
     * 'p' (progress) and 't' (ticker, unparsed) as keys
     *
     * @return array
     */
    public function getLastOutOfBandOutput()
    {
        return $this->oob;
    }
}

/**
 * Monotone scm class
 *
 * @author Thomas Keller <me@thomaskeller.biz>
 */
class IDF_Scm_Monotone extends IDF_Scm
{
    /** the minimum supported interface version */
    public static $MIN_INTERFACE_VERSION = 12.0;

    private $stdio;

    /**
     * @see IDF_Scm::__construct()
     */
    public function __construct($project)
    {
        $this->project = $project;
        $this->stdio = new IDF_Scm_Monotone_Stdio($project);
    }

    /**
     * @see IDF_Scm::getRepositorySize()
     */
    public function getRepositorySize()
    {
        // FIXME: this obviously won't work with remote databases - upstream
        // needs to implement mtn db info in automate at first
        $repo = sprintf(Pluf::f('mtn_repositories'), $this->project->shortname);
        if (!file_exists($repo))
        {
            return 0;
        }

        $cmd = Pluf::f('idf_exec_cmd_prefix', '').'du -sk '
            .escapeshellarg($repo);
        $out = explode(' ',
                       self::shell_exec('IDF_Scm_Monotone::getRepositorySize', $cmd),
                       2);
        return (int) $out[0]*1024;
    }

    /**
     * @see IDF_Scm::isAvailable()
     */
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

    /**
     * @see IDF_Scm::getBranches()
     */
    public function getBranches()
    {
        if (isset($this->cache['branches'])) {
            return $this->cache['branches'];
        }
        // FIXME: we could / should introduce handling of suspended
        // (i.e. dead) branches here by hiding them from the user's eye...
        $out = $this->stdio->exec(array("branches"));

        // note: we could expand each branch with one of its head revisions
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
     * the configured one. Ensure however that we can select revisions
     * with it at all.
     *
     * @see IDF_Scm::getMainBranch()
     */
    public function getMainBranch()
    {
        $conf = $this->project->getConf();
        if (false === ($branch = $conf->getVal('mtn_master_branch', false))
            || empty($branch)) {
            $branch = "*";
        }

        if (count($this->_resolveSelector("h:$branch")) == 0)
        {
            throw new IDF_Scm_Exception(
                "Branch $branch is empty"
            );
        }

        return $branch;
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

    /**
     * Queries the certs for a given revision and returns them in an
     * associative array array("branch" => array("branch1", ...), ...)
     *
     * @param string
     * @param array
     */
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

    /**
     * Returns unique certificate values for the given revs and the specific
     * cert name
     *
     * @param array
     * @param string
     * @return array
     */
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

    /**
     * Returns the revision in which the file has been last changed,
     * starting from the start rev
     *
     * @param string
     * @param string
     * @return string
     */
    private function _getLastChangeFor($file, $startrev)
    {
        $out = $this->stdio->exec(array(
            "get_content_changed", $startrev, $file
        ));

        $stanzas = self::_parseBasicIO($out);

        // FIXME: we only care about the first returned content mark
        // everything else seem to be very, very rare cases
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
     */
    public function inBranches($commit, $path)
    {
        $revs = $this->_resolveSelector($commit);
        if (count($revs) == 0) return array();
        return $this->_getUniqueCertValuesFor($revs, "branch");
    }

    /**
     * @see IDF_Scm::getTags()
     */
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
     */
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
     * @see IDF_Scm::findAuthor()
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

    /**
     * @see IDF_Scm::getAnonymousAccessUrl()
     */
    public static function getAnonymousAccessUrl($project, $commit = null)
    {
        $scm = IDF_Scm::get($project);
        $branch = $scm->getMainBranch();

        if (!empty($commit))
        {
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

        $remote_url = Pluf::f('mtn_remote_url', '');
        if (empty($remote_url))
        {
            return '';
        }

        return sprintf($remote_url, $project->shortname)."?".$branch;
    }

    /**
     * @see IDF_Scm::getAuthAccessUrl()
     */
    public static function getAuthAccessUrl($project, $user, $commit = null)
    {
        $url = self::getAnonymousAccessUrl($project, $commit);
        return preg_replace("#^ssh://#", "ssh://$user@", $url);
    }

    /**
     * Returns this object correctly initialized for the project.
     *
     * @param IDF_Project
     * @return IDF_Scm_Monotone
     */
    public static function factory($project)
    {
        return new IDF_Scm_Monotone($project);
    }

    /**
     * @see IDF_Scm::isValidRevision()
     */
    public function isValidRevision($commit)
    {
        $revs = $this->_resolveSelector($commit);
        return count($revs) == 1;
    }

    /**
     * @see IDF_Scm::getPathInfo()
     */
    public function getPathInfo($file, $commit = null)
    {
        if ($commit === null) {
            $commit = 'h:' . $this->getMainBranch();
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

    /**
     * @see IDF_Scm::getFile()
     */
    public function getFile($def, $cmd_only=false)
    {
        // this won't work with remote databases
        if ($cmd_only)
        {
            throw new Pluf_Exception_NotImplemented();
        }

        return $this->stdio->exec(array("get_file", $def->hash));
    }

    /**
     * Returns the differences between two revisions as unified diff
     *
     * @param string    The target of the diff
     * @param string    The source of the diff, if not given, the first
     *                  parent of the target is used
     * @return string
     */
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
     * @see IDF_Scm::getCommit()
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
     * @see IDF_Scm::isCommitLarge()
     */
    public function isCommitLarge($commit=null)
    {
        if (empty($commit))
        {
            $commit = "h:"+$this->getMainBranch();
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
     * @see IDF_Scm::getChangeLog()
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

