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

namespace WASP\DB\Driver;

use WASP\Debug\LoggerAwareStaticTrait;

use WASP\DB\DB;
use WASP\DB\TableNotExists;
use WASP\DB\DBException;

use WASP\DB\Table\Table;
use WASP\DB\Table\Index;
use WASP\DB\Table\ForeignKey;
use WASP\DB\Table\Column\Column;

use WASP\Config;

use PDO;
use PDOException;

class MySQL extends Driver
{
    use LoggerAwareStaticTrait;

    protected $iquotechar = '`';

    protected $mapping = array(
        Column::CHAR => 'CHAR',
        Column::VARCHAR => 'VARCHAR',
        Column::TEXT => 'MEDIUMTEXT',
        Column::JSON => 'MEDIUMTEXT',
        Column::ENUM => 'ENUM',

        Column::BOOLEAN => 'TINYINT',
        Column::TINYINT => 'TINYINT',
        Column::SMALLINT => 'SMALLINT',
        Column::MEDIUMINT => 'MEDIUMINT',
        Column::INT => 'INT',
        Column::BIGINT => 'BIGINT',
        Column::FLOAT => 'FLOAT',
        Column::DECIMAL => 'DECIMAL',

        Column::DATETIME => 'DATETIME',
        Column::DATE => 'DATE',
        Column::TIME => 'TIME',

        Column::BINARY => 'MEDIUMBLOB'
    );

    public function generateDSN(array $config)
    {
        if (!isset($config['database']))
            throw new DBException("Required field missing: database");

        if (isset($config['socket']))
            return "mysql:socket=" . $config['socket'] . ";dbname=" . $config['database'] . ";charset=utf8"; 

        if (!isset($config['hostname']))
            throw new DBException("Required field missing: socket or hostname");

        if (isset($config['port']))
            $port = ";port=" . $config['port'];

        return "mysql:host=" . $config['hostname'] . ";dbname=" . $config['database'] . $port . ";charset=utf8";
    }

    public function select($table, $where, $order, array $params)
    {
        $q = "SELECT * FROM " . $this->getName($table);
        
        $col_idx = 0;
        $q .= $this->getWhere($where, $col_idx, $params);
        $q .= $this->getOrder($order);

        $st = $this->db->prepare($q);

        $st->execute($params);
        return $st;
    }

    public function update($table, $idfield, array $record)
    {
        $id = $record[$idfield];
        if (empty($id))
            throw new DBEXception("No ID set for record to be updated");

        unset($record[$idfield]);
        if (count($record) == 0)
            throw new DBException("Nothing to update");
        
        $col_idx = 0;
        $params = array();

        $parts = array();
        foreach ($record as $k => $v)
        {
            $col_name = "col" . (++$col_idx);
            $parts[] .= $this->identQuote($k) . " = :{$col_name}";
            $params[$col_name] = $v;
        }

        $q = "UPDATE " . $this->getName($table) . " SET ";
        $q .= implode(", ", $parts);
        $q .= $this->getWhere(array($idfield => $id), $col_idx, $params);

        $this->logger->info("Preparing update query {0}", [$q]);
        $st = $this->db->prepare($q);
        $st->execute($params);

        return $st->rowCount();
    }

    public function insert($table, $idfield, array &$record)
    {
        if (!empty($record[$idfield]))
            throw new DBException("ID set for record to be inserted");

        $q = "INSERT INTO " . $this->getName($table) . " ";
        $fields = array_map(array($this, "identQuote"), array_keys($record));
        $q .= "(" . implode(", ", $fields) . ")";

        $col_idx = 0;
        $params = array();
        $parts = array();
        foreach ($record as $val)
        {
            $col_name = "col" . (++$col_idx);
            $parts[] = ":{$col_name}";
            $params[$col_name] = $val;
        }
        $q .= " VALUES (" . implode(", ", $parts) . ")";
    
        $st = $this->db->prepare($q);

        $this->logger->info("Executing insert query with params {0}", [$q]);
        $st->execute($params);
        $record[$idfield] = $this->db->lastInsertId();

        return $record[$idfield];
    }

    public function upsert($table, $idfield, $conflict, array &$record)
    {
        if (!empty($record[$idfield]))
            return $This->update($table, $idfield, $record);

        $q = "INSERT INTO " . $this->getName($table) . " ";
        $fields = array_map(array($this, "identQuote"), array_keys($record));
        $q .= "(" . implode(", ", $fields) . ")";

        $col_idx = 0;
        $params = array();
        $parts = array();
        foreach ($record as $val)
        {
            $col_name = "col" . (++$col_idx);
            $parts[] = ":{$col_name}";
            $params[$col_name] = $val;
        }
        $q .= " VALUES (" . implode(", ", $parts) . ")";

        // Upsert part
        $q .= " ON DUPLICATE KEY UPDATE ";
        $conflict = (array)$conflict;
        $parts = array();
        foreach ($record as $field => $value)
        {
            if (in_array($field, $conflict))
                continue;

            $col_name = "col" . (++$col_idx);
            $parts[] = $this->identQuote($field) . ' = :' . $col_name;
            $params[$col_name] = $value;
        }
        $q .= implode(",", $parts);
    
        $st = $this->db->prepare($q);

        $this->logger->info("Executing upsert query with params {0}", [$params]);
        $st->execute($params);
        $record[$idfield] = $this->db->lastInsertId();

        return $record[$idfield];
    }

    public function delete($table, $where)
    {
        $q = "DELETE FROM " . $this->getName($table);
        $col_idx = 0;
        $params = array();
        $q .= $this->getWhere($where, $col_idx, $params);

        $this->logger->info("Model.DAO", "Preparing delete query {0}", [$q]);
        $st = $this->db->prepare($q);
        $st->execute($params);

        return $st->rowCount();
    }

    public function getColumns($table_name)
    {
        $q = $this->db->prepare("
            SELECT column_name, data_type, column_type, is_nullable, column_default, numeric_precision, numeric_scale, character_maximum_length, extra
                FROM information_schema.columns 
                WHERE table_name = :table AND table_schema = :schema
                ORDER BY ordinal_position
        ");

        $table_name = $this->getName($table_name, false);
        $q->execute(array("table" => $table_name, "schema" => $this->schema));

        if ($q->rowCount() === 0)
            throw new TableNotExists();

        return $q->fetchAll();
    }

    public function createTable(Table $table)
    {
        $query = "CREATE TABLE " . $this->getName($table->getName()) . " (\n";

        $cols = $table->getColumns();
        $coldefs = array();
        $serial = null;
        foreach ($cols as $c)
        {
            if ($c->getSerial())
                $serial = $c;
            $coldefs[] = $this->getColumnDefinition($c);
        }

        $query .= "    " . implode(",\n    ", $coldefs);
        $query .= "\n) ENGINE=InnoDB CHARSET=utf8 COLLATE=utf8_general_ci\n";

        // Create the main table
        $this->db->exec($query);

        // Add indexes
        $serial_col = null;

        $indexes = $table->getIndexes();
        foreach ($indexes as $idx)
            $this->createIndex($table, $idx);

        // Add auto_increment
        if ($serial !== null)
            $this->createSerial($table, $serial);

        // Add foreign keys
        $fks = $table->getForeignKeys();
        foreach ($fks as $fk)
            $this->createForeignKey($table, $fk);
        return $this;
    }

    /**
     * Drop a table
     *
     * @param $table mixed The table to drop
     * @param $safe boolean Add IF EXISTS to query to avoid errors when it does not exist
     * @return Driver Provides fluent interface 
     */
    public function dropTable($table, $safe = false)
    {
        $query = "DROP TABLE " . ($safe ? " IF EXISTS " : "") . $this->getName($table);
        $this->db->exec($query);
        return $this;
    }

    
    public function createIndex(Table $table, Index $idx)
    {
        $cols = $idx->getColumns();
        $names = array();
        foreach ($cols as $col)
            $names[] = $this->identQuote($col);
        $names = '(' . implode(',', $names) . ')';

        if ($idx->getType() === Index::PRIMARY)
        {
            $this->db->exec("ALTER TABLE " . $this->getName($table) . " ADD PRIMARY KEY $names");
            $cols = $idx->getColumns();
            $first_col = $cols[0];
            $col = $table->getColumn($first_col);
            if (count($cols) == 1 && $col->getSerial())
                $serial_col = $col;
        }
        else
        {
            $q = "CREATE ";
            if ($idx->getType() === Index::UNIQUE)
                $q .= "UNIQUE ";
            $q .= "INDEX " . $this->getName($idx) . " ON " . $this->getName($table) . " $names";
            $this->db->exec($q);
        }
        return $this;
    }

    public function dropIndex(Table $table, Index $idx)
    {
        $name = $idx->getName();
        $q = " DROP INDEX " . $this->identQuote($name) . " ON " . $this->getName($table);
        $this->db->exec($q);
        return $this;
    }

    public function createForeignKey(Table $table, ForeignKey $fk)
    {
        $src_table = $table->getName();
        $src_cols = array();

        foreach ($fk->getColumns() as $c)
            $src_cols[] = $this->identQuote($c);

        $tgt_table = $fk->getReferredTable();
        $tgt_cols = array();

        foreach ($fk->getReferredColumns() as $c)
            $tgt_cols[] = $this->identQuote($c);

        $q = 'ALTER TABLE ' . $this->getName($src_table)
            . ' ADD FOREIGN KEY ' . $this->getName($fk)
            . '(' . implode(',', $src_cols) . ') '
            . 'REFERENCES ' . $this->getName($tgt_table)
            . '(' . implode(',', $tgt_cols) . ')';

        $on_update = $fk->getOnUpdate();
        if ($on_update === ForeignKey::DO_CASCADE)
            $q .= ' ON UPDATE CASCADE ';
        elseif ($on_update === ForeignKey::DO_RESTRICT)
            $q .= ' ON UPDATE RESTRICT ';
        elseif ($on_update === ForeignKey::DO_NULL)
            $q .= ' ON UPDATE SET NULL ';

        $on_delete = $fk->getOnDelete();
        if ($on_update === ForeignKey::DO_CASCADE)
            $q .= ' ON DELETE CASCADE ';
        elseif ($on_update === ForeignKey::DO_RESTRICT)
            $q .= ' ON DELETE RESTRICT ';
        elseif ($on_update === ForeignKey::DO_NULL)
            $q .= ' ON DELETE SET NULL ';

        $this->db->exec($q);
        return $this;
    }

    public function dropForeignKey(Table $table, ForeignKey $fk)
    {
        $name = $fk->getName();
        $this->db->exec("ALTER TABLE DROP FOREIGN KEY " . $this->identQuote($name));
        return $this;
    }

    public function createSerial(Table $table, Column $column)
    {
        $q = "ALTER TABLE " . $this->getName($table->getName()) 
            . " MODIFY "
            . " " . $this->getColumnDefinition($column) . " AUTO_INCREMENT";

        $this->db->exec($q);
        return $this;
    }

    public function dropSerial(Table $table, Column $column)
    {
        $q = "ALTER TABLE " . $this->getName($table->getName()) 
            . " MODIFY " . $this->identQuote($column->getName())
            . " " . $this->getColumnDefinition($column);

        $this->db->exec($column);
        $column
            ->setSerial(false)
            ->setDefault(null);
        return $this;
    }

    public function addColumn(Table $table, Column $column)
    {
        $q = "ALTER TABLE " . $this->getName($table) . " ADD COLUMN " . $this->getColumnDefinition($column);
        $this->db->exec($q);

        return $this;
    }

    public function removeColumn(Table $table, Column $column)
    {
        $q = "ALTER TABLE " . $this->getName($table->getName()) . " DROP COLUMN " . $this->identQuote($column->getName());
        $this->db->exec($q);

        return $this;
    }

    public function getColumnDefinition(Column $col)
    {
        $numtype = $col->getType();
        if (!isset($this->mapping[$numtype]))
            throw new DBException("Unsupported column type: $numtype");

        $type = $this->mapping[$numtype];
        $coldef = $this->identQuote($col->getName()) . " " . $type;
        switch ($numtype)
        {
            case Column::CHAR:
            case Column::VARCHAR:
                $coldef .= "(" . $col->getMaxLength() . ")";
                break;
            case Column::SMALLINT:
            case Column::INT:
            case Column::BIGINT:
                $coldef .= "(" . $col->getNumericPrecision() . ")";
                break;
            case Column::BOOLEAN:
                $coldef .= "(1)";
                break;
            case Column::DECIMAL:
                $coldef .= "(" . $col->getNumericPrecision() . "," . $col->getNumericScale() . ")";
                break;
            case Column::ENUM:
                $coldef .= "('" . implode("','", $col->getEnumValues()) . "')";
                break;
        }

        $coldef .= $col->isNullable() ? " NULL" : " NOT NULL";
        $def = $col->getDefault();

        if ($def)
        {
            if (is_bool($def))
                $def = $def ? 1 : 0;
            elseif (!in_array($def, array("NOW()", "CURRENT_TIMESTAMP", "CURRENT_TIME", "CURRENT_DATE")))
                $def = $this->db->quote($def);

            $coldef .= " DEFAULT " . $def;
        }
        
        return $coldef;
    }

    public function loadTable($table_name)
    {
        $table = new Table($table_name);

        // Get all columns
        $columns = $this->getColumns($table_name);
        $serial = null;
        foreach ($columns as $col)
        {
            $type = strtoupper($col['data_type']);
            $numtype = array_search($type, $this->mapping);
            if ($numtype === false)
                throw new DBException("Unsupported field type: " . $type);
            
            $column = new Column(
                $col['column_name'],
                $numtype,
                $col['character_maximum_length'],
                $col['numeric_precision'],
                $col['numeric_scale'],
                $col['is_nullable'],
                $col['column_default']
            );

            if ($numtype === Column::ENUM)
            {
                // Extract values from enum
                $vals = substr($col['column_type'], 5, -1); //  Remove 'ENUM(' and ')'
                $enum_values = explode(',', $vals);
                $vals = array();
                foreach ($enum_values as $val)
                    $vals[] = trim($val, "'");
                $column->setEnumValues($vals);
            }

            $table->addColumn($column);
            if (strtolower($col['extra']) === "auto_increment")
            {
                $pkey = new Index(Index::PRIMARY);
                $pkey->addColumn($column);
                $table->addIndex($pkey);

                $column->setSerial(true);
                $serial = $column;
            }
        }

        $constraints = $this->getConstraints($table_name);

        // Constraints with multiple columns will have multiple rows
        $summarized = array();
        foreach ($constraints as $constraint)
        {
            if ($serial !== null && $constraint['CONSTRAINT_TYPE'] === "PRIMARY KEY" && $constraint['COLUMN_NAME'] == $serial->getName())
                continue;

            $n = $this->stripPrefix($constraint['CONSTRAINT_NAME']);
            if (!isset($summarized[$n]))
                $summarized[$n] = array(
                    'name' => $n,
                    'column' => array(),
                    'referred_table' => array(),
                    'referred_column' => array(),
                    'type' => $constraint['CONSTRAINT_TYPE']
                );

            $summarized[$n]['column'][] = $constraint['COLUMN_NAME'];
            $summarized[$n]['referred_table'] = $this->stripPrefix($constraint['REF_TABLE']);
            $summarized[$n]['referred_column'][] = $constraint['REF_COLUMN'];
        }
        
        // Get update/delete policy from foreign keys
        $fks = $this->getForeignKeys($table_name);
        foreach ($fks as $fk)
        {
            $n = $this->stripPrefix($fk['CONSTRAINT_NAME']);
            var_Dump($this->table_prefix);
            var_Dump($n);
            if (!empty($this->prefix) && substr($n, 0, strlen($this->prefix)) == $this->prefix)
                $n = substr($n, strlen($this->prefix));
            if (isset($summarized[$n]))
            {
                $summarized[$n]['on_update'] = $fk['UPDATE_RULE'];
                $summarized[$n]['on_delete'] = $fk['DELETE_RULE'];
            }
        }

        foreach ($summarized as $constraint)
        {
            if ($constraint['type'] === "FOREIGN KEY")
            {
                $table->addForeignKey(new ForeignKey($constraint));
            }
            elseif ($constraint['type'] === "PRIMARY KEY")
            {
                $constraint['type'] = Index::PRIMARY;
                $table->addIndex(new Index($constraint));
            }
            elseif ($constraint['type'] === "UNIQUE")
            {
                $constraint['type'] = Index::UNIQUE;
                $table->addIndex(new Index($constraint));
            }
            else
                throw new DBException("Unsupported constraint type: {$constraint['type']}");
        }

        // Get all indexes
        $indexes = $this->getIndexes($table_name);

        $summarized = array();
        foreach ($indexes as $index)
        {
            // We need to skip primary and unique keys, as they have already been added by the constraints
            if ($index['Non_unique'] == 0)
                continue;

            $n = $this->stripPrefix($index['Key_name']);
            if (!isset($summarized[$n]))
                $summarized[$n] = array(
                    'name' => $n,
                    'type' => Index::INDEX,
                    'column' => array()
                );

            $summarized[$n]['column'][] = $index['Column_name'];
        }

        foreach ($summarized as $idx)
            $table->addIndex(new Index($idx));

        $table->validate();

        return $table;
    }

    public function getForeignKeys($table_name)
    {
        $q = "
        SELECT 
                CONSTRAINT_NAME, UPDATE_RULE, DELETE_RULE 
        FROM information_schema.REFERENTIAL_CONSTRAINTS 
        WHERE CONSTRAINT_SCHEMA = :schema AND TABLE_NAME  = :table";
        $q = $this->db->prepare($q);
        $tname = $this->getName($table_name, false);

        $q->execute(array("schema" => $this->schema, "table" => $tname));
        return $q->fetchAll();
    }

    public function getConstraints($table_name)
    {
        $q = "
        SELECT 
            kcu.CONSTRAINT_NAME AS CONSTRAINT_NAME,
            kcu.COLUMN_NAME AS COLUMN_NAME,
            kcu.REFERENCED_TABLE_NAME AS REF_TABLE,
            kcu.REFERENCED_COLUMN_NAME AS REF_COLUMN,
            tc.CONSTRAINT_TYPE AS CONSTRAINT_TYPE
        FROM
            information_schema.key_column_usage kcu
        LEFT JOIN information_schema.TABLE_CONSTRAINTS tc 
            ON (
                tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME AND
                tc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA AND
                tc.TABLE_NAME = kcu.TABLE_NAME
            )
        WHERE 
            tc.CONSTRAINT_SCHEMA = :schema AND
            kcu.table_name = :table
        ";

        $table_name = $this->getName($table_name, false);
        $q = $this->db->prepare($q);
        $q->execute(array("schema" => $this->schema, "table" => $table_name));

        return $q->fetchAll();
    }

    public function getIndexes($table_name)
    {
        $q = "SHOW INDEX FROM " . $this->getName($table_name);
        $q = $this->db->prepare($q);
        $q->execute();

        return $q->fetchAll();
    }
}

// @codeCoverageIgnoreStart
MySQL::setLogger();
// @codeCoverageIgnoreEnd
