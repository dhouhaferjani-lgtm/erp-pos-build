# HANDOVER — POS Returns / Voids / Refunds + Disposition → fold into the POS fiscal-chain orchestrator

**Date:** 2026-06-29
**From:** return-disposition design session
**Branch:** `feat/pos-return-disposition` (worktree `../erp.pos-refund`, off `origin/dev`), HEAD `4134803a8` — **design + adversarial reviews only, no code yet**
**Purpose:** the owner wants **ONE orchestrator session for everything POS** (returns/voids/refunds, disposition, the fiscal-event migration, and caisse-redesign coordination) to avoid cross-session conflicts and ship a clean version. This hands that session a verified design + the conflict map.

---

## Read in this order
1. **Spec (this effort):** [`docs/superpowers/specs/2026-06-29-pos-return-disposition-design.md`](../superpowers/specs/2026-06-29-pos-return-disposition-design.md) — disposition model + the refund fiscal-chain reconciliation, phased, with both reviews folded.
2. **Adversarial reviews (folded):** [Codex](../superpowers/reviews/2026-06-29-pos-return-disposition-codex-review.md) · [Opus](../superpowers/reviews/2026-06-29-pos-return-disposition-opus-review.md). Opus verdict: **design sound, Phase 0 plannable** with 8 edits (all folded).
3. **Prior canonical refund/exchange/voucher design (the baseline this builds on):** [`docs/superpowers/specs/2026-04-28-pos-refund-flow-design.md`](../superpowers/specs/2026-04-28-pos-refund-flow-design.md) — "one UX, two fiscal documents," vouchers, exchange (`exchange_group_id`), proration, QR lookup, offline-first intent.

NF525 + architecture + disposition-workflow research is summarized in spec §1 (full briefs were in the design session).

---

## Verified code reality (the "legacy" clarification)
- **Returns/voids are LIVE but not migrated** — `ReceiptReturnService` / `ReceiptVoidService`, **server-side, online-only**, sealed onto the **legacy inline `pos_receipts` chain** (`ReceiptFinalizationService`), **not** `fiscal_events`. They are the only implementation; not deprecated.
- **Sales + the whole cash/shift lifecycle ARE device-authored `fiscal_events`** (offline-first): `SALE_RECEIPT`, `OPENING_FLOAT`, `CASH_IN/OUT`, `SAFE_DROP`, `X_REPORT`, `Z_REPORT`, `SESSION_OPEN/CLOSE`.
- **Refund fiscal model is already code-committed:** `SALE_RECEIPT` + `invoice_type_code='REFUND'|'VOID'` + `original_receipt_reference` (the `FiscalPayloadConstraintValidator` says so verbatim; device payload + projection already implement it). The reserved `REFUND_RECEIPT`/`SALE_VOID`/`PARTIAL_REFUND` enum cases are **vestigial**.
- **LIVE LATENT HAZARD** (spec §5.1): `PosCoreReceiptProjection` maps `invoice_type_code=REFUND/VOID` to a Return receipt but calls `decrementStockForLines()` **unconditionally** — a device-authored refund through the already-accepted path would **decrement** stock. The device doesn't author refunds today (latent), but it is a landmine for Phase 3.
- **Offline-return root cause:** the "Retours / Échange" lookup queries the local `receipt_qr_index`, which is **server-pull-only** → offline (or pre-sync) returns find nothing ("Aucun reçu trouvé"). Settlement is also online-only.

---

## Locked decisions
- **Disposition:** `RESTOCK | SCRAP | NOT_RECEIVED` now; `QUARANTINE | DESTROY | RETURN_TO_VENDOR` target (back-office). Two orthogonal facts (`physical_receipt` × `resalable`). **Two-movement scrap, quantity-only** in Phase 0 (no WAC re-blend). `NOT_RECEIVED` = zero movements. SCRAP skips batch restitution.
- **`restock_policy` hierarchy:** nullable on product → category (parent tree) → tenant default (`Company.reservation_settings`, seeded vertical-aware). Resolver mirrors the margin-override-hierarchy pattern.
- **`never ⇒ no RESTOCK` backend guard ships in Phase 0** (regulated-goods floor — the launch is parapharmacy).
- **Disposition is outside the signed fiscal perimeter in Phase 0** (operational inventory fact; not in the hash).

## OPEN — needs owner / orchestrator ratification
1. **Refund fiscal model (spec §5):** confirm we standardize on the **`invoice_type_code` axis** and formally retire the separate `REFUND_RECEIPT`/`SALE_VOID` event-type idea. *Recommended* (it is built, validated, projected). This **reverses** an earlier "two distinct event types" lean — flagged because it changes the Phase-3 shape (extend the `SALE_RECEIPT` refund payload with a refunded-line subset + per-line disposition, rather than new event types).
2. **Return cost/WAC + GL:** Phase 0 is quantity-only; decide when returns-shrinkage / write-off valuation wires into GL (accounting-gl roadmap).

---

## Phasing (each = its own implementation plan, TDD)
- **Phase 0 (READY TO PLAN NOW):** `ReturnLineDisposition` enum + facts + input contract on the **legacy `ReceiptReturnService` path**; quantity-only two-movement SCRAP (batch-skip) / `NOT_RECEIVED` no-op / `RESTOCK` unchanged; `RestockPolicy` enum + columns + resolver; **`never` guard**; **projection-decrement guard fast-follow** (§5.1). Additive, default `RESTOCK`.
- **Phase 1:** refund-modal disposition selector (`apps/pos`) — **coordinate with `pos-caisse-redesign`**; wires resolver UI defaults + `if_sealed` routing.
- **Phase 2:** `QUARANTINE/DESTROY/RETURN_TO_VENDOR` + non-sellable location + back-office reclassification (transition table) + lot-aware destruction.
- **Phase 3:** extend canonical `SALE_RECEIPT` refund payload (line subset + per-line disposition) + **disposition-gated projection** (fix §5.1) + **offline lookup fix** (write local index on local sale authoring).
- **Phase 4:** cash reconciliation (refund cash → fiscal cash path) + retire legacy return/void services (dual-arm coexistence; no chain rewrite).

---

## Conflict map (why one orchestrator)
- **`feat/pos-caisse-redesign`** (`../erp.pos-caisse`) — owns the refund-modal UI surface → Phase 1 coordination.
- **`feat/parapharmacy-merchandising`** (`../erp.parapharm`) — `restock_policy` defaults touch product/category; coordinate migrations + seeders.
- **`feat/accounting-gl-go-live`** (`../erp.accounting-gl`) — owns returns-shrinkage / write-off GL posting (Phase 0 leaves movements GL-ready, not GL-wired).
- **`feat/margin-category-override`** (`../erp.margin-hier`) — `RestockPolicyResolver` mirrors its product→category→tenant resolver + provenance pattern; **reuse, don't duplicate**.
- **Superseded `fix/pos-refund-void-restock`** (commit `bf394748d`) — blanket projection restock; **DO NOT merge standalone**. Its restock plumbing informs the Phase-3 disposition-gated projection (§5.1) but must become disposition-aware (and the model is `invoice_type_code`, not new event types).

---

## Next action for the orchestrator
1. Ratify the two OPEN decisions.
2. `writing-plans` for **Phase 0**, then execute via subagent-driven TDD on this branch (or rebase/fold into the orchestrator's own branch).
3. Keep all POS return/void/refund/disposition/fiscal-event work under this one orchestrator to prevent the cross-branch conflicts this handoff is meant to avoid.
