<?php

namespace Vinelab\NeoEloquent\Tests\DatabaseDriver\Drivers;

use Vinelab\NeoEloquent\DatabaseDriver\Drivers\ClientAbstract;
use Vinelab\NeoEloquent\Tests\TestCase;

class ClientAbstractTest extends TestCase
{
    public function testBoltUriIncludesDatabaseWithoutLegacyHostFormatting()
    {
        $client = new class extends ClientAbstract {
        };

        $uri = $client->buildUriFromConfig([
            'scheme' => 'bolt',
            'host' => 'localhost',
            'port' => 7687,
            'database' => 'neo4j',
        ]);

        $this->assertEquals('bolt://localhost:7687?database=neo4j', $uri);
    }

    public function testExplicitUrlWinsOverComposedConfig()
    {
        $client = new class extends ClientAbstract {
        };

        $uri = $client->buildUriFromConfig([
            'url' => 'neo4j://graph.example.test:7687?database=analytics',
            'scheme' => 'bolt',
            'host' => 'localhost',
            'port' => 7687,
            'database' => 'neo4j',
        ]);

        $this->assertEquals('neo4j://graph.example.test:7687?database=analytics', $uri);
    }
}
