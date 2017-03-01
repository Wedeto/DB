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

use WASP\Debug;
use PDOException;

use WASP\DB\SQL\QueryBuilder as QB;

class DAO
{
    /** A mapping between class identifier and full, namespaced class name */
    protected static $classes = array();

    /** A mapping between full, namespaced class name and class identifier */
    protected static $classnames = array();

    /** Override to set the name of the ID field */
    protected static $idfield = "id";

    /** Override to set the name of the table */
    protected static $table = null;

    /** The columns defined in the database */
    protected static $columns = null;

    /** The quote character for identifiers */
    protected static $ident_quote = '`';

    /** The ID value */
    protected $id;
    
    /** The database record */
    protected $record;

    /** The associated ACL entity */
    protected $acl_entity = null;

    protected function db()
    {
        return DB::get();
    }

    public function save()
    {
        $idf = static::$idfield;
        if (isset($this->record[$idf]))
            self::update($this->record);
        else
            $this->id = self::insert($this->record);
        $this->initACL();
    }

    protected function load($id)
    {
        $idf = static::$idfield;
        $rec = static::fetchSingle(array($idf => $id));
        if (empty($rec))
            throw new DAOEXception("Object not found with $id");

        $this->record = $rec;
        $this->id = $id;
        $this->initACL();
        $this->init();
    }

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

    // Override to perform initialization after record has been loaded
    protected function init()
    {}

    // Create an object from a database record or a ID
    public static function get($id)
    {
        $idf = static::$idfield;
        if (!\is_int_val($id))
        {
            if (!is_array($id) || empty($id[$idf]))
                throw new DAOException("Cannot initialize object from $id");
            $record = $id;
            $id = $record[$idf];
        }
        else
        {
            $record = static::fetchSingle(array($idf => $id));
            if (empty($record))
                return null;
        }

        $class = get_called_class();
        $obj = new $class();
        $obj->id = $id;
        $obj->record = $record;
        $obj->init();
        return $obj;
    }

    // Create an object from a database record or a ID
    public static function getAll($where = array(), $order = array(), $params = array())
    {
        $list = array();
        $records = static::fetchAll($where, $order, $params);
        foreach ($records as $record)
            $list[] = static::get($record);
        return $list;
    }

    protected static function fetchSingle()
    {
        $select = static::select(func_get_args());
        return $st->fetch();
    }

    protected static function fetchAll()
    {
        $select = static::select(func_get_args());
        return $st->fetchAll();
    }

    protected static function select()
    {
        $select = Q::select(func_get_args());
        $select->add(new TableClause(static::tablename()));

        $db = DB::get()->driver();
        return $db->execute($query, $parameters);
    }

    protected static function update(array $record)
    {
        $db = DB::get()->driver();
        return $db->update(static::tablename(), static::$idfield, $record);
    }

    protected static function insert(array &$record)
    {
        $db = DB::get()->driver();
        return $db->insert(static::tablename(), static::$idfield, $record);
    }

    protected static function delete($where)
    {
        $db = DB::get()->driver();
        return $db->insert(static::tablename(), $where);
    }

    public function getID()
    {
        return $this->id;
    }

    public function getField($field)
    {
        if (isset($this->record[$field]))
            return $this->record[$field];
        return null;
    }

    public function setField($field, $value)
    {
        $correct = $this->validate($field, $value);
        if ($correct !== true)
            throw new DAOException("Field $field cannot be set to $value: {$correct}");

        $this->record[$field] = $value;
    }

    public function __get($field)
    {
        return $this->getField($field);
        if (isset($this->record[$field]))
            return $this->record[$field];
        return null;
    }

    public function __set($field, $value)
    {
        $this->setField($field, $value);
    }
    
    // Override to perform checks
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
        if (!class_exists("WASP\\ACL\\Entity", false))
            return;
        
        $id = \WASP\ACL\Entity::generateID($this);
        if (!\WASP\ACL\Entity::hasInstance($id))
            $this->acl_entity = new \WASP\ACL\Entity($id, $this->getParents());
        else
            $this->acl_entity = \WASP\ACL\Entity::getInstance($id);
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

    public static function tablename()
    {
        return static::$table;
    }

    public static function quoteIdentity($identity)
    {
        $identity = str_replace(self::$ident_quote, self::$ident_quote . self::$ident_quote, $identity);
        return self::$ident_quote . $identity . self::$ident_quote;
    }

    public static function getColumns()
    {
        if (self::$columns === null)
        {
            $driver = DB::get()->driver();
            self::$columns = $driver->getColumns(static::tablename());
        }
        return self::$columns;
    }
}
