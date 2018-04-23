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

use Wedeto\Util\DI\InjectionTrait;
use Wedeto\Util\DI\DI;
use Wedeto\Util\DI\Injector;
use Wedeto\Util\Dictionary;
use Wedeto\Util\Validation\Type;
use Wedeto\Util\LoggerAwareStaticTrait;
use Wedeto\Util\Configuration;
use Wedeto\DB\Schema\Schema;
use Wedeto\DB\Query\Query;
use Wedeto\DB\Query\Parameters;

use Wedeto\DB\Driver\Driver;

use Wedeto\DB\Exception\ConfigurationException;
use Wedeto\DB\Exception\DriverException;
use Wedeto\DB\Exception\IOException;
use Wedeto\DB\Exception\DAOException;


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
    use InjectionTrait;

    const WDI_REUSABLE = true;

    protected $pdo;
    protected $dsn;
    protected $driver;
    protected $config;
    protected $schema;
    protected $dao = [];

    /**
     * Create a new DB object for a specific configuration set.
     *
     * @param Wedeto\DB\DBConfig $config The configuration for this connection
     */
    public function __construct(DBConfig $config)
    {
        $this->getLogger();
        $this->config = $config;
        if ($this->config->dget('lazy', true) == false)
            $this->connect();
    }

    /**
     * Find a proper driver based on the 'type' parameter in the configuration.
     *
     * @param string $type The type / driver name.
     * @return Wedeto\DB\Driver\Driver A initialized driver object
     * @throws Wedeto\DB\Exception\DriverException When no driver could be found
     */
    protected function setupDriver(string $type)
    {
        // A full namespaced class name may be provided
        $driver = null;
        $driver_class = null;
        if (is_a($type, Driver::class, true))
        {
            $driver_class = $type;
            $driver = new $type($this);
        }
        else
        {
            // Or the name of one of the included drivers
            $driver_class = "Wedeto\\DB\\Driver\\" . $type;
            if (is_a($driver_class, Driver::class, true))
            {
                $driver = new $driver_class($this);
            }
        }

        if ($driver === null)
            throw new DriverException("No driver available for database type $type");

        $actual_class = get_class($driver);
        if (strcmp($driver_class, $actual_class) != 0)
        {
            // Warn if the case doesn't match the actual classname
            self::$logger->warning(
                "WARNING: Configurated class {} does not match actual "
                    . "classname: {}. This may cause issues with "
                    . "autoloading.",
                [$driver_class, $actual_class]
            );
        }
        return $driver;
    }

    /**
     * Connect to the database: actually initialize the PDO and connect it.
     *
     * @throws PDOException If the connection fails
     */
    private function connect()
    {
        $username = $this->config->get('username');
        $password = $this->config->get('password');
        $host = $this->config->get('hostname');
        $database = $this->config->get('database');
        $schema = $this->config->get('schema');
        $this->dsn = $this->config->get('dsn');
        if (!$this->config->has('type', Type::STRING))
            throw new ConfigurationException("Please specify the database type in the configuration section [sql]");

        $type = $this->config->getString('type');

        // Set up the driver
        $this->driver = $this->setupDriver($type);
        $this->driver->setTablePrefix($this->config->dget('prefix', ''));
            
        if (!$this->dsn)
        {
            $this->dsn = $this->driver->generateDSN($this->config->toArray());
            self::$logger->info("Generated DSN: {0}", [$this->dsn]);
            $this->config->set('dsn', $this->dsn);
        }
            
        // Create the PDO and connect it to the database, setting default options
        //$pdo = new PDO($this->dsn, $username, $password);
        $pdo = DI::getInjector()
            ->newInstance(
                PDO::class,
                ['dsn' => $this->dsn, 'username' => $username, 'passwd' => $password]
            )
        ;
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
        $this->pdo = $pdo;
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
     * @return Wedeto\DB\Schema\Schema the database schema
     */
    public function getSchema()
    {
        if ($this->schema === null)
        {
            $drv = $this->getDriver();
            $host = $drv->getHostname();
            $database = $drv->getDatabaseName();
            $schema = $drv->getSchemaName();
            $type = $this->config->get('type');
            $schema_name = sprintf("%s_%s_%s_%s", $type, $host, $database, $schema);

            $this->schema =  DI::getInjector()->newInstance(
                Schema::class, 
                ['schema_name' => $schema_name, 'use_cache' => true]
            );
            $this->schema->setDBDriver($this->driver);
        }

        return $this->schema;
    }

    /**
     * Get a DAO for a model. When none is available, a new one will be instantiated.
     *
     * @param string $class The name of the Model class
     * @return DAO An instantiated DAO for the class.
     */
    public function getDAO(string $class)
    {
        if (!isset($this->dao[$class]))
        {
            if (!is_subclass_of($class, Model::class))
                throw new DAOException("$class is not a valid Model");

            $tablename = $class::getTablename();
            $dao = DI::getInjector()->newInstance(
                DAO::class,
                ['classname' => $class, 'tablename' => $tablename, 'db' => $this]
            );
            $this->dao[$class] = $dao;
        }

        return $this->dao[$class];
    }

    /**
     * Flush all cached DAOs and schema - useful after a schema alteration
     * @return DB Provides fluent interface
     */
    public function clearCache()
    {
        if (!empty($this->schema))
        {
            echo "CLEARING CACHE!\n";
            $this->schema->clearCache();
        }

        $this->dao = [];
        $this->schema = null;
        return $this;
    }

    /** 
     * Set the DAO for a model manually.
     * @param string $class The name of the Model class
     * @param DAO $dao The DAO to set. Omit to reset - a new one will be instantiated on request
     * @return DB Provides fluent interface
     */
    public function setDAO(string $class, DAO $dao = null)
    {
        if (!is_subclass_of($class, Model::class))
            throw new DAOException("$class is not a valid Model");

        $this->dao[$class] = $dao;
        return $this;
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

    /** 
     * @return array A clean representation of this object
     */
    public function __debuginfo()
    {
        $drv = get_class($this->getDriver());
        return array('dsn' => $this->dsn, 'driver' => $drv);
    }

    /**
     * Prepare a query and return the PDOStatement
     *
     * @param Wedeto\DB\Query\Query The query to prepare
     * @return PDOStatement The prepared statement
     */
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
     *
     * Occurences of %PREFIX% will be replaced with the configured table
     * prefix. YOU ARE STRONGLY ADVISED TO ENCLOSE ALL TABLE REFERENCES
     * WITH IDENTIFIER QUOTES. For MySQL use backticks, for PostgreSQL
     * use double quotes. Failing to do so may introduce problems when
     * a prefix is used that requires quoting, for example when it
     * includes hyphens.
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
     *                    thus leading to the last statement not being executed.
     * Not adhering to 3) will result in lines being skipped.
     *
     * Statements are concatenated and fed to the SQL driver, so any other
     * language construct understood by the database is allowed.
     *
     * @param string $filename The SQL file to load and execute
     * @return $this Provides fluent interface
     * @throws PDOException When the SQL is faulty
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
                $statement = str_replace('%PREFIX%', $prefix, $trimmed);
                $this->exec($statement);
                $statement = '';
            }
        }
        fclose($fh);
        return $this;
    }

    /**
     * Start a transaction
     *
     * @return bool True on success, false on failure
     */
    public function beginTransaction()
    {
        return $this->getPDO()->beginTransaction();
    }

    /**
     * Commit a transaction 
     *
     * @return bool True on success, false on failure
     */
    public function commit()
    {
        return $this->getPDO()->commit();
    }

    /**
     * Rollback a transaction 
     *
     * @return bool True on success, false on failure
     */
    public function rollBack()
    {
        return $this->getPDO()->rollback();
    }
}
