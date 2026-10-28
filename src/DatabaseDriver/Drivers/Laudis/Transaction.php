<?php

namespace Vinelab\NeoEloquent\DatabaseDriver\Drivers\Laudis;

use Closure;
use Laudis\Neo4j\Contracts\UnmanagedTransactionInterface;
use Laudis\Neo4j\Databags\Statement;
use Laudis\Neo4j\Databags\SummarizedResult;
use LogicException;
use Throwable;
use Vinelab\NeoEloquent\DatabaseDriver\Interfaces\TransactionInterface;

class Transaction implements TransactionInterface
{
    private bool $closed = false;
    private ?Throwable $failure = null;

    public function __construct(
        private UnmanagedTransactionInterface $transaction,
        private Closure $onClose
    ) {
    }

    public function runStatement(Statement $statement): SummarizedResult
    {
        $this->assertOpen();
        if ($this->failure !== null) {
            throw $this->failure;
        }

        try {
            $result = $this->transaction->runStatement($statement);
            // Pull errors must surface inside the callback, before commit/retry.
            $result->preload();
            $result->rewind();

            return $result;
        } catch (Throwable $error) {
            // A caught query failure must never permit a partial commit or fallback
            // to auto-commit queries, even if the driver resets its own state.
            $this->failure = $error;
            throw $error;
        }
    }

    public function commit()
    {
        $this->assertOpen();
        try {
            if ($this->failure !== null) {
                throw $this->failure;
            }
            $this->transaction->commit();
        } catch (Throwable $error) {
            try {
                if (!$this->transaction->isFinished()) {
                    $this->transaction->rollback();
                }
            } catch (Throwable $cleanupError) {
                // Preserve the commit error; its outcome may be unknown.
            }
            $this->close(true);
            throw $error;
        }
        $this->close(false);
    }

    public function rollBack()
    {
        if ($this->closed) {
            return;
        }
        $failed = $this->failure !== null;
        try {
            if (!$this->transaction->isFinished()) {
                $this->transaction->rollback();
            }
        } catch (Throwable $error) {
            $failed = true;
            throw $error;
        } finally {
            $this->close($failed);
        }
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new LogicException('The Neo4j transaction is already closed.');
        }
    }

    private function close(bool $failed): void
    {
        $this->closed = true;
        ($this->onClose)($this, $failed);
    }
}
