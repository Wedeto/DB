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

use WASP\Auth\ACL\Entity;
use WASP\DB\Query;
use WASP\DB\Query\Builder as QB;
use WASP\DB\Schema\Column\Column;

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

    /** Override to set the name of the ID field */
    protected static $idfield = "id";

    /** Subclasses should override this to set the name of the table */
    protected static $table = null;

    /** The ID value */
    protected $id;
    
    /** The database record */
    protected $record;

    /** The altered fields */
    protected $changed;

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
        $table = static::getTable($database);
        return $table->getColumns();
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
        if ($this->source_db === null)
            $this->setSourceDB(static::db());

        return $this->source_db;
    }

    /**
     * Save the current record to the database.
     * @return WASP\DB\DAO Provides fluent interface
     */
    public function save(DB $database = null)
    {
        if ($database === null)
            $database = $this->getSourceDB();

        $idf = static::$idfield;
        if (isset($this->record[$idf]))
        {
            // Update the current record
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

            // ACL record should be initialized now that there is an ID
            $this->initACL();
        }

        return $this;
    }

    /** 
     * Load the record from the database
     */
    protected function load($id, DB $database = null)
    {
        if ($database === null)
            $database = static::db();
        $idf = static::$idfield;
        $rec = static::fetchSingle(QB::where(array($idf => $id)), $database);
        if (empty($rec))
            throw new DAOEXception("Object not found with $id");

        $this->assignRecord($rec, $database);
    }

    /** 
     * Assign the provided record to this object.
     * @param array $record The record obtained from the database.
     * @param WASP\DB\DB $database The database this record comes from
     * @return WASP\DB\DAO Provides fluent interface
     */
    public function assignRecord(array $record, DB $database = null)
    {
        if ($database === null)
            $database = static::db();

        $this->id = isset($record[static::$idfield]) ? $record[static::$idfield] : null;
        $this->record = $record;
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
     * Remove the current record from the database
     */
    public function remove()
    {
        $idf = static::$idfield;
        if ($this->id === null)
            throw new DAOException("Object does not have a ID");

        static::delete(array($idf => $this->id));
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
     * Initialize a single object from a database record or an ID.
     *
     * @param array|int $id The entry to initialize. If this is an integer, it
     *                      is used to fetch a record with that ID from the 
     *                      database. If it is an array, it is used as a
     *                      database record directly.
     * @return An instance of the class this method is called on
     */
    public static function get($id, DB $database = null)
    {
        if ($database === null)
            $database = static::db();

        $idf = static::$idfield;
        if (!\WASP\is_int_val($id))
        {
            if (!is_array($id) || empty($id[$idf]))
                throw new DAOException("Cannot initialize object from " . \WASP\str($id));
            $record = $id;
            $id = $record[$idf];
        }
        else
        {
            $record = static::fetchSingle(array($idf => $id), $database);
            if (empty($record))
                return null;
        }

        $cl = static::class;
        $obj = new $cl;
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
        $records = static::fetchAll($args);
        foreach ($records as $record)
            $list[] = static::get($record);
        return $list;
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
        $args = \WASP\flatten_array($args);
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
     * @param array $record The record to update. Should contain key/value pairs where
     *                      keys are fieldnames, values are the values to update them to.
     *                      Should also contain the ID field specified by static::$idfield,
     *                      which will be used to find the record to be updated.
     * @param WASP\DB\DB $database The DB on which to perform the operation
     */
    public static function update($id, array $record, DB $database = null)
    {
        $idf = static::$idfield;
        if ($database === null)
            $database = static::db();

        $update = new Query\Update;
        $update->add(new Query\SourceTableClause(static::tablename()));
        $update->add(new Query\WhereClause([static::$idfield => $id]));
        
        $columns = static::getColumns($database);
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
     * @return int The new row ID
     */
    protected static function insert(array &$record, DB $database = null)
    {
        if ($database === null)
            $database = static::db();
        $db_record = array();
        $columns = static::getColumns($database);
        foreach ($record as $field => $value)
        {
            if (!isset($columns[$field]))
                throw new DAOException("Invalid field: " . $field);

            $coldef = $columns[$field];
            $db_record[$field] = $coldef->beforeInsertFilter($value);
        }

        $insert = new Query\Insert(static::tablename(), $db_record, static::$idfield);

        $drv = $database->driver();
        $id = $drv->insert($insert);
        $record[static::$idfield] = $id;
        return $id;
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
     * @return int The unique ID of this DAO.
     */
    public function getID()
    {
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

        $columns = static::getColumns($this->getSourceDB());
        if (!isset($columns[$field]))
            throw new DAOException("Field $field does not exist!");

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
        $id = (int)$parts[1];

        return new $classname($id);
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

        $cl = get_called_class();
        self::$classes[$name] = $cl;
        self::$classesnames[$cl] = $name;
    }
}

// @codeCoverageIgnoreStart
\WASP\Functions::load();
// @codeCoverageIgnoreEnd
