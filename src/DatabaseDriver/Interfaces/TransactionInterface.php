<?php

namespace Vinelab\NeoEloquent\DatabaseDriver\Interfaces;

interface TransactionInterface extends QueryExecutorInterface
{
    public function commit();

    public function rollBack();
}
