# Adversarial Review — Fiscal Line-Arithmetic Invariant (device + server)

**Branch:** `feat/precision-line-total-invariant` (uncommitted working tree; `HEAD == origin/dev == 780f7352e`)
**Reviewer:** Opus 4.8 (1M)
**Date:** 2026-05-30
**Files under review:**
- `apps/pos/src/lib/fiscal/payloads/SaleReceiptPayload.ts` (device invariant)
- `apps/pos/src/lib/fiscal/payloads/__tests__/SaleReceiptPayload.lineInvariant.test.ts` (untracked, device tests)
- `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php` (`validateLineItem`, server invariant)
- `apps/api/tests/Feature/Fiscal/FiscalPayloadConstraintValidatorTest.php` (server tests)

---

## THE CRITICAL QUESTION: can the server check quarantine a VALID receipt? — YES.

**Empirically confirmed.** A real, well-formed, device-authored taxed receipt is QUARANTINED by the new server check. This is a production-breaking false positive on the dominant case (any line with non-zero VAT).

### Root cause: tax-model mismatch between the two fields the server compares

The POS cart is **unconditionally tax-INCLUSIVE** (`apps/pos/src/stores/cartStore.ts:84`: "Tax-inclusive: extract tax from price that already includes it." — there is no tax-exclusive mode). Therefore in the cart:
- `item.unit_price` **includes** tax.
- `item.line_total = unit_price × qty − discount` (a GROSS/inclusive figure).
- `item.tax_amount` is **extracted** from the inclusive total (`computeTaxAmount`: `net = lineTotal / (1+rate/100); tax = lineTotal − net`).

The device canonical builder (`SaleReceiptPayload.ts:174-236`) then writes:
- `unit_price: bcformat(item.unit_price, scale)` (line 233) — the **INCLUSIVE** cart price, copied verbatim, **no net conversion anywhere**.
- `line_subtotal = bcformat(bcsub(item.line_total, item.tax_amount), scale)` (line 184) — i.e. `line_total − tax` = **NET**.

But the canonical contract defines `unit_price` as **NET/pre-tax**, with VAT added on top. This is unambiguous from the golden vectors (`apps/api/tests/Fixtures/Fiscal/sale-receipt-golden/v4/`):
- F-04: `unit_price=20.00`, `line_vat=4.00`, `line_subtotal=20.00` (gross would be 24.00).
- F-01: `unit_price=10.00`, `line_vat=2.00`, `line_subtotal=10.00`.
- F-05/F-06: every line has `line_subtotal == unit_price × qty` with VAT on top.

The server check (`FiscalPayloadConstraintValidator.php:1645-1662`) is built for the NET contract:
```php
$pricedQuantity = round(unit_price × quantity, scale, HALF_UP);
$expectedSubtotal = $pricedQuantity − line_discount_amount;
if (bccomp($expectedSubtotal, $line_subtotal, $scale) !== 0) throw … // → QUARANTINE
```

So for any taxed line the server computes `inclusive_unit_price × qty − disc` (a **gross** number) and compares it to `line_subtotal` (a **net** number). They differ by exactly the line VAT → quarantine.

### Empirical proof (round-trip device output → server validator)

Device test (a) (`SaleReceiptPayload.lineInvariant.test.ts:78-91`) asserts that a cart line with `unit_price=6.00` (inclusive), `qty=2`, `line_total=12.00`, `tax_rate=20`, `tax_amount=2.00` is VALID device output. I ran the device suite — all 6 pass. The device emits canonical `unit_price=6.00` (inclusive), `line_subtotal=10.00` (net), `line_vat=2.00`.

I then fed that exact device-produced canonical payload to the server validator (temporary `DeviceServerRoundTripTest`):

```
>>> RESULT: SERVER QUARANTINED valid device receipt:
    payload_line_arithmetic_mismatch:line_items[0]:expected=12.00:got=10.00
```

The server expected `6.00 × 2 − 0 = 12.00` and rejected the net subtotal `10.00`. The two layers do **not** agree on real taxed data. (Test deleted after running.)

### Why the test suite is green anyway — the dodge

- **All server tests pass (114) and golden vectors pass (2)** — but every server "passing" test (`test_line_arithmetic_consistent_*`) HAND-AUTHORS canonical payloads with **net** `unit_price` (e.g. `unit_price=5.00`, `line_subtotal=13.00`, `line_vat=2.60`). None of them round-trips actual device output. The golden vectors are likewise net. So the suite never exercises a tax-inclusive `unit_price` — the exact field the device emits — and the defect is invisible by construction.
- **All device tests pass (6)** — but they only assert the device's *own* inclusive-gross invariant; none asserts that the resulting canonical `line_subtotal` equals `unit_price × qty − disc` (the server's rule). Device test (a) is the proof the two contracts diverge.

This is the requested "constructed to dodge the tax-model issue" failure mode: each side is internally tested against its own model, and the suites never cross the seam.

---

## Findings

### BLOCKER-1 — Server quarantines every valid taxed SALE_RECEIPT
`FiscalPayloadConstraintValidator.php:1645-1662` vs `SaleReceiptPayload.ts:233`.

The server invariant assumes canonical `unit_price` is NET; the device emits it INCLUSIVE. Any sale line with `vat_rate > 0` (i.e. the overwhelming majority of real receipts in FR/TN/IT/SA) produces `unit_price × qty ≠ line_subtotal` (off by the line VAT) and is routed to QUARANTINE — event stored, never projected → silent revenue/GL loss and a fiscal reporting hole. Zero-VAT lines (the only ones the device tests/golden vectors exercising the device path happen to dodge) pass; everything taxed fails.

**Two mutually-exclusive fixes — pick per the true contract (do NOT ship both):**

1. **If the canonical contract is net (golden vectors say it is):** the *device* is the bug. `buildLineItems` must convert the inclusive cart price to a net `unit_price` before writing canonical, so canonical satisfies `line_subtotal == round(net_unit_price × qty, scale) − discount`. Concretely, derive `net_unit_price` from the net line (`line_subtotal + discount) / qty`, rounded at scale) rather than copying `item.unit_price`. Then the server check is correct as written. NOTE: this also means the *current device already emits a contract-violating `unit_price`* independent of this PR — the new server check merely surfaced a pre-existing latent device/contract drift. Confirm the golden hash bytes are regenerated if `unit_price` changes (hash-affecting).

2. **If `unit_price` is intended to be inclusive at the canonical layer:** the *server check* is wrong. It must reconstruct gross and compare against a gross figure, or compare `line_subtotal == (round(unit_price × qty, scale) − discount) − line_vat`. But this contradicts every golden vector, so this path also requires re-deriving the golden fixtures — higher blast radius.

**Either way, the fix is incomplete until a test round-trips real device output (`buildSaleReceiptPayload`) through the server validator and passes for a taxed line.** That cross-seam test is the missing guard.

### P1-1 — Tests are non-tautological for forgery but blind to the tax seam
The server negative tests *would* fail under a forged line (verified: `test_line_subtotal_not_equal_…` and `test_line_discount_not_folded_…` correctly reject), so the check is not a no-op. But because every positive test is hand-authored net, the suite gives false confidence that device output passes. **Add a conformance test that runs `buildSaleReceiptPayload` on a taxed cart and asserts the server validator accepts the result** (or, minimally, a PHP fixture mirroring device test (a)). Without it, BLOCKER-1 can regress undetected.

### P2-1 — Device gross-invariant double-rounding on fractional quantity
`SaleReceiptPayload.ts:193-197` computes `bcmul(item.unit_price, String(item.quantity), scale)` at currency scale, but `quantity` is emitted at scale 3 (line 230) and the cart's `recalcLineTotal` (cartStore.ts:97) multiplies at currency `decimals` too. For fractional quantities (scale-4 quantities mentioned in MEMORY, now scale-3 in canonical) the invariant re-multiplies a scale-3-truncated quantity, which can disagree with the cart's original multiply that used the un-truncated quantity. Low likelihood given current integer-qty retail flows, but it can throw `LineArithmeticInvariantError` on a legitimately-priced fractional-qty line. Multiply at `QUANTITY_SCALE + scale` then round once, mirroring the server's `bcmul(…, QUANTITY_SCALE + scale + 1)`.

### NIT-1 — Scale source is correct on both sides
Confirmed not a defect: device uses `getCurrencyDecimals(input.currency)` (payload currency, lines 90-95); server uses `$payload['currency_scale']` (line 624), not a bound `CompanyContext`. Comparison is exact `bccomp(...) === 0` with no tolerance on both sides. Quarantine path is the existing non-crashing `RuntimeException → sub_array_shape → QUARANTINE` route (not a hard crash). Modifier `price_adjustment` folding IS enforced on the device (folded into `unit_price` at `cartStore.ts:192`; device test (c) at lines 136-165 proves an unfolded adjustment throws). The ACCOUNT_CHARGE line-item path does NOT carry the new arithmetic check, so blast radius is bounded to SALE_RECEIPT.

---

## VERDICT

**REJECT — 1 BLOCKER.**

The server line-arithmetic invariant quarantines every valid taxed SALE_RECEIPT because it compares the device's tax-INCLUSIVE canonical `unit_price` against a NET `line_subtotal`, expecting both to be net. Empirically reproduced: device test (a)'s own output yields `payload_line_arithmetic_mismatch:line_items[0]:expected=12.00:got=10.00` from the server. The full test suite is green only because every positive test (server + golden) hand-authors net `unit_price` and never round-trips real device output across the seam. Do not merge until (a) the net-vs-inclusive contract is reconciled on ONE side, and (b) a cross-seam conformance test feeds `buildSaleReceiptPayload` output for a taxed line through the server validator and passes.
