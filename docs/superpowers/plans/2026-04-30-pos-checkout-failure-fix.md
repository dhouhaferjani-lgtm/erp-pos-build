# POS — "Échec du paiement" + Fiscal Chain Interrupted Diagnosis & Fix

**Status:** Planned, not started
**Owner:** TBD
**Severity:** P0 — cashier cannot complete a sale on the dev environment
**Estimated effort:** 1–3 hours (depends on root cause; first hour is observability + repro, then targeted fix)
**Coordination risk:** Low — touches `paymentStore.ts` catch handler, `errorHandling.ts`, and one schema-style i18n key. No overlap with refund-flow or POS performance work.

---

## Symptoms reported (2026-04-30)

1. **Home page (above cart):** thin pink banner — "Fiscal chain has been interrupted" (`ChainBreakAlert` component fires).
2. **Cash payment screen:** after Exact button + Terminer et imprimer, pink banner reads "**Échec du paiement**" — generic French translation of `errors.checkoutFailed`.
3. **Tauri devtools console:** **clean** — no JS error logged. The catch handler in `paymentStore.processCashCheckout` swallows the underlying error without surfacing it.

The two issues may be related (a chain-break could be triggering downstream checkout rejection) or independent (chain-break is a separate state issue that didn't block the user before this session).

---

## Why "no error in console" is itself a bug

`apps/pos/src/stores/paymentStore.ts:272–278`:

```ts
} catch (error) {
  set({
    isProcessing: false,
    error: error instanceof Error ? error.message : i18n.t('errors.checkoutFailed', { ns: 'pos' }),
  });
  throw error;
}
```

The fallback `errors.checkoutFailed` ("Échec du paiement") fires when:
- `error` is **not** an instance of `Error` (e.g. a thrown string, a Promise rejected with a primitive, a Tauri plugin rejection wrapped as a non-Error object), OR
- `error.message` is falsy (empty string, null).

When this fallback fires, the underlying error is **never logged**. The re-throw at the bottom of the catch propagates to `HomePage.handleCashConfirm`, which has its own try/catch that silently swallows (`// Error is stored in paymentStore`). Net effect: a real exception happens but no developer trace exists.

This is the dominant ergonomics bug — fix observability first, then the underlying cause becomes diagnosable in seconds.

---

## Investigation plan

### Step 1 — Make the failure observable (15 min)

In `paymentStore.ts:processCashCheckout` and `processCardCheckout` and `processAdvancedCheckout`, add a `console.error` at the catch site **before** any state mutation, with a structured payload:

```ts
} catch (error) {
  console.error('[POS][checkout] cash checkout failed', {
    error,
    errorType: typeof error,
    isError: error instanceof Error,
    message: error instanceof Error ? error.message : String(error),
    stack: error instanceof Error ? error.stack : undefined,
    cartItemCount: cartItems.length,
    tenderedAmount,
    terminalId,
  });
  set({
    isProcessing: false,
    error: error instanceof Error && error.message
      ? error.message
      : `${i18n.t('errors.checkoutFailed', { ns: 'pos' })}: ${error instanceof Error ? error.constructor.name : typeof error}`,
  });
  throw error;
}
```

The improved fallback message appends `: <ErrorName>` so the cashier-visible banner is at least slightly diagnostic ("Échec du paiement: TypeError" beats "Échec du paiement").

Repeat the same pattern in `createReceiptLocalFirst` (so we see whether the failure happens before reaching the API at all) and in `createOfflineReceipt` itself for the SQLite write step.

**Acceptance:** trigger the failure once with Tauri devtools open. The console now shows the actual error class, message, and stack.

### Step 2 — Reproduce + categorize (15 min)

Open Tauri devtools, hit the failure, and read the structured log entry. Most likely causes (ranked by probability based on the existing offline-first audit):

| Likely cause | Where it throws | Symptom in log | Fix path |
|---|---|---|---|
| Local SQLite missing `terminal_state` row | `createOfflineReceipt:108` `getTerminalState()` returns null | `Error: Terminal hash chain not initialized` | `seedOfflineHashChain()` didn't complete OR ran against a stale `companyId`. Re-trigger from terminal store on first checkout attempt. |
| `paymentMethods` array empty in store but DB has them | `processCashCheckout:228` `cashMethod` undefined | `Error: errors.noCashMethod` (might show key, not text) | Phase 2 of offline-first-hardening plan — `refreshFromSQLite()` after sync. |
| `paymentRepositories` array empty | `processCashCheckout:237` | `Error: errors.noCashRegister` | Same as above. |
| `operatorId` null (no PIN-verified operator AND no logged-in user) | `createReceiptLocalFirst:117` | `Error: errors.noOperatorIdentified` | Operator gating in PIN flow. |
| SQLite INSERT fails (schema drift or constraint) | inside `createOfflineReceipt` writes | `SqliteError: ...` | Schema migration audit. |
| Hash-chain regression guard (`FiscalRegressionError`) | `terminalStateRepository.upsertTerminalState` | `FiscalRegressionError: hash_sequence X < Y` | Local DB ahead of server (e.g. previous unsynced receipts) — needs reconciliation logic. **This may also be the root cause of the chain-break banner above.** |

### Step 3 — Targeted fix per cause (30 min – 2 h)

Branch on what Step 2 reveals. For the most likely cases:

**A) Terminal hash chain missing locally:**
- Add a defensive lazy-init in `processCashCheckout` before calling `createReceiptLocalFirst`: if `getTerminalState` returns null, call `seedOfflineHashChain(terminalId)` and retry once.
- File: `apps/pos/src/stores/paymentStore.ts` + import from `terminalStore`.
- Test: simulate fresh-DB scenario in unit test, assert seed is triggered + checkout proceeds.

**B) Stale paymentMethods / paymentRepositories in store:**
- This is exactly Phase 2 of `2026-04-30-pos-offline-first-hardening.md`. Implement `refreshFromSQLite()` and call it before `find()`.
- Cross-reference: this is also the recurring bug from the project memory ("No cash payment method configured").

**C) Hash-chain regression (chain-break banner is the same root cause):**
- Read the local `terminal_state.hash_sequence` and the offline receipt queue.
- If the server's `hash_sequence` is older than local (unsynced receipts), the system should preserve local — verified by the existing `FiscalRegressionError` handling in `pullTerminalState`. If a receipt push failed and corrupted the sequence, we need a recovery flow: identify which queued receipt broke the chain, surface it via an admin tool, allow it to be voided locally.
- This is a larger investigation — covered in detail in [`docs/superpowers/audits/2026-04-30-pos-offline-first-audit-codex.md`](../audits/2026-04-30-pos-offline-first-audit-codex.md) §4.

### Step 4 — Verify on dev + add regression test (30 min)

Once the fix lands:
1. Repro on the dev environment, confirm checkout completes.
2. Write a unit test in `apps/pos/src/stores/__tests__/paymentStore.test.ts` covering the failure mode (e.g. "throws meaningful error when paymentMethods is empty after rehydration" or "auto-seeds terminal_state when missing on first checkout").
3. Run `pnpm test` + `pnpm typecheck`.

### Step 5 — Push as hotfix (15 min)

`fix(pos): surface checkout failure root cause + auto-recovery for [X]`

PR `dev → main`, hold for human merge.

---

## Currency precision verification (TND 3 decimals)

Confirmed correct from code audit:
- `apps/pos/src/lib/currency.ts:11` — `CURRENCY_DECIMALS: { TND: 3, ... }` defaults to 2.
- `useCurrency()` returns `decimals` derived from active company's currency.
- All monetary writes go through `bcformat(value, decimals)` (BC math, no float drift).
- `CashPaymentScreen.handleExact` calls `total.toFixed(decimals)` so for TND a 37.700 total tenders as `"37.700"`.
- NumPad allows decimal entry up to one decimal point with no fractional-digit cap.

**No code change needed**, but add a smoke test before parapharmacy-Tunisia go-live: switch the active company to TND, run a 3-decimal Exact + Numpad + Change Due path, verify the receipt JSON stores 3 decimals end-to-end. Effort: 30 min.

**One UX consideration (not blocking):** the NumPad's decimal button is a literal "`.`", not a comma. Display formatting via `Intl.NumberFormat('fr-FR'/'fr-TN')` correctly renders "37,70" / "37,700" but the keypad button shows ".". Cosmetic, change to "," if French/Tunisian cashiers find it confusing.

---

## Acceptance

- [ ] Tauri devtools console logs the underlying error class + message + stack on every checkout failure.
- [ ] Cashier-visible banner includes at least the error class name when no message is available.
- [ ] Root cause from Step 2 is identified, fixed, and covered by a regression test.
- [ ] Cash checkout completes successfully on dev with `pharmacy@demo.local` / PharmaBio France.
- [ ] PR opened with hotfix.
