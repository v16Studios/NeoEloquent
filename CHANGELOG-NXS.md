# NXS fork changes

## 9.9.1-RC1 — local review candidate

Based on upstream Ulobby 9.9.0 (`ce76714`). Package identity remains `ulobby/neoeloquent`.

- Retain required legacy-fork behavior while preserving upstream Laravel 13 signatures, relation escaping and soft-delete fixes; repair pagination, relation metadata, removed helpers, nullability and dynamic-property warnings (V16-33).
- Implement real transactions across raw queries, models and cached node/edge helpers, with original-error preservation, bounded pre-commit retries and failure recovery. Reject nesting/savepoints and ambiguous-commit replay. Repair node helper updates/empty creation and zero/false relationship values (V16-34).
- Preserve named parameter maps, lists, empty lists and raw `$id`; retain the legacy list-of-maps binding format and `idn` alias without overwriting an explicitly supplied alias (V16-35).
- Add real relationship ID 0, configuration/database, parameter-shape, createWith, label-array and installed-consumer acceptance checks. Record the nullable Nova pivot and existing `saveOrFail()` behavior.
- Narrow support to PHP 8.5, Illuminate 12.70.2+/13.39.0+ and Laudis 3.7+; pin complete test dependency locks and the PHP/Neo4j images. CI runs the reproducible local verification script.

See [release-candidate.md](docs/release-candidate.md) for the supported matrix, immutable installation procedure, exact evidence, exclusions and upstream-retirement conditions. This candidate requires the V16-36 human checkpoint before application integration.
