# N-1 VAT resolution — gate r1, TREASURY/GL lens

Lane: `fix/campaign-n1-vat-resolution`, worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/n1-vat-resolution`, HEAD `51250b4cd`, base local `dev`.
Lens: DOCUMENT → GL → VAT-declaration consequence of product tax-rate derivation. POS seal / autosave are the fiscal-pos lens (r2) and are NOT re-reviewed here.
Method: read the code, then executed. Lane tests run BY PATH on sqlite AND on a throwaway PostgreSQL (`127.0.0.1:5433`, db `autoerp_test_n1tr`, **dropped**). Three defect probes written, run on both drivers, then deleted; one tamper applied to the migration and reverted (`git status --porcelain` clean, tamper string count = 0). One test process at a time. Full suite never run. Lane not modified, nothing committed.

---

## VERDICT: spec ❌ + quality CHANGES-REQUESTED

The **product arm** (`ProductController` + `TaxResolutionService`) is correct and I found nothing wrong with it. The **second half of the migration** — the `document_lines` repair + re-total added in `1dc3ce465` — is not safe to run unattended on every tenant DB. It is **type-blind** (it rewrites purchase and credit-note documents from the *sales* product master), it leaves three derived money columns stale (`documents.balance_due`, `document_lines.recoverable_tax_amount`/`non_recoverable_tax_amount`, `document_tax_details.*`), and its guard tests are **vacuous** — I removed the posted-document guard from the production SQL and all 14 tests still passed.

Findings 1–4 are all in the `repairUnpostedDocumentLines()` half. The cheapest defensible fix is to narrow that half to the two document types the campaign actually found (`quote`, `sales_order`) and refuse anything with a cached `balance_due` or a `document_tax_details` snapshot — see "What must change before merge".

---

## Lens questions, answered

**(1) Does a 7 % document line post the invoice GL entry with a 7 % output-VAT account?** No such thing exists, and that is pre-existing, not a lane defect. `tax_configurations` has **no GL-account column at all** (`apps/api/app/Modules/Taxation/Domain/Entities/TaxConfiguration.php:36-54` — `country_code, tax_type, name, code, percentage_rate, fixed_amount, applies_to, is_default, is_active, effective_from, effective_to, sequence_order, stacks_on, applicable_document_types, is_stamp_duty, is_recoverable, metadata`), so a configuration cannot carry a GL account and there is no "config-derived rate carries its own account vs the company default" branch to get wrong. `GeneralLedgerService::createFromInvoice()` (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:135-192`) resolves ONE account — `SystemAccountPurpose::VatCollected` (`:142`) — and credits the whole header `tax_amount` to it (`:183-192`), rate-blind. The TN chart seeds a single `4457 TVA collectée` (`apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php:207-208`); there are no `44571/44572/44573` per-rate sub-accounts. So: the 7 % line posts the **right amount** (header `tax_amount` is recomputed from the repaired lines) to a **single aggregate 4457**. Per-rate granularity exists ONLY in `document_tax_details`, which is why finding 3 is serious. *(Also noted, pre-existing, out of lane: `:184` uses the bare no-arg `$this->scale()` — `GeneralLedgerService::scale():62-65` → `getScaleResolver()->getScale()` — which throws outside a request context. Not this lane's to fix.)*

**(2) Does the VAT declaration read the per-line configuration rather than `products.tax_rate`?** It reads neither: it reads the **snapshot** `document_tax_details.tax_rate` (`apps/api/app/Modules/Taxation/Infrastructure/Repositories/EloquentVatDataRepository.php:31-66`), written by `TaxCalculationService::snapshotTaxDetails()` (`apps/api/app/Modules/Taxation/Domain/Services/TaxCalculationService.php:509-528`) from `document->lines` grouped by `tax_rate` (`:127-135`). `TunisiaVatStrategy::mapToDeclaration()` (`apps/api/app/Modules/Taxation/Infrastructure/Strategies/TunisiaVatStrategy.php:54-66`) buckets `'7.00' → base_7/vat_7`. **Going forward** the lane is correct: a line at 7.00 snapshots at 7.00 and lands in `base_7`. **For the rows the migration repairs it is wrong** — see finding 3, proven by execution.

**(3) Does the re-total recompute `total`/`tax_total`/`balance_due` consistently, bcmath-only, at TND 3 dp, and leave posted/credit-note/allocated documents alone?** Partly. bcmath and scale are clean: `DocumentTotalsCalculator::recalculate()` (`apps/api/app/Modules/Document/Domain/Services/DocumentTotalsCalculator.php:33-57`) uses `bcadd` at the passed scale and delegates tax to `TaxCalculationService`, which is bc-only; the migration passes the **document's own currency** (`…_n1.php:441-444`, `getScale($currency)` with a `getScaleSafe(null, 3)` fallback for a blank currency) — correct for the no-`CompanyContext` `tenants:migrate` world (rule 20). No float anywhere on the path; the rate comparison itself happens DB-side. **But** `recalculate()` writes only `subtotal, line_tax_amount, stamp_duty_amount, tax_amount, total` (`DocumentTotalsCalculator.php:50-56`) — **not `balance_due`** (finding 2), **not the line-level VAT columns** (finding 1), **not the tax snapshot** (finding 3). Posted and sealed documents are correctly out of reach in the shipped code (I proved the guard holds with a mixed fixture) — but the tests that claim to prove it don't (finding 4). Credit notes and purchase documents are **not** excluded (finding 1).

**(4) `TaxResolutionService::resolveRateFromTaxConfiguration()` scale/format.** Clean. `apps/api/app/Modules/Taxation/Domain/Services/TaxResolutionService.php:131-150`: returns `CurrencyScale::bcformatStrict($configuration->percentage_rate ?? '0', 2)` — canonical decimal string at **percent** scale 2 (rule 19: percent is NOT currency-scaled), no float, no `number_format`. Scoped by `country_code` (`:138`) — which IS the ownership boundary, since `tax_configurations` carries no `company_id`/`tenant_id` (`TaxConfiguration.php:36-54`) — plus `applies_to = LINE_ITEMS` (`:139`) and `isPercentage()` (`:142`). Returns `null` rather than a default; the caller converts that to a 422 (`ProductController.php:1148-1155`). A foreign-country or malformed id cannot reach it: both requests validate `uuid` + `exists:tax_configurations,id` + `TaxConfigurationCountryCoherent` (`CreateProductRequest.php:206-209`, `UpdateProductRequest.php:192-195`), so the PG "non-UUID into a uuid column 500s" trap is closed.

---

## Execution log

| Run | Driver | Result |
|---|---|---|
| `tests/Feature/Product/Migrations/BackfillProductsTaxRateN1MigrationTest.php` | sqlite | 14 passed (49 assertions) |
| same + `tests/Feature/Product/ProductTaxRateDerivationTest.php` | PG 5433 throwaway | 23 passed (69 assertions) |
| defect probes A/B/C (temporary, deleted) | sqlite | 3 failed — findings 1, 2, 3 |
| defect probes A/B/C (temporary, deleted) | PG 5433 throwaway | 3 failed, byte-identical |
| **tamper**: posted-guard removed from the PG `document_lines` UPDATE | PG | **14/14 STILL PASSED** — finding 4 |
| mixed-fixture guard probe, same tamper | PG | FAILED (posted line rewritten 19.00 → 7.00) |
| mixed-fixture guard probe, tamper reverted | PG | PASSED (posted line stays 19.00) |

Probe output, verbatim (identical on both drivers):

```
PROBE-A supplier_invoice: line.tax_rate=7.00 line.recoverable=19.000 doc.tax_amount=7.000 doc.total=107.000 doc.balance_due=119.000
PROBE-B sales_order:      doc.total=107.000 doc.balance_due=119.000
PROBE-C confirmed invoice: doc.tax_amount=7.000 dtd.tax_rate=19.00 dtd.tax_base=100.000 dtd.tax_amount=19.000
GUARD-PROBE (tampered):   draft line=7.00  posted line=7.00      <- guard gone, lane tests still green
GUARD-PROBE (reverted):   draft line=7.00  posted line=19.00
```

---

## Findings

### 1. [CRITICAL] The unposted-line repair is TYPE-BLIND: it rewrites supplier invoices, purchase orders and credit notes from the *sales* product master, and can make a draft supplier invoice permanently unpostable

`apps/api/database/migrations/tenant/2026_08_24_100000_backfill_products_tax_rate_from_tax_configuration_n1.php:159-172` (`AFFECTED_DOCUMENT_IDS_SQL`) and `:364-411` (both driver spellings of the `document_lines` UPDATE) filter on `d.status IN ('draft','confirmed') AND d.fiscal_status='DRAFT' AND d.fiscal_hash IS NULL` — and on **nothing else**. There is no `d.type` predicate. Every `DocumentType` (`apps/api/app/Modules/Document/Domain/Enums/DocumentType.php:9-48`) that can hold a product line is in scope: `supplier_invoice`, `purchase_order`, `purchase_rfq`, `credit_note`, `supplier_credit_note`, `return_note`, `delivery_note`, `expense`.

Draft supplier invoices are squarely in the predicate: `apps/api/app/Modules/Procurement/Application/CreateSupplierInvoiceService.php:120-147` creates them with `fiscal_category = NonFiscal`, `fiscal_status = FiscalStatus::Draft`, `status = DocumentStatus::Draft`, and its lines carry `product_id` (`:149-175`). Their `tax_rate` is **what the supplier charged on their paper**, not what our product master says; rewriting it from `products.default_tax_configuration_id` is factually wrong, silently changes what we owe, and mis-states deductible input VAT.

It also **bricks the AP posting path**. `SupplierInvoicePostingService.php:257-283` builds the deductible-VAT legs by summing `document_lines.recoverable_tax_amount` / `non_recoverable_tax_amount` — columns this migration never touches — and `GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry()` enforces a hard invariant at `:2033-2040`:

> `total %s != billedHT %s + recoverableVAT %s + nonRecoverableVAT %s + timbre %s … Refusing to post an unbalanced GR-IR clearing entry.`

PROBE-A, executed on sqlite and PG: after the migration the line reads `tax_rate=7.00` but `recoverable_tax_amount=19.000`, while the header was re-totalled to `total=107.000`. Expected total at post time = 100.000 + 19.000 + 0 + 0 = 119.000 ≠ 107.000 → `DomainException`, posting refused, **forever** (nothing re-derives the line VAT columns). Every affected draft supplier invoice on every tenant becomes unpostable on the next deploy.

Draft **credit notes** are equally reachable, and are worse in a different way: a credit note exists to reverse a specific sealed invoice. Rewriting its rate to the product's current configuration while the original invoice stays sealed at 19 % leaves a permanent VAT and AR residual that nothing reconciles.

**Fix:** add an explicit allow-list to BOTH the id pre-read (`:165-171`) and both UPDATE spellings (`:374-380`, `:399-404`): `AND d.type IN ('quote','sales_order')` — the only two types the campaign census actually found (handback §"Campaign-tenant census", QT-2026-0002/0003/0004 + SO-2026-0001). Anything else is a fiscal/AP correction that needs a human. Add a test per excluded type asserting the line is untouched.

### 2. [CRITICAL] The re-total moves `documents.total` but never `balance_due`, and the PG trigger does not fire on a `documents` UPDATE — stale AR/AP outstanding and a wrong payment ceiling

`DocumentTotalsCalculator::recalculate()` (`apps/api/app/Modules/Document/Domain/Services/DocumentTotalsCalculator.php:50-56`) writes `subtotal, line_tax_amount, stamp_duty_amount, tax_amount, total`. It does **not** write `balance_due`. The cache-maintaining trigger is bound to `payment_allocations` only — `apps/api/database/migrations/tenant/2026_01_08_214145_add_balance_due_cache_trigger.php:55-61`: `AFTER INSERT OR UPDATE OR DELETE ON payment_allocations`. A `documents` UPDATE fires nothing.

`Document::outstandingBalance()` — documented in-file as "the ONE definition (W-6 D2)" — treats a non-null `balance_due` as **AUTHORITATIVE** and returns it verbatim (`apps/api/app/Modules/Document/Domain/Document.php:767-770`). Consumers of that stale number are all treasury: `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:505` (the payment ceiling), `apps/api/app/Modules/Treasury/Domain/Services/MultiPaymentService.php:62`, `apps/api/app/Modules/Treasury/Application/Services/CloseInvoiceWithToleranceService.php:74`, `apps/api/app/Modules/Accounting/Application/Services/Reports/AgedReceivablesService.php:168,186` and `…/AgedPayablesService.php:167,368`.

`balance_due` is non-null on plenty of draft/confirmed documents — it is written at creation by `apps/api/app/Modules/Procurement/Application/StandaloneReceiptService.php:221` (`'balance_due' => $subtotal`, status Draft), `apps/api/app/Modules/Procurement/Application/PurchaseQuoteRequestService.php:61`, `apps/api/app/Modules/Document/Application/Services/POSAccountChargeDraftService.php:65,167`, and on every conversion by `apps/api/app/Modules/Document/Domain/Services/Conversion/Concerns/CopiesDocumentData.php:87`. And **confirmed sales orders are payable**: `apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php:476-481` explicitly allows prepayment allocation to `type = sales_order AND status = confirmed`, which makes the trigger populate `balance_due` — exactly the document class this migration re-totals.

PROBE-B, on both drivers: `doc.total=107.000` but `doc.balance_due=119.000`. The customer's outstanding on a prepaid, repaired SO is overstated by the VAT delta, and the payment ceiling accepts 12.000 TND too much.

**Fix:** in `retotal()` (`…_n1.php:437-453`), after `$calculator->recalculate()`, refresh the cache for any document whose `balance_due` was non-null, using the trigger's own formula — `total − Σ payment_allocations.amount − Σ credit_note_allocations.amount` — and count it in the gate line. Or (simpler and safer) exclude documents with a non-null `balance_due` from the repair entirely and report them for manual handling.

### 3. [CRITICAL] The repair does not refresh `document_tax_details`, so a repaired confirmed invoice declares the OLD rate to the DGI

The VAT declaration reads the immutable snapshot, not the live lines: `apps/api/app/Modules/Taxation/Infrastructure/Repositories/EloquentVatDataRepository.php:31-66` joins `document_tax_details` to `documents` filtered on `d.type IN ('invoice','credit_note','expense')`, date range and `d.deleted_at IS NULL` — **with no `status` and no `fiscal_status` predicate**. A *confirmed* (not yet posted) invoice is therefore declared.

Confirmed documents DO carry that snapshot: `InvoiceController.php:628`, `QuoteController.php:551`, `SalesOrderService.php:107,188`, `DeliveryNoteService.php:144`, `PurchaseOrderService.php:95` all call `TaxCalculationService::snapshotTaxDetails()` on the draft→confirmed transition. The migration re-totals the header through `DocumentTotalsCalculator::recalculate()`, which never calls `snapshotTaxDetails()`.

PROBE-C, on both drivers: after the repair `doc.tax_amount=7.000` while `dtd.tax_rate=19.00, dtd.tax_base=100.000, dtd.tax_amount=19.000`. The DGI form gets `base_19 = 100.000 / vat_19 = 19.000` for a document that now says 7 %. The declaration and the invoice disagree by construction — and since finding 1 also lets the repair touch `credit_note` and `expense` documents, both the OUTPUT and the INPUT sides of the declaration are exposed.

The campaign tenant happens to escape (its 7 rows are all `quote`/`sales_order`, which the type filter excludes) — but the migration ships to every tenant, not just that one.

**Fix:** either (a) re-snapshot inside `retotal()` by calling `TaxCalculationService::snapshotTaxDetails($document, $result)` alongside `recalculate()` — note `recalculate()` currently discards the `TaxCalculationResult`, so it needs a variant that returns it; or (b) refuse to repair any document that already has a `document_tax_details` row, report the count in the gate line, and leave those to the owner-executed `BackfillTaxDetailsCommand` (`apps/api/app/Console/Commands/BackfillTaxDetailsCommand.php`), which exists for exactly this and is **not** mentioned anywhere in the handback or its promotion checklist.

### 4. [IMPORTANT] The posted/sealed guard tests are vacuous — I deleted the guard and all 14 tests stayed green

`repairUnpostedDocumentLines()` early-returns when the id pre-read is empty (`…_n1.php:360-362`), and the pre-read carries its own copy of the status predicate (`:168-170`). `test_a_posted_document_line_is_never_touched` (`apps/api/tests/Feature/Product/Migrations/BackfillProductsTaxRateN1MigrationTest.php:195-207`) and `test_a_sealed_document_line_is_never_touched` (`:209-227`) each build **one** document — the excluded one — so the pre-read returns `[]`, the UPDATE never executes, and the assertion passes no matter what the UPDATE's own guard says.

Proven: I changed `d.status IN ('draft','confirmed')` to `('draft','confirmed','posted')` in the PG `document_lines` UPDATE (`:377`) and the class ran **14/14 green on PostgreSQL**. A mixed fixture (one drifted draft invoice + one drifted posted invoice, same tenant) caught it immediately — the posted line was rewritten `19.00 → 7.00`. With the tamper reverted, that same mixed fixture passes. So the shipped guard is correct; the *tests* do not hold it. Given findings 1–3 will force this SQL to be edited again, an unguarded guard is how a posted document gets rewritten in round 2.

**Fix:** rebuild both guard cases (and the free-text case at `:229-248`, same shape) as MIXED fixtures — always include one repairable draft alongside the row that must not move, so the UPDATE actually executes. Re-run the same tamper afterwards and confirm it goes red.

### 5. [IMPORTANT] The re-total is unbounded and unlogged: it re-prices confirmed documents through the *current* tax engine with no per-document audit trail

`retotal()` (`…_n1.php:425-456`) calls `DocumentTotalsCalculator::recalculate()`, which calls `TaxCalculationService::calculateDocumentTaxes()` (`TaxCalculationService.php:57-83`) — and that re-derives the whole tax picture from the **currently active** configuration set: `forDocumentType()`, `effectiveOn($documentDate)`, partner exemption status (`:87`), and document-level stamp duty. Any of those changing since the document was written moves `total` for reasons that have nothing to do with N-1. The migration cannot tell the two apart: it compares `[subtotal, tax_amount, total]` before/after (`:446-451`) and reports only a **count** (`docs_retotalled=K`, `:240-247`). A confirmed sales order that a customer has already been quoted silently changes price with no event, no journal and no before/after record.

**Fix:** log one line per re-totalled document at WARNING (`document_number`, old→new `tax_amount`, old→new `total`) so the change is reconstructable from the deploy log, and assert in a test that the delta equals the VAT delta and nothing else. Add both greps to the promotion checklist.

### 6. [IMPORTANT] Soft-deleted documents get their lines repaired but never re-totalled

The raw UPDATE (`…_n1.php:364-411`) and the id pre-read (`:159-172`) have **no `d.deleted_at IS NULL`** predicate, but `retotal()` goes through `Document::query()` (`:434`) and `Document` uses `SoftDeletes` (`apps/api/app/Modules/Document/Domain/Document.php:36,112`), whose global scope excludes trashed rows. A soft-deleted draft document therefore ends up with 7 % lines under a 19 % header — the exact self-contradiction the second half exists to prevent — and `doc_lines` counts it while `docs_retotalled` does not, so the gate line's two counters silently disagree. (Note the declaration query itself does filter `d.deleted_at IS NULL`, `EloquentVatDataRepository.php:44`, so this is a data-integrity defect rather than a declaration one.)

**Fix:** add `AND d.deleted_at IS NULL` to the pre-read and to both UPDATE spellings.

### 7. [MINOR] `app()` inside the migration

`…_n1.php:428-430` resolves `DocumentTotalsCalculator` and `CurrencyScaleResolverInterface` through `app()`, against rule 13. The docblock (`:307-309`) argues a migration is a script, not an injected service, and hand-wiring the tax engine's dependency graph would be worse — which I accept. Recording it so the next reader does not re-litigate it, and so the PHPStan/deptrac waiver (if any) is visible. No change required.

### 8. [MINOR / informational, pre-existing — owner ticket, not this lane] No per-rate output-VAT sub-accounts

Per lens question (1): a 7 % sale and a 19 % sale credit the same `4457 TVA collectée` (`GeneralLedgerService.php:142,183-192`; `TunisiaChartOfAccountsSeeder.php:207-208`), because `tax_configurations` cannot carry a GL account (`TaxConfiguration.php:36-54`). Rate-level detail survives only in `document_tax_details`. That is a defensible design as long as the declaration is driven off the snapshot — which makes finding 3 the load-bearing one. If the owner ever wants `44571/44572/44573`, it needs a schema column on `tax_configurations` plus a resolver change; it is NOT in this lane's scope and nothing here regressed it.

---

## What must change before merge

Findings 1, 2 and 3 are all in `repairUnpostedDocumentLines()` and all three are closed at once by narrowing it: add `AND d.type IN ('quote','sales_order')` and `AND d.deleted_at IS NULL` to the id pre-read and both UPDATE spellings, and skip (or refresh) any document carrying a non-null `balance_due` or an existing `document_tax_details` row — then rebuild the posted/sealed guard tests as MIXED fixtures (finding 4) and re-run my tamper to prove they bite. The product arm, the `TaxResolutionService` derivation, the scale/format contract and the first half of the migration are sound and need no change.
