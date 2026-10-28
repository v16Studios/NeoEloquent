<?php

namespace Vinelab\NeoEloquent\Tests\Compatibility;

require_once __DIR__.'/CompatibilityTest.php';

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Vinelab\NeoEloquent\Eloquent\SoftDeletes;

class SoftCompatibilityNode extends CompatibilityNode
{
    use SoftDeletes;
}

/** Uses only the disposable Neo4j container defined in compose.yaml. */
class GraphCompatibilityTest extends CompatibilityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('NEOELOQUENT_DISPOSABLE_GRAPH') !== '1') {
            $this->markTestSkipped('Run with the isolated compatibility Compose harness.');
        }
    }

    protected function tearDown(): void
    {
        if (getenv('NEOELOQUENT_DISPOSABLE_GRAPH') === '1') {
            (new CompatibilityNode())->getConnection()->select('MATCH (n:CompatibilityNode) DETACH DELETE n');
        }
        Paginator::currentPageResolver(fn () => 1);
        Paginator::currentPathResolver(fn () => '/');
        parent::tearDown();
    }

    public function testCrudAndIdentity(): void
    {
        $node = CompatibilityNode::create(['name' => 'first', 'enabled' => true, 'score' => 2]);
        $id = $node->getKey();
        $this->assertSame('first', CompatibilityNode::findOrFail($id)->name);
        $node->update(['name' => 'updated']);
        $this->assertSame('updated', CompatibilityNode::findOrFail($id)->name);
        $this->assertTrue(CompatibilityNode::findOrFail($id)->enabled);
        $this->assertTrue($node->delete());
        $this->assertNull(CompatibilityNode::find($id));
    }

    public function testSimplePaginationHonoursExplicitAndResolvedPage(): void
    {
        foreach (range(1, 5) as $rank) {
            CompatibilityNode::create(['rank' => $rank]);
        }
        Paginator::currentPathResolver(fn () => '/nodes');
        Paginator::currentPageResolver(function ($name) {
            $this->assertSame('nodes_page', $name);
            return 2;
        });
        $page = CompatibilityNode::orderBy('rank')->simplePaginate(2, ['*'], 'nodes_page');
        $this->assertSame([3, 4], $page->getCollection()->pluck('rank')->all());
        $this->assertTrue($page->hasMorePages());
        $this->assertSame('/nodes?nodes_page=3', $page->nextPageUrl());
        $last = CompatibilityNode::orderBy('rank')->simplePaginate(2, ['*'], 'nodes_page', 3);
        $this->assertSame([5], $last->getCollection()->pluck('rank')->all());
        $this->assertFalse($last->hasMorePages());
        $model = new CompatibilityNode();
        $model->setPerPage(3);
        $this->assertCount(3, $model->newQuery()->simplePaginate(null, ['*'], 'nodes_page', 1));
        $this->assertCount(0, CompatibilityNode::where('rank', 99)->simplePaginate(2, ['*'], 'nodes_page', 1));
    }

    public function testNamespacedRelationshipAliasPreservesTypeAndDirection(): void
    {
        $parent = CompatibilityNode::create(['name' => 'parent']);
        $child = CompatibilityNode::create(['name' => 'child']);
        $parent->children()->save($child, ['weight' => 7]);
        $this->assertSame([$child->getKey()], $parent->children()->get()->modelKeys());
        $this->assertSame([$parent->getKey()], $child->parents()->get()->modelKeys());
        $row = $parent->getConnection()->select(
            'MATCH (a:CompatibilityNode)-[r]->(b:CompatibilityNode) RETURN type(r) AS type, id(a) AS source, id(b) AS target, r.weight AS weight'
        )->getResults()[0];
        $this->assertSame('Vendor\\User', $row['type']);
        $this->assertSame($parent->getKey(), $row['source']);
        $this->assertSame($child->getKey(), $row['target']);
        $this->assertSame(7, $row['weight']);
    }

    public function testSoftDeletesAndArrayableWhereInRemainSupported(): void
    {
        $node = SoftCompatibilityNode::create(['name' => 'soft']);
        $this->assertCount(1, SoftCompatibilityNode::whereIn('id', new Collection([$node->getKey()]))->get());
        $node->delete();
        $this->assertNull(SoftCompatibilityNode::find($node->getKey()));
        $this->assertNotNull(SoftCompatibilityNode::withTrashed()->find($node->getKey()));
        $node->restore();
        $this->assertNotNull(SoftCompatibilityNode::find($node->getKey()));
        $this->assertCount(0, SoftCompatibilityNode::whereIn('id', [])->get());
    }

    public function testSelectWithLaravel13ArgumentsReturnsBoundValue(): void
    {
        $rows = (new CompatibilityNode())->getConnection()->select('RETURN $value AS value', ['value' => 7], true, [])->getResults();
        $this->assertSame(7, $rows[0]['value']);
    }
}
