# Backend Request-Hygiene Audit — Where the Request Path Breaks at Scale

**Date:** 2026-09-02
**Scope:** `apps/erp/apps/api` (Laravel 12, Otospex/IziPOS). Read-only audit — no code modified.
**Question:** as tenants/users grow, where does the backend request path break because requests are not designed correctly?

This builds on `00-research-baseline.md` (industry benchmark) and `01-census.md` (macro grep counts) in this same directory. Every finding below is independently re-verified against the actual code with `path:line` citations — the census's grep counts are cited only as corroborating context, never as the evidence itself.

---

## Method

1. Traced the full middleware stack for one authenticated GET (`bootstrap/app.php` → every middleware class it registers) by opening each class and counting real DB calls, cross-checked against vendor Sanctum/Stancl source where the app extends them.
2. Read concrete list, write, report, and cache call sites for the named growing tables (products, documents, stock movements/levels, journal entries, partners, audit logs, POS events) rather than trusting grep counts alone.
3. Delegated three scoped, read-only sub-investigations (list endpoints; write endpoints/transactions/queues; caching + reports) to run in parallel, each required to cite `path:line`; findings below merge their output with my own primary reads, and every finding was either independently opened or is flagged where it wasn't.
4. Checked existing guards: custom PHPStan rules (`phpstan.neon`), `tests/Architecture/*Ratchet*`, `tests/Feature/**/*PaginationTest.php`, `BaselinePerformanceTest.php`, deploy scripts.

---

## Per-request overhead trace

Traced for an authenticated `GET` with a Bearer token and `X-Company-Id` header, on a module-gated route (e.g. `GET /api/v1/products`) — the common shape for tenant-#1 (POS/catalog/inventory) traffic.

| Step | File:line | Query | Cacheable but isn't? |
|---|---|---|---|
| 1. `ResolveTenancy` resolves tenant from bearer | `app/Modules/Identity/Presentation/Middleware/ResolveTenancy.php:122` | `SELECT` `personal_access_tokens` (via `CentralPersonalAccessToken::findToken`) | Duplicate of step 4 — same token, same table, two round trips |
| 2. `ResolveTenancy` loads the Tenant row | `app/Modules/Identity/Presentation/Middleware/ResolveTenancy.php:63` | `SELECT` `tenants` | Tenant rows change rarely; not cached, and re-fetched again at step 8 |
| 3. `TenancyResolver` checks DB-per-tenant provisioning | `app/Modules/Tenant/Application/Services/TenancyResolver.php:90` → `vendor/stancl/tenancy/src/TenantDatabaseManagers/PostgreSQLDatabaseManager.php:44` | `SELECT datname FROM pg_database WHERE datname = '…'` | Yes — provisioning state changes only on tenant creation/deprovision, yet re-queried on **every single request**, no `Cache::remember` |
| 4. `auth:sanctum` guard resolves the user | `vendor/laravel/sanctum/src/Guard.php:40` | `SELECT` `personal_access_tokens` again | Duplicate of step 1 |
| 5. `auth:sanctum` guard loads `tokenable` (User) | `vendor/laravel/sanctum/src/Guard.php:46-48` | `SELECT` `users` | — |
| 6. `auth:sanctum` guard stamps last-used | `vendor/laravel/sanctum/src/Guard.php:162-173` | `UPDATE personal_access_tokens SET last_used_at=…` (a **write**, not a read) | Not debounced — fires on every authenticated request, against the single shared **central** DB |
| 7. `SetPermissionsTeam` | `app/Modules/Identity/Presentation/Middleware/SetPermissionsTeam.php:28` | none itself; the first `$user->can()` downstream reads the Spatie cache | Cache hit on warm Redis (see Finding B-6 for why it isn't per-tenant) |
| 8. `CompanyContextMiddleware` validates company access | `app/Http/Middleware/CompanyContextMiddleware.php:135` → `app/Modules/Company/Services/CompanyContext.php:160` | `SELECT ... user_company_memberships ... EXISTS` | — |
| 9. `RequireModule` (module-gated routes only) | `app/Http/Middleware/RequireModule.php:52` | `SELECT` `tenants` (lazy `$user->tenant`) — **3rd Tenant fetch this request** | Same tenant row as step 2, no request-level memoization |
| 10. `RequireModule` → `CompanyConfigService` | `app/Services/CompanyConfigService.php:52` | 0 on warm cache (`GlobalCache::remember`, 24h TTL) | Already well cached — **done well** |
| 11. Controller: `CompanyContext::requireCompany()` | `app/Modules/Company/Services/CompanyContext.php:104` | `SELECT` `companies` + eager `tenants` | Never memoized — re-queried on every call, and some controllers call it twice per request (see B-5) |

**Tally**: a plain authenticated GET costs **7 DB operations before the controller body runs** (steps 1-2, 4-8; one of them a write), a module-gated GET costs **8-9** (adds step 9), and the controller's own `requireCompany()` call adds **1-2 more** before any business query runs. None of this is instrumented or capped — it scales with request volume, not with the underlying data, so it doesn't get worse as tables grow, but it multiplies flat per-request overhead by (tenants × users × requests/user), which is exactly the axis in the audit question.

---

## Findings table

| ID | Severity | Area | Evidence (`path:line`) | Failure mode at scale |
|---|---|---|---|---|
| B-1 | P2 | Per-request overhead | `ResolveTenancy.php:122` + `vendor/laravel/sanctum/src/Guard.php:40` | Same bearer token looked up twice per request (own middleware + Sanctum's guard) |
| B-2 | P1 | Per-request overhead | `TenancyResolver.php:90` + `PostgreSQLDatabaseManager.php:44` | Uncached `pg_database` catalog probe on **every** request across **every** tenant; scales linearly with total request volume, hits the shared central connection |
| B-3 | P1 | Per-request overhead | `vendor/laravel/sanctum/src/Guard.php:162-173` | Write (`UPDATE personal_access_tokens`) on every authenticated request, no debounce, against the one shared central DB — write-amplification hotspot as tenant×user count grows |
| B-4 | P2 | Per-request overhead | `ResolveTenancy.php:63`, `RequireModule.php:52` | Tenant row fetched up to 3×/request with no request-scoped memoization |
| B-5 | P2 | Per-request overhead | `CompanyContext.php:104` (called 2× in one request: `PartnerController.php:62,148`) | `Company::with('tenant')->find()` uncached; some controllers pay it twice |
| B-6 | P1 | Per-request overhead / caching | `config/permission.php:192,200`; `vendor/spatie/laravel-permission/src/PermissionRegistrar.php:74,222` | Spatie's permission cache is **one global key** across every tenant (not tenant-scoped) — any single tenant's role/permission edit busts the cache for **all** tenants simultaneously, forcing a full reload (all roles+permissions, all tenants) on the very next request from anyone |
| B-7 | **P0** | List endpoints — stock movements | `app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:70-74` | Default path (no `page` query param) runs `->get()` with 4 eager relations over the company's **entire** stock-movement history — unbounded, and `stock_movements` grows with every sale/receipt/transfer/adjustment |
| B-8 | **P0** | List endpoints — audit logs | `app/Modules/Compliance/Services/AuditService.php:131-144` (`getEventsInRange`), `AuditController.php:53-58` | Date-range audit query has no `limit()` and the controller enforces no max span — a "last quarter" request pulls the entire company audit history in one response |
| B-9 | P1 | List endpoints — documents | `app/Modules/Document/Presentation/Controllers/DocumentController.php:104-110` | Legacy `?limit=` code path is never capped |
| B-10 | P1 | List endpoints — products/partners | `app/Support/Traits/FiltersAndSorts.php:227-284`, used by `ProductController.php:288-298` (3 extra queries) and `PartnerController.php:122-136` (4 extra queries) | Every list load with a multi-key `aggregateConfig` pays N extra COUNT/SUM/AVG queries on top of `paginate()`'s own COUNT — 4-5 aggregate queries per products/partners list page, uncached, scales with table size not row count returned |
| B-11 | P1 | List endpoints — products | `ProductController.php:105` | `per_page` capped at 2000 (not 100) — large payload + serialization cost per request |
| B-12 | P1 | List endpoints — audit logs | `AuditController.php:71-72` | Full `payload`/`metadata` JSONB columns returned per row on a table that grows with every mutation in the system; no summary projection |
| B-13 | P2 | List endpoints — documents | `DocumentController.php:89-90` | Search `LIKE` not wildcard-escaped (`%`/`_` from user input) — contrast with the escaped pattern at `ReceiptController.php:117-118` and `FiltersAndSorts.php:164` |
| B-14 | P3 | List endpoints — categories/loyalty | `app/Modules/Product/Presentation/Controllers/CategoryController.php:44`; `app/Modules/Loyalty/Presentation/Controllers/LoyaltyMemberController.php:50,57-59` | Same unescaped-wildcard pattern as B-13 |
| B-15 | P2 | List endpoints — audit logs | `AuditController.php:67-79` | No pagination metadata (`total`/`page`/`has_more`) in the response at all — the client has no way to know it's not seeing the whole result set |
| B-16 | P2 | Write endpoints | ~30 `store()`/`update()` actions across Marketplace, Catalog, Treasury, Import, Pricing, POS, Cart, Inventory, PurchaseHub (full list in fork transcript, e.g. `app/Modules/Treasury/Presentation/Controllers/PaymentController.php:338`) | Raw `Request $request` instead of a `FormRequest`; spot-checked `PaymentController.php:365` and it *does* validate inline (`$request->validate([...])`), so this is a convention gap (`docs/conventions/06-FORMS.md`) rather than a proven no-validation bug — not exhaustively confirmed for every listed file |
| B-17 | P2 | Queued jobs / tenant context | 17 of 32 `ShouldQueue` classes lack `BindsTenantContext` (e.g. `app/Modules/Billing/Notifications/*`, `app/Modules/Identity/Application/Notifications/*`, `app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php`) | Relies entirely on Stancl's `QueueTenancyBootstrapper` (`config/tenancy.php:38-42`) auto-restoring tenant context from the queue payload; that framework-level bootstrapper is confirmed registered, but whether it reliably covers `ShouldQueue` **listeners** (vs. explicitly dispatched jobs) was not verified — this is exactly the incident class named in CLAUDE.md rule 20 |
| B-18 | P1 | Caching — money/tax hot path | `app/Shared/Infrastructure/CurrencyScaleResolver.php:43,57` → `CompanyContext.php:93`, `AppServiceProvider.php:102` | `getScale()` (mandatory per CLAUDE.md rule 19 at every money/quantity boundary) runs 2 fresh uncached queries (`Company::find`, `Country::find`) every call — a 20-line document/POS sale can trigger up to ~40 queries against two nearly-static reference tables |
| B-19 | P1 | Caching — tax resolution | `app/Modules/Taxation/Domain/Services/TaxResolutionService.php:51` | `$product->category()->first()` per product line, no eager load, no cache — same reference-data-refetched-per-line pattern as B-18 |
| B-20 | P1 | Caching — tenant isolation | `config/tenancy.php:40,89-100`; `config/tenancy_resolver.php:29-31`; cache sites e.g. `app/Modules/PlatformIntegration/Application/Services/BarcodeLookupService.php:49`, `CatalogBrowseService.php:315` | Cache tenant-scoping is **100% implicit** via `CacheTenancyBootstrapper` tagging, active only while `tenancy()->initialized` is true; no cache key anywhere embeds a tenant id directly, so any code path that touches `Cache::` without a live tenancy context (queued job, console, webhook) silently falls into one shared, un-tagged keyspace — no error, no warning. Ties directly to B-17 (jobs without tenant context) |
| B-21 | P1 | Reports — dashboard finance widget | `app/Modules/Accounting/Application/Services/Reports/FinanceSummaryService.php:60-81` | Fully uncached; calls `ProfitLossService::generate()` **twice** (MTD + YTD) and `BalanceSheetService::generate()` once, each a full GROUP-BY scan over `journal_entries`/`journal_lines`, on every dashboard load |
| B-22 | P1 | Reports — dashboard stats | `app/Modules/Dashboard/Presentation/Controllers/DashboardController.php:52-53,60-61,86-88,90-93,99-104,107,109-111,121-122` | 9 separate uncached queries per call; 2 are **unbounded full-table `COUNT(*)`** with no date filter (`totalInvoices`, `totalPartners`) — grows without bound as tenant age increases |
| B-23 | P1 | Reports — POS fiscal chain | `app/Modules/POS/Presentation/Controllers/ReportController.php:479-497` | On a broken hash chain, loads the terminal's **entire unbounded receipt history** and recomputes hashes sequentially in PHP — a tenant-#1 POS/fiscal path |
| B-24 | P2 | Reports — aged receivables | `app/Modules/Accounting/Application/Services/Reports/AgedReceivablesService.php:186-194,224-241` | Per-row N+1 on historical credit notes (self-documented, bounded by import volume) + unbounded outstanding-invoice load filtered in PHP with `bccomp` |
| B-25 | P2 | Reports — no range cap | `app/Modules/Accounting/Presentation/Requests/GetProfitLossRequest.php:16-24` | Validates `date_from <= date_to` but no maximum span — a client can request a decade-wide P&L, forcing a full-history GL scan |
| B-26 | P2 (not verified) | Reports — live sales | `app/Modules/Accounting/Application/Services/Reports/LiveSalesReportService.php:74` | Correlated subquery per receipt row against `pos_receipt_lines`; index on `receipt_id` not verified |
| B-27 | P2 | Caching — HTTP layer | grep across `app/Http` and every `Presentation/Controllers` (no `ETag`/`If-None-Match`/304 hits; only 2 `Cache-Control` uses, both unrelated to list/read caching: `app/Modules/Workshop/Technician/Presentation/Controllers/PayrollExportController.php:115`, `app/Modules/Media/Infrastructure/Storage/MediaStorageAdapter.php:99`) | Zero conditional-GET support anywhere — every list/read re-transfers the full payload every time regardless of change |
| B-28 | P2 | Caching — deploy consistency | `apps/api/docker/entrypoint.sh:214` (fatal) vs. `:219-220` (`route:cache` failure only warns, doesn't fail) | Inconsistent guard: production can silently run with routes re-registered from scratch on every request (all ~40 modules' `routes.php` re-`require`'d and re-parsed) with no alert; no `event:cache` call at all |
| B-29 | **P1** | Rate limiting | `app/Providers/AppServiceProvider.php:359-364` (defines `api` limiter, 100/min) — never referenced via `throttle:api` anywhere (exhaustive grep of `bootstrap/app.php` + every `app/Modules/*/Presentation/routes.php` + `routes/api.php`) | The generic API rate limiter is **dead code**. Laravel 12's `bootstrap/app.php`-style `api` middleware group carries no default throttle either (that changed from the old `RouteServiceProvider` skeleton). Only ~13 narrowly-scoped named limiters exist (auth, uploads, one webhook, storefront booking) — products, documents, reports, exports, imports, search, and bulk operations have **zero rate limiting**. As user/tenant count grows, one runaway client (buggy poll loop, retry storm, scripted abuse) has no per-route ceiling protecting shared DB/Redis/Horizon capacity from a noisy-neighbor tenant |

---

## Top findings expanded

### B-7 / B-8 — Unbounded default-list `->get()` on the two fastest-growing tables (P0)

**Mechanism.** `StockMovementController::index` (`app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:70-74`) branches on whether the request has a `page` query param at all:
```
if (! $request->has('page')) { $movements = $query->orderBy('created_at','desc')->get(); ... }
```
Any client that omits `page` — the natural shape of a first-load GET, or an older client build that predates pagination — gets every stock movement the company has ever recorded, each row eager-loading `product.unitOfMeasure`, `location`, `user`, `reversalOf`. `AuditService::getEventsInRange` (`app/Modules/Compliance/Services/AuditService.php:131-144`) has the identical shape for a different reason: no `limit()` call at all, and `AuditController::index` (`:53-58`) passes `from`/`to` straight through with no max-span check.

**Blast radius.** `stock_movements` gets a row on every sale, receipt, transfer, and adjustment — this is the highest-write-volume table in the Inventory tenant-#1 flow. `audit_events` gets a row on every mutation platform-wide. Both grow strictly with (tenants × users × time), which is exactly the audit's growth axis. At 10 tenants × 10 users after a few months of activity, the naive request shape (omit `page`, or request "last quarter's" audit trail) times out or OOMs the PHP-FPM worker — not a slow degradation, a hard break.

**Tenant-#1 flows hit:** Inventory (stock movements is core to every receiving/adjustment/count screen); indirectly POS and Purchasing, since their writes are what populate `stock_movements`.

### B-29 — The only generic rate limiter is dead code (P1)

**Mechanism.** `AppServiceProvider::configureRateLimiting()` defines a 100/min `api` limiter (`app/Providers/AppServiceProvider.php:359-364`) that reads as the safety net for "everything else." It is never wired to any route: `throttle:api` does not appear anywhere in `bootstrap/app.php`'s middleware configuration or in any of the 40+ module `routes.php` files. Laravel 12's `bootstrap/app.php` skeleton (unlike the old Laravel ≤10 `RouteServiceProvider`) does not implicitly apply a default API throttle to the `api` group either — confirmed by reading the group registration in `bootstrap/app.php:130-152`, which appends tenancy/security/locale/company/impersonation middleware but no throttle.

**Blast radius.** Every endpoint not in the ~13-item named-limiter list is unthrottled: full product/document/partner/journal-entry lists, all report/dashboard endpoints (including the uncached, multi-query ones in B-21/B-22/B-23), imports, exports, search. This is exactly the endpoint class the audit calls out ("exports, reports, imports, search, bulk ops, PDF") — none of them have a throttle. As tenant/user count grows, there is no per-route ceiling standing between one client's bug (a retry loop, a broken polling interval) and shared DB connection / Redis / Horizon queue exhaustion for every other tenant.

### B-18 / B-19 / B-21 / B-22 — Reference-data and dashboard queries repeated per call, uncached (P1 cluster)

**Mechanism.** `CurrencyScaleResolver::getScale()` (`app/Shared/Infrastructure/CurrencyScaleResolver.php:43,57`) is the mandated call site at every money/quantity boundary (CLAUDE.md rule 19) — and it runs a fresh `Company::find()` and `Country::find()` on every single call, no cache layer at all. `TaxResolutionService::resolveProductTax()` (`app/Modules/Taxation/Domain/Services/TaxResolutionService.php:51`) does the same for tax category lookups, per product line. `FinanceSummaryService::generate()` (`app/Modules/Accounting/Application/Services/Reports/FinanceSummaryService.php:60-81`) and `DashboardController::stats()` (`app/Modules/Dashboard/Presentation/Controllers/DashboardController.php:52-122`) run 3 and 9 raw queries respectively against `journal_entries`/`documents`/`partners` with zero caching, two of them unbounded `COUNT(*)`.

**Blast radius.** Every line of every document, every POS sale line, and every dashboard load pays this. Unlike B-7/B-8 (which need table growth to hurt), this cluster hurts from day one and gets linearly worse as request volume (tenants × users) grows, because none of it is amortized across requests — it's recomputed from scratch every time, on tables (`companies`, `countries`, `product_categories`) that almost never change. This is the highest-frequency, lowest-effort-to-fix cluster in the whole audit — these are exactly the "settings/tax/currency-scale lookups repeated per request" the audit asked about, and none of them are cached, in contrast to the module-config lookup (B-10 done-well) which is.

**Tenant-#1 flows hit:** Catalog/Pricing (tax resolution on every product), Treasury/Accounting (currency scale on every money boundary), and the dashboard landing screen every user sees first.

### B-10 — Aggregate fan-out is a systemic pattern, not a one-off (P1)

**Mechanism.** `FiltersAndSorts::calculateAggregates()` (`app/Support/Traits/FiltersAndSorts.php:227-284`) clones the filtered query once per configured aggregate key and runs a separate `count()`/`sum()`/`avg()` for each. `ProductController` (`:288-298`) configures 3 keys → 3 extra queries; `PartnerController` (`:122-136`) configures 4 → 4 extra. Combined with `paginate()`'s own `COUNT(*)`, that's **4-5 aggregate-class queries on the full filtered table, per list page load**, independent of which page or how many rows are actually returned. The same trait is shared by `ContactController`, `CertificationController`, `HealthClaimController`, `IngredientController`, `KeyComponentController` — so this cost scales with how many "stat card" endpoints exist, not with any one table.

**Blast radius.** Every keystroke-driven search on Products/Partners (the frontend debounces search per `01-census.md` D6, but each debounced fetch still re-runs this) re-executes the full aggregate set against the growing `products`/`partners` tables. Catalog and Purchasing (partner/supplier lists) are both tenant-#1 flows.

---

## Done well

- **`CompanyConfigService::getConfigForTenant()`** correctly uses Stancl's `GlobalCache::remember()` (not the tenant-tagged `Cache` facade) with a 24h TTL, with a documented rationale for why `GlobalCache` specifically avoids the tag-invalidation gap — `app/Services/CompanyConfigService.php:21-30,52`. This is the template the B-18/B-19/B-21 cluster should follow.
- **`ProductController::index`** enforces a sort-column allow-list (`getAllowedSortColumns()`, `ProductController.php:213`) via the shared trait (`FiltersAndSorts.php:32`), and batch-resolves product media in one query for the whole page with an explicit "no N+1" comment (`ProductController.php:139-142`).
- **`FiltersAndSorts`**'s search filter properly escapes LIKE wildcards (`addcslashes($searchTerm, '%_\\')`, `FiltersAndSorts.php:164`) — applied to Products, Partners, Contacts, and the Product sub-entity controllers.
- **`ReceiptController::index`** (POS) is the best-in-class list endpoint in the codebase: FormRequest-validated `per_page` capped at 100, column-limited eager loads (no line-items/JSONB in the list payload), properly escaped LIKE with an explicit `ESCAPE '\\'` clause, and `Gate::authorize()` plus location scoping — `app/Modules/POS/Presentation/Controllers/ReceiptController.php:78,84-102,87-93,117-118,189-190`.
- **`StockTransferController::index`** clamps `per_page` to 1-100 (`app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:77-78`) and eager-loads nested relations (`lines.product.unitOfMeasure`, `lines.batchAllocations.batch`) with location-based access scoping — a solid Inventory/transfers example.
- **`StockLevelController::index`** deliberately batches lot-presence into one query for the whole page with a "not one EXISTS per row" comment (`StockLevelData::lotPresenceMapFor`) instead of the naive per-row check.
- **`PartnerData::fromModel`** guards every relation access with `relationLoaded()` and falls back to null/empty instead of lazy-loading — a good general N+1-avoidance pattern (`app/Modules/Partner/Application/DTOs/PartnerData.php:114-127`).
- **No mass-assignment pattern found anywhere**: zero occurrences of `::create($request->all())` / `->fill($request->all())` / `->update($request->all())`, and zero models use `protected $guarded = []` (all explicit `$fillable`).
- **Transaction hygiene on the POS receipt-creation hot path**: `ReceiptCreationService::createReceipt` wraps the write in one `DB::transaction` and deliberately creates the receipt in `pending_seal` state, deferring the fiscal hash-chain sealing to a separate `ReceiptFinalizationService::finalize()` call **outside** that transaction — keeping the row-lock critical section short (`app/Modules/POS/Application/Services/ReceiptCreationService.php:142,62-66,554-555`).
- **Idempotency**: Treasury's `PaymentController` resolves and checks an idempotency key **before** validation/write, so a retry returns the original row untouched (`app/Modules/Treasury/Presentation/Controllers/PaymentController.php:353-364`); the live fiscal-event ingestion path carries an `idempotency_key` in its wire envelope by design (`app/Modules/Fiscal/Presentation/Controllers/FiscalEventIngestionController.php:21`); shift-close is idempotent-by-state rather than needing a key (`app/Modules/POS/Presentation/Controllers/SyncController.php:104-138`).
- **No synchronous Mail/PDF generation found in any controller** (grep across every `Presentation/Controllers` directory for `Mail::to(...)->send(`, `->send(new `, and Pdf/dompdf instantiation returned zero hits).
- **`TrialBalanceService`, `ProfitLossService`, `BalanceSheetService`, `SalesReportService`** all use SQL-side `SUM()`/`GROUP BY`/`selectRaw` aggregation, not PHP-side loops — genuinely good pattern (contrast with B-21/B-22/B-23), and `DashboardController` itself documents why it sums via SQL cast-to-text rather than `pluck()`-and-sum in PHP (`DashboardController.php:44-49`) even though it fails to cache the result.
- **Deploy caching**: `docker/entrypoint.sh:214-224` runs `config:cache`, `route:cache`, `view:cache` at container boot (not just build time), degrading gracefully rather than crashing on failure.
- **Tenant status enforcement at request time, not just login**: `ResolveTenancy.php:69-76` rejects Suspended/Archived tenants on every request, closing the "token minted while active, org suspended mid-session" gap.
- **`CentralPersonalAccessToken`** is deliberately pinned to the central connection (`Sanctum::usePersonalAccessTokenModel`, `app/Providers/AppServiceProvider.php:194`) with a documented rationale for why the tokenable relation must NOT inherit that connection — correct under DB-per-tenant, even though it doesn't eliminate the double-lookup in B-1.

---

## Not verified

- **Lock ordering across the 40+ `lockForUpdate` call sites** in Inventory/Treasury/POS/Accounting was not exhaustively diffed for cross-aggregate conflicts (e.g. one path locking StockLevel-then-PaymentRepository vs. another locking the reverse) — the one plausible cross-module bridge checked (`TreasuryReceiptBridge.php:133`) turned out to be a docblock, not live code, but this is not a systematic clearance.
- **Whether `QueueTenancyBootstrapper` reliably restores tenant context for `ShouldQueue` event *listeners*** (as opposed to explicitly dispatched jobs) — the framework-level bootstrapper is confirmed registered (`config/tenancy.php:38-42`), but the 17 listeners/notifications lacking the explicit `BindsTenantContext` safety net (B-17) were not traced end-to-end through a queue-worker execution.
- **Whether services (not just controllers) synchronously call a mailer or PDF renderer mid-request** — only controllers were grepped for this; service-layer call sites were not exhaustively checked.
- **Index coverage on `pos_receipt_lines.receipt_id`** for the correlated subquery in `LiveSalesReportService.php:74` (B-26).
- **Actual production/staging environment values** for `CACHE_STORE` and `tenancy_resolver.db_per_tenant` — confirmed from `.env`/`.env.example` in the repo, not read live from Dokploy; if a deployed environment diverges from the repo default, B-2/B-6/B-20's severity shifts accordingly.
- **Every module controller beyond the 7 named growing-table families** for the same unbounded-default-`->get()` idiom found in B-7/B-9 — the two instances found share an identical shape (`if (!$request->has('page')) { ...->get(); }` / uncapped legacy `limit`), suggesting more may exist outside this audit's named scope.
- **FormRequest validation completeness** for the ~30 raw-`Request` write actions in B-16 — only one (`PaymentController.php:365`) was opened and confirmed to validate inline; the rest are cited from a grep pass, not individually opened.

---

*No application files were modified to produce this audit.*
