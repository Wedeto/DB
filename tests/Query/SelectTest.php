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

use PHPUnit\Framework\TestCase;

use WASP\DB\Query\Builder as Q;

/**
 * @covers WASP\DB\Query\Select
 */
class SelectTest extends TestCase
{
    public function testSelect()
    {
        $q = Q::select(
            Q::field('barbaz'),
            Q::alias('foobar', 'baz'),
            Q::from('test', 't1'),
            Q::where(
                Q::equals(
                    'foo',
                    3
                )
            ),
            Q::join(
                Q::with('test2', 't2'),
                Q::on(
                    Q::equals(
                        Q::field('id', 't2'),
                        Q::field('id', 't1')
                    )
                )
            ),
            Q::order('foo'),
            Q::limit(10),
            Q::offset(5)
        );

        $this->assertInstanceOf(Select::class, $q);

        $wh = $q->getWhere();
        $this->assertInstanceOf(WhereClause::class, $wh);

        $joins = $q->getJoins();
        $this->assertEquals(1, count($joins));
        $this->assertInstanceOf(JoinClause::class, $joins[0]);

        $t = $q->getTable();
        $this->assertInstanceOf(SourceTableClause::class, $t);

        $fields = $q->getFields();
        $this->assertEquals(2, count($fields));
        $this->assertInstanceOf(FieldAlias::class, $fields[0]);
        $this->assertInstanceOf(FieldAlias::class, $fields[1]);

        $this->assertInstanceOf(LimitClause::class, $q->getLimit());
        $this->assertInstanceOf(OffsetClause::class, $q->getOffset());
        $this->assertInstanceOf(OrderClause::class, $q->getOrder());

        $cq = Select::countQuery($q);
        $this->assertInstanceOf(Select::class, $cq);

        $fields = $cq->getFields();
        $this->assertEquals(1, count($fields));
        $cnt = $fields[0];
        $this->assertInstanceOf(SQLFunction::class, $cnt->getExpression());
        $this->assertEquals("COUNT", $cnt->getExpression()->getFunction());
        $this->assertEquals($wh, $cq->getWhere());
        $this->assertEquals($joins, $cq->getJoins());
        $this->assertEquals($t, $cq->getTable());
    }

    public function testSelectFunctionConstruction()
    {
        $q = new Select;
        $q
            ->from("test_table")
            ->where(['foo' => 'bar'])
            ->join(Q::join(
                Q::with("test_table2"),
                Q::on(
                    Q::equals(
                        Q::Field("id", "test_table2"),
                        Q::Field("id", "test_table")
                    )
                )
            ))
            ->limit(30)
            ->offset(5);

        $t = $q->getTable();
        $this->assertInstanceOf(SourceTableClause::class, $t);
        $this->assertEquals("test_table", $t->getTable());

        $wh = $q->getWhere();
        $this->assertInstanceOf(WhereClause::class, $wh);

        $op = $wh->getOperand();
        $this->assertInstanceOf(ComparisonOperator::class, $op);

        $lhs = $op->getLHS();
        $this->assertInstanceOf(FieldName::class, $lhs);
        $this->assertEquals("foo", $lhs->getField());

        $rhs = $op->getRHS();
        $this->assertInstanceOf(ConstantValue::class, $rhs);
        $this->assertEquals("bar", $rhs->getValue());

        $l = $q->getLimit();
        $this->assertInstanceOf(LimitClause::class, $l);

        $l2 = $l->getLimit();
        $this->assertInstanceOf(ConstantValue::class, $l2);
        $this->assertEquals(30, $l2->getValue());

        $o = $q->getOffset();
        $this->assertInstanceOf(OffsetClause::class, $o);

        $o2 = $o->getOffset();
        $this->assertInstanceOf(ConstantValue::class, $o2);
        $this->assertEquals(5, $o2->getValue());
    }

    public function testGroupBySelect()
    {
        $q = new Select;

        $q
            ->add(new FieldName('id'))
            ->add(new FieldAlias(new SQLFunction('COUNT', '*'), 'ROWCOUNT'))
            ->from(new TableClause('test_table'))
            ->groupBy('id', new HavingClause(Q::greaterThan('ROWCOUNT', 10)));

        $t = $q->getTable();
        $this->assertInstanceOf(SourceTableClause::class, $t);
        $this->assertEquals('test_table', $t->getTable());

        $gb = $q->getGroupBy();
        $this->assertInstanceOf(GroupByClause::class, $gb);

        $h = $gb->getHaving();
        $this->assertInstanceOf(HavingClause::class, $h);

        $groups = $gb->getGroups();
        $this->assertEquals(1, count($groups));
        $this->assertInstanceOf(FieldName::class, $groups[0]);
        $this->assertEquals("id", $groups[0]->getField());

        $this->expectException(\WASP\DB\DBException::class);
        $this->expectExceptionMessage("Forming count query for queries including group by or union distinct is not supported");
        $cq = Select::countQuery($q);
    }

    public function testUnion()
    {
        $q = new Select;

        $q
            ->add(new FieldName('id'))
            ->from(new SourceTableClause('test_table'))
            ->where(Q::lessThan('id', 100));


        $q2 = new Select;
        $q2
            ->add(new FieldName('id'))
            ->from(new SourceTableClause('test_table'))
            ->where(Q::greaterThan('id', 1000));

        $uq = new UnionClause("", $q2);

        $q->setUnion($uq);
        $this->assertEquals($uq, $q->getUnion());

        $uq2 = new UnionClause("ALL", $q2);
        $q->add($uq2);
        $this->assertEquals($uq2, $q->getUnion());

        $cq = Select::countQuery($q);
        $this->assertInstanceOf(Select::class, $cq);
        $fields = $cq->getFields();
        $this->assertEquals(1, count($fields));
        $this->assertInstanceOf(ArithmeticOperator::class, $fields[0]->getExpression());
    }

    public function testInvalidArgument()
    {
        $q = new Select;
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Unknown clause");
        $q->add(new HavingClause(Q::equals('foo', 'bar')));
    }
}
