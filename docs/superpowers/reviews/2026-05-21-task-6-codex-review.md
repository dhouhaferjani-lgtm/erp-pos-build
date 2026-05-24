# Task 6 Codex Self-Adversarial Review — POS-Core Account Charge Receipt Projection

Commits reviewed:

- `cde93edcf Phase 3.6.1: Project account charge receipts`

Scope:

- Task 6 from `docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md`
- `AccountChargeReceiptProjection`
- `pos_account_charge_receipts` migration/model
- POS provider registration
- `AccountChargeProjectionTest`
- `AccountChargeD16Test`
- CI PG merge-gate filter

## Verdict

REQUEST-CHANGES

## Findings

### BLOCKER-1 — Idempotency is not atomic under concurrent projection replay

`AccountChargeReceiptProjection::apply()` checks for an existing row by `fiscal_event_id`, then enters a transaction and checks again before `AccountChargeReceipt::query()->create()`. That covers sequential replay, and the test verifies sequential idempotency. It does not close the race where two jobs pass both probes before either commits; the loser can still hit the unique `fiscal_event_id` constraint.

This repeats the Task 21 standing pattern: projection idempotency needs a DB primitive, not just an application-level pre-check. Because Task 6 adds a jsonb projection table and a unique replay key, the projector should use an atomic insert primitive (`insertOrIgnore` / `ON CONFLICT DO NOTHING`) and treat zero rows inserted as an idempotent no-op.

Required fix:

- Replace the double-probe + create path with a single atomic insert.
- Preserve canonical payload snapshot from the typed reader.
- Keep D16 clean: no Treasury/Accounting/Document/B2B/Customer live imports.
- Re-run targeted Task 6 tests and the full gate.

## Checked Attack Vectors

- D16 bounded-module guard: PASS for imports and container helpers.
- Provider tag: PASS; projector is tagged as `FiscalEventProjector`.
- CI PG filter: PASS; `AccountChargeProjectionTest` is included in the PG merge-gate filter with rationale.
- Cross-tenant FK safety: PASS for emitted values; row uses the source `FiscalEvent` tenant/company and has FK coverage.
- Fail-loud behavior: PASS for wrong event type/null payload via `CanonicalPayloadReader::forAccountCharge()`.
- Contract drift: PASS for amount source (`totals.amount_charged_to_account`) and payload snapshot.
- Skip policy: PASS; no class-level skips or new skipped tests.

## Verification Already Run

- RED before implementation: Task 6 targeted tests failed because projection/model/tag/file were absent.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/AccountChargeProjectionTest.php tests/Feature/Fiscal/AccountChargeD16Test.php` — passed, 6 tests, 23 assertions.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpstan analyse --level=8 --memory-limit=1G` — passed.
- `./vendor/bin/pint --test ...` — passed after import ordering fix.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/` — passed, 1180 tests, 4024 assertions, 107 skipped, 2 incomplete, 16 deprecations.
- `pnpm test` from `apps/pos` — passed, 168 files, 1500 tests.
- `pnpm typecheck && pnpm lint` from `apps/pos` — passed with the existing 41 warnings.
- `bash apps/api/scripts/check-saleReceipt-chokepoints.sh && bash apps/pos/scripts/check-pass-2b-pending.sh` — passed.
