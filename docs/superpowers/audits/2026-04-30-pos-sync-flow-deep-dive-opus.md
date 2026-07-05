# POS Sync-Flow Deep Dive — 2026-04-30 (Opus)

> **Scope:** End-to-end audit of the Tauri POS desktop app (`apps/pos`) sync flow, anchored on the user-reported correctness bug *"POS shows 'Échec du paiement' but the same receipt appears finalized in back-office."* No code is changed. Cited line numbers reference `dev` at the time of investigation.
>
> **Top-level verdict.** The server is *idempotent* on the offline-sync path (`/pos/receipts/sync`) and the client *does* re-enter recovery via the existing `lastReceiptIdempotencyKey` machinery. The user-reported symptom is therefore **not** caused by re-POSTing the same key against a non-idempotent server. It is caused by **structural retry behavior on the client**: every time the cashier hits Cash → Confirm again, `crypto.randomUUID()` allocates a *new* idempotency key (`apps/pos/src/lib/offline/receiptService.ts:151`). If the cashier interprets a non-checkout error toast (or a stalled-sync banner) as "the sale failed" and clicks Confirm a second time, two distinct receipts ride two distinct keys to the server, both succeed, and the back-office sees both. The fix class is therefore *retry suppression + UX truth*, not idempotency hardening.
>
> **Independent novel finding (not in either prior audit):** there is *no HTTP read-timeout* anywhere in the POS code path (`apps/pos/src/lib/api.ts:87-92`, `apps/pos/src/lib/connectivity.ts:11-14`). `connectTimeout` only bounds the TCP handshake. A request whose body is sent and committed server-side but whose response is dropped by the network will hang the client `fetch` indefinitely (or until the OS gives up minutes later). This is the strongest match to the "client never knew the receipt synced" half of the user's symptom.

---

## 1. Executive summary — top 5 ranked findings

| # | Severity | Finding | Where the bite lands |
|---|---|---|---|
| 1 | **P0** | **Each retry of `processCashCheckout` mints a new `idempotency_key` and a new local hash-chain row.** A cashier who retries because of *any* visual error — including a non-checkout error toast, the chain-break banner, or a stuck-sync indicator — produces a second receipt. Both will sync, both will succeed (different keys, no dedupe). Result: the cart is billed twice on the same physical sale. (`apps/pos/src/lib/offline/receiptService.ts:151`, `apps/pos/src/stores/paymentStore.ts:218-279`) | Direct double-billing. |
| 2 | **P0** | **No HTTP read timeout on any client request.** `connectTimeout: 10000` covers only the TCP handshake. A request whose response is silently dropped (cellular tower handoff, server LB transient, captive-portal interception) will hang past the user's patience while the server has already committed. The receipt sits in `status='syncing'` (`updateReceiptStatus(db, id, 'syncing')` was called at `syncService.ts:180`) and is *not* re-queued by `getPendingReceiptsForSync` (which filters `status IN ('pending','failed')`). The cashier sees an apparently-stuck POS and may force-close, retry, or escalate — driving the bug-1 retry path. (`apps/pos/src/lib/api.ts:87-92`, `apps/pos/src/lib/connectivity.ts:11-14`, `apps/pos/src/lib/sync/syncService.ts:180`, `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:131-139`) | Client never learns the result of a real-world request. |
| 3 | **P0** | **Receipts left in `status='syncing'` after a process kill, JS reload, or a hung POST become invisible to the next sync run.** `getPendingReceiptsForSync` only matches `pending` or `failed` (`offlineReceiptRepository.ts:131-139`). There is *no* sweep that demotes orphaned `syncing` rows back to `pending` on app boot. So if `pushOfflineReceipts` is mid-call when the app is killed (or when a hung HTTP request never returns), the receipt is stranded silently and never re-pushed. The server may or may not have it — the client can no longer even ask. | Silent loss of either retry-eligibility or audit visibility. |
| 4 | **P1** | **Observability blackout in checkout's catch block.** `paymentStore.processCashCheckout`'s catch (`paymentStore.ts:272-278`) sets a user-facing message but does *not* `console.error` the underlying error class, message, or stack. The consolidation checkpoint (Tier 0 §C0.1) already calls this out; what hasn't been documented is that **four** further async paths in the sync chain swallow errors silently (`syncService.ts:830-833, 863, 869`, `syncStore.ts:80-82`), each of which can hide a partial-sync failure that contributes to the "client thought it failed, server didn't" symptom. | Failure diagnosis is impossible from the field. |
| 5 | **P1** | **Hash-chain divergence after a retry-then-double-create.** When the cashier retries (bug 1), the local chain advances *twice* (`receiptService.ts:219`). The server accepts both because each `previous_hash` lines up with its predecessor's offline-computed hash. The client and server end up *consistent* but each holds *two distinct receipts*. `pullTerminalState`'s `FiscalRegressionError` guard (`syncService.ts:512-531`, `terminalStateRepository.ts:114-169`) preserves local — which is *exactly* what protects the bug. The chain-break banner the user is also seeing is a *different* divergence pattern (the local chain advanced but a sync attempt was rejected), and it's distinct from the double-billing pattern but shares the same retry trigger. | The double receipt is fiscally legitimate on both sides; it cannot be self-detected without a cart-hash side channel. |

**Net read of the bug class.** Idempotency works on the wire. The bug is upstream of the wire: the *retry semantics on the client side regenerate the unique key*, and the user has visual triggers (sync banners, stuck UI from no read timeout, generic catch-all error toasts) that prompt them to retry without knowing the first attempt is in flight. The smallest fix that closes the bug class is **retry suppression** on the cart side: while there is *any* `pending` or `syncing` row in `offline_receipts` whose `lines` JSON matches the current cart and whose `created_at` is less than 30 seconds old, the Confirm button must be disabled and the cashier must see "your sale is being recorded — please wait." Combined with a read timeout on `fetch`, the bug becomes structurally unreachable.

---

## 2. Receipt push contract

### Two routes exist; only one is idempotent

| Route | Controller | Service | Idempotent? |
|---|---|---|---|
| `POST /pos/receipts/sync` | `SyncController::syncReceipts` (`apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php:54-89`) | `ReceiptSyncService::syncBatch` (`apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:67-105`) | **YES.** `syncSingleReceipt` line 115 looks up `Receipt::where('idempotency_key', $payload->idempotencyKey)->first()`; if a row exists, returns `SyncReceiptResult::duplicate(...)` with the original `fiscal_hash`, the *current* `terminal->last_hash`, and `current_sequence - 1` (lines 116-129). |
| `POST /pos/receipts` | `ReceiptController::store` (`apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:261-316`) | `ReceiptCreationService::createReceipt` (`apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:99-661`) | **NO.** Does not accept `idempotency_key` in `StoreReceiptRequest`; does not query for a pre-existing key; will create a duplicate row on every retry. |

### Which route does the POS use?

- **Production checkout flow:** `paymentStore.processCashCheckout` → `createReceiptLocalFirst` → `createOfflineReceipt` → SQLite + `triggerSync` → `pushOfflineReceipts` → `apiPost('/pos/receipts/sync', { receipts: [payload] })`. (`apps/pos/src/stores/paymentStore.ts:218-279`, `apps/pos/src/lib/offline/receiptService.ts:101-237`, `apps/pos/src/lib/sync/syncService.ts:178-186`.)
- **The non-idempotent `/pos/receipts` route is reachable only via dead code.** The function `createReceipt` in `apps/pos/src/api/receiptApi.ts:14-18` and its caller `onlineCheckout` in `apps/pos/src/lib/offline/offlineCheckoutService.ts:72-104` are imported nowhere in production source — only in tests (`grep -rn 'executeCheckout' apps/pos/src/` returns only test files and the function itself). `paymentStore.offlineFirst.test.ts:91` actively asserts `createReceipt` is *never* called.

**Latent risk (P3):** the dead code is one accidental import away from being live. If a future PR re-enables the online path on `executeCheckout` (or a back-office UI gets a "create receipt" button that hits `POST /pos/receipts`), every retry against that route will silently mint duplicates. **Recommendation:** delete `onlineCheckout`, `createReceipt`, `executeCheckout`, and the `/pos/receipts` route on the server, OR add `idempotency_key` to `StoreReceiptRequest` and dedupe in `ReceiptCreationService`.

### The successful response shape (sync route)

`SyncReceiptResponseItem` (`apps/pos/src/lib/sync/syncService.ts:90-100`) includes:

| Field | Meaning |
|---|---|
| `idempotency_key` | Echo of the request key — used by the client to correlate. |
| `status` | `'synced' | 'duplicate' | 'failed' | 'chain_broken'` |
| `receipt_id` | Server-assigned UUID. Stored back via `setServerReceiptId` (`syncService.ts:195`). Persists locally even on duplicate so the success modal can later upgrade from local SQLite preview to API receipt. |
| `server_fiscal_hash` | Echo of the receipt's hash. |
| `terminal_last_hash` | Server's *current* terminal-level chain head **after** sealing this receipt. Distinct from `server_fiscal_hash` only when the server has additional receipts ahead (e.g., another terminal? — see open question Q4). |
| `terminal_hash_sequence` | Server's current sequence after sealing this receipt. |

### Idempotency end-to-end — verified invariants

1. Client computes `idempotency_key = crypto.randomUUID()` once per local receipt creation (`receiptService.ts:151`). Stored on `offline_receipts` (`migrations.ts:93` — `UNIQUE NOT NULL`).
2. Client POSTs to `/pos/receipts/sync` (`syncService.ts:184`). Treats both `synced` and `duplicate` as success and runs the same `setServerReceiptId` + `advanceHashChain` reconciliation (`syncService.ts:191-232`).
3. Server's `Receipt::where('idempotency_key', ...)->first()` is the dedupe gate; the column is `UNIQUE` per migration `2026_03_12_100000_add_idempotency_key_to_pos_receipts.php`. The duplicate response includes the *current* terminal hash state to let the client reconcile correctly even if it missed an earlier `synced` response (`ReceiptSyncService.php:117-129`).
4. `SyncReceiptsRequest::receipts.*.idempotency_key` is `required|string|max:255` (cited from grep — `apps/api/app/Modules/POS/Presentation/Requests/SyncReceiptsRequest.php:51`). Required at the validation boundary.

**The contract on the wire is sound.** The bug is upstream.

---

## 3. Failure-mode taxonomy

For each network shape, what does the client do today?

| Network shape | Client behavior | Receipt status | Cashier sees |
|---|---|---|---|
| **DNS resolution fails** | Tauri `fetch` rejects with a generic `Error`. `pushOfflineReceipts` catch at `syncService.ts:249-262` runs: `incrementRetryCount`, `updateReceiptStatus(failed, message)`, `logSyncOperation('error', ...)`. `isChainBreakError` is false (DNS error doesn't contain "hash chain"), so chainBreak stays false. | `failed`, `retry_count++`. Re-queued by next tick (it's still `< 5`). | If checkout-time: cashier sees the local receipt success modal (the local create already succeeded). Background sync indicator goes red. **Cashier does not directly see the DNS error** — there's no toast for it. |
| **TCP connect timeout (10 s)** | Same path as DNS. Tauri `fetch` rejects after 10 s. (`api.ts:91`) | Same as DNS. | Same as DNS. |
| **Read timeout MID-REQUEST (server got body, response never arrives)** | **NO GUARD EXISTS.** `fetch` will hang for the OS-default duration (often minutes). `pushOfflineReceipts` is `await`ing the call. The receipt is in `status='syncing'` (set at line 180 *before* the request). `isSyncing` in `useSyncStore` is true — locking out new ticks. Eventually the OS gives up; catch fires; receipt → `failed`, retry_count++. | While hung: `syncing` (invisible to retry sweep). After OS gives up: `failed`. | Spinner if any sync indicator is rendered; otherwise nothing. The local receipt success modal already showed. **If the cashier is on the cash payment screen, they see the modal stick around because the success state is set but the modal close handler depends on lastReceiptServerId in some flows (`HomePage.tsx:213`).** |
| **5xx AFTER server commit (response failed)** | If the client never receives a response at all, behavior matches the read-timeout case above. If the client receives a 5xx body: `request<T>` throws `ApiRequestError(500, ...)` (`api.ts:102-118`). pushOfflineReceipts catch: `incrementRetryCount`, `updateReceiptStatus(failed, ...)`. Next tick re-pushes the SAME idempotency key. **Server returns `'duplicate'` with the original receipt's data — full recovery.** | First call: `failed`. Second call: `synced`. | First call: same as DNS. Second call (after backoff/connectivity recovery): green. |
| **5xx BEFORE server commit** | Same client-side path as 5xx-after-commit. Next retry actually creates the receipt (status `'synced'`). | `failed` → `synced`. | Same as above; recovers. |
| **Client process killed mid-POST (SIGKILL)** | Receipt was set to `'syncing'` at `syncService.ts:180` before the POST. **No bootstrap sweep promotes orphaned `'syncing'` rows back to `'pending'` on next start.** They're invisible to the sync runner forever. The receipt is on the server only if the server saw the POST. | `'syncing'` indefinitely. **Stranded.** | Nothing — the cashier sees the next launch's terminal status as normal. |
| **Network drops between request and response** | Behaves like read-timeout. No bound. | `'syncing'` until the OS times out, then `'failed'`. | Stuck UI if any UI awaits sync; otherwise nothing. |

### Severity

- The **stranded-`syncing`** path (kill or hung-then-OS-timeout) is the most insidious. It blocks the chain (hash_sequence is locally advanced but the receipt is not in the eligible queue). The next *successful* receipt will have a `previous_hash` that points to the stranded receipt's hash. Server will accept it because the stranded receipt did or didn't reach the server, but the *client's view of "what's pending"* is broken.
- The **read-timeout** path is the proximate cause of the user-reported symptom, because it lets the cashier perceive a stalled checkout while the server has already committed.

### Recommended fixes (sketch only)

1. **Boot-time `'syncing'` reaper.** On `SyncScheduler.start()`, run `UPDATE offline_receipts SET status='pending' WHERE status='syncing'`. There is no concurrent sync at that point (we're starting up), so it's safe. Effort: 5 LOC.
2. **Read timeout via AbortController on `lib/api.ts:request<T>`.** 30 s for foreground, 60 s for `pushOfflineReceipts` (large payload, slow link tolerance), 5 s for `checkServerHealth`. Effort: 15 LOC.
3. **Retry guard before re-creating a local receipt.** In `paymentStore.processCashCheckout` (and the card/advanced variants), before the catch path completes, check `getPendingReceipts(db)` for a row with the *same total + same line count + created within last 30 seconds*. If found, surface "your sale is being recorded — please wait" instead of `errors.checkoutFailed`. Effort: 30 LOC.

---

## 4. Sync push reliability

### When does the queue drain?

- **Initial tick** when `SyncScheduler.start()` is called from `terminalStore.seedOfflineHashChain`. (`syncScheduler.ts:25-44`)
- **Interval tick** every 1 min (base) up to 5 min (after backoff). (`syncScheduler.ts:9-12, 31-33`)
- **Connectivity transition** offline → online fires immediate `tick()` and resets interval. (`syncScheduler.ts:36-44`)
- **Receipt insert** schedules a 250 ms-debounced `triggerSync` from inside `insertOfflineReceipt` (`offlineReceiptRepository.ts:9-22, 88-91`). The debounce has a subtle timing dependency on the outer `BEGIN/COMMIT` transaction in `receiptService.ts:216-224` — the 250 ms gives `COMMIT` time to land first. Fragile but currently safe.
- **Visibility change** in `AppShell` triggers `triggerSync` after 1 min stale. (`AppShell.tsx:42-59` — cited from prior audits.)

### Head-of-line blocking?

`pushOfflineReceipts` iterates `getPendingReceiptsForSync(db)` ordered by `hash_sequence ASC` (`offlineReceiptRepository.ts:131-139`). On chain-break, it `break`s out (`syncService.ts:241, 261`). On any other failure: continue to next receipt. So a single non-chain-break failure does *not* block the queue.

**However:** `MAX_SYNC_RETRIES = 5`. After 5 transient failures, the receipt is invisible to `getPendingReceiptsForSync` (still `failed`, but `retry_count >= 5`). It's now blocked. `cleanupStuckReceipts` deletes it after 90 days (`offlineReceiptRepository.ts:177-185`). There is *no operator-visible queue* for stuck receipts. `getStuckReceipts` (`offlineReceiptRepository.ts:149-157`) is exported but no caller uses it.

### Dead-letter pattern

None. `getStuckReceipts` is dead code in this sense — it exists, it's tested, but no UI surfaces stranded items. **Risk:** a transient backend issue that lasts longer than 5 retry windows (5 min × 2 backoff × 2 backoff = 20 min — small but real) permanently strands receipts. The 90-day cleanup then *deletes them*, taking the offline-fiscal-hash + previous_hash with them. **The fiscal chain on the client is now structurally inconsistent with the server's perception of which receipts exist.**

### User-visible signal when a receipt is stuck

- Header backlog badge (`Header.tsx:374-378`, cited from prior audits) reads `pendingReceiptCount` from `useSyncStore`. After `completeSync`, `syncStore.ts:50-57` writes `pendingReceiptCount: result.receiptsFailed` — *not* the queue depth, *not* including stuck rows. So a stranded receipt is invisible to the operator.

### Recommended fixes

1. **Reset `retry_count = 0` on next *successful* sync of any receipt for the same terminal.** Transient backend hiccups should not permanently strand healthy terminals. Effort: 5 LOC. (Same as Claude prior audit's open question 6.)
2. **Stuck receipts panel.** Render `getStuckReceipts(db)` with manual "force retry" button (resets retry_count, requeues). Effort: M.
3. **Hydrate `pendingReceiptCount` from `getPendingReceiptCount(db)` at scheduler start AND replace the buggy line at `syncStore.ts:56`.** Already on prior audit's quick-win list.

---

## 5. Hash chain divergence recovery

### The pull/repair path

`pullTerminalState` (`syncService.ts:484-538`) fetches the server's `genesis_seed`, `last_hash`, `hash_sequence` and calls `upsertTerminalState`. The repository function (`terminalStateRepository.ts:114-169`) compares incoming `hash_sequence` with the current local row; if `incoming < current`, it throws `FiscalRegressionError`. `pullTerminalState` catches that error and treats it as success (`syncService.ts:512-531`). Net: when the local chain is ahead of the server (because we have unsynced receipts), local wins.

### Is preserving local always correct?

**Direction A — local ahead of server (offline receipts not yet pushed).** Correct. We must not rewind the local chain or we'll create offline receipts with a stale `previous_hash` that the server will reject.

**Direction B — server ahead of client (e.g., the cashier thinks K1 failed but it actually committed; client retried with K2 which also committed).** **Subtle case, partially correct.** After both K1 and K2 commit server-side, the server's `hash_sequence = N + 2`. The client also has `hash_sequence = N + 2` locally (because each retry advanced the local chain). They match. `pullTerminalState` will simply upsert with the same sequence — no regression error fires. The chain looks consistent. **But the client now has no signal that the sale was double-recorded.**

**Direction C — server has advanced past the client (e.g., a different terminal? offline receipt pushed by a different process?).** If the server's `hash_sequence > local`, `upsertTerminalState` accepts it (no regression). Local catches up. **But in single-terminal-per-chain world, this should be impossible** — we don't have a path that advances the server chain without advancing the local chain. If it ever happens, it's a corruption. The current code silently masks it.

### Specific scenario — "server has advanced past the client because a 'failed' POST actually committed"

Trace through the code:

1. Client creates K1 locally. `advanceHashChain` runs, local sequence → N+1, local last_hash → H1.
2. Client POSTs K1 to `/pos/receipts/sync`. **Server commits.** Server's terminal `last_hash` → H1, `current_sequence` → N+2.
3. Server response is dropped (read timeout or 5xx-after-commit). Client catch: `incrementRetryCount`, `updateReceiptStatus(K1, 'failed', message)`. (`syncService.ts:249-262`)
4. Next sync tick re-pushes K1. Server's `Receipt::where('idempotency_key', K1)` is non-null. Server returns `'duplicate'` with `terminal_last_hash: H1`, `terminal_hash_sequence: N+1`. (`ReceiptSyncService.php:121-129`)
5. Client `advanceHashChain(db, terminalId, H1, N+1)` is called (`syncService.ts:211-216`). `terminalStateRepository.advanceHashChain` (lines 171-213) checks `before === null || newSequence <= before`. Local `before` is N+1 (from step 1). `newSequence` is N+1. **N+1 ≤ N+1 is true → throws `FiscalRegressionError`.**
6. Caller catches the regression in `syncService.ts:217-230` and logs it. `K1` is marked `'synced'`. **All consistent.** Good.

**This works correctly today.** The double-billing risk is *not* in this path. It's in the path where the cashier retries via the UI before sync completes.

### Cart-double-create scenario (the actual bug)

1. Client creates K1 locally. Local sequence → N+1, last_hash → H1. Sync starts in background.
2. While sync is in flight (could be the read-timeout case), cashier sees something they interpret as failure and clicks Confirm again.
3. `processCashCheckout` runs again. `createOfflineReceipt` reads local terminal_state (`receiptService.ts:108`) — currently at sequence N+1, last_hash H1. New receipt K2 generated with `previous_hash = H1`, sequence N+2, hash H2.
4. Local sequence → N+2, last_hash → H2.
5. Background sync drains both K1 and K2 in order. Server commits K1 (advance to N+1/H1) then K2 (whose `previous_hash = H1` matches server's last_hash → accept). Server final state: sequence N+2, last_hash H2.
6. Server has **two receipts**. Back-office shows two. Client also has two in `offline_receipts`, both `synced`. **Hashes match, chain is intact, audit-trail is internally consistent. There is no automated way to detect this is a double-bill.**

The only structural defense is *upstream* of the local create: prevent the second `processCashCheckout` from running.

### Recommended fixes

1. **Cart-hash dedupe gate.** Before `createOfflineReceipt`, hash `(terminal_id, sorted line ids, total)` and check `offline_receipts` for a row with that hash + `created_at > NOW() - 30s`. If found, refuse and surface "your sale is being recorded — please wait." Effort: M (need a new column or a derived hash query).
2. **Cheap alternative — UI lock.** Set `isProcessing = true` from the *moment the Confirm button is tapped* and don't unset it until either `lastReceiptServerId` is populated *or* 60 s have elapsed. The current `isProcessing: false` at `paymentStore.ts:271` clears too early — it only awaits the *local* create. Effort: 5 LOC.
3. **No-op for the wire — server already does the right thing.** Don't try to make the server detect "two receipts in 30 s with the same totals" — that's a UX, not a fiscal, concern.

---

## 6. Idempotency end-to-end (review)

```
┌────────────────────────────────────┐                ┌────────────────────────────────────┐
│  CLIENT (Tauri)                    │                │  SERVER (Laravel)                  │
│                                    │                │                                    │
│  paymentStore.processCashCheckout  │                │                                    │
│   └─> createReceiptLocalFirst      │                │                                    │
│        └─> createOfflineReceipt    │                │                                    │
│             • crypto.randomUUID()  │   K=K1         │                                    │
│             • BEGIN TX             │                │                                    │
│             • INSERT offline_recpt │                │                                    │
│             • advanceHashChain     │                │                                    │
│             • COMMIT               │                │                                    │
│             • scheduleDebouncedSync│                │                                    │
│        set({ lastReceipt, K1 })    │                │                                    │
│                                    │                │                                    │
│  triggerSync() ───────POST /pos/receipts/sync─────> │  SyncController::syncReceipts      │
│                                    │   {K1, ...}    │   └─> ReceiptSyncService::syncBatch│
│                                    │                │        └─> syncSingleReceipt       │
│                                    │                │             • Receipt::where(K1)   │
│                                    │                │             • [exists?] → DUP path │
│                                    │                │             • [not?]   → COMMIT    │
│                                    │                │                                    │
│  pushOfflineReceipts.catch          <───response──── │                                    │
│   • on synced/duplicate:           │   {status,     │                                    │
│     - setServerReceiptId           │    receipt_id, │                                    │
│     - advanceHashChain (reconcile) │    term_hash,  │                                    │
│     - updateReceiptStatus(synced)  │    term_seq}   │                                    │
│   • on failed:                     │                │                                    │
│     - incrementRetryCount          │                │                                    │
│     - updateReceiptStatus(failed)  │                │                                    │
│     - next tick re-POST K1 ────────┼────────────────┼─> server returns DUPLICATE         │
│                                    │                │                                    │
└────────────────────────────────────┘                └────────────────────────────────────┘

Invariants:
  I1. K1 is generated exactly once per local create. ✓ (receiptService.ts:151)
  I2. K1 is in offline_receipts.UNIQUE column. ✓ (migrations.ts:93)
  I3. Server has UNIQUE index on pos_receipts.idempotency_key. ✓ (migration 2026_03_12_100000)
  I4. 'synced' and 'duplicate' both succeed client-side. ✓ (syncService.ts:191)
  I5. After-the-fact reconcile via advanceHashChain accepts server-truth-or-local-ahead. ✓ (terminalStateRepository.ts:171-213)

Broken (real-world):
  X1. cashier retries → new K2 → server creates 2nd receipt → DOUBLE BILL (no dedupe gate exists in client UI)
  X2. read-timeout → request hangs → 'syncing' → unsweepable on next launch
  X3. cleanupStuckReceipts deletes after 90 days → fiscal-chain-on-client diverges from server
```

---

## 7. Observability instrumentation checklist

Every line below currently swallows or under-logs an error. The structured payload shape is the same throughout: `{ scope, op, error_class, error_message, terminal_id?, idempotency_key?, hash_sequence? }`.

| File:Line | Today | What to log | Why it matters |
|---|---|---|---|
| `apps/pos/src/stores/paymentStore.ts:189` | `} catch {` (silent) | `console.error('[POS][paymentStore] SQLite read failed during fetchPaymentConfig', { error })` | Hides why payment config is empty on screen. |
| `apps/pos/src/stores/paymentStore.ts:207` | `} catch {` (silent) | `console.error('[POS][paymentStore] SQLite write of fresh payment config failed', { error })` | Cache write failure lets next launch re-fetch from API instead of using SQLite. |
| `apps/pos/src/stores/paymentStore.ts:210-215` | `console.warn` only when `paymentMethods.length === 0` | Always `console.error` with the API error class + status code | Currently masks "API returned 200 but with empty body" or "API returned 500 but cache was already populated" — both produce silent staleness. |
| `apps/pos/src/stores/paymentStore.ts:272-278` | Sets user-facing message; no console log of underlying error class/stack | `console.error('[POS][paymentStore] processCashCheckout failed', { error_class: error.constructor.name, error_message: error.message, stack: error.stack, terminal_id, cart_total })` | **The user-reported bug. The Tier 0 entry-point fix.** |
| `apps/pos/src/stores/paymentStore.ts:340` | Same shape as :272 | Same — for processCardCheckout | Same. |
| `apps/pos/src/stores/paymentStore.ts:395` | Same shape as :272 | Same — for processAdvancedCheckout | Same. |
| `apps/pos/src/stores/syncStore.ts:80-82` | `scheduler.syncNow().catch(() => {})` | `console.error('[POS][syncStore] triggerSync swallowed scheduler error', { error })` | Comment claims errors are handled inside `tick()`, but a startup-time exception in `tick` itself (before `try`) is invisible. |
| `apps/pos/src/lib/sync/syncService.ts:830-833` | 4× `} catch { /* non-critical */ }` | `console.warn` with which cleanup failed | Non-critical, but a chronic SQLite write failure (e.g., disk full) is otherwise undetectable. |
| `apps/pos/src/lib/sync/syncService.ts:863` | `} catch { /* non-critical */ }` for `refreshCompanyConfig` | `console.warn('[POS][sync] refreshCompanyConfig failed', { error })` | If company-locale or modules drift, debugging is hard without this log. |
| `apps/pos/src/lib/sync/syncService.ts:869` | `} catch { /* image caching is non-critical */ }` | `console.warn('[POS][sync] processDownloadQueue failed', { error })` | Same — mask of cache-disk failures. |
| `apps/pos/src/lib/api.ts:114` | `} catch {}` swallowing failed JSON body parse | `console.warn('[POS][api] error response was not JSON', { status, url })` | An HTML error page from a captive portal currently hides the real upstream failure. |
| `apps/pos/src/lib/connectivity.ts:16` | `} catch { return false }` | `console.warn('[POS][connectivity] checkServerHealth failed', { error })` (rate-limited) | Helps diagnose captive-portal vs. real-down cases. |
| `apps/pos/src/lib/sync/syncService.ts:201-204` | `logSyncOperation(error)` only — no console | Add `console.error('[POS][sync] server_receipt_id writeback failed', { idempotency_key, server_receipt_id })` | Loud signal that the local SQLite is one step behind server truth. |
| `apps/pos/src/lib/sync/syncService.ts:217-230` | `logSyncOperation(success)` only | Add `console.warn('[fiscal] reconcile skipped (local ahead)', { idempotency_key, local_seq, server_seq })` | **This is exactly the divergence path that masks the user-reported bug** — surface it. |
| `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:18-20` | `console.warn` exists, but only on import error | Verify the `.catch` actually triggers in field reproductions; add timestamp + receipt_id when it does. | Edge case but the debounced sync hook has a known fragility. |

**Highest-leverage instrumentation for the user-reported symptom (in order):**

1. `paymentStore.ts:272-278` — surfaces "what error class did the cashier just see."
2. `api.ts` — add a structured request-result log on every failed `fetch` (including read-timeout when the AbortController fires).
3. `syncService.ts:217-230` — surfaces the regression-skip path (the divergence proxy).
4. Boot-time log of `getPendingReceiptCount(db)` so we know what's pending across reboots.

---

## 8. The specific user-reported symptom — precise sequence

### Reproduction sequence (P0, double-billing risk)

1. Cart has items. Cashier taps Cash. Payment screen renders.
2. Cashier taps Exact, then Confirm.
3. `processCashCheckout` runs. `createOfflineReceipt` succeeds: SQLite has K1 (status `pending`), local hash chain advanced to N+1/H1. (`receiptService.ts:101-237`)
4. `triggerSync()` fires (fire-and-forget; not awaited). (`paymentStore.ts:153`)
5. `set({ lastReceipt: { id, ... }, lastReceiptIdempotencyKey: K1, lastReceiptServerId: null })`. Success modal shows. (`paymentStore.ts:155-170`)
6. **Background sync POSTs K1 to `/pos/receipts/sync`.** Server commits K1.
7. **Network drops between request and response (cellular tower handoff is the canonical case).** Tauri `fetch` has only `connectTimeout: 10000` — TCP is already established → no timeout fires. The promise hangs.
8. Cashier's success modal closes (auto-dismiss timer or user dismissed it).
9. Cashier sees something they interpret as "the sale failed" — possibilities:
   - The chain-break banner from a *prior* stranded receipt (`HomePage.tsx`).
   - A red sync indicator (`Header.tsx`).
   - A stale `error` in `paymentStore.error` from a prior failed config fetch — also rendered as a toast.
   - A printer error dialog that mentions the receipt couldn't be printed.
10. Cashier taps Cash → Confirm again, **without reading carefully**.
11. `processCashCheckout` runs again. `crypto.randomUUID()` mints K2. `createOfflineReceipt` reads local terminal_state (sequence N+1, last_hash H1) → new receipt with `previous_hash=H1`, sequence N+2, hash H2. Local advances to N+2/H2. (`receiptService.ts:151, 219`)
12. `triggerSync()` fires.
13. (Eventually) the OS times out the original K1 connection → catch fires → K1 marked `failed`, retry_count=1.
14. Next tick: K1 re-posted → server returns `'duplicate'`. K1 → `'synced'`.
15. Same tick (or next): K2 posted → server commits (its `previous_hash = H1` matches the server's `last_hash` after K1 → accepted). K2 → `'synced'`. Server's chain advances to N+2/H2.
16. **Both receipts appear in the back-office. Both have valid fiscal hashes. Both are part of a continuous chain. There is no programmatic way to detect they represent the same sale.**

### Smallest fix that closes this bug class

The **minimum** fix has three components, each cheap:

1. **UI lock on cart for 60 s after Confirm** — disable the entire payment surface until either `lastReceiptServerId` is populated OR a 60 s timeout elapses. Roughly: `paymentStore.processCashCheckout` keeps `isProcessing=true` until a sync completion observer fires. Effort: ~30 LOC, single file.
2. **Read timeout** on `fetch` (60 s for sync POSTs) so the OS-default-minute hang becomes a 60 s hang with a clear error trail. Effort: ~10 LOC.
3. **Boot-time `'syncing'` reaper** so an interrupted in-flight sync is re-pushable next launch. Effort: ~5 LOC.

These three together produce the invariant *"a cashier cannot start a second sale until the first either confirms server-side or fails with a typed error."* The double-bill risk goes from "structural on retry" to "requires a 60 s window of nothing happening + a process kill" — which is so narrow that it would be flagged by any audit log review.

### Severity

P0 — correctness bug, financial impact, end-user-visible.

---

## 9. Open questions for the human

1. **What's the realistic 4G latency at the parapharmacy go-live site?** The 60 s read timeout proposed above assumes payloads ≤ ~50 KB and round-trip latency ≤ ~3 s. If field measurements show p99 latency over slow 3G is, say, 15 s for a single receipt, the read timeout needs to be 90 s and the UI lock needs to be ≥120 s. Without measurements we're guessing.
2. **Is the back-office back-end aware of duplicate-cart-different-key receipts?** Specifically — does the daily reconciliation (Z-report grand totals, sales reports) currently expose "two receipts within 30 s with identical lines + total" as an alert? If not, the historical data may already contain double-bills.
3. **Should cart Confirm be globally disabled when ANY `pending` or `syncing` receipt exists for this terminal?** Or only for *this* terminal *this* cashier? The latter is more permissive but matches retail practice (one cashier, one register).
4. **Does the server's hash chain assume terminal-scoped ordering only?** I read this as terminal-scoped (`Receipt::where('terminal_id', ...)` lock at `ReceiptSyncService.php:137-140`). But two terminals at the same store committing receipts simultaneously could in principle race for `current_sequence`. The `lockForUpdate()` serializes them per terminal. But if the same `terminal_id` is reused (multi-process? hot reload?), two concurrent `syncSingleReceipt` calls could deadlock. This is unlikely in production but worth confirming.
5. **Is `cleanupStuckReceipts(db)` (90-day delete) really safe?** If a stranded receipt's `previous_hash` is the chain's authoritative anchor for receipts created *after* it, deleting it leaves a chain hole. We never reach `cleanupStuckReceipts` in the path I traced — `getPendingReceiptsForSync` excludes `retry_count >= 5`, so the stranded receipts are silent → eventually deleted → previous_hash anchors are gone. Need to confirm the *server* doesn't care about the client's deleted local snapshot (it shouldn't — server has its own `pos_receipts` rows).
6. **Should `MAX_SYNC_RETRIES` reset to 0 after any successful sync of any receipt for the same terminal?** A receipt that hits 5 transient failures during a single bad 4G window is permanently stuck even after the network recovers. Same as Claude's prior audit's open question 6 — restating because the answer is gating the fix recommendation.
7. **Is the dead `/pos/receipts` non-idempotent route still needed for any client?** If no, deleting it eliminates one entire class of latent failure. If yes (e.g., the back-office has an "issue manual receipt" button), it must accept `idempotency_key` and dedupe.

---

## 10. Reconciliation with prior audits

### Codex audit (`2026-04-30-pos-offline-first-audit-codex.md`)

| Codex finding | This audit's verdict |
|---|---|
| §5 — "1000+ pending receipts replay as 1000 sequential pushes" | **Agree.** Confirmed at `syncService.ts:178-186` (loop). Not the user-reported bug; orthogonal performance concern. |
| §5 — "receipts that hit retry_count ≥ 5 stop retrying" | **Agree, and extend.** They also become invisible to `getPendingReceiptCount` *because the count includes them* but the *queue runner* ignores them. The 90-day cleanup compounds this. P1, not P2. |
| §5 — "pendingReceiptCount not hydrated on startup" | **Agree.** Confirmed at `syncStore.ts:50-57`. |
| §6 — "terminal-scoped receipt ordering is INFERRED" | **Agree.** Server-side `ReceiptSyncService.php:137-140` confirms `Terminal::where('id', $payload->terminalId)->lockForUpdate()` — terminal-scoped. The client assumption is correct. |
| §7 — "no read-timeout on fetch" — *NOT IN CODEX* | **New finding here.** Codex flagged the captive-portal probe but didn't extend it to the entire `request<T>` path. **This is the proximate cause of the user-reported symptom.** |
| §1 #4 — "payment-config failures surface late at checkout" | **Agree, but tangential** to the sync-flow bug. Claude raised this to P0 due to `paymentStore` not being refreshed from SQLite by the scheduler — that's accurate and stands. |

### Claude audit (`2026-04-30-pos-offline-first-audit-claude.md`)

| Claude finding | This audit's verdict |
|---|---|
| §3 P0 #3 — "paymentStore never reseeded from SQLite after sync tick" | **Agree.** Not the sync-flow bug, but it *amplifies* it — when a stale paymentStore throws `errors.noCashMethod`, the cashier sees a checkout-time error that they may interpret as the sale-level failure that drives the retry loop. P0 in its own right. |
| §5 — "scheduleDebouncedSync from inside an uncommitted transaction" | **Agree, P3.** The 250 ms debounce makes it safe in practice. I'd elevate the *fix* (move it post-`COMMIT`) because it removes a load-bearing timing assumption. |
| §5 — "1000-receipt replay as 1000 sequential pushes; server endpoint already supports a batch" | **Agree.** The server's `SyncReceiptsRequest` accepts `receipts` as an array, and `ReceiptSyncService::syncBatch` already iterates. Client-side change is "send 50 per call, preserve hash_sequence ordering." Not on the path of the user-reported bug. |
| §8 — "lastSyncAt non-durable" | **Agree.** Tangential. |
| Reconnect-storm risk | **Agree with Claude over Codex.** `isSyncing` guard is sufficient. |

### Where my audit diverges

1. **The user-reported bug is upstream of the wire.** Both prior audits implied (correctly) that on-the-wire idempotency is sound. Neither traced the structural retry path through `crypto.randomUUID()` to the double-bill. This audit names that path explicitly and identifies the smallest fix (UI lock + read timeout + `'syncing'` reaper).
2. **No HTTP read timeout** anywhere is the proximate cause of the "client never knew it succeeded." Codex flagged this for the health probe; Claude noted it for the foreground; **neither connected it to the post-commit-response-lost path that produces the user-reported symptom.**
3. **The stranded `'syncing'` rows on process kill** — neither prior audit mentioned that `getPendingReceiptsForSync` excludes `'syncing'`. This is a genuine silent-loss path.
4. **Dead-code `/pos/receipts` non-idempotent route** — neither prior audit caught that the route exists, that `executeCheckout`'s online branch hits it, and that this is one accidental import away from being live.

---

## Appendix — how the audit was conducted

- Server-side: read `ReceiptController.php`, `ReceiptCreationService.php`, `ReceiptSyncService.php`, `SyncController.php`, `SyncReceiptPayload.php`, `routes.php`, and the idempotency_key migration in full.
- Client-side: read `syncService.ts`, `syncScheduler.ts`, `paymentStore.ts`, `receiptService.ts`, `offlineReceiptRepository.ts`, `terminalStateRepository.ts`, `syncStore.ts`, `connectivityStore.ts`, `connectivity.ts`, `api.ts`, `offlineCheckoutService.ts`, `receiptApi.ts` in full.
- Cross-referenced two prior audits at `docs/superpowers/audits/`.
- No code was modified; no test was run. All claims are static-analysis claims grounded in the cited file:line references.

— Opus, 2026-04-30
