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

use Wedeto\DB\Exception\InvalidValueException;

/**
 * A model represents a table in the database. It is accompanied by a 
 * DAO that reads and writes to the database. You can set one per class,
 * using setDAO(). If you don't, one will be generated for you when needed.
 *
 * Table structure is loaded from the driver, bringing out of the box data
 * transformations such as to/from PHP DateTime objects and JSON
 * encoding/decoding.
 */
abstract class Model
{
    /** Subclasses should override this to set the name of the table */
    protected static $_table = null;

    /** The value for the primary key columns */
    protected $_id;
    
    /** The database record */
    protected $_record;

    /** The altered fields */
    protected $_changed = array();

    /** The database this object was retrieved from */
    protected $_source_db = null;

    /** The list of DB -> DAO mappings */
    protected static $_dao = [];

    /**
     * Return the name of this table
     */
    public static function tablename()
    {
        return static::$_table;
    }
    
    /**
     * Set the DAO to save / retrieve objects
     *
     * @param DAO $dao The DAO instance
     */
    public static function setDAO(DAO $dao, $db = null)
    {
        $db = $db ?: DB::get();
        $hash = spl_object_hash($db);
        static::$_dao[$hash] = $dao;
    }

    /**
     * @param DB $db The DB for which to obtain the DAO. When omitted, the default DB is used.
     * @return DAO The DAO managing insertion / extraction of this object
     */
    public static function getDAO(DB $db = null)
    {
        $db = $db ?: DB::get();
        $hash = spl_object_hash($db);

        if (isset(static::$_dao[$hash]))
        {
            static::$_dao[$hash] = new DAO(static::class, static::$_table, $db);
        }
        
        return static::$_dao[$hash];
    }

    /**
     * Get a suitable database. Either the provided database argument,
     * or when null, the source database. If source database is also null,
     * the default database is returned.
     * @return Wedeto\DB\DB A suitable database
     */
    public function getDB($db)
    {
        if (null !== $db && !($db instanceof DB))
            throw new InvalidTypeException("Invalid database");
        return $db ?: $this->_source_db ?: DB::get();
    }

    /**
     * Save the record to the database
     * @return Wedeto\DB\Model the saved object
     */
    public function save($db = null)
    {
        $this->getDAO($this->getDB($db))->save($this);
        return $this;
    }

    /**
     * Remove the current record from the database it was retrieved from.
     */
    public function remove($db = null)
    {
        $this->getDAO($this->getDB($db))->remove($this);
        return $this;
    }

    /**
     * Set the database this object came from
     *
     * @param Wedeto\DB\DB $db The database connection
     * @return Wedeto\DB\DAO Provides fluent interface
     */
    public function setSourceDB(DB $db)
    {
        if ($db === null)
            throw new \InvalidArgumentException("Source database must not be null");
        $this->_source_db = $db;
        return $this;
    }

    /**
     * @return Wedeto\DB\DB The database this object was retrieved from
     */
    public function getSourceDB()
    {
        return $this->_source_db;
    }

    /** 
     * Assign the provided record to this object.
     * @param array $record The record obtained from the database.
     * @param Wedeto\DB\DB $database The database this record comes from
     * @return Wedeto\DB\DAO Provides fluent interface
     */
    public function assignRecord(array $record, DB $database)
    {
        $dao = $this->getDAO();
        $table = $dao->getTable($database);
        $pkey = $table->getPrimaryColumns();
        if ($pkey !== null)
        {
            $this->_id = array();
            foreach ($pkey as $col)
                $this->_id[$col->getName()] = $record[$col->getName()];
        }
        else
            $this->_id = null;

        $this->_record = $record;
        $this->_changed = array();
        $this->setSourceDB($database);
        $this->init();

        $columns = $table->getColumns($database);
        foreach ($columns as $name => $def)
        {
            if (!array_key_exists($name, $this->_record))
                continue;

            $this->_record[$name] = $def->afterFetchFilter($this->_record[$name]);
        }
        return $this;
    }

    /**
     * This method is called after assigning new data to $this->_record.
     * It can be used to initialize dependent member variables or provide additional actions.
     *
     * You should override to perform initialization after record has been loaded
     */
    protected function init()
    {}

    /**
     * @return mixed The primary key values for this object. This is a single scalar
     *               for unary primary keys, and an array of values for
     *               compined primary keys.
     */
    public function getID()
    {
        if (empty($this->_id))
            return null;

        if (count($this->_id) === 1)
            return reset($this->_id);

        return $this->_id;
    }

    /**
     * Set the primary key of the model instance
     * @param mixed $id The primary key. Either a scalar value or an array for a combined primary key
     * @return Wedeto\DB\Model Provides fluent interface
     */
    public function setID($id)
    {
        $dao = $this->getDAO();
        $pkey = $dao->getPrimaryKey();

        if (count($pkey) === 1 && is_scalar($id))
        {
            $pcol = reset($pkey);
            $this->setField($pcol->getName(), $id);
        }
        elseif (is_array($id) && array_keys($id) === array_keys($pkey))
        {
            foreach ($pkey as $name => $def)
            {
                $this->setField($name, $id[$name]);
            }
            $this->_id = $id;
        }
        else
        {
            throw new InvalidTypeException("Provided ID does not match primary key");
        }
        return $this;
    }

    /**
     * Remove the data from this object, after removal
     * @return Wedeto\DB\Model Prvides fluent interface
     */
    public function destruct()
    {
        $this->_id = [];
        $this->_source_db = null;
        $this->_record = [];
        $this->_changed = [];
        return $this;
    }

    /**
     * Get the value for a field of this record.
     * @param string $field The name of the field to get
     * @return mixed The value of this field
     */
    public function getField(string $field)
    {
        if (isset($this->_record[$field]))
            return $this->_record[$field];
        return null;
    }

    /**
     * Set a field to a new value. The value will be validated first by
     * calling validate.
     *
     * @param string $field The field to retrieve
     * @param mixed $value The value to set it to.
     * @return Wedeto\DB\DAO Provides fluent interface.
     */
    public function setField(string $field, $value)
    {
        if (isset($this->_record[$field]) && $this->_record[$field] === $value)
            return;

        $db = $this->getDB();
        $dao = static::getDAO($db);
        $columns = $dao->getColumns();
        if (!isset($columns[$field]))
            throw new InvalidValueException("Field $field does not exist!");

        $pkey = $dao->getPrimaryKey();

        $coldef = $columns[$field];
        $coldef->validate($value);

        $correct = $this->validate($field, $value);
        if ($correct !== true)
            throw new InvalidValueException("Field $field cannot be set to $value: {$correct}");

        $this->_record[$field] = $value;
        $this->_changed[$field] = true;

        if (isset($pkey[$field]))
            $this->_id[$field] = $value;

        return $this;
    }

    /**
     * Get the value for a property of this database record. Allows to access them transparently
     * by doing $obj->field.
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
        return $this->_record;
    }

    /**
     * @return array The record with all fields that have been modified
     */
    public function getChanges()
    {
        $changes = [];
        foreach ($this->_changed as $key => $_)
        {
            $changes[$key] = $this->_record[$key];
        }

        return $changes;
    }

    /**
     * @param string $field The field that should be marked unchanged
     * @return Model Provides fluent interface
     */
    public function setChanged(string $field, bool $changed)
    {
        if (!isset($this->record[$field]))
            throw new InvalidTypeException("Unknown field: $field");

        if ($changed)
        {

            $this->_changed[$field] = true;
        }
        else
        {
            unset($this->_changed[$field]);
        }

        return $this;
    }

    /**
     * Set all values as unchanged
     * @return Model Provides fluent interface
     */
    public function markClean()
    {
        $this->_changed = [];
        return $this;
    }

    /**
     * Magic method __set allows transparant property access to
     * instances of the DAO.
     *
     * @param string $field The field to set
     * @param mixed $value What to set the field to
     * @seealso Wedeto\DB\DAO
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
     * Find a database object in a set of arguments
     * @param array $args The list of arguments that may contain a DB object
     * @return Wedeto\DB\DB The database object if found, false otherwise.
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
}
