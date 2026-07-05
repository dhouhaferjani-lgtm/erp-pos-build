# POS Checkout Failure — Deep Dive Audit (Opus)

**Date:** 2026-04-30
**Author:** Claude Opus (audit-only, no code written)
**Scope:** Tauri POS desktop app (`apps/pos`) — cash checkout "Échec du paiement" + persistent "Fiscal chain has been interrupted" banner.
**Verifies/extends:** [`docs/superpowers/plans/2026-04-30-pos-checkout-failure-fix.md`](../plans/2026-04-30-pos-checkout-failure-fix.md).

---

## Executive Summary — Top 5 findings (ranked by likelihood × user impact)

| # | Finding | Where | Likelihood | User impact |
|---|---|---|---|---|
| **1** | **Catch in `processCashCheckout` swallows non-`Error` throwables and never logs them.** The ternary `error instanceof Error ? error.message : i18n.t('errors.checkoutFailed')` falls through whenever the underlying rejection is a Tauri-SQL plugin error string (not a JS `Error`). The re-thrown error is then eaten by `HomePage.handleCashConfirm`'s parameter-less `catch {}`. Net effect: the cashier sees the generic French banner; devtools console stays clean. **This is the dominant ergonomic bug — without fixing it, none of the other root causes can be diagnosed in seconds.** | `apps/pos/src/stores/paymentStore.ts:272–278` + `apps/pos/src/pages/HomePage.tsx:362–365` | Confirmed (high) | Blocks every diagnostic loop |
| **2** | **The actual checkout failure is almost certainly a tauri-plugin-sql rejection from inside the `BEGIN TRANSACTION → INSERT → advanceHashChain → COMMIT` block in `createOfflineReceipt`.** tauri-plugin-sql rejects promises with Rust-side error *strings* (not `Error` instances), which is exactly what triggers the Finding 1 fallback. Most plausible failure modes (in order): (a) NOT NULL constraint violation on `payment_method_id`/`payment_repository_id` if the in-memory `paymentMethods`/`paymentRepositories` arrays were populated from a paginated/wrapped API response shape (the recurring "double-unwrap" memory bug); (b) `database is locked` from the fire-and-forget background sync racing the local INSERT; (c) the `BEGIN TRANSACTION` failing because a prior `createOfflineReceipt` left a transaction dangling after a partial throw that didn't reach `ROLLBACK`. | `apps/pos/src/lib/offline/receiptService.ts:216–224`; `insertOfflineReceipt` at `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:60–86` | High (consistent with symptom) | P0 — cashier cannot complete a sale |
| **3** | **`ChainBreakAlert` is *purely* server-driven** — the flag is only set from `syncScheduler.tick` after `pushOfflineReceipts` returns `chainBreak=true`. There is **no client-detected chain break**. The banner persists across reloads because `useSyncStore` has no `persist` middleware (state resets to `chainBreak: false` on cold-start), but the *first sync tick after re-init* immediately re-fires `setChainBreak(true, ...)` because the offending `failed` receipt is still in SQLite with `retry_count < MAX_SYNC_RETRIES (5)` and continues to be pushed. **The chain-break is not a transient UI bug — there is a genuine bad receipt or hash-mismatch on the server side that the sync loop keeps re-trying.** | `apps/pos/src/stores/syncStore.ts:38, 89–93`; `apps/pos/src/lib/sync/syncScheduler.ts:80–85`; `apps/pos/src/lib/sync/syncService.ts:178–263` | Confirmed | Persistent operator-visible alarm |
| **4** | **Once `retry_count` saturates at 5 the bad receipt is *skipped*, but the chain stays broken** because every subsequent offline receipt's `previous_hash` chains to that bad receipt's `fiscal_hash` (which the server never accepted). So the chain-break flag is *amplifying*: each fresh sale adds another receipt that the server will reject on the same grounds. The only way out is operator-side reconciliation (void all post-break local receipts, re-anchor on server). No such recovery flow exists in the code. | `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:131–139` (`getPendingReceiptsForSync` includes `status='failed' AND retry_count < 5`); `apps/pos/src/lib/sync/syncService.ts:234–262` (chain_broken halt) | High | The two bugs may converge: every successful local checkout lengthens the broken chain |
| **5** | **The "Échec du paiement" and the chain-break banner are *almost certainly independent root causes*** — `processCashCheckout` writes only to local SQLite and triggers sync fire-and-forget; it never awaits the network. So a server-side chain-break cannot directly cause the local checkout to throw. They share a *secondary* coupling via Finding #2(b): if the background sync triggered by an earlier successful checkout is still holding a write lock on the SQLite file when the next checkout attempts `BEGIN TRANSACTION`, the BEGIN throws "database is locked" — which is exactly the symptom we'd see. So while not the *same* root cause, the chain-break and the checkout failure may *interact* via SQLite write contention. | See per-bug sections below. | Medium | Affects fix scoping |

**Bottom line for the orchestrator session:**
1. Ship Finding 1's observability fix first (15 min, paste-ready snippets in §6 below). It is *prerequisite* to all further triage.
2. With logs in hand, the orchestrator can in <5 minutes confirm which of the Finding 2(a/b/c) sub-hypotheses fired.
3. The chain-break is a *separate workstream* that requires an operator-side reconciliation tool — out of scope for an emergency hotfix.

---

## §1 — Why the console is silent (Finding #1, confirmed)

### The exact code paths that swallow

**Layer A — `processCashCheckout` catch:** [`apps/pos/src/stores/paymentStore.ts:272–278`](../../apps/pos/src/stores/paymentStore.ts)

```ts
} catch (error) {
  set({
    isProcessing: false,
    error: error instanceof Error ? error.message : i18n.t('errors.checkoutFailed', { ns: 'pos' }),
  });
  throw error;
}
```

Three failure modes here:
- **No `console.error` call before the `set()`** — the underlying error object is never written to devtools.
- **`error instanceof Error`** is false for tauri-plugin-sql rejections (which are serialized Rust error strings — see "Tauri error shape" below). The displayed message becomes the generic French i18n key.
- **`throw error`** propagates upward, but only into Layer B which silently eats it.

The same pattern is duplicated at:
- `processCardCheckout` — `paymentStore.ts:340–346`
- `processAdvancedCheckout` — `paymentStore.ts:395–401`

**Layer B — `HomePage.handleCashConfirm` catch:** [`apps/pos/src/pages/HomePage.tsx:362–365`](../../apps/pos/src/pages/HomePage.tsx)

```ts
} catch {
  // Error is stored in paymentStore and displayed in the modal
}
```

Parameter-less catch — the error binding isn't even available. The comment is technically correct (the message *is* in `paymentStore.error`), but the underlying `Error` object (with stack, name, constructor) is now unreachable. The same anti-pattern repeats in `handleAdvancedComplete` at `HomePage.tsx:389–391`.

### Other catches in the call chain that may also swallow

| Function | File:line | Behavior | Surfaces? |
|---|---|---|---|
| `paymentStore.fetchPaymentConfig` SQLite step | `paymentStore.ts:189–191` | `} catch { /* SQLite not ready yet */ }` | **No** — silent (intentional, but during a startup race could hide a corrupted DB) |
| `paymentStore.fetchPaymentConfig` API step | `paymentStore.ts:210–215` | Catches and only `console.warn` if both API and SQLite empty | **Partial** — warn is benign |
| `paymentStore.fetchPaymentConfig` SQLite writeback | `paymentStore.ts:207–209` | `} catch { /* Non-critical */ }` | **No** |
| `terminalStore.seedOfflineHashChain` | `terminalStore.ts:107–109` | `console.error('[Terminal] Failed to seed offline hash chain:', error)` | **Yes** — would show in console if it fired (rule out via grep) |
| `terminalStore.refreshHashChainReady` | `terminalStore.ts:328–331` | `console.error('[Terminal] refreshHashChainReady failed:', error)` | Yes |
| `getDatabase` (db.ts:7–26) | none | No try/catch — propagates `Database.load()` errors as raw plugin rejections (string) | **N/A** — caller catches |
| `getTerminalState` (terminalStateRepository.ts:77–88) | none | Direct `queryOne` — propagates Tauri plugin error string | **N/A** — caller catches |
| `pushOfflineReceipts` outer | `syncService.ts:249–262` | Catches per-receipt; logs to sync_log; sets `chainBreak` if matching `isChainBreakError`. Does NOT `console.error`. | **No** — silent unless reading SQLite `sync_log` table |
| `runFullSync` cleanups | `syncService.ts:830–833` | All `try { ... } catch { /* non-critical */ }` | **No** |
| `triggerSync` outer | `syncStore.ts:80–82` | `scheduler.syncNow().catch(() => {})` — explicit silence | **No** |
| `HomePage` ESC/POS receipt build | `HomePage.tsx:235–237` | `console.error('[POS] Failed to assemble receipt for thermal print:', err)` | Yes (for printing only) |
| `HomePage.handleAddRecommendation` | `HomePage.tsx:303–305` | `} catch { /* Silently fail */ }` | **No** |

**Verdict:** The cash-checkout error path has *zero* `console.error` between the SQLite plugin and the user-visible banner. Every layer either swallows or unwraps in a way that loses the original throwable.

### Tauri error shape — why `instanceof Error` is false

`@tauri-apps/plugin-sql`'s JS API resolves to native `invoke()` calls (`plugin:sql|execute`, `plugin:sql|select`). When the Rust side returns `Err(crate::Error::...)`, Tauri serializes the error to JSON and the JS-side `invoke` rejects the promise with a *string* (or a plain object), **not** with a `new Error(...)`.

Empirically the rejection looks like one of:
- `"error returned from database: (code: 1) NOT NULL constraint failed: offline_receipts.payment_method_id"`
- `"error returned from database: (code: 5) database is locked"`
- `"error returned from database: (code: 19) UNIQUE constraint failed: offline_receipts.idempotency_key"`

`typeof error === 'string'` ⇒ `error instanceof Error === false` ⇒ the ternary in `processCashCheckout`'s catch falls back to `i18n.t('errors.checkoutFailed')` ⇒ user sees "Échec du paiement". Devtools console is silent because nobody logged the string before discarding it.

This is a *known and reproducible* class of bug for tauri-plugin-sql consumers; the same anti-pattern was the headline finding of [`docs/superpowers/audits/2026-04-30-pos-offline-first-audit-codex.md`](2026-04-30-pos-offline-first-audit-codex.md) §3.

---

## §2 — Top 5 candidate root causes for "Échec du paiement"

Ranked by likelihood for a fresh PharmaBio France parapharmacy seed (9 payment methods, 7 payment repositories per the user note).

### Cause A — NOT NULL constraint violation on `offline_receipts.payment_method_id` or `payment_repository_id`

(a) **Throw site:** `insertOfflineReceipt` in `offlineReceiptRepository.ts:60–86` (the parametrised INSERT). Schema: `payment_method_id TEXT NOT NULL`, `payment_repository_id TEXT NOT NULL` (`migrations.ts:112–113`). If `cashMethod.id` or `cashRegister.id` is `undefined`, Tauri SQL rejects with `"NOT NULL constraint failed: offline_receipts.payment_method_id"`.
(b) **Why error.message could be empty/falsy:** Not empty — the Tauri rejection is a non-empty *string*. But because it's not an `Error` instance, the catch ignores it and writes the i18n fallback into `paymentStore.error`. Hence the cashier sees the generic banner and the original message vanishes.
(c) **Required state:** `paymentMethods` array contains an object lacking `id`. The most common way this happens in this codebase is the recurring **"paginated response double-unwrap"** bug recorded in [`MEMORY.md`](../../../../.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/MEMORY.md) — `apiGet<PaymentMethod[]>('/payment-methods')` (`paymentApi.ts:4`) unwraps `response.data.data`. If the server now returns `{ data: { data: [...], meta: {...} } }` (paginated shape) instead of `{ data: [...] }` (flat array), `apiGet` returns the *inner object* `{ data: [...], meta: {...} }`, and the code `paymentMethods.find(m => m.is_physical && ...)` either throws `paymentMethods.find is not a function` (Error — would surface via `error.message`) or returns a wrapper-shaped non-PaymentMethod row whose `.id` is undefined.
(d) **Likelihood on PharmaBio:** Medium-low. `apps/api`'s payment-methods controller has been using a flat `data: [...]` shape for months and the SQLite cache pathway in `fetchPaymentConfig` would have rescued the in-memory state on second load. But worth ruling out via the instrumentation snippet.

### Cause B — `database is locked` from the fire-and-forget sync racing the next checkout

(a) **Throw site:** `db.execute('BEGIN TRANSACTION')` at `receiptService.ts:216`. SQLite returns `SQLITE_BUSY` when another connection holds a write lock. tauri-plugin-sql in WAL mode generally serialises writes via a single Rust-side connection — but `Database.load()` returns a *cloned* connection handle, and `runFullSync` does many `await execute(...)` calls across `pullProducts` / `pullPaymentConfig` / etc. that can interleave with a checkout's BEGIN.
(b) **Why error.message could be empty/falsy:** Same Tauri-string mechanism as Cause A.
(c) **Required state:** A sync tick is in flight when the user presses "Terminer et imprimer". The fire-and-forget from `createReceiptLocalFirst` (line 153) plus the 250 ms debounced sync from `scheduleDebouncedSync` (`offlineReceiptRepository.ts:9–22`) means: every successful prior checkout schedules a sync 250 ms later, which keeps `isSyncing` true for several seconds while pulling products, payment config, terminal state, z-chain state, tables, active menu, etc.
(d) **Likelihood on PharmaBio:** **Highest of the five for a fresh parapharmacy seed**, because the *first* checkout after shift open follows immediately after `seedOfflineHashChain` started the scheduler (`terminalStore.ts:104–106`), and `useEffect(...)` on `HomePage:168–174` triggers `fetchPaymentConfig` simultaneously. The PharmaBio seed has ~thousands of products whose first sync round-trip can take many seconds — the cashier opens the cart and clicks Pay during that window.

### Cause C — Stale paymentMethods/paymentRepositories arrays after rehydration

(a) **Throw site:** `processCashCheckout:228–235` (`if (!cashMethod) ... throw new Error(i18n.t('errors.noCashMethod'))`) or `:240–244` (`noCashRegister`).
(b) **Why error.message could be empty/falsy:** This case throws a real `Error` whose `.message` is the translated string ("Aucun moyen de paiement espèces configuré...") — so the user *would* see the descriptive message, not "Échec du paiement". **This rules Cause C out as the explanation for the observed banner text.** Listing it for completeness because the prior plan calls it out.
(c) Required state: `paymentMethods` array empty after rehydration.
(d) Likelihood on PharmaBio: Low — fetchPaymentConfig has SQLite-first fallback (`paymentStore.ts:179–215`), and even if API fails the SQLite cache from prior sync would populate.

### Cause D — `terminal_state` row missing or `last_hash` corrupted

(a) **Throw site:** `getTerminalState` returns `null` ⇒ `createOfflineReceipt:108–111` throws `new Error('Terminal hash chain not initialized...')`. This is a real `Error` ⇒ user *would* see the message in clear text.
(b) Doesn't fit the symptom.
(c) Required state: `terminal_state` row missing for `terminalId`.
(d) Likelihood on PharmaBio: Effectively zero, because:
  - `HomePage.tsx:621` gates the Pay button on `checkoutDisabled={!hashChainReady || isProcessing}`.
  - `hashChainReady` is set true only when `getTerminalState(...)` returns non-null (`terminalStore.ts:95`, `327`).
  - The user *can press* Pay Cash, so `hashChainReady === true`, so `getTerminalState` returns a row.

**Same logic rules out:** `noOperatorIdentified` (Error, surfaces), `noPaymentProvided` (Error, surfaces), `noCompanySelected` (Error, surfaces), `unknownPaymentMethod` (Error, surfaces). All of these would render their translated message into the banner if they fired.

### Cause E — Dangling SQLite transaction from a prior partial throw

(a) **Throw site:** `db.execute('BEGIN TRANSACTION')` at `receiptService.ts:216` rejects with `"cannot start a transaction within a transaction"` (SQLITE_ERROR code 1) if a previous `createOfflineReceipt` call's catch block (lines 221–224) failed *before* reaching `db.execute('ROLLBACK')`.
(b) Tauri-string error ⇒ falls into the i18n fallback (matches symptom).
(c) Required state: An earlier checkout in the same session threw between `BEGIN TRANSACTION` and the catch's `ROLLBACK`, *and* the `ROLLBACK` itself threw too. Looking at the catch at `:221–224`:

```ts
} catch (error) {
  await db.execute('ROLLBACK');
  throw error;
}
```

If `db.execute('ROLLBACK')` itself rejects, the `await` rethrows that rejection (replacing `error` with a `ROLLBACK`-level error and *losing the original*), and the transaction stays open. The next `BEGIN TRANSACTION` then fails. The original cause is also lost.

(d) Likelihood on PharmaBio: Medium — only fires after at least one prior failed checkout in the same Tauri session. Could explain why the user reports *consistent* failures (the first one breaks the transaction state, every subsequent one fails on BEGIN).

### Cause F (bonus) — `connection.close()` race when switching companies

`getDatabase` at `db.ts:7–26` closes the previous connection if `currentDbName !== dbName`. If `useAuthStore.companyId` is briefly null/different at the moment `processCashCheckout` runs (e.g., during a token refresh), the close races with the in-flight BEGIN. Probability low; mention for completeness.

---

## §3 — `ChainBreakAlert` root cause (Finding #3 + #4 expanded)

### What it gates on

[`apps/pos/src/components/atoms/ChainBreakAlert.tsx:6–11`](../../apps/pos/src/components/atoms/ChainBreakAlert.tsx):

```ts
const chainBreak = useSyncStore((s) => s.chainBreak);
const chainBreakReceiptNumber = useSyncStore((s) => s.chainBreakReceiptNumber);
const chainBreakAcknowledgedAt = useSyncStore((s) => s.chainBreakAcknowledgedAt);
const acknowledgeChainBreak = useSyncStore((s) => s.acknowledgeChainBreak);

if (!chainBreak) return null;
```

Pure read of `useSyncStore.chainBreak`. The "Acknowledge" button only sets a timestamp — it does **not** clear the flag (`syncStore.ts:95`).

### Where the flag gets set (the only writer)

`apps/pos/src/lib/sync/syncScheduler.ts:80–85`:

```ts
if (result.chainBreak) {
  const { getLastSyncedReceiptNumber } = await import('@/lib/db/repositories/offlineReceiptRepository');
  const lastSynced = await getLastSyncedReceiptNumber(this.db);
  useSyncStore.getState().setChainBreak(true, lastSynced);
}
```

`result.chainBreak` is set inside `pushOfflineReceipts` in two paths (`syncService.ts:234–262`):

1. **Server returned `status: 'chain_broken'`** for a pushed receipt (or `status: 'failed'` with an error message matching `/hash chain|hash mismatch|chain break/i` — see `isChainBreakError` at `:151–158`).
2. **A network or other thrown error** matched `isChainBreakError(error)` after a per-receipt push.

In both cases the loop `break`s immediately to preserve chain integrity.

**Critical:** `setChainBreak(false, null)` is called from **nowhere in production code** (only in tests). So the flag is never auto-cleared. The only "soft reset" is `acknowledgeChainBreak`, which leaves `chainBreak: true` and just records a timestamp (`ChainBreakAlert` then renders the smaller acknowledged variant — but it stays visible).

### Server vs client divergence

The flag is **purely server-detected**. Cite:
- The trigger source is the server's response to `POST /pos/receipts/sync` containing `results[*].status: 'chain_broken'` (`syncService.ts:90–100`, `:234`).
- `pullTerminalState` does NOT set chain-break — it gracefully handles `FiscalRegressionError` from the local repo as "preserved local state" (`syncService.ts:512–532`).
- `advanceHashChain` regression from a server response also does NOT set chain-break — it's logged as `reconcile skipped (local ahead)` (`syncService.ts:217–230`).

### Why it persists across reloads

`useSyncStore` has **no `persist` middleware** (`syncStore.ts:31–41`). On every cold start, `chainBreak: false`. So the banner *should* clear on relaunch.

**It re-fires within the first sync tick after init**, because:
1. `terminalStore.initialize` → `seedOfflineHashChain` → `new SyncScheduler(...).start()` → first `tick()` runs immediately (`syncScheduler.ts:25–33`).
2. `tick()` → `runFullSync` → `pushOfflineReceipts` iterates `getPendingReceiptsForSync`, which returns `status IN ('pending', 'failed') AND retry_count < 5` (`offlineReceiptRepository.ts:131–139`).
3. The bad receipt (or any subsequent receipt whose `previous_hash` chains to the unsynced bad one) re-fails ⇒ `result.chainBreak = true` ⇒ `setChainBreak(true, ...)`.

This explains the user's observation that "this also persists." It is not a stale UI state — it is a real, ongoing sync rejection.

### The amplification trap

After `retry_count` saturates at 5, the bad receipt is *no longer pushed*. But every newer offline receipt's `previous_hash` field is still the bad receipt's `fiscal_hash`. The server's chain ends at the last successfully synced receipt, so the server can never validate the newer receipts either — they all return `chain_broken`. The chain-break flag re-fires on every sync tick that has any newer receipt to push. **The bug compounds with every sale.**

The only fix is operator-side reconciliation: identify the first un-synced receipt, void it locally, and rebuild the chain from the next un-voided receipt with the server's `last_hash`. There is no such tool in the code today.

---

## §4 — Convergence with the sync deep-dive (Finding #5)

The previous sync deep-dive ([`2026-04-30-pos-sync-flow-deep-dive-opus.md`](2026-04-30-pos-sync-flow-deep-dive-opus.md)) — which I did not author but inherits the same call-graph view — would have noted that POS-side "POS error / server finalized" disagreements arise when:
- A receipt succeeds locally (writes SQLite, advances hash chain),
- but its push to the server returns an error that *coincidentally* matches the chain-break regex,
- triggering `setChainBreak(true, ...)` on the next tick even though the underlying issue may be transient (e.g., a 500 from the API server containing the substring "hash mismatch" in an unrelated context).

The **"Échec du paiement" failure and the chain-break banner are independent root causes**, with the following coupling:

- **Same root cause? No.** Cash checkout writes to local SQLite; chain-break is detected on server-side push response.
- **Coupled via SQLite write contention?** Yes — Cause B above (database is locked) creates a feedback loop: every successful checkout triggers a sync, which holds write locks while pulling products/payment config/etc., increasing the probability that the *next* checkout's BEGIN fails. The chain-break banner increases the rate of sync attempts (because the scheduler keeps backing-off and retrying).
- **Coupled via the lost-message problem?** Yes — once Finding #1 obscures the real error, the operator (and us) cannot tell whether the local write failed (Cause A/B/E), the server rejected (chain-break), or both.

So the orchestrator session should treat them as two workstreams:
1. **P0 hotfix:** Finding #1 observability + Finding #2's most likely sub-cause (B or E). 1–2 hours.
2. **P1 follow-up:** Finding #3+#4 chain-break recovery tool. Days, requires server cooperation, out of scope for an emergency hotfix.

---

## §5 — Why the prior plan's hypothesis ranking shifts

The prior plan ([`2026-04-30-pos-checkout-failure-fix.md`](../plans/2026-04-30-pos-checkout-failure-fix.md) §Step 2 table) ranks causes in this order:
1. Local SQLite missing `terminal_state` row
2. `paymentMethods` array empty
3. `paymentRepositories` array empty
4. `operatorId` null
5. SQLite INSERT fails (schema drift)
6. Hash-chain regression guard

**My re-ranking** (with the gating-button observation that rules out #1, #4, #6, and the `Error`-vs-string observation that rules out #2 and #3):

| Prior rank | Verdict | Rationale |
|---|---|---|
| 1 | **Ruled out** | `hashChainReady=true` is required to reach Pay Cash; this implies `terminal_state` exists. |
| 2 | **Ruled out** | Throws `Error("noCashMethod")` whose message would surface verbatim. |
| 3 | **Ruled out** | Same — throws `Error("noCashRegister")`, surfaces. |
| 4 | **Ruled out** | Same — throws `Error("noOperatorIdentified")`, surfaces. |
| 5 | **Promoted to #1 candidate** | This is exactly the Tauri-string failure mode. The plan's instinct was right; the framing was off. |
| 6 | **Ruled out** | `FiscalRegressionError` extends `Error`; its message would surface verbatim. |

**Net new candidates added by this audit:**
- **Cause B**: `database is locked` from sync race. Plausible for first-checkout-after-shift-open scenario.
- **Cause E**: Dangling transaction from a prior partial throw. Could explain *consistent* repeat failures.
- **Cause A**: Paginated-response double-unwrap surfacing as undefined `id`. Worth ruling out given the recurring nature in this codebase.

---

## §6 — Minimum-viable diagnostic instrumentation (paste-ready)

Add the following five `console.error`/`console.info` calls. **No logic changes**, just observability. Expected ship time: 15 minutes including a smoke run on dev.

### 6a — `paymentStore.processCashCheckout` catch (the headline fix)

**File:** `apps/pos/src/stores/paymentStore.ts`
**Replace lines 272–278 with:**

```ts
} catch (error) {
  console.error('[POS][checkout][cash] failed', {
    error,
    errorType: typeof error,
    isError: error instanceof Error,
    errorName: error instanceof Error ? error.name : undefined,
    message: error instanceof Error ? error.message : String(error),
    stack: error instanceof Error ? error.stack : undefined,
    cartItemCount: cartItems.length,
    tenderedAmount,
    terminalId,
    cashMethodId: cashMethod.id,
    cashRegisterId: cashRegister.id,
  });
  set({
    isProcessing: false,
    error: error instanceof Error && error.message
      ? error.message
      : `${i18n.t('errors.checkoutFailed', { ns: 'pos' })}: ${error instanceof Error ? error.constructor.name : typeof error}${typeof error === 'string' ? ` — ${error.slice(0, 200)}` : ''}`,
  });
  throw error;
}
```

### 6b — `paymentStore.processCardCheckout` catch (mirror)

**File:** `apps/pos/src/stores/paymentStore.ts`
**Replace lines 340–346 with the equivalent `[POS][checkout][card]`-prefixed block.** Same fields minus `tenderedAmount`. Same expanded fallback string.

### 6c — `paymentStore.processAdvancedCheckout` catch (mirror)

**File:** `apps/pos/src/stores/paymentStore.ts`
**Replace lines 395–401 with the equivalent `[POS][checkout][advanced]`-prefixed block.** Add `paymentLineCount: payments.length`.

### 6d — `createReceiptLocalFirst` (capture *before* SQLite enters)

**File:** `apps/pos/src/stores/paymentStore.ts`
**Wrap the call to `createOfflineReceipt` at lines 130–150 in a try/catch:**

```ts
let result;
try {
  result = await createOfflineReceipt(db, { /* ...existing args... */ });
} catch (error) {
  console.error('[POS][checkout][localFirst] createOfflineReceipt threw', {
    error,
    errorType: typeof error,
    isError: error instanceof Error,
    message: error instanceof Error ? error.message : String(error),
    stack: error instanceof Error ? error.stack : undefined,
    companyId,
    operatorId,
    operatorName,
    primaryMethodCode: primary.methodCode,
    primaryPaymentMethodId: primary.paymentMethodId,
    primaryRepositoryId: primary.repositoryId,
    paymentLineCount: payments.length,
  });
  throw error;
}
```

This separates "local SQLite write failed" from "something earlier in the flow threw" (e.g., `getDatabase` itself).

### 6e — `createOfflineReceipt` SQLite transaction block

**File:** `apps/pos/src/lib/offline/receiptService.ts`
**Replace lines 216–224 with:**

```ts
await db.execute('BEGIN TRANSACTION');
try {
  await insertOfflineReceipt(db, offlineReceipt);
  await advanceHashChain(db, input.terminalId, fiscalHash, newSequence);
  await db.execute('COMMIT');
} catch (error) {
  console.error('[POS][offline][receipt] tx body threw — rolling back', {
    error,
    errorType: typeof error,
    isError: error instanceof Error,
    message: error instanceof Error ? error.message : String(error),
    receiptNumber,
    hashSequence: newSequence,
    previousHash: terminalState.last_hash,
    terminalId: input.terminalId,
  });
  try {
    await db.execute('ROLLBACK');
  } catch (rollbackError) {
    console.error('[POS][offline][receipt] ROLLBACK also threw — connection may be in bad state', {
      rollbackError,
      rollbackErrorType: typeof rollbackError,
      isError: rollbackError instanceof Error,
      message: rollbackError instanceof Error ? rollbackError.message : String(rollbackError),
    });
  }
  throw error;
}
```

This isolates Cause E (dangling transaction) — if the second `console.error` fires, that's the smoking gun.

### Acceptance for the orchestrator session

After adding the five snippets, trigger the failure once with Tauri devtools open. Read the structured log entry. The error will fall into one of:

| Console output | Implication | Next step |
|---|---|---|
| `errorType: 'string'` + message contains `NOT NULL constraint failed: offline_receipts.payment_method_id` | Cause A | Audit the `paymentMethods.find(...)` result, verify API response shape isn't paginated |
| `errorType: 'string'` + message contains `database is locked` | Cause B | Add a SQLite mutex around BEGIN, or queue checkout writes through `useSyncStore.isSyncing` |
| `errorType: 'string'` + message contains `cannot start a transaction within a transaction` (or first ROLLBACK fired) | Cause E | Refactor the transaction handler to ensure ROLLBACK never throws (use `try/finally` instead of try/catch with await ROLLBACK), or call `PRAGMA foreign_keys = ON; ROLLBACK; ` defensively at session start |
| `isError: true` + a meaningful name like `FiscalRegressionError` | A real `Error` was being thrown all along, but the secondary HomePage catch was swallowing the stack | Move the `console.error` from paymentStore into the `HomePage` catches as well; root cause is whatever `error.name` says |
| `errorType: 'object'` (not Error) | Some other plugin (e.g., `@tauri-apps/plugin-http`) is rejecting with a structured object | Inspect `Object.keys(error)` and treat similarly |

---

## §7 — Targeted fix candidates for the top 3 sub-causes

### Fix for Cause A (NOT NULL on payment_method_id) — defensive guard at the boundary

**Cited lines:** `paymentStore.ts:228–244` (cashMethod / cashRegister find).

**Sketch:**
```ts
// After existing find() calls, before the try block at line 248:
if (!cashMethod.id || !cashRegister.id) {
  const msg = `Payment configuration corrupted: cash method id=${String(cashMethod.id)}, repository id=${String(cashRegister.id)}. Re-run sync.`;
  console.error('[POS][checkout][cash] payment config has falsy IDs', { cashMethod, cashRegister });
  set({ error: msg });
  throw new Error(msg);
}
```

This converts a Tauri NOT NULL into a meaningful operator-facing message. **Effort: 15 min** including a unit test.

**Root-cause fix (rather than the boundary guard):** verify `apiGet<PaymentMethod[]>('/payment-methods')` in `paymentApi.ts:4` returns a flat array. If the controller is now paginated, switch to `api.get` directly per the [`MEMORY.md`](../../../../.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/MEMORY.md) "double-unwrap" rule. **Effort: 20 min** + verifying the API contract.

### Fix for Cause B (database is locked) — serialise checkouts behind sync state

**Cited lines:** `paymentStore.ts:248` (`set({ isProcessing: true, error: null })`); `receiptService.ts:216` (`BEGIN TRANSACTION`).

**Sketch:** Before BEGIN, check `useSyncStore.getState().isSyncing` and either (a) wait for it to drop to false (with a 3 s timeout) or (b) accept that the checkout itself will queue behind the lock — and explicitly handle the `SQLITE_BUSY` rejection as a retry-once.

A safer fix is to instruct tauri-plugin-sql to use a higher `busy_timeout` (default is 5 seconds, configurable via `PRAGMA busy_timeout = N` or via the plugin's `Database.load()` URL params). Setting `busy_timeout=10000` (10 s) lets BEGIN wait through any concurrent sync write rather than failing immediately.

**Effort: 30 min** for the PRAGMA approach (one-line change at `db.ts:20`); 2+ hours for proper write serialisation. Recommend the PRAGMA approach for the hotfix.

### Fix for Cause E (dangling transaction) — `try/finally` around ROLLBACK

**Cited lines:** `receiptService.ts:221–224`.

**Sketch:** Replace the inner catch with a structure that guarantees the connection's transaction state is reset:

```ts
let txError: unknown = null;
try {
  await insertOfflineReceipt(db, offlineReceipt);
  await advanceHashChain(db, input.terminalId, fiscalHash, newSequence);
  await db.execute('COMMIT');
} catch (error) {
  txError = error;
  // Best-effort rollback; never let it shadow the original error.
  await db.execute('ROLLBACK').catch((rollbackError) => {
    console.error('[POS][offline][receipt] ROLLBACK swallowed', { rollbackError });
  });
}
if (txError) throw txError;
```

The key change: `db.execute('ROLLBACK').catch(...)` does **not** await on a `throw`, so a failing rollback no longer shadows the original error.

**Effort: 20 min** including a regression test that simulates the dangling-tx scenario.

---

## §8 — Reconciliation against prior plans

### vs `2026-04-30-pos-checkout-failure-fix.md`

| Section | Prior plan | This audit |
|---|---|---|
| §1 Symptoms | Identical | — |
| §2 Why no error | Correctly identifies the swallowed catch | Extends with: tauri-plugin-sql rejects with strings (root mechanism); HomePage catch is also a swallower (compounds the silence); 5 other catches in the call chain are listed for completeness |
| §3 Step 1 instrumentation | `console.error` at the catch with structured payload | Same recommendation; this audit provides ready-to-paste snippets for **5 sites**, not just the cash catch — including the `BEGIN TRANSACTION` block which is where the underlying failure actually fires |
| §3 Step 2 cause table | 6 ranked causes | Re-ranks with explicit `instanceof Error` reasoning that *rules out* 4 of the 6 (causes 1, 2, 3, 4, 6 cannot match the symptom because they throw real Errors with surfacing messages); promotes "SQLite INSERT fails (schema drift)" from #5 to #1 (re-named: "Tauri SQL string rejection from BEGIN/INSERT/COMMIT"); adds new candidate B (database is locked) and E (dangling transaction) |
| §3 Step 3 fixes | 3 targeted fixes | Same shape; this audit cites specific line numbers and provides estimated effort per fix |
| §3 Currency precision | "no code change needed" | Confirmed — the cash checkout failure is unrelated to TND precision |
| Acceptance | Lists the cashier-visible improvements | This audit adds an **operator decision matrix** (§6 acceptance table) so the orchestrator knows what to do based on what the console shows |

**Net delta from this audit:**
- Provides a *deterministic* triage table mapping console output → root cause → next step.
- Identifies Causes B and E that the prior plan didn't enumerate.
- Reframes the chain-break as a separate, ongoing server-side workstream (with no current recovery tool) that is **not** the cause of "Échec du paiement" but is concurrent with it.

### vs `2026-04-30-pos-offline-first-audit-codex.md`

The Codex audit's §3 (POS sync error patterns) describes the same Tauri-string mechanism as a recurring class. This audit provides the per-site fix snippets that the Codex audit recommended in principle.

---

## Open questions for the orchestrator session

1. **Is the offline_receipts table on the user's local SQLite carrying a `failed` row with `retry_count < 5`?** A simple SQLite query at session start would answer this:
   `SELECT id, receipt_number, retry_count, sync_error FROM offline_receipts WHERE status = 'failed' ORDER BY hash_sequence;`
2. **Has the user already triggered a chain-break-clearing migration (e.g., manually voiding the bad receipt)?** If so, the chain-break flag should clear on next sync — verify by reading sync_log.
3. **What does `/payment-methods` return on the dev server right now?** Hit the endpoint via curl with the same auth; verify shape is `{ data: [...] }` not `{ data: { data: [...] } }`.
4. **Is the dev server's tauri-plugin-sql version pinned to one that supports multi-statement `execute()`?** Migration 22 has a multi-statement body; if multi-statement is broken, half the cash-counting tables are missing and the user's local DB is in a degenerate state. (Quickly verifiable: `SELECT name FROM sqlite_master WHERE type='table' AND name IN ('z_report_counts', 'company_fraud_settings_cache');` — both should return.)

---

## Appendix — file:line citation table

| Subject | File:line |
|---|---|
| Cash checkout entry | `apps/pos/src/stores/paymentStore.ts:218–279` |
| Catch that swallows non-Error | `apps/pos/src/stores/paymentStore.ts:272–278` |
| HomePage catch that swallows the rethrow | `apps/pos/src/pages/HomePage.tsx:362–365` |
| `createReceiptLocalFirst` operator/company gates | `apps/pos/src/stores/paymentStore.ts:95–173` |
| `createOfflineReceipt` core | `apps/pos/src/lib/offline/receiptService.ts:101–237` |
| Transaction block | `apps/pos/src/lib/offline/receiptService.ts:216–224` |
| `insertOfflineReceipt` SQL | `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:60–86` |
| `getTerminalState` | `apps/pos/src/lib/db/repositories/terminalStateRepository.ts:77–88` |
| `advanceHashChain` regression guard | `apps/pos/src/lib/db/repositories/terminalStateRepository.ts:171–213` |
| `getDatabase` | `apps/pos/src/lib/db.ts:7–26` |
| Sync scheduler tick | `apps/pos/src/lib/sync/syncScheduler.ts:63–126` |
| `setChainBreak` only writer | `apps/pos/src/lib/sync/syncScheduler.ts:80–85` |
| `pushOfflineReceipts` chain-break detection | `apps/pos/src/lib/sync/syncService.ts:178–263` |
| `isChainBreakError` regex | `apps/pos/src/lib/sync/syncService.ts:151–158` |
| `pullTerminalState` regression-as-success | `apps/pos/src/lib/sync/syncService.ts:484–539` |
| `getPendingReceiptsForSync` | `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:131–139` |
| ChainBreakAlert component | `apps/pos/src/components/atoms/ChainBreakAlert.tsx` |
| `useSyncStore` (no persist middleware) | `apps/pos/src/stores/syncStore.ts:31–96` |
| HomePage Pay Cash gating | `apps/pos/src/pages/HomePage.tsx:621` |
| `apiGet` / `ApiRequestError` (Error-instance contract) | `apps/pos/src/lib/api.ts:1–145` |
| French i18n keys | `apps/pos/src/locales/fr/pos.json:541–567` |
| Migrations (offline_receipts schema) | `apps/pos/src/lib/db/migrations.ts:87–122, 303–325` |
