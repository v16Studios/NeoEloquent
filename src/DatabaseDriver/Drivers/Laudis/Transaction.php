<?php

namespace Vinelab\NeoEloquent\DatabaseDriver\Drivers\Laudis;

use Laudis\Neo4j\Databags\Statement;
use Vinelab\NeoEloquent\DatabaseDriver\CypherQuery;
use Vinelab\NeoEloquent\DatabaseDriver\Interfaces\TransactionInterface;

class Transaction implements TransactionInterface
{
    protected $transaction;

    public function __construct($transaction)
    {
        $this->transaction = $transaction;
    }

    public function executeCypherQuery(CypherQuery $cypherQuery): ResultSet
    {
        return new ResultSet(
            $this->transaction->runStatement(
                new Statement($cypherQuery->getQuery(), $cypherQuery->getParameters())
            )
        );
    }

    public function commit()
    {
        $this->transaction->commit();
    }

    public function rollBack()
    {
        $this->transaction->rollback();
    }
}
