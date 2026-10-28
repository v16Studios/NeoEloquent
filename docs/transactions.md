# Real Neo4j transactions — V16-34

This implementation follows the 9.9.0 compatibility checkpoint `e0f8e9c`. It replaces the upstream transaction stubs and Laravel's inherited PDO transaction callback with a single real Laudis unmanaged transaction. The application has not yet been switched to this fork; V16-35 packaging/CI and V16-36 review are still required.

## What participates

All operations using the **same NeoEloquent connection/client adapter** participate: `select`, `statement`, query-builder writes, model create/update/delete, `getClient()->run`, prepared CypherQuery objects, and node/relationship helpers. Helpers and queries created before `beginTransaction()` resolve the active transaction at execution time. Nested helpers created while reading relationships retain the adapter too.

The Laudis adapter owns the current transaction and supplies itself to Node/Relation factories. Routing happens in its `runStatement()` method. CypherQuery and the Eloquent query builder therefore retain their existing client interface; a second connection-level executor abstraction is unnecessary. This is the missing piece in the older fork's connection-only routing approach.

Transactions on different connections remain independent. A manually constructed helper given a separate raw Laudis Client does not join this connection's transaction. Use the connection lifecycle API rather than mixing it with direct driver transaction management. One connection is intended for sequential use, not concurrent requests/fibers sharing mutable transaction state.

## Lifecycle contract

```php
$created = $connection->transaction(function ($connection) {
    return Widget::create(['name' => 'example']);
}, 3);
```

- The driver validates BEGIN against the server before the application callback runs. Failed begin leaves Laravel's transaction level at zero.
- Success commits once and returns the callback's exact value. Failure catches any Throwable, rolls back, clears state and rethrows the original callback error. A secondary cleanup failure does not replace it and disables automatic retry. A directly requested rollback exposes its own failure.
- `beginTransaction()` / `commit()` / `rollBack()` support a manual single transaction. Level is always zero or one. Rollback with no transaction is a no-op; commit without one throws. Only full rollback (`null` or `0`) is accepted.
- Nested callbacks and nested begin calls throw **before the inner callback/write**. The Cypher grammar reports no savepoint support. Laravel operations that internally request nesting, such as inherited `createOrFirst()` inside an existing transaction, must be avoided or adapted at the application layer; no nested/savepoint behavior is emulated. This fork already overrides `saveOrFail()` to call `save()` directly; V16-35 verifies that it participates in an outer transaction without nesting.
- Manual commit/rollback and client replacement are rejected while a managed callback runs. These cannot silently end its transaction and let later statements auto-commit.
- A failed query leaves the transaction unusable. Catching the error inside the callback cannot permit more writes or a partial commit.
- Results inside the transaction are materialized before returning, so deferred fetch failures surface before commit. Large reads must be bounded/chunked; the transactional raw-result path is not streaming.

Helper tests also exposed and repaired existing no-op node updates, empty-property node creation and relationship updates dropping zero/false values. Node update maps do not write hydrated internal IDs back as stored properties. Node labels, relationship types/direction and existing IDs remain unchanged by transaction routing.

## Retry and uncertain outcomes

`attempts` is a positive integer including the first attempt; the default is one. Before-commit retries are bounded and apply only to a native `Neo4jException` classified `TransientError`, including one retained in a QueryException cause chain. No message-text heuristics or generic exception retries are used. Native begin failures can retry before application code runs; begin event/hook failures are not automatically retried. Callback failures retry only after rollback succeeds, with a short bounded delay.

Every retry must create/reload its models and helpers from current persisted state. Objects mutated during a rolled-back attempt retain their in-memory attributes/IDs; database rollback does not rewind PHP objects. Do not perform payments, broadcasts, email or other irreversible external effects inside a retried callback.

**No commit failure is automatically retried**, even when its exception has a transient classification. An acknowledgement can be lost after persistence. The caller receives the original error and must reconcile its durable operation key/status before deciding to repeat the business operation. The fault test covers both failure before persistence and failure after the server has committed, with exactly one callback invocation in either case.

Laravel transaction-manager bookkeeping and after-commit callbacks are updated after graph persistence. Rollback callbacks registered through this connection run after confirmed rollback and cleared bookkeeping. Unknown commit outcomes and failed rollback do not emit a rollback event or run those callbacks. A callback failure cannot leave stale callbacks queued for the next transaction. SQL audit, queues and external effects still require application-level coordination; graph and MySQL are not one distributed transaction.

After driver failure the adapter closes/discards the old connection pool and builds a fresh client. It does not send another rollback on a transaction the driver already marks finished. A real server-termination test exposed a hang in that invalid cleanup sequence; the corrected path is covered by the regression suite. No transaction path falls back to PDO.

## Verification and reproduction

Use [the isolated harness instructions](../tests/compatibility/README.md). Start the Compose server with its **test-only one-second lock acquisition timeout** and run:

```sh
docker compose -f tests/compatibility/compose.yaml up -d --wait neo4j
docker compose -f tests/compatibility/compose.yaml run --rm tests php -d default_socket_timeout=5 vendor/bin/phpunit -c tests/compatibility/transactions.xml --do-not-cache-result
docker compose -f tests/compatibility/compose.yaml run --rm tests php -d default_socket_timeout=5 vendor/bin/phpunit --do-not-cache-result
```

For Laravel 12, use `vendor/laravel12/vendor/bin/phpunit` and `--bootstrap vendor/laravel12/vendor/autoload.php`, prepared with the existing dependency helper. A generated Laravel 13 manifest can be used the same way. Root dependencies in the recorded run resolve Illuminate 13.39.0; the isolated Laravel 12 manifest resolves 12.70.2. Both use PHP 8.5.12, Neo4j 5.26.32, Laudis 3.7.0 and PHPUnit 9.6.38. Earlier supported Laudis/PHP versions are not newly certified by this checkpoint; V16-35 must validate or narrow the release matrix.

The transaction suite verifies mixed raw/model/helper commit and rollback, independent-client visibility, cached helpers, attach/detach/delete, zero/false property updates, nested/lifecycle rejection, original Throwable identity, bounded retries and recovery. It exercises actual server rejection of an unknown database, server termination before commit, and a real competing transaction causing `Neo.TransientError.Transaction.LockAcquisitionTimeout`. On retry the callback reads the competing transaction's newly committed balance and leaves only one invoice.

Additional fault injection wraps real unmanaged transactions to test begin failure, rollback failure before/after the rollback request, commit failure before/after persistence, and notification failures. It does not claim to reproduce every network partition or cluster failover. Application double-spend/concurrency/idempotency contracts remain V16-42/V16-45 work; ordinary transaction atomicity does not supply business locking rules.

Result artifacts are in `tests/compatibility/evidence/transactions/`. Two obsolete skipped transaction tests were replaced with active callback/lifecycle tests. Remaining skipped/incomplete cases concern the previously documented query/polymorphic limits, not transaction validation. No production services or data were used.

| Illuminate | Transaction suite | Full suite | Full-suite exclusions |
| --- | --- | --- | --- |
| 12.70.2 | 21 tests / 128 assertions, all pass | 307 tests / 1,234 assertions, no failures/errors/deprecations | 10 skipped, 12 incomplete |
| 13.39.0 | 21 tests / 128 assertions, all pass | 307 tests / 1,234 assertions, no failures/errors/deprecations | 10 skipped, 12 incomplete |

The before/after script proves the original defect independently of the new assertions: at `e0f8e9c`, a failing callback leaves one committed node and replaces the original exception with a null-transaction rollback error. With this change, no node remains, the original exception is preserved and the transaction level returns to zero. Reproduce against the same disposable server:

```sh
NEOELOQUENT_BASELINE=$(mktemp -d /tmp/nxs-tx-baseline.XXXXXX)
git archive e0f8e9c src | tar -x -C "$NEOELOQUENT_BASELINE"
docker compose -f tests/compatibility/compose.yaml run --rm -v "$NEOELOQUENT_BASELINE/src:/workspace/src:ro" tests php tests/compatibility/reproduce-transactions.php broken
docker compose -f tests/compatibility/compose.yaml run --rm tests php tests/compatibility/reproduce-transactions.php fixed
docker compose -f tests/compatibility/compose.yaml down -v
```

Both script modes exit zero only when their expected behavior is observed; `broken` intentionally confirms the historical failure. Dependency lock hashes and test versions are recorded in `validation.json`; locks remain local to this library checkout.

## Sources checked

- The installed Laudis 3.7.0 `Client`, `BoltUnmanagedTransaction`, `UnmanagedTransactionInterface`, result and connection-pool implementations; Laravel 12/13 `ManagesTransactions` and transaction-manager code.
- [Neo4j transaction lifecycle](https://neo4j.com/docs/operations-manual/current/database-internals/transaction-management/).
- [Server transaction inspection/termination](https://neo4j.com/docs/operations-manual/current/database-internals/show-and-terminate-transactions/).
- [Lock acquisition timeout](https://neo4j.com/docs/operations-manual/current/database-internals/concurrent-data-access/) and [Neo4j transient error codes](https://neo4j.com/docs/status-codes/current/errors/all-errors/). The behavior above was verified on the pinned 5.26.32 test server.
