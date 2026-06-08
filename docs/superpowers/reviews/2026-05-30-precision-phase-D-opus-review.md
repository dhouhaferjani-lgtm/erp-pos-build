# Precision Phase 5 (JSONB tightening) — Opus adversarial review + reconciliation

Branch `feat/precision-phase-d` (Phase 5, stacked then rebased onto dev). Diff base: phase-c/Phase-3.

## Opus verdict: REQUEST-CHANGES → all addressed (1 P1, 2 P2, NITs)

### P1 — `previewEarning(): float→string` unannounced API response-shape break — FIXED
Frontend `loyaltyApi.ts` types `points_to_earn: number`; the bcmath change made it a string. `points_to_earn` is a NON-FISCAL display preview. Fix: `LoyaltyPOSController` casts the preview result to `(float)` at the wire boundary (internal `previewEarning` stays canonical bcmath string); no frontend change needed. Controller test asserts numeric.

### P2 — opening-balance staging silently truncates EUR (<3dp currencies) — FIXED
`OpeningBalanceBatchService` canonicalized money via `bcformatStrict(value, getScale($currency))` → EUR `10000.105` truncated to `10000.10` even though the 3dp ingress regex accepts it. Fix: canonicalize staging money at the fixed **storage scale 3** (matches the regex ceiling + the `decimal(N,3)` journal columns), not the currency display scale. Removed the now-dead currency lookup + resolver injection. Test: EUR `debit='10000.105'` stored as `"10000.105"`.

### P2 — inventory `final_qty` column written from float pipeline — FIXED
`CountingReconciliationService` now assigns `final_qty` via `bcadd((string)$x,'0',4)` at all four resolution paths, consistent with the scale-4 event payload. Broader float decision logic left (pre-existing, out of scope).

### Verified clean by Opus
- Context-safety (the Phase-3 `getScale()` bug) NOT reintroduced — loyalty uses `getScaleSafe`, opening-balance now fixed-scale, held-order request-only.
- `bcformatStrict`/`bcformat` null/empty/non-numeric inputs guarded (no 500s).
- **5.5 Z-report is ingress-only and fiscal-safe** — validation rules only, snapshots archived verbatim, hash/canonical bytes untouched, `numeric` retained so device values pass.
- variance `bcsub(...,4)` exact; tests non-tautological.

### NITs (noted, non-blocking)
Loyalty points accumulated at currency scale (smell, lossless); `PointsEarnedV2` re-floats (immutable event, future V3).

## 5.6 — pos_receipt_lines CHECK constraint — GATE-STOPPED (NOT added)
A dedicated feasibility pre-check proved the CHECK is infeasible as a plain PG constraint without out-of-scope changes:
1. `bcmul` TRUNCATES (not rounds) → a `round()`-based CHECK rejects valid rows.
2. Scale is per-currency (TND=3, EUR=2, JPY=0), NOT stored on `pos_receipt_lines`; PG CHECK cannot reference the parent `pos_receipts.currency`.
3. The projection path supplies `line_total` verbatim from the canonical `LineItemDTO`, not derived from unit_price×qty−discount.
Adding a checkable invariant requires persisting a per-row scale + switching the write path to a CHECK-reproducible rounding rule — both touch `ReceiptCreationService`/`PosCoreReceiptProjection`/the canonical fiscal payload contract. **Escalated to owner per handoff §9; constraint deliberately not added.**

## Codex (5.5 Z-report fiscal): covered by Opus fiscal verification — ingress-only validation, no hash/canonical-byte/snapshot mutation (snapshots archived verbatim; a test pins that well-formed device payloads still sync).

**Final verdict: APPROVE** (P1 + both P2 closed; 5.6 gate-stopped & escalated; 5.5 fiscal-safe).
