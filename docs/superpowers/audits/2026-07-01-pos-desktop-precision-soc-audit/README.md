# Desktop POS (`apps/pos`) — Precision & SoC Audit

**Date:** 2026-07-01
**Scope:** The Tauri 2 desktop POS — `apps/pos` (347 TS/TSX + 54 Rust files) — the **production source-of-truth** (the `apps/web` POS new-sale flow is retired). This is the first audit of `apps/pos`; the 2026-06-24 hexagonal/SoC audit only ever scanned `apps/api` + `apps/web`.
**Method:** 3 parallel read-only auditors against `dev` `f6793753d` — (1) money/quantity float precision, (2) frontend SoC, (3) Rust + SQLite + device fiscal contracts.
**Trigger:** porting the web P0-5 fix (`AdvancedPaymentsModal` float sum) revealed the desktop app has its **own** copy of that modal — and its on-device money math had never been precision-audited.

---

## 1. Executive summary

The **fiscal storage and integrity layers are architecturally sound** — but the **TypeScript computation layer carries a real float-on-money debt** that the sound storage cannot save you from.

- ✅ **SQLite money columns are `TEXT`** (decimal strings). The old `REAL` terminal `cumulative_*` fields were already converted by **migration v21** (CAST REAL→TEXT). Pre-v21 devices may carry drifted values → one-time reconciliation caveat for any TND-launched terminal.
- ✅ **Rust does zero money arithmetic** — it's I/O only (printing, AES-GCM, binding); all money math is in TS via **big.js**. No `f64` fiscal math.
- ✅ **Fiscal-event hash inputs are canonical strings** (`bcformat` scale-3, recursively normalized) → SHA-256 chain is deterministic.
- ✅ **Rule-20 cross-layer contracts implemented** — `sqliteTime.ts` `toSqliteUtc()` normalizes JS boundaries; device-minted `fiscal_shift_id` preserved via nullish-merge.
- 🔴 **~12 candidate P0 sites compute money with native float** (`parseFloat`/`Number(`/`+`/`reduce`) *before* the value reaches those sound `TEXT`/string boundaries. So a drifted number is `.toFixed()`'d and **persisted into a TEXT column and hashed** — the form is right, the number can be wrong. Several also drive **fiscal gates** (fully-paid / valid-tender) via float comparison.

**Bottom line:** this is the **same class as the web P0 sweep**, but on the SoT and reaching device-authored fiscal receipts — and it slots in cleanly because storage already expects decimal strings. The fix is "stop computing money in float in the TS layer," not a storage/Rust/hash rework.

> The "~12 P0" count is the auditor's first pass; as in the web sweep, each site gets per-site TDD validation during the fix and some may right-size to P1. The **class** is confirmed real (the `AdvancedPaymentsModal`, `CashPaymentScreen`, `cartStore` discount, and `holdStore` round-trip are unambiguous).

---

## 2. Precision — float-on-money (the fiscal-risk axis)

Correct tool present but bypassed: `apps/pos/src/lib/decimal.ts` (`bcadd/bcsub/bcmul/bcdiv/bccomp/bcsum/bcformat`, big.js-backed). Money/qty must flow as decimal **strings** through these.

### Candidate P0 — float reaches a persisted / hashed / gate value
| # | Site | What it corrupts |
|---|---|---|
| D0-1 | `components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx:238,246,252` | `voucherTenders.reduce(...parseFloat...)` + `paymentLines.reduce((s,l)=>s+l.amount,0)` → `totalPaid` → the **"fully paid" gate** (`>= total`) and change-due. **This is the direct P0-5 analog. PORT NEEDED.** |
| D0-2 | `components/organisms/CashPaymentScreen/CashPaymentScreen.tsx:45-47,74` | `parseFloat(tendered)`; float `changeDue`; float `>= total` valid-tender gate; float passed to `onConfirm` → `processCashCheckout` → persisted `pos_receipt_payments.amount` via `.toFixed()` |
| D0-3 | `stores/cartStore.ts:472-487` | discount + line-total via `parseFloat(unit_price)*qty` and `(gross*parseFloat(value))/100`, persisted via `.toFixed()` — every discounted sale's lines carry IEEE-754 jitter |
| D0-4 | `stores/paymentStore.ts:1113-1117` | tender-sufficiency gate `tenderedAmount + 0.000001 < totalEstimate` (float + arbitrary epsilon) |
| D0-5 | `stores/holdStore.ts:61-62` | held-sale totals `parseFloat(row.total).toString()` on SQLite round-trip → parked sales drift on recall |
| D0-6 | `stores/paymentStore.ts:433,447,1113` | `Number(subtotal)` / `Number(total)` / `Number(tenderedAmountStr)` boundary conversions feeding gates/returns — validate each (some may be legitimate display, some feed fiscal) |

Plus ~6 P1 (displayed/aggregate totals & audit-trail payloads carrying float). Full table: `01-precision-money-float.md`.

### The fix pattern (identical to the web P0 sweep)
Convert each site to big.js/`bcsum`/`bccomp` on decimal **strings**; change payment-line `amount: number → string` where it ripples; replace float gates (`>=`, `+epsilon <`) with `bccomp(...) <op> 0`. The persisted/hash boundary already takes strings, so no storage change.

---

## 3. Frontend SoC — mostly clean

| Dimension | Result |
|---|---|
| Raw API bypass (components calling fetch/invoke directly) | **0** ✅ |
| Untranslated strings (missing `t()`) | **0** ✅ (246 `t()` imports) |
| `any` in non-test src | **0** ✅ |
| Double-unwrap / API envelope bugs | none ✅ |
| **Fiscal logic in components** | **0** — all in `lib/fiscal/*` ✅ |
| God components | `HomePage.tsx` 1724, `paymentStore.ts` 1537, `lib/FiscalEventEngine.ts` 3636, `terminalStore.ts` 1016, `AdvancedPaymentsModal.tsx` 918 (MEDIUM) |
| Design-token adoption | **0.4%** (6 token uses vs ~1473 hardcoded color classes; worst: ZReportModal 55, AdvancedPaymentsModal 54) — HIGH volume, LOW risk (mechanical, like web P3-1) |

SoC is in good shape — the only debt is god-component size and design-token decay, both low-correctness-risk. Detail: `02-frontend-soc.md`.

---

## 4. Rust / SQLite / device fiscal contracts — sound

Detail: `03-rust-sqlite-fiscal.md`. Highlights:
- **Money columns `TEXT`** across `offline_receipts`, `offline_cash_drawer_ops`, `held_transactions`, balances. v21 migration fixed the old `REAL` `cumulative_*` terminal fields.
- **Rust:** no fiscal float; money never arithmetic'd in Rust. `db_writer.rs` binds JSON numbers as f64 but SQLite `TEXT` affinity stores them as text (no loss) — though upstream TS float is still the issue (§2).
- **Hashes:** receipt + Z-report normalize all monetary fields to scale-3 strings via big.js before SHA-256. Canonical-bytes column (v37) for round-trip audit.
- **P1 residuals:** `operator_pins.max_discount_percent` still `REAL` (percent cache, not fiscal arithmetic); pre-v21 `cumulative_*` backfill reconciliation for TND terminals.

---

## 5. Recommended sequencing

1. **D0-1 (P0-5 port) — done in this branch.** Port the web `AdvancedPaymentsModal` precision fix to `apps/pos`'s own modal (float sums → `bcsum`, fully-paid gate → `bccomp`), TDD'd.
2. **Desktop precision sweep (D0-2…D0-6 + P1 validate).** The same subagent-driven TDD sweep used for the web/api P0s — convert the remaining float-on-money TS sites, one task + review per site, regression test each. Higher fiscal stakes than web (this is the SoT authoring receipts), so worth doing before broad launch.
3. **P1 hygiene:** validate/convert the displayed-aggregate float sites; convert `operator_pins.max_discount_percent` if it ever feeds arithmetic; reconcile pre-v21 TND `cumulative_*`.
4. **SoC (post-launch, low risk):** design-token migration (ESLint ratchet like web) + split the worst god-components.
5. **Lock it:** add the precision lint guards (the `no-parsefloat-on-money` ESLint rule from web, ported to `apps/pos`) so new float-on-money fails CI.

---

## 6. Appendix
- `01-precision-money-float.md` — full P0/P1 float-on-money table + the D0-1 port scope
- `02-frontend-soc.md` — SoC metrics + god-component/token detail
- `03-rust-sqlite-fiscal.md` — SQLite money-column table, Rust handling, hash integrity, Rule-20 contracts
