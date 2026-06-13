# Adversarial Review — Fiscal Aggregate-Consistency Invariant v2

- **Branch:** `feat/precision-line-total-invariant` (worktree `apps/erp.precision-drift-remediation`)
- **Tip:** `ee5a3dfa3` — "fix(fiscal): aggregate-level line invariant (NF525) — replace false-positive per-line check"
- **Reviewer:** Opus 4.8 (1M)
- **Date:** 2026-05-30
- **Prior review:** `docs/superpowers/reviews/2026-05-30-precision-line-invariant-opus-review.md` (v1 — BLOCKER: per-line net-vs-gross check quarantined every taxed receipt)
- **The bar:** *Can a valid receipt be quarantined (server) or blocked from authoring (device)?* If yes → BLOCKER.

---

## Verdict summary

| | Server (`FiscalPayloadConstraintValidator`) | Device (`SaleReceiptPayload.ts`) |
|---|---|---|
| Prior v1 per-line BLOCKER | **GONE** ✅ (removed; regression test added; all 14 goldens pass) | n/a (device per-line check was always net-vs-gross-safe) |
| New aggregate check #1–#4 | No false positive ✅ | **NEW BLOCKER** ❌ on transaction-discount receipts |

The server-side rework is correct and the original BLOCKER is genuinely fixed. **But the device-side aggregate assertion introduces a new, reachable false-positive that hard-throws on any receipt carrying a transaction-level discount — blocking the sale from being authored/signed at all.** This is strictly worse than server quarantine.

---

## BLOCKER-1 — Device aggregate identity #1 omits the transaction-discount term; every ticket-level-discounted sale throws and cannot be signed

**File:** `apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts:143, 188–195` (and the missing discount param at `:181`).

The device computes (verified against `apps/pos/src/lib/offline/receiptService.ts:268–307`):
- `subtotalGross = Σ line_total` (gross, tax-inclusive)
- `taxAmount = Σ tax_amount`
- `total = subtotalGross − transactionDiscountAmount` (receiptService.ts:282)
- In `buildSaleReceiptPayload`: `subtotal(net) = subtotalGross − taxAmount`, `vat_total = taxAmount`

The device assertion (SaleReceiptPayload.ts:189–190):

```ts
const subtotalPlusVat = bcformat(bcadd(subtotal, vatTotal, scale), scale);
if (bccomp(subtotalPlusVat, total) !== 0) throw new SaleReceiptAggregateInvariantError(...)
```

`subtotal + vat_total = (subtotalGross − taxAmount) + taxAmount = subtotalGross`. The assertion therefore demands `subtotalGross == total`, i.e. `subtotalGross == subtotalGross − transactionDiscountAmount` — which is **false whenever a transaction discount is applied**. The discount term `discountAmount` is computed at SaleReceiptPayload.ts:122 but is **never passed** into `assertSaleReceiptAggregates` (call site line 143), unlike the server which correctly uses `subtotal + vat_total == total + transaction_discount_amount` (`FiscalPayloadConstraintValidator.php:807–809`).

**Reproduced (isolated, 0% tax so only the aggregate check is in play):** clean line 10.00, `transactionDiscountAmount '2.00'`, `total '8.00'` — exactly what `receiptService` emits:

```
SaleReceiptAggregateInvariantError: subtotal (10.00) + vat_total (0.00) = 10.00 != total 8.00.
```

`transactionDiscount` is a fully-wired, reachable feature (`cartStore.setTransactionDiscount`, `paymentStore`, `holdStore`, `refundDraftStore`). `buildSaleReceiptPayload` is on the critical authoring path (`receiptService.ts:295`); the throw happens *before* canonical bytes are produced, so the sale **cannot complete**. A valid discounted receipt is blocked → BLOCKER.

**Fix direction:** thread the discount into the device assertion to match the server — assert `subtotal + vat_total == total + transaction_discount_amount` (pass `discountAmount` into `assertSaleReceiptAggregates`). The server and device identities MUST be symmetric.

---

## BLOCKER-2 — Branch is not preflight-green: 8 pre-existing device tests fail (the suite was left RED)

Running the device suites the review scope names:

- `apps/pos` `src/lib/fiscal/**` → **228 pass** ✅
- `apps/pos` `src/lib/offline/**` + `paymentStore.offlineFirst` + `offlineFirstFlow` → **8 fail / 105 pass** ❌

Failing files:
- `src/lib/offline/__tests__/receiptService.test.ts` (the transaction+line discount test)
- `src/__tests__/integration/offlineFirstFlow.test.ts`
- `src/lib/offline/__tests__/idempotencyRetry.integration.test.ts` (×2)
- `src/lib/offline/__tests__/voucherCheckout.integration.test.ts` (×4)

Two distinct causes:
1. **7 stale-fixture failures** — hand-built carts where `line_total` was set independently of `unit_price × quantity` (e.g. `line_total: '50.00'` with `unit_price '10.00', quantity 1`). The device **line-arithmetic invariant** (`SaleReceiptPayload.ts:274–287` — carried over from the WIP commit, sound for real carts) correctly flags them. These are fixture debt, but the branch shipped them RED.
2. **1 is the BLOCKER-1 path** — `receiptService.test.ts > carries every discount...` trips the line invariant first (its fixture also has a stale `line_total`), but even with the fixture fixed it would then hit BLOCKER-1's `SaleReceiptAggregateInvariantError`.

Per CLAUDE.md Rule 10 (preflight before commit) and the verification-before-completion discipline, a branch with a RED suite is not mergeable regardless of BLOCKER-1.

---

## What is actually correct (verified, not assumed)

### Server: original BLOCKER is gone, no new false positive
- Per-line `line_subtotal == round(unit_price × qty) − discount` check **removed**; replaced by a `NOTE` comment documenting the net-vs-gross trap (`FiscalPayloadConstraintValidator.php:1708–1721`).
- Regression test `test_device_shaped_taxed_receipt_with_inclusive_unit_price_passes` feeds the exact device shape (gross `unit_price 12.00`, net `line_subtotal 10.00`, `line_vat 2.00`) and the validator **accepts** it. The false positive is gone.
- **All 14 golden fixtures pass** the server validator (probed directly: F-01…F-14, including **F-04 voucher**, **F-06 multi-VAT-rate**, **F-09 refund**, **F-10 void**, **F-11 training**, **F-12 dine-in**). 0 quarantined.
- Server `FiscalPayloadConstraintValidatorTest` → **114 pass / 242 assertions**.

### The classic multi-rate VAT-aggregation trap does NOT bite (#1 question)
For `Σ vat_breakdown.vat == vat_total` and `Σ vat_breakdown.net == subtotal`:
- `tax_amount` is already rounded to currency scale in the cart (`cartStore.computeTaxAmount` returns `bcsub(..., decimals)`), and `line_vat = bcformat(tax_amount, scale)` is a no-op.
- `vat_total = bcformat(Σ tax_amount, scale)`; `Σ vat_breakdown.vat = Σ_groups bcformat(Σ_{in group} line_vat, scale)`. Because **every addend is already at scale**, grouped-sum and flat-sum are *both* exactly `Σ line_vat`. Identical for net. No ordering-dependent drift, for any number of rates. F-06 (4 rates) confirms it empirically.

### Device no-discount path is sound
Probed realistic non-discount carts (fractional qty `2.5`, odd rates `7%/19%`, `12.99 × 1.333`) through `buildSaleReceiptPayload` → all pass. The device line invariant uses the same `bcmul` half-up (`Big.RM = 1`) as `cartStore.recalcLineTotal`, so real carts reconcile. A real **line-level** discount (where `recalcLineTotal` subtracts it from `line_total`) also passes — the failure is exclusively the **transaction-level** discount path.

---

## Secondary findings

### P2 — Server aggregate check #1 and #4 are fully redundant with pre-existing checks
- New check #1 (`subtotal + vat_total == total + discount`, `:807–813`) **exactly duplicates** the §6.D `payload_total_arithmetic_mismatch` step already run earlier in the same method (`:703–713`). The §6.D check fires first; the author's own test `test_aggregate_subtotal_plus_vat_not_equal_total_is_quarantined` asserts the `payload_total_arithmetic_mismatch` prefix, confirming the new #1 is dead code on that path.
- New check #4 (`gross == net + vat` per group, `:824–829`) **exactly duplicates** the per-row check in `validateVatBreakdownRow` (`:1826–1828`, `payload_partition_gross_mismatch`) AND the per-group check in `validateVatPartition` (`:1936–1940`).
- Only #2 (`Σ net == subtotal`) and #3 (`Σ vat == vat_total`) are genuinely new (they tie the breakdown to the declared top-level `subtotal`/`vat_total`, which `validateVatPartition` ties only to the *line* sums). Not redundant; correct.
- Not a blocker (redundant exact checks can't false-positive), but the method could be reduced to #2/#3 only. Note this means **identity #1 does NOT conflict** with `payload_total_arithmetic_mismatch` — same formula, same scale, same bccomp — it is purely redundant, not contradictory.

### Confirmations against the checklist
- **Exact comparison, no tolerance:** ✅ `bccomp(... , scale) === 0` everywhere (server) / `bccomp(...) !== 0` (device).
- **Scale from payload, not CompanyContext:** ✅ `$scale = $payload['currency_scale']`, validated to `{0,2,3}` before use (`:623–637`).
- **Quarantine, non-crashing:** ✅ server throws `RuntimeException` → wrapped as `sub_array_shape:<msg>` by the parser / `InvalidCorrectedPayloadException` by the resolver → event stored-not-projected.
- **hash / canonical_bytes untouched:** ✅ the validator operates on the decoded payload only; no hash recomputation in the new code.
- **Device assertion matches server:** ❌ — see BLOCKER-1 (the discount term asymmetry).
- **Tests non-tautological:** ✅ server tests tamper real golden fixtures and assert specific forensic prefixes; they exercise #2 and #3 distinctly. **Gap:** neither the server nor the device tests cover a non-zero `transaction_discount_amount` in identity #1 — which is exactly why BLOCKER-1 slipped through. Add a discounted-receipt happy-path test on **both** sides.
- **Scale-0 (JPY) / scale-3 (TND) / zero-tax / single-line:** ✅ server `test_aggregate_consistent_at_scale_3_tnd_passes` + goldens cover these; device no-discount probe covers fractional/odd-rate. No false positive on these edges.

---

## Required before merge
1. **Fix BLOCKER-1:** pass `transaction_discount_amount` into the device `assertSaleReceiptAggregates` and assert `subtotal + vat_total == total + transaction_discount_amount` (symmetric with server `:807–809`).
2. **Add a transaction-discount happy-path test on the device** (and one on the server) — the missing coverage that hid this.
3. **Fix BLOCKER-2:** repair the 8 stale `line_total` fixtures (or the helper) so the device line-arithmetic invariant reconciles; get `apps/pos` `src/lib/offline/**` green.
4. *(Optional P2)* trim server `validateSaleReceiptAggregateConsistency` to checks #2/#3 only (drop redundant #1/#4).

---

## VERDICT

**BLOCKER.** The prior per-line server BLOCKER is genuinely fixed (server: 0 false positives, all 14 goldens pass, regression test in place). However a **new device-side BLOCKER** was introduced: the aggregate assertion `subtotal + vat_total == total` omits the transaction-discount term, so every ticket-level-discounted sale throws `SaleReceiptAggregateInvariantError` and cannot be authored/signed (reproduced). Additionally the branch is **not preflight-green** — 8 device tests fail. Do not merge until BLOCKER-1 and BLOCKER-2 are resolved and discounted-receipt coverage is added on both sides.
