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

class Builder
{
    public static function select()
    {
        $s = new Select;
        foreach (func_get_args() as $arg)
        {
            $arg = \WASP\cast_array($arg);
            foreach ($arg as $arg_val)
                $s->add($arg_val);
        }

        return $s;
    }

    public function update()
    {
        $u = new Update;
        foreach (func_get_args() as $arg)
        {
            $arg = \WASP\cast_array($arg);
            foreach ($arg as $arg_val)
                $u->add($arg_val);
        }

        return $s;
    }

    public function delete()
    {
        $d = new Delete;
        foreach (func_get_args() as $arg)
        {
            $arg = \WASP\cast_array($arg);
            foreach ($arg as $arg_val)
                $d->add($arg_val);
        }

        return $d;
    }

    public function insert()
    {
        $i = new Delete;
        foreach (func_get_args() as $arg)
        {
            $arg = \WASP\cast_array($arg);
            foreach ($arg as $arg_val)
                $i->add($arg_val);
        }

        return $i;
    }

    public static function where(Expression $operand)
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

    public static function order()
    {
        $cl = new OrderClause;
        foreach (func_get_args() as $arg)
            $cl->addClause($arg);
        return $cl;
    }

    public static function get($expression, $alias = "")
    {
        return new GetClause($expression, $alias);
    }

    public static function field($field, $table = null)
    {
        return new FieldName($field, $table);
    }

    public static function func($func)
    {
        return new SQLFunction($func);
    }

    public static function table($table, $alias = "")
    {
        return new TableClause($table, $alias);
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

    public static function wildcard()
    {
        return new Wildcard();
    }
}
