# Adversarial gate — documents fix lane (3 commits, local `dev`, NOT pushed)

**Date:** 2026-08-02
**Reviewer:** adversarial Opus gate (code-verified, probes run against local sqlite test env + `tests/Feature/Document`)
**Scope:** `0939fcab6` (credit-note numbering), `b9653b604` (CreditNoteDetailPage confirm/post routes), `7258a409f` (confirm/conversion VAT zeroing)
**Ticket:** `docs/superpowers/tickets/2026-08-02-documents-w1-rerun-defects.md`
**Ruling in force:** an explicitly-supplied line rate must NEVER be silently zeroed; where no `TaxConfiguration` row matches, confirm/conversion honours the explicit line rate (draft==confirm identity per `18e61a554`).

---

## VERDICT: APPROVE-WITH-FIXES

The three root causes are correctly diagnosed and the fixes are the right shape. One **hard blocker** (a red test at HEAD that the hand-off claimed green) must land before promotion; three findings should be ticketed and one of them (F3) arguably belongs in this lane because the ruling explicitly invokes the draft==confirm identity that the conversion path still does not satisfy.

| # | Severity | Finding | Gate |
|---|---|---|---|
| F1 | **BLOCKER** | Stale FE test asserts the OLD broken credit-note routes → RED at HEAD | must fix before promote |
| F2 | HIGH | Identical `/documents/{id}/confirm` 404 defect unfixed on DeliveryNote + ReturnNote detail pages; their tests lock it in | ticket before promote |
| F3 | HIGH | draft ≠ confirm for CONVERTED invoices (stamp duty); the lane's own test bakes the divergence in | ticket (recommend: fix in lane) |
| F4 | MEDIUM | `document_tax_details.tax_base` = whole-document subtotal on every rate row; fix widens the VAT-declaration base overstatement | ticket |
| F5 | MEDIUM | `snapshotTaxDetails()` never writes `is_stamp_duty` → TN stamp invisible to `TunisiaVatStrategy`, folded into VAT decl as rate-0 output (pre-existing) | ticket |
| F6 | LOW | Hardcoded English `"VAT {rate}%"` persisted + surfaced on the tax-breakdown endpoint | ticket |
| F7 | LOW | `InvoiceController` docblock rationale now false, will mislead the next editor | fix in lane (comment-only) |
| F8 | LOW | `runningTaxTotal` now compounds unconfigured line VAT into `TOTAL_INCLUDING_PREVIOUS` doc-level taxes (inert for seeded TN/FR) | record decision |
| F9 | LOW | Numbering regression test is coincidence-passing; does not prove collision-freedom | fix in lane (test-only) |
| F10 | INFO | Commit message overstates "closes the underlying race condition" — tenant-vs-company scope mismatch survives (pre-existing, not a regression) | reword / ticket |

---

## A. Numbering fix (`0939fcab6`) — SOUND, test is weak

**Both formats parsed?** Moot and better than asked: `CreditNoteService::generateCreditNoteNumber()` is deleted outright; all three creation paths now call `DocumentNumberingService::generateNumber()` (`app/Modules/Document/Application/Services/CreditNoteService.php:80, 252, 413`). No `document_number` string is parsed anywhere in the credit-note path. The ticket's suggested "parse BOTH formats" would have been the weaker fix.

**Concurrency (two simultaneous creates)?** Handled. `DocumentNumberingService::generateForKeyOnce()` (`app/Modules/Document/Domain/Services/DocumentNumberingService.php:44-68`) does `lockForUpdate()->first()` inside `DB::transaction`, backed by the unique index `(company_id, type, year)` (`database/migrations/tenant/2025_11_30_140002_add_company_id_to_document_sequences.php:22`); the create-race window (no row to lock yet) is covered by the 23505 retry in `generateForKey()` (`:31-40`). This is strictly stronger than the removed `orderBy('created_at','desc')` + regex, which had no lock at all.

**PG string-vs-numeric ordering of seq?** No longer applicable — no `MAX`/`ORDER BY` on `document_number` remains in the credit-note path.

**Tenant scoping of the MAX query?** The sequence lookup is `where('company_id')·where('type')·where('year')` with no `tenant_id` predicate. Safe under database-per-tenant (each tenant DB is physically separate) and identical to what every other document type already does. No cross-tenant leak.

### F9 — LOW: the regression test passes by coincidence
`tests/Unit/Document/CreditNoteServiceTest.php:239` (`it_generates_collision_free_numbers_when_a_legacy_format_row_exists`) seeds `CN-{year}-0006` while `document_sequences.last_number` starts at 0, so the generated number is `CN-{year}-0001` and cannot collide with the fixture regardless of correctness.

**Probe (run):** copying the test with the fixture changed to `CN-{year}-0001` errors out with a UNIQUE violation raised from the `documents` insert at `app/Modules/Document/Application/Services/CreditNoteService.php:93`. The test therefore does not demonstrate collision-freedom against a sequence that is behind existing rows — it only demonstrates that the *old regex misparse* is gone (which the `assertNotSame('CN-02027', …)` line does carry). Recommend adding a case that pre-seeds `document_sequences` in sync and one that asserts the misparse specifically, or drop the "collision-free" framing.

### F10 — INFO: "closes the underlying race condition" overstates the change
`documents` is unique on `(tenant_id, type, document_number)` (`database/migrations/tenant/2025_11_30_080000_create_documents_table.php:39`) while `document_sequences` is scoped to `(company_id, type, year)`. In a **multi-company tenant**, company B's fresh counter emits `CN-{year}-0001`, which collides with company A's existing row → uncaught 500 (the retry in `generateForKey` only covers the *sequence-row* unique violation, not the `documents` insert). The removed `CN-%05d` path had exactly the same tenant-vs-company exposure, so this is **not a regression** and not a promotion blocker — but the collision class was moved, not closed, and the commit message should not claim otherwise.

**Tests:** `tests/Unit/Document/CreditNoteServiceTest.php` + `tests/Feature/Document/CreditNoteIntegrationTest.php` + `CreditNoteAllocationTest` + `CreditNoteTenantIsolationTest` + `Types/CreditNoteDocumentTest` — all green.

---

## B. Route fix (`b9653b604`) — CORRECT, but leaves a red test and two identical live defects

The diagnosis is verified: `php artisan`-independent route reading confirms `app/Modules/Document/Presentation/routes.php` exposes **no** `/documents/{document}/confirm` or `/post` (only `revert`, `showAny`, `additional-costs`, `related`, `tax-breakdown`, `payments`, `credit-allocations`, `pdf`, `email`). The real routes are `routes.php:203` and `:207`. The new client functions (`apps/web/src/features/documents/api/creditNotes.ts:54-77`) and their use in `CreditNoteDetailPage.tsx:59,74` are right.

**Does confirm/post now double-fire anything?** No. Each mutation issues exactly one `apiPost`, both gated behind `ConfirmDialog`. `invalidateQueries({ queryKey: ['document', id] })` (`CreditNoteDetailPage.tsx:62,76`) prefix-matches the `tenantScopedKey(['document', id])` read key (`:50`) — correct, no stale-cache or duplicate-request hazard. `queryKey` audit (`tools/audit-tanstack-keys.mjs`) reports Gate C 0 new / 0 stale. ESLint on the touched files: 0 errors. `tsc --noEmit`: clean.

### F1 — **BLOCKER**: a stale test asserting the old broken routes is RED at HEAD
`apps/web/src/features/documents/__tests__/DetailPagesAndRepository.tenantScope.test.tsx:248-249` renders `CreditNoteDetailPage` and still asserts:

```
expect(mockApiPost).toHaveBeenCalledWith('/documents/doc-1/confirm')
expect(mockApiPost).toHaveBeenCalledWith('/documents/doc-1/post')
```

`api/creditNotes.ts` imports `apiPost` from the same mocked `lib/api` module, so the spy now records `/credit-notes/doc-1/confirm|post` and the assertion fails.

**Verified (run at HEAD):**
```
npx vitest run src/features/documents
 Test Files  1 failed | 37 passed (38)
      Tests  1 failed | 282 passed (283)
 FAIL  …/DetailPagesAndRepository.tenantScope.test.tsx > detail pages and repository tenant scope
       > scopes credit note detail reads and confirm/post invalidations
```

This directly contradicts the hand-off's "component tests + typecheck green". Fix: update `:248-249` to the `/credit-notes/…` URLs (and rename the case if it is meant to be route-agnostic). One-line change, but the gate cannot pass on a red suite.

### F2 — HIGH: two sibling pages carry the identical 404 defect, unfixed, with tests pinning it
- `apps/web/src/features/documents/delivery-notes/DeliveryNoteDetailPage.tsx:60` → `apiPost('/documents/${id}/confirm')`; real route is `/delivery-notes/{deliveryNote}/confirm` (`routes.php:266`).
- `apps/web/src/features/documents/return-notes/ReturnNoteDetailPage.tsx:56` → `apiPost('/documents/${id}/confirm')`; real route is `/return-notes/{returnNote}/confirm` (`routes.php:296`).

Both Confirm buttons 404 unconditionally, exactly as the credit-note one did. Worse, the current suites *assert* the broken URL and therefore lock it in: `DetailPagesAndRepository.tenantScope.test.tsx:273` and `ReturnCreditNotePages.tenantScope.test.tsx:334`. This is outside the ticket's stated scope (ticket item 2 names credit notes only) and I am **not** asking for it in this lane — but it is the same P0 class on two more live pages and must be ticketed before promotion, not discovered by the next campaign re-run.

**E2E:** removing the `apiRequest` workaround from `e2e/money-campaign/w1b-support.ts` in favour of driving the real buttons is the correct direction. Note the helper now depends on untranslated English labels (`'Confirm'`, `'Post'`, `'Post Invoice'`) — consistent with the rest of that suite, flagged only as future brittleness.

---

## C. VAT fix (`7258a409f`) — the ruling is correctly implemented; two real consistency gaps remain

### Configured rates: byte-identical — VERIFIED
`app/Modules/Taxation/Domain/Services/TaxCalculationService.php:115-144`: the per-rate arithmetic block was moved verbatim out of `if ($matchingConfig)` with no edit to the bcmath. The matched-config `CalculatedTax` construction (`:146-160`) is character-identical to the pre-fix version, and both `$lineItemsTaxTotal` (`:144`) and `$runningTaxTotal` (`:191`) accumulate the same `$taxAmount`. For a document whose every rate matches a config, `lineItemsTaxTotal`, `documentTaxTotal`, `totalTax` and `total` are unchanged. Corroborated by green suites: Taxation 39/39, GL + VAT-report 41/41, `tests/Feature/Document` 398 tests with no new failures.

### Over-taxation / arbitrary-rate injection: a ceiling EXISTS (0–100 %, 2 dp) — but no allowlist
Every document ingress validates `lines.*.tax_rate` as `['nullable'|'required','numeric','min:0','max:100','regex:/^\d+(\.\d{1,2})?$/']`:
- `app/Modules/Document/Presentation/Requests/CreateDocumentRequest.php:131` (used by `InvoiceController::store`, `QuoteController::store`, `SalesOrderController::store`)
- `app/Modules/Document/Presentation/Requests/UpdateDocumentRequest.php:109`
- `app/Modules/Document/Presentation/Controllers/CreditNoteController.php:165` (standalone credit notes; the controller takes a bare `Request` but rolls the same rule inline — checked because a bare `Request` is normally a ceiling hole)

`DocumentLineTaxResolver::resolveTaxRate()` (`app/Modules/Document/Application/Services/DocumentLineTaxResolver.php:41-45`) does return the caller-supplied rate verbatim, but only after that validation. So the reachable band is **0–100 % at 2 dp**, not unbounded. The change does not widen it: an arbitrary in-band rate was already honoured on the **draft** (`InvoiceController.php:283`, `CopiesDocumentData::recalculateTotals():285`); the fix makes confirm agree with the draft rather than shrinking the document. This is exactly the ruling.

Residual, and worth recording as an accepted risk rather than a defect: a permissioned user can now post e.g. 87 % VAT and it lands in `document_tax_details` and the VAT declaration with no operator signal. The ruling forecloses refusal and forecloses zeroing; the remaining lever is **visibility**. Recommend a follow-up that flags unconfigured rates on the confirm response / in an admin report (the `code: 'UNCONFIGURED'` marker at `TaxCalculationService.php:177` already makes them queryable).

### Quote/order (NonFiscal) arms → downstream consumers stay consistent — VERIFIED
- **Conversion totals:** `CopiesDocumentData::createTargetDocument()` now sets `fiscal_category` (`app/Modules/Document/Domain/Services/Conversion/Concerns/CopiesDocumentData.php:71`). Blast radius is the four converters that use the trait — `QuoteToSalesOrderConverter:129` (NonFiscal→NonFiscal, no change), `SalesOrderToInvoiceConverter:168` and `DeliveryNoteToInvoiceConverter:133,181` (NON_FISCAL→TAX_INVOICE, the fix), `SalesOrderToDeliveryNoteConverter:160,217` (NON_FISCAL→DELIVERY_NOTE). `InvoiceToCreditNoteConverter` and `PurchaseQuoteRequestToPurchaseOrderConverter` do **not** use the trait and already set their own category — no conflict, no override collision with `$overrides`.
- **Hash chain:** `FiscalCategory::requiresHashChain()` has **zero** call sites in `app/` (repo-wide grep), and `isFiscal()` is consumed only by `DocumentData::from()` (`app/Modules/Document/Application/DTOs/DocumentData.php:193`). Flipping converted invoices/delivery notes to fiscal categories therefore changes tax-config matching and one DTO boolean — it does **not** silently arm a fiscal sealing path.
- **GL on invoice post:** `GeneralLedgerService:178` / `:240` consume the aggregate `$invoice->tax_amount` / `$creditNote->tax_amount` into a single VAT account. Since `total = subtotal + tax_amount` stays coherent, the journal stays balanced; only the magnitude changes (correctly). GL suites green.
- **VAT period reports:** `EloquentVatDataRepository::aggregateByRateAndDirection()` filters `d.type IN ('invoice','credit_note','expense')` (`app/Modules/Taxation/Infrastructure/Repositories/EloquentVatDataRepository.php:29`), so the new non-zero quote/order tax never reaches a declaration. Unconfigured invoice/credit-note rates now **do** appear — as their own `tax_rate` bucket with a NULL `tc.id` and `COALESCE(is_recoverable, true)` (`:41`). That is an improvement (they were previously missing from the declaration entirely) — but see F4.

### Parent ticket `2026-08-02-confirm-zeroes-vat-unconfigured-rates.md`: BOTH arms closed
- Finding 1 (invoice, unconfigured rate) → `tests/Feature/Document/ConversionChainVatIntegrityTest.php:303`
- Finding 2 (quote / sales order, permanently NonFiscal) → `:155` and `:245`

The parent ticket file still reads open; it should be marked closed-against-this-commit as part of the promotion.

### F3 — HIGH: the draft==confirm identity is NOT achieved on the conversion path
The ruling's stated goal is "keeps the draft==confirm identity `18e61a554` established". `18e61a554` achieved it for `InvoiceController::store()`/`update()` by folding document-level taxes into the draft via `withDocumentLevelTaxes()` (`app/Modules/Document/Presentation/Controllers/InvoiceController.php:124-141`). The **conversion** path was not given the same treatment: `CopiesDocumentData::recalculateTotals()` (`.../Concerns/CopiesDocumentData.php:272-298`) still loops lines and applies only `LINE_ITEMS` VAT — it can by construction never see the TN stamp duty, and it does not write `line_tax_amount` / `stamp_duty_amount` either (unlike `DocumentTotalsCalculator::recalculate():50-56`).

The lane's own test **asserts the divergence rather than closing it**:
- converted invoice draft: `subtotal 100.000 / tax 19.000 / total 119.000` — `ConversionChainVatIntegrityTest.php:212-214`
- same invoice after confirm: `tax 20.000 / total 120.000` — `:230-236`

So a user who converts an order sees a draft invoice that is short by exactly the stamp duty and jumps 1.000 TND on confirmation. That is precisely the MTP-DOC-01/03/04 defect class `18e61a554` was written to kill, now reachable through a different door. Recommended fix is small and local: have the trait's `recalculateTotals()` route through `DocumentTotalsCalculator` (or apply the same `documentTaxTotal` fold), which also fixes the missing `line_tax_amount`/`stamp_duty_amount` columns on converted drafts.

(Adjacent, pre-existing, not introduced: `CopiesDocumentData::scale()` at `:36-39` calls the bare no-arg `getScale()`, which per CLAUDE.md rule 19 ignores the document currency and throws outside a bound `CompanyContext`. Worth folding into the same fix if F3 is actioned.)

### F4 — MEDIUM: per-rate `tax_base` is the WHOLE document subtotal; the fix widens the overstatement
`TaxCalculationService.php:154` (matched) and `:182` (unconfigured) both pass `base: $subtotal`, where `$subtotal` is the entire document subtotal from `calculateSubtotal()` (`:71`), not the base of the lines carrying that rate.

**Probe (run, then deleted):** a TN invoice with two lines — 100.000 @ 19 % (configured) and 200.000 @ 21 % (unconfigured), subtotal 300.000 — snapshots:

```
code=STAMP_TAX_INVOICE rate=0   base=300  amount=1   is_stamp_duty=false
code=TVA_19            rate=19  base=300  amount=19  is_stamp_duty=false
code=UNCONFIGURED      rate=21  base=300  amount=42  is_stamp_duty=false
SUM(non-stamp tax_base) = 900 vs document subtotal 300.000
```

`EloquentVatDataRepository` does `SUM(dtd.tax_base) as base_amount` (`:38`), so the declaration's taxable base is inflated 3× on this document. The bug is **pre-existing** (any multi-configured-rate document already produced one over-stated row per rate), but the fix now emits a row for every previously-silent unconfigured rate, so it strictly increases the number of over-stated rows and the number of affected documents. Ticket separately; not a promotion blocker on its own, but it should not reach tenant #1's first VAT declaration.

### F5 — MEDIUM (pre-existing, surfaced by the same probe): `is_stamp_duty` is never snapshotted
`snapshotTaxDetails()` (`TaxCalculationService.php:326-337`) writes `tax_code`, `tax_name`, `tax_type`, `tax_rate`, `tax_fixed_amount`, `tax_base`, `tax_amount` — but **not** `is_stamp_duty`, so the STAMP_TAX_INVOICE row persists with the column default `false` (see probe output above). Consequences:
- `TunisiaVatStrategy` (`app/Modules/Taxation/Infrastructure/Strategies/TunisiaVatStrategy.php:96-109`) filters `where('document_tax_details.is_stamp_duty', true)` → reports **zero** stamp duties collected.
- `EloquentVatDataRepository` filters `where('dtd.is_stamp_duty', false)` (`:30`) → the 1.000 TND stamp is aggregated **into the VAT declaration** as a rate-`0.00` OUTPUT line with base 300 and VAT 1.

Not introduced by this lane, but it lives in the same table the fix now writes more rows to, and it is a TN compliance leak ahead of first-tenant launch. Ticket.

### F6 — LOW: hardcoded English tax name on a fiscal artifact
`name: "VAT {$rateStr}%"` (`TaxCalculationService.php:178`) is persisted to `document_tax_details.tax_name` and surfaced verbatim by `DocumentController::taxBreakdown()` (`:343`) and `DocumentTaxBreakdownResource:52`. On an `fr_TN` tenant it appears next to "TVA 19%". Prefer a neutral, locale-agnostic label or an i18n key resolved at presentation.

### F7 — LOW: the `InvoiceController` docblock rationale is now false
`app/Modules/Document/Presentation/Controllers/InvoiceController.php:113-118` still states that consuming `totalTax` "would silently ZERO the VAT of any line carrying an explicitly supplied rate that has no configuration row (a new P0 regression at create time)". After this fix that trap no longer exists. The code (`:133`, `documentTaxTotal` only) remains correct and non-double-counting, but the comment will send the next editor down a dead path — and it is the exact comment that would otherwise justify the F3 fix. Update it in this lane (comment-only).

### F8 — LOW: `runningTaxTotal` now compounds unconfigured line VAT
`$runningTaxTotal = bcadd(...)` moved out of the matched branch to `TaxCalculationService.php:191`, so unconfigured line VAT now feeds the compound base of any `applies_to = DOCUMENT_TOTAL` config with `stacks_on = TOTAL_INCLUDING_PREVIOUS` (`TaxConfiguration::calculateAmount()`). Inert for the shipped seeders — TN's `DOCUMENT_TOTAL` rows are all `FIXED_AMOUNT` (`database/seeders/TunisiaTaxConfigurationSeeder.php:134-137`) and FR seeds no `DOCUMENT_TOTAL` row at all — but a tenant-configured percentage document-level tax will behave differently. The new behaviour is arguably the correct one; flagged so it is a recorded decision rather than an accident.

---

## D. Cross-cutting

**draft==confirm identity for `InvoiceDraftDocumentTaxTest` fixtures:** preserved — `tests/Feature/Document/InvoiceDraftDocumentTaxTest.php` green alongside `DocumentLineTaxConfigurationResolutionTest`, `CreateDocumentTest`, `UpdateDocumentTest`, `DocumentConversionScenarioTest`, `DocumentConversionFieldsCarryTest` (54/54 in that batch). The identity holds for the `store()`/`update()` path; it does **not** hold for the conversion path — F3.

**bcmath-only, no floats, scale from the resolver:** confirmed. The fallback branch reuses the same `bcdiv`/`bcmul`/`bcadd` block at `scale+1` with a single `CurrencyScale::bcformat(..., $scale)` at the boundary (`TaxCalculationService.php:129-142`); `$scale` comes from `scaleFor($document)` (`:41-50`), which threads the document's own currency into the resolver and falls back to `getScaleSafe(null, 3)` — no bare no-arg `getScale()` anywhere in the changed backend code. No `(float)` cast, no `parseFloat` on the FE side of the change.

**Static gates run at HEAD:**
- `phpstan analyse` on the three changed backend files (level 8, incl. `ForbidFloatCastOnDecimalProperty` / `ForbidHardcodedBcmathScale`) → **[OK] No errors**
- `pint --test` on the three changed files + the two test files → **pass**
- `tsc --noEmit` (apps/web) → **clean**
- `eslint` on the three changed FE files → **0 errors** (60 pre-existing `colorClasses` deprecation warnings, untouched lines)
- `node tools/audit-tanstack-keys.mjs` → Gate C **0 new, 0 stale**

**Backend suites run (by path):**
| Suite | Result |
|---|---|
| `ConversionChainVatIntegrityTest` + `CreditNoteServiceTest` + `DocumentTotalsCalculatorTest` | 17/17 OK |
| Taxation (11 files incl. `TaxCalculationServiceTest`, `TaxSnapshot*`, `TaxRecoverability`, `TaxBreakdown*`) | 39/39 OK (24 skipped, pre-existing) |
| Document/conversion/credit-note (7 files incl. `InvoiceDraftDocumentTaxTest`, `DocumentConversionScenarioTest`) | 54/54 OK |
| GL + VAT reporting (`CreditNoteGLIntegrationTest`, `InvoiceAndCreditNoteGLIntegrationTest`, `VatDataRepositoryTest`, `VatReportControllerTest`, `VatPeriodControllerTest`, `TunisiaVatStrategyTest`) | 41/41 OK |
| `tests/Feature/Document` (whole directory) | 398 tests, **13 errors — ALL in `IngressPrecisionTest`** |

The 13 `IngressPrecisionTest` errors are `ArgumentCountError: CreateDocumentRequest::__construct()` and are **pre-existing**: reproduced identically (13/13, same signature) by checking out `a0b07d92e`, the commit immediately before this lane. Not a regression, but note the reported "backend 37/184 green" figure hides them — they should be ticketed independently.

**Frontend suite run at HEAD:** `src/features/documents` → 37 files pass, **1 file fails** (F1), 282/283 tests.

---

## Promotion checklist

**Must fix before promote**
1. **F1** — update `DetailPagesAndRepository.tenantScope.test.tsx:248-249` to the `/credit-notes/…` URLs; re-run `npx vitest run src/features/documents` to green.

**Should fix in this lane (small, comment/test-only, or directly implied by the ruling)**
2. **F7** — correct the now-false rationale at `InvoiceController.php:113-118`.
3. **F9** — strengthen the numbering regression test so it fails without the fix for the right reason.
4. **F3** — strongly recommended in-lane: route `CopiesDocumentData::recalculateTotals()` through `DocumentTotalsCalculator` so converted drafts carry document-level taxes and populate `line_tax_amount`/`stamp_duty_amount`. If deferred, it must be an explicit orchestrator ruling, because the commit message claims the draft==confirm identity is kept and the lane's own test proves it is not on this path.

**Ticket before promote (do not lose)**
5. **F2** — `/documents/{id}/confirm` 404 on `DeliveryNoteDetailPage.tsx:60` and `ReturnNoteDetailPage.tsx:56`, plus the two tests pinning the broken URLs.
6. **F4** — per-rate `tax_base` overstatement feeding the VAT declaration.
7. **F5** — `is_stamp_duty` never snapshotted; TN stamp invisible to `TunisiaVatStrategy` and mis-bucketed by the VAT declaration.
8. **F6** — hardcoded `"VAT {rate}%"` label.
9. **F8** — record the compound-base decision.
10. Pre-existing `IngressPrecisionTest` 13 errors.
11. Mark `docs/superpowers/tickets/2026-08-02-confirm-zeroes-vat-unconfigured-rates.md` CLOSED (both arms) against `7258a409f`.

**Not merged, not pushed** — gate only.
