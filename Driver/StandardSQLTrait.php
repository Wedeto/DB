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

use WASP\DB\Query\Clause;
use WASP\DB\Query\Query;
use WASP\DB\Query\Select;
use WASP\DB\Query\GetClause;
use WASP\DB\Query\TableClause;
use WASP\DB\Query\JoinClause;
use WASP\DB\Query\WhereClause;
use WASP\DB\Query\LimitClause;
use WASP\DB\Query\OffsetClause;
use WASP\DB\Query\SubQuery;
use WASP\DB\Query\Operator;
use WASP\DB\Query\FieldName;
use WASP\DB\Query\ConstantValue;
use WASP\DB\Query\SQLFunction;

trait StandardSQL
{
    /**
     * Write an query clause as SQL query syntax
     * @param Parameters $params The query parameters: tables and placeholder values
     * @param Clause $clause The clause to write
     * @param bool $inner_clause Whether this is a inner or outer clause. An
     *                           inner clause will be wrapped in braces when
     *                           it's a binary operator.
     * @return string The generated SQL
     */
    public function toSQL(Parameters $params, Clause $clause, bool $inner_clause = false)
    {
        if ($clause instanceof Query)
            return $this->queryToSQL($params, $clause);

        if ($clause instanceof GetClause)
            return $this->getToSQL($params, $clause);

        if ($clause instanceof TableClause)
            return $this->tableToSQL($params, $clause);

        if ($clause instanceof WhereClause)
            return $this->whereToSQL($params, $clause);

        if ($clause instanceof OrderClause)
            return $this->orderToSQL($params, $clause);

        if ($clause instanceof LimitClause)
            return $this->limitToSQL($params, $clause);

        if ($clause instanceof OffsetClause)
            return $this->offsetToSQL($params, $clause);

        if ($clause instanceof ConstantValue)
            return $this->constantToSQL($params, $clause);

        if ($clause instanceof Operator)
            return $this->operatorToSQL($params, $clause, $inner_clause);

        if ($clause instanceof SQLFunction)
            return $this->functionToSQL($params, $clause);

        if ($clause instanceof SubQuery)
            return $this->subQueryToSQL($params, $clause);

        if ($clause instanceof FieldName)
            return $this->fieldToSQL($params, $clause);

        throw new \InvalidArgumentException("Unknown clause: " . get_class($clause));
    }

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
        $lhs !== null && $inner_clause)
            return '(' . $lhs . ' ' . $op . ' ' . $rhs . ')';
        
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

        $key = $params->assign($expression->getValue());
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
        list($table, $alias) = $params->resolveTable($table);
        if ($alias)
            $table_ref = $this->identQuote($alias);
        else
            $table_ref = $this->getName($table);

        return $table_ref . '.' . $this->identQuote($field);
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
            $arg[] = $this->toSQL($params, $arg, false);

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
        $name = $table->getTable();
        $alias = $table->getAlias();

        if ($table instanceof SourceTableClause)
        {
            $sql = $this->getName($name);
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
        return $this->getName($tname);
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
            $source[] = "FROM " . $this->tableToSQL($table);

        foreach ($this->joins as $join)
            $source[] = $join->toSQL($params);

        // Now build the start of the query, all tables should be known
        $query = array();

        $query[] = "SELECT";
        if (!empty($this->fields))
        {
            $parts = array();
            foreach ($this->fields as $field)
                $parts[] = $field->toSQL($params);
            $query[] = implode(", ", $parts);
        }
        else
            $query[] = "*";

        // Add the source tables and joins to the query
        foreach ($source as $part)
            $query[] = $part;

        if ($this->where)
            $query[] = $this->where->toSQL($params);
        
        if ($this->order)
            $query[] = $this->order->toSQL($params);

        if ($this->limit)
            $query[] = $this->limit->toSQL($params);

        if ($this->offset)
            $query[] = $this->offset->toSQL($params);
        
        return implode(" ", $query);
    }

    /**
     * Write a constant array clause as SQL query syntax
     * @param Parameters $params The query parameters: tables and placeholder values
     * @param Direction $dir The constant array-clause to write
     * @return string The generated SQL
     */
    public function constantArrayToSQL(Parameters $params, ConstantArray $list)
    {
        $values = $list->getValues();
        $value = '{' . implode(',', $values) . '}';

        $key = $params->assign($value);
        return 'ANY(:' . $key . ')';
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
                    $this->alias = $prefix . '_' . $this->expression->getField();
                }
            }
            elseif ($expr instanceof FunctionExpression)
            {
                $func = $this->expression->getFunction();
                $alias = strtolower($func);
            }
        }

        if ($alias)
            return $sql . ' AS ' . $params->getDB()->identQuote($alias);
        else
            return $sql;
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
        return "OFFSET " . $this->toSQL($params, $offset->getLimit());
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
