# NXS 9.9.1-RC2 acceptance

Prepared 2026-11-01 for V16-38 on `upgrade/neoeloquent-9.9-laravel-13`. Based on the reviewed RC1 fork of Ulobby 9.9.0. Package identity and supported PHP/Illuminate/Laudis constraints are unchanged. This is a v16Studios fork candidate, not an upstream release. Never move RC1 or RC2 after publication; verify the exact tag commit in the consuming lock.

## Behavior repaired

The application's ItemRepository creates an Item attached to both a Binder and a Game. RC1 emitted comma-separated patterns in one MERGE and Neo4j 5 rejected the query. Each attachment now gets its own MERGE in the same statement. See [Neo4j MERGE clauses](https://neo4j.com/docs/cypher-manual/5/clauses/merge/).

Additional regressions found that missing targets could leave created nodes before hydration failed, combinations of attachment groups duplicated returned models, and an empty relation list was treated as attributes for a blank child. Attachment groups are now collected and checked for completeness before CREATE. Duplicate requested IDs represent one target; every distinct requested ID must match the related model's labels. A mismatch throws QueryException with an explanatory cause and no writes from this operation. No separate preflight query or nested transaction is used. Empty relations return an empty collection (or null for a single-valued relation), and parent-only results hydrate correctly.

Auto-commit constraint failure also exposed stale failed Bolt state in a later explicit transaction. The adapter now consumes and rewinds auto-commit results inside its error boundary and discards failed transport state, retaining the original exception. It does not introduce application retries. Reads are materialized, as model/result conversion already requires; this is not a streaming API.

Database atomicity applies to this statement or the caller's outer graph transaction. Model observers and external side effects are not rolled back by Neo4j. Financial locking, idempotency, cross-store coordination and all other [transaction restrictions](transactions.md) remain application responsibilities. Missing-attachment rejection is an intentional stricter behavior than RC1.

## Validation

Run `PHP_BIN=php85 bash tests/compatibility/verify-release.sh 13` and then `12`, serially. The unchanged committed locks and pinned PHP 8.5.12 / Neo4j 5.26.32 Community containers reproduce the matrix. These are disposable synthetic graphs with no published ports. Evidence is in `tests/compatibility/evidence/rc2/`; the RC1 evidence is retained.

Each of Laravel 12.70.2 and 13.39.0: focused **44 tests / 247 assertions**, full **318 tests / 1,302 assertions**, no failures/errors. The full suite retains **10 skipped / 12 incomplete** legacy cases, which are not passing coverage.

Coverage includes real numeric node/edge ID zero, single and multiple attachment groups, mixed new/existing relations, duplicate requested IDs, relation directions, loaded-collection identity, empty lists, missing groups/partially missing groups, all-or-nothing constraint failure, callback rollback/original exception and a subsequent successful explicit transaction.

The independent consumer checker also exercises multi-relation creation and rejects missing attachments without partial writes. Prepare a fresh consumer for this exact candidate, run strict validation/platform checks/locked reinstall and `check-consumer.php` against its 40-character source commit before application acceptance. Preserve its manifest/lock and results separately from RC1. The application must pin this exact version from the canonical v16Studios GitHub repository and verify the installed commit; do not patch vendor source.

The [RC1 support boundaries and upstream retirement rules](release-candidate.md) remain applicable. This candidate does not authorize production deployment or certify the later Nova/GraphQL/financial/migration stages.

## Immutable candidate verification

Tag `9.9.1-RC2` resolves to **`21661a2f209e3bc16cff2a62840e0c52f9d28b5f`**. The independent local VCS consumer installed that exact package source and passed provider, connection, commit/rollback, parameter and both new createWith checks. Strict Composer validation, platform requirements and a subsequent locked install pass. Full consumer manifest/lock and output are preserved in `tests/compatibility/evidence/rc2/consumer-local/`. Publication and the consuming application lock are verified separately; this local consumer does not prove remote availability.
