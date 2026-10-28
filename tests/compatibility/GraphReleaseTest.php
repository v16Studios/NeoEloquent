<?php

namespace Vinelab\NeoEloquent\Tests\Compatibility;

require_once __DIR__.'/CompatibilityTest.php';

use Vinelab\NeoEloquent\Connection;
use Vinelab\NeoEloquent\Eloquent\Model;

class ReleaseNode extends Model
{
    protected $label = 'ReleaseFixture';
    protected $guarded = [];
    public $timestamps = false;

    public function children()
    {
        return $this->hasMany(self::class, 'RELEASE_OWNS');
    }

    public function parents()
    {
        return $this->belongsToMany(self::class, 'RELEASE_OWNS');
    }
}

class ReleaseEntity extends ReleaseNode
{
    protected $label = ['ReleaseUser', 'ReleaseCompany'];
}

/** First in release.xml so a fresh store proves real node AND relationship ID 0. */
class GraphReleaseTest extends CompatibilityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('NEOELOQUENT_DISPOSABLE_GRAPH') !== '1') {
            $this->markTestSkipped('Requires the isolated disposable graph harness.');
        }
    }

    protected function tearDown(): void
    {
        if (getenv('NEOELOQUENT_DISPOSABLE_GRAPH') === '1') {
            (new ReleaseNode())->getConnection()->statement(
                'MATCH (n) WHERE n:ReleaseFixture OR n:ReleaseUser OR n:ReleaseCompany DETACH DELETE n'
            );
        }
        parent::tearDown();
    }

    public function testZeroIdsSurviveRelationshipHydrationUpdateAndDetach(): void
    {
        $owner = ReleaseNode::create(['name' => 'owner']);
        $child = ReleaseNode::create(['name' => 'child']);
        $edge = $owner->children()->attach($child, ['quantity' => 2, 'enabled' => true]);
        if (getenv('NEOELOQUENT_EXPECT_FIRST_ID_ZERO') === '1') {
            $this->assertSame(0, $owner->getKey());
            $this->assertSame(0, $edge->getKey());
        }
        $id = $edge->getKey();
        $this->assertTrue($edge->exists());
        $this->assertSame([$child->getKey()], $owner->children()->get()->modelKeys());
        $hydrated = $owner->children()->edge($child);
        $this->assertSame($id, $hydrated->getKey());
        $hydrated->quantity = 0;
        $hydrated->enabled = false;
        $this->assertTrue($hydrated->save());
        $again = $owner->children()->edge($child);
        $this->assertSame($id, $again->getKey());
        $this->assertSame(0, $again->quantity);
        $this->assertFalse($again->enabled);
        $this->assertNull($child->parents()->getPivotClass());
        $owner->children()->detach($child);
        $this->assertCount(0, $owner->children()->get());
        $this->assertNotNull(ReleaseNode::find($owner->getKey()));
        $this->assertNotNull(ReleaseNode::find($child->getKey()));
    }

    public function testExplicitDatabaseAndUrlOverrideSelectTheServerDatabase(): void
    {
        $config = ['driver' => 'neo4j', 'scheme' => 'bolt', 'host' => '127.0.0.1', 'port' => 7687,
            'database' => 'neo4j', 'fetch_size' => 2, 'default_timeout' => 5];
        $connection = new Connection($config);
        $this->assertSame('neo4j', $connection->select('CALL db.info() YIELD name RETURN name')->getResults()[0]['name']);
        $this->assertCount(5, $connection->select('UNWIND range(1, 5) AS value RETURN value')->getResults());
        $config['database'] = 'deliberately_missing_database';
        $config['url'] = 'bolt://127.0.0.1:7687?database=neo4j';
        $override = new Connection($config);
        $this->assertSame('neo4j', $override->select('CALL db.info() YIELD name RETURN name')->getResults()[0]['name']);
        $system = new Connection(array_replace($config, ['url' => 'bolt://127.0.0.1:7687?database=system']));
        $this->assertNotEmpty($system->select('SHOW DATABASES YIELD name RETURN name')->getResults());
    }

    public function testCreateWithAndBoundValuesPreservePropertyShapes(): void
    {
        $child = ReleaseNode::create(['name' => 'child']);
        $owner = ReleaseNode::createWith([
            'name' => "O'Reilly \\ graph ☃", 'zero' => 0, 'enabled' => false,
            'optional' => null, 'values' => [0, 2, 3],
        ], ['children' => [$child->getKey()]]);
        $read = ReleaseNode::findOrFail($owner->getKey());
        $this->assertSame("O'Reilly \\ graph ☃", $read->name);
        $this->assertSame(0, $read->zero);
        $this->assertFalse($read->enabled);
        $this->assertNull($read->optional);
        $this->assertSame([0, 2, 3], $read->values);
        $this->assertSame([$child->getKey()], $read->children()->get()->modelKeys());
        $this->assertEqualsCanonicalizing([$owner->getKey(), $child->getKey()], ReleaseNode::whereIn('id', [$owner->getKey(), $child->getKey()])->get()->modelKeys());
        $rows = $owner->getConnection()->select(
            'RETURN $map.zero AS zero, $map.enabled AS enabled, size($empty) AS size, $optional AS optional',
            ['map' => ['zero' => 0, 'enabled' => false], 'empty' => [], 'optional' => null]
        )->getResults();
        $this->assertSame(['zero' => 0, 'enabled' => false, 'size' => 0, 'optional' => null], $rows[0]);
        $this->assertSame(['id' => 7, 'alias' => 9], $owner->getConnection()->select(
            'RETURN $id AS id, $idn AS alias', ['idn' => 9, 'id' => 7]
        )->getResults()[0]);
    }

    public function testArrayLabelsRequireAllLabelsAndPreserveIdentity(): void
    {
        $connection = (new ReleaseNode())->getConnection();
        $connection->statement('CREATE (:ReleaseUser {name: "user-only"}), (:ReleaseCompany {name: "company-only"})');
        $both = ReleaseEntity::create(['name' => 'both']);
        // Same array-label shape as App\Models\Entity: AND, not a union of labels.
        $this->assertSame([$both->getKey()], ReleaseEntity::all()->modelKeys());
        $this->assertSame('both', ReleaseEntity::findOrFail($both->getKey())->name);
        $both->update(['name' => 'updated']);
        $row = $connection->select('MATCH (n) WHERE id(n) = $id RETURN labels(n) AS labels', ['id' => $both->getKey()])->getResults()[0];
        $this->assertEqualsCanonicalizing(['ReleaseUser', 'ReleaseCompany'], $row['labels']->toArray());
        $both->delete();
        $this->assertSame(2, $connection->select('MATCH (n) WHERE n:ReleaseUser OR n:ReleaseCompany RETURN count(n) AS total')->getResults()[0]['total']);
    }

    public function testForkSaveOrFailParticipatesWithoutStartingANestedTransaction(): void
    {
        $connection = (new ReleaseNode())->getConnection();
        $connection->beginTransaction();
        $node = new ReleaseNode(['name' => 'saveOrFail']);
        $this->assertTrue($node->saveOrFail());
        $this->assertSame(1, $connection->transactionLevel());
        $connection->rollBack();
        $this->assertNull(ReleaseNode::find($node->getKey()));
    }
}
