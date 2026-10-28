# NeoEloquent 9.9.0 fork compatibility — V16-33

This branch starts at upstream tag `9.9.0`, commit `ce76714db4e27614bc1a03322b942b8e342c8297`. The local branch is `upgrade/neoeloquent-9.9-laravel-13`; `origin` is v16Studios/NeoEloquent and `upstream` is ulobby/NeoEloquent. Package identity remains `ulobby/neoeloquent`, including the existing `vinelab/neoeloquent` replacement and `Vinelab\NeoEloquent` namespace. No application dependencies have been switched to this branch.

**This records the V16-33 compatibility checkpoint, not a release candidate.** Transaction support was absent at this checkpoint. Subsequent V16-34 implementation and verification are documented in [transactions.md](transactions.md); V16-35 packaging/CI and V16-36 review remain outstanding. The comparison decisions and test counts below are historical V16-33 evidence.

## Historical fork decisions

Installed application revision: `28c6536acb57208c17d0941736e238835c467e82`, compared with its upstream merge base `70cc519c6c3867c5610b6045be714c1a896f19d4`. All six changed files are accounted for:

| Installed delta | Decision |
| --- | --- |
| `Connection.php`: transaction wrapper | Defer to V16-34; callback wrapping alone cannot enlist Laudis helper writes. Do not port an unverified transaction implementation. |
| `Connection.php`, `Query/Builder.php`: duplicate `$as` PHPDoc | Retire; no runtime effect. |
| `Eloquent/Builder.php`: `simplePaginate()` | Retain current-page resolver, explicit page, model page size, extra-row detection and Laravel's paginator factory. |
| `Eloquent/Model.php`: `snake_case()` | Retain `Str::snake()` and import. |
| `Eloquent/Relations/BelongsToMany.php`: `getPivotClass()` returning `''` | Adapt to an explicit nullable metadata result; see Nova contract below. |
| `Eloquent/Relations/HasOneOrMany.php`: `saveMany()` PHPDoc | Retain correct array parameter and Collection return documentation. |

Modern remote revision: `1199106ace68a91b424b11960856a4948b66bf4e`, compared directly with upstream 9.9.0. Its complete 35-file difference is classified below; paths are relative to the repository root.

| Modern delta/files | Decision |
| --- | --- |
| `src/Connection.php`: Bolt defaults and merged config | Retain Bolt/7687/neo4j defaults with explicit configuration taking precedence. Keep upstream public `getScheme()` and `getDatabaseName()` accessors. |
| `src/Connection.php`: three-argument `select()` | Reject. It fatally conflicts with Laravel 13. Keep upstream's four-argument signature, tested by reflection and an actual bound query. |
| `src/Connection.php`: transaction state/query routing | Defer to V16-34; no transaction changes in this checkpoint. |
| `src/DatabaseDriver/Drivers/ClientAbstract.php` | Retain direct URL/address construction, using the supplied config instead of instance config, and remove empty `@` user info. Encode the database query value. Keep upstream `getDatabase()`. Authentication still uses separate username/password config; URL credentials are not a supported authentication contract. |
| `src/DatabaseDriver/CypherQuery.php`, `Drivers/Laudis/{Laudis,Transaction}.php`, `Interfaces/{ClientInterface,QueryExecutorInterface,TransactionInterface}.php` | Defer transaction executor changes to V16-34. The modern fork still needs proof that node and relationship helpers use the same transaction. |
| `src/Eloquent/Builder.php` | Retain paginator fix. |
| `src/Eloquent/Model.php` | Retain helper fix; reject removal of upstream `qualifyColumn()`, which supports graph soft deletes. |
| `src/Eloquent/Relations/{BelongsToMany,HasOneOrMany}.php` | Adapt pivot metadata; retain PHPDoc corrections as above. |
| `src/NeoEloquentServiceProvider.php` | Keep upstream config/name handling; do not reintroduce the older provider callback. |
| `src/Query/Builder.php` | Retire duplicate PHPDoc. |
| `src/Query/Grammars/{CypherGrammar,Grammar}.php` | Keep upstream Arrayable/empty `whereIn`, array/nested-list formatting and namespaced relationship alias escaping. Stored relationship types are unchanged. |
| `.github/workflows/tests.yml`, `README.md`, `composer.json` | Keep upstream Laravel 12/13 and PHP matrix/package metadata; do not port the older fork's support claims or version constraints. CI/release work belongs to V16-35. |
| `tests/TestCase.php` | Keep upstream order-independent model comparisons; do not remove them. |
| `tests/Vinelab/NeoEloquent/ConnectionTest.php`, `tests/config/database.php` | Preserve modern `$parameter` Cypher syntax rather than old `{parameter}` syntax. New focused tests cover default database selection without copying redundant fixture configuration. |
| `tests/Vinelab/NeoEloquent/DatabaseDriver/{CypherQueryTest.php,Drivers/ClientAbstractTest.php}` | Transaction executor tests deferred; URI/default behavior covered by the new focused suite. |
| `tests/Vinelab/NeoEloquent/{Eloquent/BuilderTest.php,Query/BuilderTest.php,Query/GrammarTest.php}` | Keep upstream framework-aware mocks, grammar constructor and list-format expectations. |
| `tests/functional/AggregateTest.php` | Keep upstream connection-aware grammar construction and floating-point tolerances. |
| `tests/functional/ModelEventsTest.php` | Keep upstream observer registration in `booted()` for Laravel 13. |
| `tests/functional/{BelongsToManyRelationTest,HasManyRelationTest,PolymorphicHyperMorphToTest,QueryingRelationsTest,SimpleCRUDTest,WheresTheTest}.php` | Keep upstream order-independent assertions, valid ID 0 expectations and updated API expectations. Do not restore older order-sensitive or nonzero-ID assertions. |

## Additional compatibility repairs

Nineteen implicitly nullable parameters now have explicit nullable types across relation APIs, relationship queries, query-builder labels and schema callbacks. Their accepted arguments are unchanged. `HasOneOrMany::$type` is declared, allowing the broad dynamic-property allowances on HasOne/HasMany to be removed. Existing test fixture properties are declared and PHPUnit converts deprecations to exceptions.

The helper regression test exposed reads of nonexistent `$relation` on MorphTo/MorphedByOne. These now use the inherited `$relationName`, as does OneRelation's metadata getter. Construction/query compilation is tested for unnamed morphs and the historical `morphTo(RelatedClass::class, 'EDGE_TYPE')` overload. This does not establish full polymorphic hydration support.

## Nova and polymorphic boundaries

`BelongsToMany::getPivotClass(): ?string` returns `null`: graph relationships have edge identities/properties, not an Eloquent SQL Pivot model. This replaces the historical empty-string sentinel. A Nova adapter may use this metadata only after handling null. Edge mutations must use graph relation/edge APIs and preserve direction and relationship IDs. Do not instantiate this return value, use `viaPivotId` as a SQL pivot ID, or substitute `Illuminate\Database\Eloquent\Relations\Pivot`.

Full Nova 5 detach/update/action-event integration is **not verified here**. V16-40 must supply and exercise the application adapter, including nullable metadata handling and relationship-ID mapping. This shim alone does not make Nova's default SQL pivot controllers compatible.

The upstream HyperMorph suite still skips dynamic/eager loading of morphed models and morphing models (four cases), and marks dynamic/eager MorphedBy and MorphTo loading incomplete (four cases). Treat these polymorphic reads/hydration paths, generic SQL-style morph ID/type semantics, and Nova polymorphic fields as unsupported until independently implemented and tested. Do not enable those cases merely because relation construction now succeeds. Existing tested HyperMorph creation/attachment behavior is retained.

## Verification

See [the test harness](../tests/compatibility/README.md) for exact commands and [evidence](../tests/compatibility/evidence/). The fixture graph is isolated with no host ports or external network, and contains synthetic data only. The application environment and credentials are never mounted.

Tested on PHP 8.5.12, Neo4j 5.26.32 Community, Laudis 3.7.0 and PHPUnit 9.6.38, against Illuminate 13.39.0 and 12.70.2. Each focused run starts with a fresh store and verifies real node ID 0. Checks include CRUD, pagination boundaries/custom page names, relationship type/direction/properties, soft deletes, Arrayable/empty `whereIn`, default URI configuration, select signature/bindings and relation metadata/construction.

| Suite | Laravel 13.39.0 | Laravel 12.70.2 |
| --- | --- | --- |
| Focused, fresh Neo4j store | 12 tests / 50 assertions; all pass | 12 tests / 50 assertions; all pass |
| Full suite | 286 tests / 1,101 assertions; no failures/errors | 286 tests / 1,101 assertions; no failures/errors |
| Full-suite exclusions | 12 skipped, 12 incomplete | 12 skipped, 12 incomplete |
| PHP deprecation output | None | None |

The three focused tests against the untouched upstream source reproduce the missing pivot/helper/paginator APIs (expected exit 2). Loading the modern fork's Connection against Laravel 13 reproduces its fatal signature mismatch (expected exit 255). All 68 source/compatibility PHP files pass PHP 8.5 syntax checks without deprecations; Composer validation and whitespace checks pass.

The broader suite's skipped/incomplete cases remain visible. In particular its two skipped transaction tests are not transaction validation. Broader PHP-version CI and release packaging remain V16-35 work.
