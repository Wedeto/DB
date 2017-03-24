<?php
/*
This is part of WASP, the Web Application Software Platform.
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

namespace WASP\DB;

use PDOException;
use DateTime;

use WASP\Util\Functions as WF;

use WASP\Auth\ACL\Entity;
use WASP\DB\Query;
use WASP\DB\Query\Builder as QB;
use WASP\DB\Schema\Column\Column;
use WASP\DB\Schema\Index;

/**
 * DAO (Data Access Object) allows for simple persistence of PHP objects to
 * a relational database - it an ORM.
 *
 * Table structure is loaded from the driver, bringing out of the box data
 * transformations such as to/from PHP DateTime objects and JSON
 * encoding/decoding.
 *
 * It provives CRUD in the form of static insert, select, update and delete functions,
 * and allows for object-level saving, loading and deleting by methods get, save
 * and remove.
 *
 * If the ACL system is active, it also supports ACL access checking.
 */
abstract class DAO
{
    /** A mapping between class identifier and full, namespaced class name */
    protected static $classes = array();

    /** A mapping between full, namespaced class name and class identifier */
    protected static $classnames = array();

    /** The database connection */
    protected static $db_connections = array();

    /** Subclasses should override this to set the name of the table */
    protected static $table = null;

    /** The value for the primary key columns */
    protected $id;
    
    /** The database record */
    protected $record;

    /** The altered fields */
    protected $changed = array();

    /** The associated ACL entity */
    protected $acl_entity = null;

    /** The database this object was retrieved from */
    protected $source_db = null;

    /**
     * Return the active connectio
     * @return WASP\DB\DB An active database connection
     */
    public static function db()
    {
        $class = static::class;
        if (isset(self::$db_connections[$class]))
            return self::$db_connections[$class];

        if (!isset(self::$db_connections['_default']))
            self::$db_connections['_default'] = DB::get();

        return self::$db_connections['_default'];
    }

    /**
     * Return the name of this table
     */
    public static function tablename()
    {
        return static::$table;
    }

    /**
     * Get the table specification
     * @param WASP\DB\DB $database The database to get the columns from. If
     *                             null, the default is used.
     */
    public static function getTable(DB $database = null)
    {
        if ($database === null)
            $database = static::db();
        $schema = $database->getSchema();

        return $schema->getTable(static::$table);
    }

    /**
     * @param WASP\DB\DB $database The database to get the columns from. If
     *                             null, the default is used.
     * @return array The set of columns associated with this table
     */
    public static function getColumns(DB $database = null)
    {
        return static::getTable($database ?: static::db());
    }

    /**
     * @param WASP\DB\DB $database The database to get the primary key from. If
     *                             null, the default is used.
     * @return array Associative array with column names as keys and their
     *               column definitions as value.
     */
    public static function getPrimaryKey(DB $database = null)
    {
        return static::getTable($database ?: static::db())->getPrimaryColumns();
    }

    /**
     * Form a condition that matches the record using the values in the provided record.
     * @param array $pkey The column names in the primary key
     * @param array $record The record to match
     */
    public static function getSelector($pkey, array $record)
    {
        if ($pkey !== null && !is_array($pkey))
            throw new \InvalidArgumentException("Invalid primary key: " . WF::str($pkey));

        // When no primary key is available, we match on all fields
        $is_primary_key = true;
        if ($pkey === null)
        {
            $table = static::getTable();
            $pkey = array();
            foreach ($record as $k => $v)
                $pkey[$k] = $table->getColumn($v);
            $is_primary_key = false;
        }

        // Make sure there are fields to match on
        if (empty($pkey))
            throw new \InvalidArgumentException("No fields to match on");

        $condition = null;
        foreach ($pkey as $pkey_column => $coldef)
        {
            if ($is_primary_key && (!array_key_exists($pkey_column, $record) || $record[$pkey_column] === null))
                throw new DAOException("Record does not have value for primary key column {$pkey_column}");
            
            $pkey_value = $coldef->beforeInsertFilter($record[$pkey_column]);
            $comparator = new Query\ComparisonOperator("=", $pkey_column, $pkey_value);
            $condition = $condition === null ? $comparator : new Query\BooleanOperator("AND", $condition, $comparator);
        }

        return $condition;
    }

    /**
     * Set the database for the current DAO type
     *
     * Databases will be linked to a specific instance. If you want to set the
     * database for all tables, you can call this directly on DAO.
     *
     * @param WASP\DB\DB $db The database connection
     */
    public static function setDB(DB $db)
    {
        $class = static::class;
        if ($class === DAO::class)
            $class = '_default';
        self::$db_connections[$class] = $db;
    }

    /**
     * Set the database this object came from
     *
     * @param WASP\DB\DB $db The database connection
     * @return WASP\DB\DAO PRovides fluent interface
     */
    public function setSourceDB(DB $db)
    {
        if ($db === null)
            throw new \InvalidArgumentException("Source database must not be null");
        $this->source_db = $db;
        return $this;
    }

    /**
     * @return WASP\DB\DB The database this object was retrieved from
     */
    public function getSourceDB()
    {
        return $this->source_db;
    }

    /**
     * Save the current record to the database.
     * @param WASP\DB\DB $database The database to save to. When you specify this,
     *                             an insert is implied. When not specified,
     *                             the source database is used to update, when
     *                             availabe, and the default database is used
     *                             to insert when no source database is
     *                             available.
     * @return WASP\DB\DAO Provides fluent interface
     */
    public function save(DB $database = null)
    {
        $insert = $database !== null;
        if ($database === null)
            $database = $this->getSourceDB();

        if ($database === null)
        {
            $insert = true;
            $database = static::db();
        }

        if (!$insert)
        {
            // Update the current record
            if (empty($this->changed))
                return $this;

            $changes = array();
            foreach ($this->changed as $field => $v)
                $changes[$field] = $this->record[$field];
            static::update($this->id, $changes, $database);
            $this->changed = array();
            $this->setSourceDB($database);
        }
        else
        {
            $this->id = static::insert($this->record, $database);
            $this->setSourceDB($database);
            $this->changed = array();

            // ACL record should be initialized now that there is an ID
            $this->initACL();
        }

        return $this;
    }

    /** 
     * Load the record from the database
     * @param mixed $id The record to load, indicating the primary key. The
     *                  type should match the primary key: scalar for unary
     *                  primary keys, associative array for combined primary
     *                  keys.
     * @param WASP\DB\DB $database The database load from. This is set as
     *                             source database. When not specified, the
     *                             default database is used.
     */
    protected function load($id, DB $database = null)
    {
        if ($database === null)
            $database = static::db();

        $pkey = static::getTable($database)->getPrimaryColumns();
        if ($pkey === null)
            throw new DAOException("A primary key is required to select a record by ID");

        // If there's a unary primary key, the value can be specified as a
        // single scalar, rather than an array
        if (count($pkey) === 1 && is_scalar($id))
            foreach ($pkey as $colname => $def)
                $id[$col] = $id;

        $condition = static::getSelector($pkey, $id);
        $rec = static::fetchSingle(QB::where($condition), $database);
        if (empty($rec))
            throw new DAOEXception("Object not found with " . WF::str($id));

        $this->assignRecord($rec, $database);
    }

    /** 
     * Assign the provided record to this object.
     * @param array $record The record obtained from the database.
     * @param WASP\DB\DB $database The database this record comes from
     * @return WASP\DB\DAO Provides fluent interface
     */
    public function assignRecord(array $record, DB $database)
    {
        if ($database === null)
            $database = static::db();

        $pkey = static::getTable($database)->getPrimaryColumns();
        if ($pkey !== null)
        {
            $this->id = array();
            foreach ($pkey as $col)
                $this->id[$col->getName()] = $record[$col->getName()];
        }
        else
            $this->id = null;

        $this->record = $record;
        $this->changed = array();
        $this->setSourceDB($database);
        $this->init();

        $columns = static::getColumns($database);
        foreach ($columns as $name => $def)
        {
            if (!array_key_exists($name, $this->record))
                continue;

            $this->record[$name] = $def->afterFetchFilter($this->record[$name]);
        }
        return $this;
    }

    /**
     * Remove the current record from the database it was retrieved from.
     */
    public function remove()
    {
        $db = $this->getSourceDB();

        if ($db === null)
            throw new DAOException("No database to remove this record from - it appears as not saved");

        $pkey = static::getTable($db)->getPrimaryColumns();
        $condition = static::getSelector($pkey, $this->id);

        static::delete(new WhereClause($condition), $db);
        $this->id = null;
        $this->record = null;
        $this->removeACL();
    }

    /**
     * This method is called after assigning new data to $this->record.
     * It can be used to initialize dependent member variables or provide additional actions.
     *
     * You should override to perform initialization after record has been loaded
     */
    protected function init()
    {}

    /**
     * Initialize a single object from a ID
     *
     * @param mixed $id The record to load, indicating the primary key. The
     *                  type should match the primary key: scalar for simple
     *                  primary keys, and array for combined primary keys.
     * @return An instance of the class this method is called on
     */
    public static function get($id, DB $database = null)
    {
        if ($database === null)
            $database = static::db();
        $pkey = static::getTable($database)->getPrimaryColumns();

        if ($pkey === null)
            throw new DAOException("Cannot fetch by ID without primary key");

        if (is_scalar($id) && count($pkey) === 1)
            foreach ($pkey as $colname => $def)
                $id = [$colname => $id];

        $condition = self::getSelector($pkey, $id);
        $record = static::fetchSingle(new Query\WhereClause($condition), $database);

        if (empty($record))
            return null;

        $obj = new static;
        $obj->assignRecord($record, $database);
        return $obj;
    }

    /**
     * Retrieve a set of records, create object from them and return the resulting list.
     * @param $args The provided arguments for the select query.
     * @return array The retrieved DAO objects
     * @seealso WASP\DB\DAO::select
     */
    public static function getAll(...$args)
    {
        $list = array();
        $db = self::getDBFromList($args) ?: static::db();
        $records = static::fetchAll($args);
        foreach ($records as $record)
        {
            $obj = new static;
            $obj->assignRecord($record, $db);
            $list[] = $obj;
        }
        return $list;
    }

    /** 
     * Find a database object in a set of arguments
     * @param array $args The list of arguments that may contain a DB object
     * @return WASP\DB\DB The database object if found, false otherwise.
     */
    protected static function getDBFromList(array $args)
    {
        foreach ($args as $arg)
        {
            if (is_array($arg))
                $arg = static::getDBFromList($arg);

            if ($arg instanceof DB)
                return $arg;
        }
        return null;
    }

    /**
     * Execute a query, retrieve the first record and return it.
     *
     * @param $args The provided arguments for the select query.
     * @return array The retrieved record
     * @seealso WASP\DB\DAO::select
     */
    protected static function fetchSingle(...$args)
    { 
        $args[] = QB::limit(1);
        $select = static::select($args);
        return $select->fetch();
    }

    /**
     * Execute a query, retrieve all records and return them in an array.
     * @param $args The provided arguments for the select query.
     * @return array The retrieved records.
     * @seealso WASP\DB\DAO::select
     */
    protected static function fetchAll(...$args)
    {
        $select = static::select($args);
        return $select->fetchAll();
    }

    /**
     * Select one or more records from the database.
     * 
     * @param $args The provided arguments should contain query parts passed to the
     *              Select constructor. These can be objects such as:
     *              FieldName, JoinClause, WhereClause, OrderClause, LimitClause,
     *              OffsetClause. A WASP\DB\DB object can also be passed in to
     *              use as a Database.
     *
     * @seealso WASP\DB\Query\Select
     */
    public static function select(...$args)
    {
        $args = WF::flatten_array($args);
        $database = null;
        foreach ($args as $idx => $val)
        {
            if ($val instanceof DB)
            {
                list($database, ) = array_splice($args, $idx, 1);
                break;
            }
        }

        if ($database === null)
            $database = static::db();

        $select = new Query\Select;
        $select->add(new Query\SourceTableClause(static::tablename()));
        $cols = static::getColumns($database);
        foreach ($cols as $name => $def)
            $select->add(new Query\GetClause($name));
        foreach ($args as $arg)
            $select->add($arg);

        $drv = $database->driver();
        return $drv->select($select);
    }

    /**
     * Update records in the database.
     *
     * @param mixed $id The values for the primary key to select the record to update
     * @param array $record The record to update. Should contain key/value pairs where
     *                      keys are fieldnames, values are the values to update them to.
     *                      Should also contain the value for the primary key.
     *                      which will be used to find the record to be updated.
     * @param WASP\DB\DB $database The DB on which to perform the operation
     */
    public static function update($id, array $record, DB $database)
    {
        $table = static::getTable($database);
        $columns = $table->getColumns();

        $pkey = $table->getPrimaryColumns();
        if ($pkey === null)
            throw new DAOException("Cannot update record without primary key");

        if (is_scalar($id) && count($pkey) === 1)
            foreach ($pkey as $colname => $def)
                $id = [$colname => $def];

        $condition = static::getSelector($pkey, $id);

        // Remove primary key from the to-be-updated fields
        foreach ($pkey as $column)
            unset($record[$column->getName()]);

        $update = new Query\Update;
        $update->add(new Query\SourceTableClause(static::tablename()));
        $update->add(new Query\WhereClause($condition));
        
        foreach ($record as $field => $value)
        {
            if (!isset($columns[$field]))
                throw new DAOException("Invalid field: " . $field);

            $coldef = $columns[$field];
            $value = $coldef->beforeInsertFilter($value);
            $update->add(new Query\UpdateField($field, $value));
        }

        $drv = $database->driver();
        return $drv->update($update);
    }

    /**
     * Insert a new record into the database.
     *
     * @param array $record The record to insert. Keys should be fieldnames,
     *                      values the values to insert.
     * @param WASP\DB\DB $database The DB on which to perform the operation
     * @return int A generated serial, if available
     */
    protected static function insert(array &$record, DB $database)
    {
        $db_record = array();
        $table = static::getTable($database);
        $columns = $table->getColumns();

        foreach ($columns as $field => $def)
        {
            if (!isset($record[$field]) && !$def->isNullable() && $def->getDefault() === null && !$def->getSerial())
                throw new DBException("Column must not be null: {$field}");
        }

        foreach ($record as $field => $value)
        {
            if (!isset($columns[$field]))
                throw new DAOException("Invalid field: " . $field);

            $coldef = $columns[$field];
            $db_record[$field] = $coldef->beforeInsertFilter($value);
        }


        $pkey = $table->getPrimaryColumns();
        if ($pkey === null)
            $pkey = [];
        $insert = new Query\Insert(static::tablename(), $db_record, $pkey);

        $drv = $database->driver();
        $drv->insert($insert, $pkey);
            
        // Store the potentially generated serial in the inserted record
        $pkey_values = $insert->getInsertId();
        foreach ($pkey as $pkey_column)
        {
            if (!$pkey_column->getSerial())
                continue;

            $colname = $pkey_column->getName();
            if (!isset($pkey_values[$colname]))
                throw new DAOException("No value generated for serial column {$colname}");

            $record[$colname] = $pkey_values[$colname];
        }

        return $pkey_values;
    }

    /**
     * Delete records from the database.
     *
     * @param WASP\DB\Query\WhereClause Specifies which records to delete. You can use
     *                                  WASP\DB\Query\Builder to create it, or provide a
     *                                  string that will be interpreted as custom SQL. A third
     *                                  option is to provide an associative array where keys
     *                                  indicate field names and values their
     *                                  values to match them with.
     * @return int The number of rows deleted
     */
    protected static function delete($where, DB $database = null)
    {
        if ($database === null)
            $database = static::db();
        $delete = new Query\Delete(static::tablename(), $where);
        $drv = $database->driver();
        return $drv->delete($delete);
    }

    /**
     * @return mixed The primary key values for this object. This is a single scalar
     *               for unary primary keys, and an array of values for
     *               compined primary keys.
     */
    public function getID()
    {
        if (empty($this->id))
            return null;

        if (count($this->id) === 1)
            return reset($this->id);

        return $this->id;
    }

    /**
     * Get the value for a field of this record.
     * @param string $field The name of the field to get
     * @return mixed The value of this field
     */
    public function getField(string $field)
    {
        if (isset($this->record[$field]))
            return $this->record[$field];
        return null;
    }

    /**
     * Set a field to a new value. The value will be validated first by
     * calling validate.
     * @param string $field The field to retrieve
     * @param mixed $value The value to set it to.
     * @return WASP\DB\DAO Provides fluent interface.
     */
    public function setField(string $field, $value)
    {
        if (isset($this->record[$field]) && $this->record[$field] === $value)
            return;

        $db = $this->getSourceDB();
        if ($db === null)
            $db = static::db();

        $table = static::getTable($db);
        $columns = $table->getColumns();
        if (!isset($columns[$field]))
            throw new DAOException("Field $field does not exist!");

        $pkey = $table->getPrimaryColumns();

        $coldef = $columns[$field];
        $coldef->validate($value);

        $correct = $this->validate($field, $value);
        if ($correct !== true)
            throw new DAOException("Field $field cannot be set to $value: {$correct}");

        $this->record[$field] = $value;
        $this->changed[$field] = true;
        return $this;
    }

    /**
     * Get the value for a property of this database record. Allows to access them transparently
     * by doing $obj->id.
     *
     * @param string $field The name of the field to get
     * @seealso DAO::getField
     */
    public function __get($field)
    {
        return $this->getField($field);
    }

    /**
     * @return array The record with all data of this DAO object that is stored
     * in the database.
     */
    public function getRecord()
    {
        return $this->record;
    }

    /**
     * Magic method __set allows transparant property access to
     * instances of the DAO.
     *
     * @param string $field The field to set
     * @param mixed $value What to set the field to
     * @seealso WASP\DB\DAO
     */
    public function __set($field, $value)
    {
        return $this->setField($field, $value);
    }
    
    /**
     * Validate a value for the field before setting it. This method is called
     * from the setField method before updating the value. You can override
     * this to add validators.
     *
     * @return bool True if the value is valid, false if not.
     */
    public function validate($field, $value)
    {
        return true;
    }

    /**
     * Override to provide a list of parent objects where this object can 
     * inherit permissions from. Used by the ACL permission system.
     */
    protected function getParents()
    {
        return array();
    }

    /**
     * Check if an action is allowed on this object. If the ACL subsystem
     * is not loaded, true will be returned.
     *
     * @param $action scalar The action to be performed
     * @param $role WASP\ACL\Role The role that wants to perform an action. 
     *                           If not specified, the current user is used.
     * @return boolean True if the action is allowed, false if it is not
     * @throws WASP\ACL\Exception When the role or the action is invalid
     */
    public function isAllowed($action, $role = null)
    {
        if ($this->acl_entity === null)
        {
            if (class_exists("WASP\\ACL\\Rule", false))
                return WASP\ACL\Rule::getDefaultPolicy();

            return true;
        }

        if ($role === null)
            $role = WASP\Request::$current_role;

        return $this->acl_entity->isAllowed($role, $action, array(get_class($this), "loadByACLID"));
    }

    /**
     * This method will load a new instance to be used in ACL inheritance
     */
    public static function loadByACLID($id)
    {
        $parts = explode("#", $id);
        if (count($parts) !== 2)
            throw new \RuntimeException("Invalid DAO ID: {$id}");

        if (!isset(self::$classes[$parts[0]]))
            throw new \RuntimeException("Invalid DAO type: {$parts[0]}");

        $classname = self::$classes[$parts[0]];
        $pkey_values = explode("-", $id);

        return call_user_func(array($classname, "get"), $pkey_values);
    }

    /**
     * Return the ACL Entity that manages permissions on this object
     *
     * @return WASP\ACL\Entity The ACL Entity that manages permissions
     */
    public function getACL()
    {
        return $this->acl_entity;
    }

    /**
     * Set up the ACL entity. This is called after the init() method,
     * so that ID and parents can be set up before calling.
     */
    protected function initACL()
    {
        if (!class_exists(Entity::class, false))
            return;

        // We cannot generate ACL's for object without a ID
        if ($this->id === null)
            return;
        
        // Generate the ACL ID
        $id = Entity::generateID($this);

        // Retrieve or obtain the appropriate ACL
        if (!(Entity::hasInstance($id)))
            $this->acl_entity = new Entity($id, $this->getParents());
        else
            $this->acl_entity = Entity::getInstance($id);
    }

    /**
     * Return the name of the object class to be used in ACL entity naming.
     */
    public static function registerClass($name)
    {
        if (isset(self::$classes[$name]))
            throw new \RuntimeException("Cannot register the same name twice");

        $cl = static::class;
        self::$classes[$name] = $cl;
        self::$classesnames[$cl] = $name;
    }
}
