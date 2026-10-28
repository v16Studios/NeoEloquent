<?php

namespace Vinelab\NeoEloquent\Tests\Transactions;

require_once __DIR__.'/TransactionFaults.php';

use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\DatabaseTransactionsManager;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Databags\Neo4jError;
use Laudis\Neo4j\Exception\Neo4jException;
use PHPUnit\Framework\TestCase;
use Vinelab\NeoEloquent\Connection;
use Vinelab\NeoEloquent\Eloquent\Model;
use Vinelab\NeoEloquent\DatabaseDriver\Drivers\Laudis\Laudis;
use Vinelab\NeoEloquent\QueryException;

class Account extends Model
{
    protected $label = 'TransactionFixture';
    protected $guarded = [];
    public $timestamps = false;

    public function assets()
    {
        return $this->hasMany(self::class, 'OWNS');
    }
}

class GraphConnection extends Connection
{
    public function getPdo()
    {
        throw new \LogicException('Graph transactions must never call PDO.');
    }
}

class GraphTransactionTest extends TestCase
{
    protected $connection;
    protected $observer;

    protected function config(): array
    {
        return ['name' => 'default', 'driver' => 'neo4j', 'scheme' => 'bolt', 'host' => '127.0.0.1', 'port' => 7687, 'database' => 'neo4j'];
    }

    protected function setUp(): void
    {
        if (getenv('NEOELOQUENT_DISPOSABLE_GRAPH') !== '1') {
            $this->markTestSkipped('Use the isolated compatibility Compose harness.');
        }
        $this->connection = new GraphConnection($this->config());
        $resolver = new ConnectionResolver(['default' => $this->connection]);
        $resolver->setDefaultConnection('default');
        Model::setConnectionResolver($resolver);
        $this->observer = ClientBuilder::create()->withDriver('default', 'bolt://127.0.0.1:7687')->build();
    }

    protected function tearDown(): void
    {
        if ($this->connection && $this->connection->transactionLevel()) {
            try {
                $this->connection->rollBack();
            } catch (\Throwable $ignored) {
            }
        }
        if ($this->observer) {
            $this->observer->run('MATCH (n:TransactionFixture) DETACH DELETE n')->preload();
        }
        parent::tearDown();
    }

    protected function countNodes(): int
    {
        return $this->observer->run('MATCH (n:TransactionFixture) RETURN count(n) AS count')->first()->get('count');
    }

    protected function transientError(): Neo4jException
    {
        return new Neo4jException([Neo4jError::fromMessageAndCode('Neo.TransientError.Transaction.DeadlockDetected', 'Injected transient failure')]);
    }

    protected function assertRecovered(): void
    {
        $this->assertSame(0, $this->connection->transactionLevel());
        $this->assertSame('recovery', $this->connection->transaction(fn () => Account::create(['name' => 'recovery'])->name));
    }

    public function testMixedWritesRollbackAndPreserveTheOriginalThrowable(): void
    {
        $owner = Account::create(['name' => 'owner', 'balance' => 10]);
        $cachedQuery = Account::query();
        $helper = $this->connection->getClient()->makeNode()->setProperties(['name' => 'helper']);
        $rawQuery = $this->connection->getCypherQuery('CREATE (:TransactionFixture {name: $name})', ['name' => 'prepared']);
        $failure = new \TypeError('failure after mixed writes');
        try {
            $this->connection->transaction(function ($db) use ($owner, $cachedQuery, $helper, $rawQuery, $failure) {
                $this->assertSame($this->connection, $db);
                $owner->update(['balance' => 5]);
                $asset = $cachedQuery->create(['name' => 'asset']);
                $owner->assets()->save($asset, ['quantity' => 1]);
                $helper->save()->addLabels(['TransactionFixture']);
                $rawQuery->getResultSet();
                $db->getClient()->run('CREATE (:TransactionFixture {name: "raw-client"})');
                $db->statement('CREATE (:TransactionFixture {name: $name})', ['name' => 'statement']);
                $this->assertSame(1, $this->countNodes(), 'Another client must not see uncommitted writes.');
                throw $failure;
            });
            $this->fail('Expected the original throwable.');
        } catch (\Throwable $caught) {
            $this->assertSame($failure, $caught, get_class($caught).': '.$caught->getMessage());
        }
        $this->assertSame(0, $this->connection->transactionLevel());
        $this->assertSame(1, $this->countNodes());
        $this->assertSame(10, Account::findOrFail($owner->getKey())->balance);
        $this->assertCount(0, Account::findOrFail($owner->getKey())->assets);
        $this->assertSame('recovered', $this->connection->transaction(fn () => Account::create(['name' => 'recovered'])->name));
    }

    public function testMixedWritesCommitOnceAndReturnTheCallbackValue(): void
    {
        $token = new \stdClass();
        $result = $this->connection->transaction(function () use ($token) {
            $owner = Account::create(['name' => 'owner', 'balance' => 5]);
            $owner->assets()->create(['name' => 'asset'], ['quantity' => 2]);
            $this->assertSame(0, $this->countNodes());
            return $token;
        });
        $this->assertSame($token, $result);
        $this->assertSame(0, $this->connection->transactionLevel());
        $this->assertSame(2, $this->countNodes());
        $this->assertCount(1, Account::where('name', 'owner')->firstOrFail()->assets);
    }

    public function testNestedTransactionsAreRejectedBeforeTheInnerCallback(): void
    {
        $ran = false;
        $this->connection->transaction(function ($db) use (&$ran) {
            Account::create(['name' => 'outer']);
            try {
                $db->transaction(function () use (&$ran) { $ran = true; });
                $this->fail('Nested transactions must be rejected.');
            } catch (\LogicException $expected) {
                $this->assertSame(1, $db->transactionLevel());
            }
        });
        $this->assertFalse($ran);
        $this->assertSame(1, $this->countNodes());
    }

    public function testManualLifecycleAndPreparedHelpersUseTheCurrentTransaction(): void
    {
        $node = $this->connection->getClient()->makeNode()->setProperties(['name' => 'manual']);
        $this->connection->beginTransaction();
        $node->save()->addLabels(['TransactionFixture']);
        $this->assertSame(0, $this->countNodes());
        $this->connection->rollBack(0);
        $this->assertSame(0, $this->countNodes());
        $this->connection->beginTransaction();
        Account::create(['name' => 'committed']);
        $this->connection->commit();
        $this->assertSame(1, $this->countNodes());
        $this->connection->rollBack();
        $this->assertSame(0, $this->connection->transactionLevel());
    }

    public function testAfterCommitCallbacksAreReleasedOnlyAfterPersistence(): void
    {
        $this->connection->setTransactionManager(new DatabaseTransactionsManager());
        $observed = [];
        $this->connection->transaction(function ($db) use (&$observed) {
            Account::create(['name' => 'committed']);
            $db->afterCommit(function () use (&$observed) { $observed[] = $this->countNodes(); });
            $this->assertSame([], $observed);
        });
        $this->assertSame([1], $observed);
        try {
            $this->connection->transaction(function ($db) use (&$observed) {
                Account::create(['name' => 'rolled-back']);
                $db->afterCommit(function () use (&$observed) { $observed[] = -1; });
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $expected) {
        }
        $this->connection->transaction(fn () => null);
        $this->assertSame([1], $observed);
    }

    public function testTransientFailureRetriesWholeCallbackWithoutDuplicateNodesEdgesOrBalances(): void
    {
        $owner = Account::create(['name' => 'owner', 'balance' => 10]);
        $attempts = 0;
        $this->connection->transaction(function () use ($owner, &$attempts) {
            $attempts++;
            $current = Account::findOrFail($owner->getKey());
            $this->assertSame(10, $current->balance, 'Each attempt must start from persisted state.');
            $current->update(['balance' => 5]);
            $current->assets()->create(['name' => 'asset'], ['quantity' => 1]);
            if ($attempts === 1) {
                // Verify the wrapper preserves the native error used for classification.
                throw new QueryException('synthetic statement', [], $this->transientError());
            }
        }, 3);
        $this->assertSame(2, $attempts);
        $this->assertSame(2, $this->countNodes());
        $this->assertSame(5, Account::findOrFail($owner->getKey())->balance);
        $this->assertCount(1, Account::findOrFail($owner->getKey())->assets);
    }

    public function testRetryLimitAndNonTransientFailures(): void
    {
        foreach ([$this->transientError(), new \RuntimeException('application failure')] as $failure) {
            $attempts = 0;
            try {
                $this->connection->transaction(function () use (&$attempts, $failure) {
                    $attempts++;
                    Account::create(['name' => 'rolled-back']);
                    throw $failure;
                }, 2);
                $this->fail('Expected failure.');
            } catch (\Throwable $caught) {
                $this->assertSame($failure, $caught, get_class($caught).': '.$caught->getMessage());
            }
            $this->assertSame($failure instanceof Neo4jException ? 2 : 1, $attempts);
            $this->assertSame(0, $this->countNodes());
            $this->assertSame(0, $this->connection->transactionLevel());
        }
    }

    public function testFailedBeginDoesNotRunCallbackOrLeaveTransactionState(): void
    {
        $badConfig = $this->config();
        $badConfig['database'] = 'nonexistent-v16-test-database';
        $this->connection->setClient(new Laudis($badConfig));
        $ran = false;
        try {
            $this->connection->transaction(function () use (&$ran) { $ran = true; });
            $this->fail('An unknown database must fail during begin.');
        } catch (Neo4jException $expected) {
            $this->assertStringContainsString('DatabaseNotFound', $expected->getNeo4jCode());
        }
        $this->assertFalse($ran);
        $this->assertSame(0, $this->connection->transactionLevel());
        $this->connection->setClient(new Laudis($this->config()));
        $this->assertRecovered();
    }

    public function testTransientBeginFailureRetriesBeforeRunningCallback(): void
    {
        $client = new FaultingLaudis($this->config());
        $client->beginFailure = $this->transientError();
        $this->connection->setClient($client);
        $calls = 0;
        $this->connection->transaction(function () use (&$calls) {
            $calls++;
            Account::create(['name' => 'created-once']);
        }, 2);
        $this->assertSame(2, $client->begins);
        $this->assertSame(1, $calls);
        $this->assertSame(1, $this->countNodes());
    }

    public function testCommitFailureBeforeOrAfterPersistenceNeverReplaysCallback(): void
    {
        $this->connection->setTransactionManager(new DatabaseTransactionsManager());
        $notifications = [];
        $events = new \Illuminate\Events\Dispatcher();
        $events->listen(\Illuminate\Database\Events\TransactionRolledBack::class, function () use (&$notifications) { $notifications[] = 'rollback-event'; });
        $this->connection->setEventDispatcher($events);
        foreach ([false, true] as $commitBeforeFailure) {
            $client = new FaultingLaudis($this->config());
            $failure = $this->transientError();
            $client->commitFailure = $failure;
            $client->commitBeforeFailure = $commitBeforeFailure;
            $this->connection->setClient($client);
            $calls = 0;
            $before = $this->countNodes();
            try {
                $this->connection->transaction(function ($db) use (&$calls, &$notifications) {
                    $calls++;
                    Account::create(['name' => 'ambiguous-commit']);
                    $db->afterCommit(function () use (&$notifications) { $notifications[] = 'commit'; });
                    $db->afterRollBack(function () use (&$notifications) { $notifications[] = 'rollback'; });
                }, 3);
                $this->fail('Expected commit failure.');
            } catch (\Throwable $caught) {
                $this->assertSame($failure, $caught, get_class($caught).': '.$caught->getMessage());
            }
            $this->assertSame(1, $calls);
            $this->assertSame($before + (int) $commitBeforeFailure, $this->countNodes());
            $this->assertRecovered();
            $this->assertSame([], $notifications, 'An unknown commit outcome must not be reported as a rollback or replay old callbacks.');
        }
    }

    public function testTerminatedServerTransactionFailsCommitAndRecovers(): void
    {
        $calls = 0;
        try {
            $this->connection->transaction(function ($db) use (&$calls) {
                $calls++;
                $db->statement('CALL tx.setMetaData({fixture: "v16-terminated"})');
                Account::create(['name' => 'terminated']);
                $id = $this->observer->run('SHOW TRANSACTIONS YIELD transactionId, metaData WHERE metaData.fixture = "v16-terminated" RETURN transactionId')->first()->get('transactionId');
                $this->observer->run('TERMINATE TRANSACTIONS $tx', ['tx' => $id])->preload();
            }, 3);
            $this->fail('Expected the server to reject commit.');
        } catch (Neo4jException $expected) {
            $this->assertStringContainsString('Terminated', $expected->getNeo4jCode());
        }
        $this->assertSame(1, $calls);
        $this->assertSame(0, $this->countNodes());
        $this->assertRecovered();
    }

    public function testRollbackFailureIsExposedAndPreventsRetry(): void
    {
        $client = new FaultingLaudis($this->config());
        $cleanupError = new \RuntimeException('rollback acknowledgement failed');
        $client->rollbackFailure = $cleanupError;
        $this->connection->setClient($client);
        $this->connection->beginTransaction();
        Account::create(['name' => 'manual']);
        try {
            $this->connection->rollBack();
            $this->fail('Expected rollback failure.');
        } catch (\Throwable $caught) {
            $this->assertSame($cleanupError, $caught);
        }
        $this->assertSame(0, $this->connection->transactionLevel());
        $client->rollbackFailure = $cleanupError;
        $primary = $this->transientError();
        $calls = 0;
        try {
            $this->connection->transaction(function () use (&$calls, $primary) {
                $calls++;
                Account::create(['name' => 'callback']);
                throw $primary;
            }, 3);
            $this->fail('Expected callback failure.');
        } catch (\Throwable $caught) {
            $this->assertSame($primary, $caught);
        }
        $this->assertSame(1, $calls);
        $this->assertSame(0, $this->countNodes());
        $this->assertRecovered();
    }

    public function testCaughtQueryFailurePoisonsTransactionAndNeverFallsBackToAutocommit(): void
    {
        $primary = null;
        try {
            $this->connection->transaction(function ($db) use (&$primary) {
                Account::create(['name' => 'before-error']);
                try {
                    $db->statement('THIS IS INVALID CYPHER');
                } catch (QueryException $caught) {
                    $primary = $caught->getPrevious();
                }
                try {
                    $db->getClient()->run('CREATE (:TransactionFixture {name: "must-not-autocommit"})');
                    $this->fail('A failed transaction must reject further statements.');
                } catch (Neo4jException $caught) {
                    $this->assertSame($primary, $caught);
                }
                // Even if both errors are caught, commit must fail.
            });
            $this->fail('Expected commit to reject a failed transaction.');
        } catch (Neo4jException $caught) {
            $this->assertSame($primary, $caught);
        }
        $this->assertSame(0, $this->countNodes());
        $this->assertRecovered();
    }

    public function testManualClosureAndClientReplacementAreRejectedInsideCallback(): void
    {
        $this->connection->transaction(function ($db) {
            foreach ([fn () => $db->commit(), fn () => $db->rollBack(), fn () => $db->beginTransaction(), fn () => $db->setClient(new Laudis($this->config()))] as $operation) {
                try {
                    $operation();
                    $this->fail('Expected unsafe lifecycle operation to fail.');
                } catch (\LogicException $expected) {
                    $this->assertSame(1, $db->transactionLevel());
                }
            }
            Account::create(['name' => 'outer']);
        });
        $this->assertSame(1, $this->countNodes());
    }

    public function testPostCommitCallbackFailureDoesNotReplayCommittedWrites(): void
    {
        $this->connection->setTransactionManager(new DatabaseTransactionsManager());
        $failure = new \RuntimeException('after commit failed');
        $calls = 0;
        try {
            $this->connection->transaction(function ($db) use (&$calls, $failure) {
                $calls++;
                Account::create(['name' => 'committed']);
                $db->afterCommit(function () use ($failure) { throw $failure; });
            }, 3);
            $this->fail('Expected post-commit callback error.');
        } catch (\Throwable $caught) {
            $this->assertSame($failure, $caught, get_class($caught).': '.$caught->getMessage());
        }
        $this->assertSame(1, $calls);
        $this->assertSame(1, $this->countNodes());
        $this->assertRecovered();
    }

    public function testCachedNodeAndRelationshipHelpersUpdateDeleteAndRollback(): void
    {
        $client = $this->connection->getClient();
        $left = $client->makeNode()->setProperties(['name' => 'left', 'balance' => 10])->save()->addLabels(['TransactionFixture']);
        $right = $client->makeNode()->setProperties(['name' => 'right'])->save()->addLabels(['TransactionFixture']);
        $edge = $client->makeRelationship()->setStartNode($left)->setEndNode($right)->setDirection('out')->setType('OWNS')->setProperties(['quantity' => 5, 'enabled' => true])->save();
        $left = $client->getNode($left->getId());
        $change = function () use ($client, $left, $right, $edge) {
            $left->setProperty('balance', 0);
            $left->save();
            $edge->setProperty('quantity', 0)->setProperty('enabled', false)->save();
            $this->assertSame(0, $client->getNode($left->getId())->getProperties()['balance']);
            $found = $left->findPathsTo($right, 'OWNS', 'out');
            $this->assertSame(0, $found[0]->getProperties()['quantity']);
            $this->assertFalse($found[0]->getProperties()['enabled']);
            $empty = $client->makeNode()->save()->addLabels(['TransactionFixture']);
            $empty->delete();
        };
        $failure = new \RuntimeException('rollback helpers');
        try {
            $this->connection->transaction(function () use ($change, $right, $failure) {
                $change();
                $right->delete();
                throw $failure;
            });
            $this->fail('Expected rollback.');
        } catch (\Throwable $caught) {
            $this->assertSame($failure, $caught, get_class($caught).': '.$caught->getMessage());
        }
        $this->assertSame(10, $client->getNode($left->getId())->getProperties()['balance']);
        $this->assertSame(5, $left->getRelationships('OWNS', 'out')[0]->getProperties()['quantity']);
        $this->assertSame(2, $this->countNodes());
        $this->connection->transaction($change);
        $this->assertSame(0, $client->getNode($left->getId())->getProperties()['balance']);
        $this->assertSame(0, $left->getRelationships('OWNS', 'out')[0]->getProperties()['quantity']);
        $keys = $this->observer->run('MATCH (n) WHERE id(n) = $id RETURN keys(n) AS keys', ['id' => $left->getId()])->first()->get('keys')->toArray();
        $this->assertNotContains('id', $keys, 'A hydrated virtual ID must not become a stored property.');
        $this->connection->transaction(function () use ($edge, $right) { $edge->delete(); $right->delete(); });
        $this->assertCount(0, $left->getRelationships('OWNS', 'out'));
        $this->assertSame(1, $this->countNodes());
    }

    public function testAttachDetachAndModelDeletionRollBackTogether(): void
    {
        $owner = Account::create(['name' => 'owner']);
        $buyer = Account::create(['name' => 'buyer']);
        $asset = Account::create(['name' => 'asset']);
        $owner->assets()->attach($asset);
        $cachedRelation = $owner->assets();
        $failure = new \RuntimeException('rollback transfer');
        try {
            $this->connection->transaction(function () use ($cachedRelation, $buyer, $asset, $owner, $failure) {
                $cachedRelation->detach($asset);
                $buyer->assets()->attach($asset);
                $this->assertCount(0, $owner->assets()->get());
                $this->assertCount(1, $buyer->assets()->get());
                $owner->delete();
                throw $failure;
            });
            $this->fail('Expected rollback.');
        } catch (\Throwable $caught) {
            $this->assertSame($failure, $caught, get_class($caught).': '.$caught->getMessage());
        }
        $this->assertSame(3, $this->countNodes());
        $this->assertCount(1, Account::findOrFail($owner->getKey())->assets);
        $this->assertCount(0, $buyer->assets()->get());
    }

    public function testUnsupportedSavepointsAndInvalidAttemptCountsFailBeforeWrites(): void
    {
        $this->assertFalse($this->connection->getQueryGrammar()->supportsSavepoints());
        foreach ([0, -1, '2'] as $attempts) {
            try {
                $this->connection->transaction(fn () => Account::create(['name' => 'invalid']), $attempts);
                $this->fail('Expected invalid attempts to fail.');
            } catch (\InvalidArgumentException $expected) {
                $this->assertSame(0, $this->connection->transactionLevel());
            }
        }
        $this->connection->beginTransaction();
        try {
            $this->connection->rollBack(1);
            $this->fail('Expected partial rollback rejection.');
        } catch (\InvalidArgumentException $expected) {
            $this->assertSame(1, $this->connection->transactionLevel());
        }
        $this->connection->rollBack();
        $this->assertSame(0, $this->countNodes());
    }

    public function testServerLockTimeoutRetriesFromFreshStateWithoutDuplicateWrites(): void
    {
        $setting = $this->observer->run('SHOW SETTINGS YIELD name, value WHERE name = "db.lock.acquisition.timeout" RETURN value')->first()->get('value');
        $this->assertSame('1s', $setting, 'Use the configured disposable Compose server.');
        $owner = Account::create(['name' => 'owner', 'balance' => 10]);
        $holder = new GraphConnection($this->config());
        $holder->beginTransaction();
        $holder->statement('MATCH (n) WHERE id(n) = $node SET n.balance = n.balance + 1', ['node' => $owner->getKey()]);
        $attempts = 0;
        $seenBalances = [];
        $serverCode = null;
        try {
            $this->connection->transaction(function () use ($owner, $holder, &$attempts, &$seenBalances, &$serverCode) {
                $attempts++;
                $fresh = Account::findOrFail($owner->getKey());
                $seenBalances[] = $fresh->balance;
                Account::create(['name' => 'invoice']);
                try {
                    $fresh->update(['balance' => $fresh->balance - 1]);
                } catch (QueryException $error) {
                    $serverCode = $error->getPrevious()->getNeo4jCode();
                    // The competing transaction finishes between attempts.
                    $holder->commit();
                    throw $error;
                }
            }, 2);
        } finally {
            $holder->rollBack();
        }
        $this->assertSame('Neo.TransientError.Transaction.LockAcquisitionTimeout', $serverCode);
        $this->assertSame(2, $attempts);
        $this->assertSame([10, 11], $seenBalances);
        $this->assertSame(10, Account::findOrFail($owner->getKey())->balance);
        $this->assertSame(2, $this->countNodes());
    }

    public function testRollbackTransportFailureDiscardsTheConnectionAndDoesNotRetry(): void
    {
        $client = new FaultingLaudis($this->config());
        $client->rollbackBeforeFailure = false;
        $client->rollbackFailure = new \RuntimeException('rollback could not be sent');
        $this->connection->setClient($client);
        $failure = $this->transientError();
        $calls = 0;
        try {
            $this->connection->transaction(function () use ($failure, &$calls) {
                $calls++;
                Account::create(['name' => 'uncommitted']);
                throw $failure;
            }, 3);
            $this->fail('Expected original callback failure.');
        } catch (\Throwable $caught) {
            $this->assertSame($failure, $caught);
        }
        $this->assertSame(1, $calls);
        $this->assertSame(0, $this->countNodes());
        $this->assertRecovered();
    }

    public function testRollbackCallbacksRunAfterCleanupAndCannotLeakIntoAnotherTransaction(): void
    {
        $this->connection->setTransactionManager(new DatabaseTransactionsManager());
        $notifications = [];
        $failure = new \RuntimeException('primary callback failed');
        try {
            $this->connection->transaction(function ($db) use ($failure, &$notifications) {
                Account::create(['name' => 'rolled-back']);
                $db->afterRollBack(function () use (&$notifications) {
                    $notifications[] = $this->countNodes();
                    $this->assertSame(0, $this->connection->transactionLevel());
                    throw new \RuntimeException('rollback notification failed');
                });
                throw $failure;
            });
        } catch (\Throwable $caught) {
            $this->assertSame($failure, $caught);
        }
        $this->assertSame([0], $notifications);
        $this->assertRecovered();
        $this->assertSame([0], $notifications);
    }
}
