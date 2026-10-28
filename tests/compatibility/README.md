# Reproduce the compatibility checks

Real transaction tests and their lifecycle/retry contract are documented in [transactions.md](../../docs/transactions.md). The Compose graph uses a test-only one-second lock acquisition timeout for its deterministic contention test.

Run from the fork root with Docker/Compose, Composer and PHP 8.5 available. Commands below use `php`; use `php85` locally if the default PHP differs. The library manifest continues to advertise Laravel 12 and 13. The generated manifests and vendor directories stay under ignored `vendor/`.

```sh
php tests/compatibility/prepare-dependencies.php 13
php tests/compatibility/prepare-dependencies.php 12
php "$(command -v composer)" --working-dir=vendor/laravel13 update --no-interaction --no-plugins --no-scripts --prefer-dist
php "$(command -v composer)" --working-dir=vendor/laravel12 update --no-interaction --no-plugins --no-scripts --prefer-dist
docker compose -f tests/compatibility/compose.yaml build tests
```

For each major, start with a fresh **disposable test store** so the first created node has ID 0. The Compose project is `nxs-neoeloquent`; `down -v` applies only to that project. The fresh-store flag must not be used for a second run on an already-used store.

```sh
docker compose -f tests/compatibility/compose.yaml down -v
docker compose -f tests/compatibility/compose.yaml up -d --wait neo4j
NEOELOQUENT_EXPECT_FIRST_ID_ZERO=1 docker compose -f tests/compatibility/compose.yaml run --rm tests php vendor/laravel13/vendor/bin/phpunit -c tests/compatibility/phpunit.xml --bootstrap vendor/laravel13/vendor/autoload.php --do-not-cache-result
docker compose -f tests/compatibility/compose.yaml run --rm tests php vendor/laravel13/vendor/bin/phpunit --bootstrap vendor/laravel13/vendor/autoload.php --do-not-cache-result
```

Repeat those four commands with `laravel12` in place of `laravel13`. The second command invoking PHPUnit runs the broader suite; it intentionally does not set the fresh-ID flag. Alternatively, root Composer dependencies can be used with `php vendor/bin/phpunit`; the Compose default command runs the focused suite against root dependencies. The recorded Laravel 13 run used root dependencies, and Laravel 12 used the isolated manifest.

```sh
docker compose -f tests/compatibility/compose.yaml down -v
```

The graph tests only run when `NEOELOQUENT_DISPOSABLE_GRAPH=1`, set inside Compose. Do not set this flag against a persistent database: the legacy suite deletes all graph data during cleanup. Unit tests can run outside Compose with the focused configuration; graph tests then report skipped. The Compose network is internal, has no published ports and mounts only source, tests, vendor and Composer/PHPUnit metadata. PHP/Neo4j images are pinned by digest.

Dependency updates resolve the current allowed versions. The saved evidence records the exact versions and hashes used for this checkpoint; preserve the generated lock files locally for exact repeated installation. CI version coverage and a distributable fork reference are handled in V16-35.

## Reproduce the original defects

With root dependencies installed for Laravel 13, export the exact upstream source into a separate temporary directory. Mount that source over `/workspace/src` in the same disposable harness, leaving the new regression tests in place:

```sh
NEOELOQUENT_BASELINE=$(mktemp -d)
git archive ce76714db4e27614bc1a03322b942b8e342c8297 src | tar -x -C "$NEOELOQUENT_BASELINE"
docker compose -f tests/compatibility/compose.yaml run --rm -v "$NEOELOQUENT_BASELINE/src:/workspace/src:ro" tests php vendor/bin/phpunit --no-configuration --do-not-cache-result --bootstrap vendor/autoload.php --filter 'testGraphRelationReportsNoSqlPivotModel|testUnnamedMorphUsesStrHelperAndRelationName|testSimplePaginationHonoursExplicitAndResolvedPage' tests/compatibility
```

This diagnostic run is expected to fail on missing `getPivotClass()`, `snake_case()` and `getPaginator()`; old nullability deprecations are also visible. The normal focused configuration treats those deprecations as errors. Restore the ordinary Compose source mount by omitting the override for subsequent runs.

The separate modern-fork signature reproduction loads revision `1199106`'s Connection class against the Laravel 13 autoloader and exits 255 with an incompatible `select()` declaration. The new branch retains upstream 9.9.0's correct signature instead of copying that class wholesale.
