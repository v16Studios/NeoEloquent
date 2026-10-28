<?php

namespace Vinelab\NeoEloquent\Tests\Compatibility;

use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Vinelab\NeoEloquent\Connection;
use Vinelab\NeoEloquent\Eloquent\Model;
use Vinelab\NeoEloquent\Eloquent\Relations\MorphTo;
use Vinelab\NeoEloquent\Eloquent\Relations\MorphedByOne;

class CompatibilityNode extends Model
{
    protected $label = 'CompatibilityNode';
    protected $guarded = [];
    public $timestamps = false;

    public function children()
    {
        return $this->hasMany(self::class, 'Vendor\\User');
    }

    public function parents()
    {
        return $this->belongsToMany(self::class, 'Vendor\\User');
    }

    public function commentableTarget()
    {
        return $this->morphTo();
    }

    public function explicitTarget()
    {
        return $this->morphTo(self::class, 'ON');
    }
}

abstract class CompatibilityTestCase extends TestCase
{
    protected function setUp(): void
    {
        Model::setConnectionResolver(new ConnectionResolver(['default' => new Connection([
            'driver' => 'neo4j', 'scheme' => 'bolt', 'host' => '127.0.0.1', 'port' => 7687,
        ])]));
        Model::getConnectionResolver()->setDefaultConnection('default');
    }
}

class CompatibilityTest extends CompatibilityTestCase
{
    public function testConnectionDefaultsAndExplicitUriConfiguration(): void
    {
        $connection = new Connection();
        $client = $connection->getClient();
        $this->assertSame('bolt', $connection->getConfig('scheme'));
        $this->assertSame(7687, $connection->getPort());
        $this->assertSame('neo4j', $connection->getDatabaseName());
        $this->assertSame('neo4j', $client->getDatabase());
        $this->assertSame('bolt://localhost:7687?database=neo4j', $client->buildUriFromConfig($connection->getConfig()));
        $this->assertSame('bolt://example.test:9999?database=other', $client->buildUriFromConfig([
            'host' => 'example.test', 'port' => 9999, 'database' => 'other',
        ]));
        $this->assertSame('neo4j+s://example.test', $client->buildUriFromConfig(['url' => 'neo4j+s://example.test']));
        $this->assertSame('http', $connection->getScheme(['scheme' => 'http']));
    }

    public function testSelectAcceptsLaravel13FetchArguments(): void
    {
        $method = new ReflectionMethod(Connection::class, 'select');
        $this->assertCount(4, $method->getParameters());
        $this->assertSame('array', (string) $method->getParameters()[3]->getType());
        $this->assertSame([], $method->getParameters()[3]->getDefaultValue());
    }

    public function testZeroIdIsHydratedAsAnExistingModel(): void
    {
        $node = (new CompatibilityNode())->newFromBuilder(['id' => 0, 'name' => 'zero']);
        $this->assertTrue($node->exists);
        $this->assertSame(0, $node->getKey());
        $this->assertSame(0, $node->toArray()['id']);
        $this->assertSame('deleted_at', $node->qualifyColumn('deleted_at'));
    }

    public function testGraphRelationReportsNoSqlPivotModel(): void
    {
        $relation = Relation::noConstraints(fn () => (new CompatibilityNode())->parents());
        $this->assertNull($relation->getPivotClass());
    }

    public function testRelationConstructionDoesNotCreateDynamicProperties(): void
    {
        $relation = (new CompatibilityNode(['id' => 0]))->children();
        $this->assertSame('children', $relation->getRelationName());
        $this->assertSame('Vendor\\User', $relation->getRelationType());
    }

    public function testUnnamedMorphUsesStrHelperAndRelationName(): void
    {
        $relation = (new CompatibilityNode(['id' => 0]))->commentableTarget();
        $this->assertInstanceOf(MorphTo::class, $relation);
        $this->assertSame('commentable_target', $relation->getRelationName());
        $this->assertStringContainsString('commentable_target', $relation->toSql());
    }

    public function testExplicitGraphMorphRetainsItsHistoricalArgumentMeaning(): void
    {
        $relation = (new CompatibilityNode(['id' => 0]))->explicitTarget();
        $this->assertInstanceOf(MorphedByOne::class, $relation);
        $this->assertSame('explicitTarget', $relation->getRelationName());
        $this->assertSame('ON', $relation->getRelationType());
    }
}
