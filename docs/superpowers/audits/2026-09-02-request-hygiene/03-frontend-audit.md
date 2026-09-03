# Frontend Request-Hygiene Audit — Where the Client Generates Unnecessary API Load

**Date:** 2026-09-02
**Scope:** `apps/erp/apps/web/src` (React 19 / Vite / TanStack Query 5 / Zustand) and `apps/erp/apps/pos/src` (Tauri, offline-first). Read-only audit — no application files modified.
**Question:** as users grow, where does the frontend generate unnecessary or unoptimized load on the API, and where does request handling hurt UX?

Companion to `02-backend-audit.md` in this same directory (backend request-path costs). This audit is client-side only: query fan-out per page, TanStack Query configuration, pagination/debounce, invalidation granularity, duplicate hooks, boot sequence, payload usage, retry/cancellation, client-side input hygiene, and the POS sync engine.

---

## Method

1. Read `apps/web/src/lib/queryClient.ts` for global defaults, then traced the boot provider chain (`App.tsx` → `AuthProvider` → `CompanyProvider` → `CompanyConfigContext` → `LocationProvider`) file by file to establish the mandatory round-trip count before first render.
2. For each named page (documents create/edit, products create/edit, purchase-order create, stock-transfer create, inventory-counting create/detail, treasury payment create), opened the page component, then followed every imported picker/selector/child component that could hold its own `useQuery`, distinguishing requests fired unconditionally on mount from requests gated behind `enabled` (user interaction, focus, or an id param).
3. Grepped every `'/<resource>'` string literal used as an API path across `.ts`/`.tsx` (excluding tests) and grouped hits by literal endpoint to find the same reference-data endpoint fetched under different query-key factories (duplicate-hook detection), then opened each call site to confirm the divergent key and whether cache is actually shared.
4. Grepped `refetchInterval`, `invalidateQueries(`, `placeholderData`/`keepPreviousData`, `zodResolver`, and `signal` across the app to answer the polling, invalidation-granularity, form-validation, and cancellation questions quantitatively before reading representative call sites.
5. For POS (`apps/pos/src`), read `syncScheduler.ts` (cadence/backoff) and `syncService.ts` (`runFullSync`, ~2600 lines) for batch sizes, delta-cursor usage, and idempotency; read `HomePage.tsx` and `LoginPage.tsx`/`authStore.ts` for the sale-screen and boot fan-out.
6. Every finding below cites the file and line I actually opened; I did not infer behavior from function/file names alone.

---

## Boot sequence (web app, `apps/web`)

Provider nesting in `apps/web/src/App.tsx:27-40`: `ProductConfigProvider` (no I/O — reads `VITE_APP_PRODUCT`) → `AuthProvider` → `CompanyProvider` → `CompanyConfigProvider` → `LocationProvider`.

| Step | Request | Evidence | Gated on |
|---|---|---|---|
| 1 | `GET /auth/me` | `features/auth/AuthProvider.tsx:62-71` | `token !== null` (skipped on public routes) |
| 2 | `GET /user/companies` | `features/company/CompanyProvider.tsx:80-89` | step 1's `tenantId`, i.e. a real waterfall (2 sequential round trips minimum) |
| 3a | `GET /company/config` | `contexts/CompanyConfigContext.tsx:58-64` | step 2's resolved `companyId` — a **second** waterfall stage |
| 3b | `GET /locations` | `features/locations/LocationProvider.tsx:33-42` | step 2's resolved `companyId`, runs in parallel with 3a |

**Total: 3 sequential stages (auth → companies → {config, locations} in parallel) = minimum ~3 network round trips before the dashboard shell renders**, plus whatever the landed page itself needs. No notifications/permissions/"kitchen sink" boot call was found — `DashboardLayout.tsx` and both `Sidebar` implementations fire no queries of their own (`grep useQuery` on those files returned nothing). This is a reasonable, bounded boot waterfall — see Done Well.

## Boot sequence (POS, `apps/pos`)

| Step | Request | Evidence |
|---|---|---|
| 1 | `POST /auth/login` (×2 if the account is multi-org and no cached tenant hint matches — auto-select re-POST) | `apps/pos/src/stores/authStore.ts:299-345` |
| 2 | `GET /user/companies` | `apps/pos/src/stores/authStore.ts:388` (`fetchCompanies`) |
| 3 | `GET /company/config` | `apps/pos/src/stores/authStore.ts:420` (`refreshCompanyConfig`) |
| 4 | `runFullSync()` — **~12 pull calls executed sequentially with `await`**, not parallelized: `pullProducts`, `pullPaymentConfig`, `pullPaymentPolicy`, `pullOperatorPins`, `pullTerminalState`, `pullZChainState`, `pullTables`, `pullActiveMenu`, `pullVouchers`, `pullVoucherLedger`, `pullReceiptQrIndex`, then a customer-mirror push/pull chain | `apps/pos/src/lib/sync/syncService.ts:2317-2330` |

First login of the day is therefore **~3 auth round trips + ~12 sequential sync round trips** before the terminal is usable — see F-14.

---

## Fan-out per page (web app)

Counts are requests fired **unconditionally on mount** (i.e. not behind an interaction gate like combobox-open or field-focus). Interaction-triggered waterfalls are called out separately — several pages look cheap at mount but are pathological the moment the user starts typing.

| Page | Mount requests | Endpoints | Reference data (shareable)? | Waterfalls | Per-row requests |
|---|---|---|---|---|---|
| **Documents create** (`DocumentForm.tsx`, any type) | 0 beyond boot | — (document query disabled when `!isEditing`, `DocumentForm.tsx:317`) | — | none at mount | none at mount, but see F-2 (bulk pricing re-fires on every price-field keystroke) |
| **Documents edit** | 1 | `GET /{endpoint}/{id}` | no | none | none |
| **Products create** | 2 | `GET /categories/tree` (`useCategoryTree`, `features/catalog/api/queries.ts:81`), `GET /uom/units` (`useUnits`, `features/uom/hooks/useUnits.ts:122-126`) | **yes** — both are classic reference data | none | none |
| **Products edit** | 4 | adds `GET /products/{id}` (`features/inventory/ProductForm.tsx:354`) and `GET /products/{id}/variants` (`useVariantsForProduct`, `features/inventory/ProductForm.tsx:385`) | mixed | none | none |
| **Purchase order create** | 0 beyond boot | reuses `DocumentForm` (`routes/index.tsx:936-944`, `documentType="purchase_order"`) | — | same as documents create | same as documents create |
| **Stock transfer create** | 2 | `GET /locations` (key `['locations','all']`, `CreateStockTransferPage.tsx:522`) + `GET /company/locations/transaction-destinations` (key `['locations','transaction-destinations']`, `:526`) — **both unconditional, no `enabled` guard at all** | **yes**, and duplicative of boot-time `LocationProvider` data (F-6) | none | **yes — per-row, see F-3**: `AvailabilityCell` (`:425-431`) fires `GET /products/{id}/stock-levels` per line, `VariantSelectCell` (`:464`, via `useProductVariants`) fires `GET /product-variants?product_id=…` per line, as soon as a line has a product |
| **Inventory counting — create** (`CreateCountingPage.tsx`) | ~3 | `GET /users` (`useUsers`, `features/users/hooks/useUsers.ts:28`), `GET /categories` flat list (`useCategories`, via `CategorySelector`, `features/categories/components/CategorySelector.tsx:47`), `GET /locations` (via `LocationSelectorMulti` → `useLocations`, `features/locations/hooks/useLocations.ts:26-30`) | **yes, all three** — users/categories/locations are all reference data, and locations is a 3rd distinct key for the same `/locations` payload | node-scope query (`:490`) waterfalls on `locationId` selection | none |
| **Inventory counting — detail** (`CountingDetailPage.tsx`) | 1 | `GET` counting detail (`useCountingDetail`, `features/inventory-counting/api/queries.ts:65`) | no | none | none |
| **Treasury payment create** (`PaymentForm.tsx`) | 3 (unconditional) + up to 4 more if navigated with an id query param | `GET /payment-methods` (`:441`), **`GET /partners` — full list, no pagination params** (`:453`), `GET /payment-repositories` (`:465`); conditionally `GET /invoices/{id}`, `/purchase-orders/{id}`, `/documents/{id}`, `/supplier-invoices/{id}` (`:344-379`, each gated on a URL param) | payment-methods/repositories yes; **partners is unbounded — F-5** | `openInvoicesData` (`:530`) waterfalls on `selectedPartnerId` | none |
| **POS sale screen** (`apps/pos/src/pages/HomePage.tsx`) | 0 | — (`grep useQuery` on the file: zero matches) | n/a — reads local SQLite/Zustand mirrors only | none | none |
| **POS boot/login** | see table above (~3 auth + ~12 sequential sync pulls) | — | most of the sync pulls ARE the reference-data refresh (products, payment config, tables, menu, vouchers…) | **yes, structural** — every pull in `runFullSync` is sequentially awaited, not batched (F-14) | n/a |

---

## QueryClient defaults

`apps/web/src/lib/queryClient.ts:3-15`:

```
staleTime: 5 minutes, retry: 1, refetchOnWindowFocus: false, refetchOnReconnect: true
mutations.retry: 0
```

`gcTime` is not overridden (TanStack v5 default: 5 minutes). Consequences:

- `refetchOnWindowFocus: false` is set globally — good, this is the single most common source of refetch storms in multi-tab usage and it's disabled everywhere (no per-query override was found re-enabling it).
- `retry: 1` is a **plain number, not a status-aware function** — no query anywhere overrides this with a 4xx exclusion (`grep -rn "retry: (failureCount"` and similar patterns: 0 hits). Every failed GET — including a 404 on a stale detail id or a 403 permission denial — is retried once automatically. See F-8.
- Reference-data queries (`useCategoryTree`, `useUnits`, `useLocations`, `useCompanyConfig`) inherit the 5-minute `staleTime` except where explicitly raised (`useLocations` → 300000ms explicit, `CompanyConfigContext` → 1 hour, `PartnerPicker`'s single-partner lookup → 60s). None are refetched on window focus. This part of the picture is healthy.
- **Polling** (`grep refetchInterval`, all outside test files):

| Query | Interval | Where | Pauses when tab hidden? |
|---|---|---|---|
| Owner-dashboard "today" reports | 60s / 15s | `features/owner-dashboard/hooks/useOwnerReports.ts:35,134` | explicit `refetchIntervalInBackground: false` (`:135`) |
| Admin monitoring (dashboard/health/perf/queue) | 30s / 30s / 30s / 60s / 15s | `features/admin/hooks/useMonitoring.ts:19,28,37,46,55` | not set → TanStack default (`false`), so yes |
| Web-POS layout (2 queries) | 30s each | `features/pos/layouts/POSLayout.tsx:51,59` | default, yes |
| Kitchen orders | 30s | `features/pos/hooks/useKitchenOrders.ts:29` | default, yes |
| Held orders | 30s | `features/pos/hooks/useHeldOrders.ts:35` | default, yes |
| Active orders ("safety net") | 10s | `features/pos/hooks/useOrders.ts:64` | default, yes |
| Counting dashboard | 30s | `features/inventory-counting/api/queries.ts:48` | default, yes |
| Counting reconciliation | 30s | `features/inventory-counting/api/queries.ts:79` | default, yes |
| Document-ingestion list (while any row processing) | 5s conditional | `features/document-ingestions/queries.ts:51` | default, yes |
| Document-ingestion detail (while processing) | 4s conditional | `features/document-ingestions/queries.ts:63` | default, yes |
| Import wizard (while importing) | 2s conditional | `features/import/pages/ImportWizardPage.tsx:358` | default, yes |
| Notifications | 60s | `features/notifications/hooks/useNotifications.ts:26` | default, yes |

All polling pauses on a hidden tab (TanStack default `refetchIntervalInBackground: false`, only one call site sets it explicitly), so none of this is "unbounded background polling" in the P0 sense. But the web-POS layout alone runs 5 concurrent pollers (2 in `POSLayout` + kitchen + held + active-orders) at 10-30s cadence for the entire time a cashier has that screen open — a legitimate, all-day baseline load that scales linearly with concurrently-open POS terminals (see F-12/F-13, rated P2 informational, not a defect).

---

## Findings table

| ID | Severity | Area | Evidence | Effect at scale / on UX |
|---|---|---|---|---|
| F-1 | **P0** | Fan-out / debounce | `components/molecules/line-items/LineItemEntryBar.tsx:70-80` (`queryKey: ['line-entry-products', trimmedQuery]`) and `:244` (`setQuery(event.target.value)`) — no debounce anywhere in this component | Product-line search used on every document/PO/counting-session line-add fires `GET /products?search=…` on **every keystroke** while the dropdown is open. A 6-character search = 6 requests. |
| F-2 | **P0** | Fan-out / debounce | `features/documents/components/DocumentLineEditor.tsx:340-397` (`pricingContextSignature` recomputed from live `lines`), `:823-841` (`onFocus` sets `focusedPriceLineId`), `MoneyInput` `onChange` fires per keystroke (`components/atoms/MoneyInput/MoneyInput.tsx:93,112`, no debounce) | While a unit-price field is focused, every keystroke changes the signature → a fresh `tenantScopedKey` → a **new bulk `POST /line-entry/pricing-context/bulk` carrying every non-service line on the document**. Cost grows with both keystrokes AND line count. |
| F-3 | **P0** | Per-row grid requests | `features/stock-transfers/pages/CreateStockTransferPage.tsx:415-431` (`AvailabilityCell` → `GET /products/{id}/stock-levels`), `:459-464` (`VariantSelectCell` → `useProductVariants` → `GET /product-variants?product_id=…`) | Both components are rendered **per grid row**. Adding N lines to a stock transfer fires up to 2N concurrent requests as soon as products are picked — exactly the "per-row requests in grids" pattern the review targets. |
| F-4 | **P0** | Invalidation granularity | `providers/WebSocketReconnectProvider.tsx:16-31` (`queryClient.invalidateQueries()` — **no key filter at all**), mounted at `components/templates/DashboardLayout/DashboardLayout.tsx:47` (i.e. wraps every authenticated page) | Any WebSocket reconnect (network blip, laptop sleep/wake, server deploy dropping the socket) invalidates and refetches **every currently-mounted query in the entire app** simultaneously — a refetch storm whose size scales with how many queries the current page/tabs have open. |
| F-5 | **P1** | Payload / pagination | `features/treasury/PaymentForm.tsx:453-459` (`api.get('/partners')`, no `per_page`/`search` params), `:906` (`partners.map` into a native `<select>` with every partner as an `<option>`) | Unbounded full-partner-list fetch and render on every payment-create mount. `PartnerPicker` (a paginated, debounced combobox, `per_page=20`) exists elsewhere in the same codebase and isn't reused here. Grows linearly with partner count; large tenants get a slow mount and an unusable giant `<select>`. |
| F-6 | **P1** | Duplicate hooks / boot-cache defeat | 6 distinct query-key variants for the same `/locations` (or equivalent) reference data, none reusing the already-fetched `locationStore`: `features/locations/LocationProvider.tsx:34` (`['locations', companyId]`, boot), `features/stock-adjustments/pages/CreateStockAdjustmentPage.tsx:179-186` (`['locations','options']`), `features/purchases/supplier-invoices/SupplierInvoiceCreatePage.tsx:225` (same pattern), `features/purchases/StandaloneReceiptPage.tsx:134` (same pattern), `features/stock-transfers/pages/CreateStockTransferPage.tsx:522` (`['locations','all']`), `features/locations/hooks/useLocations.ts:22-30` (`['locations','list']`), `features/document-ingestions/queries.ts:67-77` (`['locations']`) | Every one of these pages independently re-fetches the full location list on mount instead of reading the Zustand `locationStore` `LocationProvider` already populated at boot. At scale this is one extra request per page load, per user, for data that's already in memory. |
| F-7 | **P2** | Duplicate hooks | `features/uom/hooks/useUnits.ts:119-127` (key `['uom','units',{categoryId:undefined},t,c]`) vs `features/workshop-bundles/hooks/useUnits.ts:24-39` (key `['uom','units',t,c]`) — same `/uom/units` endpoint, non-matching keys | If both a product form and the workshop-bundle authoring UI are used in the same session, units are fetched twice under separate cache entries. Small table, low absolute cost, but a clean instance of the pattern the review asked to find. |
| F-8 | **P2** | Retry policy | `lib/queryClient.ts:7` (`retry: 1`, no status check); confirmed no per-query override anywhere (`grep "retry: (failureCount"` → 0 hits) | A 403 (permission denied) or 404 (stale id) GET is retried once automatically — guaranteed to fail again — doubling failed-request volume for every dead-end lookup, app-wide. |
| F-9 | **P2** | Cancellation | `lib/api.ts:381` (`apiGet<T>(url, params?)`) and `:389` (`apiPost<T>(url, data?)`) — neither accepts a `signal`/`AbortSignal` parameter; `grep -rln signal` across hooks/queries returns effectively nothing outside 2 unrelated files | The shared API helpers give queries **no way** to wire `AbortSignal` from TanStack's `queryFn({ signal })` into the underlying axios call, so navigating away mid-fetch never cancels the request — most consequential on F-2's bulk pricing POST, which can still be in flight (and its response silently discarded) well after the user has moved to the next field. |
| F-10 | **P2** | Pagination UX | `grep -rln "placeholderData\|keepPreviousData"` → **one** hit app-wide, `features/pos/hooks/useDiscountPreview.ts:87`; not used on `DocumentListPage.tsx`, `ProductListPage`, or any other list page inspected | Every page/filter/sort change on a paginated grid blanks to a loading state instead of keeping the previous rows visible — a UX regression (flicker) at every page turn, not a load problem per se. |
| F-11 | **P2** | Client input hygiene | No RHF at all: `features/settings/UsersPage.tsx`, `RolesPage.tsx`, `CompanyPage.tsx`, `features/auth/LoginPage.tsx`, `RegisterPage.tsx` (each hand-rolls its own validation, e.g. `features/auth/LoginPage.tsx:108-126`). RHF without `zodResolver`: `features/inventory/ProductForm.tsx`, `features/documents/DocumentForm.tsx`, `features/treasury/PaymentForm.tsx`, `features/vehicles/VehicleForm.tsx`, `features/services/ServiceForm.tsx` (all confirmed `useForm` present, `zodResolver` absent via direct grep) | Inconsistent with `docs/conventions/06-FORMS.md`'s RHF+zod mandate. Hand-rolled/inline-only validation means malformed payloads that a shared schema would catch client-side instead round-trip to the API for a 422. |
| F-12 | **P2 (informational)** | Polling load | Web-POS screen runs 5 concurrent pollers while open: `features/pos/layouts/POSLayout.tsx:51,59` (30s ×2), `features/pos/hooks/useKitchenOrders.ts:29` (30s), `useHeldOrders.ts:35` (30s), `useOrders.ts:64` (10s, explicitly a "safety net") | All pause when the tab is hidden (TanStack default), so not pathological — but it's an all-day baseline of ~5 requests/10-30s per open terminal, scaling linearly with concurrently-open POS screens. |
| F-13 | **P3** | Convention / correctness | `features/locations/LocationProvider.tsx:34` — key is `['locations', currentCompanyId]`, built by hand instead of `tenantScopedKey(...)` (contrast every other location hook in F-6, which does use it) | Deviates from the codebase's own tenant-scoping convention (`lib/tenantScopedKey.ts:20-23`, enforced elsewhere by `apps/web/tools/audit-tanstack-keys.mjs`). `companyId` alone is a UUID so collision risk is theoretical, but it's an inconsistency in the exact provider that's supposed to be the canonical source. |
| F-14 | **P1** | POS boot UX | `apps/pos/src/lib/sync/syncService.ts:2317-2330` — `pullProducts`, `pullPaymentConfig`, `pullPaymentPolicy`, `pullOperatorPins`, `pullTerminalState`, `pullZChainState`, `pullTables`, `pullActiveMenu`, `pullVouchers`, `pullVoucherLedger`, `pullReceiptQrIndex` are all `await`-chained in sequence, not run in parallel batches | First-login-of-the-day cold sync (empty local SQLite) pays the full latency of ~12 sequential round trips end-to-end instead of overlapping independent pulls — directly extends time-to-usable-terminal, worst on a slow backroom connection. |
| F-15 | **P2 (justified tradeoff, flagged for visibility)** | POS batch size | `apps/pos/src/lib/sync/syncService.ts:414-436` — `pushOfflineReceipts` pushes fiscal events **one at a time** in a `for` loop, each its own `POST /pos/sync/fiscal-events` with `envelopes: [event]`, despite the endpoint accepting an array | After an extended offline period (venue with hours of no connectivity), reconnecting means N sequential HTTP round trips instead of fewer batched calls. The comment at `:429` ("Push receipts first (order matters for chain)") makes clear this is deliberate — fiscal hash-chain ordering requires strict sequential ack per event — so this is a correctness-driven tradeoff, not an oversight, but it's still the actual network shape at reconnect and worth the owner's awareness. |

---

## Top findings expanded

### F-1 / F-3 — the same missing-debounce bug exists in two different components, but only one was fixed

The codebase has **three** product/partner/service search comboboxes built to the same pattern (open-triggered, debounced, tenant-scoped key): `PartnerPicker` (`components/molecules/pickers/PartnerPicker.tsx:115,126` — `useDebouncedValue(query, 250)`), `ServicePicker` (`components/molecules/pickers/ServicePicker.tsx:94-99` — same), and `ProductPicker` (`components/molecules/pickers/ProductPicker.tsx:110,143` — same, used by stock transfers). But the product-search bar actually used on **every document, purchase-order, and counting-session line-add** — `LineItemEntryBar` — reimplements the same interaction from scratch (`components/molecules/line-items/LineItemEntryBar.tsx:59-80`) without the debounce, keying its query directly off raw keystroke state (`:70`, `:244`). This is the single highest-traffic search surface in the app (every sales/purchase document, every counting session) and it's the one instance missing the pattern its siblings already have.

Compounding this, `DocumentLineEditor`'s bulk pricing-context query (F-2) shares the same root cause — no debounce on state that changes per keystroke — but is worse in shape: it's a `POST` carrying **every line on the document**, so the request body itself grows with document size, not just the request count.

### F-4 — a global `invalidateQueries()` with no filter, mounted at the app root

`WebSocketReconnectProvider` (`providers/WebSocketReconnectProvider.tsx`) is deliberately designed to recover from missed WebSocket events after a reconnect — a legitimate goal — but implements it with the broadest possible tool: `queryClient.invalidateQueries()` with zero arguments (`:29`). TanStack Query treats an argument-less call as "match everything." Mounted in `DashboardLayout.tsx:47`, this wraps every authenticated route, so it fires on every page the user could be on. Contrast this with `CompanySelector.tsx:37-44`, which does the same argument-less `invalidateQueries()` call but on an infrequent, deliberate user action (switching companies) and with a comment explaining why it's safe in that specific case (queued via `queueMicrotask` so only queries already re-rendered onto the new company's scoped keys are "active" and thus actually refetched — the old company's queries just get marked stale, not refetched). `WebSocketReconnectProvider` has no such reasoning and fires on a transient, comparatively frequent network event.

### F-6 — reference data is fetched under 6+ different keys instead of the one the boot provider already populated

`LocationProvider` fetches the company's full location list at boot and stores it in `locationStore` (`features/locations/LocationProvider.tsx:33-42`). Five other call sites independently re-fetch essentially the same `GET /locations` payload, each under its own query key, none reading `locationStore`: stock adjustments, supplier-invoice create, standalone receipt create (all three share the literal comment-annotated pattern at e.g. `CreateStockAdjustmentPage.tsx:179-186`, explicitly keyed apart from `stock-adjustments` to avoid over-broad invalidation — a reasonable local concern that nonetheless left the redundant-fetch problem unaddressed), stock-transfer create (two more variants), and the canonical `useLocations` hook itself (`features/locations/hooks/useLocations.ts`) which is *also* not what `LocationProvider` uses. This is the clearest instance of the "duplicate hooks defeat dedup/cache" pattern the review asked to find — it isn't hypothetical, it's the single most-fetched piece of reference data in the app, splintered across 6 cache entries.

---

## Done well (evidence)

- **QueryClient defaults are sane globally**: `refetchOnWindowFocus: false` and a 5-minute `staleTime` (`lib/queryClient.ts:6,8`) avoid the most common refetch-storm trigger (tab-switch thrashing) for every query in the app by default.
- **Three of four search comboboxes debounce correctly and gate on open state**: `PartnerPicker.tsx:115,126`, `ServicePicker.tsx:94-99`, `ProductPicker.tsx:110,143` all use `useDebouncedValue(query, 250)` and `enabled: isOpen`. This is the majority pattern; F-1 is the outlier, not the norm.
- **Bulk pricing context is a single request for N lines, not N requests**: `DocumentLineEditor.tsx:392-397` posts one `line-entry/pricing-context/bulk` call carrying every line, rather than pricing each line individually — undermined only by F-2's lack of debounce, not by the batching design itself.
- **List-page search is debounced by a shared component**: `SearchInput` debounces internally (300ms default, `components/molecules/SearchInput/SearchInput.tsx:19-39`) and `DocumentListPage.tsx:135-138,442` uses it correctly, with `page` reset to 1 on every filter/search change (`:134,138`) so stale-page requests aren't fired.
- **Boot sequence is bounded and lean**: 3 sequential stages, no chrome-level (`Sidebar`/`TopBar`/`DashboardLayout`) queries beyond the 4 providers (`grep useQuery` on those files: 0 hits).
- **`useCurrency` reads from the already-loaded company store, not a fresh request** (`hooks/useCurrency.ts:42-44`) — currency/locale/decimals formatting never triggers network I/O.
- **`CompanyConfigContext` caches for a full hour** (`contexts/CompanyConfigContext.tsx:61`) since module/vertical config genuinely doesn't change often — the longest deliberate staleTime found, correctly matched to the data's volatility.
- **POS sale screen (`HomePage.tsx`) makes zero direct network requests** — the entire cart/checkout flow reads local SQLite/Zustand mirrors; network I/O is confined to the background `SyncScheduler`. Correct offline-first design for a POS.
- **POS reference-data sync is delta/cursor-based, not full re-pulls**: products (`syncService.ts:720-723`, `updated_since` watermark), stock levels (`:1082-1095`, explicit full-vs-delta mode with a persisted cursor), product variants (`:1202-1213`), vouchers (`:2031-2033`) — this directly answers "full catalog re-pull vs delta" in the requested-scope favorably.
- **POS sync scheduler backs off correctly**: 60s base interval, doubling to a 5-minute cap on failure, and an immediate resync + interval reset the moment connectivity is restored (`apps/pos/src/lib/sync/syncScheduler.ts:11-13,38-46,239-252`) — not a fixed-interval poll that ignores failure state.
- **POS login supports request cancellation** via a caller-owned `AbortController`, re-created per attempt (`apps/pos/src/pages/LoginPage.tsx:29-36`) — the one place in the whole codebase (web included) that actually wires cancellation, which sharpens F-9's point that the web app's shared `apiGet`/`apiPost` don't expose the same capability.
- **Fiscal event push carries real idempotency semantics**: server-side `stored` vs `idempotent` result handling (`syncService.ts:478-482`) and refund events keyed by `idempotency_key = refund_intents.id` (`:346` comment, `:385`) — retries after a dropped response don't double-apply.

---

## Not verified

- The mobile/tablet live count-entry surface (if one exists as a distinct route from `CreateCountingPage`/`CountingDetailPage`/`CountingReviewPage`) was not located or traced — this audit only covers the three web routes under `inventory-counting`.
- `apps/pos` `PinEntryPage.tsx`/`TerminalSetupPage.tsx` request counts were not traced line-by-line; only `LoginPage.tsx` and the `authStore.ts` login/`fetchCompanies`/`refreshCompanyConfig` chain were opened.
- `ProductVariantMatrixEditor` (imported by `ProductForm.tsx:30`) and the enrichment platform-query module (`features/inventory/api/platformQueries.ts`) were identified from imports but not opened in full — their own mount-time fan-out is not included in the Products create/edit counts above.
- `CreateCreditNotePage.tsx` and `CreateReturnNotePage.tsx` are dedicated files distinct from `DocumentForm.tsx`; I assumed (but did not independently trace) that they share the same fan-out shape since the architecture routes all document types through the unified `documents` table.
- Response payload composition (whether `/partners`, `/products` list endpoints select a trimmed column set vs. full entities, and whether any list response embeds base64/media) was not inspected — that's a backend-response-shape question covered by `02-backend-audit.md`, not visible from the frontend call sites alone.
- No live network trace (browser devtools / Playwright network capture) was run — every finding here is static-code reading of query keys, `enabled` conditions, and debounce wiring, not measured request counts under an actual browser session.
- The full inventory of `refetchInterval` call sites was obtained by grep; a hand-rolled `setInterval`-based poll that doesn't use TanStack's `refetchInterval` (and so wouldn't show up in that grep) was not separately searched for.
