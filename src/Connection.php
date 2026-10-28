<?php

namespace Vinelab\NeoEloquent;

use Closure;
use Exception;
use Illuminate\Database\Connection as IlluminateConnection;
use Illuminate\Database\Schema\Grammars\Grammar as IlluminateSchemaGrammar;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Laudis\Neo4j\Exception\Neo4jException;
use LogicException;
use Throwable;
use Vinelab\NeoEloquent\DatabaseDriver\CypherQuery;
use Vinelab\NeoEloquent\DatabaseDriver\DatabaseDriver;
use Vinelab\NeoEloquent\DatabaseDriver\Interfaces\ClientInterface;
use Vinelab\NeoEloquent\DatabaseDriver\Interfaces\ResultSetInterface;
use Vinelab\NeoEloquent\DatabaseDriver\Interfaces\TransactionInterface;
use Vinelab\NeoEloquent\Query\Builder;

class Connection extends IlluminateConnection
{
    /**
     * The Neo4j active client connection.
     *
     * @var ClientInterface
     */
    protected $neo;

    /**
     * The Neo4j database transaction.
     *
     * @var TransactionInterface
     */
    protected $transaction;

    protected bool $transactionCallbackRunning = false;
    protected bool $retryableBeginFailure = false;
    protected bool $commitOutcomeUnknown = false;
    protected array $rollbackCallbacks = [];

    /**
     * Default connection configuration parameters.
     *
     * @var array
     */
    protected $defaults = [
        'scheme'            => 'bolt',
        'host'              => 'localhost',
        'port'              => 7687,
        'database'          => 'neo4j',
        'username'          => null,
        'password'          => null,
        'ssl'               => false,
        'enhance_exception' => true,
    ];

    /**
     * The neo4j driver name.
     *
     * @var string
     */
    protected $driverName = 'neo4j';

    /**
     * The query post processor implementation.
     *
     * @var \Illuminate\Database\Query\Processors\Processor
     */
    protected $postProcessor;

    /**
     * Create a new database connection instance.
     *
     * @param array $config The database connection configuration
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;

        // activate and set the database client connection
        $this->neo = $this->createConnection();

        // We need to initialize a query grammar and the query post processors
        // which are both very important parts of the database abstractions
        // so we initialize these to their default values while starting.
        $this->useDefaultQueryGrammar();

        $this->useDefaultPostProcessor();
    }

    /**
     * Create a new Neo4j client.
     *
     * @return ClientInterface
     */
    public function createConnection()
    {
        $client = DatabaseDriver::create($this->getConfig());

        return $client;
    }

    public function getScheme(array $config)
    {
        return Arr::get($config, 'scheme', $this->defaults['scheme']);
    }

    /**
     * Get the currenty active database client.
     *
     * @return ClientInterface
     */
    public function getClient()
    {
        return $this->neo;
    }

    /**
     * Set the client responsible for the
     * database communication.
     *
     * @param ClientInterface $client
     */
    public function setClient(ClientInterface $client)
    {
        if ($this->transactions > 0 || $this->transactionCallbackRunning) {
            throw new LogicException('Cannot replace the client during a Neo4j transaction.');
        }
        $this->neo = $client;
    }

    /**
     * Get the connection host.
     *
     * @return string
     */
    public function getHost()
    {
        return $this->getConfig('host');
    }

    /**
     * Get the connection port.
     *
     * @return int|string
     */
    public function getPort()
    {
        return $this->getConfig('port');
    }

    /**
     * Get the connection username.
     *
     * @return int|string
     */
    public function getUsername()
    {
        return $this->getConfig('username');
    }

    /**
     * Get the connection password.
     *
     * @return int|string
     */
    public function getPassword()
    {
        return $this->getConfig('password');
    }

    /**
     * Get the database name to connect to
     * @return string | null
     */
    public function getDatabaseName()
    {
        return $this->getConfig('database');
    }

    /**
     * Get the connection ssl setting.
     *
     * @return bool
     */
    public function getSsl()
    {
        return $this->getConfig('ssl');
    }

    /**
     * Get an option from the configuration options.
     *
     * @param string|null $option
     *
     * @return mixed
     */
    public function getConfig($option = null)
    {
        return Arr::get(array_merge($this->defaults, $this->config), $option);
    }

    /**
     * Get the Neo4j driver name.
     *
     * @return string
     */
    public function getDriverName()
    {
        return $this->driverName;
    }

    /**
     * Run a select statement against the database.
     *
     * @param string $query
     * @param array  $bindings
     * @param bool   $useReadPdo
     *
     * @return array
     */
    public function select($query, $bindings = [], $useReadPdo = false, array $fetchUsing = [])
    {
        return $this->run($query, $bindings, function (self $me, $query, array $bindings) {
            if ($me->pretending()) {
                return [];
            }

            // For select statements, we'll simply execute the query and return an array
            // of the database result set. Each element in the array will be a single
            // node from the database, and will either be an array or objects.
            $statement = $me->getCypherQuery($query, $bindings);

            return $statement->getResultSet();
        });
    }

    /**
     * Run a Cypher statement and get the number of nodes affected.
     *
     * @param string $query
     * @param array  $bindings
     *
     * @return int
     */
    public function affectingStatement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function (self $me, $query, array $bindings) {
            if ($me->pretending()) {
                return 0;
            }

            // For update or delete statements, we want to get the number of rows affected
            // by the statement and return that back to the developer. We'll first need
            // to execute the statement and then we'll use CypherQuery to fetch the affected.
            $statement = $me->getCypherQuery($query, $bindings);

            return $statement->getResultSet();
        });
    }

    /**
     * Execute a Cypher statement and return the boolean result.
     *
     * @param string $query
     * @param array  $bindings
     *
     * @return bool|ResultSetInterface When $result is set to true.
     */
    public function statement($query, $bindings = [], $rawResults = false)
    {
        return $this->run($query, $bindings, function (self $me, $query, array $bindings) use ($rawResults) {
            if ($me->pretending()) {
                return true;
            }

            $statement = $me->getCypherQuery($query, $bindings);

            $result = $statement->getResultSet();

            return ($rawResults === true) ? $result : $result instanceof ResultSetInterface;
        });
    }

    /**
     * Make a query out of a Cypher statement
     * and the bindings values.
     *
     * @param string $query
     * @param array  $bindings
     *
     * @return CypherQuery
     */
    public function getCypherQuery($query, array $bindings)
    {
        return new CypherQuery($this->getClient(), $query, $this->prepareBindings($bindings));
    }

    /**
     * Prepare the query bindings for execution.
     *
     * @param array $bindings
     *
     * @return array
     */
    public function prepareBindings(array $bindings)
    {
        $grammar = $this->getQueryGrammar();
        $prepared = [];

        foreach ($bindings as $key => $binding) {
            if (!is_int($key)) {
                // Named parameters are values, including maps and empty lists.
                // Never flatten them into unrelated top-level parameters.
                $prepared[$key] = $binding instanceof \DateTimeInterface
                    ? $binding->format($grammar->getDateFormat()) : $binding;
                if ($key === 'id') {
                    // Preserve the legacy grammar alias as well as raw Cypher $id.
                    $alias = $grammar->getIdReplacement($key);
                    if (!array_key_exists($alias, $bindings)) {
                        $prepared[$alias] = $prepared[$key];
                    }
                }
                continue;
            }

            // Legacy builder/caller shape: [['name' => value], ['id' => value]].
            foreach (is_array($binding) ? $binding : ['id' => $binding] as $property => $value) {
                $property = is_int($property) ? 'id' : $property;
                if ($property === 'id') {
                    $property = $grammar->getIdReplacement($property);
                }
                $prepared[$property] = $value instanceof \DateTimeInterface
                    ? $value->format($grammar->getDateFormat()) : $value;
            }
        }

        return $prepared;
    }

    /**
     * Get the query grammar used by the connection.
     *
     * @return \Vinelab\NeoEloquent\Query\Grammars\CypherGrammar
     */
    public function getQueryGrammar()
    {
        if (!$this->queryGrammar) {
            $this->useDefaultQueryGrammar();
        }

        return $this->queryGrammar;
    }

    /**
     * Get the default query grammar instance.
     *
     * @return \Vinelab\NeoEloquent\Query\Grammars\CypherGrammar
     */
    protected function getDefaultQueryGrammar()
    {
        return new Query\Grammars\CypherGrammar($this);
    }

    /**
     * A binding should always be in an associative
     * form of a key=>value, otherwise we will not be able to
     * consider it a valid binding and replace its values in the query.
     * This function validates whether the binding is valid to be used.
     *
     * @param array $binding
     *
     * @return bool
     */
    public function isBinding(array $binding)
    {
        if (!empty($binding)) {
            // A binding is valid only when the key is not a number
            $keys = array_keys($binding);

            return !is_numeric(reset($keys));
        }

        return false;
    }

    /**
     * Run a single, non-nested transaction. Only pre-commit transient failures
     * are retried, and only after a successful rollback. Commit outcomes may
     * be ambiguous: never replay a callback after attempting commit.
     */
    public function transaction(Closure $callback, $attempts = 1)
    {
        if (!is_int($attempts) || $attempts < 1) {
            throw new InvalidArgumentException('Transaction attempts must be a positive integer.');
        }
        if ($this->transactions > 0 || $this->transactionCallbackRunning) {
            throw new LogicException('Nested Neo4j transactions are not supported.');
        }

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $this->beginTransaction();
            } catch (Throwable $error) {
                if ($this->retryableBeginFailure && $attempt < $attempts) {
                    usleep(min(100000, 10000 * $attempt));
                    continue;
                }
                throw $error;
            }

            try {
                $this->transactionCallbackRunning = true;
                try {
                    $result = $callback($this);
                } finally {
                    $this->transactionCallbackRunning = false;
                }
            } catch (Throwable $error) {
                $rolledBack = false;
                try {
                    $this->rollBack();
                    $rolledBack = true;
                } catch (Throwable $cleanupError) {
                    // Preserve the callback's original Throwable, and do not retry
                    // when cleanup could not be confirmed.
                }
                if ($rolledBack && $attempt < $attempts && $this->isRetryableTransactionError($error)) {
                    usleep(min(100000, 10000 * $attempt));
                    continue;
                }
                throw $error;
            }

            $this->commit();

            return $result;
        }
    }

    protected function isRetryableTransactionError(Throwable $error): bool
    {
        do {
            if ($error instanceof Neo4jException) {
                return $error->getClassification() === 'TransientError';
            }
            $error = $error->getPrevious();
        } while ($error !== null);

        return false;
    }

    /** Start one real graph transaction; Neo4j savepoints are not supported. */
    public function beginTransaction()
    {
        $this->retryableBeginFailure = false;
        if ($this->transactions > 0 || $this->transactionCallbackRunning) {
            throw new LogicException('Nested Neo4j transactions are not supported.');
        }
        foreach ($this->beforeStartingTransaction as $callback) {
            $callback($this);
        }

        try {
            $transaction = $this->neo->beginTransaction();
        } catch (Throwable $error) {
            $this->retryableBeginFailure = $this->isRetryableTransactionError($error);
            throw $error;
        }
        if (!$transaction instanceof TransactionInterface) {
            throw new LogicException('The Neo4j client did not open a real transaction.');
        }
        $this->transaction = $transaction;
        $this->transactions = 1;

        try {
            $this->transactionsManager?->begin($this->getName(), 1);
            $this->fireConnectionEvent('beganTransaction');
        } catch (Throwable $error) {
            try {
                $this->rollBack();
            } catch (Throwable $cleanupError) {
            }
            throw $error;
        }
    }

    public function commit()
    {
        if ($this->transactionCallbackRunning) {
            throw new LogicException('Do not manually commit inside a transaction callback.');
        }
        if ($this->transactions !== 1 || $this->transaction === null) {
            throw new LogicException('There is no active Neo4j transaction to commit.');
        }

        try {
            $this->fireConnectionEvent('committing');
            $this->transaction->commit();
        } catch (Throwable $error) {
            $this->commitOutcomeUnknown = true;
            try {
                $this->rollBack();
            } catch (Throwable $cleanupError) {
            } finally {
                $this->commitOutcomeUnknown = false;
            }
            throw $error;
        }

        $this->transaction = null;
        $this->transactions = 0;
        $this->rollbackCallbacks = [];
        // Callback/event errors after this point must never cause rollback or retry.
        $this->transactionsManager?->commit($this->getName(), 1, 0);
        $this->fireConnectionEvent('committed');
    }

    public function rollBack($toLevel = null)
    {
        if ($this->transactionCallbackRunning) {
            throw new LogicException('Do not manually roll back inside a transaction callback.');
        }
        if ($toLevel !== null && $toLevel !== 0) {
            throw new InvalidArgumentException('Neo4j only supports rollback of the entire transaction (level 0).');
        }
        if ($this->transactions === 0) {
            return;
        }

        $error = null;
        try {
            $this->transaction->rollBack();
        } catch (Throwable $failure) {
            $error = $failure;
        } finally {
            $this->transaction = null;
            $this->transactions = 0;
        }
        $callbacks = $this->rollbackCallbacks;
        $this->rollbackCallbacks = [];
        try {
            $this->transactionsManager?->rollback($this->getName(), 0);
            if ($error === null && !$this->commitOutcomeUnknown) {
                foreach ($callbacks as $callback) {
                    $callback();
                }
                $this->fireConnectionEvent('rollingBack');
            }
        } catch (Throwable $notificationError) {
            $error = $error ?? $notificationError;
        }
        if ($error !== null) {
            throw $error;
        }
    }

    /** Run rollback callbacks only after confirmed rollback and cleared bookkeeping. */
    public function afterRollBack($callback)
    {
        if (!$this->transactionsManager) {
            throw new \RuntimeException('Transactions Manager has not been set.');
        }
        if ($this->transactions > 0) {
            $this->rollbackCallbacks[] = $callback;
        }
    }

    /**
     * Begin a fluent query against a database table.
     * In neo4j's terminologies this is a node.
     *
     * @param string      $table
     * @param string|null $as
     *
     * @return \Vinelab\NeoEloquent\Query\Builder
     */
    public function table($table, $as = null)
    {
        $query = new Builder($this, $this->getQueryGrammar(), $this->getPostProcessor());

        return $query->from($table, $as);
    }

    /**
     * Run a Cypher statement and log its execution context.
     *
     * @param string  $query
     * @param array   $bindings
     * @param Closure $callback
     *
     * @throws QueryException
     *
     * @return mixed
     */
    protected function run($query, $bindings, Closure $callback)
    {
        $start = microtime(true);

        // To execute the statement, we'll simply call the callback, which will actually
        // run the Cypher against the Neo4j connection. Then we can calculate the time it
        // took to execute and log the query Cypher, bindings and time in our memory.
        try {
            $result = $callback($this, $query, $bindings);
        }

        // If an exception occurs when attempting to run a query, we'll format the error
        // message to include the bindings with Cypher, which will make this exception a
        // lot more helpful to the developer instead of just the database's errors.
        catch (Exception $e) {
            $enhanceException = Arr::get($this->config, 'enhance_exception', $this->defaults['enhance_exception']);
            if ($enhanceException === true) {
                throw new QueryException($query, $bindings, $e);
            }

            throw $e;
        }

        // Once we have run the query we will calculate the time that it took to run and
        // then log the query, bindings, and execution time so we will report them on
        // the event that the developer needs them. We'll log time in milliseconds.
        $time = $this->getElapsedTime($start);

        $this->logQuery($query, $bindings, $time);

        return $result;
    }

    /**
     * Set the schema grammar used by the connection.
     *
     * @param  \Illuminate\Database\Schema\Grammars\Grammar
     *
     * @return void
     */
    public function setSchemaGrammar(IlluminateSchemaGrammar $grammar)
    {
        $this->schemaGrammar = $grammar;
    }

    /**
     * Get the schema grammar used by the connection.
     *
     * @return \Illuminate\Database\Schema\Grammars\Grammar
     */
    public function getSchemaGrammar()
    {
        return $this->schemaGrammar;
    }

    /**
     * Get the default schema grammar instance.
     *
     * @return \Illuminate\Database\Schema\Grammars\Grammar
     */
    protected function getDefaultSchemaGrammar()
    {
    }

    /**
     * Get a schema builder instance for the connection.
     *
     * @return \Vinelab\NeoEloquent\Schema\Builder
     */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new Schema\Builder($this);
    }

    /**
     * Get the last Id created by Neo4J.
     *
     * @return int
     */
    public function lastInsertedId()
    {
        $query = 'MATCH (n) RETURN MAX(id(n)) AS lastIdCreated';

        $statement = $this->getCypherQuery($query, []);
        $result = $statement->getResultSet();

        return $result[0][0];
    }
}
