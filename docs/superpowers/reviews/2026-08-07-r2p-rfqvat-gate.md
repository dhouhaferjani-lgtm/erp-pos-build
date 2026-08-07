# Gate record — `702f57974` RFQ-awarded PO zero-VAT (NARROW)

- **Branch:** `fix/r2p-purchasing` · **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.fix-r2p-purchasing`
- **Diff base:** `a1952aa23` · **Commit under gate:** `702f57974` ONLY (siblings `e9971cf03`, `11a3363ff` gated separately)
- **Spec:** `docs/superpowers/tickets/2026-08-03-w4-purchasing-inventory-defects.md` §143-188 (defect #3 / MTP-RFQ-06)
- **Reviewer:** treasury-reviewer (adversarial, code-grounded). Gate only — no code modified.
- **Date:** 2026-08-07

## VERDICT

**spec ❌ (partial) + quality CHANGES-REQUESTED**

The chosen fix does close the *line-rate* half of defect #3 (verified red-cause + green), but the
awarded PO's **header** (`tax_amount` / `total` / `balance_due`) is still the RFQ's untaxed header,
so the ticket's own stated acceptance ("update MTP-RFQ-06 to `tax_amount 23.750` / `total 148.750`")
is met only *after* confirm — and `balance_due` is now permanently wrong. The mandated tripwire
update was not made, and one new deptrac layer violation is introduced.

## What was verified (evidence)

### 1. Resolver-chain parity — PASS at the rate level, GAP at the totals level
- Converter injects the **same class**: `PurchaseQuoteRequestToPurchaseOrderConverter.php:26`
  (`DocumentLineTaxResolver`), identical to `DraftPurchaseOrderService.php:29` and to
  `PurchaseOrderController.php:75` / `:391`. No divergent copy of the chain was written.
- Precedence is single-sourced in `DocumentLineTaxResolver::resolveTaxRate()`
  (`DocumentLineTaxResolver.php:41-77`): explicit line rate → line `tax_configuration_id` →
  product `default_tax_configuration_id` → product `tax_rate` → company `default_tax_configuration_id`
  → company `default_tax_rate` → `'0.00'`.
- Scale discipline: the only new formatting is `CurrencyScale::bcformatStrict($rate, 2)`
  (`DocumentLineTaxResolver.php:111`) — a **percent**, correctly NOT currency-scaled (rule 19).
  No `getScale()` with no argument is introduced anywhere in the diff; the converter performs no
  money arithmetic at all.
- **Gap:** `DraftPurchaseOrderService` also computes the line `tax_amount` and the document
  `subtotal/tax/total` at draft time (`:41-42`, `:60-62`, `:161-164`, `:200-204`). The converter
  does neither. See I-1 / M-3.

### 2. Explicit-rate case — PASS, no inversion
`DocumentLineTaxResolver.php:43-45` short-circuits on a numeric line `tax_rate` **before** any
default lookup, and the converter feeds the source line's own value in at
`PurchaseQuoteRequestToPurchaseOrderConverter.php:186` (`'tax_rate' => $line->tax_rate`).
If the RFQ contract ever gains a real `tax_rate`, the explicit value wins. No unconditional overwrite.

### 3. Zero-rate legitimacy — PASS
- `hasNumericValue()` (`DocumentLineTaxResolver.php:101-104`) is a *presence* test, not a truthiness
  test: `'0.00'` returns `true`, `null` returns `false`. So an explicit line 0% and a product whose
  own `tax_rate` is `'0.00'` (`:61-63`) are both returned as `'0.00'` and are NOT bumped to the
  company default. Configured-zero is distinguished from missing.
- A 0% *configuration* is likewise honoured: `rateFromConfigurationId()` returns
  `formatRate('0') === '0.00'`, which is non-null, so the chain stops (`:47-59`, `:93`).
  `TaxType` has only `PERCENTAGE` / `FIXED_AMOUNT` (`app/Modules/Taxation/Domain/Enums/TaxType.php:9-10`),
  so an exemption is necessarily a PERCENTAGE row at rate 0 — the `isPercentage()` guard at `:89`
  cannot silently drop it.
- Same class of bug as 2026-08-02: downstream, `TaxCalculationService.php:133-136` also only skips a
  **NULL** rate (`$rateStr === ''`), keeping an explicit 0% in the declaration's zero-rate bracket
  (comment `:110-115`), and `:238-268` honours an unconfigured explicit rate instead of zeroing it.
- Recorded (pre-existing, shared with the hand-authored path, NOT introduced here): a product whose
  `default_tax_configuration_id` points at a `FIXED_AMOUNT` row falls through (`:89`), and the
  category-level `categories.default_tax_rate`
  (`database/migrations/tenant/2025_12_30_104000_add_tax_fields_to_categories.php:14`) is never
  consulted by the resolver.

### 4. Money math at confirm — PASS for what confirm computes; header divergence is the problem
- `PurchaseOrderService::confirmAndAllocateCosts()` (`:86-95`) delegates to
  `TaxCalculationService::calculateDocumentTaxes()`, which resolves scale from the **document's own
  currency** (`TaxCalculationService.php:43-52`, explicit-currency, context-safe) and is bcmath
  throughout (`:140-167`, `:200-221`, `:301-302`). No float, no `number_format`.
- The pinned numbers reproduce on live PG: TND ⇒ scale 3, 125.000 × 19% ⇒ `23.750`, total `148.750`.
- **But confirm writes only `tax_amount` and `total` (`PurchaseOrderService.php:89-92`)** — see I-2.
- Red-cause proven structurally without mutating the tree: `PurchaseQuoteRequestService::replaceLines()`
  (`:200-221`) never sets `tax_rate` on an RFQ line, and the pre-fix converter line was
  `'tax_rate' => $line->tax_rate` (diff), so the awarded rate was NULL by construction.

### 5. Blast radius — all green (run by the reviewer, live PG, `phpunit-pgsql.xml`, by path)
| Suite | Result |
|---|---|
| `tests/Feature/Procurement/PurchaseQuoteRequestAwardTest.php` | 10 passed (37 assertions) |
| `PurchaseQuoteRequestHttpTest` + `Invariants` + `Migration` + `Service` + `Validation` + `AutoGeneratedPurchaseOrderExposureTest` | 24 passed (172 assertions) |
| `tests/Unit/Document/PurchaseOrderServiceTest.php`, `Conversion/DocumentConverterRegistryTest.php`, `tests/Unit/Modules/Document/DocumentConversionFieldsCarryTest.php` | 27 passed (67 assertions) |
| PHPStan (changed file) | `[OK] No errors` |
| Deptrac | **103 violations, +1 attributable to this commit** (see I-4) |

Other converters in the same registry, checked for the same null-copy shape (report only, not fixed):
`DeliveryNoteToInvoiceConverter.php:209`, `SalesOrderToDeliveryNoteConverter.php:305/327/355/443/485/533`,
`SalesOrderToInvoiceConverter.php:574/596/622` and the shared
`Concerns/CopiesDocumentData.php:141` all copy `$line->tax_rate` verbatim — but their *sources*
(quote / sales order / delivery note) are authored through controllers that inject the same
`DocumentLineTaxResolver` (`QuoteController.php:58`, `SalesOrderController.php:58`,
`InvoiceController.php:73`), so the rate is never NULL there. `InvoiceToCreditNoteConverter` and
`PurchaseOrderToGoodsReceiptConverter` do not copy `tax_rate` at all. The RFQ path was the only
source contract that structurally cannot carry a rate. **The shape is not replicated elsewhere.**

## FINDINGS

### [IMPORTANT] I-1 — the awarded **draft** PO still shows zero VAT; only confirm fixes it
`app/Modules/Document/Domain/Services/Conversion/Converters/PurchaseQuoteRequestToPurchaseOrderConverter.php:104-108`
copies `subtotal` / `discount_amount` / `tax_amount` / `total` verbatim from the RFQ, and the RFQ's
`tax_amount` is hard-`0` from creation (`PurchaseQuoteRequestService.php:56`; `replaceLines():222-224`
rewrites `subtotal`/`total`/`balance_due` and never touches `tax_amount`). The commit stamps a real
rate on the line but never recomputes the header, so the awarded draft reads
`subtotal 125.000 / tax_amount 0.000 / total 125.000` while its own line carries `19.00`.

*Why it matters:* both sibling PO-creation paths compute the taxed header **at draft**
(`PurchaseOrderController.php:394-409, 429-432`; `DraftPurchaseOrderService.php:41-42, 60-62`), so the
buyer approving an RFQ-awarded draft sees a materially different committed amount than the buyer
approving a hand-authored or replenishment draft — a 19% understatement on the approval screen.
The ticket's acceptance text ("update MTP-RFQ-06 to `tax_amount 23.750` / `total 148.750`") is not
satisfied for the draft read. The commit message's parity claim ("RFQ-sourced and
replenishment-sourced POs now default consistently") is true only of the line rate.

*Fix:* the exact remedy already exists in-repo — `Concerns/CopiesDocumentData::recalculateTotals()`
(`:289-329`), which the sibling `QuoteToSalesOrderConverter.php:145` calls for precisely this reason
(its comment at `:139-142`: "document-level subtotal/tax/total MUST be recomputed afterwards or the
sales order would carry stale source totals"). Call an equivalent after the line loop
(`converter:139`), or compute the header from `$resolvedTaxRates` inline.

### [IMPORTANT] I-2 — `balance_due` now contradicts `total` on every RFQ-awarded PO
`converter:108` sets `'balance_due' => $source->total` (125.000). `PurchaseOrderService.php:89-92`
updates only `tax_amount` and `total` at confirm. Result after confirm: `total 148.750`,
`balance_due 125.000` — surfaced to the API by `DocumentController.php:387` and `:452`.

*Why it matters:* **this mismatch is introduced by this commit.** Pre-fix the two agreed (both
125.000, merely untaxed); post-fix the document is internally inconsistent, and it is
path-specific — `PurchaseOrderController::store()` never writes `balance_due` at all, so
hand-authored POs do not carry a stale value. `AgedPayablesService.php:234` recomputes its own
figure in memory, so the report is unaffected, but the document read is wrong.

*Fix:* same call as I-1 — `recalculateTotals()` writes `'balance_due' => $total` (`:328`). Or drop
the `balance_due` copy at `converter:108` (a draft PO is not yet payable; the RFQ itself stores `0`
at `PurchaseQuoteRequestService.php:224`).

### [IMPORTANT] I-3 — the ticket-mandated tripwire update was not made; the e2e suite is now red
`apps/web/e2e/money-campaign/purchasing-rfq.spec.ts:258` still asserts
`draftLines[0].tax_rate ... toBeNull()`, `:267` asserts confirmed `tax_amount === '0.000'`, `:268`
asserts confirmed `total === '125.000'`. The ticket's "When fixed" clause (§185-187) explicitly
requires rewriting MTP-RFQ-06. `git log a1952aa23..HEAD -- apps/web/e2e/money-campaign/purchasing-rfq.spec.ts`
is empty — the spec is untouched on this branch.

*Why it matters:* a tripwire left asserting the defect turns green→red noise into a broken gate, and
whoever fixes it will discover I-1 the hard way — note that `:260` (`draft.tax_amount === '0.000'`)
would **still pass** today, which is exactly the divergence in I-1.

*Fix:* update `:258` to `'19.00'`, `:267` to `'23.750'`, `:268` to `'148.750'`, and (after I-1)
`:260` to `'23.750'`; drop the "TRIPWIRE" framing and the STATE-LEFT-BEHIND annotation text at
`:278-283` that still quotes `tax_amount 0.000`.

### [IMPORTANT] I-4 — new hexagonal layer violation: Domain → Application
`converter:8` / `:26` make `App\Modules\Document\Domain\...\PurchaseQuoteRequestToPurchaseOrderConverter`
(layer `ModuleDomain`) depend on `App\Modules\Document\Application\Services\DocumentLineTaxResolver`
(layer `ModuleApplication`). `deptrac.yaml:92-94` allows `ModuleDomain` only `SharedDomain` +
contracts. Reviewer-run deptrac reports 103 violations, and the JSON report contains exactly one
entry for this file — i.e. **+1 new violation** on a ratchet that is already above baseline
(MEMORY: 97 on dev vs baseline 61; this bites at the dev→main PR).

*Why it matters:* CLAUDE rule 4 (hexagonal) and rule 6. Every other consumer of this resolver is
Application or Presentation (`DraftPurchaseOrderService`, four controllers) — the converter is the
only Domain-tier consumer.

*Fix:* either move the resolution to the Application caller (`PurchaseQuoteRequestAwardService`) and
pass resolved rates into `convert()` via `$options`, or expose the resolver behind a
`App\Shared\Contracts\*` port. Note the same commit also newly imports the cross-module Eloquent
models `Company` (`:7`) and `Product` (`:17`) into a Document-tier Domain class; deptrac permits
Domain→Domain and the sibling services do the same, so this is noted, not blocking.

### [MINOR] M-1 — the new test pins only the happy path
`tests/Feature/Procurement/PurchaseQuoteRequestAwardTest.php:131-203` is a real behavioural test
(RefreshDatabase `:38`, real models, real services, discriminating: the company is created with no
`default_tax_rate` at `:63-71`, so `'19.00'` can only have come from the product at `:134`). But it
pins none of the hostile cases this fix creates a surface for: (a) an explicit line rate winning over
the default, (b) a legitimately 0%-configured product not being bumped to the company default,
(c) a product-less RFQ line, (d) the draft header / `balance_due` (I-1, I-2 — the test asserts
`$po->subtotal` at `:196` but deliberately not `$po->tax_amount` / `$po->total`).

### [MINOR] M-2 — company lookup drops the tenant filter the sibling keeps
`converter:164` `Company::query()->findOrFail($source->company_id)` vs
`DraftPurchaseOrderService.php:36-39` `->where('tenant_id', ...)->whereKey(...)->firstOrFail()`.
Harmless under db-per-tenant and not flagged by Gate B (`tests/Architecture/TenantScopedFindCallsTest.php`
scans the Application tier and is `@group sweep-progress`), but it is a gratuitous parity divergence
in a file whose whole point is parity.

### [MINOR] M-3 — line-level tax fields left NULL on RFQ-awarded PO lines
The converter sets `tax_rate` only. `DraftPurchaseOrderService::persistLines():200-204` also writes
`tax_amount`, `tax_recoverable`, `recoverable_tax_amount`, `non_recoverable_tax_amount`. At confirm,
`LandedCostService::allocateCostsAndTaxes():189-222` does read the now-populated `$line->tax_rate`
and writes `non_recoverable_tax` — so the ticket's inventory-costing consequence **is** closed — but
`document_lines.tax_amount` / `recoverable_tax_amount` stay NULL on this path where they are
populated on the other two.

## One line to fix before merge
Recompute the awarded PO's header from the resolved line rates (reuse
`CopiesDocumentData::recalculateTotals()`, which also repairs `balance_due`), update MTP-RFQ-06, and
move the resolver call out of the Domain tier.

---

# Fix-round re-verify — `857c7a9c5` (group R)

- **Commit:** `857c7a9c5` "fix(procurement,document): recompute awarded-PO header, move tax resolution out of Domain tier"
- **Scope of this re-verify:** ONLY the findings raised above (I-1, I-2, I-3, I-4, M-1, M-2, M-3). No re-gate of the sibling commits `9762db355` / `c5aa0eb72` that landed alongside.
- **Date:** 2026-08-07 · Reviewer re-ran every check personally on live PG.

## RE-VERDICT

**spec ✅ + quality APPROVED — CLEAR TO MERGE.**

All four IMPORTANT findings and all three MINORs are closed, verified against the code and by
re-running the gates myself. No new defect introduced by the fix round. Six non-blocking residual
notes recorded below.

## R1 — I-1 / I-2 (draft header + `balance_due`) — CLOSED

- The verbatim header copy is gone. `PurchaseQuoteRequestToPurchaseOrderConverter.php` now
  `use CopiesDocumentData;` and calls **`$this->recalculateTotals($purchaseOrder)`** after the line
  loop — the exact remedy the gate prescribed, and the same call
  `QuoteToSalesOrderConverter.php:145` makes. `Concerns/CopiesDocumentData::recalculateTotals():289-329`
  writes `subtotal`, `tax_amount`, `total` **and `balance_due` => `$total`** (`:325-328`), so I-2 is
  fixed at the source rather than papered over.
- Scale discipline in the new converter code is correct and explicit-currency:
  `$source->currency !== '' ? getScale($source->currency) : getScaleSafe(null, 3)` — the same guard
  as `TaxCalculationService::scaleFor():43-52`. **No no-arg `getScale()` on this path.** Line tax is
  `bcmul($lineTotal, bcdiv($taxRate,'100',$scale+4), $scale+4)` truncated by `bcformatStrict` — bcmath
  only, no float. The `bccomp($taxRate,'0',4)` non-zero check is annotated `precision-ok` and is a
  percent test, not money.
- Draft/confirm agreement checked arithmetically, not assumed: `recalculateTotals():310` uses
  `bcdiv(rate,'100',4)` — exact for any scale-2 rate — then `bcmul(..., $scale)`; the converter uses
  `scale+4` then truncates to `$scale`. Both are truncations of the same exact product to the same
  scale, so line `tax_amount` and header `tax_amount` are byte-identical. Confirmed on the pinned
  case: `125.000 × 19% ⇒ 23.750`, `total 148.750`.
- **Test evidence (reviewer-run, live PG):**
  `test_award_carries_a_default_tax_rate_when_the_rfq_line_has_none` now asserts the DRAFT header
  `tax_amount '23.750'`, `total '148.750'`, `balance_due '148.750'`, and post-confirm
  `assertSame($confirmed->total, $confirmed->balance_due)` — the precise place my gate proved the
  mismatch was introduced. Green.

## R2 — I-4 (deptrac / layer) — CLOSED

- **Where the resolver call now lives:** the Application tier —
  `app/Modules/Procurement/Application/PurchaseQuoteRequestAwardService.php`, constructor-injected
  `DocumentLineTaxResolver`, new private `resolveTaxRates()`; rates are passed to the converter as
  `['tax_rates' => ...]` keyed by **RFQ line id** (not positional index — more robust than the old
  index alignment). The Domain-tier converter no longer imports `DocumentLineTaxResolver`, `Company`
  or `Product` at all; it reads `$options['tax_rates'][$line->id] ?? $line->tax_rate` behind an
  `is_array()` guard. The docblock even declines an `@see` tag to avoid a docblock-only Domain→
  Application reference.
- **Reviewer-run deptrac: 102 violations** (was 103 with the defect, and 102 is the pre-`702f57974`
  baseline my first-round run established). Machine-checked the JSON report: **0 entries for
  `PurchaseQuoteRequestToPurchaseOrderConverter` and 0 for `PurchaseQuoteRequestAwardService`.**
  Net zero new violations — claim confirmed exactly.
- **Semantics survived the move — verified, not assumed.** The chain is still the single
  `DocumentLineTaxResolver::resolveTaxRate():41-77`; nothing was reimplemented. Explicit-rate-wins is
  still `:43-45`, configured-zero is still the `hasNumericValue()` presence test `:101-104`. Both are
  now *pinned by tests* (see R4/M-1) rather than left to inspection.
- **No bypass path:** the only production caller converting RFQ→PurchaseOrder is
  `PurchaseQuoteRequestAwardService.php:59` (route `POST /purchase-quote-requests/{id}/convert-to-po`
  → `Procurement/Presentation/routes.php:70` → `PurchaseQuoteRequestController::convertToPo():185` →
  the award service). Swept every `converterRegistry->convert(` call site:
  `DocumentConversionController.php:33/68/101/213/270/340` — `:340` is PurchaseOrder→PurchaseOrder
  (goods receipt), none is RFQ→PO. So no route can reach the converter without `tax_rates`.
- PHPStan L8 on both changed production files: `[OK] No errors`.

## R3 — I-3 (MTP-RFQ-06 flip) — CLOSED

`apps/web/e2e/money-campaign/purchasing-rfq.spec.ts` — title changed from "TRIPWIRE … VAT is
silently missing" to "FIXED … carries the resolved default tax rate". Figures cross-checked against
the backend test and the ticket:
- draft `lines[0].tax_rate` → `'19.00'` (was `toBeNull()`), `draft.subtotal` `'125.000'`;
- **draft-header taxed assertion is present** — `draft.tax_amount` `'23.750'` with the comment "already
  correct at draft", `draft.total` `'148.750'`, `draft.balance_due` `'148.750'`;
- confirmed `tax_amount` `'23.750'`, `total` `'148.750'`, plus `confirmed.balance_due === confirmed.total`;
- the `STATE-LEFT-BEHIND` annotation text was updated from `tax_amount 0.000, total 125.000` to
  `tax_amount 23.750, total 148.750`.
All figures agree with the ticket's §185-187 acceptance and with the backend assertions. (The e2e
itself needs a live stack; not executed here.)

## R4 — minors — ALL CLOSED

- **M-2 (tenant scoping):** `PurchaseQuoteRequestAwardService::resolveTaxRates()` uses
  `Company::query()->where('tenant_id', $tenantId)->whereKey($companyId)->firstOrFail()` — mirrors
  `DraftPurchaseOrderService.php:36-39` exactly. The `Product` query keeps both `tenant_id` and
  `company_id` filters.
- **M-3 (line tax fields):** the converter now writes `tax_amount`, `tax_recoverable => true`,
  `recoverable_tax_amount`, `non_recoverable_tax_amount => bcformatStrict('0',$scale)` on every
  created PO line — field-for-field what `DraftPurchaseOrderService::persistLines():200-204` writes.
  Pinned by four new assertions in the award test.
- **M-1 (hostile cases):** three new tests added, and each is *discriminating*, which I checked
  rather than took on trust:
  - `test_award_prefers_an_explicit_line_tax_rate_over_the_product_default` — product `19.00`, line
    stamped `7.00` post-`recordResponse` (correctly, because `replaceLines()` delete-and-recreates);
    asserts `7.00`.
  - `test_award_does_not_bump_a_configured_zero_product_rate_to_the_company_default` — sets the
    **company default to a NONZERO `19.00`** so a presence-vs-truthiness bug would resolve `19.00`;
    asserts `'0.00'`, `tax_amount '0.000'`, `total '30.000'`.
  - `test_award_resolves_company_default_tax_rate_for_a_product_less_rfq_line` — company default
    `13.00` deliberately ≠ the orphaned product's `19.00`; asserts `13.00`.

## Reviewer-run gate results (fix round)

| Check | Result |
|---|---|
| `tests/Feature/Procurement/PurchaseQuoteRequestAwardTest.php` | **13 passed (55 assertions)** — incl. the 3 new hostile cases |
| RFQ suite (Http/Invariants/Migration/Service/Validation) + `AutoGeneratedPurchaseOrderExposureTest` + `PurchaseOrderServiceTest` + `DocumentConverterRegistryTest` + `DocumentConversionFieldsCarryTest` | **51 passed (239 assertions)** |
| PHPStan L8, both changed production files | `[OK] No errors` |
| Deptrac | **102** (baseline), 0 entries for either touched file |

## Residual notes (non-blocking, recorded so a future change is deliberate)

- **N-1:** by adopting the trait the converter inherits `CopiesDocumentData::scale():39-42`, which
  calls a **no-arg `getScale()`** — the rule-19 trap. Verified harmless today: `grep '$this->scale()'`
  over the trait returns nothing (dead helper) and the converter computes its own explicit-currency
  scale. Flagged only as a latent trap for whoever edits this class next.
- **N-2:** `'tax_recoverable' => true` is hardcoded on the draft line, which is optimistic for a
  `NON_REGISTERED` company (`TaxCalculationService::determineRecoverability():447-450`). It is
  byte-identical to `DraftPurchaseOrderService::persistLines():202` — i.e. exactly the parity M-3
  asked for — and confirm's `LandedCostService::allocateCostsAndTaxes():189-222` writes the real
  `non_recoverable_tax`. Shared recorded behaviour, not a new defect.
- **N-3:** `recalculateTotals()` bases line tax on the stored `line_total`, whereas confirm's
  `TaxCalculationService` bases it on `DocumentLine::calculateTotal()` (net of line discount). RFQ
  lines never carry discounts (`PurchaseQuoteRequestService::replaceLines():200-220` sets none), so
  they agree today. Pre-existing property of the trait, shared with `QuoteToSalesOrderConverter`.
- **N-4:** the converter keeps its own `dispatchConversionEvent()`, which shadows the trait's
  `:245`. The class declaration wins (no fatal, behaviour unchanged, tests green) but a reader could
  reasonably assume the trait version runs.
- **N-5:** `recalculateTotals():318` folds `calculateDocumentTaxes()->documentTaxTotal` (DocumentTotal-level
  configs, e.g. a TN droit de timbre) into the draft header. Correct and confirm-consistent, but the
  test DB seeds no `TaxConfiguration` rows, so this leg is **not exercised** — unverified for a live
  TN tenant.
- **N-6:** the e2e comment at the flipped `tax_rate` assertion attributes `19.00` to "the
  demo-pharmacy-tn company default"; I **cannot verify** that tenant's seed without a live stack.

## Fix-round bottom line
Nothing left to fix before merge.
