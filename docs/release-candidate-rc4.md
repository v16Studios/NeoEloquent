# NXS 9.9.1-RC4 acceptance

Prepared 2026-11-02 for V16-40 on `upgrade/neoeloquent-9.9-laravel-13`. RC1–RC3 remain immutable. Package identity, dependency constraints and transaction semantics are unchanged.

Nova's Company edit page calls `exists()`. The inherited SQL implementation generated `select exists(MATCH ...)` and assumed an array response, while this driver returns a ResultSet. The graph builder now compiles a Cypher existential subquery and reads its boolean result through the driver's result API.

The existential subquery contains a clone of the complete select query, preserving related aliases, parent scope, grouped predicates, bindings, projection, distinct, SKIP and LIMIT. Compilation does not populate the caller's columns. Before-query callbacks retain Laravel's once-per-execution behavior. This uses Neo4j 5 syntax, including RETURN within EXISTS; see the [Cypher manual](https://neo4j.com/docs/cypher-manual/current/subqueries/existential/).

## Validation

Run `PHP_BIN=php85 bash tests/compatibility/verify-release.sh 13`, then `12`, serially. The checked-in dependency locks and PHP 8.5.12 / Neo4j 5.26.32 images are unchanged. Both Illuminate 12.70.2 and 13.39.0 pass **50 focused tests / 288 assertions** and **324 full tests / 1,342 assertions**, with no failures or errors. The full suite retains 10 skipped / 12 incomplete legacy cases. Results are retained in `tests/compatibility/evidence/rc4/`.

Regression coverage includes existing/missing rows, numeric ID zero, Unicode, empty ID lists, zero limit, offsets, distinct projections, builder reuse, callbacks, existsOr/doesntExist, parent-scoped outgoing/incoming relationships, nested repeated property bindings and soft-deleted models. Prior transaction and relationship acceptance remains in the suite.

The independent consumer installs the exact immutable candidate and checks existence queries against the installed package alongside the prior transaction/relationship checks. The application must install from the canonical GitHub repository and verify its source reference before normal and cached-bootstrap acceptance. Never patch vendor or move a published tag.

[RC1 support boundaries](release-candidate.md), [RC2](release-candidate-rc2.md), [RC3](release-candidate-rc3.md) and [transaction restrictions](transactions.md) remain applicable. This repair does not certify production migration, deployment or unsupported polymorphic loading.

## Immutable installed candidate

Tag `9.9.1-RC4` resolves to **`d480915e2c5757a057d70204c851e56751e4d639`**. The independent local VCS consumer loads that exact installed source and passes its graph checks, platform validation and locked reinstall. Its complete manifest/lock and output are retained in `tests/compatibility/evidence/rc4/consumer-local/`. Unqualified strict Composer validation reports only the two intentional exact-version pin warnings; `validate --strict --no-check-all` passes. No platform or security checks are excluded. Remote availability and application acceptance are recorded separately.
