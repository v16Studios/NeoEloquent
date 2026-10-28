<?php

namespace Vinelab\NeoEloquent\Tests\Transactions;

use Laudis\Neo4j\Contracts\UnmanagedTransactionInterface;
use Laudis\Neo4j\Databags\Statement;
use Laudis\Neo4j\Databags\SummarizedResult;
use Laudis\Neo4j\Types\CypherList;
use Vinelab\NeoEloquent\DatabaseDriver\Drivers\Laudis\Laudis;

/** Faults are injected around real Neo4j transactions, not fake persistence. */
class FaultingLaudis extends Laudis
{
    public int $begins = 0;
    public ?\Throwable $beginFailure = null;
    public ?\Throwable $commitFailure = null;
    public ?\Throwable $rollbackFailure = null;
    public bool $commitBeforeFailure = false;
    public bool $rollbackBeforeFailure = true;

    protected function openTransaction(): UnmanagedTransactionInterface
    {
        $this->begins++;
        if ($this->beginFailure !== null) {
            $error = $this->beginFailure;
            $this->beginFailure = null;
            throw $error;
        }
        return new FaultingTransaction(parent::openTransaction(), $this);
    }
}

class FaultingTransaction implements UnmanagedTransactionInterface
{
    public function __construct(private UnmanagedTransactionInterface $delegate, private FaultingLaudis $faults)
    {
    }

    public function commit(iterable $statements = []): CypherList
    {
        if ($this->faults->commitFailure !== null) {
            $error = $this->faults->commitFailure;
            $this->faults->commitFailure = null;
            if ($this->faults->commitBeforeFailure) {
                $this->delegate->commit($statements);
            }
            throw $error;
        }
        return $this->delegate->commit($statements);
    }

    public function rollback(): void
    {
        if (!$this->faults->rollbackBeforeFailure && $this->faults->rollbackFailure !== null) {
            $error = $this->faults->rollbackFailure;
            $this->faults->rollbackFailure = null;
            throw $error;
        }
        $this->delegate->rollback();
        if ($this->faults->rollbackFailure !== null) {
            $error = $this->faults->rollbackFailure;
            $this->faults->rollbackFailure = null;
            throw $error;
        }
    }

    public function run(string $statement, iterable $parameters = []): SummarizedResult
    {
        return $this->delegate->run($statement, $parameters);
    }

    public function runStatement(Statement $statement): SummarizedResult
    {
        return $this->delegate->runStatement($statement);
    }

    public function runStatements(iterable $statements): CypherList
    {
        return $this->delegate->runStatements($statements);
    }

    public function isRolledBack(): bool { return $this->delegate->isRolledBack(); }
    public function isCommitted(): bool { return $this->delegate->isCommitted(); }
    public function isFinished(): bool { return $this->delegate->isFinished(); }
}
