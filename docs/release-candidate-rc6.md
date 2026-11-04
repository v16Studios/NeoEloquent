# NXS 9.9.1-RC6 acceptance

Prepared 2026-11-04 for [V16-62](https://linear.app/v16studios/issue/V16-62) on `upgrade/neoeloquent-9.9-laravel-13`. RC1–RC5 remain immutable. Dependency constraints, graph identities and transaction restrictions are unchanged.

## Mutation contract

Node and direct HasMany/HasOne/BelongsToMany/BelongsTo bulk update/delete now compile the complete relationship MATCH and WHERE predicates. Previously relationship writes omitted MATCH and failed with a syntax error. Targets are deduplicated before ordering, SKIP and LIMIT; update returns the number of distinct matched nodes, including nodes whose supplied values are already equal. LIMIT zero and unsaved internal-ID parents select no nodes. Custom property keys retain their existing matching semantics.

Deletion removes the selected related **nodes and all their incident edges**, including edges from other parents. Other nodes survive. Use relationship `detach()` or `edge()->delete()` to remove only links. The existing delete return type is unchanged (driver ResultSet); an integer affected-node count is not newly promised. This follows [Neo4j's DETACH DELETE semantics](https://neo4j.com/docs/cypher-manual/5/clauses/delete/).

Direct predicates (including grouped predicates and aliases), target-property ordering and pagination are supported. Read projections do not select writable properties; the mutation values and target node determine the write. Explicit aggregate/group/having/join/union/WITH/with-WHERE query shapes are rejected before executing a mutation, rather than dropping their restrictions. This includes relationship-count queries constructed using WITH. Ordering or assignments referencing parent/edge aliases after target selection fail closed because only the target is in scope. This release does not add expression-valued increments, optional-ID query deletion or arbitrary projection/aggregation mutation support. Existing model-instance, soft-delete and edge APIs retain their contracts.

## Validation

The pre-repair focused run reproduced nine missing-MATCH errors (64 tests / 345 assertions), retained in `tests/compatibility/evidence/rc6/before/`. Regressions now require unsaved-parent operations to execute successfully and leave persisted data intact. Saved-parent coverage spans incoming/outgoing relations, nested/qualified predicates, cloned/interleaved queries, false/zero values, duplicate edges, distinct counts, ordered pagination, LIMIT zero, actual target/non-target state and explicit rollback restoring both nodes and edges. The first fresh-store fixture exercises real node/edge ID zero. Unsupported read-shape guards are checked against unchanged graph state.

NXS application call-site audit found model-instance updates/deletes and explicit edge deletions, but no direct relation-query bulk update/delete in `app/` or `routes/`. In particular, RemoveUserFromCompanyMutation's `$relation` is an edge, and Nova notification bulk writes use SQL. This is a static call-site audit, not proof about all possible dynamic/plugin calls. Installed application acceptance additionally exercises User → Binder writes and ID zero.

Run the locked matrices serially with `PHP_BIN=php85 bash tests/compatibility/verify-release.sh 13`, then `12`. Both locked Illuminate 12.70.2 and 13.39.0 matrices pass **71 focused tests / 475 assertions** and **345 full tests / 1,527 assertions** on PHP 8.5.12 / Neo4j 5.26.32. The same **10 skipped / 12 incomplete** legacy cases remain; they are not passing coverage. Installed-consumer and application results are recorded separately after verification. All graph fixtures are disposable; no production data is used. [RC1 support boundaries](release-candidate.md), [RC5](release-candidate-rc5.md) and [transaction restrictions](transactions.md) continue to apply.

## Immutable installed candidate

Tag `9.9.1-RC6` resolves to **`eb39db27d4e8cfbbedb6b91850a4f4c7cba974e7`**. The independent VCS consumer loads that exact installed source (not the development checkout) with Laravel 13.39.0 and passes every check, including all four relationship directions/types, unsaved mutations, custom string keys, LIMIT zero and rollback. Only NeoEloquent changed among 81 consumer packages; its retained Laudis version is 3.7.1, versus 3.7.0 in the matrix/application locks. Strict manifest/lock validation, platform checks and locked reinstall pass. Full manifest/lock/results are retained in `tests/compatibility/evidence/rc6/consumer-local/`. Remote publication, hosted CI and NXS integration are verified separately.
