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
use Wedeto\Util\DI\DI;
use Wedeto\DB\DB;
use Wedeto\DB\Exception\MigrationException;
use Wedeto\DB\Exception\InvalidTypeException;
use Wedeto\DB\Exception\NoMigrationTableException;
use Wedeto\DB\Exception\TableNotExistsException;
use Wedeto\DB\Model\DBVersion;
use Wedeto\DB\Query\Builder as QB;

class NullVersion extends DBVersion
{}

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

    /** The database to migate */
    protected $db = null;

    /** The DAO to manipulate the database */
    protected $dao = null;

    /**
     * Create the migration module
     *
     * @param string $module The name of the module
     * @param string $path The path where the migration files are located
     * @param DB $db The database to migrate
     */
    public function __construct(string $module, $path, DB $db)
    {
        $this->getLogger();
        $this->module = $module;
        $this->migration_path = $path;
        $this->db = $db;
    }

    /**
     * Load the current version from the database
     */
    private function loadVersion()
    {
        try
        {
            $this->dao = $this->db->getDAO(DBVersion::class);
            $this->db_version = 
                $this->dao->get(
                    QB::where(["module" => $this->module]), 
                    QB::order(['migration_date' => 'DESC'])
                ) 
                ?: new NullVersion;
        }
        catch (TableNotExistsException $e)
        {
            if ($this->module !== "wedeto.db")
            {
                throw new NoMigrationTableException(); 
            }
            $this->db_version = new NullVersion;
        }
    }

    /**
     * @return string the name of this module
     */
    public function getModule()
    {
        return $this->module;
    }

    /**
     * @return DB The database this module is linked to
     */
    public function getDB()
    {
        return $this->db;
    }

    /**
     * @return string The path to the migration files
     */
    public function getPath()
    {
        return $this->migration_path;
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
        if (null === $this->db_version)
            $this->loadVersion();

        return $this->db_version instanceof NullVersion ? 0 : (int)$this->db_version->to_version;
    }

    /**
     * Check if the module is up to date with the newest available version
     * @return bool True if the module is up to date, false if it isn't
     */
    public function isUpToDate()
    {
        return $this->getCurrentVersion() === $this->getLatestVersion();
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
        $it = new \GlobIterator($glob, \FilesystemIterator::NEW_CURRENT_AND_KEY);

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

                $path = $pathinfo->getPathname();

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

        $db = $this->db;

        foreach ($trajectory as $migration)
        {
            $migration['module'] = $this->module;
            $filename = $migration['file'];
            $ext = strtolower(substr($filename, -4));

            $db->beginTransaction();
            try
            {
                static::$logger->info("Migrating module {module} from {from} to {to} using file {file}", $migration);

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

                // Clear the database schema cache
                $db->clearCache();
                $this->dao = $this->db->getDAO(DBVersion::class);

                $version = new DBVersion;
                $version->module = $this->module;
                $version->from_version = (int)$migration['from'];
                $version->to_version = (int)$migration['to'];
                $version->migration_date = new \DateTime();
                $version->filename = $filename;
                $version->md5sum = md5(file_get_contents($filename));

                $this->dao->save($version);
                static::$logger->info("Succesfully migrated module {module} from {from} to {to} using file {file}", $migration);
                $db->commit();
            }
            catch (\Exception $e)
            {
                // Upgrade failed, roll back to previous state
                static::$logger->error("Migration of module {module} from {from} to {to} using file {file}", $migration);
                static::$logger->error("Exception: {0}", [$e]);
                //\Wedeto\Util\Functions::debug($e);
                $db->rollback();
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
    protected function plan(int $from, int $to)
    {
        $migrations = [];
        
        if ($from === $to)
            return $migrations;

        $is_downgrade = $to < $from;
        $reachable = $this->migrations[$from] ?? [];

        // For downgrades, we want the lowest reachable target that is above or equal to the final target
        // For upgrades, we want the highest reachable target that is below or equal to the final target
        // To always use the first encountered, the array needs to be reversed for upgrades, as it's sorted by key
        if (!$is_downgrade)
            $reachable = array_reverse($reachable, true);

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
            throw new MigrationException("No migration path from version $from to $to for module {$this->module}");

        $last = end($migrations);
        if ($last['to'] !== $to)
        {
            $rest = $this->plan($last['to'], $to);
            foreach ($rest as $migration)
                $migrations[] = $migration;
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
