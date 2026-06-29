# Appendix 07 — Structural / Deptrac sweep (cross-cutting)

## A. Deptrac drift

Refreshed run (`./vendor/bin/deptrac analyse --config-file=deptrac.yaml`):

| Metric | Value |
|---|---|
| Violations (total) | **59** |
| Skipped | 0 |
| Uncovered | 9015 |
| Allowed | 7447 |

**Per-category vs. baseline (2026-05-14):**

| Category | Baseline | Current | Drift |
|---|---|---|---|
| ModuleDomain → ModuleApplication | 22 | 22 | 0 |
| ModuleApplication → ModuleInfrastructure | 21 | 21 | 0 |
| ModuleInfrastructure → ModulePresentation | 1 | 1 | 0 |
| SharedContracts → ModuleApplication | 11 | 11 | 0 |
| SharedContracts → ModuleDomain | 2 | 2 | 0 |
| SharedInfrastructure → ModuleDomain | 2 | 2 | 0 |
| **Total** | **59** | **59** | **0** |

**Verdict: FLAT — no regression.** Same grandfathered set (e.g. `Document\Domain\Services\DeliveryNoteService` → 3 Inventory/BatchExpiry Application services; `Treasury\Domain\Services\PaymentRefundService` → `RefundAllocation` DTO ×6; `Shared\Contracts\LoyaltyServiceInterface` → Loyalty Application DTOs ×9; `Shared\Infrastructure\CurrencyScaleResolver` → `Company\Domain\Company` ×2).

**Coverage limitation:** `deptrac.yaml:18-22` states it deliberately does NOT enforce cross-module coupling — module A → module B Domain is treated as an allowed same-layer dependency. The 0-drift result only proves *within-module* layer direction is stable. The cross-module Eloquent coupling in §C is invisible to this gate.

## B. Misplaced files (outside Domain/Application/Infrastructure/Presentation; `Providers/`, `routes*.php`, `README.md` accepted)

| Module | Misplaced path | Should be |
|---|---|---|
| **Workshop** | `Bundle/`, `Technician/`, `WorkOrder/` (each a full hexagon); no top-level layer dirs | Promote to first-class modules or nest under a layer. Most severe anomaly. |
| Workshop/Technician | `TechnicianServiceProvider.php` | `Workshop/Technician/Providers/` |
| Company | `Services/` (CompanyContext, LocationContext) | `Company/Application/Services/` |
| Company | `Listeners/` (CreateFiscalYearsForNewCompany) | `Company/Application/Listeners/` |
| Company | `CompanyServiceProvider.php` | `Company/Providers/` |
| Compliance | `Services/` (AuditService, **FiscalHashService**, AnomalyDetectionService, Nf525/) | `Compliance/Application/Services/` (hash service → `Domain/Services/`) |
| Compliance | `Commands/` (VerifyFiscalChains, BackfillFiscalHashes, ExportNf525Jet) | `Compliance/Presentation/Console/` or `Application/Commands/` |
| Import | `Services/` (ImportService, ValidationEngine, SpreadsheetParserService, …) | `Import/Application/Services/` |
| BatchExpiry | `Jobs/` (DailyExpiryCheck) | `BatchExpiry/Infrastructure/Jobs/` |
| BatchExpiry | `Notifications/` (CriticalBatchExpiryNotification) | `BatchExpiry/Infrastructure/Notifications/` |
| BatchExpiry | `BatchExpiryServiceProvider.php` | `BatchExpiry/Providers/` |
| Billing | `Notifications/` (5 files) | `Billing/Infrastructure/Notifications/` |
| Accounting | `Listeners/` (InvoicePostedListener) | `Accounting/Application/Listeners/` |
| Inventory | `Listeners/` (PostCOGSOnInvoice) | `Inventory/Application/Listeners/` |
| POS | `Commands/` (VerifyPosChainCommand) | `POS/Presentation/Console/` |
| Media | `MediaServiceProvider.php` | `Media/Providers/` |
| Partner | `PartnerServiceProvider.php` | `Partner/Providers/` |
| Product | `ProductServiceProvider.php` | `Product/Providers/` |
| Scheduling | `SchedulingServiceProvider.php` | `Scheduling/Providers/` |

Note: Admin, Communication, Dashboard, PurchaseHub are partial hexagons (Application/Presentation only) — acceptable thinness, not misplacement.

## C. Global anti-pattern sweep

| Pattern | Count (excl. tests) | Notes |
|---|---|---|
| `app()` service-location (excl. Providers) | **22** | CLAUDE rule 13 |
| `resolve()` | **34** | service-location |
| `(float)` casts | **185** | money/quantity precision risk |
| `floatval(` | 0 | clean |
| `number_format(` | **24** | precision risk |
| Cross-module Domain model/entity imports | **726** | rule 6, deptrac-invisible |
| `DB::` inside `*/Domain/*` | **84** (10 raw SQL) | domain-purity leak |

**`app()` worst offenders:** `Accounting/Presentation/Requests/GetProfitLossRequest.php:104` (+ GetBalanceSheet:94, GetLedger:81, CreateJournalEntry:31); `Document/Presentation/Requests/{CreateDocumentRequest.php:49, UpdateDocumentRequest.php:46}`; `Identity/Presentation/Middleware/ResolveTenancy.php:76`; `BatchExpiry/Jobs/DailyExpiryCheck.php:139`; `Document/Presentation/Controllers/Concerns/HandlesDocuments.php:84`.

**`(float)`/`number_format` (money) worst offenders:** `Taxation/Application/Services/CertificatePDFService.php:85-88`; `Accounting/Application/Services/Reports/FormatsReportNumbers.php:11`; `SalesReportService.php:131-175`; `CashRegisterReportService.php:65`; `Document/Application/Services/DocumentPdfService.php:202-247`; `StockAlertReportService.php:51`.

**Cross-module leaks — top pairs:** POS→Identity (36), POS→Company (23), Inventory→Company (21), Accounting→Company (14), Document→Company (14), Document→Product (12), POS→Partner (12), Inventory→Product (12), Treasury→Identity (12). Most-imported foreign model: `Company\Domain\Company`. Worst single files: `Tenant/Application/Services/OnboardingChecklistService.php`; `Promotion/Domain/Entities/Promotion.php` (Domain entity importing Company + Tenant).

**`DB::` in Domain — examples:** `Accounting/Domain/Services/GeneralLedgerService.php`; `BatchExpiry/Domain/Services/{BatchWriteOffService,FEFOInventoryService}.php`; `Taxation/Domain/Services/TaxResolutionService.php`; `Document/Domain/Services/{SalesOrderService,DocumentPostingService,ReturnNoteService,PurchaseOrderService,DocumentCacheValidationService}.php`.

## Top 5 systemic issues

1. **726 cross-module imports outside CI enforcement** — deptrac is green but only checks within-module layering by design. Add the deferred M3 cross-module ruleset before the boundary erodes further.
2. **185 `(float)` + 24 `number_format`, many on money/quantity** — concentrated in fiscal/tax/reporting output. Highest financial-correctness risk.
3. **84 `DB::` (10 raw SQL) inside `*/Domain/*`** — domain-purity leak across Accounting, Document, BatchExpiry, Taxation.
4. **Workshop has no canonical layer structure** — three nested hexagons that dodge the deptrac globs.
5. **Widespread misplaced top-level dirs + root ServiceProviders + 56 service-location calls** — needs a one-time cleanup sweep plus a structural lint to prevent recurrence.
