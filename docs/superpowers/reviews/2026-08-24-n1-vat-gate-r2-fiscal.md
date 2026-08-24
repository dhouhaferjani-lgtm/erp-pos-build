# N-1 VAT resolution — adversarial gate r2 (fiscal-POS lens)

Lane: `fix/campaign-n1-vat-resolution` — worktree `.worktrees/n1-vat-resolution`, HEAD `51250b4cd`.
Fix-round commits reviewed: `074dbbec0` (finding 1 BLOCKER), `1dc3ce465` (findings 3/4/5, MIGRATION-BEARING),
`51250b4cd` (handback). Diff base `9c1fe61bf`.
Reviewer: fiscal-pos-reviewer. Nothing merged, nothing left modified — every tamper was restored and the
worktree verified clean (`git status --porcelain` empty, HEAD still `51250b4cd`).

## VERDICT

**spec ❌ + quality CHANGES-REQUESTED.**

The r1 BLOCKER is genuinely closed and I proved it end-to-end on the real N-1 shape. But the fix round
introduced **two new merge-blocking defects of its own**, both proven by execution, not by reading:

1. The migration's new second half is **document-TYPE-blind**. It rewrites and re-totals draft CREDIT NOTES
   (which exist only to mirror a SEALED invoice), draft SUPPLIER INVOICES / SUPPLIER CREDIT NOTES / EXPENSES,
   and return/delivery notes. That is correcting a sealed fiscal fact *through the document that reverses it*
   — exactly the class the migration's own docblock swears it never touches.
2. The lane trips the repo's own architecture gate: `php tools/deptrac-ratchet.php` → **`RESULT: FAIL`,
   exit 1**, `ModuleDomain on ModuleApplication` 54 → **55**, at `DraftPersistenceService.php:53`. This repo
   already has a written, in-code refusal to do this exact injection.

---

## What I verified GREEN, by execution

### (1) The r1 blocker is closed — on the REAL N-1 shape, not just the handback's fixture

Throwaway probe class (since deleted; tree verified clean), `POST /api/v1/documents/auto-save`, persisted
`document_lines.tax_rate` read from the DB:

```
PROBE A  stale product (products.tax_rate='19.00', default_tax_configuration_id=TVA_7),
         payload = tax_configuration_id only, NO tax_rate            -> ["7.00"]   PASS
PROBE B  product with NO configuration + rate 19.00, line carries TVA_7 -> ["7.00"] PASS
PROBE D  product belonging to another company in the tenant           -> ["19.00"] (company default; expected)
```
`AutoSaveDraftLineTaxResolutionTest` 5/5 green on sqlite. Red-proof: commenting out
`DraftPersistenceService.php:79` (`$data = $this->resolveLineTaxRates(...)`) → **3 failed / 2 passed**
(`'7.00'→'0.00'`, `'13.00'→'0.00'`, `'19.00'→'0.00'`). The probe discriminates.

The country-scope 422 holds: removing the `lines.*.tax_configuration_id` key from
`AutoSaveDraftRequest.php:232` → `test_autosave_refuses_a_tax_configuration_from_another_country`
**Expected 422, received 200**. Reverted.

### (2) The migration's SEAL guards are real — proven with a MIXED fixture on PostgreSQL

One product on TVA_7 with `tax_rate='19.00'`, one line at 19.00 on each of twelve documents, throwaway DB
`autoerp_test_n1g2` on `127.0.0.1:5433` (created and dropped):

```
GATE: ... status=ok repaired=1 doc_lines=8 docs_retotalled=8

draft quote            line 7.00   total 119.000 -> 107.000   REWRITTEN  (intended)
confirmed quote        line 7.00   total 119.000 -> 107.000   REWRITTEN  (intended)
SEALED invoice         line 19.00  total unchanged            UNTOUCHED
posted invoice         line 19.00  total unchanged            UNTOUCHED
cancelled quote        line 19.00  total unchanged            UNTOUCHED
VOIDED fiscal          line 19.00  total unchanged            UNTOUCHED
```
Second run: `status=ok repaired=0 doc_lines=0 docs_retotalled=0` — **idempotent, confirmed by execution**.
Savepoint isolation is pinned by the lane's own `test_a_failure_is_reported_as_failed_and_never_thrown`
(drops `documents.fiscal_hash`, asserts `status=FAILED` **and** that the product half survived).
`BackfillProductsTaxRateN1MigrationTest` **14/14 on PostgreSQL** and **14/14 on sqlite**.

No float touches a rate: the SQL compares/copies `decimal(5,2)` inside the DB; the re-total runs through
`DocumentTotalsCalculator::recalculate()` → `TaxCalculationService`, which is `bcmath`-only and resolves scale
from the document's OWN currency (`TaxCalculationService.php:45-53`) — context-free, correct for
`tenants:migrate` (rule 20). `retotal()` (`…_n1.php:441-444`) mirrors that guard. **No CompanyContext is
required anywhere on the migration path — verified by reading, and the PG run is the proof.**

### (3) The 5th constructor argument changed nothing for callers — verified

Only two hand-wired construction sites exist repo-wide and both were updated:
`tests/Unit/Modules/Document/DraftPersistenceServiceTest.php:92`, `…/DraftLineEventV2Test.php:101`.
The single production consumer is `DraftController.php:41` (constructor-injected, container-resolved);
`T2EventsV2DualDispatchTest.php:330` uses `app(...)`. There is **no service-provider binding**
for `DraftPersistenceService`, and `DocumentLineTaxResolver` is dependency-free, so auto-resolution is safe.
`ProductTaxRateDerivationTest` 9/9, `BackfillProductsTaxRateN1MigrationTest` 14/14, phpstan level 8 on both
changed `app/` files **[OK] No errors**, `pint --test` on all four changed PHP files **pass**.

### (4) The campaign-tenant census claim is HONEST — re-run read-only by me

`tenant01a03028-9470-70e6-83ca-cdc354f17cf1` on 5433:
- product drift = **2** (`LAIT-INF400` 19.00→13.00, `SERU-PHY20` 19.00→7.00) ✔
- case-(b) refusals = **0** ✔
- in-scope unposted document lines = **7 across 4 documents** — `QT-2026-0002` (1), `QT-2026-0003` (2),
  `QT-2026-0004` confirmed (2), `SO-2026-0001` (2) ✔
- lines matching the drift but **out of reach** of the guards = **0** ✔
- `pos_receipt_lines` for affected products = **0** — nothing wrong is sealed in that chain ✔

I also swept all ten local tenant DBs: only the campaign tenant has in-scope lines, and all of them are
`quote`/`sales_order`. **Locally, finding 1 below does not bite today** — it is a fleet-wide latent defect in
an unattended `tenants:migrate` that auto-runs on push to `origin/dev`.

### (5) r1-test re-tamper

`ProductTaxRateDerivationTest::test_create_refuses_a_configuration_that_states_no_line_item_percentage`
(the new finding-5 test) discriminates the CONTROLLER refusal, not the FormRequest:
`CreateProductRequest.php:206-209` does **not** scope `applies_to`, so a `DOCUMENT_TOTAL` id passes
validation. Neutering `ProductController.php:1147` (`if ($rate === null && false)`) → **Expected 422 but
received 201**, 1 failed / 8 passed. Reverted (byte-exact restore from a scratch copy).

---

## Findings

### 1. [CRITICAL] The migration's second half rewrites DRAFT CREDIT NOTES — it corrects a sealed invoice through its own reversal
`…_backfill_products_tax_rate_from_tax_configuration_n1.php:168` (ids SQL), `:377` (PG UPDATE), `:402`
(sqlite UPDATE): the predicate is `d.status IN ('draft','confirmed') AND d.fiscal_status='DRAFT' AND
d.fiscal_hash IS NULL`. **There is no `d.type` filter.**

`CreditNoteService` creates every credit note as `'status' => DocumentStatus::Draft`,
`'fiscal_status' => FiscalStatus::Draft`, no `fiscal_hash`
(`CreditNoteService.php:873`, `:1007`, `:1188`) and copies the ORIGINAL SEALED INVOICE's line verbatim —
`'product_id' => $invoiceLine->product_id` (`:1068`) and `'tax_rate' => $invoiceLine->tax_rate` (`:1075`).
A credit note therefore sits in exactly the state the migration hunts for, and its rate DISAGREES with the
product's configuration **by design** — that is the whole point of a reversal.

Proven on PostgreSQL with the mixed fixture above:

```
DRAFT CREDIT NOTE           line 19.00 -> 7.00    header 119.000 -> 107.000   *** REWRITTEN ***
DRAFT SUPPLIER INVOICE      line 19.00 -> 7.00    header 119.000 -> 107.000   *** REWRITTEN ***
DRAFT SUPPLIER CREDIT NOTE  line 19.00 -> 7.00    header 119.000 -> 107.000   *** REWRITTEN ***
DRAFT EXPENSE               line 19.00 -> 7.00    header 119.000 -> 107.000   *** REWRITTEN ***
DRAFT RETURN NOTE           line 19.00 -> 7.00    header 119.000 -> 107.000   *** REWRITTEN ***
DRAFT DELIVERY NOTE         line 19.00 -> 7.00    header 119.000 -> 107.000   *** REWRITTEN ***
```

*Why it matters (three separate fiscal breakages):*
- **Credit note.** The invoice sealed 19 % into the chain; the avoir now credits 7 %. Confirming it mints a
  wrong-VAT `avoir` AFTER the fix shipped, the customer is under-credited, and the invoice↔credit-note
  reconciliation (`CreditNoteService::remainingCreditHeadroom()` `:148-165`, which sums prior credit notes
  against the invoice) silently drifts. The migration's own docblock (`:125-132`, `:278-288`) claims sealed
  documents are "untouchable … correcting one is a credit note, not an UPDATE" — here the credit note itself
  is the thing being UPDATEd.
- **Purchase side.** `CreateSupplierInvoiceService.php:127-128,180` writes `'tax_rate' => $ld['vatRate']` —
  the **supplier's** stated VAT on the vendor's paper invoice — on a Draft/DRAFT document. Overwriting it
  with the tenant's own SALE-side product configuration rewrites deductible input VAT and changes the amount
  payable versus the document the supplier issued. `SupplierInvoiceCommitter` (OCR ingestion) is the same
  shape. `d.type` is the only thing that separates them and it is not consulted.
- **Blast radius is unattended.** This is a tenant migration; pushing to `origin/dev` auto-deploys and runs
  `tenants:migrate` across the fleet. Local DBs happen to be clean (I swept all ten); staging/production are
  not proven clean by anything in the handback.

**Fix (do not ship without it):** make the second half an explicit **ALLOW-LIST of sales-side originating
types**, not a status-only filter — add `AND d.type IN ('quote','sales_order')` (the exact set the finding-3
rationale argues for, and the exact set the campaign census found) to **all three** SQL statements
(`:168`, `:377`, `:402`) and to the docblock census queries (`:100-104`, `:329`). If `invoice` drafts are
wanted, name that decision explicitly. Then add one test per EXCLUDED type asserting UNTOUCHED —
`credit_note`, `supplier_invoice`, `supplier_credit_note`, `expense`, `return_note`, `delivery_note` —
because the current suite has no fixture that is unposted-but-must-not-be-repaired, which is why this passed
14/14.

### 2. [CRITICAL — CI gate red] The lane introduces a new hexagonal violation the repo already refused once
`apps/api/app/Modules/Document/Domain/Services/DraftPersistenceService.php:53` —
`private readonly DocumentLineTaxResolver $lineTaxResolver` puts a `Modules/Document/**Application**` class
into a `Modules/Document/**Domain**` constructor.

Executed in the worktree:

```
php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json
  ModuleDomain on ModuleApplication      54 -> 55   BLOCKER (+1)
  TOTAL                                 182 -> 183
  BLOCKER — new Domain-tier leakage (Domain must not depend on Application/Infrastructure/Presentation)
  RESULT: FAIL — architecture boundary regression.       exit code 1
```
The deptrac JSON names it precisely: *"DraftPersistenceService must not depend on DocumentLineTaxResolver
(ModuleDomain on ModuleApplication)", line 53*. The lane does not touch `deptrac.baseline.json`, so this is
lane-introduced, not inherited. `deptrac.yaml:89-94` marks this category a hard fail, not a ratchet.

Worse, the repo carries a written refusal of this exact injection:
`Domain/Services/Conversion/Converters/PurchaseQuoteRequestToPurchaseOrderConverter.php:86-94` —
*"This Domain-tier converter does NOT resolve tax rates itself (that would inject the Application-tier
DocumentLineTaxResolver into a Domain class — a hexagonal-layer violation). The caller resolves rates …
and passes them in via `$options['tax_rates']` … see `PurchaseQuoteRequestAwardService::resolveTaxRates()` in
the Application tier (deliberately NOT an `@see` docblock tag — this Domain-tier class must not carry even a
docblock-only reference to an Application-tier class)."* The fix round did the thing that comment forbids,
and also added the `use` import (`DraftPersistenceService.php:8`) and four docblock references to it.

**Fix (pick one, then re-run the ratchet to exit 0):**
(a) *Follow the existing precedent.* Resolve in the caller — `DraftController` is Presentation and may
depend on Application — and pass resolved rates into `saveDraft()`, mirroring
`PurchaseQuoteRequestAwardService::resolveTaxRates()`. Smallest, and it is the pattern the codebase already
chose for this exact resolver.
(b) *Move the resolver down a tier.* `DocumentLineTaxResolver` imports only `Company\Domain\Company`,
`Product\Domain\Product`, `Taxation\Domain\Entities\TaxConfiguration` and `Shared\Domain\CurrencyScale`
(`DocumentLineTaxResolver.php:7-11`) — all Domain/Shared — so relocating it to
`Document/Domain/Services/` is deptrac-clean. Costs 6 import updates (`DraftPurchaseOrderService.php:29`,
`QuoteController.php:11`, `SalesOrderController.php:11`, `InvoiceController.php:11`,
`PurchaseOrderController.php:11`, `PurchaseQuoteRequestAwardService.php:8`) plus 2 tests. It is a plain class
move, not an Event, so rule 8 does not apply.
Do **not** baseline it away — the ratchet calls new Domain leakage a BLOCKER by name.

### 3. [IMPORTANT] Re-totalling a CONFIRMED SALES ORDER can strand an existing prepayment
`…_n1.php:377` admits `status='confirmed'`, and `retotal()` (`:425-456`) rewrites `documents.total`
(119.000 → 107.000 in my probe). `PaymentAllocationService::getOpenInvoices()` (`:466-483`) explicitly allows
allocations against **confirmed sales orders** ("Confirmed sales orders (for prepayments)") and selects them
with `whereRaw('total > COALESCE((SELECT SUM(amount) FROM payment_allocations …), 0)')`. Lowering `total`
under an existing allocation can make the document over-allocated and silently drop it out of that query;
`PaymentAllocationService.php:241` also flips `status` to `Paid` off the same comparison.
The campaign tenant's confirmed row is a QUOTE (not allocatable) and `SO-2026-0001` is a draft, so this does
not bite there — but it is fleet-wide.
**Fix:** either exclude documents that have any `payment_allocations` row from the re-total, or add a
deploy-checklist census
`SELECT d.document_number FROM documents d JOIN payment_allocations pa ON pa.document_id=d.id WHERE d.id IN (<affected ids>)`
that must return 0 rows per tenant, and name it in the handback. Add a test.

### 4. [IMPORTANT] A client-echoed `tax_rate` still outranks `tax_configuration_id` — the brief's r2 requirement is NOT met
`DocumentLineTaxResolver.php:43-45` short-circuits on an explicit rate before the configuration is ever read.
Proven on the autosave endpoint:

```
PROBE C  payload = tax_configuration_id (TVA_7) AND tax_rate '19.00'  -> persisted ["19.00"]
```
This is r1 finding 8 unchanged; the fix round deliberately routed through the shared resolver (correct — one
policy, one place) but the policy itself is still "client wins". `applyLineTax`
(`DocumentForm.tsx:188-196`) means the *current* web bundle never sends both, so this is not exploited
in-product — but mobile/integration callers can, and **a browser still running a pre-deploy cached bundle
will keep sending `tax_rate` alone and keep getting the N-1 behaviour** until it refreshes. The handback asks
for an owner ruling and does not have one.
**Fix:** get the ruling before promotion. If "configuration wins", swap branches 1 and 2 in
`DocumentLineTaxResolver::resolveTaxRate()` and pin it with a test on BOTH document write paths. Either way,
name the stale-bundle window in the deploy checklist.

### 5. [IMPORTANT] The headline new test cannot tell the configuration branch from the product-rate branch
`AutoSaveDraftLineTaxResolutionTest.php:266-276` — `productOn()` sets
`'tax_rate' => $configuration->percentage_rate`, so in
`test_an_autosaved_line_resolves_seven_percent_from_the_tax_configuration_id` (`:115`) and
`test_an_autosaved_line_falls_back_to_the_products_own_configuration` (`:138`) resolver branches 2, 3 and 4
all yield the same `7.00`/`13.00`. Proven: renaming the whole `lines.*.tax_configuration_id` rule key at
`AutoSaveDraftRequest.php:232` (so `validated()` drops the field exactly as before the fix) leaves **4 of 5
green** — only the 422 case goes red. The class's own docblock claims it pins "the draft path resolves tax
through the SAME `DocumentLineTaxResolver`"; it does not pin that.
**Fix:** make the fixture the real N-1 shape — `'tax_rate' => '19.00'` on the config-bearing product — and
add a case where the product has **no** configuration while the line carries one (my PROBE B: expect `7.00`,
which is `19.00` if the rule is dropped). Both are one-line changes and both then discriminate.

### 6. [IMPORTANT] Manifest: lane and dev both moved `gated_ceiling` to the literal 1152 — git will NOT conflict, and the merged value will be wrong
Deep-diffed the lane's `apps/api/tests/feature-lane-manifest.json` against local `dev` (`9316506d4`,
which contains Session B's `681c7bf29`):

```
group          dev   lane
Document        77    78   (+1 lane: AutoSaveDraftLineTaxResolutionTest)
POS            150   151   (+1 lane: ReceiptProductTaxConfigurationRateTest)
Product         55    57   (+2 lane: ProductTaxRateDerivationTest + the migration test)
Fiscal          80    79   (+1 DEV, Session B)
Inventory      111   108   (+3 DEV, Session B)
gated_ceiling 1152  1152
```
Both sides started from **1148** and both independently arrived at **1152** — dev via +4 (Fiscal 1,
Inventory 3), the lane via +4 (Document 1, POS 1, Product 2). Because the two sides wrote the *same literal*,
a three-way merge resolves `gated_ceiling` to **1152 with no conflict**, while the merged group counts sum to
**1156**. `feature-lane-manifest-check.php` will then fail on dev with
`GATED-LANE COVERAGE GREW: 1156 … ceiling is 1152`.
For the record, the checker is **EXIT=0 inside the lane worktree as it stands** (I re-ran it after removing
my probe files).
**Reconciliation the parent must perform in the merge commit:** set `gated_ceiling` to **1156**, confirm all
five group counts survived (Document 78, POS 151, Product 57, Fiscal 80, Inventory 111), keep both sides'
`note` prose, and re-run `php apps/api/tools/feature-lane-manifest-check.php` to EXIT=0 before promoting.

### 7. [MINOR] `retotal()` uses `app()` twice, and migrations are outside PHPStan's reach
`…_n1.php:428` and `:430` (`app(DocumentTotalsCalculator::class)`, `app(CurrencyScaleResolverInterface::class)`)
violate rule 13. The docblock (`:305-309`) argues a migration is a script, which is defensible — but note
that `phpstan.neon:6-8` scans `app/` only, so **nothing** static-analyses this file: no level-8 check, and
none of the `ForbidHardcodedBcmathScale` / `ForbidFloatCastOnDecimalProperty` money guards run on it. Worth
one line in the handback so the next migration author knows the guards are off here.

### 8. [MINOR] `docs_retotalled` under-reports
`…_n1.php:446-452` compares only `[subtotal, tax_amount, total]`, but
`DocumentTotalsCalculator::recalculate()` also writes `line_tax_amount` and `stamp_duty_amount`
(`DocumentTotalsCalculator.php:49-54`). A document whose split changed but whose three compared columns
happened not to will be counted as untouched in the gate line a deploy checklist greps.
**Fix:** compare all five columns, or use `$document->wasChanged()`.

### 9. [MINOR] Autosave now costs 2 + N queries per keystroke-debounce tick
`DraftPersistenceService.php:170-188` adds one `Company` lookup and one `Product` lookup per save, and
`DocumentLineTaxResolver::rateFromConfigurationId()` (`:82-94`) issues an uncached `TaxConfiguration::find()`
**per line**. Auto-save fires on a debounce while the operator types; a 30-line document is ~32 extra queries
per tick, inside the `lockForUpdate()` transaction. Correctness is fine.
**Fix:** memoise configuration lookups per `resolve()` call (they are country-scoped reference rows).

### 10. [MINOR — process, not code] A second agent was writing to this worktree during the gate
At 16:58:48 an uncommitted edit appeared in
`…_backfill_products_tax_rate_from_tax_configuration_n1.php:377` changing
`d.status IN ('draft','confirmed')` to `d.status IN ('draft','confirmed','posted')`, and at 17:00:13 an
untracked `apps/api/tests/Feature/Product/Migrations/ZzGuardProbeN1Test.php` appeared. Neither was mine; both
were gone by the end of my run and the tree is clean at `51250b4cd`. It reads like a parallel reviewer's
tamper cycle. **All of my evidence above was gathered against HEAD content** — I verified
`git show HEAD:…` still carries `('draft','confirmed')`, and my mixed-fixture probe (which reported
`posted invoice → UNTOUCHED`) ran ~an hour before that edit existed. The parent should confirm no second
lens is mid-tamper before merging, since an un-restored tamper in that file would ship a migration that
rewrites POSTED document lines.

---

## What must change before merge

**Findings 1 and 2 are blocking.** Type-scope the migration's second half to an explicit sales-side
allow-list and add one UNTOUCHED test per excluded type (credit note first); then resolve the
Domain→Application injection so `tools/deptrac-ratchet.php` exits 0, following the precedent already written
at `PurchaseQuoteRequestToPurchaseOrderConverter.php:86-94`. Finding 6 (manifest `gated_ceiling` → 1156) must
be done by the parent in the merge commit or dev's own checker goes red. Findings 3, 4 and 5 must be closed
or explicitly owner-ruled before promotion; 7-10 are follow-ups.

Re-gate scope for r3: the migration's type predicate + its new negative tests, the deptrac resolution, the
two test-fixture changes in finding 5, and the manifest arithmetic. The autosave resolution itself, the
country-scope 422, the seal/post/void/cancel guards, idempotency, savepoint isolation and the census are
**ACCEPTED as-is and need no re-review**.
