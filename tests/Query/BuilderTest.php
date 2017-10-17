<?php
/*
This is part of Wedeto, the WEb DEvelopment TOolkit.
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

namespace Wedeto\DB\Query;

use PHPUnit\Framework\TestCase;

use Wedeto\DB\Query\Builder as Q;

/**
 * @covers Wedeto\DB\Query\Builder
 */
class BuilderTest extends TestCase
{
    public function testSelectQuery()
    {
        $q = Q::select(
            Q::field('foo'),
            Q::from('test'),
            Q::where(['bar' => 'baz']),
            Q::order(['val' => 'ASC', 'val2' => 'DESC'])
        );

        $this->assertInstanceOf(Select::class, $q);
        $this->assertEquals(1, count($q->getFields()));

        $order = $q->getOrder();
        $clauses = $order->getClauses();
        $this->assertEquals(2, count($clauses));

        $cl1 = $clauses[0];
        $this->assertEquals('val', $cl1->getOperand()->getField());
        $this->assertEquals('ASC', $cl1->getDirection());

        $cl2 = $clauses[1];
        $this->assertEquals('val2', $cl2->getOperand()->getField());
        $this->assertEquals('DESC', $cl2->getDirection());

        $q = Q::select(
            'foo',
            'bar',
            'val',
            'val2',
            Q::from('test'),
            Q::where(['bar' => 'baz']),
            Q::order(['val' => 'ASC', 'val2' => 'DESC'])
        );

        $this->assertInstanceOf(Select::class, $q);
        $this->assertEquals(4, count($q->getFields()));
    }

    public function testUpdateQuery()
    {
        $q = Q::update(
            Q::into("testtable"),
            Q::set('foo', 'bar'),
            Q::increment('test'),
            Q::where(['id' => 1])
        );
        
        $this->assertInstanceOf(Update::class, $q);
        
        $this->assertInstanceOf(SourceTableClause::class, $q->getTable());
        $this->assertEquals('testtable', $q->getTable()->getTable());

        $updates = $q->getUpdates();
        $this->assertEquals(2, count($updates));
        $this->assertInstanceOf(UpdateField::class, $updates[0]);
        $this->assertInstanceOf(UpdateField::class, $updates[1]);

        $this->assertEquals('foo', $updates[0]->getField()->getField());
        $this->assertEquals('test', $updates[1]->getField()->getField());

        $this->assertEquals('bar', $updates[0]->getValue()->getValue());
        $this->assertInstanceOf(ArithmeticOperator::class, $updates[1]->getValue());
        $this->assertInstanceOf(FieldName::class, $updates[1]->getValue()->getLHS());
        $this->assertInstanceOf(ConstantValue::class, $updates[1]->getValue()->getRHS());
        $this->assertEquals(1, $updates[1]->getValue()->getRHS()->getValue());

        $where = $q->getWhere();
        $this->assertInstanceOf(WhereClause::class, $where);
        $this->assertInstanceOf(ComparisonOperator::class, $where->getOperand());
        $this->assertEquals('=', $where->getOperand()->getOperator());
        $this->assertInstanceOf(FieldName::class, $where->getOperand()->getLHS());
        $this->assertEquals('id', $where->getOperand()->getLHS()->getField());
        $this->assertInstanceOf(ConstantValue::class, $where->getOperand()->getRHS());
        $this->assertEquals(1, $where->getOperand()->getRHS()->getValue());
    }

    public function testSet()
    {
        $s = Q::set('foo', 'bar');
        $this->assertInstanceOf(UpdateField::class, $s);
        $this->assertInstanceOf(FieldName::class, $s->getField());
        $this->assertEquals('foo', $s->getField()->getField());
        $this->assertInstanceOf(ConstantValue::class, $s->getValue());
        $this->assertEquals('bar', $s->getValue()->getValue());

        $s = Q::set('foo', Q::add(Q::field('field1'), Q::field('field2')));
        $this->assertInstanceOf(UpdateField::Class, $s);
        $this->assertEquals('foo', $s->getField()->getField());
        $this->assertInstanceOf(ArithmeticOperator::class, $s->getValue());
        $this->assertEquals('+', $s->getValue()->getOperator());
        $this->assertInstanceOf(FieldName::class, $s->getValue()->getLHS());
        $this->assertEquals('field1', $s->getValue()->getLHS()->getField());
        $this->assertInstanceOf(FieldName::class, $s->getValue()->getRHS());
        $this->assertEquals('field2', $s->getValue()->getRHS()->getField());
    }

    public function testDelete()
    {
        $q = Q::delete(
            Q::from('test_table'),
            Q::where(Q::greaterThan('foo', 5))
        );

        $this->assertInstanceOf(Delete::class, $q);
        $this->assertInstanceOf(SourceTableClause::class, $q->getTable());
        $this->assertEquals('test_table', $q->getTable()->getTable());

        $where = $q->getWhere();
        $this->assertInstanceOf(WhereClause::class, $where);
        $this->assertInstanceOf(ComparisonOperator::class, $where->getOperand());
        $this->assertInstanceOf(FieldName::class, $where->getOperand()->getLHS());
        $this->assertEquals('foo', $where->getOperand()->getLHS()->getField());
        $this->assertInstanceOf(ConstantValue::class, $where->getOperand()->getRHS());
        $this->assertEquals(5, $where->getOperand()->getRHS()->getValue());
    }

    public function testInsert()
    {
        $q = Q::insert(
            Q::into('test_table'),
            ['foo' => 'bar', 'lorem' => 'ipsum']
        );

        $this->assertInstanceOf(Insert::class, $q);
        $this->assertInstanceOf(SourceTableClause::class, $q->getTable());
        $this->assertEquals('test_table', $q->getTable()->getTable());

        $this->assertEquals(['foo' => Q::field('foo'), 'lorem' => Q::field('lorem')], $q->getFields());
        $this->assertEquals(['foo' => Q::variable('bar'), 'lorem' => Q::variable('ipsum')], $q->getValues());

        $q = Q::insert(
            Q::into('test_table'),
            ['foo' => 'bar', 'lorem' => 'ipsum'],
            "test_id"
        );

        $this->assertInstanceOf(Insert::class, $q);
        $this->assertInstanceOf(SourceTableClause::class, $q->getTable());
        $this->assertEquals('test_table', $q->getTable()->getTable());

        $this->assertEquals(['foo' => Q::field('foo'), 'lorem' => Q::field('lorem')], $q->getFields());
        $this->assertEquals(['foo' => Q::variable('bar'), 'lorem' => Q::variable('ipsum')], $q->getValues());
        $this->assertEquals(['test_id'], $q->getPrimaryKey());
    }

    public function testWhere()
    {
        $w = Q::where('"foo" = :c0');
        $this->assertInstanceOf(WhereClause::class, $w);
        $this->assertInstanceOf(CustomSQL::class, $w->getOperand());
        $this->assertEquals('"foo" = :c0', $w->getOperand()->getSQL());

        $w = Q::where(['foo' => 'bar']);
        $this->assertInstanceOf(WhereClause::class, $w);
        $this->assertInstanceOf(ComparisonOperator::class, $w->getOperand());
        $this->assertInstanceOf(FieldName::class, $w->getOperand()->getLHS());
        $this->assertEquals('foo', $w->getOperand()->getLHS()->getField());
        $this->assertEquals('=', $w->getOperand()->getOperator());
        $this->assertInstanceOf(ConstantValue::class, $w->getOperand()->getRHS());
        $this->assertEquals('bar', $w->getOperand()->getRHS()->getValue());
    }

    public function testOr()
    {
        $o = Q::or(Q::equals('foo', '1'), Q::equals('foo', '2'));
        $this->assertInstanceOf(BooleanOperator::class, $o);
        $this->assertEquals('OR', $o->getOperator());

        $this->assertInstanceOf(ComparisonOperator::class, $o->getLHS());
        $this->assertInstanceOf(FieldName::class, $o->getLHS()->getLHS());
        $this->assertEquals('foo', $o->getLHS()->getLHS()->getField());
        $this->assertInstanceOf(ConstantValue::class, $o->getLHS()->getRHS());
        $this->assertEquals(1, $o->getLHS()->getRHS()->getValue());

        $this->assertInstanceOf(ComparisonOperator::class, $o->getRHS());
        $this->assertInstanceOf(FieldName::class, $o->getRHS()->getLHS());
        $this->assertEquals('foo', $o->getRHS()->getLHS()->getField());
        $this->assertInstanceOf(ConstantValue::class, $o->getRHS()->getRHS());
        $this->assertEquals(2, $o->getRHS()->getRHS()->getValue());
    }

    public function testAnd()
    {
        $o = Q::and(Q::equals('foo', '1'), Q::equals('foo', '2'));
        $this->assertInstanceOf(BooleanOperator::class, $o);
        $this->assertEquals('AND', $o->getOperator());

        $this->assertInstanceOf(ComparisonOperator::class, $o->getLHS());
        $this->assertInstanceOf(FieldName::class, $o->getLHS()->getLHS());
        $this->assertEquals('foo', $o->getLHS()->getLHS()->getField());
        $this->assertInstanceOf(ConstantValue::class, $o->getLHS()->getRHS());
        $this->assertEquals(1, $o->getLHS()->getRHS()->getValue());

        $this->assertInstanceOf(ComparisonOperator::class, $o->getRHS());
        $this->assertInstanceOf(FieldName::class, $o->getRHS()->getLHS());
        $this->assertEquals('foo', $o->getRHS()->getLHS()->getField());
        $this->assertInstanceOf(ConstantValue::class, $o->getRHS()->getRHS());
        $this->assertEquals(2, $o->getRHS()->getRHS()->getValue());
    }

    public function testNot()
    {
        $n = Q::not(Q::equals('foo', '1'));
        $this->assertInstanceOf(UnaryOperator::class, $n);
        $this->assertEquals('NOT', $n->getOperator());

        $this->assertNull($n->getLHS());
        $this->assertInstanceOf(ComparisonOperator::class, $n->getRHS());
        $this->assertEquals('=', $n->getRHS()->getOperator());
        $this->assertInstanceOf(FieldName::class, $n->getRHS()->getLHS());
        $this->assertEquals('foo', $n->getRHS()->getLHS()->getField());
        $this->assertInstanceOf(ConstantValue::class, $n->getRHS()->getRHS());
        $this->assertEquals(1, $n->getRHS()->getRHS()->getValue());
    }

    public function testComparison()
    {
        $q = Q::like('foo', 'bar');
        $this->assertInstanceOf(ComparisonOperator::class, $q);
        $this->assertEquals('LIKE', $q->getOperator());
        $this->assertInstanceOf(FieldName::class, $q->getLHS());
        $this->assertEquals("foo", $q->getLHS()->getField());
        $this->assertInstanceOf(ConstantValue::class, $q->getRHS());
        $this->assertEquals("%bar%", $q->getRHS()->getValue());

        $q = Q::ilike('foo', 'bar');
        $this->assertInstanceOf(ComparisonOperator::class, $q);
        $this->assertEquals('ILIKE', $q->getOperator());
        $this->assertInstanceOf(FieldName::class, $q->getLHS());
        $this->assertEquals("foo", $q->getLHS()->getField());
        $this->assertInstanceOf(ConstantValue::class, $q->getRHS());
        $this->assertEquals("%bar%", $q->getRHS()->getValue());

        $q = Q::greaterThan('foo', 3);
        $this->assertInstanceOf(ComparisonOperator::class, $q);
        $this->assertEquals('>', $q->getOperator());
        $this->assertInstanceOf(FieldName::class, $q->getLHS());
        $this->assertEquals("foo", $q->getLHS()->getField());
        $this->assertInstanceOf(ConstantValue::class, $q->getRHS());
        $this->assertEquals(3, $q->getRHS()->getValue());

        $q = Q::greaterThanOrEquals('foo', 5);
        $this->assertInstanceOf(ComparisonOperator::class, $q);
        $this->assertEquals('>=', $q->getOperator());
        $this->assertInstanceOf(FieldName::class, $q->getLHS());
        $this->assertEquals("foo", $q->getLHS()->getField());
        $this->assertInstanceOf(ConstantValue::class, $q->getRHS());
        $this->assertEquals(5, $q->getRHS()->getValue());

        $q = Q::lessThan('foo', 9);
        $this->assertInstanceOf(ComparisonOperator::class, $q);
        $this->assertEquals('<', $q->getOperator());
        $this->assertInstanceOf(FieldName::class, $q->getLHS());
        $this->assertEquals("foo", $q->getLHS()->getField());
        $this->assertInstanceOf(ConstantValue::class, $q->getRHS());
        $this->assertEquals(9, $q->getRHS()->getValue());

        $q = Q::lessThanOrEquals('foo', 3.14);
        $this->assertInstanceOf(ComparisonOperator::class, $q);
        $this->assertEquals('<=', $q->getOperator());
        $this->assertInstanceOf(FieldName::class, $q->getLHS());
        $this->assertEquals("foo", $q->getLHS()->getField());
        $this->assertInstanceOf(ConstantValue::class, $q->getRHS());
        $this->assertEquals(3.14, $q->getRHS()->getValue());

        $q = Q::operator('=', 'foo', 'bar');
        $this->assertInstanceOf(ComparisonOperator::class, $q);
        $this->assertEquals('=', $q->getOperator());
        $this->assertInstanceOf(FieldName::class, $q->getLHS());
        $this->assertEquals("foo", $q->getLHS()->getField());
        $this->assertInstanceOf(ConstantValue::class, $q->getRHS());
        $this->assertEquals('bar', $q->getRHS()->getValue());
    }

    public function testDirection()
    {
        $o = Q::ascending('foo');
        $this->assertInstanceOf(Direction::class, $o);
        $this->assertEquals('foo', $o->getOperand()->getField());

        $o = Q::descending('bar');
        $this->assertInstanceOf(Direction::class, $o);
        $this->assertEquals('bar', $o->getOperand()->getField());

        $o = Q::order(Q::ascending('foo'), Q::descending('bar'));
        $this->assertInstanceOf(OrderClause::class, $o);

        $orders = $o->getClauses();
        $this->assertEquals(2, count($orders));

        $this->assertInstanceOf(Direction::class, $orders[0]);
        $this->assertEquals('ASC', $orders[0]->getDirection());
        $this->assertEquals('foo', $orders[0]->getOperand()->getField());

        $this->assertEquals('DESC', $orders[1]->getDirection());
        $this->assertEquals('bar', $orders[1]->getOperand()->getField());

        $o = Q::order('bar DESC NULLS LAST');
        $orders = $o->getClauses();
        $this->assertInstanceOf(CustomSQL::class, $orders[0]);
        $this->assertEquals('bar DESC NULLS LAST', $orders[0]->getSQL());
    }

    public function testGet()
    {
        $g = Q::get(Q::field('foo'), 'alias');
        $this->assertInstanceOf(GetClause::class, $g);

        $this->assertEquals('alias', $g->getAlias());
        $this->assertInstanceOf(FieldName::class, $g->getExpression());
        $this->assertEquals('foo', $g->getExpression()->getField());
    }

    public function testFunc()
    {
        $f = Q::func('COUNT', '*');
        $this->assertInstanceOf(SQLFunction::class, $f);
        $this->assertEquals('COUNT', $f->getFunction());
        $args = $f->getArguments();
        $this->assertEquals(1, count($args));
        $this->assertInstanceOf(Wildcard::class, $args[0]);
    }

    public function testTable()
    {
        $t = Q::table('name');
        $this->assertInstanceOf(TableClause::class, $t);
        $this->assertEquals('name', $t->getTable());

        $t = Q::into('name', 'foo');
        $this->assertInstanceOf(SourceTableClause::class, $t);
        $this->assertEquals('name', $t->getTable());
        $this->assertEquals('foo', $t->getAlias());

        $t = Q::to('name', 'foo');
        $this->assertInstanceOf(SourceTableClause::class, $t);
        $this->assertEquals('name', $t->getTable());
        $this->assertEquals('foo', $t->getAlias());

        $t = Q::from('name', 'foo');
        $this->assertInstanceOf(SourceTableClause::class, $t);
        $this->assertEquals('name', $t->getTable());
        $this->assertEquals('foo', $t->getAlias());

        $t = Q::with('name', 'foo');
        $this->assertInstanceOf(SourceTableClause::class, $t);
        $this->assertEquals('name', $t->getTable());
        $this->assertEquals('foo', $t->getAlias());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Builder::table takes exactly one argument");
        Q::table('name', 'alias');
    }

    public function testLimit()
    {
        $l = Q::limit(25);
        $this->assertInstanceOf(LimitClause::class, $l);
        $this->assertInstanceOf(ConstantValue::class, $l->getLimit());
        $this->assertEquals(25, $l->getLimit()->getValue());
    }

    public function testOffset()
    {
        $o = Q::offset(25);
        $this->assertInstanceOf(OffsetClause::class, $o);
        $this->assertInstanceOf(ConstantValue::class, $o->getOffset());
        $this->assertEquals(25, $o->getOffset()->getValue());
    }

    public function testJoin()
    {
        $j = Q::join(Q::with('othertable', 't2'), Q::on(Q::equals(Q::field('id', 't2'), Q::field('id', 't1'))));

        $this->assertInstanceOf(JoinClause::class, $j);
        $this->assertEquals('LEFT', $j->getType());
        $this->assertInstanceOf(SourceTableClause::class, $j->getTable());
        $this->assertInstanceOf(ComparisonOperator::class, $j->getCondition());
        $this->assertEquals('othertable', $j->getTable()->getTable());
        $this->assertEquals('t2', $j->getTable()->getAlias());

        $this->assertEquals('=', $j->getCondition()->getOperator());
        $this->assertInstanceOf(FieldName::class, $j->getCondition()->getLHS());

        $this->assertEquals('id', $j->getCondition()->getLHS()->getField());
        $this->assertEquals('t2', $j->getCondition()->getLHS()->getTable()->getTable());

        $this->assertInstanceOf(FieldName::class, $j->getCondition()->getRHS());
        $this->assertEquals('id', $j->getCondition()->getRHS()->getField());
        $this->assertEquals('t1', $j->getCondition()->getRHS()->getTable()->getTable());
    }

    public function testAny()
    {
        $a = Q::any('id', 1, 2, 3, 4);
        $this->assertInstanceOf(EqualsOneOf::class, $a);
        
        $this->assertInstanceOf(FieldName::class, $a->getField());
        $this->assertEquals('id', $a->getField()->getField());

        $this->assertInstanceOf(ConstantArray::class, $a->getList());
        $this->assertEquals([1, 2, 3, 4], $a->getList()->getValue());
    }

    public function testWildcard()
    {
        $w = Q::wildcard();
        $this->assertInstanceOf(Wildcard::class, $w);
    }

    public function testNull()
    {
        $n = Q::null();
        $this->assertInstanceOf(NullValue::class, $n);
    }

    public function testNulleq()
    {
        $ne = Q::nulleq('foo', 'test');

        $this->assertInstanceOf(BooleanOperator::class, $ne);
        $this->assertEquals('OR', $ne->getOperator());
        $this->assertInstanceOf(ComparisonOperator::class, $ne->getLHS());
        $this->assertInstanceOf(FieldName::class, $ne->getLHS()->getLHS());
        $this->assertEquals('foo', $ne->getLHS()->getLHS()->getField());
        $this->assertInstanceOf(ConstantValue::class, $ne->getLHS()->getRHS());
        $this->assertEquals('test', $ne->getLHS()->getRHS()->getValue());

        $this->assertInstanceOf(BooleanOperator::class, $ne->getRHS());
        $this->assertEquals('AND', $ne->getRHS()->getOperator());
        $this->assertInstanceOf(ComparisonOperator::class, $ne->getRHS()->getLHS());
        $this->assertEquals('IS', $ne->getRHS()->getLHS()->getOperator());
        $this->assertInstanceOf(NullValue::class, $ne->getRHS()->getLHS()->getRHS());
        $this->assertInstanceOf(FieldName::class, $ne->getRHS()->getLHS()->getLHS());
        $this->assertEquals('foo', $ne->getRHS()->getLHS()->getLHS()->getField());

        $this->assertInstanceOf(ComparisonOperator::class, $ne->getRHS()->getRHS());
        $this->assertEquals('IS', $ne->getRHS()->getRHS()->getOperator());
        $this->assertInstanceOf(NullValue::class, $ne->getRHS()->getRHS()->getRHS());
        $this->assertInstanceOf(ConstantValue::class, $ne->getRHS()->getRHS()->getLHS());

        $this->assertSame($ne->getLHS()->getLHS(), $ne->getRHS()->getLHS()->getLHS());
        $this->assertSame($ne->getLHS()->getRHS(), $ne->getRHS()->getRHS()->getLHS());
    }

    public function testIncrementDecrement()
    {
        $i = Q::increment('foo');
        $this->assertInstanceOf(UpdateField::class, $i);
        $this->assertInstanceOf(FieldName::class, $i->getField());
        $this->assertInstanceOf(ArithmeticOperator::class, $i->getValue());
        $this->assertInstanceOf(FieldName::class, $i->getValue()->getLHS());
        $this->assertInstanceOf(ConstantValue::class, $i->getValue()->getRHS());

        $this->assertEquals('+', $i->getValue()->getOperator());
        $this->assertEquals('foo', $i->getValue()->getLHS()->getField());
        $this->assertEquals(1, $i->getValue()->getRHS()->getValue());

        $i = Q::increment('foo', 5);
        $this->assertInstanceOf(UpdateField::class, $i);
        $this->assertInstanceOf(FieldName::class, $i->getField());
        $this->assertInstanceOf(ArithmeticOperator::class, $i->getValue());
        $this->assertInstanceOf(FieldName::class, $i->getValue()->getLHS());
        $this->assertInstanceOf(ConstantValue::class, $i->getValue()->getRHS());

        $this->assertEquals('+', $i->getValue()->getOperator());
        $this->assertEquals('foo', $i->getValue()->getLHS()->getField());
        $this->assertEquals(5, $i->getValue()->getRHS()->getValue());

        $i = Q::decrement('foo');
        $this->assertInstanceOf(UpdateField::class, $i);
        $this->assertInstanceOf(FieldName::class, $i->getField());
        $this->assertInstanceOf(ArithmeticOperator::class, $i->getValue());
        $this->assertInstanceOf(FieldName::class, $i->getValue()->getLHS());
        $this->assertInstanceOf(ConstantValue::class, $i->getValue()->getRHS());

        $this->assertEquals('+', $i->getValue()->getOperator());
        $this->assertEquals('foo', $i->getValue()->getLHS()->getField());
        $this->assertEquals(-1, $i->getValue()->getRHS()->getValue());

        $i = Q::decrement('foo', 5);
        $this->assertInstanceOf(UpdateField::class, $i);
        $this->assertInstanceOf(FieldName::class, $i->getField());
        $this->assertInstanceOf(ArithmeticOperator::class, $i->getValue());
        $this->assertInstanceOf(FieldName::class, $i->getValue()->getLHS());
        $this->assertInstanceOf(ConstantValue::class, $i->getValue()->getRHS());

        $this->assertEquals('+', $i->getValue()->getOperator());
        $this->assertEquals('foo', $i->getValue()->getLHS()->getField());
        $this->assertEquals(-5, $i->getValue()->getRHS()->getValue());
    }

    public function testArithmetic()
    {
        $a = Q::add('foo', '3');
        $this->assertInstanceOf(ArithmeticOperator::class, $a);
        $this->assertInstanceOf(FieldName::class, $a->getLHS());
        $this->assertInstanceOf(ConstantValue::class, $a->getRHS());

        $this->assertEquals('foo', $a->getLHS()->getField());
        $this->assertEquals('+', $a->getOperator());
        $this->assertEquals(3, $a->getRHS()->getValue());

        $a = Q::subtract('foo', '3');
        $this->assertInstanceOf(ArithmeticOperator::class, $a);
        $this->assertInstanceOf(FieldName::class, $a->getLHS());
        $this->assertInstanceOf(ConstantValue::class, $a->getRHS());

        $this->assertEquals('foo', $a->getLHS()->getField());
        $this->assertEquals('-', $a->getOperator());
        $this->assertEquals(3, $a->getRHS()->getValue());

        $a = Q::multiply('foo', '3.14');
        $this->assertInstanceOf(ArithmeticOperator::class, $a);
        $this->assertInstanceOf(FieldName::class, $a->getLHS());
        $this->assertInstanceOf(ConstantValue::class, $a->getRHS());

        $this->assertEquals('foo', $a->getLHS()->getField());
        $this->assertEquals('*', $a->getOperator());
        $this->assertEquals(3.14, $a->getRHS()->getValue());

        $a = Q::divide('foo', 42);
        $this->assertInstanceOf(ArithmeticOperator::class, $a);
        $this->assertInstanceOf(FieldName::class, $a->getLHS());
        $this->assertInstanceOf(ConstantValue::class, $a->getRHS());

        $this->assertEquals('foo', $a->getLHS()->getField());
        $this->assertEquals('/', $a->getOperator());
        $this->assertEquals(42, $a->getRHS()->getValue());
    }

    public function testGroupBy()
    {
        $g = Q::groupBy(Q::field('category'), Q::having(Q::greaterThan(Q::func('COUNT', 'id'), 20)));

        $this->assertInstanceOf(GroupByClause::class, $g);
        $groups = $g->getGroups();
        $this->assertEquals(Q::field('category'), $groups[0]);
        $this->assertEquals(Q::having(Q::greaterThan(Q::func('COUNT', 'id'), 20)), $g->getHaving());
    }

    public function testUnion()
    {
        $select = Q::select(
            Q::field('id'), Q::field('name'),
            Q::from('table'),
            Q::where(Q::greaterThan('id', 1000))
        );

        $u = Q::union($select);
        $this->assertInstanceOf(UnionClause::class, $u);
        $this->assertSame($select, $u->getQuery());
        $this->assertEquals('DISTINCT', $u->getType());

        $u = Q::unionAll($select);
        $this->assertInstanceOf(UnionClause::class, $u);
        $this->assertSame($select, $u->getQuery());
        $this->assertEquals('ALL', $u->getType());
    }

    public function testSubQuery()
    {
        $select = Q::select(
            Q::field('id'), Q::field('name'),
            Q::from('table'),
            Q::where(Q::greaterThan('id', 1000))
        );

        $sub = Q::subquery($select);
        $this->assertInstanceOf(SubQuery::class, $sub);
        $this->assertSame($select, $sub->getQuery());

        $sub = Q::subquery($select, 'myfunc');
        $this->assertInstanceOf(SourceSubQuery::class, $sub);
        $this->assertEquals('myfunc', $sub->getAlias());
        $this->assertSame($select, $sub->getSubQuery()->getQuery());
    }
}
