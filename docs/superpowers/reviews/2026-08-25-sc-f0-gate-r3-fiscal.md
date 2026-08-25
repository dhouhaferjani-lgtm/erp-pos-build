# C-F0 gate r3 — FISCAL lens (adversarial)

| | |
|---|---|
| Lane | C-F0 — confirmed-unposted output is a VAT-free proforma (Session C, document-lifecycle-dimensions) |
| Branch / worktree | `feat/sc-f0-proforma-output` · `.worktrees/sc-f0-proforma-output` |
| Reviewed SHA | `cbf3d6af5` (fix round r2 on `1cd09ee9c`) |
| Base | `0ae906b0e` · red commit `394aecc0d` |
| Date / round | 2026-08-25 · r3 |
| Lens | fiscal-pos-reviewer (adversarial, code-grounded) |
| Prior records | `…-sc-f0-gate-r1-fiscal.md` (blocking: F-1) · `…-sc-f0-gate-r2-fiscal.md` (blocking: F-7, F-8) |
| PG throwaway | `autoerp_test_scf0_gate3` (127.0.0.1:5433) — created and **dropped** |

**VERDICT: spec ✅ + quality ACCEPT-WITH-CONDITIONS — merge-blocking: NO.**

Both r2 blockers are closed, and closed in the way that matters: I re-ran the four tamper
probes myself rather than reading the handback's table, and every forbidden derivation goes
red against the new fixture. The R-8 ruling is implemented as ruled — derived, not assembled —
and the derivation is falsifiable. Two Important findings remain (a documented bound that is
measurably wrong, and a duplicated credit-note block with no coverage); neither is a fiscal
exposure and neither blocks.

---

## 1. Condition-by-condition re-verification, by execution

### r2 condition 1 / F-7 [was BLOCKING] — one basis per row · **CLOSED**
`ProformaGrossAmountResolver::unitPrice()` (`:114-134`) is now
`bcround(bcdiv(lineAmount, quantity, scale + 4), scale)`, with a zero-quantity short-circuit to
`lineAmount()` guarded by a `precision-ok:` pragma naming `document_lines.quantity` as
`decimal(N,4)`. One basis: the gross line amount is built from the persisted post-discount
facts, and the unit price is that amount divided by the quantity — so the persisted
`tax_amount` is still never divided on its own.

`test_every_proforma_row_closes_on_its_own_face` asserts `unit × qty == amount` at currency
scale **with no tolerance**, over `discountedUnpostedInvoice()`
(`discount_amount = 10.000`, `stamp_duty_amount = 1.000`, per-line persisted post-discount
taxes, and a line-level discount on row 3). I re-derived the arithmetic independently:

| row | qty | net | persisted tax | printed unit | printed amount | `unit × qty` |
|---|---|---|---|---|---|---|
| 1 | 2 | 200.000 | 36.480 (rate × net would be 38.000) | 118.240 | 236.480 | 236.480 ✓ |
| 2 | 1 | 50.000 | 9.120 | 59.120 | 59.120 | 59.120 ✓ |
| 3 (line discount 10.000) | 4 | 90.000 | 16.416 | 26.604 | 106.416 | 106.416 ✓ |

Σ gross = 402.016; `402.016 + 1.000 − 10.000 = 393.016` = stored `total`. Checks out.

**Rule 19 re-audit — clean.** bcmath on strings throughout (`bcdiv`, `bcmul`, `bcadd`, `bcsub`,
`bccomp`); no float anywhere; scale from the document's currency via `getScaleSafe($currency, 3)`
(`:116`, `:145`) with `$currency = $document->currency ?? $company->currency`
(`DocumentPdfService.php:173`); division intermediate at `scale + 4`, multiplication at
`scale + 1`, rounded **once** at the boundary with `CurrencyScale::bcround()` (half-away-from-zero,
`CurrencyScale.php:172-197`), never `bcformat()`. PHPStan level 8 with the precision rules over
all six changed/new files → `[OK] No errors`.

### r2 condition 2 / F-8 [was BLOCKING] — the derivation invariant is falsifiable · **CLOSED**
I applied the four tampers myself, ran the full class after each, and reverted (tree verified
clean after every one):

| my tamper | result |
|---|---|
| **A** · `unitPrice()` rate-derived — the exact r1 code | `Tests: 32, Failures: 1` — `test_every_proforma_row_closes_on_its_own_face` |
| **B** · `unitPrice()` = `unit_price + tax_amount ÷ qty` | `Tests: 32, Failures: 1` — same test |
| **C** · `unitPrice()` = `tax_amount ÷ qty` | `Tests: 32, Failures: 4` — row closure, gross-lines, both no-VAT-derivable tests |
| **D** · reconciling row assembled from the stored `discount_amount` instead of derived | `Tests: 32, Failures: 2` — `…_is_derived_and_not_assembled_from_the_stored_discount` and `…_runs_the_other_way_is_not_called_a_discount` |

Row 1 (qty 2 **and** persisted tax ≠ rate × net) kills A; row 3 (line discount, so
`line_total ≠ unit_price × qty`) kills B and C. The r2 hole is shut: at r2 a rewrite to a
forbidden derivation left all 24 tests green; at r3 every one of them is red. Baseline before
tampering: `OK (32 tests, 395 assertions)`.

### r2 condition 3 / F-10 — repeat PG runs · **CLOSED with a disclosure (R-9)**
Handback delivers 14 attached outputs (ProformaOutputTest ×6 incl. a fresh database,
ProformaTemplateCensusTest ×6 + ×2 fresh), all green. I did not take them on trust — my own r3
PG sweep on `autoerp_test_scf0_gate3`: `ProformaOutputTest` ×3 → `OK (32, 395)` each,
`ProformaTemplateCensusTest` ×3 → `OK (7, 24)` each, plus `PostingMarkerPrintTest OK (10, 20)`,
`DocumentPdfSellerTaxIdTest OK (3, 5)`, `DocumentPdfRenderTest OK (6, 20)`. Combined with r2's
runs, the two lane classes are now green on PG in every observation anyone has made. The
disclosure is ruled below as **R-9**.

### r2 condition 4 / R-8 — derived reconciling row, duty words · **CLOSED as ruled**
`ProformaGrossAmountResolver::totals()` (`:141-171`) → `ProformaTotals` DTO. `stampDuty` is the
stored statutory figure; the residual is `total − (Σ printed gross lines + stamp)` and is signed
into `discount` (reduces) or `surcharge` (increases). The blades render and compute nothing
(`components/totals.blade.php:45-70`, `templates/credit_note.blade.php:64-84`).

The **derived-not-assembled** condition — the first and most important of my r2 ruling — is
implemented and pinned by `legacyTaxRowUnpostedInvoice()`: a NULL-`tax_amount` row under a
document-level discount, where the fallback taxes the pre-discount net (38.000) while the stored
`line_tax_amount` was taken on the discounted base (36.100). Derived gives 11.900 and the page
closes; assembled would give 10.000 and leave a silent 1.900 in front of the customer. Tamper D
above proves the pin is real.

**Labels — audited in all three locales, programmatically**, against the Latin-script forbidden
tokens *and* against Arabic tax words (`ضريب`, `أداء`, `القيمة المضافة`, `جباي`):

| key | en | fr | ar | audit |
|---|---|---|---|---|
| `stamp_duty` | Stamp duty | Droit de timbre | معلوم الطابع | clean ×3 |
| `discount` | Discount | Remise | تخفيض | clean ×3 |
| `adjustment` | Adjustment | Ajustement | تعديل | clean ×3 |

The Arabic reasoning holds and is the right call: `معلوم الطابع` is "stamp fee/duty" and
deliberately omits `جبائي` (fiscal) and `ضريبة` (tax). The `lang/ar` comment is explicit that the
token scan is Latin-script and therefore cannot guard the Arabic strings — stating the limit of
the guard instead of implying coverage is exactly right. `adjustment` for a positive residual is
correct: calling an increase a discount would be a false statement on a customer document.

Substantively, the ruling's compliance basis is unchanged and re-affirmed: a document-level
discount is not a tax mention, and the TN timbre is a *droit de timbre* under the Code des droits
d'enregistrement et de timbre — Art. 18 attaches liability to VAT mentioned on an issued invoice
and to nothing else. `test_the_duty_and_discount_rows_do_not_reveal_the_vat` re-checks, by exact
membership, that the two new rows hand back none of {62.016, 340.000, 330.000, 200.000, 100.000,
50.000, 90.000, 25.000, 36.480, 9.120, 16.416}.

### r2 condition 5 / F-9 — the `getScaleSafe()` rationale · **CLOSED**
`ProformaGrossAmountResolver.php:43-56` now states that the resolver does **not** run in a
worker, and justifies the choice on its own merits (entity currency in hand; correct for a
document denominated in something other than the company currency; no acquired dependency on
request state). I re-verified the premise: `DocumentEmailService::send():48` and `queue():106`
both call `generateContent()` in-request, before the mailable reaches the mailer. Accurate now.

### r2 condition 6 — FE lane (R-1/R-2) and the OQ-14 owner ruling
Orchestrator-owned, untouched, still owed. Unchanged from r1/r2.

### Posted pins — re-proven byte-identical, two ways
1. `git diff --name-only 394aecc0d..cbf3d6af5 -- apps/api/tests/Fixtures/proforma` → **0 files**.
   The snapshots have not been touched since they were captured on the base commit, now across
   three fix rounds.
2. **Isolation tamper (mine):** both closures **and** `proformaTotals` replaced with `throw`.
   `--filter 'snapshot|still_shows_the_tax_breakdown'` → `OK (3 tests, 10 assertions)`; a proforma
   test → `RuntimeException: TAMPER totals`. The posted arm provably reaches no proforma code at
   all, so "posted output unchanged" is a structural property, not a passing assertion.

### Full execution table

| test | sqlite | pgsql (`autoerp_test_scf0_gate3`) |
|---|---|---|
| `ProformaOutputTest.php` | OK (32, 395) | OK (32, 395) ×3 |
| `ProformaTemplateCensusTest.php` | OK (7, 24) | OK (7, 24) ×3 |
| `PostingMarkerPrintTest.php` | OK (10, 20) | OK (10, 20) |
| `Modules/Document/DocumentPdfSellerTaxIdTest.php` | OK (3, 5) | OK (3, 5) |
| `Modules/Document/DocumentPdfRenderTest.php` | OK (6, 20) | OK (6, 20) |
| `Compliance/FacturXWorkOrderInvoiceTest.php` | OK (2, 7) | — |

Guards: pint `{"result":"pass"}` · PHPStan level 8 `[OK] No errors` (6 files) · deptrac
`RESULT: PASS — no boundary regression` · manifest `OK — 1420 Feature classes in 74 groups;
… every --filter entry is anchored and uniquely matched`. Ceilings unchanged (`Document` 86,
`gated_ceiling` 1175 — `ProformaTotals` is a DTO, not a Feature class). Migration: none.

---

## 2. Findings

### [Important] F-11 · `ProformaGrossAmountResolver.php:107-113` + handback R-10 — the stated bound is measurably wrong, and the behaviour is unpinned
The docblock and R-10 both claim that where the quantity does not divide the amount,
`printed unit × qty` differs from the printed amount "by **less than half a currency unit per
line item**". Measured, on the real resolver:

```
qty=3.0000     net unit 100.000 -> unit 119.000  amount   357.000  unit*qty   357.000  drift  0.000
qty=7.0000     net unit  10.000 -> unit  11.900  amount    83.300  unit*qty    83.300  drift  0.000
qty=1000.0000  net unit   0.333 -> unit   0.396  amount   396.270  unit*qty   396.000  drift -0.270
qty=10000.0000 net unit   0.333 -> unit   0.396  amount  3962.700  unit*qty  3960.000  drift -2.700
```
The true bound is `qty × 0.5 × 10^-scale` — **linear in quantity**, not constant. At 10 000 units
it is 2.700 DT, five times the claimed ceiling and plainly visible. The posted invoice does not
have this: its stored net unit multiplies exactly (`0.333 × 10000 = 3330.000`), so the drift is
newly introduced by deriving the unit from a rounded quotient.

Not a fiscal exposure — the printed **amount** is authoritative, the totals box sums the amounts,
and I probed that the page still closes (`items 119,000 | 357,000`, `totals 357,000`). But a
docblock that reassures where it should characterise is how the next reader gets surprised in
front of a customer. **Required change:** restate the bound as `qty × 0.5 × 10^-scale`, and pin a
bulk non-dividing row asserting the drift exists, is bounded by that expression, and that the
totals box still reconciles. See the R-10 ruling for why the scale itself should not change.

### [Important] F-12 · `templates/credit_note.blade.php:64-84` — the R-8 rows are duplicated and have zero coverage
The credit note re-implements the three conditional rows of `components/totals.blade.php:45-70`
in its own totals block. Every new R-8 test builds an **Invoice** —
`discountedUnpostedInvoice()` (`:721`), `surchargedUnpostedInvoice()` (`:812`),
`legacyTaxRowUnpostedInvoice()` (`:790`) — and `renderHtml()` selects the credit-note template
only for `DocumentType::CreditNote`, so the duplicated block is exercised by nothing. TN credit
notes do carry a timbre: `CreditNoteService.php:113` writes `stamp_duty_amount` from
`$taxResult->documentTaxTotal`.

I probed it, and **today it is correct** — which is why this is a coverage finding and not a
defect:
```
[CN] items cells : 118,240 DT | 236,480 DT
[CN] totals cells: Stamp duty | 1,000 DT | Discount | -10,000 DT | Estimated total | 227,480 DT
[CN] 236.480 + 1.000 - 10.000 = 227.480 vs stored total 227.480
[CN] /VAT/i 0 · /\btax/i 0 · /TVA/i 0 · /posted/i 0
```
The handback itself says the block "must stay in step with the component" and nothing enforces
it; the next edit to the shared component will silently not reach the credit note. **Required
change:** one credit-note-typed case over the discounted shape (and, ideally, the same for the
surcharge sign).

### [Minor] F-13 · `ProformaOutputTest::discountedUnpostedInvoice()` — the fixture's line taxes are not the engine's proration of its own discount
`36.480 + 9.120 + 16.416 = 62.016` implies a taxed base of 326.400, i.e. 340.000 reduced by 4%,
whereas the fixture's `discount_amount` is 10.000 (2.94%). The fixture is internally consistent
(`340.000 − 10.000 + 62.016 + 1.000 = 393.016`) and every assertion is over what is printed, so
no assertion is weakened — but the docblock presents the numbers as the tax engine's proration
(`TaxCalculationService:171-183`) and a reader who checks will not be able to reconcile them.
Either derive the taxes from the stated discount, or say plainly that the figures were chosen for
exact divisibility.

### [Minor] F-14 · handback §Item 2 tamper table — not reproducible as written
My independent tamper B produced 1 failure where the handback reports 3, and my D produced 2
where it reports 3. Both still go red, so the conclusion is unaffected; the counts differ because
the tamper bodies differ (my B short-circuits only when `tax_amount` is non-null; my D assembles
from `discount_amount` alone rather than `stamp − discount`). Recorded so the table is read as
evidence-of-redness and not as a reproducible fixture.

### Observations (recorded, not findings)
- `formatMoney()` still casts to float at the final display step (r1 residual R-6), so the
  resolver's deliberately float-free strings are float-formatted on the way to the page.
  Pre-existing and repo-wide; now the last step of a longer bcmath chain than before.
- The totals box prints no gross subtotal row — the reader sums the line amounts themselves, as
  on any invoice. Deliberate and correct: a printed gross subtotal would add nothing and one more
  figure to reason about.

---

## 3. Rulings

### R-9 — one unreproduced PG error in `DocumentPdfRenderTest`: **LEDGER ticket + environment note. NOT a merge blocker.**
Verified rather than assumed:
- `grep -c "DocumentPdfRenderTest" .github/workflows/ci.yml` → **0**. It is in no `--filter`
  allowlist.
- It lives in `tests/Feature/Modules/Document/`, group `Modules`, whose lane is
  `feature-lane-tenancy` — parked behind `vars.SELF_HOSTED_RUNNER_READY` (`ci.yml:1867-1872`),
  exactly like `feature-lane-documents` (`:1749-1755`).

So the class that flaked runs on **no CI event today** and cannot redden anything. The two classes
this lane did put on the live `backend-test-pgsql` job are green in every observation: 14/14 in
the handback's sweep, and 8/8 more in my own r2 and r3 PG runs. The intermittency has now been
seen once each on two unrelated dompdf-rendering classes and never reproduced across 8+ retries,
which points at the local PG/dompdf environment rather than at either class — not proof, but the
weight of it. It does become a real gate the day the self-hosted runner flag flips, so it belongs
on the LEDGER with a standing instruction to capture the message next time, not on this merge.

### R-10 — unit price at currency scale: **ACCEPT. Do NOT widen the scale.** (with F-11's correction mandatory)
- Widening cannot buy the property. `unit × qty == amount` is unattainable at *any* finite scale
  for a non-terminating quotient (`236.480 ÷ 3 = 78.8266…`), so a wider scale converts "sometimes
  visibly off on bulk lines" into "rarely off by a millime" while printing an off-convention price
  on a customer document.
- Rule 19's display contract renders money at the currency's own scale (`formatCurrency`); a
  scale-5 unit price would need a bespoke formatter and would be the only figure on the page not
  at currency scale.
- The property that actually matters already holds and is probed: the printed **amount** is
  authoritative, the estimated total sums the amounts, and the totals box closes.
- Therefore the scale stays and **the claim changes** — F-11 is the condition attached to this
  ruling, not an optional tidy-up. An accurate `qty × 0.5 × 10^-scale` bound plus a pinned bulk row
  is what makes this an accepted, characterised trade-off rather than an unexamined one.

---

## 4. No new fiscal exposure; earlier dispositions stand

The r2 fix round removes nothing from the guard set and adds two labelled rows whose words were
audited in three locales. Gate r1's **RULING 1** (predicate = the seal) and **RULING 2**
(Factur-X gate in scope) carry over untouched — `ProformaOutputPolicy` and
`DocumentPdfService.php:79` are unchanged since `2974a6e40`, `PostingMarkerPrintTest` is 10/10 on
sqlite and PG, and the Factur-X classes are green. Gate r2's closures-and-`totals()` isolation
proof extends the "posted output byte-identical" property to this round.

Residuals **R-1** (web renders VAT for a confirmed-unposted invoice; `sales.json` marker copy
stale — still the largest hole and still a FE lane), **R-2** (email covering note calls a proforma
an invoice, prints `Total`, `(float)` cast), **R-3** (request-locale vs company-locale split),
**R-4** (Arabic unshaped in PDF; no `dir="rtl"` under `resources/views/documents/`), **R-5**
(`posting_marker` included by hand), **R-6** (`formatMoney` float cast), **R-7**
(`PostingMarkerPrintTest:196` PHPStan `return.type`) — all unchanged and untouched. **R-8** is
closed as ruled. **R-9** and **R-10** are ruled above.

---

## 5. Conditions

1. Correct the R-10 bound (F-11): restate it as `qty × 0.5 × 10^-scale` in
   `ProformaGrossAmountResolver.php:107-113` and in the handback, and pin a bulk non-dividing row
   asserting the drift is bounded by that expression and that the totals box still reconciles.
   This is the condition attached to the R-10 acceptance, not optional.
2. Cover the credit-note R-8 rows (F-12): one CN-typed case over the discounted shape, and
   ideally the surcharge sign, so the duplicated block in `credit_note.blade.php:64-84` cannot
   drift from `components/totals.blade.php`.
3. Reconcile or re-label the discounted fixture's line taxes (F-13) — comment-only is acceptable.
4. Open a LEDGER row for R-9 with the instruction to capture the failure message next time; note
   that it becomes a live gate when `SELF_HOSTED_RUNNER_READY` flips.
5. Carried from r1/r2, unchanged: the FE follow-up lane for R-1/R-2, and the OQ-14 owner ruling.

None of the five blocks the merge.

**VERDICT: spec ✅ + quality ACCEPT-WITH-CONDITIONS — merge-blocking: NO.**
