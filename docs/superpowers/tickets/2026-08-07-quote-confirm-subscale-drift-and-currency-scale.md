# Quote precision residuals — sub-scale draft/confirm drift, company-vs-document scale, FE/BE discount divergence

**Source:** R2-B treasury/precision gate findings I-2, I-3, m-3 (2026-08-07), all **measured**, all **pre-existing**, none introduced by R2-B.
**Status:** OPEN. (i) folds into an existing ticket; (ii) wants its own small lane; (iii) is a note.

---

## (i) Residual draft-vs-confirm drift of 1 millime at sub-scale shapes

**Measured** (TND): payload `3 × 0.333 @ 33.33%` + `7 × 1.007 − 0.001` +
`1.0001 × 0.001` → draft header `7.716 / 1.465 / 9.181`, confirm total
`9.180`. **A 1-millime move at confirm.**

Three mechanisms compound:

1. The controller computes each line at the currency `scale`
   (`QuoteController.php:225`, via `DocumentLine::computeLineTotal`).
2. `TaxCalculationService::calculateSubtotal()` computes at **scale+1 then
   rounds** — `TaxCalculationService.php:331-334`:
   `CurrencyScale::bcformat($line->calculateTotal($scale + 1), $scale)`.
3. `confirm()` **never rewrites `subtotal`** (`QuoteController.php:545-548`
   writes only `tax_amount` and `total`), so the draft-time and confirm-time
   conventions end up stored side by side in one row.

**R2-B shrank this drift from 0.397 → 0.001** (it removed the discount-blind
component; what remains is pure rounding-convention mismatch). The lane's
draft==confirm identity test cannot see it because its payload is
round-numbered by construction.

**This is the same class as — and should be folded into —
`2026-08-03-f2f3-regate-carryovers.md` §R1** ("pre-existing path-symmetric
draft-vs-confirm rounding divergence"), which already prescribes the fix
direction: *unify draft total computation on the SAME rounding pipeline as
TaxCalculationService STEP 1, then draft==confirm holds universally.* Shared
with the invoice and sales-order controllers — not quote-specific.
Certification-relevant: totals must be reproducible.

---

## (ii) Cross-currency quote computed at COMPANY scale, recomputed downstream at DOCUMENT scale

**Measured:** a TND quote created under an EUR/FR company is truncated to
2 dp at create, then `confirm()` recomputes at 3 dp — `9.160 → 9.179`.

- `QuoteController::scale()` (`:61-64`) calls **no-arg** `getScale()`, which
  resolves from `CompanyContext` — the **company's** country/currency
  (`CurrencyScaleResolver.php:35-70`).
- But `currency` is **client-settable per document**
  (`CreateDocumentRequest.php:84`).
- Every downstream consumer resolves `$document->currency` instead.

So the document's own currency never reaches the arithmetic that creates it.
Rule 19 permits a no-arg `getScale()` inside an HTTP controller (context is
bound), so this is not a rule violation — it is a *wrong-input* bug that the
rule does not catch.

**Fix:** resolve the scale from the resolved document currency rather than the
company, i.e. `getScale($validated['currency'] ?? $company->currency)`. The
identical one-line change applies to `InvoiceController` and
`SalesOrderController`, which share the pattern — hence **its own small lane**
rather than a drive-by edit here.

---

## (iii) FE/BE divergence: `discount_percent = "0"` together with `discount_amount > 0`

- **Frontend** `apps/web/src/features/documents/components/DocumentLineEditor.tsx:55-57`
  branches on `discountPercent !== undefined && !== null && !== ''`. The
  string `"0"` **passes** that guard, so the FE computes a zero percent
  discount and **silently drops the flat `discount_amount`**.
- **Backend** `DocumentLine.php:290-295` branches on
  `bccomp($discountPercent, '0', 4) !== 0`, so `"0"` correctly falls through
  and the flat amount **is applied**.

Same payload, two different line totals. **Reachable by API clients only**
today — the FE's own editor never emits that combination — which is why this
is a note rather than a lane. Pre-existing on invoice and sales order; R2-B
extends the same (correct) backend behaviour to quotes, so the divergence now
also covers quotes.

**Fix direction:** align the FE guard on numeric-zero semantics
(`bccomp(discountPercent,'0') !== 0`) so both tiers implement the one W-3
Option-A rule.

---

## Cross-references

- `2026-08-03-f2f3-regate-carryovers.md` §R1 — the parent rounding-unification
  ticket for (i).
- `2026-08-07-r2b-legacy-discount-blind-quotes.md` — the R2-B write-path fix
  that shrank (i) and whose confirm-never-writes-`subtotal` behaviour is the
  third mechanism in it.
