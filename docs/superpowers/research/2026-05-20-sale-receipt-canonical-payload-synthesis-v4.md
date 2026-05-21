# SALE_RECEIPT Canonical Payload — Synthesis v4

**Date:** 2026-05-20
**Author:** Controller
**Status:** DRAFT — pending Codex round-4 → owner final sign-off → plan amendment.
**Supersedes:** v1 (Codex BLOCK), v2 (Codex REQUEST-CHANGES), v3 (Codex REQUEST-CHANGES). All prior versions at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis{,-v2,-v3}.md`.

---

## 0. Diff from v3 — Codex round-3 closures

This v4 carries v3 unchanged EXCEPT for these targeted closures of Codex round-3 P1+P2 findings:

| Finding | Closure (v4 section) |
|---|---|
| N-11 — Pass 2A old-shape test contradicts grep gate | §8.A grep-gate scope narrowed to `apps/api/tests/Feature/Fiscal/*` + `apps/pos/src/lib/fiscal/__tests__/*`. Device `receiptService.test.ts` explicitly EXCLUDED from Pass 2A migration; rewritten in Pass 2B atomically with the receiptService refactor. The "temporary integration test pinning old shape" idea from v3 §8 is RETRACTED — no such test exists in either pass. |
| N-14 — TN regex placeholder has no enforced stop | §7 + §17 changes: drop the strict per-country `seller.tax_number` regex from Pass 2A entirely. Pass 2A validator enforces only a universal pattern (`^[A-Za-z0-9 \-/.]{4,40}$`, non-empty, no control characters). Strict per-country regex hardening DEFERRED to a new task (post-Pass-2; before Tunisia or France launch). No Pass 2A merge gate depends on accountant attestation. |
| N-16 — VAT partition rule not implementable enough | §6 + §11 Amended A4: concrete algorithm specified using `bcadd` + `bccomp` at `currency_scale`; gross derived as `bcadd(net, vat)`; explicit invoice-discount handling (lines + vat_breakdown reflect POST-discount values; `transaction_discount_amount` is a separate informational field); negative-test list locked. |
| N-12 — Nf525DataProvider refactor scope under-itemized | §8.B Pass 2A sub-inventory: `mapSaleReceipt`, `mapVoidedReceipt`, `mapReturnReceipt`, `mapLine`, `mapPayment`, `mapVatDetail` refactored to read from `CanonicalPayloadReader` for fiscal-event-backed receipts (`fiscal_event_id IS NOT NULL`); legacy projection-fallback path preserved only for `fiscal_event_id IS NULL` (dead-code after Pass 2B but kept until Phase-2 cleanup per D5). Test coverage: canonical-only gtin / tax_category_code / foreign_currency / original_receipt_reference. |
| N-15 — Concurrent-receipt mutex primitive unspecified | §11 Amended A5: in-process per-`tenant_id:terminal_id` Promise queue in `paymentStore`; try/finally release after SQLite tx commits OR rollback; linear backoff (50ms, 100ms, 200ms) between retries; 3 retries → `FiscalChainContentionError`. Tests: lock release on success, validation-failure-before-tx, rollback path, retry exhaustion. |

Path corrections in N-7 (v3 file inventory had two wrong paths):
- `FiscalPayloadConstraintValidatorTest.php` — file does NOT exist in current tree; CREATE as part of Pass 2A.
- `StrictCanonicalParserTest.php` — exists at `apps/api/tests/Unit/Fiscal/StrictCanonicalParserTest.php` (NOT `Feature/Fiscal`).

Everything else from v3 (Candidate C-v3 27-key shape, D1-D9 decisions, §4 ZATCA XPath mapping, §5 D16 buyer invariants, §10 fixture matrix, §12 cross-language drift framing, §13 parse-failure transition plan, §14 adapter scoping) carries unchanged.

---

## 1. Owner decisions landing in v4 (D1–D9 from v3, unchanged)

D1. 10-field PHP shape is incomplete. Adopt expanded canonical.
D2. No dual chain — legacy v3 code DELETED in Pass 2.
D3. Specs for all 4 regimes; impl NF525 + Tunisia immediately; ZATCA + DE + IT DEFERRED.
D4. v1 rewrite in place — no event_version bump.
D5. Server-side mirror columns stay through Pass 2; Phase-2 audit + drop task added.
D6. DROP feature flag. Pass 2A + 2B = two clean commits on dev branch.
D7. B2C Simplified only via Tauri POS. Existing web B2B flow UNTOUCHED. Drop `invoice_subtype_code`.
D8. B2B / ZATCA Tax Invoice path TBD later (POS-authored vs web-B2B-aggregated).
D9. Tunisia priority + immediate target. NF525-certifiable canonical satisfies Tunisia by superset.

---

## 2. Verdict on payload candidates (recap from v3)

Candidate A (PHP 10 fields) — inadequate per committed external research §1-§4.
Candidate B (plan A4, 25 fields) — closer but missing seller/buyer block + cashier_name + gtin + tax_category_code + invoice_type_code + original_receipt_reference + non_collected_subtype + lottery_code + foreign_currency.
**Candidate C-v3** — 27-key cross-regime superset per §3 (unchanged from v3).

---

## 3. Candidate C-v3 — canonical payload (sorted lex)

`See v3 §3.` Unchanged in v4. 27 top-level keys. PAYLOAD_KEYS list (sorted):

```
[
  "business_date", "buyer", "cashier_id", "cashier_name", "consumption_mode",
  "currency_code", "currency_scale", "event_time_device", "invoice_type_code",
  "line_items", "lottery_code", "notes", "original_receipt_reference",
  "payments", "receipt_uuid", "seller", "shift_id", "subtotal", "table_id",
  "terminal_id", "total", "training_flag", "transaction_discount_amount",
  "transaction_discount_reason", "vat_breakdown", "vat_total", "vouchers_redeemed"
]
```

EXCLUDED (server-derived or envelope-level): `receipt_number`, `fiscal_event_id` (only referenced in `original_receipt_reference`), `fiscal_hash` / `previous_hash` / `sequence_number` (envelope), VAT-breakdown / payment-methods hashes.

---

## 4. Envelope vs payload mapping — full ZATCA UBL

`See v3 §4.` Unchanged in v4. The full XPath table is the deferred-but-ready ZATCA mapping; Pass 2A does NOT implement the ZATCA adapter (per D3).

---

## 5. D16-safe buyer block invariant

`See v3 §5.` Unchanged in v4. CI grep guard catches direct + indirect imports + container-resolved services + Eloquent cross-module reads.

---

## 6. VAT partition rule — concrete algorithm (N-16 closure)

Validator's responsibility on every SALE_RECEIPT payload:

```
GIVEN:
  payload.line_items[]      — N line records, each with {line_subtotal, line_vat, vat_rate, tax_category_code}
  payload.vat_breakdown[]   — K breakdown records, each with {rate, tax_category_code, net_amount, vat_amount, gross_amount}
  payload.currency_scale    — integer 0|2|3

ALGORITHM (PHP-side, using BCMath at $scale = payload.currency_scale):

1. Group line_items by composite key (line_items[i].vat_rate, line_items[i].tax_category_code).
2. For each distinct group g = (rate, category):
     g.sum_net    = bcadd accumulator over line_items[i].line_subtotal where (vat_rate, tax_category_code) match g
     g.sum_vat    = bcadd accumulator over line_items[i].line_vat where match
     g.sum_gross  = bcadd(g.sum_net, g.sum_vat, $scale)
3. Build set of distinct line groups G_lines = {(rate, category) : group g exists}.
4. Build set of breakdown groups G_breakdown = {(vat_breakdown[k].rate, vat_breakdown[k].tax_category_code) : 0 <= k < K}.

ASSERTIONS (validator REJECTS if any fails):

A1. SET EQUALITY:
    G_lines == G_breakdown.
    Forensic prefix on failure: payload_partition_mismatch:lines_set=<sorted_csv>:breakdown_set=<sorted_csv>

A2. NO DUPLICATE PARTITION ROW:
    For all (i, j) where i < j: (vat_breakdown[i].rate, vat_breakdown[i].tax_category_code) != (vat_breakdown[j].rate, vat_breakdown[j].tax_category_code).
    Forensic prefix: payload_partition_duplicate:rate=<r>:category=<c>

A3. AMOUNT EQUALITY per group (BCMath at $scale):
    For each breakdown row b with key (r, c):
      bccomp(g.sum_net,   b.net_amount,   $scale) == 0   OR forensic: payload_partition_net_mismatch:rate=<r>:category=<c>:expected=<g.sum_net>:got=<b.net_amount>
      bccomp(g.sum_vat,   b.vat_amount,   $scale) == 0   OR forensic: payload_partition_vat_mismatch:rate=<r>:category=<c>:expected=<g.sum_vat>:got=<b.vat_amount>
      bccomp(g.sum_gross, b.gross_amount, $scale) == 0   OR forensic: payload_partition_gross_mismatch:rate=<r>:category=<c>:expected=<g.sum_gross>:got=<b.gross_amount>

A4. SCALE INVARIANT:
    For every bcformat string field in the payload (subtotal, vat_total, total, transaction_discount_amount, line_items[].*amount, vat_breakdown[].*amount, payments[].amount, vouchers_redeemed[].redeemed_amount):
      The field MUST match moneyRegex($scale) = ^-?(0|[1-9]\d*)(\.\d{$scale})?$  (no fraction part when $scale = 0)
    Forensic prefix: payload_money_scale_mismatch:field=<path>:value=<actual>:expected_scale=<n>
```

### Invoice-level discount handling

`transaction_discount_amount` is a **separate informational field**, NOT folded into `vat_breakdown` aggregation. The convention:

- `line_items[].line_discount_amount` already represents per-line discount applied BEFORE VAT calculation. `line_items[].line_subtotal` and `line_items[].line_vat` reflect the post-line-discount values.
- `transaction_discount_amount` represents an invoice-level discount that the operator applied OVER the already-summed line totals (e.g. "10€ off the whole basket"). Per French + Tunisian + DE practice, this affects the EFFECTIVE total but the VAT breakdown rows reflect the per-line-discount-only aggregation.
- The validator does NOT enforce a relationship between `transaction_discount_amount` and `total`. The `total` field is whatever the device computed (`total = subtotal - transaction_discount_amount + vat_total` is a CONVENTION but not a partition-rule invariant — the device authors `total` directly).
- Cross-check rule (separate from partition): `bccomp(bcadd(payload.subtotal, payload.vat_total, $scale), bcadd(payload.total, payload.transaction_discount_amount, $scale), $scale) == 0`. Validator asserts: `payload.subtotal + payload.vat_total == payload.total + payload.transaction_discount_amount` at `$scale`. Forensic prefix: `payload_total_arithmetic_mismatch:subtotal+vat=<expected>:total+discount=<got>`.

### Negative-test list (Pass 2A `FiscalPayloadConstraintValidatorTest`)

1. **Duplicate partition row** — vat_breakdown contains two entries with same (rate, category); expect `payload_partition_duplicate`.
2. **Missing partition row** — line_items has lines at (20%, "") but vat_breakdown omits the 20% row; expect `payload_partition_mismatch`.
3. **Extra partition row** — vat_breakdown has a 5% row with no matching line_items; expect `payload_partition_mismatch`.
4. **One-cent drift** — line_items[].line_subtotal sums to "100.01" but vat_breakdown.net_amount is "100.00"; expect `payload_partition_net_mismatch`.
5. **Mixed 0% categories** — two breakdown rows at rate "0.00" with different tax_category_code ("Z" zero-rated vs "E" exempt); both must be present + match their respective line groups; tests verify partition treats them as distinct keys.
6. **Wrong scale** — payload.currency_scale=2 but line_items[i].unit_price="10.000" (3 decimal places); expect `payload_money_scale_mismatch`.
7. **Total arithmetic mismatch** — `subtotal + vat_total != total + transaction_discount_amount`; expect `payload_total_arithmetic_mismatch`.

### Implementation note

The TS-side validator (FiscalEventEngine `validateRequestPayload`) does NOT need to implement the partition algorithm — that's server-side, after parsing. The device validator only needs to assert structural / shape / regex conformance. Partition is a SEMANTIC invariant enforced server-side at the parser/validator boundary, AFTER the canonical_bytes have been verified for hash chain integrity.

---

## 7. Per-country tax-number patterns — universal validation only in Pass 2A (N-14 closure)

**Pass 2A validates `seller.tax_number` and `buyer.tax_number` (when not null) against a single universal regex:**

```
^[A-Za-z0-9 \-/.]{4,40}$
```

Plus non-empty, no control characters, no leading/trailing whitespace.

Per-country strict validation (KSA 15-digit, FR SIRET 14-digit, IT P.IVA 11-digit, DE USt-ID format, TN matricule fiscal format) DEFERRED to a new task post-Pass-2:

**NEW deferred task — "Per-country tax-number strict validation."** Added to roadmap v2 Phase-1.5 (pre-Tunisia-launch). Owner consults with accountants in each target country to confirm the exact regex pattern; the task lands the per-country regex table + per-country test fixtures + a validator branch keyed on `seller.tax_jurisdiction_country_code`.

Rationale: strict regex per country is a hardening pass. Shipping it incorrect in Pass 2A would cause all-or-nothing validation failures (e.g. TN sellers can't seal any receipts) — much worse than shipping universal validation and tightening later. With no production tenants, universal-now-strict-later is safe.

---

## 8. Pass 2A + Pass 2B — two clean commits on dev branch (refined per N-11 closure)

### 8.A — Pass 2A (contract land, single commit)

Scope identical to v3 §8.A EXCEPT:

**Test migration grep gate scope CORRECTED:**
- Grep gate target: `apps/api/tests/Feature/Fiscal/*.php`, `apps/api/tests/Unit/Fiscal/*.php`, `apps/pos/src/lib/fiscal/__tests__/*.ts`.
- Grep gate EXCLUDES: `apps/pos/src/lib/offline/__tests__/receiptService.test.ts`, `apps/pos/src/stores/__tests__/paymentStore*.test.ts` (these are Pass 2B's scope).
- Pre-commit assertion: across the IN-SCOPE files, the OLD 10-key signature regex `currency_scale[\s\S]{0,200}discount_total[\s\S]{0,200}lines[\s\S]{0,200}payment_lines` returns ZERO matches.
- ALL in-scope test files migrated to assert the 27-key Candidate C-v3 shape.

**Test file inventory (corrected paths):**
- `apps/api/tests/Feature/Fiscal/OutboxIngestorTest.php` — `minimalSaleReceiptPayload()` helper at line 821-840: regenerated.
- `apps/api/tests/Feature/Fiscal/ParseFailureResumeTest.php` — `correctedPayload()` helper at line 679-698: regenerated.
- `apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php` — payload seeders at line 927-938: regenerated.
- `apps/api/tests/Unit/Fiscal/StrictCanonicalParserTest.php` — parse-success + parse-failure prefix tests expanded for new 27-key shape + new forensic prefixes.
- `apps/api/tests/Feature/Fiscal/FiscalEventIngestionEndpointTest.php` — payload shape at line 464-483: regenerated.
- **CREATE** `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php` — does not currently exist; Pass 2A creates it. Coverage: extras-rejection, key-set, per-event constraints, nested object shape, partition algorithm (§6), money-scale, total-arithmetic-check.
- `apps/pos/src/lib/fiscal/__tests__/FiscalEventEngine.test.ts` — TS validator expanded for 27-key shape.
- `apps/pos/src/lib/fiscal/__tests__/payloadKeys.crossLanguage.test.ts` — drift gate updated for SALE_RECEIPT 27-key list.

**Pass 2A device-side test files NOT touched** (deferred to Pass 2B):
- `apps/pos/src/lib/offline/__tests__/receiptService.test.ts` — keeps existing 10-key shape mock assertions; receiptService.ts itself still emits old shape after Pass 2A merge.
- `apps/pos/src/stores/__tests__/paymentStore*.test.ts` — same.

**Why this is safe (corrected from v3):**
- Server-side fiscal tests in Pass 2A all expect 27-key shape (forward-locked).
- Device-side `receiptService.ts` STILL emits 10-key shape after Pass 2A merge (uncovered ground-truth).
- The two don't interact in CI because Pass 2A doesn't run any test that exercises `createOfflineReceipt` end-to-end through the new server validator — Pass 2A's server tests use hand-authored 27-key fixtures; Pass 2A's device tests still mock `FiscalEventEngine.append` (unchanged).
- Risk window: any new dev work between Pass 2A and Pass 2B that tries to wire `createOfflineReceipt` → `engine.append` → server ingestion would fail validation. Mitigation: Pass 2A's commit message explicitly says "Pass 2B is required before any end-to-end checkout test exercises the new contract." Pass 2B must merge before any further dev work touches the device→server seal path.

### 8.B — Nf525DataProvider refactor (within Pass 2A, sub-inventory per N-12)

In the SAME Pass 2A commit:

`apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php`:
- `mapSaleReceipt($receipt)`: bifurcate by `$receipt->fiscal_event_id IS NOT NULL`.
  - Fiscal-event-backed path: load `FiscalEvent::find($receipt->fiscal_event_id)`; use `CanonicalPayloadReader::forSaleReceipt($event)` to get a typed DTO; map DTO fields to `Nf525ExportSnapshot.sale` shape.
  - Legacy path (`fiscal_event_id IS NULL`): existing implementation preserved; will be dead code after Pass 2B (per D2 — no new device-side receipts seal without fiscal_event_id post-2B); kept until Phase-2 mirror-column-audit task drops it.
- `mapVoidedReceipt($receipt)` + `mapReturnReceipt($receipt)`: SAME bifurcation pattern.
- `mapLine($line)`: extract per-line fields from `CanonicalPayloadReader::forLineItems($event)` for fiscal-event-backed; legacy uses existing Receipt relation.
- `mapPayment($payment)`: extract from `CanonicalPayloadReader::forPayments($event)`.
- `mapVatDetail($vatRow)`: extract from `CanonicalPayloadReader::forVatBreakdown($event)`.

Cross-task touch: extends Task 30 closure (which currently bifurcates only top-level fields, not line-level).

Tests added to `apps/api/tests/Feature/Fiscal/Nf525ExportTest.php`:
- Canonical-only `gtin` round-trip: payload carries `gtin` for line; legacy `pos_receipt_lines` has no `gtin` column; NF525 export emits the gtin from canonical.
- Canonical-only `tax_category_code` round-trip.
- Foreign-currency payment fields (`payments[].foreign_currency_amount` + `payments[].foreign_currency_code`) round-trip in NF525 export.
- `original_receipt_reference` populated → NF525 export includes refund-linkage section.

### 8.C — Phase-2 deferred-task entries (in roadmap v2, edited in Pass 2A commit)

Two new deferred tasks added to `docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap-v2.md`:

1. **"Phase-2 mirror-column audit + drop"** (already in v3 §9).
2. **"Per-country tax-number strict validation"** (new per N-14 closure).
3. **"ParseFailureResolution operator UX — pre-fill from best-effort parse"** (already in v3 §13).

### 8.D — Pass 2B (receiptService refactor, second commit)

Scope: unchanged from v3 §8. Receipt service refactor + engine wiring + legacy chain deletion + Hybrid test migration of device-side tests (`receiptService.test.ts` + `paymentStore*.test.ts`).

Pass 2B's grep gate inherits Pass 2A's gate AND extends to include `apps/pos/src/lib/offline/**` + `apps/pos/src/stores/**`. After Pass 2B merge: ZERO matches of the OLD 10-key signature across the worktree.

Pass 2A's commit message MUST include: "**Pass 2B (receiptService → FiscalEventEngine assembler) is required before any end-to-end checkout exercises the new contract. Pass 2A and Pass 2B must merge sequentially on dev branch; do not merge any other PR between them that touches `apps/pos/src/lib/offline/receiptService.ts`.**"

---

## 9. "No dual chain" — deletion list

`See v3 §9.` Unchanged in v4.

---

## 10. Golden vector fixture matrix

`See v3 §10.` Unchanged in v4. F-1 through F-15.

---

## 11. Amended A1–A6 — FINAL FORM (ready for plan §2144-2348)

### Amended A1 — `FiscalEventEngine` singleton

`See v3 §11 Amended A1.` Unchanged in v4.

### Amended A2 — `fiscal_event_genesis_seed` provisioning

`See v3 §11 Amended A2.` Unchanged in v4.

### Amended A3 — `tenant_id` + `company_id` source

`See v3 §11 Amended A3.` Unchanged in v4.

### Amended A4 — Canonical SALE_RECEIPT payload shape

`See v3 §11 Amended A4.` Unchanged in v4, with ONE refinement:

- `seller.tax_number` and `buyer.tax_number` validation: universal pattern per §7 (Pass 2A); per-country strict validation deferred (new task per §8.C).
- VAT partition validation: per §6 concrete algorithm (Pass 2A `FiscalPayloadConstraintValidatorTest`).

### Amended A5 — Transactional boundary

`See v3 §11 Amended A5,` with concrete concurrent-receipt mutex spec per N-15 closure:

```ts
// apps/pos/src/lib/offline/terminalMutex.ts (NEW file in Pass 2B)

const queues = new Map<string, Promise<unknown>>();

export function lockTerminal<T>(
  tenantId: string,
  terminalId: string,
  fn: () => Promise<T>
): Promise<T> {
  const key = `${tenantId}:${terminalId}`;
  const prev = queues.get(key) ?? Promise.resolve();
  const next = prev.then(fn, fn);  // run fn regardless of prev's outcome
  queues.set(key, next.catch(() => {}));  // never reject the queue itself
  return next;
}
```

`paymentStore.createReceiptLocalFirst()` wraps the entire `createOfflineReceipt(...)` call (including the SQLite transaction):

```ts
return lockTerminal(activeTerminal.tenantId, activeTerminal.id, async () => {
  // Existing logic: validate input, open SQLite tx, call createOfflineReceipt, etc.
  let attempts = 0;
  while (true) {
    try {
      return await createOfflineReceipt(db, input);
    } catch (err) {
      if (err instanceof ConcurrentChainAdvanceError && attempts < 3) {
        attempts++;
        await new Promise(r => setTimeout(r, [50, 100, 200][attempts - 1]));
        continue;
      }
      if (err instanceof ConcurrentChainAdvanceError) {
        throw new FiscalChainContentionError('Could not advance fiscal chain after 3 retries');
      }
      throw err;
    }
  }
});
```

**Key invariants:**
- Lock key: `${tenantId}:${terminalId}` — tenant-scoped so a tenant-hijack scenario can't deadlock.
- Lock release: automatic via Promise resolution (`finally` semantics implicit in `.then(fn, fn)` — fn runs regardless of prior outcome).
- Backoff: linear (50ms, 100ms, 200ms); no exponential to avoid surprising operator wait.
- 3-retry cap: after exhaustion, raises `FiscalChainContentionError` (NEW TS exception class in `apps/pos/src/lib/fiscal/errors.ts`); operator notified to retry.
- Idempotency: each retry uses the SAME `source_event_id` (already captured in `OfflineReceiptInput`); engine deduplicates on `(source_event_class, source_event_id)` per Task 15.

**Tests (Pass 2B integration suite):**
- T-1. Two concurrent `createReceiptLocalFirst` calls against same terminal serialize (one starts after the other commits).
- T-2. Validation-failure BEFORE tx opens → lock released, next call proceeds.
- T-3. SQLite rollback → lock released, next call proceeds.
- T-4. `ConcurrentChainAdvanceError` thrown once → retry succeeds; assert single fiscal_event row written.
- T-5. `ConcurrentChainAdvanceError` thrown 4 times → `FiscalChainContentionError` raised; no fiscal_event row written.
- T-6. Calls against DIFFERENT terminal_ids run concurrently (no false serialization).

### Amended A6 — Test migration strategy

`See v3 §11 Amended A6.` Unchanged in v4.

---

## 12. Cross-language drift framing

`See v3 §12.` Unchanged in v4.

---

## 13. Parse-failure resolution UX

`See v3 §13.` Unchanged in v4.

---

## 14. Adapter scoping per D3

`See v3 §14.` Unchanged in v4.

---

## 15. Scope + risk + effort estimate (revised in v4)

- **Pass 2A** (contract + Nf525DataProvider line-level refactor + CanonicalPayloadReader + Phase-2 task entries + spec v8 + plan v5): **~4-5K LOC** across ~25+ files (v3 said 3-4K; v4 adjusts upward for Nf525 line-level refactor surfaced by Codex N-12).
- **Pass 2B** (receiptService + engine wiring + legacy deletion + terminalMutex + Hybrid test migration + Task 28 absorption): **~2-3K LOC** across ~15+ files (unchanged).
- **Total Pass 2: ~6-8K LOC** across ~40 files.
- **Expected review rounds:** Pass 2A = 3-4 rounds (cross-task touch is wider with Nf525 line-level refactor — closer to Task 30 precedent which was 3 rounds); Pass 2B = 2-3 rounds.
- **Total Pass 2 = 5-7 review rounds.**

---

## 16. Status

- v1 + v2 + v3 synthesis: superseded.
- v4 (this doc): pending Codex round-4 → owner final sign-off.
- After Codex round-4 APPROVE / APPROVE-WITH-MINOR-EDITS: plan §2144-2348 amended in place; spec v8 cut; Pass 2A implementer dispatched.

---

## 17. Remaining items for owner

NONE. v4 closes all surviving Codex findings + carries owner D1-D9 verbatim. No further architectural / scoping decisions required before Pass 2A dispatch.

(Tunisia tax-number regex confirmation per N-14 NO LONGER blocks Pass 2A — universal validation ships now, strict per-country regex deferred to a separate task per §7+§8.C.)

**End of synthesis v4.**
