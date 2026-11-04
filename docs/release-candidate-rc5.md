# NXS 9.9.1-RC5 acceptance

Prepared 2026-11-04 for V16-61 on `upgrade/neoeloquent-9.9-laravel-13`. RC1–RC4 remain immutable. Package identity, dependency constraints, IDs, label intersections and transaction semantics are unchanged.

## Repair

`where()` delegates null values before its basic ID normalization. Previously `whereNull()` handled bare `id` only, leaving a relationship parent predicate as `parent.id IS NULL`. Nodes without a stored `id` property then matched regardless of internal identity. Qualified `alias.id` is now normalized to `id(alias)` for both null and not-null predicates. Null is never cast to zero. Explicit string `id(alias)` expressions and ordinary property/custom-key predicates retain their meaning; arrays of null-predicate columns are supported. This does not add arbitrary Expression-object support or polymorphic loading.

Application code must still provide a concrete target when creating a model with a dynamic User/Company owner. A query on an unsaved Binder cannot determine its future owner type. NXS addresses that separately with explicit typed relationships and the existing atomic createWith attachment guard.

## Validation

Both locked Illuminate 12.70.2 and 13.39.0 matrices on PHP 8.5.12 / Neo4j 5.26.32 pass **59 focused tests / 365 assertions** and **333 full tests / 1,417 assertions**. The full suite retains **10 skipped / 12 incomplete** legacy cases; these are not passing coverage. Dependencies and pinned images are unchanged. Reproduce serially with `PHP_BIN=php85 bash tests/compatibility/verify-release.sh 13`, then `12`.

Regressions verify empty count/exists/get/first for unsaved HasMany, HasOne, BelongsToMany and BelongsTo parents; persisted direct/eager relations; real ID zero; qualified/bare/explicit IDs; nullable properties and arrays; boolean groups and related aliases. Existing nodes/edges remain unchanged after attempted unsaved-parent bulk update/delete. Those bulk relationship statements currently fail closed with a missing-MATCH syntax error; this safety coverage does **not** certify successful bulk relationship writes. Fixing their compiler is separate work. The installed consumer additionally checks valid node mutations with a null internal-ID predicate and a custom string relationship key.

Evidence: `tests/compatibility/evidence/rc5/`. The repaired-fixture baseline has six failures with no errors (59 tests / 310 assertions); the initial fixture log is retained separately because two tests used an incorrectly cased alias before correction. No production data was used. Independent installed-consumer verification and remote publication are recorded below after completion.

[RC1 support boundaries](release-candidate.md), [RC2](release-candidate-rc2.md), [RC3](release-candidate-rc3.md), [RC4](release-candidate-rc4.md) and [transaction restrictions](transactions.md) remain applicable. This candidate does not certify application-wide acceptance or production migration/deployment.

## Immutable installed candidate

Tag `9.9.1-RC5` resolves to **`b64c8304b7c7866d629bc726799182a01a5d1964`**. The independent local VCS consumer installs that exact source (not a path/symlink package) with Laravel 13.39.0 and passes all graph checks, platform validation, strict manifest/lock validation and locked reinstall. Only the fork changed from the prior consumer lock; its Laudis version remains 3.7.1, while matrix/application locks use 3.7.0. Complete consumer manifest/lock/results are retained in `tests/compatibility/evidence/rc5/consumer-local/`. Intentional exact version pins use `validate --strict --no-check-all`; no platform or security checks are excluded.

Bulk relationship mutation support is tracked separately in [V16-62](https://linear.app/v16studios/issue/V16-62). Remote publication and NXS acceptance are verified separately after this local installed-candidate checkpoint.
