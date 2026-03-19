<?php

namespace Vinelab\NeoEloquent\DatabaseDriver\Interfaces;

use Vinelab\NeoEloquent\DatabaseDriver\CypherQuery;

interface QueryExecutorInterface
{
    public function executeCypherQuery(CypherQuery $cypherQuery);
}
