<?php

namespace Vinelab\NeoEloquent\DatabaseDriver\Drivers\Laudis;

use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\Client;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\AuthenticateInterface;
use Laudis\Neo4j\Contracts\UnmanagedTransactionInterface;
use Laudis\Neo4j\Databags\SessionConfiguration;
use Laudis\Neo4j\Databags\Statement;
use Laudis\Neo4j\Databags\SummarizedResult;
use Laudis\Neo4j\Databags\TransactionConfiguration;
use Laudis\Neo4j\Formatter\SummarizedResultFormatter;
use LogicException;
use Throwable;
use Vinelab\NeoEloquent\DatabaseDriver\CypherQuery;
use Vinelab\NeoEloquent\DatabaseDriver\Drivers\ClientAbstract;
use Vinelab\NeoEloquent\DatabaseDriver\Interfaces\ClientInterface;
use Vinelab\NeoEloquent\DatabaseDriver\Interfaces\NodeInterface;

class Laudis extends ClientAbstract implements ClientInterface
{
    /**
     * @var Client
     */
    protected $client;
    protected $config;
    private ?Transaction $transaction = null;

    public function __construct($config)
    {
        $this->config = $config;
        $this->client = $this->createClient();
    }

    protected function createClient(): Client
    {
        $formatter = SummarizedResultFormatter::create();

        $timeout = (float) ($this->getTimeout() ?? TransactionConfiguration::DEFAULT_TIMEOUT);
        $fetchSize = (int) ($this->getFetchSize() ?? SessionConfiguration::DEFAULT_FETCH_SIZE);

        return ClientBuilder::create()
            ->withDriver('default', $this->buildUriFromConfig($this->config), $this->getAuth())
            ->withDefaultTransactionConfiguration(TransactionConfiguration::default()->withTimeout($timeout))
            ->withDefaultSessionConfiguration(SessionConfiguration::default()->withFetchSize($fetchSize))
            ->withFormatter($formatter)
            ->build();
    }

    public function makeNode(): Node
    {
        return new Node($this);
    }

    public function makeRelationship(): Relation
    {
        return new Relation($this);
    }

    public function makeLabel($label)
    {
        return $label;
    }

    public function executeCypherQuery(CypherQuery $cypherQuery): ResultSet
    {
        $statement = new Statement($cypherQuery->getQuery(), $cypherQuery->getParameters());

        return new ResultSet(
            $this->runStatement($statement)
        );
    }

    public function run($cypher, iterable $parameters = [])
    {
        return $this->runStatement(new Statement($cypher, $parameters));
    }

    public function runStatement(Statement $statement): SummarizedResult
    {
        // Helpers keep this adapter, so those created before beginTransaction()
        // resolve the active transaction at execution time too.
        if ($this->transaction !== null) {
            return $this->transaction->runStatement($statement);
        }

        try {
            $result = $this->client->runStatement($statement);
            // Auto-commit can fail while pulling records, too. Consume here so
            // a failed Bolt connection cannot leak into a later transaction.
            $result->preload();
            $result->rewind();

            return $result;
        } catch (Throwable $error) {
            $this->resetClientAfterFailure();
            throw $error;
        }
    }

    private function getAuth(): AuthenticateInterface
    {
        $username = $this->getUsername();
        $password = $this->getPassword();
        if ($username && $password) {
            return Authenticate::basic($username, $password);
        }

        return Authenticate::disabled();
    }

    public function getNode($id): Node
    {
        $node = $this->makeNode();
        $node->setId($id);
        $node->populateNode();

        return $node;
    }

    public function deleteNode(NodeInterface $node)
    {
        $node->delete();
    }

    public function startBatch()
    {
        // TODO
        return new Batch();
    }

    public function commitBatch()
    {
        // TODO
        return true;
    }

    public function beginTransaction()
    {
        if ($this->transaction !== null) {
            throw new LogicException('Nested Neo4j transactions are not supported.');
        }
        try {
            $nativeTransaction = $this->openTransaction();
        } catch (Throwable $error) {
            $this->resetClientAfterFailure();
            throw $error;
        }
        $transaction = new Transaction($nativeTransaction, function (Transaction $closed, bool $failed) {
            if ($this->transaction === $closed) {
                $this->transaction = null;
            }
            if ($failed) {
                // Do not reuse transport/pool state after a failed transaction.
                $this->resetClientAfterFailure();
            }
        });
        try {
            // Laudis may defer BEGIN. Validate the server/database before reporting
            // a successful begin or running the application's callback.
            $transaction->runStatement(new Statement('RETURN 1', []));
        } catch (Throwable $error) {
            try {
                $transaction->rollBack();
            } catch (Throwable $cleanupError) {
            }
            throw $error;
        }

        return $this->transaction = $transaction;
    }

    protected function openTransaction(): UnmanagedTransactionInterface
    {
        return $this->client->beginTransaction();
    }

    private function resetClientAfterFailure(): void
    {
        try {
            $this->client->getDriver(null)->closeConnections();
        } catch (Throwable $cleanupError) {
            // Keep the original operation error; discard this transport regardless.
        }
        $this->client = $this->createClient();
    }
}
