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

use WASP\DB\Query;
use WASP\DB\Query\Parameters;
use WASP\DB\Query\Select;

use PDO;
use PDOException;

class PGSQL extends Driver
{
    use LoggerAwareStaticTrait;
    use StandardSQLTrait;

    protected $iquotechar = '"';

    protected $mapping = array(
        Column::CHAR => 'character',
        Column::VARCHAR => 'character varying',
        Column::TEXT => 'text',
        Column::JSON => 'json',
        Column::ENUM => 'enum',

        Column::BOOLEAN => 'boolean',
        Column::SMALLINT => 'smallint',
        Column::TINYINT => 'smallint',
        Column::INT => 'integer',
        Column::MEDIUMINT => 'integer',
        Column::BIGINT => 'bigint',
        Column::FLOAT => 'double precision',
        Column::DECIMAL => 'decimal',

        Column::DATETIME => 'timestamp without time zone',
        Column::DATE => 'date',
        Column::TIME => 'time',

        Column::BINARY => 'bytea'
    );

    /**************************
     ***** DATABASE SETUP *****
     **************************/

    /**
     * Generate a DSN-string to connect to the database.
     * @param array $config The configuration available for the connection
     *                      Accepted values are: 'ssl', 'sslmode', 'host',
     *                      'hostname', 'port' 'host', 'hostaddr', 'database',
     *                      'dbname', 'schema'.
     *
     *                      'host' and 'hostname' are aliases, where 'hostname'
     *                      takes precedence.
     *
     *                      'ssl' implies 'sslmode'='require' and takes precedence.
     *
     *                      When 'schema' is not specified, the PGSQL default
     *                      'public' is used.
     *
     *                      When no 'host' and 'hostname' are both not defined,
     *                      a connection will be attempted through a Unix
     *                      domain socket.
     */
    public function generateDSN(array $config)
    {
        $dsn = array();
        if (isset($config['hostname']))
            $dsn['host'] = $config['hostname'];
        elseif (isset($config['host']))
            $dsn['host'] = $config['host'];

        if (isset($config['hostaddr']))
        {
            $add = filter_var($config['hostaddr'], FILTER_VALIDATE_IP);
            if ($add === false)
                throw new \DomainException("Invalid IP-address specified for 'hostaddr': " . $config['hostaddr']);
        }

        if (!empty($config['port']) && \WASP\is_int_val($config['port']))
            $dsn['port'] = (int)$config['port'];

        if (isset($config['ssl']) && (bool)$config['ssl'])
        {
            $dsn['sslmode'] = 'require';
        }
        elseif (isset($config['sslmode']))
        {
            if (!in_array($config['ssl'], ['prefer', 'disable', 'allow', 'require']))
                throw new \Domainexception("Invalid value for sslmode: " . \WASP\str($config['ssl']));
            $dsn['sslmode'] = $config['sslmode'];
        }

        if (isset($config['dbname']))
            $dsn['dbname'] = (string)$config['dbname'];
        elseif (isset($config['database']))
            $dsn['dbname'] = (string)$config['database'];

        if (!isset($dsn['dbname']))
            throw new DBException("No database name provided");

        $this->dbname = $dsn['dbname'];
        
        if (isset($config['schema']))
            $this->schema = (string)$config['schema'];
        else
            $this->schema = 'public';

        $parts = array();
        foreach ($dsn as $key => $value)
            $parts[] = "$key=$value";

        return 'pgsql:' . implode(';', $parts);
    }

    /**********************************
     ***** SQL DIALECT DEFINITION *****
     **********************************/
    public function matchMultipleValues(Query\FieldName $field, Query\ConstantArray $list)
    {
        $operator = "=";

        $func = new Query\SQLFunction("ANY");
        $func->addArgument($list);

        return new Query\ComparisonOperator("=", $field, $func);
    }

    public function formatArray(array $values)
    {
        $vals = array();
        foreach ($values as $val)
        {
            if (is_string($val))
                $val = str_replace('"', '\\"', str_replace('\\', '\\\\', $val));
            elseif (!is_scalar($val))
                throw new InvalidArgumentException("All list elements must be scalars");
            $vals[] = '"' . $val . '"';
        }
        return '{' . implode(',', $vals) . '}';
    }

    public function duplicateKeyToSQL(Parameters $parameters, Query\DuplicateKey $duplicate)
    {
        $query = array("ON CONFLICT");

        $conflicts = $duplicate->getConflictingFields();
        $parts = array();
        foreach ($conflicts as $c)
            $parts[] = $this->toSQL($parameters, $c, false);

        $query[] = "(" . implode(', ', $parts) . ")";
        $query[] = "DO UPDATE";
        $query[] = "SET";

        $updates = $duplicate->getUpdates();
        $parts = array();
        foreach ($updates as $up)
            $parts[] = $this->updateFieldToSQL($parameters, $up);

        $query[] = implode(", " , $parts);

        return implode(" ", $query);
    }

    public function delete(Query\Delete $query)
    {
        $params = new Parameters($this);
        $sql = $this->deleteToSQL($params, $d);

        $this->logger->info("Model.DAO", "Preparing delete query {0}", [$sql]);
        $st = $this->db->prepare($q);
        $st->execute($params->getParameters());

        return $st->rowCount();
    }

    public function insert(Query\Insert $query, $id_field = null)
    {
        $parameters = new Parameters($this);
        $sql = $this->insertToSQL($parameters, $query);

        $retval = !empty($id_field);
        if ($retval)
            $sql .= " RETURNING " . $this->identQuote($id_field);

        $st = $this->db->prepare($sql);
        $st->execute($parameters->getParameters());

        if ($retval)
            $query->setInsertId($st->fetchColumn(0));
        else
            $query->setInsertId($this->db->lastInsertId());

        return $query->getInsertId();
    }

    public function select(Query\Select $query)
    {
        $parameters = new Parameters($this);
        $sql = $this->selectToSQL($parameters, $query);

        $st = $this->db->prepare($sql);
        $st->execute($parameters->getParameters());
        return $st;
    }

    public function update(Query\Update $query)
    {
        $parameters = new Parameters($this);
        $sql = $this->updateToSQL($parameters, $query);
    
        $st = $this->db->prepare($sql);
        $st->execute($parameters->getParameters());

        return $st->rowCount();
    }

    /*******************************
     ***** DATABASE MANAGEMENT *****
     *******************************/
    public function getColumns($table_name)
    {
        try
        {
            $q = $this->db->prepare("
                SELECT column_name, data_type, udt_name, is_nullable, column_default, numeric_precision, numeric_scale, character_maximum_length 
                    FROM information_schema.columns 
                    WHERE table_name = :table AND table_schema = :schema
                    ORDER BY ordinal_position
            ");

            $q->execute(array("table" => $table_name, "schema" => $this->schema));

            return $q->fetchAll();
        }
        catch (PDOException $e)
        {
            throw new TableNotExists();
        }
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
        $query .= "\n)";

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
        if ($idx->getType() === Index::PRIMARY || $idx->getType() === Index::UNIQUE)
            $q = "ALTER TABLE " . $this->getName($table) . " DROP CONSTRAINT " . $this->getName($idx);
        else
            $q = "DROP INDEX " . $this->identQuote($idx);

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
            . ' ADD CONSTRAINT ' . $this->getName($fk)
            . ' FOREIGN KEY (' . implode(',', $src_cols) . ') '
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
        $this->db->exec("ALTER TABLE DROP CONSTRAINT " . $this->identQuote($name));
        return $this;
    }

    public function createSerial(Table $table, Column $column)
    {
        $tablename = $this->getName($table);
        $colname = $this->identQuote($column->getName());
        $seqname = $this->getName($table->getName() . "_" . $column->getName() . "_seq", false);

        // Create the new sequence
        $this->db->exec("CREATE SEQUENCE $seqname");

        // Change the column type to use the sequence
        $q = "ALTER TABLE {$tablename}"
            . " ALTER COLUMN {$colname} SET DEFAULT nextval('{$seqname}'), "
            . " ALTER COLUMN {$seqname} SET NOT NULL;";
        $this->db->exec($q);

        // Make the sequence owned by the column so it will be automatically
        // removed when the column is removed
        $this->db->exec("ALTER SEQUENCE {$seqname} OWNED BY {$tablename}.{$colname};");

        return $this;
    }

    public function dropSerial(Table $table, Column $column)
    {
        $tablename = $this->getName($table);
        $colname = $this->identQuote($column->getName());
        $seqname = $this->prefix . $table->getName() . "_" . $column->getName() . "_seq";
        
        // Remove the default value for the column
        $this->db->exec("ALTER TABLE {$tablename} ALTER COLUMN {$colname} DROP DEFAULT");

        // Drop the sequence providing the value
        // TODO: maybe this is not necessary
        $this->db->exec("DROP SEQUENCE {seqname}");

        $column->setSerial(false);
        $column->setDefault(null);
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
            case Column::INT:
            case Column::BIGINT:
                $coldef .= "(" . $col->getNumericPrecision() . ")";
                break;
            case Column::BOOLEAN:
                $coldef .= "(1)";
                break;
            case Column::DECIMAL:
                $coldef .= "(" . $col->getNumericPrecision() . "," . $col->getNumericScale() . ")";
        }

        $coldef .= $col->isNullable() ? " NULL " : " NOT NULL ";
        $def = $col->getDefault();
        if ($def)
            $coldef .= " DEFAULT " . $def;
        
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
            $type = strtolower($col['data_type']);
            $numtype = array_search($type, $this->mapping);

            $enum_values = null;
            if ($col['data_type'] === "USER-DEFINED")
            {
                $udt = $col['udt_name'];

                // Check if it is an enum
                $q = "SELECT enumlabel FROM pg_catalog.pg_type pt LEFT JOIN pg_catalog.pg_enum pe ON pe.enumtypid = pt.oid WHERE pt.typname = :enumname";
                $q = $this->db->prepare($q);
                $q->execute(array('enumname' => $udt));

                if ($q->rowCount() === 0)
                    throw new DBException("Unsupported field type: " . $type);

                $enum_values = array();
                foreach ($q as $r)
                    $enum_values[] = $r['enumlabel'];
                $numtype = Column::ENUM;
            }

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

            if ($enum_values !== null)
                $column->setEnumValues($enum_values);


            $table->addColumn($column);

            // Detect serial columns by the presence of nextval( While postgres
            // technically allows the use of more than one sequence per table
            // and also does not require it to be a primary key, it's the most
            // common use case.
            if ($col['column_default'] !== null)
            {
                if (substr($col['column_default'], 0, 8) === "nextval(")
                {
                    $sequence_name = substr($col['column_default'], 9, -12);
                    $column->setSerial(true);
                    $column->setDefault(null);
                }
            }
        }

        $constraints = $this->getConstraints($table_name);
        foreach ($constraints as $constraint)
        {
            if (isset($constraint['name']))
                $constraint['name'] = $this->stripPrefix($constraint['name']);
            $table->addIndex(new Index($constraint));
        }
        
        // Get update/delete policy from foreign keys
        $fks = $this->getForeignKeys($table_name);
        foreach ($fks as $fk)
        {
            $fk['name'] = $this->stripPrefix($fk['name']);
            $table->addForeignKey(new ForeignKey($fk));
        }

        $table->validate();

        return $table;
    }

    public function getForeignKeys($table_name)
    {
        $q = "
        SELECT conname,
            pg_catalog.pg_get_constraintdef(r.oid, true) as condef
        FROM pg_catalog.pg_constraint r
        WHERE r.conrelid = :table::regclass AND r.contype = 'f' ORDER BY 1;
        ";
        $q = $this->db->prepare($q);
        $tname = $this->getName($table_name, false);
        $q->execute(array("table" => $tname));

        $fks = array();
        foreach ($q as $row)
        {
            $name = $row['conname'];
            $def = $row['condef'];

            if (!preg_match('/^FOREIGN KEY \(([\w\d\s,_]+)\) REFERENCES ([\w\d]+)\(([\w\d\s,_]+)\)[\s]*(ON UPDATE (CASCADE|RESTRICT|SET NULL))?[\s]*(ON DELETE (CASCADE|RESTRICT|SET NULL))?$/', $def, $matches))
                throw new DBException("Invalid condef: " . $def);
            $columns = explode(", ", $matches[1]);
            $reftable = $matches[2];
            $refcolumns = explode(", ", $matches[3]);

            $update_policy = isset($matches[5]) ? $matches[5] : "RESTRICT";
            $delete_policy = isset($matches[7]) ? $matches[5] : "RESTRICT";

            $fks[] = array('name' => $name, 'column' => $columns, 'referred_table' => $reftable, 'referred_column' => $refcolumns, "on_update" => $update_policy, "on_delete" => $delete_policy);
        }

        return $fks;
    }

    public function getConstraints($table_name)
    {
        $q = "
        SELECT 
            c2.relname AS name, 
            i.indisprimary AS is_primary, 
            i.indisunique AS is_unique, 
            pg_catalog.pg_get_indexdef(i.indexrelid, 0, true) AS indexdef,
            pg_catalog.pg_get_constraintdef(con.oid, true) AS constraintdef
        FROM 
            pg_catalog.pg_class c, pg_catalog.pg_class c2, pg_catalog.pg_index i
        LEFT JOIN
            pg_catalog.pg_constraint con ON (conrelid = i.indrelid AND conindid = i.indexrelid AND contype IN ('p','u','x'))
        WHERE c.oid = :table::regclass AND c.oid = i.indrelid AND i.indexrelid = c2.oid
        ORDER BY i.indisprimary DESC, i.indisunique DESC, c2.relname;
        ";

        $table_name = $this->getName($table_name, false);
        $q = $this->db->prepare($q);
        $q->execute(array("table" => $table_name));

        $constraints = array();
        foreach ($q as $row)
        {
            $name = $row['name'];
            $primary = $row['is_primary'];
            $unique = $row['is_unique'];
            $indexdef = $row['indexdef'];
            $constraintdef = $row['constraintdef'];

            if ($primary)
            {
                if (!preg_match('/^PRIMARY KEY \(([\w\d\s,_]+)\)$/', $constraintdef, $matches))
                    throw new DBException("Invalid primary key: $constraintdef");

                $columns = explode(", ", $matches[1]);
                $constraints[] = array(
                    'type' => 'PRIMARY',
                    'column' => $columns
                );
            }
            elseif ($unique)
            {
                if (!preg_match('/^UNIQUE \(([\w\d\s,_]+)\)$/', $constraintdef, $matches))
                    throw new DBException("Invalid unique key: $constraintdef");

                $columns = explode(", ", $matches[1]);
                $constraints[] = array(
                    'type' => 'UNIQUE',
                    'column' => $columns,
                    'name' => $name
                );
            }
            else
            {
                $qname = preg_quote($name, '/');
                $tname = preg_quote($table_name, '/');
                if (!preg_match('/^CREATE INDEX ' . $qname . ' ON ' . $tname . ' (USING ([\w]+))?\s*\((.+)\)(\s*WHERE (.*))?$/', $indexdef, $matches))
                    throw new DBException("Invalid index: $indexdef");

                $algo = $matches[2];
                $columns = self::explodeFunc($matches[3]);
                $constraints[] = array(
                    'type' => 'INDEX',
                    'column' => $columns,
                    'name' => $name,
                    'algorithm' => $algo,
                    'condition' => isset($matches[5]) ? $matches[5] : null
                );
            }
        }

        return $constraints;
    }
}

// @codeCoverageIgnoreStart
PGSQL::setLogger();
// @codeCoverageIgnoreEnd
