<?php

namespace Vinelab\NeoEloquent\Tests\DatabaseDriver;

use Mockery as M;
use Vinelab\NeoEloquent\DatabaseDriver\CypherQuery;
use Vinelab\NeoEloquent\DatabaseDriver\Interfaces\QueryExecutorInterface;
use Vinelab\NeoEloquent\Tests\TestCase;

class CypherQueryTest extends TestCase
{
    public function tearDown(): void
    {
        M::close();

        parent::tearDown();
    }

    public function testLegacyParametersAreNormalizedForModernNeo4j()
    {
        $executor = M::mock(QueryExecutorInterface::class);
        $query = new CypherQuery(
            $executor,
            'MATCH (n:User) WHERE n.email = {email} SET n.name = {name} RETURN n',
            ['email' => 'jane@example.com', 'name' => 'Jane']
        );

        $this->assertEquals(
            'MATCH (n:User) WHERE n.email = $email SET n.name = $name RETURN n',
            $query->getQuery()
        );
    }
}
