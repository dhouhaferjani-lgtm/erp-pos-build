# POS Sync Flow Deep Dive - 2026-04-30 (Codex)

Scope: investigation only. I read Prompt 1 in `docs/superpowers/plans/2026-04-30-pos-investigation-prompts.md` and the orchestrator context in `docs/superpowers/plans/2026-04-30-pos-consolidation-checkpoint.md`. No application code was changed.

## Executive Summary

1. `P0` A receipt can be stranded forever in local `status='syncing'` after a hung POST or process kill. The sync loop marks a row `syncing` before `POST /pos/receipts/sync`, but restart-time selection only includes `pending` and `failed`, so duplicate/idempotent recovery never runs for orphaned `syncing` rows. (`apps/pos/src/lib/sync/syncService.ts:236-242`, `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:138-145`)
2. `P0` The client has no read/overall timeout on API calls, only a 10s TCP connect timeout. A request can reach the server, commit, and then hang between response and client handler; if the app is killed before rejection, the row remains `syncing`. (`apps/pos/src/lib/api.ts:87-92`, `apps/pos/src/lib/connectivity.ts:10-17`)
3. `P0` The reported "payment failed but back-office finalized" symptom is not cleanly explained by the current checkout code, because checkout does not await sync. `processCashCheckout()` only shows the cash-modal error when local receipt creation/config fails; background sync failures should surface through sync state, not `Échec du paiement`. This means field reports are currently conflating at least two red error surfaces. (`apps/pos/src/stores/paymentStore.ts:321-350`, `apps/pos/src/pages/HomePage.tsx:676-692`, `apps/pos/src/components/organisms/CashPaymentScreen/CashPaymentScreen.tsx:97-103`)
4. `P1` End-to-end idempotency is correct on the actual Tauri path, `POST /pos/receipts/sync`, including duplicate short-circuit and canonical hash echo. The adjacent `POST /pos/receipts` route is not idempotent and should not be used by offline POS. (`apps/api/app/Modules/POS/routes.php:79-93`, `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:125-143`, `apps/api/app/Modules/POS/Application/DTOs/SyncReceiptResult.php:39-49`)
5. `P1` Observability is insufficient for this bug class: several async catches log only to SQLite or silently return, while checkout catches set UI state and rethrow without `console.error`. A cashier-visible failure can therefore leave no devtools stack. (`apps/pos/src/stores/paymentStore.ts:345-350`, `apps/pos/src/stores/syncStore.ts:75-82`, `apps/pos/src/lib/sync/syncService.ts:487-490`, `apps/pos/src/lib/sync/syncService.ts:1175-1234`)

## 1. Receipt Push Contract

### Current state

There are two receipt creation surfaces in the POS module:

| Route | Current role | Idempotency | Canonical hash/chain echo |
|---|---|---|---|
| `POST /api/v1/pos/receipts/sync` | Offline POS sync push | Yes | Yes |
| `POST /api/v1/pos/receipts` | Online/server-side POS receipt draft/create path | No | No |

The sync route is declared before collection receipt routes. (`apps/api/app/Modules/POS/routes.php:79-93`) `SyncController::syncReceipts()` validates a batch, maps each item into `SyncReceiptPayload`, calls `ReceiptSyncService::syncBatch()`, and returns per-row counts plus results. (`apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php:54-88`)

The sync request requires `idempotency_key`, `offline_fiscal_hash`, `previous_hash`, `hash_sequence`, payments, and `fiscal_schema_version`; single-receipt root payloads are normalized into `receipts[]`. (`apps/api/app/Modules/POS/Presentation/Requests/SyncReceiptsRequest.php:33-41`, `apps/api/app/Modules/POS/Presentation/Requests/SyncReceiptsRequest.php:51-119`)

Server idempotency is explicit on the sync service. `syncSingleReceipt()` first looks up `Receipt::where('idempotency_key', $payload->idempotencyKey)->first()`. If found, it returns `SyncReceiptResult::duplicate(...)` with the existing `receipt_id`, existing `fiscal_hash`, and current terminal hash state. (`apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:125-143`) The DB also has a unique nullable `idempotency_key` column. (`apps/api/database/migrations/2026_03_12_100000_add_idempotency_key_to_pos_receipts.php:17-21`)

For a new receipt, the service validates terminal chain continuity before insert, creates the receipt with `idempotency_key`, finalizes it under transaction, verifies the server-computed hash matches `offline_fiscal_hash`, refreshes terminal state, and returns `terminal_last_hash` plus `terminal_hash_sequence`. (`apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:147-189`, `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:359-397`, `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:479-576`)

`SyncReceiptResult::toArray()` returns `idempotency_key`, `status`, `receipt_id`, `server_fiscal_hash`, `error`, `terminal_last_hash`, and `terminal_hash_sequence`, so the client can reconcile without a separate pull. (`apps/api/app/Modules/POS/Application/DTOs/SyncReceiptResult.php:18-49`)

By contrast, `POST /pos/receipts` validates no `idempotency_key`, calls `ReceiptCreationService::createReceipt(...)`, and returns only `data: receipt` with HTTP 201. (`apps/api/app/Modules/POS/Presentation/Requests/StoreReceiptRequest.php:31-57`, `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:284-316`) `ReceiptCreationService::createReceipt()` has no idempotency lookup in the inspected references; it starts a normal DB transaction for receipt creation. (`apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:104-124`)

### Recommended fix sketch

Document `POST /pos/receipts/sync` as the only offline POS push contract. Either block the Tauri app from using `POST /pos/receipts`, or make the plain create route accept and short-circuit on an idempotency key so adjacent code paths do not regress into duplicate risk.

## 2. Failure-Mode Taxonomy

Local checkout is local-first. `createOfflineReceipt()` reads terminal state, computes the fiscal hash, creates a local UUID and idempotency key, inserts `offline_receipts`, advances local `terminal_state`, and commits. (`apps/pos/src/lib/offline/receiptService.ts:237-247`, `apps/pos/src/lib/offline/receiptService.ts:310-383`, `apps/pos/src/lib/offline/receiptService.ts:401-463`) The repository schedules background sync after the insert. (`apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:66-98`) `paymentStore` then fire-and-forgets `triggerSync()` and exposes the local receipt identity to the UI. (`apps/pos/src/stores/paymentStore.ts:225-245`)

Retries reuse the same idempotency key because `receiptToPayload()` forwards the stored `offline_receipts.idempotency_key`. (`apps/pos/src/lib/sync/syncService.ts:1259-1364`)

| Failure shape | Current behavior | Retry timing | Same key? | Local queue state |
|---|---|---:|---|---|
| DNS resolution fails | `apiPost()` rejects; `pushOfflineReceipts()` catch increments retry, marks row `failed`, logs to SQLite, continues to the next row unless the error text looks like chain break. (`apps/pos/src/lib/sync/syncService.ts:307-319`) | Next scheduler tick: immediate on connectivity restore, otherwise 1m -> 2m -> 4m -> 5m cap. (`apps/pos/src/lib/sync/syncScheduler.ts:35-45`, `apps/pos/src/lib/sync/syncScheduler.ts:112-149`) | Yes | `failed` |
| TCP connect timeout | Same as DNS. The only timeout configured is `connectTimeout: 10000`. (`apps/pos/src/lib/api.ts:87-92`) | Same | Yes | `failed` |
| Read timeout mid-request, server received body but client never got response | There is no app-level read/overall timeout. If the request hangs, the loop never reaches catch or success. Row was already moved to `syncing`. (`apps/pos/src/lib/sync/syncService.ts:236-242`, `apps/pos/src/lib/api.ts:87-92`) | None while hung; only retries if the underlying fetch eventually rejects | Yes if it ever retries | `syncing` while hung |
| 5xx after server commit | If the client receives the 5xx, `apiPost()` throws `ApiRequestError`; row becomes `failed`; next retry re-posts the same key and should get `duplicate` if the original commit persisted. (`apps/pos/src/lib/api.ts:102-119`, `apps/pos/src/lib/sync/syncService.ts:249-262`, `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:125-143`) | Next tick | Yes | `failed`, then `synced` after duplicate |
| 5xx before server commit | Same client behavior, but retry creates the receipt normally instead of duplicate. | Next tick | Yes | `failed`, then `synced` |
| Client process killed mid-POST | Worst case. Row was already set to `syncing`; restart query ignores `syncing`; no repair/sweep was found. (`apps/pos/src/lib/sync/syncService.ts:236-242`, `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:138-145`) | None | Not until manually requeued | Stuck `syncing` |
| Network drops between request and response | Same as read-timeout/lost-response shape. If fetch rejects, retry works; if it hangs and the app dies, row is stranded. (`apps/pos/src/lib/api.ts:87-92`, `apps/pos/src/lib/sync/syncService.ts:307-319`) | None while hung | Yes if it retries | `syncing` or later `failed` |

### Recommended fix sketch

Add an overall request timeout around `fetch`, not just `connectTimeout`. On app boot and before scheduler start, reset or reconcile stale `syncing` rows. The safest repair is to re-post each orphaned row with the stored idempotency key and let the server return `duplicate` or `synced`.

## 3. Sync Push Reliability

### Queue drain triggers

The queue drains on scheduler start, on the interval tick, on offline-to-online transitions, and after local receipt insert via a 250ms debounced trigger. (`apps/pos/src/lib/sync/syncScheduler.ts:25-45`, `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:9-21`, `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:95-98`)

`runFullSync()` pushes queued PIN updates, receipts, Z-reports, cash drawer ops, voucher ledger entries, then pulls products, payment config, operators, terminal state, Z-chain state, tables, menu, vouchers, voucher ledger, and QR index. (`apps/pos/src/lib/sync/syncService.ts:1175-1234`)

### Head-of-line behavior

Receipt rows are selected in `hash_sequence ASC` with `status IN ('pending','failed') AND retry_count < 5`. (`apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:138-145`) The loop sends batch-of-one requests, preserving chain order at the client. (`apps/pos/src/lib/sync/syncService.ts:236-242`)

Ordinary transport or non-chain failures do not stop the loop; the row is marked `failed` and later receipts are attempted. (`apps/pos/src/lib/sync/syncService.ts:300-319`) Chain-break responses do halt the loop. (`apps/pos/src/lib/sync/syncService.ts:292-299`)

This is partial head-of-line blocking: the code tries later rows after generic failures, but the fiscal chain means later rows often fail if an earlier receipt did not commit server-side. That is acceptable for chain integrity, but it makes operator-visible stuck-state handling important.

### Max retry and dead letter

There is a hard max of five retries. (`apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:136-145`) `getStuckReceipts()` exists for `failed` rows at or above that retry count, but I did not find a user-facing caller. (`apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:156-163`) `cleanupStuckReceipts()` deletes old exhausted failures after 90 days. (`apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:204-211`)

`pendingReceiptCount` is not actual queue depth. `completeSync()` sets it to `result.receiptsFailed`, which is only the just-finished run's failure count. (`apps/pos/src/stores/syncStore.ts:50-57`) A real `getPendingReceiptCount()` helper exists and counts only `pending` and `failed`, not `syncing`. (`apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:107-113`)

### Recommended fix sketch

Add an operator-visible exception queue for `failed >= MAX_SYNC_RETRIES` and orphaned `syncing`. Replace sync badge count with a SQLite-backed count for `pending`, `failed`, and stale `syncing` rows. Consider posting contiguous batches to `/pos/receipts/sync` once correctness repair is in place; the server response already supports arrays. (`apps/api/app/Modules/POS/Presentation/Requests/SyncReceiptsRequest.php:51-53`, `apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php:80-88`)

## 4. Hash Chain Divergence Recovery

`pullTerminalState()` fetches `/pos/terminals/{id}`, computes the genesis hash when `last_hash` is null, builds a local `TerminalHashState`, and calls `upsertTerminalState()`. (`apps/pos/src/lib/sync/syncService.ts:542-583`) The server resource exposes `last_hash` and `hash_sequence = current_sequence - 1`. (`apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php:42-50`)

`upsertTerminalState()` rejects only regressive incoming sequence values (`state.hash_sequence < before`) and throws `FiscalRegressionError`. (`apps/pos/src/lib/db/repositories/terminalStateRepository.ts:134-159`) `advanceHashChain()` is stricter and rejects non-strictly-increasing writes (`newSequence <= before`). (`apps/pos/src/lib/db/repositories/terminalStateRepository.ts:193-220`)

`pullTerminalState()` catches `FiscalRegressionError`, emits `console.warn`, logs a success row to SQLite, and returns `true`, preserving local state. (`apps/pos/src/lib/sync/syncService.ts:581-610`)

Preserving local state is correct when the local terminal has unsynced offline receipts and the server is behind. It is incomplete when the server has advanced because the client pushed a receipt, the server committed, and the client missed the response. In the healthy version of that scenario, the local sequence is already equal to the server sequence because local checkout advanced before sync. A retry with the same key returns `duplicate`, and the client marks the row `synced`. (`apps/pos/src/lib/offline/receiptService.ts:401-459`, `apps/pos/src/lib/sync/syncService.ts:249-290`)

The broken version is when the row is stuck `syncing`. Then idempotent retry never runs. `pullTerminalState()` may still preserve or apply a terminal state, but it does not prove whether the local row has a server `receipt_id`, and the queue state remains wrong. The regression guard is not the root bug; missing reconciliation for `syncing` rows is.

### Specific server-ahead question

If the server has advanced past the client's local sequence, `upsertTerminalState()` will accept the higher sequence because it is not regressive. (`apps/pos/src/lib/db/repositories/terminalStateRepository.ts:145-182`) That can happen if local DB was lost/reset or another writer uses the same terminal ID. In normal single-terminal local-first flow, local advances before POST, so server should be equal or behind, not ahead. A true server-ahead event should be treated as a high-severity divergence and should trigger idempotency reconciliation plus operator logging, not just a quiet terminal-state pull.

## 5. Idempotency End-to-End

### Server side

`POST /pos/receipts/sync` is idempotent by key:

- The request requires `receipts.*.idempotency_key`. (`apps/api/app/Modules/POS/Presentation/Requests/SyncReceiptsRequest.php:51-54`)
- `ReceiptSyncService` looks up by key before terminal locking or insert. (`apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:125-143`)
- Duplicate is a normal `200` response entry with status `duplicate`, not a `409`. (`apps/api/app/Modules/POS/Presentation/Controllers/SyncController.php:80-88`, `apps/api/app/Modules/POS/Application/DTOs/SyncReceiptResult.php:70-86`)
- Both `synced` and `duplicate` responses include canonical fiscal hash and terminal chain state when available. (`apps/api/app/Modules/POS/Application/DTOs/SyncReceiptResult.php:52-86`)

The plain receipt creation path is not idempotent. It does not validate an idempotency key and calls `ReceiptCreationService::createReceipt()` directly. (`apps/api/app/Modules/POS/Presentation/Requests/StoreReceiptRequest.php:31-57`, `apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php:284-316`)

### Client side

The offline receipt service generates exactly one local receipt UUID and one idempotency key per local receipt. (`apps/pos/src/lib/offline/receiptService.ts:310-338`) It persists the key in `offline_receipts`, and `receiptToPayload()` sends the same key on every retry. (`apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:66-93`, `apps/pos/src/lib/sync/syncService.ts:1339-1364`)

`pushOfflineReceipts()` treats `synced` and `duplicate` as success, marks the row `synced`, writes back `server_receipt_id`, and updates `lastReceiptServerId` if the current modal is showing that idempotency key. (`apps/pos/src/lib/sync/syncService.ts:249-290`)

`paymentStore.ts` does not need a duplicate-specific branch because duplicate handling is below it in sync. It does, however, create a brand-new idempotency key if the cashier starts a new checkout for the same cart. If the first sale already committed server-side but the POS never gave the cashier a confident success state, manual retry can still produce a second legitimate receipt with a fresh key. That is the double-billing risk outside server idempotency.

### Idempotency contract diagram

```text
Cashier confirms sale
  |
  v
createOfflineReceipt()
  - read local terminal_state: Hn / seq n
  - K1 = crypto.randomUUID()
  - R1 = crypto.randomUUID()
  - INSERT offline_receipts(R1, K1, status='pending', previous_hash=Hn, hash_sequence=n+1)
  - advance local terminal_state to Hn+1 / seq n+1
  - COMMIT
  |
  v
triggerSync() / scheduler tick
  |
  v
pushOfflineReceipts()
  - SELECT pending/failed by hash_sequence
  - UPDATE R1 status='syncing'
  - POST /pos/receipts/sync { receipts: [{ idempotency_key: K1, ... }] }
  |
  +-- first delivery, K1 absent on server
  |     validate previous_hash == terminal.last_hash
  |     create + finalize receipt
  |     advance server terminal to Hn+1 / seq n+1
  |     return status='synced', receipt_id, server_fiscal_hash=Hn+1,
  |            terminal_last_hash=Hn+1, terminal_hash_sequence=n+1
  |
  +-- retry delivery, K1 already present
        return status='duplicate', same receipt_id, same fiscal hash,
               current terminal_last_hash, current terminal_hash_sequence

Required invariants:
  - K1 is persisted before any network POST.
  - Every retry reuses K1.
  - 'duplicate' is a success state.
  - No row may remain indefinitely in 'syncing'; otherwise the duplicate path never runs.
```

## 6. Observability Gaps

### Catch blocks that need `console.error` or `console.warn`

| File:line | Current behavior | Add |
|---|---|---|
| `apps/pos/src/stores/paymentStore.ts:262-264` | Silent SQLite payment-config cache read failure | `console.warn` with `op: 'payment_config.cache_read'` |
| `apps/pos/src/stores/paymentStore.ts:280-282` | Silent SQLite payment-config writeback failure | `console.warn` with endpoint counts |
| `apps/pos/src/stores/paymentStore.ts:283-288` | API payment config failure warns only when no methods exist | `console.error` with `op: 'payment_config.api_fetch'` |
| `apps/pos/src/stores/paymentStore.ts:345-350` | Cash checkout catch sets UI error and rethrows | `console.error` with terminal id, idempotency key if known, cart count, total |
| `apps/pos/src/stores/paymentStore.ts:413-419` | Card checkout catch sets UI error and rethrows | Same payload with `op: 'checkout.card'` |
| `apps/pos/src/stores/paymentStore.ts:472-478` | Advanced checkout catch sets UI error and rethrows | Same payload with `op: 'checkout.advanced'` |
| `apps/pos/src/stores/syncStore.ts:80-82` | `scheduler.syncNow().catch(() => {})` is fully silent | `console.error` with `op: 'sync.trigger'` |
| `apps/pos/src/lib/api.ts:114-116` | Non-JSON error body parse failure is swallowed | `console.warn` with URL, method, HTTP status |
| `apps/pos/src/lib/connectivity.ts:16-17` | Health probe failures return false silently | `console.warn` with server URL and error class |
| `apps/pos/src/lib/sync/syncScheduler.ts:100-109` | Session revalidation catch ignores network errors | `console.warn` for non-401 session revalidation failures |
| `apps/pos/src/lib/sync/syncScheduler.ts:120-125` | Top-level tick failure sets store error only | `console.error` with terminal id and current interval |
| `apps/pos/src/lib/sync/syncService.ts:259-262` | Server receipt id writeback failure logs only to SQLite | `console.error` with idempotency key and server receipt id |
| `apps/pos/src/lib/sync/syncService.ts:275-288` | Chain reconciliation skip logs only to SQLite | `console.warn` with local/server sequence and hash |
| `apps/pos/src/lib/sync/syncService.ts:307-319` | Receipt push catch logs only to SQLite | `console.error` with receipt id, key, sequence, retry count |
| `apps/pos/src/lib/sync/syncService.ts:346-357` | Z-report push catch logs only to SQLite | `console.error` with report id/number |
| `apps/pos/src/lib/sync/syncService.ts:487-490` | Product pull catch logs only to SQLite | `console.error` with last sync cursor/page if available |
| `apps/pos/src/lib/sync/syncService.ts:509-512` | Payment-config pull catch logs only to SQLite | `console.error` |
| `apps/pos/src/lib/sync/syncService.ts:526-529` | Operator PIN pull catch logs only to SQLite | `console.error` |
| `apps/pos/src/lib/sync/syncService.ts:607-610` | Terminal-state outer catch logs only to SQLite | `console.error` |
| `apps/pos/src/lib/sync/syncService.ts:676-679` | Z-chain pull catch logs only to SQLite | `console.error` |
| `apps/pos/src/lib/sync/syncService.ts:760-766` | PIN-update push catch logs only to SQLite | `console.error` |
| `apps/pos/src/lib/sync/syncService.ts:836-839` | Tables pull catch logs only to SQLite | `console.error` |
| `apps/pos/src/lib/sync/syncService.ts:886-889` | Active-menu pull catch logs only to SQLite | `console.error` |
| `apps/pos/src/lib/sync/syncService.ts:955-958` | Voucher pull catch logs only to SQLite | `console.error` |
| `apps/pos/src/lib/sync/syncService.ts:996-999` | Voucher-ledger pull catch logs only to SQLite | `console.error` |
| `apps/pos/src/lib/sync/syncService.ts:1041-1044` | Receipt QR index pull catch logs only to SQLite | `console.error` |
| `apps/pos/src/lib/sync/syncService.ts:1160-1165` | Voucher-ledger push catch logs only to SQLite | `console.error` |
| `apps/pos/src/lib/sync/syncService.ts:1182-1185` | Cleanup catches are silent | `console.warn` with cleanup op |
| `apps/pos/src/lib/sync/syncService.ts:1225-1228` | Company-config refresh catch is silent | `console.warn` |
| `apps/pos/src/lib/sync/syncService.ts:1231-1234` | Image cache catch is silent | `console.warn` |

Use a stable structured payload:

```text
{
  scope: 'pos_sync' | 'payment_store' | 'api' | 'connectivity',
  op: string,
  terminal_id?: string,
  receipt_id?: string,
  receipt_number?: string,
  idempotency_key?: string,
  hash_sequence?: number,
  retry_count?: number,
  http_status?: number,
  error_class: string,
  error_message: string,
  stack?: string
}
```

## 7. Specific User-Reported Symptom

### What the current code can produce

The current code supports this sequence:

1. Cashier confirms cash payment.
2. Local receipt creation succeeds: `offline_receipts` row is inserted, local hash chain advances, transaction commits. (`apps/pos/src/lib/offline/receiptService.ts:401-463`)
3. `paymentStore` triggers sync fire-and-forget, then sets `lastReceipt`, `lastReceiptIdempotencyKey`, and `pendingReceiptId: null`. (`apps/pos/src/stores/paymentStore.ts:225-245`)
4. `HomePage.handleCashConfirm()` closes the cash modal and opens success modal after `processCashCheckout()` resolves. (`apps/pos/src/pages/HomePage.tsx:676-692`)
5. Background sync marks the row `syncing`, POSTs it, server commits, and the response hangs or is lost. (`apps/pos/src/lib/sync/syncService.ts:236-242`, `apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:570-576`)
6. If the app is killed/reloaded during the hang, the row stays `syncing` and will not retry. The back office shows the finalized receipt because the server committed it. (`apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:138-145`)

This explains "server finalized but POS never clearly reconciled success." It does not, by itself, explain the cash modal's `Échec du paiement` error, because the cash modal error is driven by `paymentStore.error` and rendered from local checkout catches. (`apps/pos/src/stores/paymentStore.ts:345-350`, `apps/pos/src/components/organisms/CashPaymentScreen/CashPaymentScreen.tsx:97-103`)

### What would produce the red payment modal

The current cash modal error path requires `processCashCheckout()` to throw before it resolves. The likely sources are missing cash method, missing cash register, missing company/operator, missing terminal hash chain, malformed local data, voucher mirror errors, SQLite transaction failure, or fiscal-regression errors during local `advanceHashChain()`. (`apps/pos/src/stores/paymentStore.ts:176-198`, `apps/pos/src/stores/paymentStore.ts:299-317`, `apps/pos/src/lib/offline/receiptService.ts:243-247`, `apps/pos/src/lib/offline/receiptService.ts:398-463`)

If one of those local throws happens, no receipt should be posted by that failing attempt. Therefore the observed server-finalized receipt likely belongs to either:

- a previous attempt that actually committed locally and synced in the background, while a later retry failed locally, or
- a success-modal/sync-warning surface that the cashier described as a payment failure, not the cash modal error, or
- code not in the inspected current path.

### Smallest fix that closes the double-billing class

1. Add a read/overall timeout to `api.ts`.
2. Requeue or reconcile stale `syncing` rows on boot and before every scheduler tick.
3. Surface "sale already recorded" when a retry receives `duplicate`, using the original receipt number/idempotency key.
4. Add a recent-sale retry suppression guard: if the same cart/total/operator was just committed locally and is `pending` or `syncing`, block a second checkout unless a manager confirms.
5. Add `console.error` to checkout catches so the next reproduction proves whether the red modal was local checkout failure or background sync failure.

## Open Questions

1. What exact UI did the cashier see: cash modal inline error, header sync badge, chain-break banner, or a toast? The current code has multiple red surfaces with different causes.
2. Are any Tauri POS builds or plugins still using `POST /pos/receipts` instead of `POST /pos/receipts/sync`?
3. How long can pharmacy 4G reads legitimately take? That determines whether the receipt POST read timeout should be 30s, 60s, or a two-stage "still syncing" flow.
4. Should orphaned `syncing` rows be auto-requeued blindly, or should the UI first show "verifying sale status" while re-posting by idempotency key?
5. Is receipt chain scope guaranteed to be terminal-only on the server? The client assumes per-terminal chains throughout, and server sync locks a terminal by `terminal_id`. (`apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:147-153`)
6. Should the POS block a repeated checkout of an identical cart while the prior local receipt is unsynced?

## Reconciliation With Existing Offline-First Audits

`docs/superpowers/audits/2026-04-30-pos-offline-first-audit-codex.md` correctly identified local receipt durability, idempotency-key reuse, duplicate-as-success handling, sequential replay, max-retry stranding, and unreliable pending counts. (`docs/superpowers/audits/2026-04-30-pos-offline-first-audit-codex.md:107-129`) This deep dive agrees, but raises the risk of `syncing` orphan rows above generic retry exhaustion because those rows bypass duplicate recovery entirely.

The Codex offline-first audit also called out missing foreground request timeouts and misleading sync status. (`docs/superpowers/audits/2026-04-30-pos-offline-first-audit-codex.md:25`, `docs/superpowers/audits/2026-04-30-pos-offline-first-audit-codex.md:161-181`) This audit confirms that the missing read timeout is directly relevant to "server committed, client missed response."

`docs/superpowers/audits/2026-04-30-pos-offline-first-audit-claude.md` strengthens two points I agree with: `pendingReceiptCount` is wrong by design, and no read timeout exists on API/health fetches. (`docs/superpowers/audits/2026-04-30-pos-offline-first-audit-claude.md:15-17`) It also highlights that `paymentStore` is not refreshed from SQLite after sync. (`docs/superpowers/audits/2026-04-30-pos-offline-first-audit-claude.md:13`) That explains cashier-blocking "no cash method" failures, but it is not sufficient to explain a receipt finalized by the server after the same failing checkout attempt; the sync-specific missing piece is orphaned `syncing` rows.

I disagree only with treating payment-config hydration as the primary explanation for "POS failed but server finalized." Payment-config failures happen before local receipt commit; server finalization requires a different or earlier successful local commit plus background sync.
