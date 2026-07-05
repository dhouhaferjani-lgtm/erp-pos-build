# POS Offline-First Hardening — Go-Live Plan (2026-04-30)

> **Goal:** ship a hardened Tauri POS today that a parapharmacy cashier can run on flaky 4G without producing the user-visible failures cataloged in the two `2026-04-30-pos-offline-first-audit-*` audits (Codex + Claude). Target: PR-ready by EOD, first client uses it tomorrow.
>
> **Operating model:** Opus does each implementation step. After every step, Codex runs an adversarial review (`codex:rescue` / `codex:gpt-5-4-prompting`) against the diff before the next step starts. Codex review is a *gate* — block on findings unless the human waives. Don't bundle multiple steps into one review.
>
> **Branch strategy:** work on `dev`. One feature branch per phase (`feat/pos-harden-phase-{1..5}`). Phase merges to `dev` after Codex pass + green CI. End of day: `dev → main` PR for the human to approve and merge.
>
> **Verification at every step:** (a) `cd apps/pos && pnpm typecheck && pnpm lint && pnpm test`; (b) build the Tauri app and smoke-test the critical path manually; (c) Codex adversarial review on the diff. No phase moves forward on red.

---

## Scope and non-goals

**In scope (today):**
- Eight quick-wins from the Claude audit §9 (each <2 h).
- The `paymentStore.refreshFromSQLite` gap (the dominant repeat-bug class).
- The post-login "authenticated without company" limbo.
- Foreground catalog warmup pagination (so 5000-SKU parapharmacy is usable).
- HTTP read-timeouts on every foreground request.
- Sync indicator truthfulness (`pendingReceiptCount`, `lastSyncAt`).
- A "config not loaded" guard so checkout fails fast and visibly.

**Explicitly out of scope (defer post-launch, written down for the human):**
- Full offline cold-start mode for fresh devices (L-effort; the realistic go-live has internet on day one).
- Batch receipt push for 1000-receipt offline backlogs (M-effort; client's first day is unlikely to hit this).
- Stranded-receipt operator UI (S-effort, but go-live cleanupStuckReceipts at 90 days is fine).
- Image preloading manifest (M-effort; lazy is acceptable for go-live).
- Server-side endpoint reconciliation (`/payment-methods` ↔ `/treasury/payment-methods`).

Capture deferred items as TODO comments + Linear/issues *during* this work, don't expand scope mid-flight.

---

## Phase 0 — Worktree, baseline, branch (0:00 → 0:20)

**Objective:** clean baseline so each phase's diff is reviewable in isolation.

1. Create worktree from `dev`: `git worktree add ../erp-pos-harden -b feat/pos-harden-phase-1`.
2. Run baseline: `cd apps/pos && pnpm install && pnpm typecheck && pnpm lint && pnpm test && pnpm build`. Snapshot the output to `docs/sessions/2026-04-30-pos-harden-baseline.txt`.
3. Confirm `apps/api` is reachable from the dev environment (we need it for cashier-flow smoke tests after each phase).
4. Spin up a 5000-product seed in a clean tenant (use existing `DemoTenantSeeder`-style command if one exists, otherwise script it). This is the parapharmacy fixture for all subsequent smoke tests.

**Codex gate:** none for Phase 0.

---

## Phase 1 — Auth + connectivity foundation (0:20 → 1:30)

> All five fixes touch boot/login surface. Bundling them into one phase keeps the auth state machine consistent across the diff. **Branch:** `feat/pos-harden-phase-1`.

### Step 1.1 — Roll back persisted auth on `/user/companies` failure

**File:** `apps/pos/src/stores/authStore.ts:130-186`.

**Change:**
- Move `setStoredValue(TOKEN, ...)`, `setStoredValue(USER, ...)`, and `set({ isAuthenticated: true })` to *after* the `apiGet<Company[]>('/user/companies')` resolves successfully.
- On companies failure, do not persist token/user, do not flip `isAuthenticated`, throw the original error so `LoginPage` shows it.
- Keep auto-select-on-single-company behavior untouched.

**Verify:** simulate a network drop after the login POST returns and before the companies GET completes — expected behavior is the user sees an error toast on `LoginPage` and can retry; no terminal-setup limbo.

**Codex review prompt (after this step's diff):**
> Adversarial review of `apps/pos/src/stores/authStore.ts` post-edit. Verify: (1) no path persists TOKEN/USER unless companies GET succeeded; (2) failure of companies GET leaves `isAuthenticated:false` and no Tauri Store entries; (3) the existing `initialize()` and `checkSession()` flows still work for already-logged-in users on app boot; (4) no race where two concurrent `login()` calls could leave inconsistent state. Save review to `docs/sessions/2026-04-30-pos-harden-codex-1.1.md`.

### Step 1.2 — Add `companies.length === 0 && isAuthenticated` recovery branch in router

**File:** `apps/pos/src/App.tsx:78-104`.

**Change:** insert a branch *before* the "needs terminal setup" check that catches `isAuthenticated && companies.length === 0`. Render a recovery screen that re-runs `/user/companies` on a button press (and auto-runs once on mount). Bonus: also render this if `companies.length === 0 && companyId === null` for users that previously had companies cached but now don't.

**Verify:** with Step 1.1 in place this branch is mostly defensive; force-feed `companies: []` via dev tools and confirm the recovery screen appears instead of a broken `TerminalSetupPage`.

**Codex review prompt:**
> Review `apps/pos/src/App.tsx` post-edit. Verify the new recovery branch cannot create a redirect loop with the existing `/login` and `/setup` branches. Confirm router behavior for these states: `(isAuthenticated:true, companies:[], companyId:null)`, `(isAuthenticated:true, companies:[A], companyId:A.id)`, `(isAuthenticated:true, companies:[A,B], companyId:null)`, `(isAuthenticated:false)`. Save to `docs/sessions/2026-04-30-pos-harden-codex-1.2.md`.

### Step 1.3 — Add HTTP read-timeouts via `AbortController`

**Files:** `apps/pos/src/lib/api.ts:68-123`, `apps/pos/src/lib/connectivity.ts:6-19`.

**Change:**
- In `request<T>`, build an `AbortController`, attach `signal: controller.signal` to the `fetch` call, and set a `setTimeout(() => controller.abort(), READ_TIMEOUT_MS)`. Default `READ_TIMEOUT_MS = 30_000`. Translate `AbortError` to `ApiRequestError(0, 'request_timeout', 'TIMEOUT')`.
- In `checkServerHealth`, same pattern with 5 s read timeout.
- Add a callsite override in `request<T>` so checkout's existing 5 s race in `offlineCheckoutService.ts:12-13` can use it instead of its own timer (cleanup, optional today).

**Verify:** `tcpdump`/devtools reproduction of a "TCP connect succeeds, no response" using `nc -l` on a port and pointing the POS at it. Login button should error within 30 s; health probe within 5 s.

**Codex review prompt:**
> Adversarial review of `apps/pos/src/lib/api.ts` and `apps/pos/src/lib/connectivity.ts`. Verify: (1) the AbortController is always cleared (`clearTimeout`) on success and on error to avoid leaking timers; (2) AbortError is correctly translated and not surfaced as "Network error" or "Unknown"; (3) the read-timeout doesn't compose badly with the existing 10 s `connectTimeout` (the longer one effectively wins for the connect phase); (4) all existing callers (`apiGet`, `apiPost`, `apiPut`, `apiDelete`, `checkServerHealth`) are covered. Save to `docs/sessions/2026-04-30-pos-harden-codex-1.3.md`.

### Step 1.4 — Move `connectivityStore.startMonitoring()` to boot

**File:** `apps/pos/src/App.tsx:144-184` (`MainApp`).

**Change:** add a `useEffect` in `MainApp` that calls `useConnectivityStore.getState().startMonitoring()` on mount and returns its cleanup. Remove the corresponding effect from `AppShell.tsx:36-39` (avoid double-monitoring).

**Verify:** open `LoginPage`, kill the API server, confirm `isOnline` flips to false within 10 s and the LoginPage's "no connection" panel appears. Repair the server, confirm flip back within 30 s.

**Codex review prompt:**
> Review the `connectivityStore.startMonitoring()` move from `AppShell` to `MainApp`. Verify only one monitor runs at a time (no double subscription). Confirm cleanup runs on `MainApp` unmount. Confirm the customer-display window (which renders `CustomerDisplayPage` directly per `App.tsx:30-31`) does *not* spin up a redundant monitor. Save to `docs/sessions/2026-04-30-pos-harden-codex-1.4.md`.

### Step 1.5 — `LoginPage` "still trying" affordance

**File:** `apps/pos/src/pages/LoginPage.tsx:18-78`.

**Change:**
- After 8 s of `isLoading` show a "Still working… check your connection" sub-text under the spinner.
- Show a "Cancel" button after the same 8 s that aborts the in-flight `login()`. Wire to a stored `AbortController` from the new infrastructure in 1.3.

**Verify:** captive-portal simulation — confirm the user can recover by clicking Cancel within 8-30 s instead of staring at a frozen spinner.

**Codex review prompt:**
> Review `LoginPage.tsx` for cancellation correctness. Verify: (1) cancelling clears the `isLoading` flag; (2) cancelling does not leave a partially-persisted token from Step 1.1's transactional login; (3) re-clicking "Sign in" after a cancellation creates a fresh AbortController. Save to `docs/sessions/2026-04-30-pos-harden-codex-1.5.md`.

### Phase 1 exit gate

- `pnpm typecheck && pnpm lint && pnpm test` green.
- Manual smoke test: cold launch → login → company auto-select → terminal setup (already provisioned) → PIN → shift open → cash sale. No regressions.
- All 5 Codex reviews in `docs/sessions/2026-04-30-pos-harden-codex-1.*.md`. Address P0/P1 findings before merging.
- Open PR `feat/pos-harden-phase-1 → dev`. Self-merge after green.

---

## Phase 2 — Payment-config recovery loop (1:30 → 2:30)

> **Branch:** `feat/pos-harden-phase-2`. The single highest-leverage cluster — fixes the "no cash method" recurring symptom.

### Step 2.1 — Add `paymentStore.refreshFromSQLite()`

**File:** `apps/pos/src/stores/paymentStore.ts`.

**Change:** add an action mirroring `productStore.refreshFromSQLite()`. Read `getAllPaymentMethods(db)` and `getAllPaymentRepositories(db)`, set them in state if non-empty (do not clobber a populated state with an empty SQLite read).

**Verify:** unit-style: clear in-memory state, call the action, confirm it repopulates from a seeded SQLite.

**Codex review prompt:**
> Review `paymentStore.refreshFromSQLite()`. Verify: (1) it never clobbers a populated in-memory state with empty SQLite results; (2) it tolerates SQLite read failure and logs without throwing; (3) it does not fight `fetchPaymentConfig` if both run concurrently — last write wins is acceptable; (4) it doesn't trigger React re-renders when methods/repositories are unchanged (use a shallow equality check or skip set when arrays are equal-by-id). Save to `docs/sessions/2026-04-30-pos-harden-codex-2.1.md`.

### Step 2.2 — Wire scheduler to refresh `paymentStore` after every tick

**File:** `apps/pos/src/lib/sync/syncScheduler.ts:73-78`.

**Change:** after the existing `useProductStore.getState().refreshFromSQLite()` call, add `usePaymentStore.getState().refreshFromSQLite()`. Order matters less than presence — pull happens, SQLite is fresh, both stores reseed.

**Verify:** end-to-end repro of the bug — boot offline, open shift (in-memory empty), bring network back, wait one tick (≤60 s), confirm cash button now works *without shift cycle*.

**Codex review prompt:**
> Review `syncScheduler.ts` post-edit. Verify the new refresh call cannot blow up the tick on payment store error (it should `.catch()` and log like the product call). Verify ordering: refresh happens *after* `runFullSync` writes to SQLite, not before. Save to `docs/sessions/2026-04-30-pos-harden-codex-2.2.md`.

### Step 2.3 — `paymentConfigReady` gate on `PaymentSummary`

**Files:** `apps/pos/src/components/pos/PaymentSummary.tsx:34-99`, `apps/pos/src/stores/paymentStore.ts`.

**Change:**
- Derive `paymentConfigReady = paymentMethods.length > 0 && paymentRepositories.length > 0` at the top of the component.
- When `!paymentConfigReady`: render the cash/card buttons in a disabled state with a `title` tooltip "Payment config not loaded — try Sync Now". Do not let the user enter a payment screen that will throw.
- Keep existing per-method validation in `processCashCheckout`/`processCardCheckout` as a defense-in-depth backstop.

**Verify:** force `paymentMethods: []` via dev tools, confirm cash button is visibly disabled and clicking does nothing.

**Codex review prompt:**
> Review `PaymentSummary.tsx`. Verify the disabled state is *visibly* disabled (not just inert), accessible via `aria-disabled`, and that the existing two-method "advanced payments" branch still renders only when ≥2 active methods. Save to `docs/sessions/2026-04-30-pos-harden-codex-2.3.md`.

### Step 2.4 — Pre-warm payment config at terminal activation

**File:** `apps/pos/src/stores/terminalStore.ts:83-110` (inside `seedOfflineHashChain`).

**Change:** after the SyncScheduler is started, fire `void usePaymentStore.getState().fetchPaymentConfig()` (don't await — must not block the seed). This means by the time the cashier reaches shift-open, payment config is already loaded.

**Verify:** confirm `paymentMethods` is populated before `HomePage`'s `useEffect` runs by adding a one-shot `console.log` in dev.

**Codex review prompt:**
> Review the pre-warm wire-up. Confirm: (1) it doesn't double-fetch if `HomePage` later calls `fetchPaymentConfig` again (it can — that's fine, but verify no race causes one to overwrite the other with stale data); (2) on a brand-new device with no cached payment config, the call still works after PIN entry rather than blocking activation. Save to `docs/sessions/2026-04-30-pos-harden-codex-2.4.md`.

### Phase 2 exit gate

Same as Phase 1: typecheck/lint/test green, manual smoke test, all Codex reviews addressed, PR `feat/pos-harden-phase-2 → dev` merged.

---

## Phase 3 — Catalog warmup that survives 5000 SKUs (2:30 → 4:00)

> **Branch:** `feat/pos-harden-phase-3`. The single largest go-live risk for parapharmacy.

### Step 3.1 — Foreground `fetchProducts` uses paginated pull

**File:** `apps/pos/src/stores/productStore.ts:52-159`.

**Change:**
- Refactor `fetchProductsFromAPI` for retail: instead of `fetchPOSProducts({ limit: 500 })`, delegate to a new exported helper `pullAllProducts(db)` in `apps/pos/src/lib/sync/syncService.ts` that wraps the existing `pullProducts` logic but returns `POSProduct[]` from the in-memory accumulation as well as writing to SQLite.
- Alternative if exposing internals is messy: have `fetchProducts` simply call `pullProducts(db)` (which already paginates and writes to SQLite) and then `refreshFromSQLite()` to load the full catalog into memory. Cleaner.
- Keep F&B `Menu` path (`fetchActiveMenu`) unchanged — it returns a single payload by design.

**Verify:** with the 5000-SKU fixture, fresh launch, confirm in-memory `products.length === 5000` after first warmup completes. Watch network panel — should see ~10 sequential `/products?per_page=500&page=N` requests.

**Codex review prompt:**
> Review the `fetchProducts` rewrite. Verify: (1) the SQLite-first instant-render path still works on second launch (cached products visible <500 ms); (2) `lastFetched` is only updated after the *full* paginated pull succeeds, not after page 1; (3) the diff path (`diffProducts`) correctly handles the 5000-product list without quadratic blowup (Map-based, so O(n) — confirm); (4) errors mid-pagination preserve whatever pages succeeded in SQLite and surface a "partial sync" indicator without claiming success. Save to `docs/sessions/2026-04-30-pos-harden-codex-3.1.md`.

### Step 3.2 — Catalog-warmup banner

**Files:** `apps/pos/src/stores/productStore.ts`, `apps/pos/src/components/organisms/ProductGrid/ProductGrid.tsx:155-175` (or a new banner component).

**Change:**
- Add `catalogWarmupState: 'idle' | 'warming' | 'partial' | 'complete' | 'error'` to `productStore`.
- During paginated pull, set `'warming'` and a `warmupProgress: { loaded: number; expected: number | null }` (expected can come from the first page's pagination metadata if the API returns one — verify; if not, just show `loaded` count).
- Render a sticky banner above the product grid: "Loading catalog: 1500 / 5000 products… you can continue selling, search may miss items". Auto-dismiss after `'complete'`.

**Verify:** start fresh, open shift, confirm banner appears and counts up; confirm it disappears at completion; confirm it stays as `'partial'` if the pull errors halfway.

**Codex review prompt:**
> Review the catalog-warmup banner. Verify: (1) it doesn't cause spurious re-renders on every page increment (debounce or batch state writes); (2) the `'partial'` state is recoverable on the next sync tick — `pullProducts` honors `updated_since` so subsequent ticks resume incrementally; (3) screen-readers can read the progress (`aria-live="polite"`). Save to `docs/sessions/2026-04-30-pos-harden-codex-3.2.md`.

### Step 3.3 — Allow shift open with partial catalog (don't gate)

**Decision point** — the human's answer to Open Question #4 in the audit determines this. Default: do NOT gate shift-open on catalog completeness. Cashiers must be able to start selling. The banner is the only signal.

**Change:** confirm there's no existing gate on `products.length` blocking shift-open. There isn't — this step is a verify-only step unless one is found.

**Codex review prompt:**
> Confirm via grep that no `disabled` state or modal gate on `HomePage`/`PaymentSummary` checks `products.length > 0`. Cashiers selling on a partial catalog must use barcode scan (which queries `/products?barcode=...` — verify that path doesn't depend on the full in-memory list) and product search must degrade gracefully. Save to `docs/sessions/2026-04-30-pos-harden-codex-3.3.md`.

### Phase 3 exit gate

Same. Heavier manual smoke this round: open shift on a 5000-SKU fixture with simulated 4G throttle (Chrome devtools "Slow 3G"), confirm cashier can sell on a partial catalog within ~5 seconds.

---

## Phase 4 — Sync indicator truthfulness (4:00 → 5:00)

> **Branch:** `feat/pos-harden-phase-4`. Trust signal — when a cashier looks at the header, the dot/badge/timestamp must be true.

### Step 4.1 — `pendingReceiptCount` reads SQLite, not "receiptsFailed"

**Files:** `apps/pos/src/stores/syncStore.ts:46-69`, `apps/pos/src/lib/sync/syncScheduler.ts:73-93`, `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:100-106`.

**Change:**
- Replace `pendingReceiptCount: result.receiptsFailed` in `completeSync` with a call to `getPendingReceiptCount(db)` *after* the tick completes. Pass the count via a new action `setPendingCount(count)` or extend `completeSync` to accept it.
- Add a startup hydration: in `seedOfflineHashChain` (`terminalStore.ts:83-110`), right after starting the scheduler, fire a one-shot `getPendingReceiptCount(db)` and call `useSyncStore.getState().setPendingCount(count)`.
- Add a hydration after every successful local receipt insert (already debounced by `scheduleDebouncedSync`) — bump the count by 1 on insert, then let the scheduler re-read it.

**Verify:** boot with 3 pending receipts in SQLite, confirm header badge shows "3" *before* the first tick. Process a 4th receipt offline, confirm "4". Bring network back, wait for tick, confirm "0" if all sync.

**Codex review prompt:**
> Review the `pendingReceiptCount` rework. Verify: (1) the count is read from SQLite (source of truth), not derived from sync results; (2) startup hydration runs even if the scheduler hasn't ticked yet; (3) optimistic increment on local insert doesn't drift from SQLite reality after several inserts; (4) no race where two ticks fire `setPendingCount` concurrently producing a stale value. Save to `docs/sessions/2026-04-30-pos-harden-codex-4.1.md`.

### Step 4.2 — Persist `lastSyncAt` to `sync_metadata`

**Files:** `apps/pos/src/stores/syncStore.ts:50-57`, `apps/pos/src/lib/sync/syncScheduler.ts:73-93`, `apps/pos/src/lib/db/repositories/syncLogRepository.ts`.

**Change:**
- After `completeSync(result)`, call `setSyncMetadata(db, 'last_sync_at', String(Date.now()))`.
- In `seedOfflineHashChain`, after the scheduler starts, read `getSyncMetadata(db, 'last_sync_at')` and call a new `setLastSyncAt(ts)` on the sync store to hydrate.

**Verify:** sync completes, restart the app, confirm SyncButton shows "X minutes ago" immediately on boot, not blank.

**Codex review prompt:**
> Review `lastSyncAt` persistence. Verify: (1) the timestamp is stored as ms-since-epoch and parsed back correctly; (2) hydration runs before the first tick so the user never sees blank-then-pop; (3) the store doesn't display a stale "5 hours ago" if SQLite has an older timestamp than the in-memory value (in-memory wins). Save to `docs/sessions/2026-04-30-pos-harden-codex-4.2.md`.

### Step 4.3 — Differentiate "synced" from "synced with errors"

**File:** `apps/pos/src/lib/sync/syncService.ts:823-887` and `apps/pos/src/components/atoms/SyncButton/SyncButton.tsx`.

**Change:**
- Add a `degraded: boolean` to `SyncResult` set when `receiptsFailed > 0 || zReportsFailed > 0 || (productsPulled === 0 && /* attempted */) || !paymentConfigPulled`.
- In `SyncButton`, render an amber dot inline when `lastSyncResult?.degraded === true` with tooltip showing the first error.

**Verify:** simulate `pullPaymentConfig` failure, confirm the SyncButton shows an amber affordance and the user can click it to retry.

**Codex review prompt:**
> Review the `degraded` signal. Verify the heuristic doesn't false-positive on valid empty catalogs (e.g., a tenant with no operators yet). Verify the amber dot is distinguishable from the green/red states already in the header. Save to `docs/sessions/2026-04-30-pos-harden-codex-4.3.md`.

### Phase 4 exit gate

Same. Smoke: kill the API mid-sync, confirm header truthfully reflects degraded state instead of "Last sync 1m ago" with a green dot.

---

## Phase 5 — Crash safety + small wins (5:00 → 6:00)

> **Branch:** `feat/pos-harden-phase-5`. Mop up the P3s that are still cheap.

### Step 5.1 — Move `scheduleDebouncedSync()` past `COMMIT`

**Files:** `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:88-91`, `apps/pos/src/lib/offline/receiptService.ts:216-224`.

**Change:** remove the `scheduleDebouncedSync()` call from inside `insertOfflineReceipt`. Move it to `createOfflineReceipt` *after* `db.execute('COMMIT')`.

**Codex review prompt:**
> Confirm the move doesn't lose a sync trigger in any callsite of `insertOfflineReceipt` (grep). Save to `docs/sessions/2026-04-30-pos-harden-codex-5.1.md`.

### Step 5.2 — Add fingerprinted regression tests for the bugs we just fixed

**Files:** `apps/pos/src/__tests__/`.

**Change:** small unit tests for each high-leverage fix. Don't aim for full coverage, just guard against regression of the specific bugs:
- Test that `paymentStore.refreshFromSQLite()` repopulates after a SQLite write.
- Test that `authStore.login()` does not persist TOKEN if `/user/companies` rejects.
- Test that `pendingReceiptCount` reflects SQLite, not `receiptsFailed`, after `completeSync`.
- Test that `request<T>` aborts after 30 s.

**Codex review prompt:**
> Review the regression tests. Confirm: (1) each test would fail against the pre-fix code (mentally simulate or run the test against an old commit); (2) tests don't depend on real network or real DB beyond the project's existing test fixtures; (3) tests use the project's existing patterns (Vitest + RefreshDatabase-equivalent for SQLite). Save to `docs/sessions/2026-04-30-pos-harden-codex-5.2.md`.

### Step 5.3 — TODO sweep + Linear/issue capture for deferred items

**Change:** for each "out of scope" item in the Scope section, drop a `// TODO(go-live-followup):` comment at a relevant code site and open an issue. This makes the deferral visible to the next person.

### Phase 5 exit gate

Same. PR `feat/pos-harden-phase-5 → dev` merged.

---

## Phase 6 — Final hardening pass + go-live PR (6:00 → 8:00)

### Step 6.1 — Run `./scripts/preflight.sh` from repo root + the POS-specific suite

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp
./scripts/preflight.sh
cd apps/pos && pnpm typecheck && pnpm lint && pnpm test && pnpm build
```

If anything is red: stop, fix, re-Codex-review the fix, re-run.

### Step 6.2 — Manual full-day cashier flow on the 5000-SKU fixture

Follow this script with stopwatch:

1. Cold launch app on a freshly-installed Tauri build. Time-to-login-form < 3 s.
2. Login. With 4G throttle (Slow 3G in devtools): time-to-PIN-entry should be < 15 s.
3. PIN entry → terminal setup if needed → shift open with €100 opening cash.
4. Add 5 products to cart via search (test the search across the full 5000). Add 1 via barcode scan.
5. Pay cash €120, confirm change €X displayed, receipt prints.
6. Toggle network OFF. Process 10 cash sales offline. Confirm header pendingReceiptCount climbs to 10.
7. Toggle network ON. Confirm pendingReceiptCount drains to 0 within 90 s.
8. Toggle network OFF mid-sale (between cart-add and pay). Confirm receipt still creates locally.
9. Generate Z report, close shift. Confirm Z prints.
10. Re-open shift, sell 1 item, close shift. Confirm second Z generates and chains correctly.

If any step fails: stop, fix, full Codex review of the fix, re-run from step 1.

### Step 6.3 — Codex full-branch adversarial review

Run `codex:rescue` against the full `dev` diff (everything since last `main` merge). Prompt:

> Adversarial review of the full POS hardening cluster. Read the audit at `docs/superpowers/audits/2026-04-30-pos-offline-first-audit-claude.md` and the plan at `docs/superpowers/plans/2026-04-30-pos-offline-first-hardening.md`. For every "P0/P1" finding called out in the audit, locate the fix in the diff and verify it actually resolves the named failure mode. Identify any *new* regression introduced by the cluster — particularly around the auth state machine, the sync scheduler ordering, and SQLite write paths. Save the review to `docs/sessions/2026-04-30-pos-harden-codex-final.md`.

Block on findings. Address P0/P1, document P2/P3 deferrals.

### Step 6.4 — Open `dev → main` PR

Standard flow: `gh pr create --base main --head dev` with a body that links the audit, the plan, the smoke-test transcript, and the final Codex review. The human approves and merges.

### Step 6.5 — Tag a release + ship installer

After main merges, tag `vYYYYMMDD-pos-harden` and run the installer build. Hand off to the parapharmacy client.

---

## Daily-standup-style summary template

Each phase, append to `docs/sessions/2026-04-30-pos-harden-progress.md`:

```
### Phase N — [name]
- Status: shipped / blocked / in-progress
- PR: <url>
- Codex reviews: [list]
- Findings addressed: [list]
- Findings deferred: [list with rationale]
- Smoke test: pass / fail
- Time spent: X:XX
- Notes: [anything the human needs to know]
```

---

## Risk register

| Risk | Likelihood | Mitigation |
|---|---|---|
| Step 1.1 breaks existing logged-in users on app restart | Low | `initialize()` reads cached token + companies independently from `login()`; rollback only affects new logins. |
| Step 2.4 pre-warm races with `HomePage` fetch | Medium | Both calls converge on `fetchPaymentConfig` which is idempotent; last-write-wins on identical data is safe. |
| Step 3.1 paginated foreground fetch slows perceived first-render | Low | SQLite-first instant-render path is preserved; pagination runs in background after first SQLite read. |
| 5000-SKU fixture isn't actually representative of the parapharmacy | Medium | Get the real product list from the client today if possible. Otherwise, oversample (10000 SKUs) to be safe. |
| A Phase N fix breaks a Phase <N feature | Medium | Codex review + manual smoke test after every phase. The phase exit gates are non-negotiable. |
| Codex review surfaces a finding we can't fix today | Low | Document the deferral in `docs/sessions/...` and reassess scope. Better to ship 6 fixes well than 8 fixes broken. |
| `dev → main` merge introduces a conflict with a hotfix landed today | Low | Pull main into dev before opening the final PR (per the team's existing `feedback_dev_main_promotion_discipline.md` guidance). |

---

## What "done" looks like at EOD

- All five phases merged to `dev`.
- Smoke test from Step 6.2 passes end-to-end on the 5000-SKU fixture.
- All Codex review files in `docs/sessions/2026-04-30-pos-harden-codex-*.md` show no unaddressed P0/P1 findings.
- `dev → main` PR is open with the human's review requested.
- Tagged release artifact is ready to install on the client's POS hardware.

If we get only Phase 1, 2, and 4 done by EOD, that's still a meaningful hardening: we close the auth limbo, the recurring "no cash method" bug, and the truthless sync indicator — the three highest-pain user-visible failures. Phase 3 (5000-SKU pagination) is the only remaining must-have for parapharmacy specifically; if it slips, we delay the client by 24 h rather than ship a known-broken catalog.
