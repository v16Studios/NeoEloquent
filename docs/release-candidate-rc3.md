# NXS 9.9.1-RC3 acceptance

Prepared 2026-11-02 for V16-40 on `upgrade/neoeloquent-9.9-laravel-13`. This is a v16Studios fork candidate based on Ulobby 9.9.0 and the immutable RC1/RC2 fixes. Package identity, dependency constraints, graph identities and transaction semantics are unchanged. Never move published candidate tags.

## Behavior repaired

Nova's grouped search on a company's games bound the related node as `games` but compiled the search predicate against `appModelsGame`. Nested Eloquent builders now inherit their enclosing node alias. Non-polymorphic direct/eager relationship builders target the related node for unqualified predicates and explicitly qualify parent constraints. This covers HasMany, HasOne, BelongsToMany and BelongsTo, including incoming relations.

Each query builder owns a cloned grammar bound to that builder; cloning a builder also rebinds its grammar. Compiling another model or a pagination clone no longer replaces the active alias context. Nested predicates compile through their own grammar while retaining parentheses and boolean grouping.

Nested builders reserve existing named WHERE parameters before adding predicates. Basic and list predicates allocate unused names, preserving different values for the same property across outer, nested and sibling groups. Explicitly named raw parameters remain the caller's responsibility. This does not implement SQL subqueries or expand unsupported polymorphic loading.

## Validation

Run `PHP_BIN=php85 bash tests/compatibility/verify-release.sh 13`, then `12`, serially. The committed matrix locks and PHP 8.5.12 / Neo4j 5.26.32 Community images are unchanged. Only disposable synthetic fixtures are used.

Each of Illuminate 12.70.2 and 13.39.0 passes **48 focused tests / 263 assertions** and **322 full-suite tests / 1,318 assertions**, with no failures or errors. The full suite retains **10 skipped / 12 incomplete** legacy cases.

Results and the original three-defect reproduction are retained in `tests/compatibility/evidence/rc3/`. The focused suite checks real node/edge ID zero, prior transaction/createWith acceptance and the new grouped search/count, repeated parameter, scalar/list ID, interleaved clone and eager/direct relationship cases. Legacy skipped/incomplete cases remain exclusions, not passing coverage.

The independent consumer checker loads the actual installed package, verifies its immutable source commit, boots Laravel 13 and exercises grouped relationship search/count, incoming predicates, repeated bindings, scalar/list IDs and interleaved clones, as well as prior provider/transaction/createWith checks. Preserve its complete manifest/lock and results; a local VCS consumer alone does not prove remote availability.

The application must install the exact candidate from the canonical v16Studios GitHub repository, verify the source commit in its lock and installed metadata, and rerun its normal and cached-bootstrap fixtures. Do not patch vendor source.

[RC1 support boundaries](release-candidate.md), [RC2 behavior](release-candidate-rc2.md) and [transaction restrictions](transactions.md) remain applicable. This query repair does not certify Nova relationship writes, audit/request idempotency, concurrent edits, production migration or deployment.

## Immutable candidate verification

Tag `9.9.1-RC3` resolves to **`af370e3985b29b05ea314317615b0d3fcc11b37d`**. The independent local VCS consumer installed that exact package source and passed all checks above. Strict Composer validation, platform checks and locked reinstall pass. Its complete manifest/lock and output are retained in `tests/compatibility/evidence/rc3/consumer-local/`. The consumer resolved Laudis 3.7.1; the unchanged matrix locks use 3.7.0. Remote publication and application integration are verified separately.
