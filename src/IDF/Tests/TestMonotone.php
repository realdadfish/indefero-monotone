<?php
/* -*- tab-width: 4; indent-tabs-mode: nil; c-basic-offset: 4 -*- */
/*
# ***** BEGIN LICENSE BLOCK *****
# This file is part of InDefero, an open source project management application.
# Copyright (C) 2010 Céondo Ltd and contributors.
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

require_once("simpletest/autorun.php");

/**
 * Test the monotone class.
 */
class IDF_Tests_TestMonotone extends UnitTestCase
{
    private $tmpdir, $dbfile;

    private function mtnCall($args, $stdin = null, $dir = null)
    {
        $cmdline = array("mtn",
                        "--confdir", $this->tmpdir,
                        "--db", $this->dbfile,
                        "--norc",
                        "--timestamps");

        $cmdline = array_merge($cmdline, $args);

        $descriptorspec = array(
           0 => array("pipe", "r"),
           1 => array("pipe", "w"),
           2 => array("file", "{$this->tmpdir}/mtn-errors", "a")
        );

        $pipes = array();
        $process = proc_open(implode(" ", $cmdline),
                             $descriptorspec,
                             $pipes,
                             empty($dir) ? $this->tmpdir : $dir);

        if (!is_resource($process))
        {
            throw new Exception("could not create process");
        }

        if (!empty($stdin))
        {
            fwrite($pipes[0], $stdin);
            fclose($pipes[0]);
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $ret = proc_close($process);
        if ($ret != 0)
        {
            throw new Exception(
                "call ended with a non-zero error code (complete cmdline was: ".
                implode(" ", $cmdline).")"
            );
        }

        return $stdout;
    }

    public function __construct()
    {
        parent::__construct("Test the monotone class.");

        $this->tmpdir = sys_get_temp_dir() . "/mtn-test";
        echo "test root is {$this->tmpdir}\n";
        $this->dbfile = "{$this->tmpdir}/test.mtn";
    }

    private static function deleteRecursive($dirname)
    {
        if (is_dir($dirname))
            $dir_handle=opendir($dirname);

        while ($file = readdir($dir_handle))
        {
            if ($file!="." && $file!="..")
            {
                if (!is_dir($dirname."/".$file))
                {
                    unlink ($dirname."/".$file);
                    continue;
                }
                self::deleteRecursive($dirname."/".$file);
            }
        }

        closedir($dir_handle);
        rmdir($dirname);

        return true;
    }

    public function setUp()
    {
        if (is_dir($this->tmpdir))
        {
            self::deleteRecursive($this->tmpdir);
        }

        mkdir($this->tmpdir);

        $this->mtnCall(array("db", "init"));

        $this->mtnCall(array("genkey", "test@test.de"), "\n\n");

        $workspaceRoot = "{$this->tmpdir}/test-workspace";
        mkdir($workspaceRoot);

        $this->mtnCall(array("setup", "-b", "testbranch", "blafoo"), null, $workspaceRoot);

        file_put_contents("$workspaceRoot/foo", "blafoo");
        $this->mtnCall(array("add", "foo"), null, $workspaceRoot);

        $this->mtnCall(array("commit", "-m", "initial"), null, $workspaceRoot);

        file_put_contents("$workspaceRoot/foo", "bla");

        file_put_contents("$workspaceRoot/bar", "blafoo");
        $this->mtnCall(array("add", "bar"), null, $workspaceRoot);

        $this->mtnCall(array("commit", "-m", "second"), null, $workspaceRoot);
    }

    public function testBranches()
    {
        $this->assertTrue(false);
    }
}
