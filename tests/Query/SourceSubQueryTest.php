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
 * @covers WASP\DB\Query\SourceSubQuery
 */
class SourceSubQueryTest extends TestCase
{
    public function testSourceSubQuery()
    {
        $sq = Q::select(
            Q::field('foo'),
            Q::field('bar'),
            Q::from('test')
        );

        // Just pass in the query
        $q = Q::select(
            Q::field('foo'),
            Q::field('bar'),
            new SourceSubQuery($sq, 'q')
        );

        $from = $q->getTable();
        $this->assertInstanceOf(SourceSubQuery::class, $from);
        $subq = $from->getSubQuery();
        $this->assertInstanceOf(SubQuery::class, $subq);
        $sel = $subq->getQuery();
        $this->assertEquals($sq, $sel);

        // Wrap the subquery directly
        $q = Q::select(
            Q::field('foo'),
            Q::field('bar'),
            new SourceSubQuery(new SubQuery($sq), 'q')
        );
        $from = $q->getTable();
        $this->assertInstanceOf(SourceSubQuery::class, $from);
        $subq = $from->getSubQuery();
        $this->assertInstanceOf(SubQuery::class, $subq);
        $sel = $subq->getQuery();
        $this->assertEquals($sq, $sel);
    }

    public function testConstructWithoutAlias()
    {
        $q = Q::select( 
            Q::field('foo'),
            Q::field('bar'),
            Q::from('test')
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Subqueries must have an alias");
        new SourceSubQuery($q, '');
    }

    public function testSetInvalidSubQuery()
    {
        $q = new Delete('test', ['foo' => 'bar']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Provide a subquery as argument to SourceSubQuery");
        new SourceSubQuery($q, 'q');
    }
}
