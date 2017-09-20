<?php
/*
This is part of Wedeto, the WEb DEvelopment TOolkit.
It is published under the MIT Open Source License.

Copyright 2017, Egbert van der Wal

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
the Software, and to permit persons to whom the Software is furnished to do so,
subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
*/

namespace Wedeto\DB;

use Wedeto\DB\Exception\MigrationException;
use Wedeto\DB\Exception\InvalidTypeException;
use Wedeto\Model\DBVersion;

class Module
{
    protected $max_version = null;
    protected $db_version = null;
    protected $module = null;
    protected $migration_path = null;

    protected $migrations = [];

    public function __construct(string $module, $path, Repository $repository)
    {
        $this->module = $module;
        $this->migration_path = $path;

        try
        {
            $columns = DBVersion::getColumns();
        }
        catch (TableNotExists $e)
        {
            $db_migrator = $module === "Wedeto.DB" ? $this : $repository->getMigration('Wedeto.DB');

            if ($db_migrator->isUpToDate())
                throw $e;

            $db_migrator->upgradeToLatest();
        }

        $this->db_version = new DBVersion($module);
    }

    public function getLatestVersion()
    {
        if ($this->max_version === null)
            $this->scanMigrations();

        return $this->max_version;
    }

    public function getCurrentVersion()
    {
        return $this->db_version->get('version');
    }

    public function isUpToDate()
    {
        return $this->getCurrentVersion() < $this->getLatestVersion();
    }

    protected function scanMigrations()
    {
        $glob = rtrim($this->migration_path, '/') . '/*-to-*.[sp][qh][lp]');
        $it = new GlobIterator($glob, \FilesystemIterator::NEW_CURRENT_AND_KEY);

        $regex = '/^([0-9]+)-to-([0-9]+)\.(sql|php)$/';

        $this->migrations = [];

        $this->max_version = 0;
        foreach ($it as $filename => $pathinfo)
        {
            if (!$pathinfo->isFile())
                continue;

            if (preg_match($regex, $filename, $matches) === 1)
            {
                $from_version = (int)$matches[1];
                $to_version = (int)$matches[2];
                if ($to_version > $this->max_version)
                    $this->max_version = $to_version;

                $path = $pathinfo->getPathname() . '/'.  $filename;

                $this->migrations[$to_version][$from_version] = $path;
            }
        }
    }

    public function upgradeToLatest()
    {
        return $this->upgradeTo($this->getLatestVersion());
    }

    public function uninstall()
    {
        $this->downgradeTo(0);
    }

    public function upgradeTo($version)
    {
        if (!is_int($version))
            throw new InvalidTypeException("Version is not an integer");

        $max_version = $this->getLatestVersion();

        if ($version <= 0 || $version > $max_version)
            throw new MigrationException("Module cannot be upgraded beyond the maximum version");

        $current_version = $this->getCurrentVersion();
        
        $trajectory = $this->plan($current_version, $version);

        $db = DB::get();
        for ($v = $current_version + 1; $v <= $version; ++$v)
        {
            if (!method_exists($this, "upgradeToV" . $v))
                continue;

            $func = array($this, "upgradeToV" . $version);
            $db->beginTransaction();
            try
            {
                call_user_func($func, $db);
                $db_version->setField('version', $v)->save();
                $db->commit();
            }
            catch (Exception $e)
            {
                $db->rollback();
                throw $e;
            }
        }
        return true;
    }

    protected function plan($from, $to)
    {
        $migrations = [];

        while ($from !== $to)
        {
            $best_source = null;
            $best_path = null;
            $best_dist = null;
            foreach ($this->migrations[$to] as $source => $path)
            {
                $dist = abs($source - $from);
                if ($best_dist === null || $dist < $best_dist)
                {
                    $best_dist = $dist;
                    $best_source = $source;
                    $best_path = $path;
                }
            }
            
            $from = $best_source;
            $migations[] = $path;
        }
    }

    public function downgradeTo($version)
    {
        if (!is_int($version))
            throw new InvalidTypeException("Version is not an integer");
        if ($version < 0 || $version > $this->getLatestVersion())
            throw new MigrationException("Invalid module version number");

        if (!method_exists($this, "downgradeToV" . $version))
            throw new MigrationException("Downgrade to version $version is not implemented");

        $db = DB::get();
        for ($v = $current_version - 1; $v >= $version; --$v)
        {
            if (!method_exists($this, "downgradeToV" . $v))
                continue;
            $func = array($this, "downgradeToV" . $version);

            $db->beginTransaction();
            try
            {
                call_user_func($func, $db);
                $db_version->set('version', $v)->save();
                $db->commit();
            }
            catch (Exception $e)
            {
                $db->rollback();
                throw $e;
            }
        }
        return true;
    }
}
