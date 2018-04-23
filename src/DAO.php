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

use PDOException;
use DateTime;

use Wedeto\Util\DI\DI;
use Wedeto\Util\Functions as WF;
use Wedeto\Auth\ACL\Entity;
use Wedeto\DB\Query;
use Wedeto\DB\Query\Builder as QB;
use Wedeto\DB\Schema\Column\Column;
use Wedeto\DB\Schema\Index;
use Wedeto\DB\Exception\InvalidTypeException;
use Wedeto\DB\Exception\InvalidValueException;
use Wedeto\DB\Exception\DAOException;
use Wedeto\ACL\Rule;

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
 */
class DAO
{
    /** The name of the table */
    protected $tablename;

    /** The database this object operates on */
    protected $db;

    /** The schema this DAO operates on */
    protected $schema;

    /** The Table object in the database */
    protected $table;

    /** The class holding the models for this object */
    protected $model_class;

    /**
     * Create a new instance, linked to a Model and a DB
     *
     * @param string $classname The model class to use
     * @param string $tablename The name of the table 
     * @param DB $db The database to query. Omit to use the default instance
     */
    public function __construct(string $classname, string $tablename, DB $db = null)
    {
        if (!class_exists($classname) || !is_subclass_of($classname, Model::class))
            throw new DAOException("$classname is not a valid Model");

        $db = $db ?: DI::getInjector()->getInstance(DB::class);
        $this->db = $db;
        $this->schema = $db->getSchema();
        $this->table = $this->schema->getTable($tablename);
        $this->tablename = $tablename;
        $this->model_class = $classname;
    }
    
    /**
     * @return string The name of the table in the database
     */
    public function getTablename()
    {
        return $this->tablename;
    }

    /**
     * @return Wedeto\DB\Schema\Table the table specification
     */
    public function getTable()
    {
        return $this->table;
    }

    /**
     * @return array The set of columns associated with this table
     */
    public function getColumns()
    {
        return $this->table->getColumns();
    }

    /**
     * @return array Associative array with column names as keys and their
     *               column definitions as value.
     */
    public function getPrimaryKey()
    {
        return $this->table->getPrimaryColumns();
    }

    /**
     * Form a condition that matches the record using the values in the provided record.
     *
     * @param array $pkey The column names in the primary key
     * @param array $record The record to match
     */
    public function getSelector($pkey, array $record)
    {
        if ($pkey !== null && !is_array($pkey))
            throw new InvalidTypeException("Invalid primary key: " . WF::str($pkey));

        // When no primary key is available, we match on all fields
        $is_primary_key = true;
        if ($pkey === null)
        {
            $pkey = array();
            foreach ($record as $k => $v)
                $pkey[$k] = $this->table->getColumn($v);
            $is_primary_key = false;
        }

        // Make sure there are fields to match on
        if (empty($pkey))
            throw new InvalidTypeException("No fields to match on");

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
     * Save the current record to the database.
     *
     * @param Wedeto\DB\Model $model The model instance to save.
     *                               If the model does not have a source
     *                               database, it is inserted, otherwise
     *                               an update is executed.
     * @return Wedeto\DB\DAO Provides fluent interface
     */
    public function save(Model $model)
    {
        $database = $model->getSourceDB();
        $pkey = $model->getID();

        if ($database === null || $database !== $this->db)
            $this->insert($model);
        else
            $this->update($model);

        return $this;
    }

    /** 
     * Load the record from the database
     * @param mixed $id The record to load, indicating the primary key. The
     *                  type should match the primary key: scalar for unary
     *                  primary keys, associative array for combined primary
     *                  keys.
     */
    public function getByID($id)
    {
        $pkey = $this->getPrimaryKey();
        if ($pkey === null)
            throw new DAOException("A primary key is required to select a record by ID");

        // If there's a unary primary key, the value can be specified as a
        // single scalar, rather than an array
        if (count($pkey) === 1 && is_scalar($id))
            foreach ($pkey as $colname => $def)
                $id[$col] = $id;

        $condition = $this->getSelector($pkey, $id);
        $rec = $this->fetchSingle(QB::where($condition));
        if (empty($rec))
            throw new DAOEXception("Object not found with " . WF::str($id));

        $model = new $this->model_class;
        $model->assignRecord($rec, $this->db);
        return $model;
    }

    /**
     * Retrieve a single record based on a provided query
     * @param args The provided arguments for the select query.
     * @return Model The fetch model
     * @see Wedeto\DB\DAO::select
     */
    public function get(...$args)
    {
        $record = $this->fetchSingle($args);
        if (!$record)
            return null;
        $obj = new $this->model_class;
        $obj->assignRecord($record, $this->db);
        return $obj;
    }

    /**
     * Retrieve a set of records, create object from them and return the resulting list.
     * @param $args The provided arguments for the select query.
     * @return array The retrieved DAO objects. If the primary key is a single field, the indices correspond to the primary key
     * @see Wedeto\DB\DAO::select
     */
    public function getAll(...$args)
    {
        $pkey = $this->getPrimaryKey();

        if (count($pkey) === 1)
        {
            reset($pkey);
            $pkey_as_index = key($pkey);
        }
        else
            $pkey_as_index = false;

        $list = array();
        $records = $this->fetchAll($args);
        foreach ($records as $record)
        {
            $obj = new $this->model_class;
            $obj->assignRecord($record, $db);

            if ($pkey_as_index)
                $list[$obj->getField($pkey_as_index)] = $obj;
            else
                $list[] = $obj;
        }
        return $list;
    }

    /**
     * Execute a query, retrieve the first record and return it.
     *
     * @param $args The provided arguments for the select query.
     * @return array The retrieved record
     * @see Wedeto\DB\DAO::select
     */
    public function fetchSingle(...$args)
    { 
        $args[] = QB::limit(1);
        $select = $this->select($args);
        return $select->fetch();
    }

    /**
     * Execute a query, retrieve all records and return them in an array.
     * @param $args The provided arguments for the select query.
     * @return array The retrieved records.
     * @see Wedeto\DB\DAO::select
     */
    public function fetchAll(...$args)
    {
        $select = $this->select($args);
        return $select->fetchAll();
    }

    /**
     * Select one or more records from the database.
     * 
     * @param $args The provided arguments should contain query parts passed to the
     *              Select constructor. These can be objects such as:
     *              FieldName, JoinClause, WhereClause, OrderClause, LimitClause,
     *              OffsetClause. A Wedeto\DB\DB object can also be passed in to
     *              use as a Database.
     * @return PreparedStatement The executed select query
     *
     * @see Wedeto\DB\Query\Select
     */
    public function select(...$args)
    {
        $args = WF::flatten_array($args);

        $select = new Query\Select;
        $select->add(new Query\SourceTableClause($this->tablename));
        $cols = $this->getColumns();
        foreach ($cols as $name => $def)
            $select->add(new Query\GetClause($name));
        foreach ($args as $arg)
            $select->add($arg);

        $drv = $this->db->getDriver();
        return $drv->select($select);
    }

    /**
     * Update records in the database.
     *
     * @param mixed $id The values for the primary key to select the record to update. You can also supply
     *                  an instance of the accompanying Model class.
     * @param array $record The record to update. Should contain key/value pairs where
     *                      keys are fieldnames, values are the values to update them to.
     *                      Should also contain the value for the primary key.
     *                      which will be used to find the record to be updated.
     *                      When providing a Model as $id, this should be omitted.
     * @return int The number of updated records
     */
    public function update($id, array $record = null)
    {
        $model = is_a($id, $this->model_class) ? $id : null;
        if (null !== $model)
        {
            if ($record !== null)
                throw new DAOException("You should not pass an array of updates when supplying a Model");

            if ($id->getSourceDB() !== $this->db)
                throw new DAOException("Cannot update object - it did not originate in this database");

            $record = $id->getChanges();
            $id = $id->getID();
        }

        if ($record === null)
            throw new DAOException("No update provided");

        // Check if there's anything to update
        if (count($records) === 0)
            return 0;

        $table = $this->table;
        $columns = $table->getColumns();

        $pkey = $table->getPrimaryColumns();
        if ($pkey === null)
            throw new DAOException("Cannot update record without primary key");

        if (is_scalar($id) && count($pkey) === 1)
            foreach ($pkey as $colname => $def)
                $id = [$colname => $def];

        $condition = $this->getSelector($pkey, $id);

        // Remove primary key from the to-be-updated fields
        foreach ($pkey as $column)
            unset($record[$column->getName()]);

        $update = new Query\Update;
        $update->add(new Query\SourceTableClause($this->tablename));
        $update->add(new Query\WhereClause($condition));
        
        foreach ($record as $field => $value)
        {
            if (!isset($columns[$field]))
                throw new DAOException("Invalid field: " . $field);

            $coldef = $columns[$field];
            $value = $coldef->beforeInsertFilter($value);
            $update->add(new Query\UpdateField($field, $value));
        }

        $drv = $this->db->getDriver();
        $rows = $drv->update($update);

        if (null !== $model)
        {
            $model->markClean();
        }

        return $rows;
    }

    /**
     * Insert a new record into the database.
     *
     * @param array $record The record to insert. Keys should be fieldnames,
     *                      values the values to insert. You can also
     *                      provide a Model instance.
     * @return int A generated serial, if available
     */
    public function insert(&$model)
    {
        $is_model = is_a($model, $this->model_class);
        if (!is_array($model) && !$is_model)
            throw new InvalidTypeException("An array or a instance of {$this->model_class} should be provided");

        $record = $is_model ? $model->getRecord() : $model;
        $db_record = array();
        $table = $this->table;
        $columns = $table->getColumns();

        foreach ($columns as $field => $def)
        {
            if (!isset($record[$field]) && !$def->isNullable() && $def->getDefault() === null && !$def->getSerial())
                throw new InvalidValueException("Column must not be null: {$field}");
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
        $insert = new Query\Insert($this->tablename, $db_record, $pkey);

        $drv = $this->db->getDriver();
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

            if ($is_model)
                $model->setField($colname, (int)$pkey_values[$colname]);
            else
                $model[$colname] = (int)$pkey_values[$colname];
        }

        if ($is_model)
        {
            $model->markClean();
            $model->setSourceDB($this->db);
        }

        return $pkey_values;
    }

    /**
     * Delete records from the database.
     *
     * @param Wedeto\DB\Query\WhereClause Specifies which records to delete. You can use
     *                                  Wedeto\DB\Query\Builder to create it, or provide a
     *                                  string that will be interpreted as custom SQL. A third
     *                                  option is to provide an associative array where keys
     *                                  indicate field names and values their
     *                                  values to match them with.
     * @return int The number of rows deleted
     */
    public function delete($where)
    {
        $is_model = is_a($where, $this->model_class);
        if (!$is_model && !is_a($where, Query\WhereClause::class))
            throw new InvalidTypeException("Must provide a WhereClause or an instance of {$this->model_class} to delete");

        if ($is_model)
        {
            $database = $where->getSourceDB();
            if ($database === null || $database !== $this->db)
                throw new DAOException("Cannot remove this record - it did not originate in this database");

            $where = $this->getSelector($this->getPrimaryKey(), $where);
        }

        $delete = new Query\Delete($this->tablename, $where);
        $drv = $this->db->getDriver();

        $rows = $drv->delete($delete);
        if ($is_model)
            $where->destruct();

        return $rows;
    }
}
