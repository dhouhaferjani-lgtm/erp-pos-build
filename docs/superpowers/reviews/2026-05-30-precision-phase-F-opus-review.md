# Precision Phase 10 (+0.7) frontend — Opus + Codex review + reconciliation

Branch `feat/precision-phase-f` vs `origin/dev`.

## Opus verdict: APPROVE WITH CHANGES — addressed
- **P1 DocumentLineEditor/Row migration cosmetic** (parseFloat→number; client line-total still IEEE-754): NOT a new regression (was already float); the backend authoritatively recomputes invoice line totals. Documented as a known follow-up (full string pipeline for the document line editor) — not blocking; backend is the source of truth.
- **P2 PriceListDetailPage default `?? 'EUR'`→ reverted to `?? 'TND'`** (TND-default codebase).
- **P2 VoucherTenderModal min floor**: zero tender already hard-blocked in `handleApply` (`<= 0` → invalid); restored a currency-correct native floor `min = 1/10**decimals`.
- **P2 CreateCreditNoteForm + PaymentForm register+value mix**: refactored to `Controller` (matches loyalty forms).
- **P2 CreateCreditNoteForm dropped `.toFixed(4)`**: backend ingress normalizes (Phase 4 numeric+regex); display uses currency-aware toFixed. OK.
- Verified clean by Opus: percent-vs-money conditionals, the atoms' string passthrough + stateful test harness, F-FRONTEND-RETURN scale-4 payload, catalog DTOs→string, 10.5 display fixes.

## Codex verdict (POS 10.6/10.7 fiscal): mixed — reconciled
- **Big.RM = 1 (half-up) vs server truncation:** This is PRE-EXISTING (`decimal.ts:14`, unchanged by Phase 10 — the diff only added bcsum/bcabs). The device has always used these helpers for the fiscal payload (buildReceiptData) and the canonical-parity / hash golden tests pass unchanged (F-crit verified). Phase 10.7 moved store math onto the SAME helpers — no NEW divergence introduced. The half-up-vs-truncate question is a real LATENT item flagged for a future COORDINATED server+device rounding alignment (out of scope; would change device behavior + needs server agreement). NOT a Phase-10 regression.
- **paymentStore override `totalEstimate.toFixed(decimals)` (float):** FIXED → `bcformat(String(totalEstimate), decimals)` (Big.js, consistent with the file). The hashed override `target.total_amount` no longer crosses a raw-JS-float boundary.
- **Quantity forced to money scale in cart bcmul:** the cart store is DISPLAY/intermediate; the canonical fiscal payload is produced by `buildReceiptData` (unchanged canonicalization; parity tests green). Quantities elsewhere kept at scale 4. No NEW divergence.
- `formatCashAmount` currency-aware: CLEAN (TND stays 3, EUR→2; correct).

## Test regressions (caught by full vitest checkpoint) — FIXED
- web DocumentTenantScope (2) + PaymentForm.tenantScope (3): stale full `vi.mock('@/hooks/useCurrency')` that dropped the now-used `getDecimals` export → render crash. Migration was correct; mocks switched to `importOriginal` partial mocks.
- pos VoucherTenderModal: new `MoneyInput` import chain reached `i18n.ts`; added a `@/lib/currency` mock to break it. (The 2 `migrations.v37` SQLite failures are pre-existing, untouched.)
- CreateCreditNoteForm test: TND credit note shows 3dp (currency-aware) — assertions aligned.

Verification: previously-failing web (5) + pos (VoucherTenderModal 19) tests pass; full pos store suite 311/311; web+pos typecheck clean; lint ratchet PASS (11342). dev backend (A–E) green via suite_e (1 known TenantCreationTest).

**Final verdict: APPROVE** (BLOCKER: none; P1 documented/backend-authoritative; P2 + Codex fiscal-float fixed; Big.RM pre-existing & flagged).
