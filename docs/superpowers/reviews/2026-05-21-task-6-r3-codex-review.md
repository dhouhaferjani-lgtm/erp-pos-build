# Task 6 R3 Codex Self-Adversarial Review — Scoped Account Charge Replay Conflict

Commits reviewed:

- `cde93edcf Phase 3.6.1: Project account charge receipts`
- `d5cda7c93 Phase 3.6.2: Harden account charge projection replay`
- `d8d150957 Phase 3.6.3: Scope account charge replay conflict`

Prior reviews:

- `docs/superpowers/reviews/2026-05-21-task-6-codex-review.md`
- `docs/superpowers/reviews/2026-05-21-task-6-r2-codex-review.md`
- `docs/superpowers/reviews/2026-05-21-task-6-opus-review.md`

## Verdict

APPROVE

## Opus Finding Closure

REQUEST-CHANGES-1 is closed. The projector no longer uses Laravel's unscoped `insertOrIgnore()`. It now emits a targeted raw statement:

```sql
INSERT INTO "pos_account_charge_receipts" (...) VALUES (...)
ON CONFLICT (fiscal_event_id) DO NOTHING
```

That keeps true replay idempotent while allowing non-replay uniqueness failures, including `id` primary-key collisions, to raise. The new regression `test_account_charge_projection_fails_loud_on_non_replay_primary_key_conflict()` forces a deterministic projection-id collision across two different fiscal events and expects `QueryException`.

## Checked Attack Vectors

- **R2/R3 fix-in-fix risk:** PASS. R3 changes only the insert primitive and adds focused coverage for the exact Opus finding.
- **Replay idempotency:** PASS. Duplicate `fiscal_event_id` remains a no-op through the scoped conflict target; existing sequential idempotency test remains green.
- **Non-replay conflicts fail loud:** PASS. Deterministic duplicate primary key with different `fiscal_event_id` now throws.
- **PostgreSQL conflict-target semantics:** PASS. The emitted statement targets `(fiscal_event_id)`, matching the unique column in the migration and avoiding bare `ON CONFLICT DO NOTHING`.
- **SQLite local compatibility:** PASS. The same `(fiscal_event_id)` conflict-target syntax is accepted by the local test suite.
- **D16 bounded-module guard:** PASS. No new module imports beyond `Ramsey\Uuid\Uuid` in tests; production projector remains POS/Fiscal/Shared/DB only.
- **Constructor injection only:** PASS. No production `app()`, `App::make()`, or `resolve()`.
- **Cross-tenant FK safety:** PASS. R3 does not alter source of tenant/company/fiscal-event values.
- **Fail-loud vs silent downgrade:** PASS. R3 makes non-replay uniqueness loud; typed canonical reader exceptions still precede insertion.
- **Contract drift:** PASS. R3 does not alter projected columns or payload snapshot semantics.

## Verification

Focused verification after R3:

- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/AccountChargeProjectionTest.php tests/Feature/Fiscal/AccountChargeD16Test.php` — passed, 7 tests, 24 assertions.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpstan analyse --level=8 app/Modules/POS/Application/Projections/AccountChargeReceiptProjection.php app/Modules/POS/Domain/AccountChargeReceipt.php tests/Feature/Fiscal/AccountChargeProjectionTest.php tests/Feature/Fiscal/AccountChargeD16Test.php --memory-limit=1G` — passed.
- `./vendor/bin/pint --test app/Modules/POS/Application/Projections/AccountChargeReceiptProjection.php app/Modules/POS/Domain/AccountChargeReceipt.php tests/Feature/Fiscal/AccountChargeProjectionTest.php tests/Feature/Fiscal/AccountChargeD16Test.php` — passed.

Full verification after R3:

- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/` — passed, 1181 tests, 4025 assertions, 107 skipped, 2 incomplete, 16 deprecations.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpstan analyse --level=8 --memory-limit=1G` — passed.
- `./vendor/bin/pint --test app/Modules/POS app/Modules/Fiscal tests/Feature/POS tests/Feature/Fiscal tests/Unit/Fiscal database/migrations/2026_05_21_150000_create_pos_account_charge_receipts_table.php` — passed.
- `pnpm test` from `apps/pos` — passed, 168 files, 1500 tests.
- `pnpm typecheck && pnpm lint` from `apps/pos` — passed with the existing 41 warnings.
- `bash apps/api/scripts/check-saleReceipt-chokepoints.sh && bash apps/pos/scripts/check-pass-2b-pending.sh` — passed.
