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

use DomainException;
use WASP\DB\DBException;

class Select extends Query
{
    protected $fields = array();
    protected $table;
    protected $joins = array();
    protected $where;
    protected $groupby = null;
    protected $union = null;
    protected $order;
    protected $limit;
    protected $offset;

    /**
     * Form a COUNT-query: a query that has the same conditions but
     * only counts the number of matching rows.
     *
     * This function only works for queries that do not use GROUP BY
     * or UNION DISTINCT, because those queries make it impossible to 
     *
     * For UNION DISTINCT: you probably want to get the count query of the main
     * query and the UNION query separately and add them together, but you are
     * still counting duplicates then. Switching to UNION ALL makes this function work.
     *
     * For GROUP BY: you need to find what count you actually need - there is no
     * direct link between the number of rows in the table and the number of rows in the result.
     */
    public static function countQuery(Select $query)
    {
        if ((!empty($query->union) && $query->union->getType() !== "ALL") || !empty($query->groupby))
            throw new DBException("Forming count query for queries including group by or union distinct is not supported");

        $cq = new Select;
        $count_func = new SQLFunction("COUNT");
        $count_func->addArgument(new Wildcard());
        $cq->fields = array(new FieldAlias($count_func, "COUNT"));
        $cq->table = $query->table;
        $cq->joins = $query->joins;
        $cq->where = $query->where;

        if (!empty($query->union))
        {
            $rcq = self::countQuery($query->union->getQuery());
            
            $main_q = new Select;
            $add_q = new ArithmeticOperator("+", new SubQuery($cq), new SubQuery($rcq));
            $main_q->add(new FieldAlias($add_q, "COUNT"));
            return $main_q;
        }

        return $cq;
    }

    public function add(Clause $clause)
    {
        if ($clause instanceof WhereClause)
            $this->where = $clause;
        elseif ($clause instanceof TableClause)
            $this->table = $clause;
        elseif ($clause instanceof FieldAlias)
            $this->fields[] = $clause;
        elseif ($clause instanceof FieldName)
            $this->fields[] = new FieldAlias($clause, "");
        elseif ($clause instanceof JoinClause)
            $this->joins[] = $clause;
        elseif ($clause instanceof GroupByClause)
            $this->groupby = $clause;
        elseif ($clause instanceof UnionClause)
            $this->union = $clause;
        elseif ($clause instanceof OrderClause)
            $this->order = $clause;
        elseif ($clause instanceof LimitClause)
            $this->limit = $clause;
        elseif ($clause instanceof OffsetClause)
            $this->offset = $clause;
        else
            throw new DomainException("Unknown clause: " . get_class($clause));

        return $this;
    }

    public function from($from)
    {
        if (!($from instanceof SourceTableClause))
            $from = new SourceTableClause($from);
        return $this->add($from);
    }

    public function where($where)
    {
        if (!($where instanceof WhereClause))
            $where = new WhereClause($where);
        return $this->add($where);
    }

    public function join(JoinClause $join)
    {
        return $this->add($join);
    }

    public function limit($limit)
    {
        if (!($limit instanceof LimitClause))
            $limit = new LimitClause($limit);
        return $this->add($limit);
    }

    public function offset($offset)
    {
        if (!($offset instanceof OffsetClause))
            $offset = new OffsetClause($offset);
        return $this->add($offset);
    }

    public function groupBy($clause)
    {
        if (!($clause instanceof GroupByClause))
            $clause = new GroupByClause(func_get_args());
        return $this->add($clause);
    }
    
    public function setUnion(UnionClause $union)
    {
        $this->union = $union;
    }

    public function getFields()
    {
        return $this->fields;
    }

    public function getTable()
    {
        return $this->table;
    }

    public function getJoins()
    {
        return $this->joins;
    }

    public function getWhere()
    {
        return $this->where;
    }

    public function getGroupBy()
    {
        return $this->groupby;
    }

    public function getUnion()
    {
        return $this->union;
    }

    public function getOrder()
    {
        return $this->order;
    }

    public function getLimit()
    {
        return $this->limit;
    }

    public function getOffset()
    {
        return $this->offset;
    }
}
