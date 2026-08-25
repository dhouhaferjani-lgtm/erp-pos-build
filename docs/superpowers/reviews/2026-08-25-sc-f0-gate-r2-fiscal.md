# C-F0 gate r2 — FISCAL lens (adversarial)

| | |
|---|---|
| Lane | C-F0 — confirmed-unposted output is a VAT-free proforma (Session C, document-lifecycle-dimensions) |
| Branch / worktree | `feat/sc-f0-proforma-output` · `.worktrees/sc-f0-proforma-output` |
| Reviewed SHA | `1cd09ee9c` (fix round r1 on top of `2974a6e40`) |
| Base | `0ae906b0e` |
| Date / round | 2026-08-25 · r2 |
| Lens | fiscal-pos-reviewer (adversarial, code-grounded) |
| Prior record | `docs/superpowers/reviews/2026-08-25-sc-f0-gate-r1-fiscal.md` (ACCEPT-WITH-CONDITIONS, merge-blocking YES on condition 1) |
| PG throwaway | `autoerp_test_scf0_gate2` (127.0.0.1:5433) — created and **dropped** |

**VERDICT: spec ✅ + quality ACCEPT-WITH-CONDITIONS — merge-blocking: YES (F-7 + F-8, one fix round).**

r1's blocking condition is closed and proven closed. Conditions 2 and 5 are closed and
proven closed. Condition 3's spec ruling is implemented correctly at the **page** level —
and, in implementing it, the fix round reproduced r1's F-2 failure mode one level down: on a
document carrying a document-level discount the proforma now contradicts itself **within a
single row**. That, plus the fact that the resolver's own load-bearing invariant is unpinned
(a rewrite to the forbidden derivation passes all 24 tests), is the r2 block.

---

## 1. Condition-by-condition re-verification, by execution

### Condition 1 (r1 F-1, BLOCKING) — badge gated · **CLOSED**
`resources/views/documents/components/header.blade.php:53` now reads
`@if($document->status && ! ($isProforma ?? false))` — the badge is **suppressed**, not
relabelled, which is the right call: a lifecycle badge has nothing true to say about a
document whose banner states it has not been entered anywhere.

`ProformaOutputTest::unsealedStatusProvider()` (`:198-206`) drives all four statuses the
predicate admits — `draft`, `confirmed`, `paid, never sealed`, `posted, never sealed` — and
`test_no_unsealed_status_puts_a_lifecycle_word_on_the_proforma` asserts (a) the full token
scan on HTML **and** PDF text, (b) absence of the `status-badge` markup on the rendered page,
(c) that no `DocumentStatus` case's badge text appears as an exact PDF line (exact membership,
not substring — the class correctly notes `238,000 DT` contains `38,000 DT`).

**Revert-probe** — r1's header restored into the lane tree:
```
git restore --source=2974a6e40 --worktree -- .../components/header.blade.php
php vendor/bin/phpunit tests/Feature/Document/ProformaOutputTest.php --filter 'lifecycle_word'
  -> Tests: 4, Assertions: 72, Failures: 4
     draft / confirmed / paid → "a proforma states no lifecycle status: the badge markup itself must be gone"
     posted                   → fails on the /posted/i token scan (r1 F-1 verbatim)
```
Tree restored; `git status --short` empty. The guard is real and the defect it guards is the
one r1 reported.

### Condition 2 (r1 F-3) — CI allowlist · **CLOSED**
`.github/workflows/ci.yml:983` now contains `…|ProformaOutputTest|ProformaTemplateCensusTest)::/`
inside the `backend-test-pgsql --filter`. `php tools/feature-lane-manifest-check.php` →
`tests/Feature lane manifest OK — 1420 Feature classes in 74 groups; … every --filter entry is
anchored and uniquely matched against 1815 test classes across all suites.` — so both entries
are anchored and each matches exactly one class. The manifest note records the addition, the
reason ("the ONLY guards against re-introducing the Code TVA Art. 18 exposure on the first
tenant's invoice printout would run on no CI event at all"), the three precedents and the
remove-on-gate-flip instruction. `classes` stays 86 and the total stays 1420 — the fix round
added test **methods**, not classes, so no further ceiling movement is owed.

### Condition 3 / r1 F-2 — gross proforma lines · **CLOSED AT PAGE LEVEL** (see F-7 for row level)
`ProformaGrossAmountResolver` (new, `Application/Services`), injected into `DocumentPdfService`
(`:32`) and exposed as two typed closures (`:204-205`) that the templates call only on the
proforma arm.

**Rule 19 audit, line by line — clean:**
- bcmath on strings only: `bcmul`, `bcadd`, `bcdiv`, `bccomp`. No `(float)`, no `parseFloat`,
  no `number_format`. PHPStan level 8 with the precision rules → `[OK] No errors` on all five
  changed/new PHP files.
- Scale from the **document's** currency, not a bare no-arg call:
  `$this->scaleResolver->getScaleSafe($currency, 3)` (`:66`, `:88`), and `$currency` is
  `$document->currency ?? $company->currency` (`DocumentPdfService.php:173`). Rule-20 trap avoided.
- Intermediates at `scale + 1`, rate fraction at `scale + 4` (`:105`), **rounded once at the
  boundary** with `CurrencyScale::bcround(..., $scale)` — half-away-from-zero
  (`CurrencyScale.php:172-197`), deliberately not `bcformat()`, which truncates and would drift
  the sum off the estimated total. Correct choice, correctly explained.
- Unit price from the **RATE**, never `tax_amount ÷ qty` (`:78-94`) — the right rule, but see **F-8**.
- The hardcoded `2` in `bccomp($rate, '0', 2)` carries a `precision-ok:` pragma naming
  `document_lines.tax_rate` as `decimal(5,2)` percent — correct, percent is not currency-scaled.
- Model casts confirm the string contract: `DocumentLine` casts `unit_price`, `line_total`,
  `tax_amount`, `discount_amount` at `decimal:3` and `tax_rate` at `decimal:2` (`DocumentLine.php:143-152`).

**Tamper-probe A** — `lineAmount()` returns `$net`:
```
1) test_proforma_line_figures_are_gross_and_sum_to_the_estimated_total
   every printed line figure must be tax-inclusive
2) test_no_figure_on_a_proforma_yields_the_vat_by_subtraction
   the HTML must not print line 1 net amount — its gross counterpart is printed instead
Tests: 2, Assertions: 7, Failures: 2
```
Both the reconciliation assertion and the exact-string "no VAT derivable" assertion are
non-vacuous. The latter is written the honest way — exact membership over
`itemsTableRightCells()` + `totalsTableCells()` + `pdfLines()`, never `assertStringNotContains`,
precisely because `238,000 DT` contains `38,000 DT`.

**Tamper-probe B — the posted arm never calls the closures.** Replaced both closures in
`prepareData()` with `throw new RuntimeException('TAMPER')`:
```
--filter 'snapshot|still_shows_the_tax_breakdown'  -> OK (3 tests, 10 assertions)   # never called
--filter 'gross_and_sum'                          -> RuntimeException: TAMPER      # always called
```
Proven in both directions. The posted snapshot pins (HTML whitespace-collapsed + `<style>`
strict + PDF text byte-identical) are green on `1cd09ee9c` on sqlite and PG, so posted output
remains byte-identical to the base capture — now across two fix rounds.

### Condition 5 (r1 F-4) — census fails closed · **CLOSED**
`TAX_EMISSION` widened (`ProformaTemplateCensusTest.php:54`) to also match `__('TVA')`,
`\bTTC\b`, `\bHT\b` (anchored, case-sensitive), `stamp_duty_amount`, `line_tax_amount`,
`document_tax_details`, `taxBreakdown`, `tax_details`. `str_contains($contents,'isProforma')`
replaced by `consultsTheFlagInCode()` (`:205-224`), which strips Blade/PHP comments and then
looks for the flag only inside evaluated constructs. The class carries its own falsification
test (`test_a_comment_does_not_satisfy_the_gate_check`, `:254-278`) including the explicit
fail-closed case for a mid-line directive.

**Tamper-probe on a real file** — gate deleted from `components/totals.blade.php`
(`@if($isProforma ?? false)` → `@if(false)`) with a Blade comment mentioning the flag left in
place, which is exactly what r1's `str_contains` would have accepted:
```
{{-- gate lives on $isProforma, see ProformaOutputPolicy --}}
@if(false)
-> these blade files put a tax mention on the page without consulting $isProforma: components/totals.blade.php
   Tests: 1, Assertions: 1, Failures: 1
```
The census now fails closed on the ordinary shape of a bad merge. Tree restored.

### r1 F-5 (Blade scoping comment) — **CLOSED**
`layouts/document.blade.php:351-361` now states the correct mechanism, cites
`CompilesLayouts.php:24`, and gives the real reason for the `?? false` asymmetry (six of the
eight templates never resolve the flag). Accurate.

### Conditions 4 and 6 — unchanged, still owed
Out of lane by construction. C-4 = the FE follow-up (R-1/R-2). C-6 = the OQ-14 owner ruling
(owner column still blank; the lane continues to ship the sheet's fail-safe default, which is
what an unruled question requires).

### Test execution — sqlite AND PostgreSQL, by path, never the full suite

| test | sqlite | pgsql |
|---|---|---|
| `ProformaOutputTest.php` | OK (24, 296) | OK (24, 296) |
| `ProformaTemplateCensusTest.php` | OK (7, 24) | OK (7, 24) — see **F-10** |
| `PostingMarkerPrintTest.php` | OK (10, 20) | OK (10, 20) |
| `Modules/Document/DocumentPdfSellerTaxIdTest.php` | OK (3, 5) | OK (3, 5) |
| `Modules/Document/DocumentPdfRenderTest.php` | OK (6, 20) | OK (6, 20) |
| `Compliance/FacturXWorkOrderInvoiceTest.php` | OK (2, 7) | — |

Guards: pint `{"result":"pass"}` · PHPStan level 8 `[OK] No errors` (5 files) · deptrac
`TOTAL 183 183 · RESULT: PASS` · manifest `OK — 1420 Feature classes in 74 groups`.

---

## 2. Findings

### [Important — MERGE-BLOCKING] F-7 · `ProformaGrossAmountResolver.php:69-94` + `components/line_items.blade.php:64,68`
**The unit price and the line amount are derived from two different bases, so on a document
carrying a document-level discount they disagree on the same row — even at quantity 1.**

Probe (real render, TN fixture, `discount_amount = 10.000`, `stamp_duty_amount = 1.000`, line
taxes prorated post-discount exactly as `TaxCalculationService.php:171-183` computes them):
```
[R8] items right cells: 119,000 DT | 236,480 DT | 59,500 DT | 59,120 DT
```
Row 2 prints `Qty 1,00 · Unit Price 59,500 DT · Amount 59,120 DT`. `unitPrice()` is rate-derived
(`50 × 1.19`); `lineAmount()` takes the persisted, post-document-discount `tax_amount`
(`50 + 9.120`). The two bases cannot agree once a document-level discount exists, because the
tax engine prorates that discount into every rate bucket
(`TaxCalculationService.php:171-183`, "a document-level discount must reduce the base EVERY rate
bucket is taxed on") while the rate-derived unit price knows nothing about it.

The POSTED invoice does not have this problem: it prints net figures and an explicit `Discount`
row, so the arithmetic closes. This lane deletes that row. The population is real, not
theoretical — `POSAccountChargeDraftService.php:62,164` writes a document-level
`discount_amount` on POS account-charge invoices.

Not a VAT leak (the `0.380` gap is the tax on that row's prorated discount share; neither the
net nor the VAT is recoverable from it). But it is **r1's F-2 defect reproduced one level
down**: the customer-facing document contradicts itself on its own face, and the fix round's
whole purpose was to stop that. Nothing pins it — the r2 fixture sets `discount_amount = '0.000'`
(`ProformaOutputTest.php:498`).

**Required change:** derive both printed figures from one basis — print
`unitPrice = lineAmount ÷ qty` (this also preserves the resolver's own rule, since it divides
the gross **amount**, never the tax), or otherwise make the row close. Add a fixture with
`discount_amount > 0` and assert `qty × printed unit price == printed line amount`.

### [Important — MERGE-BLOCKING] F-8 · `ProformaGrossAmountResolver.php:78-94` (test coverage)
**The invariant the resolver's own docblock calls load-bearing is not pinned.** It states:
"Derived from the RATE, never from `tax_amount`: … dividing it by the quantity would print a
unit price the customer cannot multiply back."

Tamper-probe — `unitPrice()` rewritten to `net + bcdiv(tax_amount, quantity)` whenever
`tax_amount` is present:
```
php vendor/bin/phpunit tests/Feature/Document/ProformaOutputTest.php
  -> OK (24 tests, 296 assertions)
```
All 24 tests pass against the forbidden derivation. The fixture is degenerate for this property:
line 1 has `tax_amount = null` (forcing the rate path) and line 2 has `quantity = 1.0000` (where
the two derivations coincide by construction).

**Required change:** add a line with `quantity > 1` **and** a persisted `tax_amount` that is not
`rate × line_total`, and assert the printed unit price is the rate-derived figure. Same fixture
work as F-7, one round.

### [Minor] F-9 · `ProformaGrossAmountResolver.php:41-43` (comment accuracy)
The docblock justifies `getScaleSafe()` with "this runs inside `DocumentEmailService::queue()`
too, where there is no CompanyContext to resolve a default from". Read
`DocumentEmailService.php:106`: `generateContent()` is called **in-request**, before the mailable
is handed to the mailer, so the resolver never executes in a worker. `getScaleSafe()` remains the
correct choice (strictly safer, rule-19 compliant) but the stated reason is wrong. Related and
unchanged by this lane: `DocumentPdfService::scale():35` is a bare no-arg `getScale()`, reachable
only from the `formatMoney` fallback at `:286` and — by the same reading — never from a worker,
so it is not a live rule-20 violation. Worth one accurate sentence rather than a plausible one.

### [Minor] F-10 · `ProformaTemplateCensusTest` — one unreproduced PostgreSQL error
The first PG pass of this gate reported `Tests: 7, Assertions: 23, Errors: 1` for this class.
Six subsequent PG runs were green (two of them against a freshly created database, four against
a migrated one), and the message was not captured — the throwaway had already been dropped when
the failure was noticed. I could not attribute it and I will not guess. It matters because
condition 2 has just put both classes into the **live** `backend-test-pgsql` job: an intermittent
failure there turns CI red for the whole repository, not only for this parked lane. Run each
class ≥5× on PG before merge and attach the outputs; if it recurs, capture the message.

### Observations (recorded, not findings)
- `formatMoney()` still casts to float (`DocumentPdfService.php:255-266`, r1 residual R-6), so the
  resolver's carefully-bcmath'd string is float-formatted at the very last step. Pre-existing, applies
  to every amount on every document PDF, untouched here — but it is now the last step of a
  deliberately float-free computation, which is worth knowing when R-6 is finally closed.
- On a line carrying a **line-level** discount, `qty × unit_price ≠ line_total` on the proforma —
  but that is equally true of the posted invoice today, so it is consistent rather than new. F-7 is
  about the **document-level** discount, which is new.

---

## 3. Ruling on R-8 (stamp duty / document-level discount)

**Adopt the proposal in principle — but compute the reconciling row, do not assemble it from the
two columns.**

**R-8 is a legibility defect, not a fiscal exposure — proven, not asserted.** Probe on a document
with `stamp_duty_amount = 1.000` and `discount_amount = 10.000`:
```
sum of printed gross lines = 295.600 ; documents.total = 286.600
residual (total - sum)     = -9.000  ; stamp - discount = -9.000
page prints 45.600 (VAT total)?      no
page prints 46.600 (VAT + stamp)?    no
page prints 240.000 (discounted net)? no
page prints 250.000 (net subtotal)?   no
```
The residual is **exactly `stamp_duty_amount − discount_amount`**, which follows from the code:
`DocumentTotalsCalculator.php:37-56` writes `total = taxResult->total`, and
`TaxCalculationService.php:334,352-372` computes `total = (Σ line net − document discount) + line
tax + stamp`, with the discount prorated into every rate bucket before the tax is taken. A reader
holding {236.480, 59.120, 286.600} cannot form the VAT (45.600) or the net (240.000 / 250.000)
without already knowing two of {discount, stamp, rate}. The Art. 18 invariant is untouched by R-8
in either direction.

**The proposed default is compliant.** A document-level discount is not a tax mention. The TN
timbre is a *droit de timbre* under the Code des droits d'enregistrement et de timbre, not a
mention of TVA under Art. 18 — the liability Art. 18 creates attaches to VAT mentioned on an issued
invoice, and naming a separate duty is not that. So printing both as their own labelled rows is
allowed, and it is what makes the page readable to the customer who has to pay the amount.

**Three conditions on adopting it**, in decreasing importance:
1. **Compute the residual, don't assemble it.** The reconciling row must be
   `total − Σ printed gross line amounts`, not `stamp_duty_amount − discount_amount`. They coincide
   only when every line carries a persisted `tax_amount`; the resolver's NULL-`tax_amount` fallback
   (`:71-73`) taxes the **pre**-discount line net, so on a legacy row with a document discount the
   assembled figure leaves a silent remainder. A derived row reconciles by construction on every
   shape, including the ones nobody thought of.
2. **Duty words, never tax words, in all three locales** — en `Stamp duty`, fr `Droit de timbre`,
   ar chosen deliberately (the token scan is Latin-script and will not catch an Arabic tax word, so
   the `ar` string is a human decision, not a guarded one). Extend `ProformaOutputTest`'s token scan
   and its exact-figure "no VAT derivable" assertion over a fixture with `stamp > 0` **and**
   `discount > 0`, in all three locales.
3. **Fix F-7 first, or the rows still will not close.** Adding a document-level `Discount` row makes
   the *page* reconcile while every *row* on it still shows `qty × unit price ≠ amount`. R-8 and F-7
   are the same defect at two scales and want one fix round.

---

## 4. No new fiscal exposure; r1 residual dispositions stand

- **No new exposure.** The fix round removes figures from the page (the badge) and replaces net
  figures with gross ones; it adds no VAT label, no rate, no seal/hash/chain/QR wording, and no new
  persisted write. The Factur-X gate (`DocumentPdfService.php:79`) and `ProformaOutputPolicy` are
  untouched, so gate r1's RULING 1 (predicate = the seal) and RULING 2 (Factur-X gate in scope)
  carry over unchanged and are re-confirmed by the green `PostingMarkerPrintTest` (10/10) and the
  four Factur-X classes.
- **R-1** (web renders VAT for a confirmed-unposted invoice; `sales.json` marker copy stale) —
  unchanged, still the largest hole, still a FE lane. The web/PDF contradiction stated in r1 stands
  verbatim.
- **R-2** (email covering note calls a proforma an "invoice", prints `Total`, `(float)` cast) —
  unchanged.
- **R-3** (request-locale vs company-locale split), **R-4** (Arabic unshaped in PDF, no `dir="rtl"`
  anywhere under `resources/views/documents/`), **R-5** (`posting_marker` included by hand),
  **R-6** (`formatMoney` float cast), **R-7** (`PostingMarkerPrintTest:196` PHPStan `return.type`)
  — all unchanged and untouched by the fix round.
- **R-8** — ruled above. New in r2, correctly self-reported by the lane.

---

## 5. Manifest note

Unchanged from r1 in its numbers: `groups.Document.classes` **86**, `gated_ceiling` **1175**
(dev tip still 84 / 1173, so the +2 union holds at merge). The fix round edits only the group
**note**, to record the two `--filter` allowlist additions, their justification, the three
precedents and the remove-on-gate-flip instruction. `feature-lane-manifest-check.php` green,
including its assertion that every `--filter` entry is anchored and uniquely matched.

---

## 6. Conditions

1. **[MERGE-BLOCKING]** Close F-7: derive the proforma's printed unit price and line amount from a
   single basis so every row closes (`unitPrice = lineAmount ÷ qty` is the coherent form and keeps
   the resolver's "never from `tax_amount ÷ qty`" rule intact), and pin it with a fixture carrying
   `discount_amount > 0`.
2. **[MERGE-BLOCKING]** Close F-8 in the same round: add a line with `quantity > 1` **and** a
   persisted `tax_amount` that is not `rate × line_total`, so the rate-derivation invariant is
   pinned. Today a rewrite to the forbidden derivation passes all 24 tests.
3. Run `ProformaOutputTest` and `ProformaTemplateCensusTest` ≥5× each on PostgreSQL before merge and
   attach the outputs (F-10) — they now gate every CI event, so an intermittent failure is
   everyone's problem.
4. Implement R-8 per the ruling in §3 — derived reconciling row, duty words in three locales,
   token scan extended over a `stamp > 0 ∧ discount > 0` fixture — after, or with, condition 1.
5. Correct the `getScaleSafe()` rationale in `ProformaGrossAmountResolver.php:41-43` (F-9);
   comment-only, may ride the same round.
6. Carried from r1, unchanged: the FE follow-up lane for R-1/R-2, and the OQ-14 owner ruling.

**VERDICT: spec ✅ + quality ACCEPT-WITH-CONDITIONS — merge-blocking: YES (conditions 1 and 2).**
