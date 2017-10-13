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

namespace Wedeto\DB\Migrate;

use Wedeto\Util\LoggerAwareStaticTrait;
use Wedeto\DB\DB;
use Wedeto\DB\Exception\MigrationException;
use Wedeto\DB\Exception\InvalidTypeException;
use Wedeto\DB\Model\DBVersion;

/**
 * A migration module that manages the migration for one specific module.
 * It scans the available migration files and executes the migrations
 */
class Module
{
    use LoggerAwareStaticTrait;

    /** The highest version number for this database module */
    protected $max_version = null;
    
    /** The DBVersion object that stores the current version */
    protected $db_version = null;

    /** The name of this migration module */
    protected $module = null;

    /** The path where the migration files for this module are located */
    protected $migration_path = null;

    /** The available migrations for this module */
    protected $migrations = [];

    /**
     * Create the migration module
     *
     * @param string $module The name of the module
     * @param string $path The path where the migration files are located
     * @param Repository The repository where migration modules are referenced
     */
    public function __construct(string $module, $path, Repository $repository)
    {
        $this->getLogger();
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

    /**
     * @return string the name of this module
     */
    public function getModule()
    {
        return $this->module;
    }

    /**
     * @return int The highest database version number for this module
     */
    public function getLatestVersion()
    {
        if ($this->max_version === null)
            $this->scanMigrations();

        return $this->max_version;
    }

    /**
     * @return int The current database version of the module
     */
    public function getCurrentVersion()
    {
        return $this->db_version->get('version');
    }

    /**
     * Check if the module is up to date with the newest available version
     * @return bool True if the module is up to date, false if it isn't
     */
    public function isUpToDate()
    {
        return $this->getCurrentVersion() < $this->getLatestVersion();
    }

    /**
     * Scan the path to find all available migrations. File should be named
     * according to the pattern:
     * 
     * SOURCE-to-TARGET.(php|sql)
     *
     * Migration files should be SQL for simple structure changes that can be
     * applied automatically. In cases where data needs to migrate from one
     * or several columns/tables to another, a PHP file can be used instead.
     *
     * The PHP file will be included, with a variable called $db available that
     * should be used to perform the migrations.
     *
     * NOTE: Migrations should always happen in SQL, not using objects, as
     * these objects may change later on. The DB\Schema utilities can be used
     * to generate these queries.
     */
    protected function scanMigrations()
    {
        $glob = rtrim($this->migration_path, '/') . '/*-to-*.[sp][qh][lp]';
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

                $this->migrations[$from_version][$to_version] = $path;
            }
        }

        // Sort the migrations ascending on their target versions per source version
        foreach ($this->migrations as $from => &$to)
            ksort($to);

        // Sort the migrations on their source version
        ksort($this->migrations);
    }

    /**
     * Upgrade to the most recent version.
     * @see migrateTo
     */
    public function upgradeToLatest()
    {
        $this->migrateTo($this->getLatestVersion());
    }

    /**
     * Uninstall the module by migrating to version 0
     */
    public function uninstall()
    {
        $this->migrateTo(0);
    }

    /**
     * Perform a migration from the current version to the target version
     *
     * @param int $version The target version
     * @throws MigrationException Whenever no migration path can be found to the target version
     * @throws DBException When something goes wrong during the migration
     */
    public function migrateTo(int $target_version)
    {
        $current_version = $this->getCurrentVersion();
        $trajectory = $this->plan($current_version, $target_version);

        $db = DB::get();

        foreach ($trajectory as $migration)
        {
            $migration['module'] = $this->module;
            $filename = $migration['file'];
            $ext = strtolower(substr($filename, -4));

            $db->beginTransaction();
            try
            {
                $this->logger->info("Migrating module {module} from {from} to {to} using file {file}", $migration);

                if ($ext === ".php")
                {
                    executePHP($db, $filename);
                }
                elseif ($ext === ".sql")
                {
                    $db->executeSQL($filename);
                }

                // If no exceptions were thrown, we're going to assume that the
                // upgrade succeeded, so update the version number in the
                // database and commit the changes.
                $this->db_version->set('version', $migration['to']);
                $this->db_version->save();
                $this->logger->info("Succesfully migrated module {module} from {from} to {to} using file {file}", $migration);
                $db->commit();
            }
            catch (Exception $e)
            {
                // Upgrade failed, roll back to previous state
                $db->rollback();
                $this->logger->error("Migration of module {module} from {from} to {to} using file {file}", $migration);
                $this->logger->error("Exception: {0}", [$e]);
                throw $e;
            }
        }
    }

    /**
     * Plan a path through available migrations to reach a specified version
     * from another version. This is always done in one direction,
     * opportunistically.  This means that when downgrading, no intermediate
     * upgrades are performed, even if they may result in shorter path.
     *
     * Whenever a more than one step is available, the larger one is selected.
     *
     * Potentially, this could result in sinks with no way out. Design your
     * upgrade trajectories with this in mind.
     *
     * @param $from The source version to start planning from
     * @param $to The target version to plan towards
     * @return array The list of migrations to execute. When $from and $to are equal,
     *               an empty array is returned.
     * @throws MigrationException When no migration path can be found
     */
    protected function plan($from, $to)
    {
        $migrations = [];
        
        if ($from === $to)
            return $migrations;

        $is_downgrade = $to < $from;
        $reachable = $this->migrations[$from];

        // For downgrades, we want the lowest reachable target that is above or equal to the final target
        // For upgrades, we want the highest reachable target that is below or equal to the final target
        // To always use the first encountered, the array needs to be reversed for upgrades, as it's sorted by key
        if (!$is_downgrade)
            $reachable = array_reverse($reachable);

        // Traverse the reachable migrations
        foreach ($reachable as $direct_to => $path)
        {
            if (
                ($direct_to === $to)                || // Bingo
                ($is_downgrade && $direct_to > $to) || // Proper downgrade
                (!$is_downgrade && $direct_to < $to)   // Proper upgrade
            )
            {
                // In the right direction, so add to the path
                $migrations[] = ['from' => $from, 'to' => $direct_to, 'file' => $path];
                break;
            }
        }

        if (count($migrations) === 0)
            throw new MigrationException("No migation path from version $from to $to for module {$this->module}");

        $last = end($migrations);
        if ($last['to'] !== $to)
        {
            $rest = $this->plan($last['to'], $to);
            foreach ($rest as $migration)
                $migrations[] = $rest;
        }

        return $migrations;
    }
}

/**
 * Load the migration file in a private scope
 * 
 * @param DB $db The database to perform migration on
 * @param string $filename The file to execute
 */
function executePHP(DB $db, $filename)
{
    require($filename);
}
