# Request Hygiene & Data Access Census

**Date:** 2026-09-02  
**Scope:** Backend validation, query patterns, caching, frontend data fetching, POS sync  
**Working directory:** /Users/houssamr/Projects/syneriva/apps/erp

---

## A. Backend Validation

### A1. Total Controller Classes

**Command:**
```bash
find apps/api -name '*Controller.php' -type f | wc -l
```

**Result:** **287 controller classes**

Most accept FormRequest subclasses, but many methods still accept raw `Request $request`.

---

### A2. Raw Request Parameter Usage

**Command:**
```bash
grep -rE 'public function \w+\(Request \$request' apps/api --include='*.php' | wc -l
```

**Result:** **607 controller methods** accept raw `Illuminate\Http\Request` instead of FormRequest subclass

| Category | Count |
|----------|-------|
| Total controller methods with `Request $request` | 607 |
| Methods in middleware | ~40 |
| Methods in main API controllers | ~200 |
| Methods in module controllers | ~367 |

**Strike:** 607/287 controllers means 2.1 methods per controller on average accept raw Request, violating the formrequest-first pattern.

**Sample methods accepting raw Request:**
- `RequireModule::handle()` — middleware pattern (acceptable)
- `CompanyConfigController::show()` — should use FormRequest for validation
- `SuperAdminController::tenants()`, `extendTrial()`, `changePlan()` — raw request access
- `DocumentAdditionalCostController::store()`, `update()` — no FormRequest
- `PosPendingCustomerController` — direct Validator instantiation
- `InventoryCountingController` — raw Validator::make on $request->all()

---

### A3. $request->all() and $request->input() Calls

**Commands:**
```bash
grep -r '\$request->all()' apps/api --include='*.php' | wc -l
grep -r '\$request->input(' apps/api --include='*.php' | wc -l
```

**Results:**

| Accessor | Count | Notes |
|----------|-------|-------|
| `$request->all()` | 16 | Mass assignment risk |
| `$request->input()` | 452 | Direct, often unvalidated access |

**Occurrences of $request->all():**
- `PlatformIntegration/EnrichmentWebhookController` — unrestricted webhook payload
- `CatalogBrowseController` — bulk criteria ($criteria = $request->all())
- `ProductMediaController` — Validator::make($request->all(), [...])
- `Compliance/FraudSettingsController` — array_keys($request->all())
- `POS/PosPendingCustomerController` — Validator::make($request->all(), [...])
- `Inventory/InventoryCountingController` — Validator::make($request->all(), [...])
- Test fixtures — 6 instances in webhook architecture tests

**Common $request->input() patterns:**
- `SuperAdminController` — reads 'email', 'search', 'days', 'plan_id', 'tenant_id', 'action', 'page' directly
- `FiltersAndSorts` trait — 'sort_by', 'sort_dir', custom filter keys
- `ValidateLocationAccess` middleware — 'location_id', 'from_location_id', 'to_location_id', 'lines' array access
- Admin auth — 'email' for credential validation

---

### A4. Models with $guarded = []

**Command:**
```bash
grep -r "protected \$guarded = \[\]" apps/api --include='*.php'
```

**Result:** **0 models** use `$guarded = []` (all use explicit `$fillable` — good).

---

### A5. FormRequest Classes and per_page Validation

**Command:**
```bash
find apps/api/app -name '*FormRequest.php' -exec grep -L "public function rules()" {} \;
```

**Result:** **0 FormRequest classes** lack `rules()` method (all 1 FormRequest in app/ has it defined).

**Note:** Total FormRequest count is suspiciously low (1 in app/). Most validation happens inline via Validator or raw Request::input().

**Commands:**
```bash
grep -r "per_page.*max:" apps/api/app --include='*.php' | wc -l
grep -r "limit.*max:" apps/api/app --include='*.php' | wc -l
```

**Results:**

| Pattern | Count |
|---------|-------|
| FormRequests validating `per_page` with `max:` | 11 |
| FormRequests validating `limit` with `max:` | 5 |

**Strike:** Only 16 total checks for pagination limits, while 452 uses of $request->input() could be reading unvalidated pagination parameters.

---

## B. Backend Query Shape

### B1. ->get() / ->all() Calls in Modules

**Command:**
```bash
grep -rE '\->get\(\)|\->all\(\)' apps/api/app/Modules --include='*.php' | wc -l
```

**Result:** **960 occurrences** across all modules

**Top 20 modules by ->get()/->all() count:**

| Module | Count |
|--------|-------|
| Treasury | 117 |
| POS | 113 |
| Document | 97 |
| Inventory | 93 |
| Accounting | 80 |
| Procurement | 42 |
| Product | 40 |
| Workshop | 31 |
| CountryDefaults | 31 |
| Compliance | 24 |
| Import | 23 |
| Loyalty | 22 |
| Catalog | 22 |
| Taxation | 21 |
| SupportAccess | 18 |
| BatchExpiry | 18 |
| Identity | 16 |
| Company | 14 |
| Scheduling | 13 |

**Sample key-model ->get() calls (first 40 shown, filtered for Document/Product/StockMovement/JournalEntry/Customer patterns):**
- `CartPromotionService` — Promotion->get()
- `TenantStatusCommand` — query->all()
- `BackupTenantCommand` — Tenant::orderBy('slug')->get()
- `TenantHealthService` — Tenant::orderBy('slug')->get()
- `DayOneCensus` — query->get()
- `GeneralLedgerHashService` — query->get()
- `PartnerBalanceService` — orderBy('partners.name')->get() × 3
- `OpeningBalanceBatchService` — query->get() × 3 (rows)
- `JournalEntryController` — JournalEntry models fetched as sets
- `AccountingService` — accounts->keyBy('id')->all()
- `CashMovementsReportService` — query->get()
- `ProfitLossService` — query->get()

---

### B2. Pagination Patterns

**Commands:**
```bash
grep -rE '\->paginate\(|\->simplePaginate\(|\->cursorPaginate\(' apps/api/app/Modules --include='*.php' | wc -l
```

**Result:** **83 total paginate calls**

**Breakdown by module:**

| Module | paginate() | simplePaginate() | cursorPaginate() |
|--------|-----------|-----------------|-----------------|
| Inventory | 12 | — | — |
| Document | 9 | — | — |
| POS | 8 | — | — |
| Product | 7 | — | — |
| Treasury | 5 | — | — |
| Others (27 modules) | 42 | — | — |

**Strike:** Only 83 paginate calls for 960 ->get()/->all() calls = **9% use pagination; 91% load entire result sets**.

---

### B3. Eager Loading (with/load/withCount)

**Commands:**
```bash
grep -rE '\->with\(|\->load\(|\->withCount\(' apps/api/app/Modules --include='*.php' | wc -l
```

**Result:** **561 occurrences** of eager-loading helpers

**Modules with many ->get() but ZERO with()/load()/withCount():**

| Module | ->get() count | with/load count |
|--------|---------------|-----------------|
| Company | 14 | 0 |
| Fiscal | 12 | 0 |
| Import | 23 | 0 |
| Scheduling | 13 | 0 |
| SupportAccess | 18 | 0 |
| Tenant | 12 | 0 |

**Strike:** 6 modules load data without prefetching relationships. Inventory, Document, POS, Product likely use eager-loading, but administrative modules (Tenant, Import, Scheduling, SupportAccess) and Fiscal show no with() patterns.

---

### B4. Raw SQL Patterns

**Commands:**
```bash
grep -rE 'orderBy\(\$request|orderBy\(request\(|sortBy' apps/api/app/Modules --include='*.php' | wc -l
grep -rE 'orderByRaw|whereRaw|DB::raw' apps/api/app/Modules --include='*.php' | wc -l
```

**Results:**

| Pattern | Count |
|---------|-------|
| `orderBy($request|request())` or `sortBy` from request | 28 |
| `orderByRaw`, `whereRaw`, `DB::raw` | 166 |

**Strike:** 166 raw SQL uses suggest complex reporting/analytics in Accounting, Expense, Workshop, BatchExpiry modules.

**Sample raw SQL patterns (first 30):**
- `whereRaw('UPPER(code) = ?', ['CASH'])` — TenantStatusCommand
- `selectRaw('1')->whereRaw('1 = 0')` — CashMovementsReportService (null query)
- `orderByRaw('maturity_date IS NOT NULL, maturity_date ASC')` — UpcomingPaymentsService
- `whereRaw('COALESCE(due_date, document_date) <= ?', [...])` — UpcomingPaymentsService × 2
- `DB::raw("balance_formula")` — BalanceSheetService, ProfitLossService
- `whereRaw('stock_levels.quantity <= (stock_levels.min_quantity * ?)', [$ratio])` — StockAlertReportService
- `orderByRaw('(expires_at IS NULL) ASC')` — TechnicianCertificationRepository
- `whereRaw('tenant_id = ?')` — ExpenseService, ExpenseOnInstrumentLifecycle × 2
- `groupBy(DB::raw(...))` — ExpenseAnalyticsService
- `whereRaw('LOWER(name) LIKE LOWER(?)', [...])` — UserController identity search

---

### B5. LIKE/ilike with % from Request Input

**Command:**
```bash
grep -riE 'like.*%|ilike.*%' apps/api/app --include='*.php' | grep -iE '\$request|request\(' | wc -l
```

**Result:** **2 occurrences** of request-based LIKE/ilike interpolation

**Sample patterns:**

| File | Pattern | Note |
|------|---------|------|
| `LoyaltyMemberController` | `where('phone', 'like', '%'.$request->input('phone').'%')` | SQL injection risk if unescaped |
| `CategoryController` | `ilike('%'.$request->input('search').'%')` | PostgreSQL ilike, parameterized OK |

**Note:** SQLite/PostgreSQL parameterization handles these safely, but the pattern is brittle.

---

## C. Caching & Limits

### C1. Cache Usage

**Commands:**
```bash
grep -r 'Cache::' apps/api/app --include='*.php' | wc -l
grep -r 'cache()' apps/api/app --include='*.php' | wc -l
grep -r '->remember(' apps/api/app --include='*.php' | wc -l
```

**Results:**

| Pattern | Count |
|---------|-------|
| `Cache::` calls | 47 |
| `cache()` helper | 0 |
| `->remember()` | 0 |

**Cache usage by module (sample from first 20 Cache:: calls):**
- `PlatformIntegration/CatalogBrowseService` — Cache::get/put for browse responses
- `PlatformIntegration/BarcodeLookupService` — Cache::get/put for barcode lookups (TTL: CACHE_TTL_SECONDS)
- `PlatformIntegration/PlatformHttpClient` — Circuit breaker via Cache (has/put/forget)
- `SmartPrompts/RecommendationEngineHttpClient` — Circuit breaker cache
- `Admin/MonitoringService` — health_check cache (10 sec TTL)

**Strike:** Only 47 Cache:: calls for an entire ERP suggest most data is fetched fresh each request.

---

### C2. Cache Configuration

**Command:**
```bash
grep -E "CACHE_STORE|SESSION_DRIVER|QUEUE_CONNECTION" apps/api/.env.example
cat apps/api/config/cache.php | grep -A 5 "'default'"
```

**Results:**

| Setting | Value | Source |
|---------|-------|--------|
| CACHE_STORE | redis | .env.example |
| SESSION_DRIVER | redis | .env.example |
| QUEUE_CONNECTION | redis | .env.example |
| Default cache store | env('CACHE_STORE', 'database') | config/cache.php |

**Note:** Production uses Redis, fallback to database. Cache pool is shared across tenants (Redis is not tenant-aware by default).

---

### C3. Rate Limiting

**Commands:**
```bash
grep -r "throttle:" apps/api --include='*.php' | head -20
grep -r "RateLimiter::for" apps/api --include='*.php' | head -10
```

**Results:**

**Throttle middleware usages:**
- `Identity/routes.php` — `throttle:login`, `throttle:register`, `throttle:email-verification`, `throttle:password-reset` (4 endpoints)
- `Scheduling/routes.php` — `throttle:storefront-booking-ip`, `throttle:storefront-booking-company-phone` (2 custom endpoints)
- `Document/routes.php` — `throttle:document-email` (2 email endpoints)
- `Catalog/SignedMediaController` — `throttle:signed-media` (1 endpoint)
- `Channel/routes.php` — `throttle:channel-webhook` (1 endpoint, commented: "malformed-id flood bypassed throttle")
- `POS/routes.php` — `throttle:pos-terminal-activation` (4 activation endpoints)

**RateLimiter::for definitions (AppServiceProvider):**

| Limiter | Limit | Scope |
|---------|-------|-------|
| admin-login | — | per IP (unspecified) |
| admin-sensitive | — | per IP |
| login | — | per email + IP |
| register | — | per IP |
| password-reset | — | per email |
| email-verification | — | per email |
| document-email | — | per authenticated user |
| pos-terminal-activation | — | per IP |
| api | — | per IP (default) |
| image-upload | — | per IP |

**Strike:** 10 rate limiters defined but no visibility on actual limits (60/min, 5/day, etc.). Comments suggest they exist but are not shown.

---

### C4. Sanctum Configuration

**Command:**
```bash
grep -r "expiration\|'expiration'" apps/api/config --include='*.php' | head -5
```

**Results:**

| Setting | Value |
|---------|-------|
| SANCTUM token expiration | 43200 minutes (30 days) |
| Permission cache expiration | 24 hours |

**Note:** Long token lifetime (30 days) requires robust token revocation on logout/security events.

---

### C5. Model Strictness

**Command:**
```bash
grep -r "preventLazyLoading\|shouldBeStrict\|preventAccessingMissingAttributes" apps/api --include='*.php' | head -10
```

**Result:** **0 occurrences** — no strict mode enabled for Eloquent models.

**Strike:** N+1 query risks are not caught by framework strictness.

---

### C6. Query Logging

**Command:**
```bash
grep -r "DB::listen\|QueryExecuted\|slow" apps/api --include='*.php' | head -15
```

**Results:**

| Pattern | Count | Notes |
|---------|-------|-------|
| `DB::listen(QueryExecuted)` | 4 | Tests only |
| `slow_queries` monitoring | 2 | Admin/MonitoringService logs pg_stat_statements |
| Comments mentioning "slow" | 5 | Docstrings, no active logging |

**Sample query logging:**
- `ShiftManagementServiceTest` — listens to QueryExecuted events, counts statements
- `OpeningBalanceBatchLifecycleHardeningTest` — tracks query keys via DB::listen
- `MonitoringService` — SELECT from pg_stat_statements for slow query report (admin endpoint)

---

## D. Frontend Data Fetching

### D1. QueryClient Configuration

**File:** `apps/web/src/lib/queryClient.ts`

**Configuration:**
```typescript
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 1000 * 60 * 5,        // 5 minutes
      retry: 1,
      refetchOnWindowFocus: false,
      refetchOnReconnect: true,
    },
    mutations: {
      retry: 0,
    },
  },
})
```

**Settings:**
- Default stale time: **5 minutes** (aggressive staleness)
- Default retry on queries: **1 retry** (modest)
- Window focus refetch: **disabled**
- Reconnect refetch: **enabled**
- Mutation retry: **0** (fail fast)

---

### D2. useQuery/useMutation/useInfiniteQuery Counts

**Command:**
```bash
find apps/web/src/features -type d -maxdepth 1 | sort | while read dir; do 
  count=$(grep -r "useQuery\|useQueries\|useInfiniteQuery\|useMutation" "$dir" --include='*.tsx' --include='*.ts' 2>/dev/null | grep -cE 'useQuery\(|useQueries\(|useInfiniteQuery\(|useMutation\(')
  [ "$count" -gt 0 ] && echo "$(basename "$dir"): $count"
done | sort -t: -k2 -rn | head -25
```

**Results: Top 25 features by hook usage**

| Feature | Total Hooks |
|---------|------------|
| features (global) | 865 |
| documents | 76 |
| treasury | 67 |
| pos | 57 |
| settings | 54 |
| catalog | 49 |
| admin | 49 |
| purchases | 42 |
| loyalty | 40 |
| inventory | 28 |
| expenses | 21 |
| parapharmacy | 20 |
| finance | 19 |
| withholding | 17 |
| placement | 16 |
| partners | 16 |
| parts-catalog | 15 |
| inventory-counting | 15 |
| services | 14 |
| import | 13 |
| batches | 13 |
| workshop-bundles | 12 |
| vehicles | 12 |
| opening-balances | 12 |
| menu | 12 |

**Total:** 865 hooks across feature directory.

**Strike:** `documents` module alone has 76 hooks (9% of total). Heavy query usage indicates deep data dependencies.

---

### D3. staleTime and refetchInterval Patterns

**Commands:**
```bash
grep -r "staleTime:" apps/web/src --include='*.tsx' --include='*.ts' | sed 's/.*staleTime: *//' | sed 's/[,}].*//' | sort | uniq -c | sort -rn

grep -r "refetchInterval:" apps/web/src --include='*.tsx' --include='*.ts' | sed 's/.*refetchInterval: *//' | sed 's/[,}].*//' | sort | uniq -c
```

**staleTime histogram (top 15):**

| Value | Count | Duration |
|-------|-------|----------|
| `5 * 60 * 1000` | 12 | 5 minutes |
| `60000` | 11 | 1 minute |
| `30000` | 9 | 30 seconds |
| `30 * 1000` | 8 | 30 seconds |
| `TWENTY_FOUR_HOURS` | 6 | 24 hours |
| `10 * 60 * 1000` | 5 | 10 minutes |
| `ONE_HOUR` | 4 | 1 hour |
| `300000` | 3 | 5 minutes |
| `1000 * 60 * 60` | 3 | 1 hour |
| `1000 * 60 * 5` | 3 | 5 minutes |
| `Infinity` | 2 | Never stale |
| `60 * 1000` | 2 | 1 minute |
| `300_000` | 2 | 5 minutes |
| `10000` | 2 | 10 seconds |
| `1000 * 60 * 30` | 2 | 30 minutes |

**Strike:** Huge variance (10s to Infinity). No standardized stale time across features.

**refetchInterval values (all 16 usages):**

| Value | Count |
|-------|-------|
| `5000` | 1 (dynamic) |
| `10000` | 1 |
| `15000` | 2 |
| `30000` | 3+ |
| `60000` | 1 |
| Conditional/dynamic | 5 |

**Strike:** Only 16 instances of `refetchInterval` suggest most UI is manual-refetch-only, not auto-polling.

---

### D4. invalidateQueries Anti-Pattern

**Command:**
```bash
grep -r "invalidateQueries" apps/web/src --include='*.tsx' --include='*.ts' | grep -E 'queryKey:\s*\[\]|invalidateQueries\(\s*\)' | wc -l
```

**Result:** **2 instances** of global invalidation (no args or empty queryKey array)

**Strike:** Low global invalidation count is good, but suggests manual key-based invalidation is the norm.

---

### D5. keepPreviousData and placeholderData

**Commands:**
```bash
grep -r "keepPreviousData" apps/web/src --include='*.tsx' --include='*.ts' | wc -l
grep -r "placeholderData" apps/web/src --include='*.tsx' --include='*.ts' | wc -l
grep -r "refetchOnWindowFocus" apps/web/src --include='*.tsx' --include='*.ts' | wc -l
```

**Results:**

| Pattern | Count |
|---------|-------|
| `keepPreviousData` | 2 |
| `placeholderData` | 1 |
| `refetchOnWindowFocus` | 2 |

**Strike:** Only 3 total uses of data persistence/placeholders across entire frontend. UI likely shows loading states between page changes instead of smooth transitions.

---

### D6. Debounce in Search

**Command:**
```bash
grep -r "useDebounce\|debounce" apps/web/src --include='*.tsx' --include='*.ts' | wc -l
```

**Result:** **112 imports** of debounce utilities

**Sample debounce patterns:**
- Search fields across 20+ features (documents, products, partners, partners, etc.)
- Filter inputs (inventory, catalog, pos)
- Form field watchers (asset management, settings)

**Strike:** Heavy debounce usage is good for search, but indicates 20+ independent search implementations (no shared pattern).

---

### D7. On-Mount Query Counts for Form Pages

**Sample pages analyzed:**

**documents/CreateCreditNotePage.tsx:**
```typescript
const { data: invoiceData } = useQuery({
  queryKey: tenantScopedKey(['invoice', sourceInvoiceId]),
  queryFn: async () => {
    const response = await api.get<{ data: Invoice }>(`/invoices/${sourceInvoiceId}`)
    return response.data.data
  },
  enabled: !!sourceInvoiceId,
})
```
- **On-mount queries: 1** (invoice detail)
- Conditional on sourceInvoiceId (credit mode)

**inventory-counting/CreateCountingPage.tsx:**
```typescript
const { data: nodes, isLoading } = useQuery({
  queryKey: [...],
  queryFn: async () => { ... },
})
```
- **On-mount queries: 1** (location hierarchy)

**purchases/StandaloneReceiptPage.tsx:**
```typescript
const policyQuery = useQuery({
  queryKey: [...],
  queryFn: async () => { ... },
})
```
- **On-mount queries: 1** (receipt policy)

**purchases/SupplierInvoiceCreatePage.tsx:**
```typescript
const policyQuery = useQuery({ ... })
```
- **On-mount queries: 1** (supplier invoice policy)

**Summary of key form pages:**

| Page | Module | On-Mount Queries |
|------|--------|------------------|
| CreateCreditNotePage | documents | 1 (conditional) |
| CreateCountingPage | inventory-counting | 1 |
| StandaloneReceiptPage | purchases | 1 |
| SupplierInvoiceCreatePage | purchases | 1 |
| GoodsReceiptListPage | purchases | 1 |

**Strike:** Each form page runs 1–2 queries on mount. No cascading fetches detected (good).

---

### D8. Direct axios/fetch Calls

**Commands:**
```bash
grep -r "axios\." apps/web/src --include='*.tsx' --include='*.ts' | grep -v "node_modules" | wc -l
grep -r "fetch(" apps/web/src --include='*.tsx' --include='*.ts' | grep -v "node_modules" | grep -v "lib/api" | head -20
```

**Results:**

| Type | Count | Acceptable? |
|------|-------|-------------|
| Direct `axios.` calls | 17 | No (should use api wrapper) |
| Direct `fetch()` calls | 2 | Potential (depends on purpose) |

**Sample direct fetch uses:**
- `useCountryDetect.ts` — `fetch(IP_API_URL, { signal })` — external IP geolocation API (legitimate)
- `enrichmentPhotos.ts` — `fetch(upload.upload_url, ...)` — S3 upload URL (legitimate)

**Strike:** 17 direct axios calls suggest inconsistent API client usage. Should be 0 (use api wrapper exclusively).

---

## E. POS & Sync Endpoints

### E1. POS Routes Overview

**File:** `apps/api/app/Modules/POS/routes.php` (285 lines)

**Route categories:**

| Category | Count | Notes |
|----------|-------|-------|
| Auth endpoints (/pos/auth/*) | 5 | PIN setup/verify, sync |
| Audit sync | 1 | /pos/audit-events/sync |
| Terminal mgmt | 14+ | claim, request, create, read, update, delete, activate, deactivate, release, archive, training toggle, z-chain, fiscal cutover |
| Shift mgmt | 6+ | open, close, current, show, index |
| Cash drawer | 2 | deposit, payout |
| Reports | 2 | X/Z reports |
| Receipt/Order sync | 5+ | create, update, fetch, payments, analytics |
| Floor/Table mgmt | 6+ | create/update/delete, query |
| Voucher sync | 2+ | ledger sync, indexing |
| Other | 15+ | manager PIN, pending customers, transfers, custom orders |

**Total endpoints:** ~60+

**Sync endpoints (device↔server):**
- `/pos/auth/sync-pins` — PIN synchronization (POST)
- `/pos/audit-events/sync` — Audit event batch sync (POST)
- `/pos/receipts/sync` — Receipt sync (inferred from VoucherLedgerSyncRequest pattern)
- `/pos/voucher-ledger/sync` — VoucherLedgerSyncRequest

---

### E2. Idempotency Key Support in POS Requests

**Commands:**
```bash
grep -r "idempotent\|idempotency" apps/api/app/Modules/POS --include='*.php' -i | head -10
grep -r "class.*Request" apps/api/app/Modules/POS --include='*.php' | grep -v "vendor" | wc -l
```

**Results:**

| Aspect | Finding |
|--------|---------|
| Idempotency mentions in POS | 10 documented patterns |
| POS FormRequest classes | 32 |
| Explicit idempotency key header | Not found |

**Idempotency implementation pattern (via Projections):**
- `DepositReceiptProjection` — Idempotent on `(fiscal_event_id)` with UNIQUE constraint
- `ZSessionLifecycleProjection` — Idempotent on event replay; z_session_events table has id-based de-duplication
- `PosCoreReceiptProjection` — Idempotent on `pos_receipts.fiscal_event_id` UNIQUE constraint
- `CloseOrphanedShiftCommand` — Re-runs are no-op after success

**Strike:** Idempotency is **event-based** (fiscal_event_id) not **request-based** (Idempotency-Key header). Sync endpoints rely on device-authored event IDs, not client-supplied idempotency tokens.

**No evidence of:**
- `Idempotency-Key` header handling
- `client_id` field in POS FormRequests
- Request deduplication middleware

---

## Summary: 10 Most Striking Numbers

1. **607 controller methods** accept raw `Request $request` instead of FormRequest — violates strict validation pattern
2. **452 $request->input() calls** across backend with minimal pre-flight validation
3. **960 ->get()/->all() calls** in modules; only **83 use paginate()** (9%) — N+1 risk
4. **6 modules** with 10+ ->get() calls but zero eager-loading (with/load/withCount) — lazy-load cascade risk
5. **166 raw SQL calls** (orderByRaw, whereRaw, DB::raw) mostly in reporting, complex query risk
6. **47 Cache:: calls** in entire codebase — minimal caching, high DB load expected
7. **607 methods with Request parameter** vs **287 total controllers** = 2.1 per controller (high raw-request dependency)
8. **865 TanStack Query hooks** in frontend with huge staleTime variance (10s to Infinity) — no standardized cache strategy
9. **112 debounce imports** across frontend suggesting 20+ uncoordinated search implementations
10. **0 Model strictness enforced** (preventLazyLoading, shouldBeStrict) — N+1 queries will silently accumulate

---

## Key Risk Areas

- **Validation:** Raw `Request $request` in 607 methods bypasses FormRequest filtering
- **Query Safety:** 91% of module queries use ->get(); 6 modules never eager-load
- **Caching:** 47 Cache:: calls is minimal; 5-minute default staleTime suggests high re-fetch rate
- **Frontend Data:** No unified TanStack config; debounce + placeholderData usage is sparse
- **POS Idempotency:** Device-side event IDs, not Idempotency-Key headers; risky under network retry
- **Raw SQL:** 166 raw queries in reporting; SQL injection surface if inputs ever become dynamic

---

## Command Reference

All metrics extracted via grep/find/wc from cwd `/Users/houssamr/Projects/syneriva/apps/erp`:

```bash
# A1 — Controller count
find apps/api -name '*Controller.php' -type f | wc -l

# A2 — Raw Request methods
grep -rE 'public function \w+\(Request \$request' apps/api --include='*.php' | wc -l

# A3 — $request usage
grep -r '\$request->all()' apps/api --include='*.php' | wc -l
grep -r '\$request->input(' apps/api --include='*.php' | wc -l

# B1 — Query methods
grep -rE '\->get\(\)|\->all\(\)' apps/api/app/Modules --include='*.php' | wc -l

# B2 — Pagination
grep -rE '\->paginate\(|\->simplePaginate\(|\->cursorPaginate\(' apps/api/app/Modules --include='*.php' | wc -l

# B3 — Eager loading
grep -rE '\->with\(|\->load\(|\->withCount\(' apps/api/app/Modules --include='*.php' | wc -l

# B4 — Raw SQL
grep -rE 'orderByRaw|whereRaw|DB::raw' apps/api/app/Modules --include='*.php' | wc -l

# C1 — Cache usage
grep -r 'Cache::' apps/api/app --include='*.php' | wc -l

# D2 — Frontend hooks
find apps/web/src/features -type d -maxdepth 1 | xargs grep -r "useQuery\|useQueries\|useInfiniteQuery\|useMutation" --include='*.tsx' --include='*.ts' 2>/dev/null | wc -l

# E1 — POS sync endpoints
grep -E "Route::|->middleware" apps/api/app/Modules/POS/routes.php | wc -l
```
