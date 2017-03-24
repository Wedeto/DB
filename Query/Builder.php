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

namespace WASP\DB\Query;

use WASP\Util\Functions as WF;

class Builder
{
    public static function select()
    {
        $s = new Select;
        $non_field = false;
        foreach (func_get_args() as $arg)
        {
            $arg = WF::cast_array($arg);
            foreach ($arg as $arg_val)
            {
                if (!is_string($arg_val) && !($arg_val instanceof FieldName) && !($arg_val instanceof FieldAlias))
                    $non_field = true;
                if ($non_field === false && is_string($arg_val))
                    $arg_val = new FieldName($arg_val);

                $s->add($arg_val);
            }
        }

        return $s;
    }

    public static function update()
    {
        $u = new Update;
        foreach (func_get_args() as $arg)
        {
            $arg = WF::cast_array($arg);
            foreach ($arg as $arg_val)
                $u->add($arg_val);
        }

        return $u;
    }

    public static function delete($table, $where)
    {
        return new Delete($table, $where);
    }

    public static function insert($table, $record, $id_field = "")
    {
        return new Insert($table, $record, $id_field);
    }

    public static function where($operand)
    {
        return new WhereClause($operand);
    }

    public static function or(Expression $lhs, Expression $rhs)
    {
        return new BooleanOperator("OR", $lhs, $rhs);
    }

    public static function and(Expression $lhs, Expression $rhs)
    {
        return new BooleanOperator("AND", $lhs, $rhs);
    }

    public static function not(Expression $operand)
    {
        return new UnaryOperator("NOT", $operand);
    }

    public static function equals($lhs, $rhs)
    {
        return new ComparisonOperator('=', $lhs, $rhs);
    }

    public static function like($lhs, $rhs)
    {
        return new ComparisonOperator('LIKE', $lhs, '%' . $rhs . '%');
    }

    public static function ilike($lhs, $rhs)
    {
        return new ComparisonOperator('ILIKE', $lhs, '%' . $rhs . '%');
    }

    public static function greaterThan($lhs, $rhs)
    {
        return new ComparisonOperator('>', $lhs, $rhs);
    }

    public static function greaterThanOrEquals($lhs, $rhs)
    {
        return new ComparisonOperator('>=', $lhs, $rhs);
    }

    public static function lessThan($lhs, $rhs)
    {
        return new ComparisonOperator('<', $lhs, $rhs);
    }

    public static function lessThanOrEquals($lhs, $rhs)
    {
        return new ComparisonOperator('<=', $lhs, $rhs);
    }

    public static function operator($op, $lhs, $rhs)
    {
        return new ComparisonOperator($op, $lhs, $rhs);
    }

    public static function ascending($operand)
    {
        return new Direction("ASC", $operand);
    }

    public static function descending($operand)
    {
        return new Direction("DESC", $operand);
    }

    public static function order($order, ...$args)
    {
        if (is_string($order) && count($args) === 0)
            return new OrderClause(new CustomSQL($order));

        return new OrderClause($args);
    }

    public static function alias($expression, string $alias)
    {
        return new FieldAlias($expression, $alias);
    }

    public static function field($field, $table = null)
    {
        return new FieldName($field, $table);
    }

    public static function func($func, ...$args)
    {
        return new SQLFunction($func, $args);
    }

    public static function table($table, $alias = "")
    {
        return new TableClause($table, $alias);
    }

    public static function into($table, $alias = "")
    {
        return new SourceTableClause($table, $alias);
    }

    public static function to($table, $alias = "")
    {
        return new SourceTableClause($table, $alias);
    }

    public static function from($table, $alias = "")
    {
        return new SourceTableClause($table, $alias);
    }

    public static function with($table, $alias = "")
    {
        return new SourceTableClause($table, $alias);
    }

    public static function limit($count)
    {
        return new LimitClause($count);
    }

    public static function offset($offset)
    {
        return new OffsetClause($offset);
    }

    public static function join($table, $condition)
    {
        return new JoinClause("LEFT", $table, $condition);
    }

    public static function on(Expression $expression)
    {
        return $expression; 
    }

    public static function t($table_alias)
    {
        return new TableClause($table_alias);
    }

    public static function any($field, ...$params)
    {
        return new EqualsOneOf($field, $params);
    }
    
    public static function variable($value)
    {
        return new ConstantValue($value);
    }

    public static function wildcard()
    {
        return new Wildcard();
    }

    public static function null()
    {
        return new NullValue();
    }

    public static function nulleq($lhs, $rhs)
    {
        if (is_null($rhs) || is_scalar($rhs))
            $rhs = self::variable($rhs);

        return 
            self::or(
                self::operator("=", $lhs, $rhs),
                self::and(
                    self::operator("IS", $lhs, self::null()),
                    self::operator("IS", $rhs, self::null())
                )
            );
    }

    public static function increment($field, $amount = 1)
    {
        if (!($field instanceof $field))
            $field = new FieldName($field);
        return new UpdateField($field, new ArithmeticOperator('+', $field, 1));
    }

    public static function decrement($field, $amount = 1)
    {
        return self::increment($field, -$amount);
    }

    public static function arithmetic($operator, $lhs, $rhs)
    {
        return new ArhimeticOperator('+', $lhs, $rhs);
    }

    public static function calc($operator, $lhs, $rhs)
    {
        return self::arithmetic($operator, $lhs, $rhs);
    }

    public static function groupBy(...$parameters)
    {
        return new GroupBy($parameters);
    }

    public static function having($condition)
    {
        return new HavingClause($condition);
    }

    public static function union(Select $union)
    {
        return new UnionClause("DISTINCT", $union);
    }

    public static function unionAll(Select $union)
    {
        return new UnionClause("ALL", $union);
    }

    public static function subquery(Select $query, $alias = null)
    {
        if (!empty($alias))
            return new FieldAlias(new SubQuery($query), $alias);

        return new SubQuery($query);
    }
}
