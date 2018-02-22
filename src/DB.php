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

use PDO;

use Wedeto\Util\Dictionary;
use Wedeto\Util\Type;
use Wedeto\Util\LoggerAwareStaticTrait;
use Wedeto\DB\Schema\Schema;
use Wedeto\DB\Query\Query;
use Wedeto\DB\Query\Parameters;

use Wedeto\DB\Exception\ConfigurationException;
use Wedeot\DB\Exception\DriverException;
use Wedeot\DB\Exception\IOException;

/**
 * The DB class wraps a PDO allowing for lazy connecting.  The configuration is
 * passed at initialization time, but the connection is not established until
 * the first method call on the database. This behavior can be altered by
 * setting [sql][lazy] = false in the configuration, in which case the PDO will
 * be connected directly on construction.
 */
class DB
{
    use LoggerAwareStaticTrait;

    protected static $default_db = null;
    protected $pdo;
    protected $dsn;
    protected $driver;
    protected $config;
    protected $schema;

    /**
     * Create a new DB object for a specific configuration set.
     *
     * @param Wedeto\Util\Dictionary $config The configuration for this connection
     */
    private function __construct(Dictionary $config)
    {
        $this->getLogger();
        $this->config = $config;
        if ($this->config->dget('sql', 'lazy', true) == false)
            $this->connect();
    }

    /**
     * Find a proper driver based on the 'type' parameter in the configuration.
     * @param string $type The type / driver name.
     * @return Wedeto\DB\Driver\Driver A initialized driver object
     * @throws Wedeto\DB\Exception\DriverException When no driver could be found
     */
    protected function setupDriver(string $type)
    {
        $driver = "Wedeto\\DB\\Driver\\" . $type;
        if (class_exists($driver))
            return new $driver($this);

        // Attempt a case insensitive match
        $driver = null;
        $type = strtolower($type);

        // Load all drivers in the Driver directory
        $path = realpath(__FILE__);
        $drivers = glob($path . '/Driver/*.php');

        // Check if any of the names match the type
        foreach ($drivers as $filename)
        {
            $filename = basename($filename, '.php');
            if (strtolower($filename) === strtolower($type))
            {
                $driver = "Wedeto\\DB\\Driver\\" . $filename;
                break;
            }
        }

        // Check if a driver was found
        if (empty($driver) || !class_exists($driver))
            throw new DriverException("No driver available for database type $type");

        return new $driver($this);
    }

    /**
     * Connect to the database: actually initialize the PDO and connect it.
     * @throws PDOException If the connection fails
     */
    private function connect()
    {
        if ($this->pdo !== null)
            return true;

        $username = $this->config->get('sql', 'username');
        $password = $this->config->get('sql', 'password');
        $host = $this->config->get('sql', 'hostname');
        $database = $this->config->get('sql', 'database');
        $schema = $this->config->get('sql', 'schema');
        $this->dsn = $this->config->get('sql', 'dsn');
        if (!$this->config->has('sql', 'type', Type::STRING))
            throw new ConfgurationException("Please specify the database type in the configuration section [sql]");

        $type = $this->config->getString('sql', 'type');

        // Set up the driver
        $this->driver = $this->setupDriver($type);
        $this->driver->setTablePrefix($this->config->dget('sql', 'prefix', ''));
            
        if (!$this->dsn)
        {
            $this->dsn = $this->driver->generateDSN($this->config->getArray('sql'));
            self::$logger->info("Generated DSN: {0}", [$this->dsn]);
            $this->config->set('sql', 'dsn', $this->dsn);
        }
            
        // Create the PDO and connect it to the database, setting default options
        $pdo = new PDO($this->dsn, $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
        $this->pdo = $pdo;
    }

    /**
     * Get a DB object for the provided configuration. If the configuration is omitted,
     * the default DB will be returned if available. The first database created is automatically
     * set as default DB. You can change this using DB#setDefaultDB.
     *
     * @param Wedeto\Util\Dictionary $config The configuration used to connect to the database
     * @return Wedeto\DB\DB The initalized DB object
     */
    public static function get(Dictionary $config = null)
    {
        if ($config === null)
            return self::getDefault();

        if (!$config->has('sql', 'pdo'))
        {
            $db = new DB($config);
            $config->set('sql', 'pdo', $db);
        }
        else
            $db = $config->get('sql', 'pdo');

        if (empty(self::$default_db))
            self::$default_db = $db;

        return $db;
    }

    /**
     * Update the default database 
     *
     * @param DB $database The database to set as default
     */
    public static function setDefault(DB $database)
    {
        self::$default_db = $database;
    }

    /** 
     * @return DB The default database
     */
    public static function getDefault()
    {
        if (empty(self::$default_db))
            throw new ConfigurationException("No database connection available");

        return self::$default_db;
    }

    /**
     * @return Wedeto\DB\Driver\Driver The driver associated with this database connection.
     */
    public function getDriver()
    {
        if ($this->driver === null)
            $this->connect();

        return $this->driver;
    }
    
    /**
     * @return PDO The PDO object of this connection. Can be used to extract the unwrapped PDO, should the need arise.
     */
    public function getPDO()
    {
        if ($this->pdo === null)
            $this->connect();

        return $this->pdo;
    }

    /**
     * Get the database schema
     */
    public function getSchema()
    {
        if ($this->schema === null)
        {
            $drv = $this->getDriver();
            $database = $drv->getDatabaseName();
            $schema = $drv->getSchemaName();
            $type = $this->config->get('sql', 'type');
            $schema_name = sprintf("%s_%s_%s", $type, $database, $schema);

            $this->schema = new Schema($schema_name, true);
            $this->schema->setDBDriver($this->driver);
        }

        return $this->schema;
    }

    /**
     * Wrap methods of the PDO. This is implemented this way
     * to allow lazy connecting to the database - the object
     * is always initialized but only connected once requested.
     */
    public function __call($func, $args)
    {
        if ($this->pdo === null)
            $this->connect();

        if ($func === "exec")
            self::$logger->info("Executing query: {0}", [$args[0]]);
        elseif ($func === "prepare")
            self::$logger->info("Preparing query: {0}", [$args[0]]);
            
        if (!method_exists($this->pdo, $func))
            throw new \RuntimeException("Function $func does not exist");

        return call_user_func_array(array($this->pdo, $func), $args);
    }

    public function __debuginfo()
    {
        $drv = get_class($this->getDriver());
        return array('dsn' => $this->dsn, 'driver' => $drv);
    }

    public function prepareQuery(Query $query)
    {
        $parameters = new Parameters($this->driver);

        if ($this->pdo === null)
            $this->connect();

        $sql = $this->driver->toSQL($parameters, $query);

        $statement = $this->prepare($sql);
        $parameters->bindParameters($statement);
        return $statement;
    }

    /**
     * Execute a SQL file in the database.
     *
     * This will scan the file for statements, skipping comment-only lines.
     * Occurences of %PREFIX% will be replaced with the configured table
     * prefix.
     *
     * There are a two basic restrictions on the format:
     *
     * 1) each statement should be terminated with a semi-colon
     * 2) each semi-colon should be at the end of a line, not followed
     *    by a comment.
     * 3) Any line where the first two non-white space characters are --
     *    is treated as a comment. Regardless of quotes. Therefore,
     *    the use of -- should be avoided except for comments.
     *
     * Not adhering to 1) will result in the last statement not being executed.
     * Not adhering to 2) will result in the semi-colon not being detected,
     * thus leading to the last statement no being executed.
     * Not adhering to 3) will result in lines being skipped.
     *
     * Statements are concatenated and fed to the SQL driver, so any other
     * language construct understood by the database is allowed.
     */
    public function executeSQL(string $filename)
    {
        $fh = @fopen($filename, "r");
        if ($fh === false)
            throw new IOException("Unable to open file '$filename'");

        $prefix = $this->driver->getTablePrefix();
        
        $statement = '';
        while (!feof($fh))
        {
            $line = fgets($fh);
            $trimmed = trim($line);

            // Skip comments
            if (substr($trimmed, 0, 2) === '--')
                continue;

            if ($line)
                $statement .= "\n" . $line;

            if (substr($trimmed, -1) === ';')
            {
                $statement = str_replace('%PREFIX%', $prefix);
                $this->exec($statement);
                $statement = '';
            }
        }
        fclose($fh);
    }

    /**
     * Start a transaction
     */
    public function beginTransaction()
    {
        $this->getPDO()->beginTransaction();
    }

    /**
     * Commit a transaction 
     */
    public function commit()
    {
        return $this->getPDO()->rollback();
    }

    /**
     * Rollback a transaction 
     */
    public function rollback()
    {
        return $this->getPDO()->rollback();
    }
}
