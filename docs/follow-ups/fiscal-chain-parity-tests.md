# Follow-up: cross-language fiscal chain parity tests

**Status:** Recommended follow-up ticket. **Not** blocking the AutoSpecs cluster merge.
**Scope:** Testing + documentation only. No hash-algorithm changes.
**Estimated effort:** 1-2 focused hours.

Paste the prompt section below into a fresh Claude session when you're ready.

---

## Why this matters

The AutoERP codebase has **three independent fiscal hash chain artifacts** serving different legal/business purposes:

| Chain | Code | Hash input | Used by |
|-------|------|------------|---------|
| **B2 Documents chain** (backend) | `apps/api/app/Modules/Compliance/Services/FiscalHashService.php` | `receipt_number \| posted_at \| total \| currency` (4 parts) | Invoices, delivery notes, quotes, credit notes (web/B2B flow) |
| **Web POS + Desktop-synced Receipts chain** (backend) | `apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php` | `receipt_number \| posted_at (ISO 8601) \| total \| currency \| vat_hash \| payment_hash` (6 parts) | Online POS + receipts synced from Tauri desktop |
| **Desktop device chain** (frontend, Tauri) | `apps/pos/src/lib/fiscal/hashService.ts` | Same 6 parts as above | Offline receipts on the Tauri desktop app |

Chains #2 and #3 MUST produce byte-identical hashes for the same input — when a desktop receipt syncs to the backend, the backend must be able to verify its hash. Today, the code LOOKS identical but nothing enforces it. A future refactor on either side (date format change, separator change, trailing-zero handling, sort order) could silently diverge them. That's a fiscal-compliance landmine.

Chain #1 is deliberately different from #2/#3 (simpler B2 compliance requirements). **Do not unify them.**

## Current test state

- `HashChainReference::VECTORS` fixture (at `apps/api/tests/Fixtures/HashChainReference.php`) tests chain #1 against itself — self-consistent.
- `apps/api/tests/Unit/Compliance/FiscalHashServiceTest.php` + `HashChainReferenceTest.php` — good coverage for #1.
- **Gap**: no test asserts that #2 (PHP `ReceiptHashService`) and #3 (TypeScript `hashService.ts`) produce the same hash for the same input.

## The prompt (copy/paste into new session)

```
Follow-up ticket: cross-language fiscal chain parity tests

Goal: lock in the intentional algorithmic equivalence between the three
fiscal chain implementations so future refactors can't silently diverge.
Do NOT unify them — they serve different legal/business purposes. Just
prove they're consistent TODAY and keep them that way.

## Background (read before writing any code)

AutoERP has THREE independent fiscal hash chain artifacts:

1. B2 Documents chain (backend only)
   - File: apps/api/app/Modules/Compliance/Services/FiscalHashService.php
   - Input: `receipt_number | posted_at | total | currency` (4 parts)
   - Used by: Document module (invoices, delivery notes, quotes, credit notes)

2. Web POS + Desktop-synced Receipts chain (backend)
   - File: apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php
   - Input: `receipt_number | posted_at (ISO 8601) | total | currency | vat_hash | payment_hash`
   - Per-terminal chain, each terminal has its own genesis_seed
   - Used by: online POS + receipts synced from Tauri

3. Desktop device chain (frontend, Tauri)
   - File: apps/pos/src/lib/fiscal/hashService.ts
   - Input: identical to #2 — same 6-part format
   - Used by: offline receipts on the Tauri desktop app

Chains #2 and #3 MUST produce byte-identical hashes for the same input.
Chain #1 is deliberately different (simpler, B2 compliance-only).

Current state: both #2 and #3 exist; `HashChainReference::VECTORS` fixture
tests #1 vs itself (see apps/api/tests/Unit/Compliance/FiscalHashServiceTest.php).
There is NO test asserting #2 and #3 produce the same hash for the same input.
That is the gap.

## Deliverables

### 1. Shared canonical test vectors

Create: packages/shared/fiscal-reference/pos-receipt-vectors.json

Format:
{
  "$schema": "https://json-schema.org/draft-07/schema#",
  "description": "Canonical test vectors for the POS+Desktop fiscal hash chain. Changing any expected_hash must be deliberate and cross-language coordinated.",
  "vectors": [
    {
      "name": "single_receipt_no_previous",
      "input": {
        "previous_hash": "",
        "receipt_number": "RCT-2026-000001",
        "posted_at": "2026-04-21T14:30:00.000Z",
        "total": "15.500",
        "currency": "TND",
        "vat_breakdown": [{"rate": "19.00", "amount": "2.47"}],
        "payments": [{"method_code": "cash", "amount": "15.500"}]
      },
      "expected_hash": "<compute once by running either implementation, freeze here>"
    },
    { "name": "chained_receipt", ... },
    { "name": "multiple_vat_rates", ... },
    { "name": "multiple_payments", ... },
    { "name": "no_vat_no_payment_edge_case", ... },
    { "name": "genesis_with_seed", "genesis_seed": "abc123...", ... }
  ]
}

Include ~8-10 vectors covering:
- Genesis case (no previous_hash, with genesis_seed)
- Non-genesis case (chained)
- Multiple VAT rates (ensure sort order is deterministic across both sides)
- Multiple payment methods (same sort-order assertion)
- Zero-VAT (NO_VAT sentinel)
- Zero-payment (NO_PAYMENT sentinel)
- Precision edge cases (e.g. amount "0.001", amount "1000000.999")
- Currency other than TND (EUR, GBP)

### 2. Backend parity test

Create: apps/api/tests/Unit/POS/ReceiptHashServiceParityTest.php

- Loads packages/shared/fiscal-reference/pos-receipt-vectors.json
- For each vector, calls ReceiptHashService::calculateHash() (or the
  underlying serializer + FiscalHashService::calculateHash) with the
  vector's input
- Asserts the result === vector.expected_hash
- Failure message: "POS receipt hash diverged from shared vector {name}.
  This breaks the Desktop ↔ Backend parity contract. See
  docs/architecture/fiscal-chain-divergence.md before changing the algorithm."

### 3. Frontend parity test

Create: apps/pos/src/lib/fiscal/__tests__/hashParity.test.ts

- Loads the same JSON (via a small vitest fs-read helper)
- For each vector, calls computeFiscalHash() with the vector's input
- Asserts === vector.expected_hash
- Same failure-message discipline

### 4. CI gating

Add both tests to the default CI matrix — they're unit tests, no DB needed.
They should run on every PR. Add a brief note to .github/workflows/ci.yml
if helpful, but the default test targets should pick them up.

### 5. Architecture documentation

Create: docs/architecture/fiscal-chain-divergence.md

Contents:
- The three-chain setup (list the three services + their hash inputs +
  their legal purposes)
- Why #1 differs from #2/#3 (B2 e-invoicing vs NF525 POS cash register)
- Why #2 and #3 MUST match (sync integrity)
- Link to the shared vectors fixture
- Link to the two parity tests
- Policy: "Do NOT unify #1 with #2/#3. Do NOT diverge #2 from #3."
- Change-control: "To modify the POS hash algorithm, you must: (a) update
  both ReceiptHashService.php and hashService.ts in the same commit,
  (b) recompute and update every vector's expected_hash, (c) document
  why in the commit message + an ADR under docs/architecture/adrs/."

### 6. (OPTIONAL — separate ticket) Chain continuity test

Out of scope for this ticket. File it separately:

"Offline → online sync chain continuity integration test:
Build an integration test that (a) creates 3 receipts on the Tauri
desktop while 'offline' (using a SQLite-backed local chain),
(b) syncs them to the backend, (c) asserts the backend's received
copies have correct previous_hash pointers AND that
ReceiptHashService::verifyTerminalChain returns true for all three.
This is a RELIABILITY test for the sync flow, complementing the
algorithm-parity test above."

## Anti-scope

- Do NOT modify #1 (B2 Documents chain) — it intentionally differs.
- Do NOT modify either existing hashService implementation. Only ADD tests + docs + fixture.
- Do NOT update any hash algorithm. If either side fails against the
  vectors, that's a BUG in that side (regression), not a spec update.
- Do NOT attempt to "unify" #1 and #2 into one chain. The separation is
  deliberate and legally motivated.

## Estimated effort

1-2 focused hours for someone who reads the existing code first.
```
