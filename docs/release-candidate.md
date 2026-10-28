# NXS fork release candidate — V16-35

Candidate tag: **`9.9.1-RC1`**, based on upstream **9.9.0** (`ce76714db4e27614bc1a03322b942b8e342c8297`). This is a v16Studios fork version, not an upstream Ulobby release. The package name remains `ulobby/neoeloquent` and the namespace remains `Vinelab\NeoEloquent`. Resolve the full source commit with `git rev-parse '9.9.1-RC1^{commit}'`; the application handoff records it literally. Never move this tag after review; use a new candidate for changed runtime code.

The branch/tag are local until publication is authorized. V16-36 reviews the candidate before the application upgrade. This document does not authorize installing it in NXS or migrating production.

## Supported matrix

| Component | Candidate constraint | Recorded validation |
| --- | --- | --- |
| PHP | `~8.5.0` | 8.5.12 |
| Illuminate | `^12.70.2 || ^13.39.0` | 12.70.2 and 13.39.0 |
| Laudis | `^3.7.0` | 3.7.0 |
| Neo4j | 5.26 Community deployment target | 5.26.32, pinned image digest |
| PHPUnit / Composer | Test tooling | 9.6.38 / 2.10.2 |

This deliberately narrows upstream's PHP 8.2+/older Illuminate/Laudis claims to the NXS candidate's verified baseline. Higher versions allowed by the ranges still require dependency-update validation; only the checked-in locks reproduce this result. Earlier PHP minors are not certified by this fork release. The Composer platform check and the real test runtime both use PHP 8.5; no ignored platform requirements are used.

Exact test manifests and complete dependency locks are checked in at `tests/compatibility/locks/laravel{12,13}/`. They are templates restored to `vendor/laravel{12,13}/`, where their relative autoload paths resolve to the checkout. Do not run Composer directly in the snapshot directory. The root library lock is not distributed as a consumer dependency lock: Composer resolves dependency requirements in the consuming application.

## Repeatable verification

```sh
PHP_BIN=php85 bash tests/compatibility/verify-release.sh 13
PHP_BIN=php85 bash tests/compatibility/verify-release.sh 12
```

Use `PHP_BIN=php` on a PHP 8.5 CI runner. Docker/Compose and Composer are required. Run serially: the script recreates only the `nxs-neoeloquent` disposable project for each major, installs the checked-in lock, starts pinned PHP/Neo4j images, runs the focused and full suites, saves logs/JUnit under `logs/release-laravel{12,13}/`, then removes the containers/data even on failure. The graph has no published ports and only synthetic records. The Compose test-only 1s lock timeout is required for deterministic transaction contention. Never repoint these tests at persistent data.

The focused suite runs first on the fresh store and asserts both node and relationship ID 0. It covers provider-independent configuration/database selection and URL precedence, parameter maps/lists/null/false/zero/Unicode, createWith, attach/update/detach, label-array semantics, the nullable BelongsToMany pivot boundary, pagination/soft deletes and all transaction regressions. On each Illuminate version: **38 tests / 210 assertions, all pass**. Each full run: **312 tests / 1,265 assertions**, zero failures/errors/deprecations, **10 skipped and 12 incomplete**. Exclusions are recorded in full output; they are not passing coverage.

`.github/workflows/tests.yml` invokes this same script for both majors using pinned action revisions and uploads artifacts. The local runs are the documented CI equivalent; a hosted Actions run is not claimed before publication. The test-only dependency locks are intentionally updated only with reviewed version changes and another matrix run.

## Independent Composer consumer

Composer repository configuration belongs in the **root application**. Put the fork before other repositories, retain canonical priority and restrict it to this package. After publication, the intended application requirement is:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/v16Studios/NeoEloquent",
      "canonical": true,
      "only": ["ulobby/neoeloquent"]
    }
  ],
  "require": {"ulobby/neoeloquent": "9.9.1-RC1"}
}
```

Retain unrelated application repositories/requirements. The explicit RC requirement permits this candidate without globally lowering stability. A canonical fork prevents fallback to another source if the candidate is absent. Commit the resulting application lock and verify its package `source.reference` equals the reviewed full commit, as well as the intended source URL. Deploy with `composer install` from that lock, never a floating `dev-master` or an unreviewed `composer update`. Tags are useful version names; the verified commit and committed lock establish the immutable input.

The local consumer uses a **VCS repository**, not a symlink/path package, and installs the tagged source into its own vendor tree alongside the actual Laravel 13.39.0 framework:

```sh
php85 tests/compatibility/prepare-consumer.php "$PWD" 9.9.1-RC1
php85 "$(command -v composer)" --working-dir=vendor/release-consumer update --no-interaction --no-plugins --no-scripts --prefer-dist
NEOELOQUENT_CANDIDATE=$(git rev-parse '9.9.1-RC1^{commit}')
docker compose -f tests/compatibility/compose.yaml up -d --wait neo4j
docker compose -f tests/compatibility/compose.yaml run --rm tests php tests/compatibility/check-consumer.php "$NEOELOQUENT_CANDIDATE"
docker compose -f tests/compatibility/compose.yaml down -v
```

Preparation refuses to overwrite an existing consumer lock. Reinstall it with `composer install`; preserve it before intentionally testing a new candidate. `check-consumer.php` verifies the installed source path/reference, boots the provider in Laravel, resolves the named connection and checks model/edge commit, rollback/original errors and raw parameter shapes. Local-source resolution does not prove remote publication or access. After approved publication, repeat with the GitHub URL and preserve the resulting deployment-ready lock; do not hand-edit the local consumer lock's repository URL.

## Application boundaries

- Label arrays mean **all labels (AND)**, not user-or-company union queries. A fixture matching `App\Models\Entity`'s shape verifies this behavior and preserves IDs. The application integration must review its intended union behavior; no label semantics were changed here.
- `BelongsToMany::getPivotClass()` returns null for graph edges. Full Nova adapters and mutation flows remain application work.
- The fork already overrides `saveOrFail()` to call `save()` directly; it participates in an outer transaction without nesting, as verified here. The earlier generic warning about that method was too broad. Methods such as inherited `createOrFirst()` can still request nested transactions and need integration review.
- [Transaction restrictions](transactions.md) still apply: no savepoints/nesting, no callback replay after commit failure, reload objects between retries, bounded transactional reads, and explicit application financial locking/idempotency and MySQL coordination.
- Unsupported polymorphic reads and other legacy exclusions remain in [the compatibility audit](compatibility-upgrade.md). This candidate does not certify the NXS application as a whole.

## Retiring the fork

Compare a future immutable upstream release against `docs/compatibility-upgrade.md`, `CHANGELOG-NXS.md` and the transaction/release regressions. Run the same locked acceptance matrix against upstream, plus the application/Nova/financial contracts. Only when all required deltas are upstream or no longer needed: remove this package's canonical fork repository from the root application, change its exact requirement to the approved upstream release, regenerate/review its lock and verify source URL/reference and staged application behavior. Preserve the old lock/artifacts for rollback. Do not remove the fork solely because an upstream manifest declares Laravel 13 support.

Composer references: [repository priority](https://getcomposer.org/doc/articles/repository-priorities.md), [VCS/root repositories](https://getcomposer.org/doc/05-repositories.md), [versions and requirements](https://getcomposer.org/doc/04-schema.md).
