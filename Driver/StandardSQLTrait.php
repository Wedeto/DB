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

use WASP\DB\DBException;
use WASP\DB\Query\Clause;
use WASP\DB\Query\ConstantValue;
use WASP\DB\Query\ConstantArray;
use WASP\DB\Query\CustomSQL;
use WASP\DB\Query\Delete;
use WASP\DB\Query\Direction;
use WASP\DB\Query\EqualsOneOf;
use WASP\DB\Query\FieldName;
use WASP\DB\Query\GetClause;
use WASP\DB\Query\GroupByClause;
use WASP\DB\Query\HavingClause;
use WASP\DB\Query\Insert;
use WASP\DB\Query\JoinClause;
use WASP\DB\Query\LimitClause;
use WASP\DB\Query\OffsetClause;
use WASP\DB\Query\Operator;
use WASP\DB\Query\OrderClause;
use WASP\DB\Query\Query;
use WASP\DB\Query\Select;
use WASP\DB\Query\SourceTableClause;
use WASP\DB\Query\SQLFunction;
use WASP\DB\Query\SubQuery;
use WASP\DB\Query\Parameters;
use WASP\DB\Query\TableClause;
use WASP\DB\Query\UnionClause;
use WASP\DB\Query\Update;
use WASP\DB\Query\UpdateField;
use WASP\DB\Query\WhereClause;
use WASP\DB\Query\Wildcard;

use OutOfRangeException;

trait StandardSQLTrait
{
    /**
     * Write an operator expression as SQL query syntax
     * @param Parameters $params The query parameters: tables and placeholder values
     * @param Operator $expression The operator to write
     * @param bool $inner_clause Whether this is a inner or outer clause. An
     *                           inner clause will be wrapped in braces when
     *                           it's a binary operator.
     * @return string The generated SQL
     */
    public function operatorToSQL(Parameters $params, Operator $expression, bool $inner_clause)
    {
        $lhs = $this->toSQL($params, $expression->getLHS(), true);
        $rhs = $this->toSQL($params, $expression->getRHS(), true);

        $op = $expression->getOperator();
        if ($lhs !== null)
        {
            $sql = $lhs . ' ' . $op . ' ' . $rhs;
            if ($inner_clause)
                return '(' . $sql . ')';
            return $sql;
        }
        
        return $op . ' ' . $rhs;
    }

    /**
     * Write a constant as SQL query syntax
     * @param Parameters $params The query parameters: tables and placeholder values
     * @param ConstantValue $expression The constant to write
     * @return string The generated SQL
     */
    public function constantToSQL(Parameters $params, ConstantValue $expression)
    {
        if ($expression instanceof ConstantArray)
            return $this->constantArrayToSQL($params, $expression);

        if ($key = $expression->getKey())
        {
            try
            {
                $v = $params->get($key);
            }
            catch (OutOfRangeException $e)
            {
                $key = null;
            }
        }

        if ($key === null)
        {
            $val = $expression->getValue();
            if ($val instanceof \DateTimeInterface)
                $val = $val->format(\DateTimeInterface::ATOM);
            elseif ($val === false)
                $val = 0;
            $key = $params->assign($val);
        }
        $expression->bind($params, $key, null);

        return ':' . $key;
    }

    /**
     * Write a field name as SQL query syntax
     * @param Parameters $params The query parameters: tables and placeholder values
     * @param FieldName $expression The field to write
     * @return string The generated SQL
     */
    public function fieldToSQL(Parameters $params, FieldName $expression)
    {
        $field = $expression->getField();
        $table = $expression->getTable();
        if (empty($table))
            $table = $params->getDefaultTable();

        if (!empty($table))
        {
            list($table, $alias) = $params->resolveTable($table->getPrefix());
            if ($alias)
                $table_ref = $this->identQuote($alias);
            else
                $table_ref = $this->getName($table);

            return $table_ref . '.' . $this->identQuote($field);
        }

        return $this->identQuote($field);
    }

    /**
     * Write a function as SQL query syntax
     * @param Parameters $params The query parameters: tables and placeholder values
     * @param SQLFunction $expression The function to write
     * @return string The generated SQL
     */
    public function functionToSQL(Parameters $params, SQLFunction $expression)
    {
        $func = $expression->getFunction();
        $arguments = $expression->getArguments();
        
        $args = array();
        foreach ($arguments as $arg)
            $args[] = $this->toSQL($params, $arg, false);

        return $func . '(' . implode(', ', $args) . ')';
    }

    /**
     * Write an function as SQL query syntax
     * @param Parameters $params The query parameters: tables and placeholder values
     * @param TableClause $table The table to write
     * @return string The generated SQL
     */
    public function tableToSQL(Parameters $params, TableClause $table)
    {
        if ($table instanceof SourceSubQuery)
        {
            $subquery = $table->getSubQuery();
            $alias = $table->getAlias();

            if (!empty($alias))
                throw new DBException("A subquery must have an alias");

            $sql = $this->subqueryToSQL($params, $subquery);
            return $sql . ' AS ' . $this->identQuote($alias);
        }

        $name = $table->getTable();
        $alias = $table->getAlias();
        $prefix_disabled = $table->getDisablePrefixing();

        if ($table instanceof SourceTableClause)
        {
            $sql = $prefix_disabled ? $this->identQuote($name) : $this->getName($name);
            if (!empty($alias))
            {
                $params->registerTable($name, $alias);
                $sql .= ' AS ' . $this->identQuote($alias);
            }
            else
                $params->registerTable($name, null);

            return $sql;
        }

        list($tname, $talias) = $params->resolveTable($name, $alias);
        if ($talias !== $tname)
            return $this->identQuote($talias);
        return $prefix_disabled ? $this->identQuote($tname) : $this->getName($tname);
    }

    /**
     * Write a update assignment as SQL query syntax
     * @param Parameters $params THe query parameters: tables and placeholder values
     * @param UpdateField $update The field to update and the new value
     * @return string The generated SQL
     */
    public function updateFieldToSQL(Parameters $params, UpdateField $update)
    {
        $fieldname = $this->toSQL($params, $update->getField());
        $value = $this->toSQL($params, $update->getValue());

        return $fieldname . ' = ' . $value;
    }

    /**
     * Write a sub query as SQL query syntax
     * @param Parameters $params The query parameters: tables and placeholder values
     * @param SubQuery $query The query to write
     * @return string The generated SQL
     */
    public function subqueryToSQL(Parameters $params, SubQuery $expression)
    {
        $q = $expression->getQuery();
        return '(' . $this->toSQL($params, $q, false) . ')';
    }

    /**
     * Write a query as SQL query syntax
     * @param Parameters $params The query parameters: tables and placeholder values
     * @param Query $query The query to write
     * @return string The generated SQL
     */
    public function queryToSQL(Parameters $params, Query $query)
    {
        if ($query instanceof Select)
            return $this->selectToSQL($params, $query);
        if ($query instanceof Update)
            return $this->updateToSQL($params, $query);
        if ($query instanceof Delete)
            return $this->deleteToSQL($params, $query);
        if ($query instanceof Insert)
            return $this->insertToSQL($params, $query);
    }

    /**
     * Write a select query as SQL query syntax
     * @param Parameters $params The query parameters: tables and placeholder values
     * @param Select $query The select query to write
     * @return string The generated SQL
     */
    public function selectToSQL(Parameters $params, Select $query)
    {
        // First get the source tables: FROM and JOIN clauses
        $source = array();

        if ($table = $query->getTable())
            $source[] = "FROM " . $this->tableToSQL($params, $table);

        foreach ($query->getJoins() as $join)
            $source[] = $this->joinToSQL($params, $join);

        // Now build the start of the query, all tables should be known
        $parts = array();

        $parts[] = "SELECT";
        $fields = $query->getFields();
        if (!empty($fields))
        {
            $field_parts = array();
            foreach ($fields as $field)
                $field_parts[] = $this->toSQL($params, $field);
            $parts[] = implode(", ", $field_parts);
        }
        else
            $parts[] = "*";

        // Add the source tables and joins to the query
        foreach ($source as $part)
            $parts[] = $part;

        if ($where = $query->getWhere())
            $parts[] = $this->whereToSQL($params, $where);

        if ($union = $query->getUnion())
            $parts[] = $this->unionToSQL($params, $union);
        
        if ($order = $query->getOrder())
            $parts[] = $this->orderToSQL($params, $order);

        if ($limit = $query->getLimit())
            $parts[] = $this->limitToSQL($params, $limit);
        
        if ($offset = $query->getOffset())
            $parts[] = $this->offsetToSQL($params, $offset);
        
        return implode(" ", $parts);
    }

    public function deleteToSQL(Parameters $params, Delete $delete)
    {
        return "DELETE FROM " . $this->tableToSQL($params, $delete->getTable()) . " " . $this->whereToSQL($params, $delete->getWhere());
    }

    public function updateToSQL(Parameters $params, Update $update)
    {
        $query = array("UPDATE");
        $query[] = $this->tableToSQL($params, $update->getTable());
        foreach ($update->getJoins() as $join)
            $query[] = $this->joinToSQL($params, $join);

        $query[] = "SET";
        $updates = array();
        foreach ($update->getUpdates() as $update_fld)
            $updates[] = $this->updateFieldToSQL($params, $update_fld);
        $query[] = implode(", ", $updates);
        if (count($updates) === 0)
            throw new DBException("Nothing to update");
        
        $where = $update->getWhere();
        if ($where)
            $query[] = $this->whereToSQL($params, $where);

        return implode(" ", $query);
    }

    public function insertToSQL(Parameters $params, Insert $insert)
    {
        $query = array("INSERT INTO");
        $query[] = $this->tableToSQL($params, $insert->getTable());

        $fields = $insert->getFields();
        foreach ($fields as $key => $field)
            $fields[$key] = $this->fieldToSQL($params, $field);

        $query[] = '(' . implode(', ', $fields) . ')';
        $query[] = 'VALUES';

        $values = $insert->getValues();
        foreach ($values as $key => $value)
            $values[$key] = $this->toSQL($params, $value);

        $query[] = '(' . implode(', ', $values) . ')';

        $dup = $insert->getOnDuplicate();
        if ($dup)
            $query[] = $this->duplicateKeyToSQL($params, $dup);

        return implode(' ', $query);
    }

    public function equalsOneOfToSQL(Parameters $params, EqualsOneOf $matcher, bool $inner_clause)
    {
        $comparator = $this->matchMultipleValues($matcher->getField(), $matcher->getList());
        return $this->toSQL($params, $comparator, $inner_clause);
    }

    /**
     * Write a constant array clause as SQL query syntax
     * @param Parameters $params The query parameters: tables and placeholder values
     * @param Direction $dir The constant array-clause to write
     * @return string The generated SQL
     */
    public function constantArrayToSQL(Parameters $params, ConstantArray $list)
    {
        if ($key = $list->getKey())
        {
            try
            {
                $key = $params->get($key);
            }
            catch (OutOfRangeException $e)
            {
                // Not a valid key, replace
                $key = null;
            }
        }

        if (!$key)
            $key = $params->assign(null);

        // Rebind, to be sure
        $list->bind($params, $key, array($this, 'formatArray'));
        return ':' . $key;
    }

    /**
     * Add a custom SQL string to the query
     * @param Parameters $params The query parameters: tables and placeholder values
     * @param CustomSQL $custom The object containing the custom SQL
     * @return string The generated SQL
     */
    public function customToSQL(Parameters $params, CustomSQL $custom, $inner_clause)
    {
        if ($inner_clause)
            return '(' . $custom->getSQL() . ')';
        return $custom->getSQL();
    }

    /**
     * Write a order clause as SQL query syntax
     * @param Parameters $params The query parameters: tables and placeholder values
     * @param Order $order The ORDER-clause to write
     * @return string The generated SQL
     */
    public function orderToSQL(Parameters $params, OrderClause $order)
    {
        $clauses = $order->getClauses();

        $strs = array();
        foreach ($clauses as $clause)
            $strs[] = $this->toSQL($params, $clause);

        if (count($strs) === 0)
            return;

        return "ORDER BY " . implode(", ", $strs);
    }

    /**
     * Write a order direction clause as SQL query syntax
     * @param Parameters $params The query parameters: tables and placeholder values
     * @param Direction $dir The partof the ORDER-clause to write
     * @return string The generated SQL
     */
    public function directionToSQL(Parameters $params, Direction $dir)
    {
        $expr = $dir->getOperand();
        $direction = $dir->getDirection();

        return $this->toSQL($params, $expr, false) . " " . $direction;
    }

    /**
     * Write a select return clause as SQL query syntax
     * @param Parameters $params The query parameters: tables and placeholder values
     * @param GetClause $query The select return clause to write
     * @return string The generated SQL
     */
    public function getToSQL(Parameters $params, GetClause $get)
    {
        $expr = $get->getExpression();
        $alias = $get->getAlias();
        $sql = $this->toSQL($params, $expr, true);
        if (empty($alias))
        {
            if ($expr instanceof FieldName)
            {
                $table = $expr->getTable();
                if (!empty($table))
                {
                    $prefix = $table->getPrefix();
                    $this->alias = $prefix . '_' . $expr->getField();
                }
            }
            elseif ($expr instanceof FunctionExpression)
            {
                $func = $expr->getFunction();
                $alias = strtolower($func);
            }
        }

        if ($alias)
            return $sql . ' AS ' . $params->getDB()->identQuote($alias);
        else
            return $sql;
    }

    /**
     * Write a UNION clause as SQL query synta
     * @param Parameters $params The query parameters: tables and placeholder values
     * @return string The generated SQL
     */
    public function unionToSQL(Parameters $params, UnionClause $union)
    {
        $q = $union->getQuery();
        $t = $union->getType();

        return $t . ' (' . $this->selectToSQL($params, $q) . ')';
    }

    /**
     * Write a GROUPBY clause as SQL query syntax
     * @param Parameters $params The query parameters: tables and placeholder values
     * @param GroupByClause $groupby The GROUP BY clause
     * @return string The generated SQL
     */
    public function groupByToSQL(Parameters $params, GroupByClause $groupby)
    {
        $groups = $groupby->getGroups();
        $having = $groupby->getHaving();

        if (count($groups) === 0)
            throw new \InvalidArgumentException("No groups in GROUP BY clause");

        $parts = array();
        foreach ($groups as $group)
        {
            $parts[] = $this->toSQL($group);
        }
        
        $having = !empty($having) ? $this->havingToSQL($params, $having) : "";

        return "GROUP BY " . implode(", ", $parts) . $having;
    }

    public function havingToSQL(Parameters $params, HavingClause $having)
    {
        return "HAVING " . $this->toSQL($params, $cond);
    }

    /**
     * Write a LIMIT clause to SQL query syntax
     * @param Parameters $params The query parameters: tables and placeholder values
     * @param LimitClause $limit The select LIMIT clause to write
     * @return string The generated SQL
     */
    public function limitToSQL(Parameters $params, LimitClause $limit)
    {
        return "LIMIT " . $this->toSQL($params, $limit->getLimit());
    }

    /**
     * Write a OFFSET clause to SQL query syntax
     * @param Parameters $params The query parameters: tables and placeholder values
     * @param OffsetClause $offset The select OFFSET clause to write
     * @return string The generated SQL
     */
    public function offsetToSQL(Parameters $params, OffsetClause $offset)
    {
        return "OFFSET " . $this->toSQL($params, $offset->getOffset());
    }

    /**
     * Write a JOIN clause to SQL query syntax
     * @param Parameters $params The query parameters: tables and placeholder values
     * @param JoinClause $join The JOIN clause to write
     * @return string The generated SQL
     */
    public function joinToSQL(Parameters $params, JoinClause $join)
    {
        return $join->getType() . " JOIN " . $this->toSQL($params, $join->getTable()) . " ON " . $this->toSQL($params, $join->getCondition());
    }

    public function whereToSQL(Parameters $params, WhereClause $where)
    {
        return "WHERE " . $this->toSQL($params, $where->getOperand());
    }
}
