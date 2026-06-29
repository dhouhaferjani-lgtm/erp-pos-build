# Hexagonal Architecture & Separation-of-Concerns Audit

**Date:** 2026-06-24
**Scope:** Backend `apps/api` (40 modules, hexagonal) + frontend `apps/web/src` (React SoC)
**Method:** 8 parallel read-only auditor agents (6 backend module clusters + 1 cross-cutting structural/deptrac sweep + 1 frontend), each scoring against `apps/erp/.claude/context/architecture.md` and the CLAUDE.md rules (constructor-injection-only, no cross-module model imports, precision contract, enums-not-magic-strings, thin controllers).
**Deliverable:** report only — **no code was changed**.

---

## 1. Executive summary

The hexagonal *intent* is well understood and the within-module layer direction is **stable** — deptrac is flat at 59 grandfathered violations with **zero regression** since 2026-05-14. The real problems live in the dimensions deptrac does **not** police:

1. **Float touches money/quantity in real fiscal/posting paths.** This is the highest-risk class for a fiscal-compliance ERP. Worst: the **Weighted-Average-Cost pipeline is float-typed end-to-end** (every goods receipt rebases persisted inventory valuation through a float), and the **Billing** entities do raw float arithmetic with **float-equality status gates** (`Paid` / fully-refunded) while ignoring their own correct bcmath `Money` VO.
2. **Module boundaries are effectively unenforced.** **726 cross-module Eloquent/Domain imports** exist and are *invisible to CI* (deptrac treats module A → module B as an allowed same-layer dependency by design). Application services, controllers, jobs and listeners freely import and `::create()` other modules' models.
3. **Domain purity leaks.** **84 `DB::` usages inside `*/Domain/*`** (10 raw SQL); Domain services own `DB::transaction`, query builders, `app()`, and the `Log` facade — especially in POS and Document.
4. **Fat controllers.** Several 800–930-line controllers run multi-step business workflows (invoice create/post, company provisioning, user invitation) and even cross-module writes directly in the Presentation edge.
5. **Frontend debt is large but low-risk.** ~12.6k hardcoded color classes (only ~25% of files on design tokens), ~50 raw `api.*` calls in components, ~40 `parseFloat` on money — mostly mechanical.

**Deptrac is green and honest, but narrow.** Treat the green badge as "within-module layering is stable," not "the architecture is clean."

---

## 2. Go-live triage (recommended priority)

Re-scored from the agents' raw severities through a **launch-risk** lens: *will this corrupt money/state in a path that actually runs?* beats *is this architecturally impure?*

### P0 — Fix before go-live (money/state correctness in live paths)
| # | Issue | Evidence |
|---|---|---|
| P0-1 | **WAC pipeline float-typed end-to-end** — `GoodsReceiptService` casts landed cost + qty to `(float)` into `WeightedAverageCostService::recordPurchase/calculateNewWAC` (both `float`-typed, then call the banned `CurrencyScale::bcformat($float,…)`). Persisted inventory valuation rebased through float on every receipt. | `Inventory/Application/Services/GoodsReceiptService.php:154,165`; `WeightedAverageCostService.php:803-829` |
| P0-2 | **Document line total via float** — `$lineTotal = (string)($quantity * $unitPrice)` after float casts, then persisted as `line_total`. | `Document/Domain/Services/DraftPersistenceService.php:246-248,576-577` |
| P0-3 | **Billing entities float arithmetic + float status gates** — `Invoice`/`Payment`/`Refund`/`InvoiceItem` do raw `(float)` math; `Paid`/fully-refunded decided by float comparison. Bypasses the module's own bcmath `Money` VO. *(Confirm whether SaaS subscription-billing is in launch scope — if deferred, this drops to P1.)* | `Billing/Domain/{Invoice.php:194-224, Payment.php:185-232, InvoiceItem.php:93-109, Refund.php:105}`; `Billing/Presentation/Controllers/AdminBillingController.php:277,341,388` |
| P0-4 | **POS money through float** — discount percent `(float)` into bcdiv money math; `ReceiptPdfService` decides change-given with `(float)$totalPaid > (float)$receipt->total`. | `POS/Application/Services/ReceiptCreationService.php:1004-1035` → `Domain/Services/DiscountCalculationService.php:144-148`; `POS/Application/Services/ReceiptPdfService.php:133` |
| P0-5 | **Frontend POS float sum on payment amounts** in the canonical sale flow (`reduce((s,p)=>s+p.amount,0)`), while the same file uses `bcadd` elsewhere. | `apps/web/src/features/pos/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx:217` |
| P0-6 | **Tax/fiscal output floated** — withholding **certificate PDFs** (`number_format((float)gross_amount,…)`) and the UK **MTD VAT exporter** launder figures through float before submission to the tax authority. | `Taxation/Application/Services/CertificatePDFService.php:85-88`; `Taxation/Infrastructure/Exporters/MtdJsonExporter.php:68,92,97` |

> Global precision sweep: **185 `(float)` casts + 24 `number_format`** across modules, concentrated in fiscal/tax/reporting output. P0 lists the ones in genuine posting/fiscal-output paths; the rest (display-only aggregates) are P2.

### P1 — High-value cleanup (boundary & layer integrity; maintenance + testability risk)
| # | Issue | Evidence |
|---|---|---|
| P1-1 | **Add a cross-module deptrac ruleset.** 726 cross-module Eloquent/Domain imports have no CI guard (the deferred M3 ticket). Worst pairs: POS→Identity (36), POS→Company (23), Inventory→Company (21); `Company\Domain\Company` is the most-imported foreign model. | `deptrac.yaml:18-22` (documents the gap); sweep §C |
| P1-2 | **Domain doing persistence/transactions/facades** — POS Domain services own `DB::transaction`, raw query builders, `app()`, `Log::`; Document Domain services run raw SQL; `Taxation\Domain\Services\TaxResolutionService` runs `DB::table('categories')`. | `POS/Domain/Services/{ShiftManagementService,GrandtotalService,ZReportHashService,ReceiptHashService}.php`; `Document/Domain/Services/*`; `Taxation/Domain/Services/TaxResolutionService.php:71` |
| P1-3 | **HTTP leaks into Application with no port** — `PlatformIntegration` injects concrete `PlatformHttpClient` and even calls the `Http` facade inside an Application service. | `PlatformIntegration/Application/Services/{ProductSubmissionService.php:12,49, BarcodeLookupService.php:21, CatalogBrowseService.php:16}` |
| P1-4 | **Fat controllers doing orchestration + cross-module writes** — `InvoiceController` (886), `AuthController` (932), `UserController` (775) run multi-step workflows; `PosPendingCustomerController` and `MarketplaceOrderService` `::create()` foreign models (`Partner`, `Document`) directly. | `Document/Presentation/Controllers/InvoiceController.php`; `Identity/Presentation/Controllers/{AuthController,UserController}.php`; `POS/Presentation/Controllers/PosPendingCustomerController.php:75-93`; `Marketplace/Application/Services/MarketplaceOrderService.php:222-338` |
| P1-5 | **`app()`/`resolve()` service-location** — 22 `app()` + 34 `resolve()` (CLAUDE rule 13). Hotspots: Accounting/Document FormRequests, `ResolveTenancy` middleware, `DailyExpiryCheck` job. | sweep §C; `Accounting/Presentation/Requests/Get*Request.php`; `Document/Presentation/Requests/*Request.php:49,46` |

### P2 — Structural placement & hygiene (mostly mechanical, do as a sweep)
| # | Issue | Evidence |
|---|---|---|
| P2-1 | **Critical fiscal logic outside the Domain layer** — the SHA-256 two-tier hash-chain engine sits in a top-level `Services/` dir. | `Compliance/Services/FiscalHashService.php` → `Compliance/Domain/Services/` |
| P2-2 | **Module-root `Services/` namespaces** (none of the 4 layers) — Import (5 services incl. god `ImportService`), Company (`CompanyContext`/`LocationContext`), Compliance. | `Import/Services/*`, `Company/Services/*`, `Compliance/Services/*` |
| P2-3 | **Media has no Infrastructure layer**; `Storage` facade called straight from Application; provider + routes at module root. **Resolve via the media-unification work (target `MediaAsset`), do NOT harden the legacy `DocumentAttachment` path.** | `Media/Application/Services/AttachmentService.php:61-135`; `Media/MediaServiceProvider.php`, `Media/routes.php` |
| P2-4 | **Stray layer dirs** — `Listeners/`/`Jobs/`/`Notifications/`/`Commands/` at module root (Accounting, Inventory, BatchExpiry, Billing, POS), POS's 5 root `routes_*.php`, 6 module-root `*ServiceProvider.php`. | sweep §B |
| P2-5 | **Workshop has no canonical layer structure** — three nested hexagons (`Bundle/`, `Technician/`, `WorkOrder/`) that silently dodge the deptrac globs. Decide: promote to first-class modules or restructure. | `Workshop/{Bundle,Technician,WorkOrder}/` |
| P2-6 | **God classes** — `Nf525DataProvider` (1694), `ReceiptCreationService` (1412), `ReceiptReturnService` (1326), `MonitoringService` (763), `ImportService`, `MarketplaceOrderService`. | per-area appendices |
| P2-7 | **Magic strings / untyped array DTOs** — subscription status/billing-cycle strings, discount-type strings, `?array` service boundaries. | `AdminBillingController.php:354-365`; `Pricing/Domain/Services/PricingService.php:45-106`; `PurchaseHub/Application/Services/PurchaseHubService.php` |

### P3 — Frontend debt (low correctness risk, high volume — schedule post-launch)
| # | Issue | Scale |
|---|---|---|
| P3-1 | Hardcoded Tailwind colors instead of design tokens (CLAUDE rule 18). | ~12,654 occurrences; only ~25% of files import `designTokens`. Worst: `MonitoringPage` (155), `PartnerDetailPage` (149), `PaymentsPage` (128) |
| P3-2 | Raw `api.*`/`fetch` in components instead of the `*Api.ts` data layer. | ~50+ sites; worst clusters settings, treasury, documents |
| P3-3 | `parseFloat`/`Number` on money/quantity. | ~40+; withholding feature + `types/creditNote.ts`/`types/treasury.ts` worst |
| P3-4 | God components. | `routes/index.tsx` (2699), `AdvancedPaymentsModal` (989), `RecordPaymentModal` (864) |
| P3-5 | `any` in non-test src; remaining hardcoded strings. | ~31 `any`; ~116 candidate untranslated literals |

> **Frontend bright spot:** the team has internalized the API-unwrap / paginated-`meta` pitfalls — **0 true double-unwrap bugs**, only one fragile paginated-via-`apiGet` call (`features/finance/api.ts:68`).

---

## 3. Cross-cutting metrics

| Metric | Value |
|---|---|
| Deptrac violations (current) | **59** — identical to 2026-05-14 baseline, **0 drift** |
| Deptrac coverage gap | cross-module coupling **not enforced by design** |
| Cross-module Domain/Eloquent imports | **726** |
| `(float)` casts (non-test, backend) | **185** |
| `number_format(` (backend) | **24** |
| `DB::` inside `*/Domain/*` | **84** (10 raw SQL) |
| `app()` / `resolve()` service-location | **22 / 34** |
| Frontend hardcoded color classes | **~12,654** (token adoption ~25% of files) |

---

## 4. Accepted patterns — explicitly NOT flagged (calibration)

So this report isn't read as noise, the auditors verified and **excluded** the following against the codebase's documented conventions:

- **Eloquent models at `Domain/` root** — explicitly permitted by `architecture.md`.
- **`Providers/` at module root** — the convention in 29/33 modules; not a defect.
- **Same-module active-record** (model queries in Application/Job/Listener for the module's *own* models) — accepted; these modules ship no repository layer by design.
- **`DB::transaction` as a facade** — pervasive codebase-wide; called out only where it sits inside the **Domain** layer.
- **Partner money handling** — verified clean (numeric-string + `decimal:4` + bcmath, no float).

---

## 5. Recommended sequencing

1. **P0 sweep (pre-launch):** convert the WAC pipeline, Document line totals, POS discount/change, the frontend POS sum, Billing entities, and tax-output PDFs/exporters to numeric-string + bcmath. Each is localized; add a regression test per fix. The `ForbidFloatCastOnDecimalProperty` / `ForbidHardcodedBcmathScale` PHPStan guards already exist — extend their coverage to lock these once fixed.
2. **P1-1 in parallel:** stand up the cross-module deptrac ruleset (per-module layers) and baseline the 726 existing edges, so new cross-module coupling fails CI even though the historical set is grandfathered. This is the single highest-leverage structural guard.
3. **P1-2…P1-5 and P2** as a post-launch architecture cleanup branch (Domain-purity extraction, controller thinning, file relocations, god-class splits). The Compliance `FiscalHashService` relocation (P2-1) is low-risk and worth doing early.
4. **P3** frontend debt: schedule the design-token migration as an incremental ratchet (already has an ESLint guardrail in newer dirs); fix the `parseFloat`-on-money sites alongside the P0 frontend fix.
5. **Media (P2-3):** do nothing on the legacy `DocumentAttachment` path — fold into the media-unification session targeting `MediaAsset`.

---

## 6. Per-area findings (appendix)

Full evidence tables from each auditor are preserved below. Severities here are the **agents' raw scores**; §2 re-prioritizes them for launch risk.

See sibling files in this folder:
- `01-sales-commerce.md` — Cart, Document, Pricing, Coupon, Promotion, Voucher, Loyalty
- `02-inventory-catalog.md` — Inventory, Catalog, Product, Uom, BatchExpiry, PurchaseHub
- `03-finance-fiscal.md` — Accounting, Treasury, Fiscal, Compliance, Taxation, Billing, Expense
- `04-pos-ops.md` — POS, Scheduling, Service, Workshop, Progression, Menu
- `05-platform-identity.md` — Identity, Tenant, Company, Admin, Contact, Partner, Communication
- `06-integration-misc.md` — Channel, Marketplace, PlatformIntegration, Media, Import, Vehicle, Dashboard, SmartPrompts
- `07-structural-deptrac-sweep.md` — deptrac drift, misplaced files, global anti-pattern counts
- `08-frontend-soc.md` — apps/web/src
