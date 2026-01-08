# Universal Modules Audit – Cross-Check Report

**Date:** 2025-12-24  
**Reviewer:** Codex (GPT-5)

## Scope
I re-ran the universal-module discovery work in `apps/api` and compared the code to the statements inside `docs/UNIVERSAL_MODULES_AUDIT_REPORT.md`. The focus was to validate the critical blockers and to flag any factual mismatches so that the upcoming freeze decision is based on precise information.

## Confirmed Critical Gaps
- **Document posting still lacks automatic GL creation.** `DocumentPostingService` only depends on `FiscalHashService` and never invokes the general ledger (`apps/api/app/Modules/Document/Domain/Services/DocumentPostingService.php:33-82`). All GL assertions inside `DocumentGLIntegrationTest` have to call `GeneralLedgerService::createFromInvoice()` manually (`apps/api/tests/Feature/Accounting/DocumentGLIntegrationTest.php:225-257`).
- **Stock levels are not touched when invoices post.** `PostCOGSOnInvoice` listens to `InvoicePosted` but only calls `GeneralLedgerService::createCOGSEntry()`—no stock movement or inventory service calls occur (`apps/api/app/Modules/Inventory/Listeners/PostCOGSOnInvoice.php:26-101`).
- **Accounting / Inventory / Partner modules still expose no domain events.** The directories `apps/api/app/Modules/Accounting/Domain/Events`, `.../Inventory/Domain/Events`, and `.../Partner/Domain/Events` are empty, so Claude’s recommendation to introduce events remains valid.

## Discrepancies & Corrections
| # | Claim from Claude’s report | Actual observation | Evidence / Notes |
|---|----------------------------|---------------------|------------------|
| 1 | “Universal modules have ZERO dependencies on vertical modules” and Decision D3 (“Should universal modules know about `vehicle_id`?”) is still open. | `Document` already imports `App\Modules\Vehicle\Domain\Vehicle` and persists a nullable `vehicle_id`, so the Document module depends on the Vehicle vertical today. | `apps/api/app/Modules/Document/Domain/Document.php:18` and `apps/api/app/Modules/Document/Domain/Document.php:91-123`. |
| 2 | “Accounting … (59 PHP files, 2 tests)” (Section 1.1). | There are nine feature suites plus four unit suites for Accounting. Examples include `GLIntegrationTest`, `DocumentGLIntegrationTest`, and `DoubleEntryValidationTest`. | `apps/api/tests/Feature/Accounting/GLIntegrationTest.php:1-55`, `apps/api/tests/Feature/Accounting/DocumentGLIntegrationTest.php:1-60`, `apps/api/tests/Unit/Accounting/DoubleEntryValidationTest.php:1-42`. |
| 3 | “Inventory … (39 PHP files, 6 tests)”. | Inventory currently has four feature suites (`StockManagementTest`, `StockMovementTest`, `BlindCountingTest`, `ReconciliationTest`) plus five unit suites (e.g., `StockAdjustmentServiceTest`). | `apps/api/tests/Feature/Inventory/StockManagementTest.php:5-80`, `apps/api/tests/Feature/Inventory/StockMovementTest.php:5-39`, `apps/api/tests/Unit/Inventory/StockAdjustmentServiceTest.php:1-37`. |
| 4 | “Partner … (9 PHP files, 6 tests)”. | Only four feature tests (`CreatePartnerTest`, `ListPartnersTest`, etc.) and one unit test (`PartnerEntityTest`) exist. | `apps/api/tests/Feature/Partner/CreatePartnerTest.php:5-58`, `apps/api/tests/Feature/Partner/DeletePartnerTest.php:5-40`, `apps/api/tests/Unit/Partner/PartnerEntityTest.php:1-34`. |
| 5 | Public API summary lists `DocumentConversionService::convertToOrder()`, `convertToInvoice()`, and `convertQuoteToInvoice()`. | The actual service exposes `convertQuoteToOrder()`, `convertOrderToInvoice(..., ?array $lineIds)`, `convertOrderToDelivery()`, and `convertOrderToPartialDelivery()`; no `convertQuoteToInvoice()` method exists. | `apps/api/app/Modules/Document/Domain/Services/DocumentConversionService.php:30-205` and `:214-320`. |
| 6 | `DocumentNumberingService::generateNumber(DocumentType $type, Company $company, Carbon $date): string`. | The implemented signature is `generateNumber(string $tenantId, string $companyId, DocumentType $type): string`, and it wraps the DB update/lock logic internally. | `apps/api/app/Modules/Document/Domain/Services/DocumentNumberingService.php:18-47`. |
| 7 | **Issue-008 / Blocker-005:** “GL hash chain not implemented; journal entries just hash their own payload.” | `GeneralLedgerService::postEntry()` already fetches the previous posted entry hash and calculates `hash('sha256', $previousHash.'|'.$data)` before persisting `hash`/`previous_hash`. The only missing piece is incrementing `chain_sequence`, but the chain itself exists. | `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:788-804` and `:907-924`. |

## Additional Notes
- Because `Document` already depends on Vehicle data, freezing the module without clarifying that coupling will constrain future “vertical isolation” work. Either remove the FK or accept that Vehicle is part of the universal model.
- The understated test counts make Accounting and Inventory look riskier than they are; however, those suites are mostly service-level tests. We still lack end-to-end coverage for “Invoice → Payment → GL” and for “Invoice → Stock deduction”, so Claude’s P1 testing asks remain relevant even though the baseline is stronger.
- If we want a ledger hash chain with explicit ordering, we only need to populate `chain_sequence`; the more drastic rework described in P1-TASK-005 is unnecessary and could be replaced with a lighter migration + service update.
