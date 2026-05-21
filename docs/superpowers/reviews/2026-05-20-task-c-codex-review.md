# Task C / Task 33 — Codex Self-Adversarial Review

**Reviewed commit:** `b713063d1` (`Phase 1.33.0: Verify fiscal full flow`)
**Reviewer:** Codex
**Date:** 2026-05-20
**Verdict:** APPROVE

## Scope Reviewed

- New full-flow feature test:
  - `apps/api/tests/Feature/Fiscal/Task33FiscalFullFlowVerificationTest.php`
- Roadmap status update:
  - `docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap-v2.md`

The test drives the required path: device-authored `SALE_RECEIPT` envelope → `/api/v1/pos/sync/fiscal-events` → server ingest/hash verification/strict parse → POS-core + Treasury projections → NF525 export from canonical payload → byte-equivalence checks across device canonical bytes, `fiscal_events.canonical_bytes`, and `pos_receipts.canonical_bytes`.

## Attack Vectors Checked

### Cross-Tenant FK Safety

PASS. The fixture creates one tenant/company/location/terminal/user graph and asserts the persisted `fiscal_events` row keeps the expected `tenant_id` and `company_id`. The projected line uses a non-UUID product snapshot (`task-33-canonical-snapshot`), so the POS-core product FK resolver cannot bind to any cross-tenant `products.id`. Payment method and Treasury repository fixtures are tenant/company scoped.

Residual note: this is not a new cross-tenant negative test; that matrix remains covered by the existing Task 21 R2 / Pass 2A.PHP.2 tests. Task 33 is intentionally the positive full-flow closure test.

### Fail-Loud vs Silent-Downgrade

PASS. The test asserts:

- endpoint result has `stored=true`;
- `sequence_conflict=false`;
- `exception_class=null`;
- `fiscal_events.integrity_status=verified`;
- `payload_parse_status=parsed`;
- both `pos_core_receipt` and `treasury_receipt_bridge` projection rows are `applied`;
- the Treasury `payments` row exists with `origin='pos'` and the same `fiscal_event_id`.

This attacks the prior silent-applied/zero-effect class by checking materialized effects, not only the projection row status.

### Dead-Path Rebuild / Legacy Chain Deletion

PASS. Task C does not reintroduce any legacy sync or chain code. The verification gate included the dead-path grep for:

`advanceHashChain|ReceiptSyncService|SyncReceiptsRequest|SyncReceiptPayload|SyncReceiptResult|computeV3FiscalHash|buildCanonicalPayload`

No hits in the scoped production/test/script paths.

### Discriminated-Union Test Matrix

PASS for Task 33 scope. This test exercises the positive `SALE_RECEIPT` canonical variant with canonical-only fields (`gtin`, `tax_category_code`, `foreign_currency_*`) carried into NF525. It does not expand the full malformed/conflict/refund/void matrix, which is already covered by Task 20, Task 21, Task 22, Task 30, and Pass 2A tests.

### Contract Drift: Code ↔ Docs

PASS. The roadmap update is narrow and factual: Phase 1 complete through Task 33, Phase 1.5 cleanup still pending before Phase 2. It does not claim Phase 1.5, Z-report rebuild, country-specific signature providers, or customer-facing Phase 2 work are complete.

### Skip Policy / Skip Citations

PASS. The new PHPUnit test contains no skips. No class-level skip was introduced.

### CLAUDE.md Rule 13 / Constructor Injection

PASS. No production code was changed. The test uses Laravel test-container access (`$this->app->make(...)`) in fixture setup/assertion code only; no `app()`, `App::make`, or `resolve()` production pattern was introduced.

### Byte-Equivalence / Chain Integrity

PASS. The test asserts:

- device-computed `current_hash === sha256(canonical_bytes)`;
- server ledger `canonical_bytes`, `previous_hash`, and `current_hash` match the device envelope;
- projected receipt `canonical_bytes`, `previous_hash`, `fiscal_hash`, and sequence mirror the verified event;
- NF525 DTO emits the same chain sequence/hash values and canonical-only fields from the fiscal event backed path.

## Verification Evidence

- `./vendor/bin/phpunit tests/Feature/Fiscal/Task33FiscalFullFlowVerificationTest.php` — PASS, 1 test / 41 assertions.
- `./vendor/bin/phpunit tests/Feature/POS/PosStabilizationTenantIsolationTest.php tests/Feature/Fiscal/ tests/Unit/Fiscal/` — PASS, 546 tests / 2005 assertions / 53 skipped.
- `pnpm typecheck` — PASS.
- `pnpm lint` — PASS with existing warnings only; 0 errors.
- `pnpm test` — PASS. POS and web Vitest suites completed.
- `./vendor/bin/phpstan analyse --level=8 --memory-limit=1G` — PASS.
- `./vendor/bin/pint --test` — PASS.
- `bash apps/pos/scripts/check-pass-2b-pending.sh` — PASS.
- `bash apps/api/scripts/check-saleReceipt-chokepoints.sh` — PASS, 6 manifest entries / 8 reconciled call sites.
- Dead-path grep — PASS, no matches.

## Findings

None.
