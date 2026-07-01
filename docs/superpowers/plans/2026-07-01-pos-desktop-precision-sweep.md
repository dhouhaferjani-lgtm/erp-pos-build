# Desktop POS Precision Sweep (D0-2…D0-6 + guard) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development, one task at a time, per-task review. Steps use `- [ ]`.

**Goal:** Eliminate every remaining float-on-money site in the Tauri SoT POS (`apps/pos`) TS layer flagged P0/P1 by the 2026-07-01 audit, converting each to decimal-string bcmath, with a discriminating test per fix, then lock it with an ESLint guard. (D0-1, the AdvancedPaymentsModal tender-state, is already done — commits `755cff86f`+`9845ae7f2`.)

**Architecture:** The correct tool exists and is bypassed: `apps/pos/src/lib/decimal.ts` (`bcadd, bcsub, bcmul, bcdiv, bccomp, bcsum, bcformat`, big.js). Storage (`TEXT` cols), Rust, and fiscal hashes already take decimal strings — so the fix is purely "stop computing money in float in TS," no storage/Rust change. Money/qty flow as decimal STRINGS; gates use `bccomp(...) <op> 0`.

**Tech Stack:** React 19 / TS strict / Zustand; Vitest (run by path); big.js via `lib/decimal.ts`.

## Global Constraints (apply to EVERY task)
- No native `+ - * / < > >= <=` on money/qty; no `parseFloat`/`Number(`/`Math.max`/`Math.min`/`.toFixed()` producing a persisted money value. Use `bcadd/bcsub/bcmul/bcdiv/bcsum` + `bccomp`.
- Percent/discount rate is a rate — `bcdiv(x,'100',scale)`, not currency-scaled itself.
- Currency scale: use the file's existing `getCurrencyDecimals(currency)` / `decimals` source (do NOT hardcode; TND=3, EUR=2).
- A `(float)`→`String()` boundary is only acceptable at a pure DISPLAY edge on an already-bc-computed value; never mid-fiscal-calc. Mark any such edge with a comment.
- **Run tests BY PATH only** — `cd apps/pos && pnpm vitest run <path>` — never the whole suite. Then `pnpm typecheck` (or `pnpm exec tsc --noEmit`). Report exact commands + output.
- Commit per task. Co-author trailer: `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.
- Scope discipline: touch only the task's sites; note adjacent drift, don't fix it.

**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.pos-audit` on `fix/pos-precision-port`. Paths under `apps/pos/`.

---

## Task S1 — D0-2: Cash tendered path (CashPaymentScreen + CashTenderedModal + thread as string)
**Files:** `src/components/organisms/CashPaymentScreen/CashPaymentScreen.tsx` (~45-47,74), `src/components/pos/CashTenderedModal.tsx` (inspect — same pattern), `src/stores/paymentStore.ts` (`processCashCheckout` ~933 `tenderedAmount.toFixed`), `src/lib/offline/receiptService.ts` (~335 `String(input.tenderedAmount)`). Tests: colocated + a paymentStore test.
**Fix:** keep `tenderedStr` a string; `changeDue = bccomp(tenderedStr,totalStr)>0 ? bcsub(tenderedStr,totalStr,decimals) : bcformat('0',decimals)`; valid-tender gate `bccomp(tenderedStr,totalStr) >= 0`; `onConfirm(tenderedStr)` passes the STRING; `processCashCheckout(tenderedAmount: string)` (drop `.toFixed()` on a float — it's already a scaled string, `bcformat` if needed); `receiptService` receives a string (drop the `String()` wrap on a float). Thread the type change through the callback signatures.
**Test:** exact-tender + change-due case that float-drifts (e.g. `100.10` tendered, `99.80` total → change `0.30`, not `0.30000000000000027`); assert exact strings + the valid gate.
- [ ] failing test → run (fail) → fix → run (pass) → `pnpm typecheck` → commit `fix(pos): cash tendered path via bcmath strings, not float (D0-2)`

## Task S2 — D0-3: Cart line discounts
**Files:** `src/stores/cartStore.ts` `applyLineDiscount` (~472-488), `removeLineDiscount` (~516-524). Test: `cartStore` test.
**Fix:** `grossTotal = bcmul(item.unit_price, String(item.quantity), decimals)` (quantity is a count — confirm its type; if decimal qty, use its string); percentage `discountAmount = bcdiv(bcmul(grossTotal, input.value, decimals+1), '100', decimals)`; fixed `discountAmount = bcformat(input.value, decimals)`; `lineTotal = bccomp(bcsub(grossTotal,discountAmount,decimals),'0')<0 ? bcformat('0',decimals) : bcsub(grossTotal,discountAmount,decimals)`. Persist those strings (no `.toFixed()` on a float). Feed the bcmath `lineTotal` into `computeTaxAmount`.
**Test:** a percentage discount that float-drifts (e.g. `10.00 × qty 3` gross `30.000`, `10.05%` → exact bcmath vs float); assert persisted `discount_amount`/`line_total` exact strings.
- [ ] failing → fail → fix → pass → typecheck → commit `fix(pos): cart line discount/total via bcmath, not float (D0-3)`

## Task S3 — D0-4: paymentStore gates (estimateCartTotal + tender-tolerance)
**Files:** `src/stores/paymentStore.ts` `estimateCartTotal` (~423-447 — the `Number(subtotal)`/`parseFloat(discountValue)`/`Number(total)` returns) and the tender-tolerance gate (~1113-1117 `Number(tenderedAmountStr)` + `+ 0.000001 < totalEstimate`). Test: paymentStore test.
**Fix:** make `estimateCartTotal` return a decimal **string** (rename callers accordingly, or add `estimateCartTotalString` and switch the gate to it); `discountValue` compare via `bccomp(transactionDiscount.value,'0')`; drop the float epsilon — gate becomes `bccomp(tenderedAmountStr, totalEstimateStr) < 0` (strictly-less = needs override; exact-equal is sufficient). Keep `tenderedAmountStr` a string (drop `Number(...)`).
**Test:** a tender exactly equal to total at TND scale-3 that the old `+0.000001` epsilon would mis-gate; assert no override required at exact tender, override required at 1-cent short.
- [ ] failing → fail → fix → pass → typecheck → commit `fix(pos): paymentStore tender gates via bccomp strings, drop float epsilon (D0-4)`

## Task S4 — PaymentLineItem.amount: number → string (the modal ripple)
**Files:** `src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx` (~355 `parseFloat(amount)`, ~372 `amount: parsedAmount`, the `PaymentLineItem` type + everywhere `l.amount` is used), `src/stores/paymentStore.ts` (`enriched.map(e=>e.amount)` ~1112 and any `line.amount` consumers). Tests: modal + paymentStore.
**Fix:** change `PaymentLineItem.amount` to `string`; validate input via `bccomp` (`amount` string, reject `bccomp(amount,'0')<=0` or non-numeric) instead of `parseFloat`+isNaN; store the raw decimal string; `computeTenderState` then no longer needs `String(l.amount)` (already a string). Update every `l.amount` consumer to treat it as a string.
**Test:** a payment line whose numeric value would float-drift if parsed; assert the stored `amount` is the exact input string and `computeTenderState` sums it exactly.
- [ ] failing → fail → fix → pass → typecheck → commit `fix(pos): PaymentLineItem.amount is a decimal string end-to-end (D0-6/modal ripple)`

## Task S5 — D0-5: holdStore SQLite round-trip
**Files:** `src/stores/holdStore.ts` (`rowToHeldTransaction` ~61-62 `parseFloat`, persist ~126-127 `.toString()`, `HeldTransaction` interface `subtotal`/`total` types, audit emit ~162). Consumers of `HeldTransaction.subtotal`/`.total`. Test: holdStore test (SQLite round-trip).
**Fix:** `HeldTransaction.subtotal`/`total` become `string`; read `subtotal: row.subtotal` (no parseFloat); persist the string directly (no `.toString()` on a float); update consumers to strings. Round-trip must be identity: `sqlText → HeldTransaction → sqlText` unchanged.
**Test:** persist a held txn with `total: '20.30'`, recall it, assert `total === '20.30'` (not `'20.3'`/drift); many-line case.
- [ ] failing → fail → fix → pass → typecheck → commit `fix(pos): held-transaction totals stay decimal strings across SQLite round-trip (D0-5)`

## Task S6 — P1 boundaries: cartStore getters + refundDraft + dashboard
**Files:** `src/stores/cartStore.ts` display getters (~613-674 `Number(...)` returns), `src/stores/refundDraftStore.ts` (~158 float sum), `src/components/pos/TodaySalesPanel.tsx` (~82-83 float dashboard sums). Tests: as available.
**Fix:** for each, decide: if the value feeds another calc/gate → return/keep a string (bcsum/bccomp); if it's a pure display leaf → keep the `Number()` but ONLY at the render boundary with a comment, and ensure nothing fiscal consumes it. `refundDraftTotal` → `bcsum(returnItems.map(i => bcabs(i.line_total)), decimals)` returning a string. Dashboard sums → `bcsum` strings, format for display.
**Test:** refundDraftTotal exact-string test; a cartStore getter consumer that must not drift.
- [ ] failing → fail → fix → pass → typecheck → commit `fix(pos): P1 display/audit money sums via bcmath (D0-P1)`

## Task S7 — Lock: port the ESLint no-parsefloat-on-money guard + slice preflight
**Files:** the web ESLint rule `no-parsefloat-on-money` (find in `apps/web` eslint config/plugins) ported to `apps/pos`'s ESLint config; `apps/pos/.eslintrc`/`eslint.config`. 
- [ ] Locate the web `no-parsefloat-on-money` (and `no-hardcoded-step`) rule; wire it into apps/pos ESLint as an error over `src/` (excluding tests if the web one does).
- [ ] Run `pnpm lint` (or eslint by path) over the touched files — fix any straggler it flags that this sweep should have caught; if it flags out-of-scope legacy sites, baseline/document them (don't silently expand).
- [ ] Slice preflight (touched files only, NEVER whole suite): `pnpm exec tsc --noEmit`; re-run each task's vitest path; `pnpm lint` on touched files.
- [ ] Update `docs/superpowers/audits/2026-07-01-pos-desktop-precision-soc-audit/README.md` §5 marking D0-2…D0-6 fixed with commit shas.
- [ ] commit `test(pos): lock no-parsefloat-on-money guard + verify desktop precision sweep`

---

## Notes
- Sequential only (tasks share `paymentStore.ts`/`cartStore.ts`) — one implementer at a time, review between.
- After S1–S7 + reviews clean → final whole-branch review (opus) over `dev..HEAD` (audit + D0-1 + sweep), then merge the bundle to dev as a clean ff.
