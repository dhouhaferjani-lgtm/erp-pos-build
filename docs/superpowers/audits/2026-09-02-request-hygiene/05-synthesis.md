# Request hygiene & scale audit — synthesis (2026-09-02)

**Scope:** how AutoERP (apps/api Laravel 12, apps/web React + TanStack Query 5, apps/pos Tauri) shapes, validates, caches and rate-limits HTTP requests, and where that breaks as tenants × users grow. **Audit only — no fixes made.**

**Lanes (all read-only, all citing `path:line`):**

| Lane | Model | File |
|---|---|---|
| Research baseline (Odoo / ERPNext / Laravel / TanStack benchmarks + budgets) | Sonnet + web | `00-research-baseline.md` |
| Mechanical census (raw counts, commands included) | Haiku | `01-census.md` |
| Backend request path audit (29 findings B-1..B-29) | Sonnet | `02-backend-audit.md` |
| Frontend + POS request pattern audit (15 findings F-1..F-15) | Sonnet | `03-frontend-audit.md` |
| Independent adversarial audit (28 findings RH-01..RH-28) | Codex CLI, gpt-5 high effort, read-only sandbox | `04-codex-independent-audit.md` |
| Idempotency inventory (605 routes, jobs, forms; I-1..I-12) — added 2026-09-03 | Sonnet | `06-idempotency-inventory.md` |
| Idempotency adversarial audit (I-01..I-24) — added 2026-09-03 | Codex CLI, high effort, read-only | `07-codex-idempotency-audit.md` |
| Idempotency synthesis (ID-1..ID-24) — added 2026-09-03 | Fable | `08-idempotency-synthesis.md` |

**Orchestrator verification (Fable):** I re-ran the census greps with portable syntax, opened every file behind the P0s below, and re-read the Stancl `CacheManager` / Spatie `PermissionRegistrar` vendor code for the permission-cache finding. Lane claims I could not confirm are marked *disputed* or *unverified*; lane claims that were wrong are listed in §6.

---

## 1. Verdict

The codebase is **not** in the "no validation, no caching, request per field" state the question feared. Writes go through FormRequests almost everywhere, the POS checkout is offline-first with idempotency keys, module/vertical config is cached tenant-safely, and the React Query defaults are sane. The scale risk is concentrated in **six specific mechanisms**, three of which are correctness bugs rather than performance debt:

| # | Mechanism | Severity | Why it matters at 10 tenants × 10 users |
|---|---|---|---|
| 1 | **Spatie permission cache is one global key across all tenant DBs** (B-6; known since 2026-07, still open) | **P0 correctness** | Tenant A's request rebuilds the registry from A's DB; tenant B then evaluates `can()` against it. Divergent permission tables (staggered seeders, module upgrades) produce ghost/missing permissions cross-tenant. Also: any role edit anywhere flushes everyone. |
| 2 | **Unbounded reads on growing tables** — stock movements & payments when `?page` is omitted, audit range with no limit, credit notes/channel orders never paginated, `/products` per_page cap 2000, dashboard full-table `COUNT(*)` (B-7, B-8, B-11, B-22, RH-08, RH-09) | **P0** | Response size and memory grow with tenant age. A single "last quarter" audit request or a client that forgets `page` pulls the entire history. |
| 3 | **The general `api` rate limiter is dead code** — defined at `AppServiceProvider.php:359` but never attached; reports, exports, imports, search, bulk ops and fiscal ingest have no throttle (B-29, RH-06) | **P1** | One buggy poll loop or retry storm from one tenant has no ceiling on shared PHP-FPM / PostgreSQL / Horizon capacity. |
| 4 | **Fixed per-request query tax of 7–9 DB ops before the controller**, incl. an unthrottled `UPDATE personal_access_tokens.last_used_at` write on the shared central DB and an uncached `pg_database` catalog probe on every request (B-1..B-5, RH-21) | **P1** | Multiplies by every request the frontend fan-out generates. Flat, but it is exactly the tenants × users × requests axis. |
| 5 | **List DTOs do per-row work** — invoice/PO index ≥6–8 queries per row via `DocumentData` (outstanding, payment status, fulfilment), products/partners lists pay 3–5 extra aggregate queries per page, `CurrencyScaleResolver::getScale()` and tax-category resolution re-query per line (RH-02, B-10, B-18, B-19, RH-15) | **P1** | A 25-row invoice page can exceed 200 statements. `getScale()` is mandatory on every money boundary (rule 19), so a 20-line document can trigger ~40 reference-table reads. |
| 6 | **Frontend keystroke and per-row fan-out** — line-entry product search with no debounce, bulk pricing POST re-fired on every unit-price keystroke, stock-transfer grid fires 2 requests per row, WebSocket reconnect invalidates every query app-wide, product edit page grows with location count (F-1..F-4, RH-12..RH-14) | **P0 load / P1** | Each of these turns one user action into N API calls, each paying mechanism 4. A network flap across many clients becomes a synchronized DB burst. |

Two further **concurrency** findings from Codex sit outside "request hygiene" but were surfaced by it and deserve their own lane: fiscal posting refreshes the target document without a row lock (genesis-chain race, RH-01), and stock-transfer numbering uses `COUNT()+1` under a unique index (RH-03), with the whole transfer processed inside one long transaction holding stock locks line by line (RH-04).

---

## 2. Measured vs. budget

Budgets are from `00-research-baseline.md` (Odoo opens a form in 1 request, ERPNext in 1–2; Laravel guidance is "queries flat, not growing with N").

| Guarantee | Budget | AutoERP measured (static, lower bound) | Evidence |
|---|---|---|---|
| Requests to open a create form | ≤ 2 | Quote/PO: 0 on cold mount, **5–6** by first line; product create **3** (+1 loyalty); stock transfer **2** cold, **7** after first line (~4N per line) | Codex table; F fan-out table |
| Requests to open an edit form | ≤ 2 | Product edit **6 base, 8 + L locations** (one placement-nodes call per active location) | `ProductPlacementFields.tsx:84-95` |
| Requests to open a list page | ≤ 2 | Documents/products: 1 list + boot; but backend pays 4–5 aggregate queries per page and per-row DTO queries | B-10, RH-02 |
| DB queries per API request | ≤ 15 list / ≤ 25 form-init, flat | Fixed tax **7–9** before controller; invoice list **≥ 8 per row** | `02-backend-audit.md` §overhead trace |
| Default / max page size | 25 / 100 | Mixed: receipts 100 cap (good), products **2000**, audit/credit notes/channel **unbounded**, stock movements/payments unbounded when `page` omitted | B-7, B-11, RH-08 |
| Rate limiting | named limiters per surface, keyed (tenant,user) | ~13 narrow limiters (auth, uploads, media, webhooks, POS activation); **no general API limiter attached** | B-29 |
| Idempotency on POS/offline writes | 100% | Present: POS receipts (caller key), stock transfers, fiscal ingest conflict handling | Codex "done well" |
| Reference-data staleTime | 5–30 min | Global 5 min, no accidental `staleTime: 0`; but reference data is fetched under **6+ divergent keys** for locations, 2 for units | F-6, F-7, RH-24 |
| `placeholderData` on paginated lists | all | **1 usage app-wide** | F-10 |
| Conditional GET / ETag | on reference endpoints | none anywhere; only media sets Cache-Control | B-27, RH-20 |
| Lazy-load guard in non-prod | `preventLazyLoading` | absent | census C5 |
| Boot waterfall (web) | ≤ 3 stages | 3 stages (auth → companies → config ∥ locations) — **within budget** | F boot table |
| POS cold sync | parallel, delta | **~12 sequential pulls**, 17–18 pull calls per minute per terminal, fiscal events pushed 1 per POST | RH-05, F-14, F-15 |

---

## 3. Consolidated findings (deduplicated across lanes, ranked)

Severity: P0 = breaks/times out at 10×10 or cross-tenant correctness; P1 = degrades noticeably; P2 = hygiene debt; P3 = nit. IDs reference the lane files.

### P0

| ID | Finding | Evidence | Lanes |
|---|---|---|---|
| S-1 | Spatie permission cache global across tenants; `PermissionRegistrar` obtains the store via `CacheManager::store()`, which bypasses Stancl's `__call`-based tenant tagging; no `TenancyInitialized` listener rescopes it. Known bug (memory `project_spatie_permission_cache_tenant_blind`, 2026-07), unfixed. | `config/permission.php:192,200`; `vendor/spatie/laravel-permission/src/PermissionRegistrar.php:79-98,221`; `vendor/stancl/tenancy/src/CacheManager.php:18-35`; `app/Providers/TenancyServiceProvider.php:49-60` | B-6 (+ orchestrator verification) |
| S-2 | Stock movements index returns the **entire** history when `page` is absent (4 eager relations); same pattern on payments. | `StockMovementController.php:70-74`; `PaymentController.php:295-305` | B-7, RH-08 |
| S-3 | Audit trail range query has no `limit()`, no max span, returns full `payload`/`metadata` JSONB per row, and no pagination metadata. | `AuditService.php:131-144`; `AuditController.php:53-79` | B-8, B-12, B-15, RH-09 |
| S-4 | Line-entry product search fires `GET /products?search=` on every keystroke (no debounce; the three sibling pickers debounce at 250 ms). Used on every document/PO/counting line add. | `LineItemEntryBar.tsx:70-80,244` | F-1, RH-18 |
| S-5 | Bulk pricing-context POST re-sends the whole line array on every unit-price keystroke while a price field is focused (unit price is in the query key). | `DocumentLineEditor.tsx:340-397` | F-2 |
| S-6 | Stock-transfer create fires stock-levels and variants **per grid row**, the stock-levels call under two different keys. | `CreateStockTransferPage.tsx:415-431,459-464`; `TransferSourceSuggestion.tsx:13-20` | F-3, RH-24 |
| S-7 | WebSocket reconnect provider calls `invalidateQueries()` with no key, mounted around every authenticated page. | `WebSocketReconnectProvider.tsx:29`; `DashboardLayout.tsx:47` | F-4, RH-22 |
| S-8 | Fiscal document posting refreshes the target without `lockForUpdate()`; predecessor is locked but a genesis posting has no predecessor; code notes no unique `(company_id,type,chain_sequence)` index. *Concurrency, not request hygiene; unproven under test.* | `DocumentPostingService.php:110-124,658-678` | RH-01 |

### P1

| ID | Finding | Evidence | Lanes |
|---|---|---|---|
| S-9 | General `api` rate limiter defined but never attached; reports, exports, imports, search, bulk, fiscal ingest, enrichment webhook unthrottled. | `AppServiceProvider.php:358-364`; `bootstrap/app.php:132-152`; module routes | B-29, RH-06, RH-17 |
| S-10 | Per-request fixed tax: bearer token looked up twice; tenant row up to 3×; `pg_database` probe every request; Sanctum `last_used_at` UPDATE every request on central DB; `CompanyContext::requireCompany()` uncached and sometimes called twice. | `ResolveTenancy.php:63,122`; `TenancyResolver.php:90`; `vendor/laravel/sanctum/src/Guard.php:40-60,162-173`; `RequireModule.php:52`; `CompanyContext.php:104` | B-1..B-5, RH-21 |
| S-11 | Document list DTO does ≥6 (PO) / ≥8 (invoice) queries per row; fulfilment grows with lines × delivery notes. | `InvoiceController.php:205-211`; `PurchaseOrderController.php:256-264`; `DocumentData.php:150-152,201-246`; `Document.php:955-993,1026-1060` | RH-02 |
| S-12 | `FiltersAndSorts` aggregate config adds 3–5 uncached COUNT/SUM/AVG queries per products/partners list page. | `FiltersAndSorts.php:227-284`; `ProductController.php:288-298`; `PartnerController.php:122-136` | B-10 |
| S-13 | `CurrencyScaleResolver::getScale()` runs 2 uncached queries per call; tax resolution does `$product->category()->first()` per line; draft autosave resolves each variant separately every 3 s. | `CurrencyScaleResolver.php:43,57`; `TaxResolutionService.php:51`; `DraftPersistenceService.php:694-732` | B-18, B-19, RH-15 |
| S-14 | Products list: per_page cap 2000, full model + up to 8 relation families + detail-grade DTO for a grid that renders a handful of columns; document list `select *` incl. JSON payload with a ~50-field DTO. | `ProductController.php:104-170`; `ProductData.php:24-134`; `DocumentController.php:117-148`; `DocumentData.php:24-101` | B-11, RH-11, RH-28 |
| S-15 | Dashboard: 9 uncached queries incl. 2 unbounded `COUNT(*)`; finance summary runs P&L twice + balance sheet uncached per load; POS broken-chain report reloads entire terminal history into PHP. | `DashboardController.php:52-122`; `FinanceSummaryService.php:60-81`; `POS/…/ReportController.php:479-497` | B-21..B-23 |
| S-16 | `CACHE_STORE` defaults to `database`: Stancl auto-tags every cache call and the database store does not support tags; cache table exists only in the central migration set. `.env.example`/prod example set Redis, so this is a fail-open default, not a live bug. | `config/cache.php:18,42-47`; `config/tenancy.php:38-44`; `migrations/0001_01_01_000001_create_cache_table.php` | RH-07 |
| S-17 | Cache tenant-scoping is 100% implicit (tag wrapper only while `tenancy()->initialized`); no key embeds a tenant id; 17 of 32 `ShouldQueue` classes lack `BindsTenantContext` and rely on the queue bootstrapper. | `config/tenancy.php:40,89-100`; e.g. `BarcodeLookupService.php:49`, `CatalogBrowseService.php:315` | B-17, B-20 |
| S-18 | Request bodies unbounded in item count: document/autosave lines, transfer lines + allocations, fiscal envelopes (count capped 100, bytes not); nginx accepts 64 MB. Inventory counting accepts arbitrary `sort_by`/`sort_dir`/`per_page`. | `AutoSaveDraftRequest.php:194-218`; `StoreStockTransferRequest.php:83-107`; `IngestFiscalEventsRequest.php:45-55`; `nginx.conf:47-49`; `InventoryCountingController.php:229-245` | RH-10, RH-16 |
| S-19 | Stock transfer: `COUNT()+1` numbering under a unique index (collision under concurrency) and one long transaction holding stock/batch locks across all lines. | `StockTransferService.php:103-187,480-556,1095-1104` | RH-03, RH-04 |
| S-20 | POS sync: 17–18 pull calls per minute per terminal, ~12 executed sequentially at cold boot, fiscal events pushed one per POST (endpoint accepts 100), one ingest transaction per envelope. | `syncScheduler.ts:27-35`; `syncService.ts:414-440,2317-2330`; `FiscalEventIngestionController.php:125-141` | RH-05, F-14, F-15 |
| S-21 | Treasury payment form loads the full unpaginated `/partners` list into a native `<select>`; a paginated `PartnerPicker` already exists. | `PaymentForm.tsx:453-459,906` | F-5 |
| S-22 | Locations reference data fetched under 6+ divergent query keys instead of the boot-time `LocationProvider`; product edit adds one placement-nodes call per location; product picker triggers up to 20 signed-media GETs per open. | `LocationProvider.tsx:34` + 5 feature hooks; `ProductPlacementFields.tsx:84-95`; `ProductCell.tsx:33-40` | F-6, RH-12, RH-13 |
| S-23 | Sanctum: back-office tokens `*` abilities for 30 days, POS tokens 12 months, ability middleware rarely used. | `config/sanctum.php:43-53`; `AuthController.php:98-175,294-310` | RH-26 |

### P2

| ID | Finding | Evidence | Lanes |
|---|---|---|---|
| S-24 | Search `LIKE` not wildcard-escaped in documents, categories, loyalty, payments (receipts and `FiltersAndSorts` escape correctly). Parameter-bound, so not injection; enables match-all and index-hostile scans. | `DocumentController.php:89-90`; `CategoryController.php:44`; `LoyaltyMemberController.php:50-59`; `HandlesDocuments.php:175-217`; `PaymentController.php:283-292` | B-13, B-14, RH-18 |
| S-25 | No ETag / conditional GET anywhere; reference endpoints include a fresh timestamp. `route:cache` failure only warns at container boot; no `event:cache`. | `docker/entrypoint.sh:214-220`; `LocationController.php:87-99`; `UomController.php:125-143` | B-27, B-28, RH-20 |
| S-26 | Supplier-invoice create makes 2K `useQueries` calls for K selected POs; replenishment dialog one stock call per product. | `supplier-invoices/api.ts:221-298`; `CreateTransferDialog.tsx:37-49` | RH-14 |
| S-27 | Global `retry: 1` with no 4xx exclusion; `apiGet`/`apiPost` accept no `AbortSignal`, so nothing cancels on navigation; `placeholderData` used once app-wide. | `lib/queryClient.ts:7`; `lib/api.ts:381-389` | F-8, F-9, F-10, RH-25 |
| S-28 | Duplicate `useUnits` hook with mismatched key; `LocationProvider` key hand-built without `tenantScopedKey`; `useProductVariants` lacks tenant/company guard. | `uom/hooks/useUnits.ts:119-127` vs `workshop-bundles/hooks/useUnits.ts:24-39`; `LocationProvider.tsx:34`; `useProductVariants.ts:10-15` | F-7, F-13, RH-24 |
| S-29 | Forms outside RHF+zod: Users/Roles/Company/Login/Register hand-rolled; Product/Document/Payment/Vehicle/Service use RHF without `zodResolver` (convention 06). | listed in `03-frontend-audit.md` F-11 | F-11 |
| S-30 | Polling: web-POS 5 concurrent pollers (10–30 s), counting 2/min, owner live sales 4/min; all pause on hidden tab. | `POSLayout.tsx:46-60`; `useOrders.ts:57-65`; `inventory-counting/api/queries.ts:41-49`; `useOwnerReports.ts:126-136` | F-12, RH-23 |
| S-31 | Report date ranges validated for order but not max span (decade-wide P&L allowed); aged receivables N+1 on historical credit notes; live-sales correlated subquery index unverified. | `GetProfitLossRequest.php:16-24`; `AgedReceivablesService.php:186-241`; `LiveSalesReportService.php:74` | B-24..B-26 |
| S-32 | `$guarded = []` on 10 models (Treasury statements/remittances etc.); Product/Document `fillable` include company/fiscal/cost/status fields. No hot route passes `$request->all()` into `fill`, so latent, not exploitable. | `BankStatement.php:34-43`; `InstrumentRemittance.php:38-47`; `Product.php:92-131`; `Document.php:125-169` | RH-27, census A3 |
| S-33 | Legacy `?limit=` path on documents index is uncapped. | `DocumentController.php:104-110` | B-9 |

---

## 4. Which tenant-#1 flows are hit (POS + purchasing + catalog + inventory + transfers + treasury)

| Flow | Findings that land on it |
|---|---|
| POS terminal fleet | S-1 (permissions), S-10 (per-request tax × 17 pulls/min), S-20 (sync shape), S-15 (chain report), S-23 (12-month tokens) |
| Purchasing (PO create → receipt → supplier invoice) | S-4, S-5 (line entry), S-11 (PO list per-row), S-13 (scale/tax per line), S-18 (unbounded lines), S-26 (2K supplier-invoice calls) |
| Catalog (product create/edit/list) | S-14 (2000-row detail DTO), S-12 (aggregate queries), S-22 (8+L on edit, 20 media GETs per picker) |
| Inventory (movements, counting) | S-2 (unbounded movements), S-18 (counting sort/per_page), S-30 (counting poll) |
| Transfers | S-6 (2 per row), S-19 (numbering race + long tx) |
| Treasury | S-2 (payments unbounded), S-21 (full partners list), S-32 (`$guarded=[]`) |
| Everything | S-7 (reconnect refetch storm), S-9 (no throttle), S-16/S-17 (cache topology) |

---

## 5. Done well (all lanes agree, evidence in lane files)

- **Write validation is essentially complete.** 263 FormRequest classes; the 23 controllers with a raw-`Request` write verb and no validation are all `destroy` handlers (no body) plus one Fiscal quarantine parse. Autosave has a dedicated FormRequest with scoped IDs and decimal ceilings.
- **POS checkout is offline-first and atomic**: caller idempotency keys checked before mutation, receipt + fiscal chain + vouchers in one SQLite transaction, sync only after commit; server ingest has DB conflict handling and after-commit projection jobs; delta cursors and exponential backoff on sync.
- **Stock transfers** carry an idempotency key, tenant/company uniqueness and after-commit domain events.
- **Tenant-safe caching where it was designed**: `CompanyConfigService` / `VerticalConfigService` via `GlobalCache`, 24 h TTL with explicit invalidation; product media batch-resolved on list pages; journal entries always paginated (20) with eager `lines.account`; receipts list caps per_page, escapes `LIKE`, column-limits eager loads; product sorting allow-listed.
- **Targeted throttles** on login/register/reset, document extraction/email, image uploads, signed media, channel webhooks, POS activation.
- **Frontend defaults** are sane (5 min staleTime, `refetchOnWindowFocus: false`, mutations never retried); 3 of 4 pickers debounce; pricing context is batched per document, not per line; boot is a bounded 3-stage waterfall; no accidental `staleTime: 0` on reference data (the one zero is a documented concurrency requirement on stock adjustments).
- **Uploads** validate MIME and size; media served via signed URLs with 1 h browser cache, never base64 in grid JSON.
- **Deploy** runs `config:cache`/`route:cache`/`view:cache` at container boot; production env example pins Redis for cache/session/queue.

---

## 6. Corrections to lane claims (so nobody quotes the wrong number)

| Lane claim | Reality (orchestrator verified) |
|---|---|
| Census: "607 controller methods accept raw `Request` — violates validation-first rule" | 520 of 1040 public controller methods take raw `Request`; they are overwhelmingly `index`/`show`/`destroy`. Create/update bodies use FormRequests. The real gap is **query-param** validation on index endpoints (sort, per_page), not body validation. |
| Census: "9% pagination rate, 91% load full result sets" | Raw ratio of `->get()` (601) to `paginate` (83) across all services, not an endpoint measure. Many `get()`s are bounded lookups. The unbounded **endpoints** are the specific ones in S-2, S-3, S-14, S-33. |
| Census: "0 idempotency handling in POS sync" | Wrong. POS receipts carry caller idempotency keys checked before mutation; stock transfers have an idempotency key; fiscal ingest handles conflicts. What is missing is a generic `Idempotency-Key` middleware on non-POS mutations. |
| Backend B-6 "one global permission key" | **Confirmed and upgraded to P0** (cross-tenant correctness), see S-1. Known since 2026-07-03, never fixed. |
| Backend B-16 "~30 raw-Request write actions" | Spot-check holds for `index`-style filters; the write-body cases resolve to `destroy` handlers (see above). Downgrade to P3 convention gap. |
| Codex RH-07 "database cache store incompatible with tagging" | Correct as a fail-open default; staging/prod env files set Redis. Keep as P1 config hardening, not a live outage. |
| Codex "5 calls to open a quote" vs frontend "0 on cold mount" | Both true: 0 page-specific calls on mount, 5–6 by the time the first line is priced. |

---

## 7. Not verified (needs runtime measurement, out of scope for a static audit)

- Actual query counts per request on staging (`DB::listen` / Telescope) — every count above is a source-derived lower bound.
- Browser waterfalls under real data volumes (locations = 30, lines = 50).
- Whether `QueueTenancyBootstrapper` reliably restores tenant context for `ShouldQueue` **listeners** (vs jobs) — the CLAUDE.md rule-20 incident class.
- Index coverage on `pos_receipt_lines.receipt_id` and the audit/stock-movement sort columns.
- Live `CACHE_STORE`, `CACHE_PREFIX`, PHP-FPM worker count, pgbouncer presence, Redis topology on staging/AX42.
- RH-01 (posting genesis race) and RH-03 (transfer numbering race) under a concurrency test.

---

## 8. Suggested lane cut — superseded by `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` (Phase A tasks 1-14 + Phase B roster B-1..B-11)

1. **S-1** permission cache per tenant (correctness, tenant-#1 blocker; pairs with the existing memory note).
2. **S-2 + S-3 + S-14 + S-33** mandatory pagination + caps + list projections on growing tables.
3. **S-9** attach a general limiter and cost-classed limiters (reports/exports/imports/sync).
4. **S-4 + S-5 + S-6 + S-7** the four frontend fan-out P0s (each is a few lines: debounce, key by focused-line only, bulk stock-levels endpoint, keyed invalidation).
5. **S-10 + S-13** request-scoped memoisation (tenant, company, scale, tax category) and Sanctum `last_used_at` debounce.
6. **S-11 + S-12** list-specific DTO/projection for documents, products, partners.
7. **S-19 + S-8** concurrency lane (sequence allocation, lock ordering, posting lock) — own reviewer (fiscal-pos + inventory-costing).
8. **S-20** POS sync batching/jitter/parallel cold pull.
9. **S-16 + S-17 + S-25** cache topology hardening (explicit store validation, tenant id in keys, ETags on reference endpoints, `route:cache` fatal).
10. Guards so it does not regress: `Model::preventLazyLoading()` outside prod, a query-count assertion helper for endpoint tests, a per_page-max ratchet, a "no `useQuery` inside grid row components" ESLint rule.
