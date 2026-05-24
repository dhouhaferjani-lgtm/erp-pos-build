# SALE_RECEIPT Canonical Payload — Synthesis v5

**Date:** 2026-05-20
**Author:** Controller
**Status:** LOCKED. Phase 1.5.2 amended §7 with the landed per-country tax-number table.
**Supersedes:** v1, v2, v3, v4. All prior versions in `docs/superpowers/research/`.

---

## 0. Diff from v4 — Codex round-4 closures

v5 carries v4 unchanged EXCEPT for these targeted closures:

| Round-4 Finding | Closure (v5 section) |
|---|---|
| N-16 partial (invoice-discount + scale invariant) | §6 rewritten: `transaction_discount_amount` LOCKED non-negative (regex without leading `^-?`); the contradictory "validator does not enforce a relationship" sentence DELETED and REPLACED with the explicit arithmetic-cross-check invariant; FR+TN+DE-practice attribution dropped (stated as deliberate AutoERP convention); full scale-invariant field enumeration locked. |
| N-19 P1 (invoice-discount semantics not contract-safe) | §6 closure same as above — `transaction_discount_amount`: non-negative; `transaction_discount_reason`: required iff amount > "0", null iff amount == "0"; signed-adjustment semantics explicitly ruled out (no surcharge via this field); VAT allocation convention explicitly stated as AutoERP convention not regime requirement. |
| N-12 partial (Nf525 sub-method bifurcation) | §8.B pseudo-code listing for `mapLine`, `mapPayment`, `mapVatDetail`: each bifurcates by `$line->receipt->fiscal_event_id !== null` (or equivalent reverse-relation); fiscal-event-backed reads from `CanonicalPayloadReader`; legacy preserves existing Eloquent reads. |
| N-23 P2 (Pass 2A safety depends on commit message) | §8.A + §8.D add CI sentinel: Pass 2A creates `apps/pos/src/lib/offline/.PASS_2B_PENDING` marker file + CI check that fails any PR with `receiptService.ts` importing `FiscalEventEngine` while marker exists. Pass 2B atomically removes marker + adds import. Enforces sequencing without depending on commit-message discipline. |
| N-24 P2 (scale invariant omits fields) | §6 closure: explicit table of every bcformat field with the scale it's validated at; covers `unit_price`, `line_subtotal`, `line_vat`, `line_discount_amount`, `vat_rate`, etc. |

Everything else from v4 (D1-D9 decisions, 27-key Candidate C-v3 shape, §4 ZATCA XPath mapping, §5 D16 invariants, §10 fixture matrix, §11 mutex spec, §12 cross-language drift framing, §13 parse-failure UX, §14 adapter scoping) carries unchanged.

---

## 1-5. (v4 §1-§5 unchanged)

See v4 at `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v4.md`.

---

## 6. VAT partition rule + scale invariant — locked (N-16 + N-19 + N-24 closure)

### 6.A — Field-level sign and presence rules

```
payload.transaction_discount_amount: bcformat NON-NEGATIVE string at currency_scale.
    Regex: ^(0|[1-9]\d*)(\.\d{$scale})?$    (NO leading minus; 0 allowed for "no discount")
    No surcharge case: this field models commercial discount only, NEVER negative adjustment.

payload.transaction_discount_reason: string | null.
    INVARIANT: (bccomp(transaction_discount_amount, "0", $currency_scale) == 0) ⟺ (transaction_discount_reason == null)
    NOTE: bcformat at scale=2 emits "0.00" not "0", so literal string equality is wrong. Use BCMath numeric comparison.
    Forensic prefix on violation: payload_discount_reason_mismatch:amount=<a>:reason_present=<bool>
```

All other money fields use the same NON-NEGATIVE regex (no `^-?` prefix anywhere in payload money fields). Refunds are modeled via `invoice_type_code='REFUND'` + `original_receipt_reference` (not negative amounts in SALE payload).

### 6.B — Scale invariant — explicit field table

| Field path | Scale | Regex |
|---|---|---|
| `subtotal` | `currency_scale` | `moneyRegex($currency_scale)` |
| `vat_total` | `currency_scale` | `moneyRegex($currency_scale)` |
| `total` | `currency_scale` | `moneyRegex($currency_scale)` |
| `transaction_discount_amount` | `currency_scale` | `moneyRegex($currency_scale)` |
| `line_items[].unit_price` | `currency_scale` | `moneyRegex($currency_scale)` |
| `line_items[].line_subtotal` | `currency_scale` | `moneyRegex($currency_scale)` |
| `line_items[].line_vat` | `currency_scale` | `moneyRegex($currency_scale)` |
| `line_items[].line_discount_amount` | `currency_scale` | `moneyRegex($currency_scale)` |
| `line_items[].quantity` | `quantity_scale` (fixed at 3 for Phase 1) | `moneyRegex(3)` |
| `line_items[].vat_rate` | `vat_rate_scale` (fixed at 2 for Phase 1 — "20.00", "5.50") | `moneyRegex(2)` |
| `vat_breakdown[].net_amount` | `currency_scale` | `moneyRegex($currency_scale)` |
| `vat_breakdown[].vat_amount` | `currency_scale` | `moneyRegex($currency_scale)` |
| `vat_breakdown[].gross_amount` | `currency_scale` | `moneyRegex($currency_scale)` |
| `vat_breakdown[].rate` | `vat_rate_scale` | `moneyRegex(2)` |
| `payments[].amount` | `currency_scale` | `moneyRegex($currency_scale)` |
| `payments[].foreign_currency_amount` (when not null) | foreign-currency scale (lookup table by foreign_currency_code) | `moneyRegex(scale_for(foreign_currency_code))` |
| `vouchers_redeemed[].redeemed_amount` | `currency_scale` | `moneyRegex($currency_scale)` |

Where:
```
moneyRegex($scale) = ^(0|[1-9]\d*)(\.\d{$scale})?$    for $scale >= 1
                   = ^(0|[1-9]\d*)$                     for $scale == 0
```

Any field failing its scale check → forensic prefix `payload_money_scale_mismatch:field=<path>:value=<actual>:expected_scale=<n>`.

### 6.C — VAT partition algorithm (PHP-side, BCMath)

```
GIVEN:
  payload.line_items[]      — N line records
  payload.vat_breakdown[]   — K breakdown records
  $scale = payload.currency_scale

ALGORITHM:

1. Group line_items by composite key (line_items[i].vat_rate, line_items[i].tax_category_code).
2. For each distinct group g = (rate, category):
     g.sum_net    = bcadd accumulator over line_items[i].line_subtotal where (vat_rate, tax_category_code) match g
     g.sum_vat    = bcadd accumulator over line_items[i].line_vat where match
     g.sum_gross  = bcadd(g.sum_net, g.sum_vat, $scale)
3. Build G_lines = {(rate, category) : group g exists}.
4. Build G_breakdown = {(vat_breakdown[k].rate, vat_breakdown[k].tax_category_code) : 0 <= k < K}.

ASSERTIONS (reject if any fails):

A1. SET EQUALITY: G_lines == G_breakdown.
    Forensic: payload_partition_mismatch:lines_set=<sorted_csv>:breakdown_set=<sorted_csv>

A2. NO DUPLICATE PARTITION ROW:
    For all (i, j) where i < j: vat_breakdown[i] != vat_breakdown[j] on (rate, tax_category_code).
    Forensic: payload_partition_duplicate:rate=<r>:category=<c>

A3. AMOUNT EQUALITY per group (BCMath bccomp at $scale):
    For each breakdown row b with key (r, c):
      bccomp(g.sum_net,   b.net_amount,   $scale) == 0
      bccomp(g.sum_vat,   b.vat_amount,   $scale) == 0
      bccomp(g.sum_gross, b.gross_amount, $scale) == 0
    Forensic on mismatch: payload_partition_<field>_mismatch:rate=<r>:category=<c>:expected=<e>:got=<g>

A4. SCALE INVARIANT per §6.B field table above.
```

### 6.D — Total arithmetic cross-check (INVARIANT — replaces v4's contradictory wording)

The validator enforces this cross-check unconditionally:

```
bccomp(
  bcadd(payload.subtotal, payload.vat_total, $currency_scale),
  bcadd(payload.total, payload.transaction_discount_amount, $currency_scale),
  $currency_scale
) == 0
```

I.e. `subtotal + vat_total == total + transaction_discount_amount` at `currency_scale`.

This models the AutoERP convention: `subtotal` is the pre-invoice-discount net total (after per-line discounts already applied); `vat_total` is the VAT on that subtotal; `total` is the final settle amount; `transaction_discount_amount` is the invoice-level commercial discount applied AFTER VAT calculation.

Forensic on mismatch: `payload_total_arithmetic_mismatch:lhs=<subtotal+vat_total>:rhs=<total+transaction_discount_amount>`

**Note on VAT allocation:** Per AutoERP convention (NOT a multi-country regime requirement — different regimes handle invoice-level discounts differently), the `vat_breakdown` rows reflect per-line-discount-aggregations and do NOT recompute VAT after the invoice-level discount. When ZATCA / DSFinV-K / IT RT adapters are built later, their export layer can recompute VAT-on-discounted-base if their regime requires it; the canonical payload's `vat_breakdown` is the AutoERP authority for the chain hash. This convention is explicitly documented in spec v8 §11.

### 6.E — Negative-test list (`FiscalPayloadConstraintValidatorTest` in Pass 2A)

1. **Duplicate partition row** — two breakdown entries at same (rate, category); expect `payload_partition_duplicate`.
2. **Missing partition row** — lines at (20%, "") but no matching breakdown; expect `payload_partition_mismatch`.
3. **Extra partition row** — breakdown row with no matching line; expect `payload_partition_mismatch`.
4. **One-cent drift** — lines sum to "100.01" but breakdown.net_amount "100.00"; expect `payload_partition_net_mismatch`.
5. **Mixed 0% categories** — two breakdown rows at rate "0.00" with categories "Z" + "E"; lines must match each; partition treats them distinct.
6. **Wrong scale** — currency_scale=2 but `line_items[i].unit_price="10.000"`; expect `payload_money_scale_mismatch:field=line_items[i].unit_price`.
7. **Total arithmetic mismatch** — subtotal+vat_total != total+transaction_discount_amount; expect `payload_total_arithmetic_mismatch`.
8. **Negative transaction_discount** — `transaction_discount_amount="-5.00"`; expect `payload_money_scale_mismatch` (regex rejects leading minus; the scale-invariant regex per §6.B handles all monetary format failures including sign — single forensic prefix for all regex misses keeps the validator's failure surface stable across format and scale faults).
9. **Discount-reason consistency, scale=2 zero** — `transaction_discount_amount="0.00"` paired with `transaction_discount_reason="seasonal"`; expect `payload_discount_reason_mismatch:amount=0.00:reason_present=true`. Use BCMath `bccomp("0.00", "0", 2) == 0` for the zero check (literal `=="0"` would mis-classify "0.00" as non-zero).
10. **Discount-reason consistency, scale=2 zero positive case** — `transaction_discount_amount="0.00"` paired with `transaction_discount_reason=null`; expect PASS (this is the no-discount case at scale=2).
11. **Discount-reason consistency, non-zero** — `transaction_discount_amount="5.00"` paired with `transaction_discount_reason=null`; expect `payload_discount_reason_mismatch:amount=5.00:reason_present=false`.
12. **Discount-reason consistency, scale=0 zero (TND)** — `transaction_discount_amount="0"` (currency_scale=0) paired with `transaction_discount_reason=null`; expect PASS.

### 6.F — TS-side validator

TS-side validator (`FiscalEventEngine.validateRequestPayload`) validates STRUCTURAL conformance (key set, types, regex, enums) but does NOT implement the partition algorithm. Partition + total-arithmetic invariants are SERVER-side semantic checks at the parser/validator boundary, after canonical_bytes hash chain integrity has been verified. This split avoids duplicating BCMath logic between TS+PHP.

---

## 7. Per-country tax-number patterns — LANDED in Phase 1.5.2

Pass 2A originally used a universal tax-number baseline
`^[A-Za-z0-9 \-/.]{4,40}$` so an incorrect country regex would not block
the receipt-chain rebuild. Phase 1.5.2 replaces that placeholder with the
country-keyed table below while retaining the universal baseline for unknown
countries.

Seller / customer `tax_number` patterns:

| Country | Pattern | Notes |
| --- | --- | --- |
| FR | `^([0-9]{9}|[0-9]{14})$` | SIREN or SIRET for seller/customer tax number. Buyer TVA intracommunautaire also accepts `^FR[0-9]{11}$`. |
| TN | `^[0-9]{7,8}[A-Z]{2}[0-9]{3}$` | Slash input such as `1234567/A/M/000` normalizes to compact `1234567AM000`; canonical producers emit compact. |
| SA | `^3[0-9]{12}03$` | Saudi VAT number shape retained for future ZATCA axis. |
| DE | `^DE[0-9]{9}$` | USt-IdNr shape retained for future DSFinV-K / TSE axis. |
| IT | `^[0-9]{11}$` | Partita IVA. Individual codice fiscale belongs in `buyer.codice_fiscale`, validated separately as `^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$`. |

Forensic mismatch prefixes:

- `payload_tax_number_format_mismatch:field=<path>:country=<country>:value=<actual>`
- `payload_buyer_codice_fiscale_format_mismatch:field=buyer.codice_fiscale:value=<actual>`

---

## 8. Pass 2A + Pass 2B — two clean commits on dev branch

### 8.A — Pass 2A scope (refined per N-23 closure)

Server-side test migration scope: unchanged from v4 §8.A.

**NEW: Pass 2A creates `apps/pos/src/lib/offline/.PASS_2B_PENDING`** — a 0-byte marker file. Content (one line):
```
Pass 2B (receiptService → FiscalEventEngine assembler) is required before any end-to-end checkout exercises the new contract. See docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md §8 + plan §2144-2348 Amended A1-A6.
```

**NEW: CI check** added in Pass 2A — `apps/pos/scripts/check-pass-2b-pending.sh` (broader than v5 first draft per Codex N-25 — checks for indirect wiring too):

```bash
#!/usr/bin/env bash
set -euo pipefail
MARKER="apps/pos/src/lib/offline/.PASS_2B_PENDING"
RECEIPT_SVC="apps/pos/src/lib/offline/receiptService.ts"
PAYMENT_STORE="apps/pos/src/stores/paymentStore.ts"

if [ ! -f "$MARKER" ]; then
  # Pass 2B has shipped (marker removed); no further checks needed.
  exit 0
fi

# Marker exists → Pass 2A landed, Pass 2B not yet shipped.
# Reject any PR that wires the engine into the device-side checkout path.

FORBIDDEN_PATTERNS=(
  # Direct class name
  'FiscalEventEngine'
  # Singleton accessor (added in Pass 2B per Amended A1)
  'getFiscalEventEngine'
  # The mutex helper Pass 2B will introduce per Amended A5
  'lockTerminal'
  # Engine.append() invocation pattern (the canonical engine seal call)
  '\.append(.*event_type'
)

for file in "$RECEIPT_SVC" "$PAYMENT_STORE"; do
  if [ ! -f "$file" ]; then
    continue
  fi
  for pattern in "${FORBIDDEN_PATTERNS[@]}"; do
    if grep -qE "$pattern" "$file" 2>/dev/null; then
      echo "ERROR: .PASS_2B_PENDING marker exists but $file matches forbidden pattern: $pattern"
      echo "  Pass 2A's contract sequencing has been violated."
      echo "  Pass 2B MUST atomically remove the marker AND add the engine wiring."
      echo "  Either complete Pass 2B in this PR (remove $MARKER + add wiring together),"
      echo "  OR revert the $file change."
      exit 1
    fi
  done
done

exit 0
```

Wired into the `chokepoint-gate` CI job (the lightweight one Task 30 added). Runs on every PR.

**Pattern coverage rationale** (Codex N-25 closure): the script checks BOTH `receiptService.ts` AND `paymentStore.ts` for FOUR forbidden patterns: the direct class import, the singleton accessor, the mutex helper, and the engine.append() invocation signature. Together these catch direct + indirect + helper-mediated wiring paths. A PR can only pass this gate by either (a) removing the marker file (= shipping Pass 2B atomically), or (b) not touching the device-side checkout wiring.

**Pass 2A pre-commit verification** (carry-over from v4 §8.A + N-23 closure):
- Full Fiscal PHPUnit suite green.
- Full POS Vitest suite green.
- PHPStan level 8 green.
- Pint --test green.
- Cross-language drift gate green.
- Source-level guard tests green (grep gate scope per v4 §8.A).
- §14.3 chokepoint gate still PASS.
- **NEW:** `check-pass-2b-pending.sh` exits 0 (marker file exists; receiptService.ts does NOT yet import FiscalEventEngine).

### 8.B — Nf525DataProvider refactor (sub-inventory per N-12 closure)

Pass 2A `Nf525DataProvider` refactor — explicit method signatures:

```php
private function mapSaleReceipt(Receipt $receipt): SaleSection {
    if ($receipt->fiscal_event_id !== null) {
        $event = FiscalEvent::findOrFail($receipt->fiscal_event_id);
        $reader = $this->canonicalReader->forSaleReceipt($event);

        return SaleSection::fromCanonical(
            receipt: $receipt,
            buyer: $reader->buyer(),                                   // BuyerDTO|null
            seller: $reader->seller(),                                  // SellerDTO
            lines: array_map([$this, 'mapLineFromCanonical'], $reader->lineItems()),     // SaleLine[]
            payments: array_map([$this, 'mapPaymentFromCanonical'], $reader->payments()), // SalePayment[]
            vatDetails: array_map([$this, 'mapVatDetailFromCanonical'], $reader->vatBreakdown()), // SaleVatDetail[]
            originalReference: $reader->originalReceiptReference(),     // OriginalReceiptReferenceDTO|null
        );
    }
    // Legacy path (fiscal_event_id IS NULL): existing implementation preserved
    return $this->mapSaleReceiptLegacy($receipt);
}

private function mapLineFromCanonical(LineItemDTO $line): SaleLine {
    return new SaleLine(
        sku: $line->sku,
        productId: $line->productId,
        gtin: $line->gtin,                                             // CANONICAL-ONLY field
        name: $line->name,
        quantity: $line->quantity,
        unitPrice: $line->unitPrice,
        lineSubtotal: $line->lineSubtotal,
        lineVat: $line->lineVat,
        vatRate: $line->vatRate,
        taxCategoryCode: $line->taxCategoryCode,                       // CANONICAL-ONLY field
        lineDiscountAmount: $line->lineDiscountAmount,
        lineDiscountReason: $line->lineDiscountReason,
        nonCollectedSubtype: $line->nonCollectedSubtype,               // CANONICAL-ONLY (IT future)
    );
}

private function mapPaymentFromCanonical(PaymentDTO $payment): SalePayment {
    return new SalePayment(
        methodCode: $payment->methodCode,
        amount: $payment->amount,
        instrumentType: $payment->instrumentType,
        instrumentSerial: $payment->instrumentSerial,
        foreignCurrencyAmount: $payment->foreignCurrencyAmount,        // CANONICAL-ONLY field
        foreignCurrencyCode: $payment->foreignCurrencyCode,            // CANONICAL-ONLY field
    );
}

private function mapVatDetailFromCanonical(VatBreakdownDTO $vat): SaleVatDetail {
    return new SaleVatDetail(
        rate: $vat->rate,
        netAmount: $vat->netAmount,
        vatAmount: $vat->vatAmount,
        grossAmount: $vat->grossAmount,
        taxCategoryCode: $vat->taxCategoryCode,                        // CANONICAL-ONLY field
    );
}

// Legacy methods kept for fiscal_event_id IS NULL path (existing code unchanged):
private function mapSaleReceiptLegacy(Receipt $receipt): SaleSection { ... }
private function mapLine(ReceiptLine $line): SaleLine { ... }         // existing
private function mapPayment(ReceiptPayment $payment): SalePayment { ... }  // existing
private function mapVatDetail(ReceiptVatDetail $vat): SaleVatDetail { ... } // existing
```

`mapVoidedReceipt` + `mapReturnReceipt` follow the same bifurcation pattern.

`CanonicalPayloadReader::forSaleReceipt(FiscalEvent $event): SaleReceiptCanonicalView` returns a typed view object with accessor methods (`buyer()`, `seller()`, `lineItems()`, etc.) — internally parses `$event->payload` JSONB (already verified-parser-derived per Task 19 + 24 R2). DTO classes (`LineItemDTO`, `PaymentDTO`, `VatBreakdownDTO`, `BuyerDTO`, `SellerDTO`, `OriginalReceiptReferenceDTO`) live in `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/` — created in Pass 2A alongside the reader.

Tests in `Nf525ExportTest`:
- Canonical-only `gtin` round-trip — payload carries gtin; pos_receipt_lines has no gtin column; NF525 export emits gtin from canonical.
- Canonical-only `tax_category_code` round-trip — both line-level and breakdown-level.
- Canonical-only `foreign_currency_*` round-trip for split payment.
- Canonical-only `original_receipt_reference` round-trip for refund/void.
- Legacy receipt (fiscal_event_id IS NULL) STILL exports correctly via the legacy path (regression guard).

### 8.C — Phase-2 deferred-task entries

`See v4 §8.C.` Unchanged in v5. Three deferred tasks added to roadmap v2 in Pass 2A: (1) mirror-column audit + drop, (2) per-country tax-number strict validation, (3) ParseFailureResolution operator UX.

### 8.D — Pass 2B scope (refined per N-23 closure)

Pass 2B atomically:
1. Refactors `receiptService.ts` to emit Candidate C-v3 shape via `engine.append()`.
2. Wires `FiscalEventEngine` singleton per Amended A1.
3. Extends `upsertTerminalState` for genesis_seed mirror per Amended A2.
4. Threads `tenantId` + `companyId` through `OfflineReceiptInput` per Amended A3.
5. Deletes legacy chain code per v4 §9.
6. **DELETES** `apps/pos/src/lib/offline/.PASS_2B_PENDING` marker.
7. Migrates device-side tests (Bucket 1 + Bucket 2 + Bucket 3 per v4 §11 Amended A6).
8. Absorbs Task 28 (`/pos/receipts/sync` retirement).

After Pass 2B merges: `check-pass-2b-pending.sh` passes (marker file deleted; `receiptService.ts` now imports `FiscalEventEngine` legitimately).

---

## 9-10. (v4 §9-§10 unchanged)

---

## 11. Amended A1–A6 — final form

`See v4 §11.` All amendments carry to v5 with these v5 refinements:

**Amended A4 — Canonical SALE_RECEIPT payload shape:**
- `seller.tax_number` / `buyer.tax_number`: universal baseline plus Phase 1.5.2 per-country table per §7.
- VAT partition + total arithmetic: per v5 §6 algorithm (replaces v4 §6).
- Scale invariant: per v5 §6.B explicit field table.
- Discount fields: `transaction_discount_amount` non-negative; `transaction_discount_reason` null-iff-zero invariant.

**Amended A5 — Transactional boundary:**
`See v4 §11 Amended A5.` Unchanged in v5. Mutex spec carries verbatim.

All other Amended A1/A2/A3/A6 unchanged.

---

## 12-14. (v4 §12-§14 unchanged)

---

## 15. Scope + risk + effort estimate (v5)

- **Pass 2A**: ~4-5K LOC across ~25+ files (unchanged from v4).
- **Pass 2B**: ~2-3K LOC across ~15+ files (unchanged).
- **Total Pass 2**: ~6-8K LOC across ~40 files.
- **Expected review rounds**: Pass 2A 3-4 (cross-task + Nf525 sub-method refactor); Pass 2B 2-3.
- **Total Pass 2 = 5-7 review rounds.**

---

## 16. Status

- v1, v2, v3, v4: superseded.
- v5 (this doc): locked as the Phase 1 SALE_RECEIPT canonical contract; §7 amended by Phase 1.5.2 to replace the universal-only placeholder with the landed per-country tax-number table.

---

## 17. Remaining items for owner

NONE. v5 closes all surviving Codex findings. No further owner decisions needed before Pass 2A dispatch.

**End of synthesis v5.**
