# POS Offline-First Audit — 2026-04-30 (Claude, second opinion)

**What differs from the Codex audit.** I agree with Codex on the four headline P0/P1 findings (500-SKU foreground cap, post-login limbo when `/user/companies` fails, no offline cold-start path, late payment-config failures) and the underlying file:line citations check out. Where I diverge: (1) Codex described `pendingReceiptCount` as "not hydrated on startup"; the bug is worse — `completeSync` overwrites the count with `receiptsFailed`, so the badge mis-reports during normal operation, not just after restart. (2) Codex did not catch that **the sync scheduler refreshes `productStore` from SQLite after every tick but never refreshes `paymentStore`** — meaning a successful background `pullPaymentConfig` cannot rescue an in-memory empty payment-methods array, so a cashier who hits "no cash method" once stays stuck until shift cycle. (3) I'm less alarmed than Codex about reconnect-storms (the `isSyncing` guard and 250 ms debounce are tight) and more alarmed about the absence of a foreground HTTP read-timeout on the health probe (captive portal hang). (4) I push the offline-cold-start gap (Codex P0 #3) down to P1 because the realistic go-live scenario is "first launch with internet" — but it remains the dominant risk for a cashier whose first POS interaction is on a 4G dead-zone day.

---

## 1. Executive Summary (top 5, ranked by user pain)

1. **P0 — Retail catalog warmup truncates at 500 SKUs.** Confirms Codex. `productStore.fetchProducts()` calls `fetchPOSProducts({ limit: 500 })` on first launch, which becomes `/products?per_page=500` — single-shot, no pagination loop in the foreground path. Background `pullProducts()` paginates correctly, but it races with the cashier opening shift. For a 5000-SKU parapharmacy on day one, search/browse misses ~90 % of the catalog until a later sync pass writes more pages to SQLite *and* the user navigates in a way that re-runs `fetchProducts`. (`apps/pos/src/stores/productStore.ts:52-58`, `apps/pos/src/api/productApi.ts:6-13`, `apps/pos/src/lib/sync/syncService.ts:378-433`)

2. **P0 — Auth promotes to "authenticated, no company" on flaky internet, and the router routes the user past company selection.** Confirms Codex. Persistence + `isAuthenticated:true` happen at `authStore.ts:155-162` *before* `/user/companies` is fetched at line 167. If that GET fails, the app reboots into a state where `isAuthenticated && companies.length === 0`, which `App.tsx:88` does NOT recognize as "needs company selection" (the guard requires `companies.length > 1`). The router falls through to "needs terminal setup" at line 97, which then issues authenticated API calls without `X-Company-Id` and produces opaque failures. (`apps/pos/src/stores/authStore.ts:154-179`, `apps/pos/src/App.tsx:55-104`)

3. **P0 (new finding, extends Codex) — `paymentStore` is never reseeded from SQLite after a sync tick.** `syncScheduler.tick()` explicitly calls `useProductStore.getState().refreshFromSQLite()` at `syncScheduler.ts:76` but there is no `usePaymentStore.refreshFromSQLite()` (the method does not exist). So if a cashier opens shift while payment config is empty in memory (because the foreground `/payment-methods` GET timed out), the next successful background `pullPaymentConfig()` writes fresh methods/repositories into SQLite at `syncService.ts:439-455` — but the in-memory `paymentMethods` stays `[]`. Result: cashier sees "No cash payment method configured" *after* the network has recovered and SQLite is correct, until they navigate away and shift-open re-runs `fetchPaymentConfig()`. This is the recurring "no cash method" symptom. (`apps/pos/src/lib/sync/syncScheduler.ts:73-78`, `apps/pos/src/stores/paymentStore.ts:178-216` — note absence of `refreshFromSQLite`)

4. **P1 — `pendingReceiptCount` is wrong by design, not just stale on restart.** `syncStore.completeSync` writes `pendingReceiptCount: result.receiptsFailed` at `syncStore.ts:50-57`. That is the count of receipts the *just-finished tick* failed on, not the actual pending+failed queue depth. After a clean tick (everything synced), the badge reads 0 even if new offline receipts have already accumulated since the tick started. There is no startup hydration via `getPendingReceiptCount(db)` — that function exists at `offlineReceiptRepository.ts:100-106` but no caller. The Header's orange backlog badge is therefore unreliable in both directions. (`apps/pos/src/stores/syncStore.ts:50-57`, `apps/pos/src/components/Header.tsx:374-378`)

5. **P1 — Foreground HTTP requests have a 10 s connect-timeout but no read-timeout; health probe has 5 s connect-only.** `lib/api.ts:91` sets `connectTimeout: 10000` on every API call; `lib/connectivity.ts:11-14` sets `connectTimeout: 5000` on the health probe. Neither bounds total request duration. A captive portal that opens the TCP handshake but never returns a complete response (common 4G failure mode) will hang `checkServerHealth()` past its nominal 5 s — and hang login/terminal/shift-open foreground calls indefinitely once a connection is established. Cashier sees a frozen spinner with no "still trying…" affordance. (`apps/pos/src/lib/api.ts:87-92`, `apps/pos/src/lib/connectivity.ts:6-19`)

---

## 2. Cold-Start Auth Flow

### Current state (verified, not just paraphrased)

- `App.tsx:51-53` calls `authStore.initialize()` on mount. `initialize()` reads token/user/companyId/companies from Tauri Store and, if a token is present, sets `isAuthenticated:true` *immediately* with whatever was cached, then calls `checkSession()` (`/auth/me`). 401 → logout; network error → keep cached auth (correct for offline). (`authStore.ts:92-127`)
- `LoginPage.tsx:11,66-78` reads `isOnline` from connectivity store and renders a static "no connection" panel when offline. There is no PIN field. There is no fallback to a previously-provisioned terminal/operator state.
- `authStore.login()` persists token + user + sets `isAuthenticated:true` *before* calling `/user/companies`. If the companies fetch throws, the function rethrows, and `LoginPage` catches and shows the error — but the persisted token + the in-memory `isAuthenticated:true` are not rolled back. (`authStore.ts:154-185`)
- The router branch at `App.tsx:88-94` only treats `companies.length > 1 && !companyId` as "needs company selection". The `companies.length === 0 && isAuthenticated` case is not modelled — the router falls through to the "no terminal" branch.
- Terminal init waits for `isAuthenticated && companyId` (`App.tsx:55-59`). With the failure mode above, `companyId === null` keeps `initTerminal` from running, so the user lands on `TerminalSetupPage` but `terminal === null && !terminalLoading` is the gate (`App.tsx:97`), and that page calls `apiGet` which lacks `X-Company-Id`, producing 422/500 from the backend.
- Once a terminal is active, `seedOfflineHashChain()` runs `pullTerminalState`/`pullZChainState` and starts the `SyncScheduler`. If the network call inside those pulls fails, `hashChainReady` falls back to whatever SQLite already has (`terminalStore.ts:83-110`). On a fresh device, SQLite has no row → `hashChainReady` stays `false` → checkout disabled at `HomePage.tsx:632`, with only a banner.
- Operator PIN: `verifyPin` is offline-first via cached bcrypt hashes (`operatorStore.ts:52-105`). `setupPin` writes the local hash first, then attempts the API, queueing an offline pin update on failure (`operatorStore.ts:107-158`). This is good — but `checkHasPins` is API-first with SQLite fallback, and the API path can return `{ has_pins: false }` for an empty tenant even when local cache has hashes.

### Failure modes

- **Fresh device, no internet at all.** Confirmed Codex P0. The only path is online username+password; no PIN-only entry. Realistic mitigation cost is high (you'd need a "previously provisioned terminal can boot offline" mode), but the realistic *probability* on day one is moderate — the cashier almost always has internet during initial provisioning. Severity in practice: P1.
- **Internet flakes after `/auth/login` returns but before `/user/companies` returns.** Confirmed Codex P0. Causes the "authenticated without company" limbo described above. The exact path through the router depends on whether companies were ever cached: a brand-new install ends up bouncing on `TerminalSetupPage` with broken API calls. Severity: P0.
- **Internet flakes during `seedOfflineHashChain`.** Cashier reaches PIN/shift screens, opens shift, then can't checkout because `hashChainReady === false`. The banner explains the state but offers no retry button. Severity: P1.
- **Captive portal during boot.** `connectivityStore.isOnline` is initialized from `navigator.onLine` (true on captive WiFi), so `LoginPage` renders the form. The first `/auth/login` POST hangs past the 10 s connect-timeout window (because the connection succeeded — only the response stalled). Severity: P1.

### Severity

P0 (driven by the post-login limbo and captive-portal hang).

### Recommended fix

- Wrap the post-login block in a transaction: only persist `TOKEN/USER` and set `isAuthenticated:true` after `/user/companies` returns; on failure, clear in-memory auth and surface "couldn't load your companies — retry". Effort **S**.
- Add a `companies.length === 0 && isAuthenticated` branch in `App.tsx` that re-runs the companies fetch with an explicit retry button. Effort **XS**.
- Add a `requestTimeout` (read timeout) wrapper around `fetch` in `lib/api.ts` and `checkServerHealth`. 30 s for foreground, 5 s for health. Effort **S**.
- Optional but high-value: a "boot offline" mode for terminals with prior `terminal_state` rows in SQLite, that skips the online login if `TOKEN` is still present and fingerprint matches. Effort **L** — defer past go-live.

---

## 3. Product Catalog Warmup

### Current state

- `HomePage.tsx:171-177` triggers `fetchProducts()` and `fetchPaymentConfig()` only after `shift` is truthy — so the catalog is not warming during the seconds the cashier spends opening their shift.
- `productStore.fetchProducts()` is correctly SQLite-first: it loads cached rows, clears `isLoading`, then *parallels* a fresh API fetch in the background. (`productStore.ts:65-159`)
- **The 500 cap is real.** `fetchProductsFromAPI(config)` in retail mode is literally `return fetchPOSProducts({ limit: 500 })` (`productStore.ts:52-58`). `fetchPOSProducts` translates `limit` to `per_page` and issues *one* GET (`productApi.ts:6-13`). Empty SQLite + 5000-SKU tenant ⇒ in-memory cap of 500.
- The diff path (`productDiff.ts:22-36`) merges by id, so even on later refreshes the in-memory `products` array is rebuilt from whatever the foreground GET returned, not unioned with the SQLite cache. So if the foreground GET only returns 500 but SQLite has 4500 from a prior background sync, the user actually *loses* products from in-memory view temporarily, until `refreshFromSQLite()` runs after the next scheduler tick.
- Background `pullProducts()` paginates `per_page=500` until a short page comes back, applies `deleted_ids` tombstones, and writes each page to SQLite (`syncService.ts:378-433`). This is correct.
- Image preloading is opportunistic: `useProductImage` enqueues per-card downloads, `processDownloadQueue` drains in batches of 10 from inside `runFullSync` (`syncService.ts:865-869`). For a tenant with 5000 SKUs and product photos, the first day will have many "broken image" placeholders.
- After every successful tick, `useProductStore.getState().refreshFromSQLite()` reconciles in-memory products against SQLite (`syncScheduler.ts:76-78`). Good.

### Failure modes

- **First-launch retail, 5000 SKUs, opens shift before background sync finishes.** Cashier sees 500 products, with no UI signal that the catalog is incomplete. Codex's P0 stands. Severity: P0.
- **Foreground refresh on a stale online connection that times out.** `doApiFetch` swallows the error if local data exists. Good. But `lastFetched` is not updated, and there is no retry timer — the user has to navigate away/return. Severity: P2.
- **Tombstones during open shift.** A product deleted server-side propagates via `pullProducts`'s `deleted_ids`, but in-memory product list is rebuilt from `refreshFromSQLite`, so a product currently in the cart could disappear from the catalog mid-shift. Receipts already created snapshot line data, so the receipt is safe — but the cart item's `product` reference points at potentially-deleted data. (`receiptService.ts:174-191`, `productDiff.ts`) Severity: P3.

### Severity

P0.

### Recommended fix

- Replace `fetchProductsFromAPI` retail path with the same paginated loop `pullProducts` uses, *or* delegate first-launch warmup to `pullProducts(db)` directly and just `refreshFromSQLite` afterward. The paginated loop already exists; the foreground path duplicates only the first page badly. Effort **S**.
- Surface a "catalog warming, X / Y products loaded" banner driven by `sync_metadata.products_last_sync` + a count from SQLite. Effort **S**.
- Add an `image_warmup_target` setting and a bounded eager image fetcher that runs after catalog completeness, capped at e.g. 200 KB/image and 2000 images. Effort **M**.

---

## 4. Payment-Config Warmup

### Current state

- Triggered only after shift open at `HomePage.tsx:171-177` — same gate as products.
- SQLite-first then API: `paymentStore.fetchPaymentConfig` reads cached methods/repositories, then awaits `Promise.all([fetchPaymentMethods(), fetchPaymentRepositories()])` and overwrites in-memory state with the API result. (`paymentStore.ts:178-216`)
- Cache writes happen on success only. On API failure, the in-memory state holds whatever was loaded from SQLite (which may be `[]` on first launch).
- Endpoint divergence is real: foreground uses `/payment-methods` and `/payment-repositories` (`paymentApi.ts:4-9`), background `pullPaymentConfig` uses `/treasury/payment-methods` and `/treasury/payment-repositories` (`syncService.ts:441-444`). Two different routes, two different filtering surfaces.
- Checkout guards are deferred: `processCashCheckout` does the `paymentMethods.find(m => m.is_physical && !m.has_maturity && m.is_active)` heuristic at `paymentStore.ts:228-234`; if it returns undefined, throws "No cash payment method configured". Same pattern for cash register, card, card repo. (`paymentStore.ts:228-310`)
- **Critical finding (extends Codex):** `syncScheduler.tick()` calls `useProductStore.getState().refreshFromSQLite()` (`syncScheduler.ts:76`) but does not refresh `paymentStore`. There is no `paymentStore.refreshFromSQLite()` method at all. The in-memory payment config is only refilled by `fetchPaymentConfig`, which is only invoked from `HomePage` `useEffect` on `shift` change. So a cashier who opens shift with empty in-memory payment config and a successful background sync afterward stays broken until: shift closes and reopens, or app restarts.

### Failure modes

- **First launch + payment-config endpoints flake at shift-open time.** Cashier reaches payment screen, hits Cash, gets "No cash payment method configured". Confirmed Codex P1. Even after network recovers and `pullPaymentConfig` populates SQLite, the cashier stays broken. Severity: **P0** (I rate higher than Codex's P1 for this exact reason — the bug is recoverable on paper but the recovery never fires).
- **Endpoint drift between `/payment-methods` and `/treasury/payment-methods`.** I cannot verify the backend without leaving the POS app, but if filters differ (e.g., one applies `is_active`, the other does not), foreground and background populate the same SQLite table with different shapes. The latest write wins — so order of operations between `fetchPaymentConfig` (foreground) and `pullPaymentConfig` (sync) determines which view the cashier gets. Severity: P1, **with an open question for the human** (see §10).
- **Heuristic-based method picking.** `paymentMethods.find(m => m.is_physical && !m.has_maturity && m.is_active)` for cash, `r.type === 'cash_register' && r.is_active` for cash register. A tenant with multiple eligible cash methods picks the first one (insertion order from API). Not a bug per se, but means re-ordering on the server side silently changes which method the POS uses. Severity: P3.

### Severity

P0 (revised up from Codex's P1, because of the missing scheduler refresh).

### Recommended fix

- Add `paymentStore.refreshFromSQLite()` and call it from `syncScheduler.tick` right after `productStore.refreshFromSQLite()`. Effort **XS** (~15 LOC). **This is the single highest-leverage quick win in the audit.**
- Pre-load payment config at terminal-activation time (inside `seedOfflineHashChain`), not at shift-open. Effort **S**.
- Collapse `/payment-methods` ↔ `/treasury/payment-methods` to one endpoint, and remove the foreground/sync divergence. Effort **S** server-side.
- Add a `paymentConfigReady` boolean to the store and gate the cash/card buttons on it (with a "config loading…" tooltip). Effort **XS**.

---

## 5. Sync Engine Push Reliability

### Current state

- Receipts in SQLite, keyed by `idempotency_key`, ordered by `hash_sequence`. (`offlineReceiptRepository.ts:24-58`)
- `createOfflineReceipt` wraps `insertOfflineReceipt + advanceHashChain` in a `BEGIN TRANSACTION ... COMMIT/ROLLBACK` block (`receiptService.ts:216-224`). Crash-safe at the SQLite level.
- **Subtle race I want to flag:** `insertOfflineReceipt` at `offlineReceiptRepository.ts:88-91` calls `scheduleDebouncedSync()` from inside that uncommitted SQL transaction. The 250 ms debounce gives the outer `COMMIT` time to land before the sync triggers, so in practice this is safe. But it's a fragile timing assumption — a slow main-thread render could push `triggerSync()` past the 250 ms window while the `COMMIT` hasn't run yet. Worth a comment explaining the timing contract. Severity: P3.
- `pushOfflineReceipts` processes serially, batch-of-one over `/pos/receipts/sync` (`syncService.ts:172-186`). Idempotent on both sides via `idempotency_key`. `synced` and `duplicate` both treated as success. Server-truth `terminal_last_hash` and `terminal_hash_sequence` reconcile back via `advanceHashChain`, which has a regression guard.
- Retry: `incrementRetryCount`, max 5 (`offlineReceiptRepository.ts:129-156`). After 5 failures, the receipt is *invisible* to `getPendingReceiptsForSync` and ages out at 90 days (`cleanupStuckReceipts`). No operator-facing exception queue.
- Scheduler backoff: 1 min → 2 min → 4 min, capped at 5 min. Reset on clean success. Immediate retry on offline→online flip. (`syncScheduler.ts:9-12,35-45`)
- Chain-break detection halts the loop at the first chain_broken receipt (`syncService.ts:234-261`).
- After a 1000-receipt offline day, the queue replays as 1000 sequential HTTP round-trips. On a 200 ms RTT this is ~3.3 minutes of pure network time, plus server work. The hash chain ordering requirement makes parallel pushing risky, so the cost is structural — but a server-side `/pos/receipts/sync` could accept N receipts per call (it already returns `results: [...]`), so the client could batch e.g. 50 at a time without losing chain ordering. (`syncService.ts:184`)
- Reconnect-storm risk: I disagree with Codex's "bursty immediate retry" framing. The `isSyncing` guard at `syncScheduler.ts:67-68` and the same guard inside `triggerSync` (`syncStore.ts:75-83`) fully serialize entries. After offline→online, the immediate `tick()` runs once; subsequent visibility/debounce/interval triggers all bail at the guard. Once isSyncing flips back to false, the next trigger is at least one event-loop turn later. This is fine.

### Failure modes

- **1000+ pending receipts replay as 1000 sequential pushes.** Confirms Codex P1. Severity: P1.
- **Receipts that hit `retry_count >= 5` are silently invisible.** Confirms Codex P2. The cashier has no UI to see them. Severity: P2.
- **Pre-COMMIT `scheduleDebouncedSync` race.** New finding. P3.

### Severity

P1.

### Recommended fix

- Server-side: accept N receipts per `/pos/receipts/sync` call (it already returns a `results` array). Client-side: batch up to e.g. 50 contiguous receipts per push, preserving chain order. Effort **M**.
- Add a "Stuck receipts (5+ failures)" panel reachable from the sync indicator. Effort **S**.
- Move `scheduleDebouncedSync()` out of the `INSERT` repository into the `createOfflineReceipt` helper, *after* `db.execute('COMMIT')`. Effort **XS**.

---

## 6. Pull Strategy and Conflict Resolution

### Current state

- Push order: PIN updates → receipts → Z-reports → cash drawer. (`syncService.ts:836-848`) Then pulls: products → payment config → operators → terminal state → Z-chain state → tables → active menu. (`syncService.ts:851-857`)
- Catalog/config pulls are server-truth cache replacements via upsert (`syncService.ts:378-455`). No local merge semantics.
- Receipt line snapshots are JSON-frozen at sale time — already-created receipts are immune to subsequent catalog mutation. (`receiptService.ts:174-191`) Good.
- Terminal hash chain: local-authoritative once advanced; `pullTerminalState` catches the regression guard's `FiscalRegressionError` and treats it as success. (`syncService.ts:484-538`) Z-chain state has the same "preserve local if server null/zero/regressive" rule (`syncService.ts:556-668`). I read through `decideZChainUpsert` carefully — the comment at `syncService.ts:629-632` explaining the dimensional mix in `localSum` is good defensive engineering.
- Multi-terminal ordering: receipt payloads include `terminal_id`; `terminal_state` is keyed by `terminal_id`; chain reconciliation only mutates the terminal_id that pushed. So the client-side model is "per-terminal chain". Whether the server agrees is unverifiable from this code.

### Failure modes

- **Open shift sees mid-shift catalog/config mutations.** Confirms Codex P1. Severity: P1.
- **Multi-terminal ordering scope assumption is unverified.** Confirms Codex P2. The client behaves correctly *if* server hash chains are terminal-scoped. Severity: P2.

### Severity

P1.

### Recommended fix

- Ship a "config changed during shift" toast when `pullPaymentConfig` or `pullProducts` reports non-empty deltas during an open shift. Effort **S**.
- Add an integration test that runs N terminals concurrently against a shared backend and asserts hash-chain integrity per-terminal. Effort **M**.

---

## 7. Connectivity Detection

### Current state

- `connectivityStore.isOnline` is initialized from `navigator.onLine` (`connectivityStore.ts:21`). On a captive portal, `navigator.onLine` reports true, so login renders normally even though no API call will succeed. The first GET hangs.
- `checkNow()` first checks `navigator.onLine`, then probes `/api/v1/health` with `connectTimeout: 5000`. No read timeout. (`connectivityStore.ts:25-38`, `connectivity.ts:6-19`)
- Polling: 30 s when online, 10 s when offline. Driven by a 10 s `setInterval` that checks `lastCheckedAt`. (`connectivityStore.ts:44-51`) Slightly more wakeups than necessary, but inert.
- `startMonitoring` is called only inside `AppShell` (`AppShell.tsx:36-39`). LoginPage and TerminalSetupPage rely on the initial `navigator.onLine` snapshot.
- Reconnect triggers: scheduler subscribes to connectivity (`syncScheduler.ts:36-44`), `AppShell` triggers sync on visibilitychange + 1 min stale (`AppShell.tsx:42-59`), and offline receipt insert schedules a 250 ms debounced sync. All three converge on `triggerSync` which respects `isSyncing`. No storm.
- Header indicator (`Header.tsx:357-379`): green/yellow/red dot + offline pill + pending count badge. Reads from `useConnectivityStore` and `useSyncStore` directly. Truthful in real time *except* `pendingReceiptCount` (see §1.4) and `lastSyncAt` (in-memory, see below).
- `lastSyncAt` is in-memory only (`syncStore.ts:50-57`). After app restart, the SyncButton reads `lastSyncAt: null` and shows no time-ago label until the first tick completes — even though `sync_log` and `sync_metadata` in SQLite have authoritative timestamps. (`syncLogRepository.ts`, `migrations.ts:141-160`)

### Failure modes

- **Captive portal on launch.** Confirmed Codex P1, with my added detail that the missing read-timeout makes the hang unbounded. Severity: P1.
- **Sync indicator misreporting `lastSyncAt` after restart.** Confirmed Codex P2. Trivial fix. Severity: P2.
- **Health probe unbounded read.** New finding. Severity: P2 (it self-recovers when the next probe interval fires, but freezes the user-facing badge during the hang).

### Severity

P1.

### Recommended fix

- Persist `lastSyncAt` to `sync_metadata.lastSyncAt` and hydrate it on `setScheduler`. Effort **XS**.
- Add a read-timeout (`AbortController` with a 5 s timer) to `checkServerHealth` and a 30 s timer to `request<T>` in `lib/api.ts`. Effort **XS**.
- Start `connectivityStore.startMonitoring()` at app boot (in `MainApp` `useEffect`) instead of inside `AppShell`, so login/setup screens reflect real reachability. Effort **XS**.
- Distinguish three states: `network_online` (navigator.onLine), `health_online` (health probe ok), `api_ready` (last `/auth/me` or similar succeeded). Surface only the most pessimistic to the user. Effort **S**.

---

## 8. Reconciliation Table — Codex findings × my verdict

| Codex finding | My verdict |
|---|---|
| P0 #1 — `productStore.fetchProducts()` foreground caps at 500 SKUs | **Agree.** Verified at `productStore.ts:52-58` + `productApi.ts:11-13`. Background `pullProducts` paginates correctly; the bug is in the foreground path only. |
| P0 #2 — Post-login limbo when `/user/companies` fails | **Agree, and extend.** The router's "needs company selection" guard requires `companies.length > 1`; the `length === 0` case isn't modelled at all. App.tsx:88-94. |
| P0 #3 — No offline cold-start path for fresh device | **Agree on description, downgrade to P1 in practice.** Realistic go-live has internet at provisioning. The fix is L-effort and probably defer-past-launch. |
| P1 #4 — Payment-config failures surface late at checkout | **Agree, raise to P0.** Codex missed that `paymentStore` is never refreshed from SQLite after a sync tick (only `productStore` is — `syncScheduler.ts:76`). The recovery loop Codex assumed exists, doesn't. |
| P2 #5 — `lastSyncAt` non-durable, `pendingReceiptCount` not hydrated | **Agree on `lastSyncAt`, extend on `pendingReceiptCount`.** The bug is worse than "not hydrated": `completeSync` actively *overwrites* with `result.receiptsFailed`, which is not the queue depth. |
| Section 2 — captive portal probe risk (INFERRED) | **Agree, confirm with code reading.** No read-timeout on `fetch` — neither in `lib/api.ts` nor in `lib/connectivity.ts`. Connect timeout alone is insufficient. |
| Section 3 — image preloading is lazy | **Agree.** I'd weight this P3 not P2 for go-live: cashiers generally tolerate slow-loading product images more than missing products. |
| Section 5 — 1000-receipt replay is sequential | **Agree.** The server endpoint already supports a batch (`results: [...]`), so the fix is mostly client-side. |
| Section 5 — receipts at retry_count ≥ 5 are silently stranded | **Agree.** Add an exception queue UI. |
| Section 6 — pulled config can mutate mid-shift | **Agree.** Receipt line snapshots already protect already-created receipts. |
| Section 7 — reconnect can fire multiple sync entries close together (P2) | **Disagree on severity.** The `isSyncing` guard at `syncScheduler.ts:67-68` is sufficient. No storm. Drop to P3 / non-issue. |
| Section 7 — `lastSyncAt` updates even when pulls silently fail | **Agree.** `runFullSync` returns a result rather than throwing; need to count pull failures and surface "degraded sync". |
| (not in Codex) `paymentStore` is never reseeded from SQLite by the scheduler | **New finding, P0.** See §4. |
| (not in Codex) `pendingReceiptCount` is set to `receiptsFailed` rather than queue depth | **New finding, P1.** See §1.4. |
| (not in Codex) No HTTP read-timeout on foreground requests or health probe | **New finding, P1/P2.** See §7. |
| (not in Codex) `scheduleDebouncedSync` fires from inside an uncommitted transaction | **New finding, P3.** See §5. The 250 ms debounce makes it safe in practice. |

---

## 9. Quick-Wins (each <2 h)

1. **Add `paymentStore.refreshFromSQLite()` and call it from `syncScheduler.tick` after `productStore.refreshFromSQLite()`.** Single highest-leverage fix in this audit. Closes the recurring "no cash method" bug class. Effort **XS**.
2. **Replace `pendingReceiptCount: result.receiptsFailed` with `pendingReceiptCount: await getPendingReceiptCount(db)` inside `completeSync`.** Helper already exists at `offlineReceiptRepository.ts:100-106`. Effort **XS**.
3. **Persist `lastSyncAt` in `sync_metadata` and hydrate at `setScheduler`.** Effort **XS**.
4. **Roll back token persistence + `isAuthenticated:true` if `/user/companies` throws inside `authStore.login`.** Five lines of try/catch. Effort **XS**.
5. **Add an `AbortController` + 30 s read timeout to `lib/api.ts:request<T>` and a 5 s read timeout to `checkServerHealth`.** Effort **XS**.
6. **Move `connectivityStore.startMonitoring()` to `MainApp` `useEffect` so it runs from boot, not from `AppShell`.** Effort **XS**.
7. **Disable the cash/card buttons on `PaymentSummary` when `paymentMethods.length === 0`, with a "config not loaded" tooltip.** Effort **XS**.
8. **Move `scheduleDebouncedSync()` out of `insertOfflineReceipt` and into `createOfflineReceipt` after `COMMIT`.** Effort **XS**.

Doing items 1, 2, 3, 4, 5 alone removes the dominant cashier-facing pain modes for go-live without touching architecture.

---

## 10. Open Questions for the Human

1. Is `/payment-methods` semantically equivalent to `/treasury/payment-methods` — same activation filters, same company scoping, same shape — so that foreground and sync writes to the same SQLite table can never disagree? If not, which one is canonical, and can the other be retired?
2. Is the server-side hash chain *strictly* terminal-scoped, or can a multi-terminal store force the chain to be store-scoped? The client assumes terminal-scope at every reconciliation point.
3. Is there a server-side `GET /products` parameter that returns "all products at once" (or a cursor-based stream) for first-launch warmup, or is the only path the page=1, page=2, … loop currently in `pullProducts`?
4. For a parapharmacy go-live on flaky 4G: is it acceptable to gate the cashier on "catalog warmup is at least N % complete" before allowing `Pay Cash` to proceed, or must shift be openable with a partial catalog and degrade gracefully? Different answers imply different fixes for §3.
5. Is "stranded receipts" (retry_count ≥ 5) something the operator must triage daily, weekly, or never (just rely on cleanupStuckReceipts after 90 days)? The answer determines whether the exception queue UI is required for launch or punts to v2.
6. The current `MAX_SYNC_RETRIES = 5` means a receipt that fails 5 times in 5 minutes (transient backend hiccup) becomes stranded for 90 days. Should the retry counter reset after a successful sync of *any* receipt for the same terminal, so transient backend issues don't permanently strand a healthy terminal?
