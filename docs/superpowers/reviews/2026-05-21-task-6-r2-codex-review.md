# Task 6 R2 Codex Self-Adversarial Review — Atomic Account Charge Projection Replay

Commits reviewed:

- `cde93edcf Phase 3.6.1: Project account charge receipts`
- `d5cda7c93 Phase 3.6.2: Harden account charge projection replay`

Prior self-review:

- `docs/superpowers/reviews/2026-05-21-task-6-codex-review.md`

## Verdict

APPROVE

## R1 Blocker Closure

BLOCKER-1 is closed. `AccountChargeReceiptProjection::apply()` now keeps the fast-path replay probe, but the actual write is a single `DB::table('pos_account_charge_receipts')->insertOrIgnore(...)` keyed by the table's unique `fiscal_event_id`. A concurrent replay that loses the race now becomes an idempotent no-op instead of surfacing a unique-key exception.

The R2 fix did not introduce a silent downgrade for non-replay failures on PostgreSQL: `ON CONFLICT DO NOTHING` handles unique/exclusion conflicts, while FK, JSON, and type failures remain hard database errors. The payload snapshot is explicitly JSON-encoded from `AccountChargePayload::toArray()` and still hydrates through the model's `payload_snapshot` array cast in the projection test.

## Checked Attack Vectors

- **D16 bounded-module guard:** PASS. The projector imports Fiscal reader/model/enum, POS projection model, Shared Fiscal projector contract, DB, and `Str`; no Treasury, Accounting, Document, Sales, Partner, Customer, Contact, or B2B imports.
- **Constructor injection only:** PASS. Projector uses constructor-injected `CanonicalPayloadReader`; no `app()`, `App::make()`, or `resolve()` in production code.
- **Atomic idempotency:** PASS. `insertOrIgnore` moves replay safety to the DB primitive behind `fiscal_event_id` uniqueness.
- **Cross-tenant FK safety:** PASS. The projection row writes `tenant_id` and `company_id` from the authoritative `FiscalEvent`; `customer_id` is a sealed payload identifier, intentionally not a live FK. Tenant/company/fiscal event FKs are present.
- **Fail-loud vs silent downgrade:** PASS. Wrong event type/null payload/structural payload drift still throw through `CanonicalPayloadReader::forAccountCharge()` before insert. PostgreSQL FK/json/type failures still throw.
- **Contract drift:** PASS. Projection amount comes from `totals.amount_charged_to_account`, matching Task 6's `amount_charged` column and the account-charge printable contract.
- **CI PG gate:** PASS. `.github/workflows/ci.yml` includes `AccountChargeProjectionTest` in the PG merge-gate filter with rationale for jsonb/FK/idempotency coverage.
- **Skip policy:** PASS. No class-level skips; no new skipped tests.
- **R2-introduces-defects pattern:** PASS. R2 changed only the write primitive and timestamp/JSON serialization; targeted and full gates passed after the change.

## Verification

RED observed before implementation:

- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/AccountChargeProjectionTest.php tests/Feature/Fiscal/AccountChargeD16Test.php` failed because the projection class/file/provider tag did not exist.

Focused verification after R2:

- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/AccountChargeProjectionTest.php tests/Feature/Fiscal/AccountChargeD16Test.php` — passed, 6 tests, 23 assertions.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpstan analyse --level=8 app/Modules/POS/Application/Projections/AccountChargeReceiptProjection.php app/Modules/POS/Domain/AccountChargeReceipt.php tests/Feature/Fiscal/AccountChargeProjectionTest.php tests/Feature/Fiscal/AccountChargeD16Test.php --memory-limit=1G` — passed.
- `./vendor/bin/pint --test app/Modules/POS/Application/Projections/AccountChargeReceiptProjection.php app/Modules/POS/Domain/AccountChargeReceipt.php tests/Feature/Fiscal/AccountChargeProjectionTest.php tests/Feature/Fiscal/AccountChargeD16Test.php` — passed.

Full verification after R2:

- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/` — passed, 1180 tests, 4024 assertions, 107 skipped, 2 incomplete, 16 deprecations.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpstan analyse --level=8 --memory-limit=1G` — passed.
- `./vendor/bin/pint --test app/Modules/POS app/Modules/Fiscal tests/Feature/POS tests/Feature/Fiscal tests/Unit/Fiscal database/migrations/2026_05_21_150000_create_pos_account_charge_receipts_table.php` — passed.
- `pnpm test` from `apps/pos` — passed, 168 files, 1500 tests.
- `pnpm typecheck && pnpm lint` from `apps/pos` — passed with the existing 41 warnings.
- `bash apps/api/scripts/check-saleReceipt-chokepoints.sh && bash apps/pos/scripts/check-pass-2b-pending.sh` — passed.
