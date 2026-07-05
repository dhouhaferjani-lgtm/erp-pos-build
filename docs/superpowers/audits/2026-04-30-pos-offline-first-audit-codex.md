# POS Offline-First Audit — 2026-04-30

Note: `docs/superpowers/audits/` did not exist at audit time, so there were no prior files there to de-duplicate against.

## 1. Executive Summary

1. `P0` Fresh-device retail warmup is not 5000-SKU-safe: `productStore.fetchProducts()` makes a single `/products?per_page=500` request on first launch, so the foreground warmup can stop at 500 products unless the background sync finishes first. (`apps/pos/src/stores/productStore.ts:52-58`, `apps/pos/src/stores/productStore.ts:104-158`, `apps/pos/src/api/productApi.ts:6-13`)
2. `P0` A flaky network after `/auth/login` but before `/user/companies` can leave a fresh device authenticated with a persisted token but without a company, and `AppRouter` can then route that user into terminal setup instead of back to a recoverable auth/company-selection state. (`apps/pos/src/stores/authStore.ts:154-179`, `apps/pos/src/App.tsx:55-59`, `apps/pos/src/App.tsx:87-104`)
3. `P0` Cold-start offline degradation does not exist for a brand-new device: with no cached token, company, terminal, or operator PIN rows, the only entry path is online username/password login, and PIN-only auth is available only after SQLite operator hashes have been pulled or created earlier. (`apps/pos/src/stores/authStore.ts:92-127`, `apps/pos/src/pages/LoginPage.tsx:66-78`, `apps/pos/src/stores/operatorStore.ts:52-105`, `apps/pos/src/stores/operatorStore.ts:160-175`)
4. `P1` Payment config warmup happens only after shift open, has no explicit loading state, and checkout fails late with runtime errors like “no cash method” or “no cash register” when config is absent or stale. (`apps/pos/src/pages/HomePage.tsx:171-177`, `apps/pos/src/stores/paymentStore.ts:178-215`, `apps/pos/src/stores/paymentStore.ts:228-244`, `apps/pos/src/components/pos/PaymentSummary.tsx:79-99`)
5. `P2` Connectivity and sync UX are only partially truthful: network monitoring starts only inside `AppShell`, `lastSyncAt` is in-memory only, `pendingReceiptCount` is not hydrated from SQLite, and product/pull failures can still end with a “last sync” timestamp. (`apps/pos/src/components/AppShell.tsx:35-39`, `apps/pos/src/stores/syncStore.ts:31-57`, `apps/pos/src/components/atoms/SyncButton/SyncButton.tsx:23-60`, `apps/pos/src/lib/sync/syncService.ts:378-433`, `apps/pos/src/lib/sync/syncService.ts:439-455`)

## 2. Cold-Start Auth Flow

### Current state

- On app boot, `AppRouter` calls `authStore.initialize()`, which only reads cached token/user/company/company-list from Tauri Store; if that cache is empty, auth initialization ends without any SQLite fallback and routes to `/login`. (`apps/pos/src/App.tsx:51-85`, `apps/pos/src/stores/authStore.ts:92-127`)
- `LoginPage` only offers email + password; there is no PIN field or offline login path on this screen. If `isOnline` is false, the page is replaced by a static “no connection” panel. (`apps/pos/src/pages/LoginPage.tsx:10-18`, `apps/pos/src/pages/LoginPage.tsx:66-78`, `apps/pos/src/pages/LoginPage.tsx:88-131`)
- After successful password login, auth persists `TOKEN` and `USER`, marks the session authenticated, then fetches `/user/companies`; only a single-company tenant is auto-selected. (`apps/pos/src/stores/authStore.ts:137-179`)
- Terminal bootstrapping does not start until both `isAuthenticated` and `companyId` are present. Terminal initialization checks, in order, persisted active terminal, persisted pending terminal, then `/pos/terminals/by-device/:deviceId`. (`apps/pos/src/App.tsx:55-59`, `apps/pos/src/stores/terminalStore.ts:115-170`)
- When a terminal is activated or claimed, `seedOfflineHashChain()` pulls terminal hash state and Z-chain state, initializes image cache, creates a `SyncScheduler`, and starts it. (`apps/pos/src/stores/terminalStore.ts:78-109`)
- After terminal presence is established, `AppRouter` checks `hasPins`; if none exist it shows `PinSetupPage`, otherwise it shows `PinEntryPage`. PIN verification is offline-first against cached bcrypt hashes in SQLite, with API fallback only when no local hash matches. (`apps/pos/src/App.tsx:61-65`, `apps/pos/src/App.tsx:106-126`, `apps/pos/src/stores/operatorStore.ts:52-105`, `apps/pos/src/stores/operatorStore.ts:160-175`)
- The cart is not interactive until the user clears all of these gates: auth init, login, company selection if needed, terminal setup/claim/activation, `hasPins` check, PIN entry/setup, shift open, and then product fetch for the grid. Products and payment config are only kicked off after `shift` becomes truthy. (`apps/pos/src/App.tsx:67-131`, `apps/pos/src/pages/HomePage.tsx:171-177`, `apps/pos/src/pages/HomePage.tsx:542-586`, `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:155-175`)
- Loading coverage is uneven: there is a generic spinner during auth init and during the “terminal loading / hasPins null” stage, button-label loading on login and terminal actions, and plain text “loading terminals/locations” states, but no step-specific shell state for “restoring session”, “loading companies”, “seeding terminal hash chain”, or “warming payment config”. (`apps/pos/src/App.tsx:67-76`, `apps/pos/src/App.tsx:106-115`, `apps/pos/src/pages/LoginPage.tsx:125-131`, `apps/pos/src/pages/TerminalSetupPage.tsx:174-176`, `apps/pos/src/pages/TerminalSetupPage.tsx:321-323`)
- Most foreground API calls use `api.ts` with a `connectTimeout` of 10 seconds but no app-level overall timeout or cancellation; only the online checkout helper wraps its network path in a separate 5-second race. (`apps/pos/src/lib/api.ts:68-123`, `apps/pos/src/lib/offline/offlineCheckoutService.ts:12-13`, `apps/pos/src/lib/offline/offlineCheckoutService.ts:57-69`)

### Failure modes

- Fresh device + no internet: the cashier cannot pass `/login`, because auth init reads only empty local store state and `LoginPage` offers no offline PIN path. User-visible outcome: hard block at login/no-connection screen. Severity `P0`. (`apps/pos/src/stores/authStore.ts:92-127`, `apps/pos/src/pages/LoginPage.tsx:66-78`)
- Internet drops after `/auth/login` succeeds but before `/user/companies` returns: token and user are already persisted and `isAuthenticated` is already true, but `companyId` may stay null and `companies` may stay empty; on a fresh device that can route the app toward terminal setup without a company context. User-visible outcome: confusing post-login limbo rather than a clean retry prompt. Severity `P0`. (`apps/pos/src/stores/authStore.ts:154-179`, `apps/pos/src/App.tsx:55-59`, `apps/pos/src/App.tsx:87-104`)
- Internet drops during terminal bootstrap after login: `seedOfflineHashChain()` logs failures and leaves `hashChainReady` false, but the user can still proceed into shift/PIN flows if terminal identity exists; checkout buttons are then disabled by `hashChainReady`, with only a banner explaining the problem. User-visible outcome: terminal appears “in”, but cashier reaches a non-checkoutable POS. Severity `P1`. (`apps/pos/src/stores/terminalStore.ts:83-109`, `apps/pos/src/pages/HomePage.tsx:630-633`, `apps/pos/src/components/atoms/TerminalNotReadyBanner.tsx:5-33`)
- Because connectivity monitoring starts only in `AppShell`, login/setup pages rely on the store’s initial `navigator.onLine` snapshot and browser events, not the server health check. [INFERRED] User-visible outcome: captive portal / DNS failure can still show the normal login form until a real request hangs or fails. Severity `P1`. (`apps/pos/src/stores/connectivityStore.ts:20-38`, `apps/pos/src/components/AppShell.tsx:35-39`, `apps/pos/src/pages/LoginPage.tsx:11-12`, `apps/pos/src/pages/LoginPage.tsx:66-78`)

### Severity

`P0`

### Recommended fix

- Add an explicit cold-start state machine in `AppRouter` for `auth -> companies -> terminal -> pins -> shift -> data`, with user-visible step labels and retry affordances. Effort `M`.
- Delay `isAuthenticated` promotion until `/user/companies` succeeds, or roll back token/user persistence if company fetch fails on first login. Effort `S`.
- Add a true offline unlock mode for previously provisioned terminals that can start from cached terminal/company/operator state without requiring the full web login path. Effort `L`.
- Apply foreground request timeouts and cancelation to login/company/terminal bootstrap calls, not just checkout. Effort `S`.

## 3. Product Catalog Warmup

### Current state

- `HomePage` starts catalog warmup only after the shift is open. (`apps/pos/src/pages/HomePage.tsx:167-177`)
- `productStore.fetchProducts()` is SQLite-first: it tries `getAllProducts(db)` immediately and clears `isLoading` if cached rows exist; only then does it fetch company config and an API refresh. (`apps/pos/src/stores/productStore.ts:73-91`, `apps/pos/src/stores/productStore.ts:93-158`)
- On a true first launch with empty SQLite, the foreground API path is a single call: retail tenants use `fetchPOSProducts({ limit: 500 })`, which becomes `/products?per_page=500`; F&B tenants use `/active-menu`. (`apps/pos/src/stores/productStore.ts:52-58`, `apps/pos/src/api/productApi.ts:6-13`, `apps/pos/src/api/productApi.ts:52-66`)
- The background sync engine has a different product strategy: `pullProducts()` loops pages of 500 until a short page arrives, writes each page into SQLite, and applies delete tombstones. (`apps/pos/src/lib/sync/syncService.ts:371-433`)
- Fresh API results are cached back to SQLite through `upsertProducts()`, which batches SQLite writes in chunks of 50 rows. (`apps/pos/src/stores/productStore.ts:107-117`, `apps/pos/src/lib/db/repositories/productRepository.ts:53-104`)
- The product grid is fully blocked by a spinner while `ProductGrid.isLoading` is true, and it shows an empty state if `products.length === 0`. There is no partial rendering path in the first-launch foreground fetch. (`apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:155-175`)
- Product images are not preloaded during `fetchProducts()`. Images download lazily per rendered card through `useProductImage()`, which enqueues downloads into a shared queue; `processDownloadQueue()` later consumes the queue in batches of 10 during sync. (`apps/pos/src/lib/images/useProductImage.ts:11-30`, `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:28-31`, `apps/pos/src/lib/images/imageCache.ts:70-107`, `apps/pos/src/lib/images/imageCache.ts:157-203`, `apps/pos/src/lib/sync/syncService.ts:865-869`)
- On next launch, the store does read SQLite first and then revalidate against the API in the background when local rows exist. (`apps/pos/src/stores/productStore.ts:73-91`, `apps/pos/src/stores/productStore.ts:149-154`)
- For a 5000-SKU retail tenant, the foreground warmup is not paginated/streamed; it requests only one 500-row page. [INFERRED] At ~250-400 bytes per product payload, that first page is roughly ~125-200 KB and can arrive in around ~0.5-1.0 s on a clean 2 Mbps link, but the remaining ~4500 products depend on the background sync path rather than the foreground warmup. (`apps/pos/src/stores/productStore.ts:52-58`, `apps/pos/src/api/productApi.ts:11-13`, `apps/pos/src/lib/sync/syncService.ts:381-409`, `apps/pos/src/types/product.ts:3-15`)

### Failure modes

- Fresh retail device + 5000 SKUs + cashier opens shift before background sync completes: the foreground warmup can populate at most 500 products, so search and browse miss most of the catalog until later sync passes. User-visible outcome: “missing products” on go-live day, not just slowness. Severity `P0`. (`apps/pos/src/stores/productStore.ts:52-58`, `apps/pos/src/stores/productStore.ts:155-158`, `apps/pos/src/api/productApi.ts:11-13`)
- Fresh device + flaky network during initial foreground fetch: because the foreground path is single-shot, a failure returns either zero products plus an error state or, if the sync path independently wrote some rows, only whatever SQLite happened to have by then; there is no progressive foreground page accumulation. User-visible outcome: full spinner then empty catalog or partial backfill that appears “random”. Severity `P1`. (`apps/pos/src/stores/productStore.ts:132-146`, `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:155-175`, `apps/pos/src/lib/sync/syncService.ts:399-433`)
- Large image catalogs do not block the catalog, but image downloads are lazy and driven by rendered cards, not by a bounded warmup plan. User-visible outcome: blank/remote images pop in over time; on flaky internet many images never localize. Severity `P2`. (`apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:104-117`, `apps/pos/src/lib/images/useProductImage.ts:19-27`, `apps/pos/src/lib/images/imageCache.ts:157-203`)
- `pullProducts()` swallows errors and returns `0`, so a failed multi-page pull can still leave partial SQLite writes from early pages while the sync summary looks superficially successful enough to update `lastSyncAt`. User-visible outcome: stale/partial catalog with an over-optimistic sync badge. Severity `P2`. (`apps/pos/src/lib/sync/syncService.ts:399-433`, `apps/pos/src/stores/syncStore.ts:50-57`, `apps/pos/src/components/atoms/SyncButton/SyncButton.tsx:44-60`)

### Severity

`P0`

### Recommended fix

- Make `fetchProducts()` use the same paginated pull contract as `pullProducts()` on first launch, with page-by-page SQLite commits and progressive UI hydration. Effort `M`.
- Add an explicit “catalog warmup” shell state that reports row counts loaded, rather than a monolithic spinner. Effort `S`.
- Pre-seed a low-resolution image manifest or defer all image caching until after catalog completeness, so image work cannot mask catalog incompleteness. Effort `S`.

## 4. Payment-Config Warmup

### Current state

- `fetchPaymentConfig()` is called from `HomePage` only after `shift` is open; there is no earlier app-init warmup. (`apps/pos/src/pages/HomePage.tsx:171-177`)
- The store is SQLite-first: it reads cached active payment methods and repositories from local tables, then tries API calls, then writes fresh values back to SQLite. (`apps/pos/src/stores/paymentStore.ts:178-209`, `apps/pos/src/lib/db/repositories/paymentRepository.ts:71-128`)
- Checkout guards are late, not early: cash/card/advanced checkout read whatever is already in memory and throw if the needed method/repository is absent. (`apps/pos/src/stores/paymentStore.ts:226-244`, `apps/pos/src/stores/paymentStore.ts:291-310`, `apps/pos/src/stores/paymentStore.ts:365-379`)
- The visible checkout UI has no payment-config loading state. `PaymentSummary` always renders its cash button and conditionally renders “Advanced payments” only when at least two active methods are already in memory. (`apps/pos/src/components/pos/PaymentSummary.tsx:34-36`, `apps/pos/src/components/pos/PaymentSummary.tsx:79-99`)
- Store/API paths diverge: foreground warmup uses `/payment-methods` and `/payment-repositories`, while sync pull uses `/treasury/payment-methods` and `/treasury/payment-repositories`. (`apps/pos/src/api/paymentApi.ts:4-10`, `apps/pos/src/lib/sync/syncService.ts:439-447`)
- Payment config is cached to SQLite, and on later launches `fetchPaymentConfig()` will restore that cache before trying the API again. (`apps/pos/src/stores/paymentStore.ts:178-209`)

### Failure modes

- Fresh device + no cached payment config + internet drop after shift open: the cashier can still open the payment UI, but `processCashCheckout()` or `processCardCheckout()` fails only when they attempt the sale. User-visible outcome: late modal error instead of a blocked/unavailable payment section. Severity `P1`. (`apps/pos/src/stores/paymentStore.ts:210-215`, `apps/pos/src/stores/paymentStore.ts:228-244`, `apps/pos/src/components/organisms/CashPaymentScreen/CashPaymentScreen.tsx:73-79`)
- Cached config exists but is stale/incomplete: because checkout logic picks methods by heuristics (`is_physical && !has_maturity`, `type === cash_register`, card-code heuristics), a partial cache can produce “No cash payment method configured” or “No cash register configured” even though the server has valid config. Severity `P1`. (`apps/pos/src/stores/paymentStore.ts:228-244`, `apps/pos/src/stores/paymentStore.ts:291-310`)
- Foreground and sync warmup do not share the same API endpoints. [INFERRED] If those endpoints drift in shape, filtering, tenancy behavior, or deployment timing, the sync-populated SQLite cache and the foreground in-memory fetch can disagree, which fits the historical symptom the task notes. Severity `P1`. (`apps/pos/src/api/paymentApi.ts:4-10`, `apps/pos/src/lib/sync/syncService.ts:439-447`)

### Severity

`P1`

### Recommended fix

- Move payment-config warmup to terminal/PIN completion or shift-open preflight, and gate checkout on a distinct `paymentConfigReady` state with retry UI. Effort `S`.
- Collapse foreground and sync warmup onto one shared API client/path so the cache and the live fetch cannot diverge. Effort `S`.
- Surface config-missing problems in the header/preflight banner before the cashier enters a payment modal. Effort `XS`.

## 5. Sync Engine Push Reliability

### Current state

- Receipt durability is SQLite-backed: offline receipts are inserted into `offline_receipts`, keyed by unique `idempotency_key`, and sorted by `hash_sequence`. (`apps/pos/src/lib/db/migrations.ts:88-121`, `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:24-58`, `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:60-91`)
- Offline receipt creation wraps `insertOfflineReceipt()` and `advanceHashChain()` in a single transaction, which protects against half-written receipt/hash state on crash during local commit. (`apps/pos/src/lib/offline/receiptService.ts:214-224`)
- The sync engine reads receipts with `status IN ('pending','failed')` and `retry_count < 5`, ordered by `hash_sequence`, and pushes them one at a time as batch-of-one requests. (`apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:129-156`, `apps/pos/src/lib/sync/syncService.ts:160-186`)
- Idempotency is preserved in the payload and on local storage: each receipt keeps `idempotency_key`, and sync treats both `synced` and `duplicate` server responses as success. (`apps/pos/src/lib/offline/receiptService.ts:150-152`, `apps/pos/src/lib/sync/syncService.ts:191-205`, `apps/pos/src/lib/sync/syncService.ts:925-949`)
- Successful sync writes back `server_receipt_id` to SQLite and updates `paymentStore.lastReceiptServerId` when the synced receipt matches the currently displayed success modal. (`apps/pos/src/lib/sync/syncService.ts:193-205`)
- Retry/backoff lives in `SyncScheduler`: base interval 1 minute, doubling to max 5 minutes, reset on clean success, and immediate retry on offline->online transitions. (`apps/pos/src/lib/sync/syncScheduler.ts:9-12`, `apps/pos/src/lib/sync/syncScheduler.ts:35-45`, `apps/pos/src/lib/sync/syncScheduler.ts:112-149`)
- The queue survives restart because it is stored in SQLite, but scheduler state and in-memory sync metadata do not survive restart. (`apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:60-91`, `apps/pos/src/stores/syncStore.ts:31-57`)

### Failure modes

- 1000+ pending receipts after a flaky day are replayed strictly serially as 1000 HTTP round trips. [INFERRED] Even before server processing, that is tens of seconds to minutes of wall-clock sync time on unstable 4G because there is no chunked batch push for healthy receipts. Severity `P1`. (`apps/pos/src/lib/sync/syncService.ts:172-186`, `apps/pos/src/lib/sync/syncScheduler.ts:9-12`)
- Receipts that hit `retry_count >= 5` stop retrying entirely and only age out after 90 days if still failed. User-visible outcome: silent stranded backlog unless staff notices a stale pending-count/broken-chain symptom. Severity `P2`. (`apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:129-156`, `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:177-185`)
- `pendingReceiptCount` is not hydrated from SQLite on startup and is only updated from sync results or explicit setters that are not used in the production flow. User-visible outcome: header backlog badge can under-report queued receipts after restart. Severity `P2`. (`apps/pos/src/stores/syncStore.ts:17-27`, `apps/pos/src/stores/syncStore.ts:50-57`, `apps/pos/src/stores/syncStore.ts:67-69`, `apps/pos/src/components/Header.tsx:56-58`, `apps/pos/src/components/Header.tsx:374-382`)

### Severity

`P1`

### Recommended fix

- Add startup hydration of pending counts and failed-count telemetry from SQLite before the first scheduler tick. Effort `XS`.
- Introduce bounded batch push for healthy receipts while preserving per-terminal order in each batch. Effort `M`.
- Surface receipts that have exhausted retries as an operator-visible exception queue, not just a missing retry. Effort `S`.

## 6. Pull Strategy And Conflict Resolution

### Current state

- Full sync is push-first then pull-second: queued PIN updates, receipts, Z-reports, cash-drawer ops, then product/payment/operator/terminal/table/menu pulls. (`apps/pos/src/lib/sync/syncService.ts:820-887`)
- Product/payment/operator pull behavior is server-truth cache replacement: rows are upserted from server data into SQLite with no local merge semantics for those entities. (`apps/pos/src/lib/sync/syncService.ts:378-455`, `apps/pos/src/lib/sync/syncService.ts:461-472`)
- Receipt line items are snapshotted into JSON at sale time, including name, SKU, unit price, tax, discounts, and modifiers, so later catalog pulls do not mutate already-created local receipts. (`apps/pos/src/lib/offline/receiptService.ts:166-212`)
- Terminal receipt hash state is local-authoritative once advanced: regressive terminal-state writes are rejected, and server hash reconciliation is skipped if local is ahead. (`apps/pos/src/lib/db/repositories/terminalStateRepository.ts:51-69`, `apps/pos/src/lib/db/repositories/terminalStateRepository.ts:114-169`, `apps/pos/src/lib/sync/syncService.ts:206-230`, `apps/pos/src/lib/sync/syncService.ts:475-538`)
- Z-chain recovery has the same “preserve local if server is null/zero/regressive” rule. (`apps/pos/src/lib/sync/syncService.ts:556-668`)

### Failure modes

- Product/price/permission updates that arrive from server during sync immediately overwrite the cache for future operations, while already-built offline receipts keep their original snapshotted values. User-visible outcome: two cashiers can see price/permission shifts mid-shift, but in-flight local receipts stay internally consistent. Severity `P1`. (`apps/pos/src/lib/offline/receiptService.ts:166-212`, `apps/pos/src/lib/sync/syncService.ts:378-472`)
- Manager/operator PIN setup done offline is pushed before `pullOperatorPins()`, which is the right order; however, if that push keeps failing, the subsequent pull restores server truth and can leave other terminals unaware of the new PIN until sync succeeds. [INFERRED] Severity `P2`. (`apps/pos/src/lib/sync/syncService.ts:670-695`, `apps/pos/src/lib/sync/syncService.ts:820-887`)
- Multiple terminals sending receipts out of order appear to be modeled as separate per-terminal chains, because receipt payloads include `terminal_id` and local hash state is keyed by `terminal_id`. [INFERRED] If the server instead enforces a wider scope, this client would not protect against cross-terminal ordering conflicts. Severity `P2`. (`apps/pos/src/lib/sync/syncService.ts:64-88`, `apps/pos/src/lib/db/repositories/terminalStateRepository.ts:40-49`, `apps/pos/src/lib/offline/receiptService.ts:166-212`)

### Severity

`P1`

### Recommended fix

- Document the intended conflict model explicitly in code and API docs: server-truth for catalog/config, local snapshot truth for completed receipts, local-authoritative monotonic terminal chains. Effort `S`.
- Add sync-visible warnings when pulled catalog/config changed materially during an open shift, so staff understand why future sales differ from earlier ones. Effort `M`.
- Verify and test server-side ordering scope for receipts from multiple terminals; if it is terminal-scoped, codify that contract in shared tests. Effort `M`.

## 7. Connectivity Detection

### Current state

- `connectivityStore.checkNow()` first trusts `navigator.onLine`; if that passes, it probes `GET {serverUrl}/api/v1/health` with a 5-second connect timeout and sets `isOnline` equal to reachability of that health endpoint. (`apps/pos/src/stores/connectivityStore.ts:25-38`, `apps/pos/src/lib/connectivity.ts:4-18`, `apps/pos/src/lib/connectivity.ts:21-23`)
- Monitoring polls every 30 seconds when online, every 10 seconds when offline, and also reacts to browser `online`/`offline` events. (`apps/pos/src/stores/connectivityStore.ts:4-6`, `apps/pos/src/stores/connectivityStore.ts:40-69`)
- Monitoring only starts from `AppShell`; login/setup/PIN screens do not start it. (`apps/pos/src/components/AppShell.tsx:35-39`)
- Reconnect triggers are layered: connectivity false->true causes immediate `SyncScheduler.tick()`, visibility regain after 1 minute can call `triggerSync()`, and each locally inserted receipt also schedules a debounced sync trigger. (`apps/pos/src/lib/sync/syncScheduler.ts:35-45`, `apps/pos/src/components/AppShell.tsx:41-59`, `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:6-22`, `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:88-91`)
- The header “Sync Now / Last sync” button renders from in-memory `lastSyncAt` and `isSyncing`, not from persisted sync logs. (`apps/pos/src/components/atoms/SyncButton/SyncButton.tsx:23-60`, `apps/pos/src/stores/syncStore.ts:31-57`)

### Failure modes

- Captive portal / DNS / partial upstream failure can produce false “online” on login/setup screens because no health probe is started there, and later false “offline” or “online” is based on the health endpoint only, not on broader API success. [INFERRED] Severity `P1`. (`apps/pos/src/components/AppShell.tsx:35-39`, `apps/pos/src/stores/connectivityStore.ts:25-38`, `apps/pos/src/pages/LoginPage.tsx:11-12`, `apps/pos/src/pages/LoginPage.tsx:66-78`)
- After a long offline window, reconnect can fire multiple sync-entry signals close together, but `isSyncing` prevents parallel runs. User-visible outcome: not a storm of concurrent pushes, but still a bursty immediate retry pattern on every connectivity flap. Severity `P2`. (`apps/pos/src/lib/sync/syncScheduler.ts:63-69`, `apps/pos/src/lib/sync/syncScheduler.ts:70-125`, `apps/pos/src/components/AppShell.tsx:45-52`)
- “Last sync” is not durable across app restart, and it can update even when product/payment pulls silently failed because `runFullSync()` returns a result object rather than throwing. User-visible outcome: misleadingly fresh sync badge after partial or failed sync work. Severity `P2`. (`apps/pos/src/stores/syncStore.ts:50-57`, `apps/pos/src/components/atoms/SyncButton/SyncButton.tsx:44-60`, `apps/pos/src/lib/sync/syncService.ts:428-433`, `apps/pos/src/lib/sync/syncService.ts:451-455`)

### Severity

`P1`

### Recommended fix

- Start connectivity monitoring before login routing, and distinguish `network_online`, `health_online`, and `api_ready` states instead of collapsing them into one boolean. Effort `S`.
- Persist the last successful sync timestamp and startup backlog counts from SQLite so the header reflects real terminal state after restart. Effort `XS`.
- Count pull failures in the sync result and expose degraded-sync status separately from “last sync time”. Effort `S`.

## 8. Quick-Wins List

- Hydrate `pendingReceiptCount` from SQLite on startup so the header badge is truthful after restart. Effort `XS`. (`apps/pos/src/stores/syncStore.ts:67-69`, `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:100-106`)
- Persist `lastSyncAt` from `sync_log` / `sync_metadata` and restore it on boot so “Last sync” survives restart. Effort `XS`. (`apps/pos/src/stores/syncStore.ts:31-57`, `apps/pos/src/components/atoms/SyncButton/SyncButton.tsx:44-60`)
- Add a payment-config-ready guard before opening cash/advanced payment flows, so missing config fails early instead of inside checkout. Effort `XS`. (`apps/pos/src/pages/HomePage.tsx:347-350`, `apps/pos/src/pages/HomePage.tsx:373-376`, `apps/pos/src/stores/paymentStore.ts:228-244`)
- Roll back or defer auth persistence when `/user/companies` fails after login, to avoid the invalid authenticated-without-company state. Effort `XS`. (`apps/pos/src/stores/authStore.ts:154-179`, `apps/pos/src/App.tsx:87-104`)
- Replace the retail first-launch `per_page=500` warmup with a paginated loop or at least a temporary “catalog still syncing” banner whenever foreground data is known incomplete. Effort `XS-S` depending on scope. (`apps/pos/src/stores/productStore.ts:52-58`, `apps/pos/src/lib/sync/syncService.ts:381-409`)

## 9. Open Questions For The Human

- Is the intended backend contract for retail POS really “foreground `/products?per_page=500` plus background full sync”, or should first-launch retail warmup already be complete for 5000+ SKUs? The code currently does the former. (`apps/pos/src/stores/productStore.ts:52-58`, `apps/pos/src/api/productApi.ts:11-13`)
- Are `/payment-methods` and `/treasury/payment-methods` guaranteed to be equivalent for POS tenants, including activation filters and company scoping? The client currently assumes both. (`apps/pos/src/api/paymentApi.ts:4-10`, `apps/pos/src/lib/sync/syncService.ts:439-447`)
- What are the real 4G bandwidth/latency conditions at the go-live pharmacies, and are image URLs served from the same origin/CDN as the API? Payload-time estimates above are [INFERRED] from code shape, not field measurements.
- Is receipt ordering on the server explicitly terminal-scoped, or can cross-terminal ordering/hash interactions occur? The client code strongly assumes terminal scope. [INFERRED] (`apps/pos/src/lib/sync/syncService.ts:64-88`, `apps/pos/src/lib/db/repositories/terminalStateRepository.ts:40-49`)
- Should a terminal be allowed to reach shift open when `hashChainReady` is false, or should terminal seeding be a hard precondition earlier in bootstrap? Today it is only a banner plus checkout disable. (`apps/pos/src/stores/terminalStore.ts:83-109`, `apps/pos/src/pages/HomePage.tsx:542-546`, `apps/pos/src/pages/HomePage.tsx:630-633`)
