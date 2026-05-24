# Task 6 Opus Second-Pass Adversarial Review — POS-Core Account Charge Receipt Projection

Commits reviewed:

- `cde93edcf Phase 3.6.1: Project account charge receipts`
- `d5cda7c93 Phase 3.6.2: Harden account charge projection replay`

Scope:

- Task 6 from `docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md`
- Spec `docs/superpowers/specs/2026-05-21-pos-charge-to-account-phase3-spec-v1.md`
- `apps/api/app/Modules/POS/Application/Projections/AccountChargeReceiptProjection.php`
- `apps/api/app/Modules/POS/Domain/AccountChargeReceipt.php`
- `apps/api/database/migrations/2026_05_21_150000_create_pos_account_charge_receipts_table.php`
- `apps/api/app/Modules/POS/Providers/POSServiceProvider.php`
- `apps/api/tests/Feature/Fiscal/AccountChargeProjectionTest.php`
- `apps/api/tests/Feature/Fiscal/AccountChargeD16Test.php`
- `.github/workflows/ci.yml`
- Relevant canonical ACCOUNT_CHARGE reader/DTOs

## Verdict

REQUEST-CHANGES

## Findings

### REQUEST-CHANGES-1 — R2 still silently downgrades non-replay unique conflicts on PostgreSQL

`AccountChargeReceiptProjection::apply()` uses `DB::table(...)->insertOrIgnore(...)` for replay idempotency at `apps/api/app/Modules/POS/Application/Projections/AccountChargeReceiptProjection.php:59`. On PostgreSQL, Laravel compiles that call to bare `ON CONFLICT DO NOTHING`, not `ON CONFLICT (fiscal_event_id) DO NOTHING`. This matters because the table has at least two uniqueness surfaces: the primary key `id` at `apps/api/database/migrations/2026_05_21_150000_create_pos_account_charge_receipts_table.php:14` and the intended replay key `fiscal_event_id` at `apps/api/database/migrations/2026_05_21_150000_create_pos_account_charge_receipts_table.php:17`.

The R2 fix closes the common concurrent replay race on `fiscal_event_id`, but it also ignores any other unique/exclusion conflict PostgreSQL sees. If the generated projection `id` collides, or if this table later adds another uniqueness invariant such as a scoped `account_charge_uuid` key, the insert affects zero rows and `apply()` returns as if the receipt already existed. That violates the Task 6 fail-loud axis for non-replay projection failures.

Required fix: target the replay conflict explicitly. Use a PostgreSQL statement or query helper that emits `ON CONFLICT (fiscal_event_id) DO NOTHING`, so conflicts on `id` or any future non-replay unique constraint still raise. Add a PG-focused regression that proves an `id` conflict with a different `fiscal_event_id` fails loud while a duplicate `fiscal_event_id` remains idempotent.

## Checked Attack Vectors

- POS-core projection contract: PASS. `name()`, `handlesEventType()`, `requiresModule()`, and `priority()` match Task 6.
- Canonical payload source: PASS. The projector reads through `CanonicalPayloadReader::forAccountCharge($event)` and stores the typed payload snapshot.
- D16 bounded-module seam: PASS for the projector source. No Treasury, Accounting, Document, Sales/B2B, Partner, Customer, or Contact imports/calls; constructor injection is used.
- Cross-tenant FK safety: PASS for the implementation path. `tenant_id`, `company_id`, and `fiscal_event_id` are copied from the authoritative `FiscalEvent`; `customer_id` is sealed-payload data and is correctly not a live FK.
- Migration shape: PASS. The table has `jsonb`, unique `fiscal_event_id`, tenant/company/fiscal-event FKs, customer/account-charge indexes, timestamps, and `decimal(15, 4)` for `amount_charged`.
- Contract drift: PASS. `amount_charged` comes from `totals.amount_charged_to_account`; `account_charge_uuid`, customer snapshot fields, currency, and `payload_snapshot` come from the ACCOUNT_CHARGE canonical view/payload.
- CI PG gate: PASS. `.github/workflows/ci.yml` includes `AccountChargeProjectionTest` in the PG merge-gate filter with a jsonb/FK/replay rationale.
- Test coverage caveat: the Task 6 tests cover projection, sequential idempotency, provider tagging, no Treasury payment effects, and D16 source guard. They do not directly test concurrent replay, PostgreSQL conflict-target behavior, or projection-level wrong-type/null/malformed ACCOUNT_CHARGE failure; the implementation relies on `CanonicalPayloadReader` and upstream parser coverage for those fail-loud paths.

## Verification

- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/AccountChargeProjectionTest.php tests/Feature/Fiscal/AccountChargeD16Test.php` — passed, 6 tests, 23 assertions.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpstan analyse --level=8 --debug app/Modules/POS/Application/Projections/AccountChargeReceiptProjection.php app/Modules/POS/Domain/AccountChargeReceipt.php tests/Feature/Fiscal/AccountChargeProjectionTest.php tests/Feature/Fiscal/AccountChargeD16Test.php --memory-limit=1G` — passed. The normal PHPStan parallel mode could not bind `tcp://127.0.0.1:0` in this sandbox, so `--debug` was used for serial analysis.
- `./vendor/bin/pint --test app/Modules/POS/Application/Projections/AccountChargeReceiptProjection.php app/Modules/POS/Domain/AccountChargeReceipt.php tests/Feature/Fiscal/AccountChargeProjectionTest.php tests/Feature/Fiscal/AccountChargeD16Test.php` — passed.
- Source guard: `rg` found no forbidden module imports or container helper calls in `AccountChargeReceiptProjection.php`.
