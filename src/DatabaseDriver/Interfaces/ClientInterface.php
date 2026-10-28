<?php

namespace Vinelab\NeoEloquent\DatabaseDriver\Interfaces;

use Vinelab\NeoEloquent\DatabaseDriver\CypherQuery;

interface ClientInterface
{
    public function run($cypher);

    public function makeNode();

    public function makeLabel($label);

    public function executeCypherQuery(CypherQuery $cypherQuery);

    public function makeRelationship();

    public function getNode($id);

    public function deleteNode(NodeInterface $node);

    /**
     * All queries and helpers created by this client must use the returned
     * transaction until it closes, including helpers created before this call.
     *
     * @return TransactionInterface
     */
    public function beginTransaction();
}
