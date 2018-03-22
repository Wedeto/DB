<?php
/*
This is part of Wedeto, the WEb DEvelopment TOolkit.
It is published under the MIT Open Source License.

Copyright 2017, Egbert van der Wal

Permission is hereby granted, free of charge, to any person obtaining a copy of
this software and associated documentation files (the "Software"), to deal in
the Software without restriction, including without limitation the rights to
use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
}the Software, and to permit persons to whom the Software is furnished to do so,
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

namespace Wedeto\DB\Schema\Column;

use DateTime;

use Wedeto\Util\Functions as WF;
use Wedeto\Util\Hook;
use Wedeto\Util\Validation\ValidationException;
use Wedeto\DB\Schema\Table;
use Wedeto\DB\Exception\InvalidTypeException;
use Wedeto\DB\Exception\InvalidValueException;

abstract class Column implements \Serializable, \JSONSerializable
{
    const CHAR       = "CHAR";
    const VARCHAR    = "VARCHAR";
    const TEXT       = "TEXT";
    const JSON       = "JSON";
    const ENUM       = "ENUM";

    const BOOLEAN    = "BOOLEAN";
    const TINYINT    = "TINYINT";
    const SMALLINT   = "SMALLINT";
    const MEDIUMINT  = "MEDIUMINT";
    const INT        = "INT";
    const BIGINT     = "BIGINT";
    const FLOAT      = "FLOAT";
    const DECIMAL    = "DECIMAL";
 
    const DATE       = "DATE";
    const DATETIME   = "DATETIME";
    const DATETIMETZ = "DATETIMETZ";
    const TIME       = "TIME";

    const BINARY     = "BINARY";

    protected $table;

    protected $name;
    protected $type;
    protected $is_nullable;

    protected $max_length;

    protected $numeric_scale;
    protected $numeric_precision;

    protected $default = null;

    protected $serial = null;
    protected $enum_values = null;

    public function __construct(string $name, string $type, $default, bool $nullable)
    {
        $this->name = $name;
        $this->type = $type;
        $this->is_nullable = WF::parse_bool($nullable);
        $this->default = $default;
    }

    public function setSerial(bool $serial = true)
    {
        $serial = $serial == true;
        if (
            $serial && 
            $this->type !== Column::INT && 
            $this->type !== Column::BIGINT && 
            $this->type !== Column::MEDIUMINT && 
            $this->type !== Column::SMALINT
        )
        {
            throw new InvalidTypeException("A serial column must be of type integer");
        }

        $this->serial = $serial == true;
    }

    public function getSerial()
    {
        return $this->serial;
    }

    public function getTable()
    {
        return $this->table;
    }

    public function setTable(Table $table)
    {
        if ($this->serial)
        {
            foreach ($table->getColumns() as $c)
                if ($c->name !== $this->name && $c->serial)
                    throw new InvalidValueException("There can be only one serial column in a table");
        }

        $this->table = $table;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getType()
    {
        return $this->type;
    }

    public function set(string $field, $value)
    {
        if (property_exists($this, $field))
            $this->field = $value;
        return $this;
    }

    public function getMaxLength()
    {
        return $this->max_length;
    }

    public function setMaxLength($max_length)
    {
        $this->max_length = $max_length === null ? null : (int)$max_length;
        return $this;
    }

    public function getNumericScale()
    {
        return $this->numeric_scale;
    }

    public function setNumericScale($scale)
    {
        $this->numeric_scale = $scale === null ? null : (int)$scale;
        return $this;
    }

    public function getNumericPrecision()
    {
        return $this->numeric_precision;
    }

    public function setNumericPrecision($precision)
    {
        $this->numeric_precision = $precision === null ? null : (int)$precision;
        return $this;
    }

    public function isNullable()
    {
        return $this->is_nullable;
    }

    public function setNullable($nullable)
    {
        $this->is_nullable = $nullable == true;
    }

    public function getDefault()
    {
        return $this->default;
    }

    public function setDefault($default)
    {
        $this->default = $default;
        return $this;
    }

    public function setEnumValues(array $values)
    {
        $this->enum_values = $values;
        return $this;
    }

    public function getEnumValues()
    {
        return $this->enum_values;
    }

    public function validate($value)
    {
        if ($value === null && !$this->is_nullable)
            throw new ValidationException(['msg' => 'Column must not be null: {name}', 'context' => ['name' => $this->name]]);

        return true;
    }

    public function afterFetchFilter($value)
    {
        return $value;
    }

    public function beforeInsertFilter($value)
    {
        if ($value === null)
        {
            if (!$this->isNullable())
                throw new ValidationException(['msg' => 'Column must not be null: {name}', 'context' => ['name' => $this->name]]);
            return null;
        }

        return $value;
    }

    /**
     * @return array A serializable array representation of this column
     */
    public function toArray()
    {
        $arr = array(
            "column_name" => $this->name,
            "data_type" => $this->type,
            "is_nullable" => $this->is_nullable ? 1 : 0,
            "column_default" => $this->default,
            "serial" => $this->serial
        );

        if ($this->numeric_precision !== null)
            $arr["numeric_precision"] = $this->numeric_precision;
        if ($this->numeric_scale !== null)
            $arr["numeric_scale"] = $this->numeric_scale;
        if ($this->max_length !== null)
            $arr["character_maximum_length"] = $this->max_length;
        if ($this->type === Column::ENUM)
            $arr["enum_values"] = $this->enum_values;
        return $arr;
    }

    /**
     * Parse deserialized array into valid arguments for column properties.
     * @param array $data The unserialized array
     * @param array A normalized array containing all fields
     */
    public static function parseArray(array $data)
    {
        $type = strtoupper($data['data_type']);
        if (!defined(static::class . '::' . $type))
            throw new InvalidValueException("Invalid column type: $type");
            
        return array(
            'name' => $data['column_name'],
            'type' => $type,
            'max_length' => $data['character_maximum_length'] ?? null,
            'is_nullable' => isset($data['is_nullable']) ? $data['is_nullable'] == true : false,
            'column_default' => $data['column_default'] ?? null,
            'numeric_precision' => $data['numeric_precision'] ?? null,
            'numeric_scale' => $data['numeric_scale'] ?? null,
            'serial' => isset($data['serial']) ? $data['serial'] == true : false,
            'enum_values' => $data['enum_values'] ?? null
        );
    }

    /** 
     * @return array A JSON-serializable version of this column
     */
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * @return array A PHP-serializable version of this column
     */
    public function serialize()
    {
        return serialize($this->toArray());
    }

    /**
     * Restore the column from its serialize form
     * @param string $data The serialized data
     */
    public function unserialize($data)
    {
        $data = unserialize($data);
        $args = self::parseArray($data);
        $this->__construct($args['name']);
        
        foreach ($args as $property => $value)
        {
            if (!empty($value))
                $this->set($property, $value);
        }
            
        return $col;
    }

    /**
     * Create a column instance from an array.
     * @param array $data The column specification containing name. type,
     *                    maximum length, numeric scale and precision, default
     *                    value, nullability and such.
     * @return Column An instantiated column object
     */
    public static function factory(array $data)
    {
        $type = ucfirst(strtolower($data['data_type']));
        $args = self::parseArray($data);

        // Execute hook to allow for additional column types or modifications
        $classname = __NAMESPACE__ . "\\" . ucfirst(strtolower($args['type']));
        $params = Hook::execute(
            'Wedeto.DB.Schema.Column.Column.FindClass', 
            ['column_defition' => $args, 'input_data' => $data, 'classname' => $classname, 'instance' => null]
        );

        // Check if a hook has already provided an instance
        if ($params['instance'] !== null && $params['instance'] instanceof Column)
            return $params['instance'];

        // Get the selected classname
        $classname = $params['classname'];
        if (!class_exists($classname))
            throw new InvalidTypeException("Unsupported column type: " . $args['type'] . " (class $classname not found)");

        $col = new $classname($args['name']);
        foreach ($args as $property => $val)
        {
            if (!empty($value))
                $this->set($property, $value);
        }

        return $col;
    }
}
