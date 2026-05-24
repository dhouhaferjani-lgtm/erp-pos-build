# Task 6 R3 Opus Adversarial Re-Review - Scoped Account Charge Replay Conflict

Commits reviewed:

- `cde93edcf Phase 3.6.1: Project account charge receipts`
- `d5cda7c93 Phase 3.6.2: Harden account charge projection replay`
- `d8d150957 Phase 3.6.3: Scope account charge replay conflict`

Prior reviews:

- `docs/superpowers/reviews/2026-05-21-task-6-opus-review.md`
- `docs/superpowers/reviews/2026-05-21-task-6-r3-codex-review.md`

## Verdict

APPROVE

## Findings

No blocking, request-changes, important, or minor findings.

## Prior Finding Closure

The prior REQUEST-CHANGES finding is closed. `AccountChargeReceiptProjection::apply()` no longer uses Laravel `insertOrIgnore()`. The insert path now delegates to `insertOnFiscalEventConflictDoNothing()` and emits:

```sql
INSERT INTO "pos_account_charge_receipts" (...) VALUES (...)
ON CONFLICT (fiscal_event_id) DO NOTHING
```

That is the required scoped replay conflict target. It does not use bare `ON CONFLICT DO NOTHING`, so a primary-key collision on `id`, or another future non-replay uniqueness violation, is not silently downgraded.

## Checked Attack Vectors

- Replay idempotency: PASS. The pre-existing same-`fiscal_event_id` guard remains, and the atomic insert conflict target at `apps/api/app/Modules/POS/Application/Projections/AccountChargeReceiptProjection.php:84`-`88` keeps concurrent duplicate replay idempotent.
- Non-replay primary-key collision: PASS. `test_account_charge_projection_fails_loud_on_non_replay_primary_key_conflict()` at `apps/api/tests/Feature/Fiscal/AccountChargeProjectionTest.php:79`-`99` forces a deterministic duplicate projection `id` across two different fiscal events and expects `QueryException`.
- Migration uniqueness surface: PASS. The table still has a primary key on `id` and a separate unique replay key on `fiscal_event_id` at `apps/api/database/migrations/2026_05_21_150000_create_pos_account_charge_receipts_table.php:14`-`17`.
- JSON payload snapshot: PASS. R3 did not alter the canonical reader path or payload serialization; `payload_snapshot` is still encoded from `payload->toArray()` with `JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES`.
- Timestamps and types: PASS. R3 still binds `now()` for `created_at`/`updated_at`, writes `amount_charged` from `totals.amount_charged_to_account`, and keeps the model casts/migration types unchanged.
- Portability: PASS for the repository-supported SQLite test path and PostgreSQL production path. The raw statement uses quoted identifiers and `ON CONFLICT (fiscal_event_id) DO NOTHING`, which both SQLite and PostgreSQL accept. This project is PostgreSQL-backed for production, and the pgsql CI gate includes `AccountChargeProjectionTest`.
- D16 boundary: PASS. The production projector imports only Fiscal/POS/shared DB dependencies and has no Treasury, Accounting, Document, Sales/B2B, Partner, Customer, or Contact module imports/calls.
- Constructor injection only: PASS. The production projector still receives `CanonicalPayloadReader` via constructor injection and contains no `app()`, `App::make()`, or `resolve()` calls.

## Verification

- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/AccountChargeProjectionTest.php tests/Feature/Fiscal/AccountChargeD16Test.php` - passed, 7 tests, 24 assertions.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpstan analyse --level=8 --debug app/Modules/POS/Application/Projections/AccountChargeReceiptProjection.php app/Modules/POS/Domain/AccountChargeReceipt.php tests/Feature/Fiscal/AccountChargeProjectionTest.php tests/Feature/Fiscal/AccountChargeD16Test.php --memory-limit=1G` - passed.
- `./vendor/bin/pint --test app/Modules/POS/Application/Projections/AccountChargeReceiptProjection.php app/Modules/POS/Domain/AccountChargeReceipt.php tests/Feature/Fiscal/AccountChargeProjectionTest.php tests/Feature/Fiscal/AccountChargeD16Test.php database/migrations/2026_05_21_150000_create_pos_account_charge_receipts_table.php` - passed.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_test DB_USERNAME=autoerp DB_PASSWORD=secret ./vendor/bin/phpunit tests/Feature/Fiscal/AccountChargeProjectionTest.php --filter 'idempotent|fails_loud'` - not runnable in this sandbox; PostgreSQL TCP connection to `127.0.0.1:5432` failed with `Operation not permitted` before migrations. The source review and SQLite run still verify the explicit conflict target locally, and `.github/workflows/ci.yml` runs `AccountChargeProjectionTest` under `DB_CONNECTION=pgsql`.
