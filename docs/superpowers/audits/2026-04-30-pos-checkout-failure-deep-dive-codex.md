# POS Checkout Failure + Chain-Break Deep Dive — 2026-04-30 (Codex)

Scope: static investigation of the Tauri POS desktop app (`apps/pos`) cash checkout failure and the `ChainBreakAlert` banner. No production code was changed.

Prior diagnostic plan checked: `docs/superpowers/plans/2026-04-30-pos-checkout-failure-fix.md`.

## Executive Summary

Top findings ranked by likelihood x user impact:

| Rank | Finding | Impact |
|---|---|---|
| 1 | The console is silent because `processCashCheckout()` catches the checkout throwable, stores only a banner string, never logs, then `HomePage.handleCashConfirm()` catches the rethrow with a parameter-less `catch {}`. | P0 diagnosability blocker |
| 2 | The exact generic banner `Échec du paiement` strongly points to a non-`Error` throwable or an `Error` with an empty message. In this call path, the most likely source is Tauri SQLite (`Database.load`, `select`, `execute`) rejecting with a string/plain object. | P0 checkout blocker |
| 3 | The chain-break banner is push-time/server-detected. It is set only when `pushOfflineReceipts()` sees `chain_broken` or a hash-chain-looking failure; `pullTerminalState()` does not set it and deliberately preserves local state on `FiscalRegressionError`. | P0 fiscal integrity alarm |
| 4 | The cash checkout failure and chain-break banner are probably not the same immediate throw site. Cash checkout is local-first and does not await receipt sync; chain-break is raised later by background sync. They can still converge through a bad local receipt, SQLite contention, or repeated retries. | P0 scoping |
| 5 | Payment config has a real sync drift: foreground checkout loads `/payment-methods` and `/payment-repositories`, but background sync pulls `/treasury/payment-methods` and `/treasury/payment-repositories`, while Laravel routes expose `/payment-methods` and `/payment-repositories`. This can leave SQLite stale even when the PharmaBio seed has the expected cash method/register. | P1 recurrent setup drift |

## 1. Why The Console Is Silent

The cash path is:

`CashPaymentScreen.handleConfirm()` calls `onConfirm(tenderedNum)` when tender is valid and not processing (`apps/pos/src/components/organisms/CashPaymentScreen/CashPaymentScreen.tsx:71`). `HomePage` passes `onConfirm={(amount) => void handleCashConfirm(amount)}` (`apps/pos/src/pages/HomePage.tsx:1001`). `handleCashConfirm()` awaits `processCashCheckout()` and then silently catches any rethrow (`apps/pos/src/pages/HomePage.tsx:676`, `apps/pos/src/pages/HomePage.tsx:690`).

Confirmed silent edge:

```ts
// apps/pos/src/pages/HomePage.tsx:676
const handleCashConfirm = useCallback(async (tenderedAmount: number) => {
  if (!terminal) return;
  try {
    await processCashCheckout(...);
    setShowCashModal(false);
    setShowSuccessModal(true);
  } catch {
    // Error is stored in paymentStore and displayed in the modal
  }
}, [...]);
```

The store catch does not log either:

```ts
// apps/pos/src/stores/paymentStore.ts:345
} catch (error) {
  set({
    isProcessing: false,
    error: error instanceof Error ? error.message : i18n.t('errors.checkoutFailed', { ns: 'pos' }),
  });
  throw error;
}
```

That explains the exact symptom:

- If the thrown value is an `Error` with a non-empty `.message`, the banner shows that message.
- If the thrown value is not an `Error`, the banner falls back to `errors.checkoutFailed`.
- In French, that key is `"Échec du paiement"` (`apps/pos/src/locales/fr/pos.json:608`).
- No layer logs the original throwable before it is discarded by `HomePage`.

Other catches in the requested call chain:

| Function | Current behavior |
|---|---|
| `HomePage.handleCashConfirm()` | Silent parameter-less catch (`apps/pos/src/pages/HomePage.tsx:690`). |
| `createReceiptLocalFirst()` | No catch; validates company/operator/payment, then propagates `getDatabase()` / `createOfflineReceipt()` failures (`apps/pos/src/stores/paymentStore.ts:163`). |
| `createOfflineReceipt()` | Catches only to `ROLLBACK` and rethrow; no log (`apps/pos/src/lib/offline/receiptService.ts:460`). |
| `getDatabase()` | No catch; `Database.load()` / migrations propagate raw plugin failures (`apps/pos/src/lib/db.ts:7`). |
| `getTerminalState()` | No catch; `queryOne()` failures propagate (`apps/pos/src/lib/db/repositories/terminalStateRepository.ts:84`). |

Adjacent silent/under-logged catches that matter during the same checkout session:

- `fetchPaymentConfig()` swallows SQLite read failure (`apps/pos/src/stores/paymentStore.ts:253`) and SQLite writeback failure (`apps/pos/src/stores/paymentStore.ts:275`).
- `fetchPaymentConfig()` catches API failure but only warns if the in-memory method list is empty (`apps/pos/src/stores/paymentStore.ts:283`).
- `syncStore.triggerSync()` swallows `scheduler.syncNow()` rejections (`apps/pos/src/stores/syncStore.ts:80`).
- `SyncScheduler.tick()` catches full-sync failure without console logging (`apps/pos/src/lib/sync/syncScheduler.ts:120`).
- `runFullSync()` has silent non-critical cleanup/refresh/image catches (`apps/pos/src/lib/sync/syncService.ts:1182`, `apps/pos/src/lib/sync/syncService.ts:1225`, `apps/pos/src/lib/sync/syncService.ts:1231`).

## 2. Top 5 Candidate Root Causes For `Échec du paiement`

The generic banner is the key discriminator. Explicit business-rule throws create real `Error` instances and should show their specific messages, not `Échec du paiement`.

### 1. SQLite transaction/write failure in `createOfflineReceipt()`

- Exact throw path: `processCashCheckout()` builds the cash payment (`apps/pos/src/stores/paymentStore.ts:328`), `createReceiptLocalFirst()` calls `createOfflineReceipt()` (`apps/pos/src/stores/paymentStore.ts:198`), then `createOfflineReceipt()` enters `BEGIN TRANSACTION`, calls `insertOfflineReceipt()`, `advanceHashChain()`, voucher balance updates, and `COMMIT` (`apps/pos/src/lib/offline/receiptService.ts:406`, `apps/pos/src/lib/offline/receiptService.ts:408`, `apps/pos/src/lib/offline/receiptService.ts:409`, `apps/pos/src/lib/offline/receiptService.ts:456`, `apps/pos/src/lib/offline/receiptService.ts:459`).
- Why `error.message` can be empty/falsy: `@tauri-apps/plugin-sql` boundaries can reject with a string/plain object rather than `new Error(...)`; `error instanceof Error` is false, so the store displays the generic i18n key.
- Required state: cash method/register were selected, operator/company are present, and one of `BEGIN`, the offline receipt insert, `terminal_state` update, voucher update, `COMMIT`, or `ROLLBACK` fails. Common shapes: `database is locked`, `constraint failed`, `cannot start a transaction within a transaction`.
- Fresh PharmaBio France likelihood: high. The seed contains a valid cash method (`CASH`, physical, no maturity, active at `apps/api/database/seeders/PaymentMethodSeeder.php:194`) and active cash registers (`apps/api/database/seeders/PaymentRepositorySeeder.php:102`, `apps/api/database/seeders/PaymentRepositorySeeder.php:113`), so a generic banner after those selections is more likely local SQLite/runtime than missing server config.

### 2. `Database.load()` or migration failure in `getDatabase(companyId)`

- Exact throw path: `createReceiptLocalFirst()` calls `getDatabase(companyId)` (`apps/pos/src/stores/paymentStore.ts:196`), which calls `Database.load('sqlite:izipos-${companyId}.db')` and then runs migrations (`apps/pos/src/lib/db.ts:20`, `apps/pos/src/lib/db.ts:23`, `apps/pos/src/lib/db.ts:36`).
- Why `error.message` can be empty/falsy: `Database.load()` and migration `execute()` calls are Tauri plugin/native boundaries.
- Required state: company is selected, but the DB file cannot open, the cached handle is invalid, a previous company switch closes the active handle, or a migration statement fails.
- Fresh PharmaBio France likelihood: medium. Less likely once products/payment config have loaded, but plausible on damaged local SQLite or a partially migrated desktop DB.

### 3. `getTerminalState()` SQL read failure or invalid local terminal-state row

- Exact throw path: `createOfflineReceipt()` reads the terminal state at `apps/pos/src/lib/offline/receiptService.ts:244`; `getTerminalState()` performs the `SELECT` at `apps/pos/src/lib/db/repositories/terminalStateRepository.ts:91`. If the row is null, `createOfflineReceipt()` throws `Terminal hash chain not initialized...` (`apps/pos/src/lib/offline/receiptService.ts:245`). If `fiscal_schema_version` is not 2 or 3, the repository throws (`apps/pos/src/lib/db/repositories/terminalStateRepository.ts:102`).
- Why `error.message` can be empty/falsy: the plain null-row and invalid-version cases throw real `Error`s, so they do not fit the generic French banner. The generic banner only fits the SQL/plugin rejection variant.
- Required state: checkout begins with `hashChainReady` true enough to open the payment UI, but the SQLite terminal-state read fails at receipt creation time.
- Fresh PharmaBio France likelihood: medium-low. `HomePage` disables checkout when `hashChainReady` is false (`apps/pos/src/pages/HomePage.tsx:966`), and that flag is set by reading `terminal_state` (`apps/pos/src/stores/terminalStore.ts:93`, `apps/pos/src/stores/terminalStore.ts:95`, `apps/pos/src/stores/terminalStore.ts:326`). A true missing row would more likely block earlier or show a specific error.

### 4. Local hash-chain advancement/update failure

- Exact throw path: `createOfflineReceipt()` computes `newSequence`, then writes the receipt and advances `terminal_state` (`apps/pos/src/lib/offline/receiptService.ts:268`, `apps/pos/src/lib/offline/receiptService.ts:408`, `apps/pos/src/lib/offline/receiptService.ts:409`). `advanceHashChain()` rejects if there is no row or the sequence is not strictly increasing (`apps/pos/src/lib/db/repositories/terminalStateRepository.ts:206`) or if the SQL update fails (`apps/pos/src/lib/db/repositories/terminalStateRepository.ts:222`).
- Why `error.message` can be empty/falsy: `FiscalRegressionError` itself is a real `Error` with a message (`apps/pos/src/lib/db/repositories/terminalStateRepository.ts:63`), so the generic banner only fits the SQL update rejection or a non-Error plugin rejection.
- Required state: a stale/broken local sequence, a duplicate sequence race, or an SQLite failure while updating the chain.
- Fresh PharmaBio France likelihood: medium if there were earlier failed attempts in the same DB; low on a pristine first sale.

### 5. Payment config unavailable, malformed, or stale despite valid seed rows

- Exact throw path: `processCashCheckout()` selects the first active physical non-maturity method (`apps/pos/src/stores/paymentStore.ts:301`) and the first active cash-register repository (`apps/pos/src/stores/paymentStore.ts:310`). Missing values throw `noCashMethod` or `noCashRegister` (`apps/pos/src/stores/paymentStore.ts:304`, `apps/pos/src/stores/paymentStore.ts:313`).
- Why `error.message` can be empty/falsy: the explicit missing-config branches are real `Error`s, so they should display specific French messages. Generic `Échec du paiement` only fits a malformed store shape that throws outside those explicit branches, such as `paymentMethods.find is not a function`, but a normal `TypeError` usually has a message.
- Required state: foreground `fetchPaymentConfig()` failed to hydrate from SQLite/API, or the in-memory arrays are malformed. This is made more plausible by silent SQLite catches (`apps/pos/src/stores/paymentStore.ts:253`, `apps/pos/src/stores/paymentStore.ts:275`).
- Fresh PharmaBio France likelihood: low for the exact generic banner, but important as a setup risk. The France seeder defines 9 methods including active `CASH` (`apps/api/database/seeders/PaymentMethodSeeder.php:191`) and repositories include two active cash registers plus 3 FR banks and PayPal (`apps/api/database/seeders/PaymentRepositorySeeder.php:100`, `apps/api/database/seeders/PaymentRepositorySeeder.php:185`, `apps/api/database/seeders/PaymentRepositorySeeder.php:219`, `apps/api/database/seeders/PaymentRepositorySeeder.php:234`).

Less likely for the exact symptom because they throw descriptive `Error`s:

- no company selected (`apps/pos/src/stores/paymentStore.ts:176`)
- no operator identified (`apps/pos/src/stores/paymentStore.ts:187`)
- no payment provided (`apps/pos/src/stores/paymentStore.ts:191`)
- no cash method/register (`apps/pos/src/stores/paymentStore.ts:304`, `apps/pos/src/stores/paymentStore.ts:313`)
- terminal hash chain null row (`apps/pos/src/lib/offline/receiptService.ts:245`)

## 3. ChainBreakAlert Root Cause

`ChainBreakAlert` gates only on `useSyncStore.chainBreak`:

```ts
// apps/pos/src/components/atoms/ChainBreakAlert.tsx:6
const chainBreak = useSyncStore((s) => s.chainBreak);
...
if (!chainBreak) return null;
```

It is mounted both before shift open and on the main home page (`apps/pos/src/pages/HomePage.tsx:864`, `apps/pos/src/pages/HomePage.tsx:909`).

The thin banner specifically means:

- `chainBreak === true`
- `chainBreakAcknowledgedAt !== null`

because the acknowledged variant renders at `apps/pos/src/components/atoms/ChainBreakAlert.tsx:13`. The acknowledge button only timestamps the alert; it does not clear it (`apps/pos/src/components/atoms/ChainBreakAlert.tsx:56`, `apps/pos/src/stores/syncStore.ts:95`).

The store flag is initialized false and is not persisted (`apps/pos/src/stores/syncStore.ts:31`). The only production caller setting it true is `SyncScheduler.tick()`:

```ts
// apps/pos/src/lib/sync/syncScheduler.ts:80
if (result.chainBreak) {
  const { getLastSyncedReceiptNumber } = await import('@/lib/db/repositories/offlineReceiptRepository');
  const lastSynced = await getLastSyncedReceiptNumber(this.db);
  useSyncStore.getState().setChainBreak(true, lastSynced);
}
```

`result.chainBreak` comes from `pushOfflineReceipts()`:

- It sets receipt status to `syncing` and POSTs a batch-of-one to `/pos/receipts/sync` (`apps/pos/src/lib/sync/syncService.ts:238`, `apps/pos/src/lib/sync/syncService.ts:242`).
- If the per-receipt response is `chain_broken`, or `failed` with hash-chain-looking text, it marks the row failed, logs, sets `chainBreak = true`, and breaks the loop (`apps/pos/src/lib/sync/syncService.ts:292`, `apps/pos/src/lib/sync/syncService.ts:296`, `apps/pos/src/lib/sync/syncService.ts:299`).
- If a thrown error matches `hash chain`, `hash mismatch`, or `chain break`, the catch also sets `chainBreak = true` and breaks (`apps/pos/src/lib/sync/syncService.ts:315`, `apps/pos/src/lib/sync/syncService.ts:316`, `apps/pos/src/lib/sync/syncService.ts:318`).

This is server-detected push divergence, not `pullTerminalState()` divergence. `pullTerminalState()` catches `FiscalRegressionError`, logs "preserved local state", records success, and returns true (`apps/pos/src/lib/sync/syncService.ts:585`, `apps/pos/src/lib/sync/syncService.ts:590`, `apps/pos/src/lib/sync/syncService.ts:595`, `apps/pos/src/lib/sync/syncService.ts:603`).

Why it appears persistent:

- The store itself resets on cold start.
- `seedOfflineHashChain()` starts a `SyncScheduler` after terminal init (`apps/pos/src/stores/terminalStore.ts:103`).
- `SyncScheduler.start()` runs an immediate tick (`apps/pos/src/lib/sync/syncScheduler.ts:28`).
- `getPendingReceiptsForSync()` retries `pending` and `failed` receipts with `retry_count < 5` (`apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:138`).
- The same bad row can re-trigger `setChainBreak(true, ...)` after every start or sync cycle.

Two important quirks:

- The banner's receipt number is the last synced receipt, not the rejected receipt, because the scheduler calls `getLastSyncedReceiptNumber()` (`apps/pos/src/lib/sync/syncScheduler.ts:82`, `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:237`).
- There is no production `setChainBreak(false, null)` call. Tests cover it, but no sync success clears the flag (`apps/pos/src/stores/syncStore.ts:89`).

## 4. Convergence With Sync Deep-Dive

These two visible bugs are unlikely to be the same immediate root cause:

- Cash checkout is local-first. `createReceiptLocalFirst()` commits SQLite, triggers sync fire-and-forget, and immediately sets `lastReceipt` from the local result (`apps/pos/src/stores/paymentStore.ts:198`, `apps/pos/src/stores/paymentStore.ts:225`, `apps/pos/src/stores/paymentStore.ts:230`).
- `processCashCheckout()` awaits only the local receipt creation (`apps/pos/src/stores/paymentStore.ts:328`) and then clears `isProcessing` (`apps/pos/src/stores/paymentStore.ts:344`).
- Chain-break is only raised by later background sync after `/pos/receipts/sync` responds or throws (`apps/pos/src/lib/sync/syncService.ts:242`, `apps/pos/src/lib/sync/syncScheduler.ts:80`).

They can still converge in three ways:

1. A previously bad local receipt poisons the queue. New receipts chain from local `terminal_state.last_hash`, but the server rejects them because it never accepted the earlier hash.
2. Background sync and checkout both write the same SQLite DB. If sync is running while checkout opens a transaction, a Tauri SQL `database is locked` string would produce the generic banner.
3. Silent observability makes retry behavior dangerous. The sync deep-dive correctly found that retrying the UI creates a new local receipt and new idempotency key (`apps/pos/src/lib/offline/receiptService.ts:311`, `apps/pos/src/lib/offline/receiptService.ts:312`), so cashier retries can become duplicate fiscal receipts even though `/pos/receipts/sync` is idempotent for the same key.

The sync deep-dive also matters for chain-break recovery:

- `getPendingReceiptsForSync()` excludes rows stuck in `syncing` (`apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:138`). If Tauri is killed mid-POST, the receipt may never retry.
- `MAX_SYNC_RETRIES` is 5 (`apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:136`); rows beyond that are no longer selected and there is no dead-letter UI.
- `cleanupStuckReceipts()` can delete old failed rows after 90 days (`apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:204`), which is dangerous for fiscal reconstruction if no reconciliation happened.

## 5. Minimum-Viable Diagnostic Instrumentation

These snippets are intentionally additive and safe for a short observability hotfix.

### `apps/pos/src/stores/paymentStore.ts`

Add near the top of the file:

```ts
function describeCheckoutError(error: unknown): string {
  if (error instanceof Error && error.message) return error.message;
  if (typeof error === 'string' && error.length > 0) return error;
  try {
    return JSON.stringify(error);
  } catch {
    return String(error);
  }
}
```

At `processCashCheckout()` catch (`apps/pos/src/stores/paymentStore.ts:345`):

```ts
    } catch (error) {
      console.error('[POS][checkout] cash checkout failed', {
        error,
        errorType: typeof error,
        isError: error instanceof Error,
        message: error instanceof Error ? error.message : String(error),
        stack: error instanceof Error ? error.stack : undefined,
        terminalId,
        cartItemCount: cartItems.length,
        tenderedAmount,
      });
      const message = describeCheckoutError(error);
      set({
        isProcessing: false,
        error: message || i18n.t('errors.checkoutFailed', { ns: 'pos' }),
      });
      throw error;
    }
```

Mirror the same pattern in:

- `processCardCheckout()` catch (`apps/pos/src/stores/paymentStore.ts:413`)
- `processAdvancedCheckout()` catch (`apps/pos/src/stores/paymentStore.ts:472`)

At `fetchPaymentConfig()` SQLite read catch (`apps/pos/src/stores/paymentStore.ts:262`):

```ts
    } catch (error) {
      console.warn('[POS][payment-config] SQLite read failed; falling back to API', {
        error,
        errorType: typeof error,
        companyId: useAuthStore.getState().companyId,
      });
    }
```

At `fetchPaymentConfig()` SQLite writeback catch (`apps/pos/src/stores/paymentStore.ts:280`):

```ts
      } catch (error) {
        console.warn('[POS][payment-config] SQLite writeback failed', {
          error,
          errorType: typeof error,
          methods: methods.length,
          repositories: repositories.length,
        });
      }
```

At `fetchPaymentConfig()` API catch (`apps/pos/src/stores/paymentStore.ts:283`):

```ts
    } catch (error) {
      console.warn('[POS][payment-config] API refresh failed; using SQLite cache if present', {
        error,
        errorType: typeof error,
        cachedMethods: get().paymentMethods.length,
        cachedRepositories: get().paymentRepositories.length,
      });
      if (get().paymentMethods.length === 0) {
        console.warn('[POS] No payment config available — neither API nor SQLite cache');
      }
    }
```

### `apps/pos/src/pages/HomePage.tsx`

At `handleCashConfirm()` catch (`apps/pos/src/pages/HomePage.tsx:690`):

```ts
      } catch (error) {
        console.error('[POS][checkout] cash confirm handler swallowed error', {
          error,
          errorType: typeof error,
          terminalId: terminal.id,
          cartItemCount: cartItems.length,
        });
        // Error is stored in paymentStore and displayed in the modal
      }
```

At `handleAdvancedComplete()` catch (`apps/pos/src/pages/HomePage.tsx:716`):

```ts
      } catch (error) {
        console.error('[POS][checkout] advanced confirm handler swallowed error', {
          error,
          errorType: typeof error,
          terminalId: terminal.id,
          cartItemCount: cartItems.length,
          paymentCount: payments.length,
        });
        // Error is stored in paymentStore
      }
```

### `apps/pos/src/lib/offline/receiptService.ts`

Around the transaction catch (`apps/pos/src/lib/offline/receiptService.ts:460`):

```ts
  } catch (error) {
    console.error('[POS][offline-receipt] transaction failed; rolling back', {
      error,
      errorType: typeof error,
      terminalId: input.terminalId,
      receiptNumber,
      idempotencyKey,
      hashSequence: newSequence,
      cartItemCount: input.cartItems.length,
      paymentCount: input.payments.length,
    });
    try {
      await db.execute('ROLLBACK');
    } catch (rollbackError) {
      console.error('[POS][offline-receipt] rollback failed', {
        rollbackError,
        rollbackErrorType: typeof rollbackError,
        originalError: error,
      });
    }
    throw error;
  }
```

### `apps/pos/src/lib/sync/syncService.ts`

At chain-break result branch (`apps/pos/src/lib/sync/syncService.ts:292`):

```ts
        console.error('[POS][sync] receipt chain break from server', {
          receiptId: receipt.id,
          receiptNumber: receipt.receipt_number,
          idempotencyKey: receipt.idempotency_key,
          hashSequence: receipt.hash_sequence,
          previousHash: receipt.previous_hash,
          fiscalHash: receipt.fiscal_hash,
          status: resultItem.status,
          error: resultItem.error,
        });
```

At per-receipt catch (`apps/pos/src/lib/sync/syncService.ts:307`):

```ts
      console.error('[POS][sync] receipt push failed', {
        error,
        errorType: typeof error,
        receiptId: receipt.id,
        receiptNumber: receipt.receipt_number,
        idempotencyKey: receipt.idempotency_key,
        hashSequence: receipt.hash_sequence,
      });
```

At `pullTerminalState()` preserved local state branch (`apps/pos/src/lib/sync/syncService.ts:589`), existing `console.warn` is good; keep it. Add console for the outer catch (`apps/pos/src/lib/sync/syncService.ts:607`):

```ts
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Unknown error';
    console.error('[POS][sync] pullTerminalState failed', {
      error,
      errorType: typeof error,
      terminalId,
      message,
    });
    await logSyncOperation(db, 'pull', 'terminal_state', terminalId, 'error', message);
    return false;
  }
```

### `apps/pos/src/stores/syncStore.ts`

At `triggerSync()` catch (`apps/pos/src/stores/syncStore.ts:80`):

```ts
    scheduler.syncNow().catch((error: unknown) => {
      console.error('[POS][sync] scheduler.syncNow rejected unexpectedly', {
        error,
        errorType: typeof error,
      });
    });
```

### `apps/pos/src/lib/api.ts`

At non-JSON error-body parse catch (`apps/pos/src/lib/api.ts:114`):

```ts
    } catch (parseError) {
      console.warn('[POS][api] failed to parse error response body', {
        parseError,
        status: response.status,
        url: fullUrl,
      });
    }
```

## 6. Targeted Fix Candidates

### Fix 1: Preserve and display non-Error checkout messages

- Target: `paymentStore.ts` catch blocks at `apps/pos/src/stores/paymentStore.ts:345`, `apps/pos/src/stores/paymentStore.ts:413`, `apps/pos/src/stores/paymentStore.ts:472`.
- Change: replace `error instanceof Error ? error.message : checkoutFailed` with a normalizer that handles strings/plain objects, and log structured details before state mutation.
- Why: converts the current generic `Échec du paiement` into `database is locked`, `constraint failed`, etc.
- Effort: 15-30 minutes.

### Fix 2: Make local receipt transactions rollback-safe and diagnose SQLite failures

- Target: `createOfflineReceipt()` transaction catch at `apps/pos/src/lib/offline/receiptService.ts:460`.
- Change: log receipt context, wrap `ROLLBACK` in its own try/catch, preserve the original error when rollback fails.
- Why: today a rollback failure can replace the original cause and leave a transaction dangling.
- Effort: 30-45 minutes.

### Fix 3: Repair payment config sync endpoint + refresh in-memory store from SQLite after sync

- Target: `pullPaymentConfig()` currently calls `/treasury/payment-methods` and `/treasury/payment-repositories` (`apps/pos/src/lib/sync/syncService.ts:499`), while Laravel registers `/payment-methods` and `/payment-repositories` (`apps/api/app/Modules/Treasury/Presentation/routes.php:27`, `apps/api/app/Modules/Treasury/Presentation/routes.php:44`). `SyncScheduler` refreshes products from SQLite after sync but not payment config (`apps/pos/src/lib/sync/syncScheduler.ts:75`).
- Change: align endpoint paths and add a payment-store `refreshFromSQLite()` or call `fetchPaymentConfig()` after successful payment-config pull.
- Why: avoids stale local payment config when the cashier is offline or the foreground API call fails.
- Effort: 45-90 minutes.

### Fix 4: Add chain-break dead-letter/recovery state

- Target: `pushOfflineReceipts()` chain-break branch (`apps/pos/src/lib/sync/syncService.ts:292`) and `ChainBreakAlert` context (`apps/pos/src/components/atoms/ChainBreakAlert.tsx:26`).
- Change: store and display the rejected receipt number/idempotency key, not only the last synced receipt. Add an operator/admin recovery flow for failed rows beyond retry count.
- Why: the current banner points near the break, not at the break; there is no recovery path.
- Effort: 0.5-2 days, depending on server reconciliation support.

### Fix 5: Prevent rapid duplicate local receipt creation

- Target: `processCashCheckout()` before `createReceiptLocalFirst()` (`apps/pos/src/stores/paymentStore.ts:328`) and possibly `CashPaymentScreen` confirm disabling (`apps/pos/src/components/organisms/CashPaymentScreen/CashPaymentScreen.tsx:171`).
- Change: add an in-memory/local duplicate guard while a matching cart receipt is pending/syncing or created within a short window.
- Why: sync idempotency protects only retries with the same idempotency key. Re-running checkout creates a new key (`apps/pos/src/lib/offline/receiptService.ts:312`).
- Effort: 1-3 hours for a minimal guard, longer for robust cart-hash persistence.

## 7. Reconciliation Note

Versus `2026-04-30-pos-checkout-failure-fix.md`:

- Agree: the first hotfix should be observability in checkout and offline receipt creation.
- Agree: SQLite insert/write failure is a top candidate.
- Disagree with the plan's initial ranking: missing `terminal_state`, empty payment methods, empty repositories, and missing operator would throw specific real `Error`s and are weaker matches for the exact `Échec du paiement` banner.
- Extend: payment config sync endpoint drift is concrete in current code and should be fixed even if it is not the exact generic-banner cause.

Versus the Opus checkout audit:

- Agree: the console silence is confirmed and chain-break is push-time/server-detected.
- Agree: chain-break is not directly thrown by cash checkout because checkout is local-first.
- Refine: `insertOfflineReceipt()` does not itself call `scheduleDebouncedSync()` before commit in a separate connection; it is invoked inside the same outer transaction and schedules the timer after the insert call returns (`apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:95`). The duplicate `triggerSync()` in `createReceiptLocalFirst()` still means sync can begin quickly after local creation (`apps/pos/src/stores/paymentStore.ts:225`).

Versus the sync deep-dives:

- Agree: `/pos/receipts/sync` idempotency is sound for the same key, but UI retries create new keys.
- Agree: stuck `syncing` rows and missing read timeout are P0/P1 sync reliability risks.
- Converge: chain-break and checkout failure should be handled as linked operational incidents but separate immediate causes: one local SQLite/checkout path, one background sync/server rejection path.

Open questions for the human/operator:

- What exact console output appears after the observability hotfix on one repro?
- Does the local `offline_receipts` table contain `failed` or `syncing` rows for this terminal, and what are their `sync_error`, `retry_count`, `hash_sequence`, `previous_hash`, and `fiscal_hash`?
- Does `sync_log` show 404s for `/treasury/payment-methods` / `/treasury/payment-repositories`?
- Was the chain-break banner acknowledged by the cashier, or is the thin variant coming from a previous session state in the same running app?
