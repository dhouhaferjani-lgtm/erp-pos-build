# Task C / Task 33 — Opus-Equivalent Adversarial Review

**Reviewed commit:** `b713063d1` (`Phase 1.33.0: Verify fiscal full flow`)  
**Reviewer:** Codex, second-pass adversarial review  
**Date:** 2026-05-21  
**Verdict:** APPROVE-WITH-MINOR-EDITS

## Scope Reviewed

- `apps/api/tests/Feature/Fiscal/Task33FiscalFullFlowVerificationTest.php`
- `docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap-v2.md`
- `docs/superpowers/reviews/2026-05-20-task-c-codex-review.md`

## Findings

No BLOCKER or REQUEST-CHANGES findings.

### Minor: test wording overstates actual TS-device coverage

**Reference:** `apps/api/tests/Feature/Fiscal/Task33FiscalFullFlowVerificationTest.php:35-43`, `:208-262`, `docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap-v2.md:30`

The test correctly exercises the real HTTP endpoint, server ingest/parse/hash verification, projection jobs, and NF525 export path. It does not execute the Tauri/TypeScript `FiscalEventEngine` or `receiptService.ts`; it builds a Tauri-style sealed envelope in PHP via the test helper. That is acceptable for an API-side Phase 1 closure test because Pass 2B already covers the device authoring path, but the comments and roadmap sentence should ideally say "Tauri-style device-shaped sealed envelope" rather than implying this single PHPUnit test executes the TS device engine.

This is not a blocking defect because the test's asserted contract is still valuable and real for the server path: the server receives a sealed envelope through `/api/v1/pos/sync/fiscal-events`, verifies the exact bytes, persists them, projects business effects, and exports canonical-only NF525 fields.

### Minor: fixture can more closely mirror real POS envelopes

**Reference:** `apps/api/tests/Feature/Fiscal/Task33FiscalFullFlowVerificationTest.php:67`, `:229-230`

The fixture uses a zero hash seed and leaves `source_event_class` / `source_event_id` null. Current server ingest accepts this and the test remains valid for the server's generic fiscal-event contract. For closer POS fidelity, consider using a non-zero 64-lowercase-hex seed and setting `source_event_class='offline_receipts'` plus `source_event_id=$eventId` or a receipt UUID, matching the Pass 2B device path.

This is minor because the endpoint permits nullable source pointers by design and the test's main purpose is full server-path verification rather than source-event idempotency coverage.

## Axes Checked

### Endpoint and Projection Path

PASS. The test posts to `/api/v1/pos/sync/fiscal-events` (`Task33FiscalFullFlowVerificationTest.php:125-127`) and does not directly call `OutboxIngestor` or projectors. With `QUEUE_CONNECTION=sync` in PHPUnit, the `DB::afterCommit()` projection dispatch runs synchronously. The test then asserts both projection rows are `applied` and checks materialized POS, Treasury, and payment rows (`:156-180`), which prevents the prior "applied but no effects" class.

### Canonical Byte Equivalence

PASS. The test pins:

- device-shaped canonical bytes and current hash (`:122-124`);
- server `fiscal_events.canonical_bytes`, `previous_hash`, and `current_hash` (`:135-146`);
- projected `pos_receipts.canonical_bytes` and mirror chain fields (`:148-154`);
- NF525 chain fields and canonical-only payload fields (`:188-201`).

### Chain Integrity

PASS for a one-event chain. The test asserts sequence `1`, previous hash equals the genesis seed, current hash equals `sha256(canonical_bytes)`, and server integrity status is `verified`. It does not run the CLI chain verifier; existing chain-verifier coverage remains the broader chain-integrity matrix.

### Cross-Tenant and FK Safety

PASS. The fixture is tenant/company consistent, and the test asserts the persisted fiscal event tenant/company. Payment method and repository fixtures are scoped to the same tenant/company. The product id is a snapshot-style non-UUID, so no cross-tenant product FK is accidentally resolved.

### Fail-Loud / Silent Downgrade

PASS. The response must be stored with no sequence conflict or exception. The event must parse as `parsed`, both projection rows must be `applied`, and POS/Treasury rows must exist. This is effect-oriented, not just status-oriented.

### Legacy Path / Rule 13

PASS. The commit does not add production code and does not reintroduce legacy receipt sync, v3 hash helpers, or direct legacy chain writers. Test-only `$this->app->make(...)` usage is not a production CLAUDE.md rule 13 violation.

### Roadmap Status

PASS with the minor wording caveat above. The update claims Phase 1 is complete through Task 33 and keeps Phase 1.5 cleanup pending before Phase 2, which is accurate and does not overclaim country-specific signatures, Z-report rebuild, or customer-facing work.

### Skips

PASS. No new skips were added.

## Residual Risk

The test closes the server-side full-flow path, not an end-to-end cross-runtime POS/API test. A future regression in TS device canonical encoding would need to be caught by the existing Pass 2B POS tests and drift gates, not this PHPUnit closure test.
