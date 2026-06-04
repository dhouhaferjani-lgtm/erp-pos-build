/**
 * Typed catalog of POS audit / fraud-detection event types (Sub-Spec C).
 *
 * Source of truth: `docs/superpowers/specs/2026-06-04-pos-fraud-event-taxonomy.md`.
 *
 * This union lists every WIRED P0/P1/P2 event — i.e. every taxonomy event whose
 * emit-point action exists in the codebase. It deliberately EXCLUDES:
 *
 *   - The two DEFERRED events (no action exists; need a new product surface):
 *       `pos.no_sale_drawer_open`, `pos.refund_no_original_receipt`.
 *   - `pos.operator_locked` (P2) — COLLAPSED into `pos.screen_lock`, which
 *     carries `{ reason, idle_ms }`. The catalog lists both for completeness;
 *     the spec implements one event (avoids double-emit).
 *
 * Adding a new emit point? Add its `pos.*` type here first so `recordAuditEvent`
 * callers stay type-checked against the canonical catalog.
 */
export type PosAuditEventType =
  // ── P0 — auth / session ───────────────────────────────────────────────
  | 'pos.login'
  | 'pos.operator_signin'
  | 'pos.operator_signoff'
  | 'pos.screen_lock'
  | 'pos.device_unbind'
  | 'pos.terminal_change'
  // ── P1 — cart / sale lifecycle ────────────────────────────────────────
  | 'pos.cart_discarded'
  | 'pos.cart_line_removed'
  | 'pos.cart_quantity_updated'
  | 'pos.sale_held'
  | 'pos.sale_recalled'
  | 'pos.sale_hold_discarded'
  // ── P1 — discounts / overrides ────────────────────────────────────────
  | 'pos.line_discount_applied'
  | 'pos.transaction_discount_applied'
  | 'pos.manager_override_denied'
  | 'pos.manager_pin_failed'
  // ── P1 — connectivity / fiscal integrity ──────────────────────────────
  | 'pos.went_offline'
  | 'pos.went_online'
  | 'pos.fiscal_chain_break'
  | 'pos.sync_failed_orphaned'
  // ── P2 — refund drafts / vouchers / customers ─────────────────────────
  | 'pos.refund_draft_created'
  | 'pos.refund_draft_discarded'
  | 'pos.voucher_double_spend_attempt'
  | 'pos.customer_attached'
  | 'pos.customer_detached'
  // ── P2 — operator unlock detail ───────────────────────────────────────
  | 'pos.operator_unlocked';
