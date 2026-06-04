# POS Fraud-Detection Event Taxonomy (canonical catalog)

- **Date:** 2026-06-04
- **Purpose:** Canonical catalog of POS-CLIENT audit events to source for the fraud-detection center, implemented by Sub-Spec C (the event-sourced audit pipeline). Based on EBR / loss-prevention research + a full inventory of POS-emittable actions.
- **Scope decision (owner):** implement **P0 + P1 + P2** (all tiers below) on the C pipeline.

## Principle

The backend already audits the **happy path** (50+ domain events in `audit_events`: posted receipts, finalized payments, shift open/close, Z-reports, *approved* overrides, void/refund of *posted* receipts — see `Compliance/Listeners/DomainEventSubscriber.php`). This catalog covers the **client-side gap**: aborted / abandoned / failed / offline actions that never become a server entity — the actions fraudsters exploit. We do NOT duplicate events already captured server-side.

## Event envelope (every event)

All events share the `audit_events` envelope. The client stamps:
- `event_id` (UUID, client-generated — idempotency key = `audit_events.id`)
- `event_type` (string, the `pos.*` type below)
- `aggregate_type` + `aggregate_id` (the entity the event is about)
- `tenant_id`, `company_id`, `user_id` (= operator id, nullable)
- `payload` (event-specific, below), `metadata` (device_id, terminal_id, shift_id, is_offline, app_version)
- `occurred_at` (client wall-clock ISO-8601, the canonical business timestamp)

`event_hash` (per-row SHA-256) is computed **server-side** on write (existing `AuditEvent` behavior). No hash chain.

---

## P0 — Auth / session (the baseline)

| event_type | aggregate (type/id) | payload | emit point |
|---|---|---|---|
| `pos.login` | PosSession / device_id | `{ tenant_id, multi_tenant: bool, via_picker: bool }` | `authStore.login` success |
| `pos.operator_signin` | Operator / operator_id | `{ method: 'pin' }` | `operatorStore.verifyPin` success |
| `pos.operator_signoff` | Operator / operator_id | `{}` | `operatorStore.clearOperator` |
| `pos.screen_lock` | PosSession / device_id | `{ reason: 'manual'|'inactivity' }` | `operatorStore.lock` |
| `pos.device_unbind` | PosSession / device_id | `{}` | `authStore.unbindDevice` |
| `pos.terminal_change` | Terminal / terminal_id | `{ previous_terminal_id }` | Settings "Change terminal" |

## P1 — Highest-value fraud signals

| event_type | aggregate | payload | emit point | signal |
|---|---|---|---|---|
| `pos.cart_discarded` | PosSale / cart-session-id | `{ line_count, subtotal, discount_total, had_return_items, line_count_removed_before }` | `cartStore.clearCart` (non-checkout path) | sale built+dumped pre-payment |
| `pos.cart_line_removed` | PosSale / cart-session-id | `{ product_id, qty, unit_price, line_total, kind: 'sale'|'return' }` | `cartStore.removeItem` | pre-tender line void / sweethearting |
| `pos.sale_held` | HeldSale / held_id | `{ line_count, total, discount_total }` | `holdStore.holdCurrentCart` | parked sale created |
| `pos.sale_recalled` | HeldSale / held_id | `{ held_duration_ms, held_by_operator_id, cross_operator: bool, cross_shift: bool }` | `holdStore.recallTransaction` | cross-operator/shift recall |
| `pos.sale_hold_discarded` | HeldSale / held_id | `{ held_duration_ms }` | `holdStore.discardTransaction` | abandoned held sale |
| `pos.no_sale_drawer_open` | CashDrawer / terminal_id | `{ reason: string|null }` | drawer-kick-without-sale path | skimming — **DEFERRED: no such action exists in code today (needs a new no-sale drawer-open product surface). Not wired by Sub-Spec C.** |
| `pos.manager_override_denied` | Override / context | `{ scope: 'void'|'discount_limit'|'tender_tolerance'|'refund', requested_amount, cart_total, reason }` | `verifyScopedManagerPin` scope-mismatch / approval failure branch | failed override (approvals already server-side) |
| `pos.manager_pin_failed` | Operator / attempted-context | `{ context, attempt_count }` (NO pin/hash) | `operatorStore.verifyPin` bcrypt-mismatch catch + `verifyScopedManagerPin` failure | brute-force / guessing |
| `pos.refund_no_original_receipt` | Refund / refund-session-id | `{ refund_total, reason, customer_id }` | manual (no scanned original) refund path | no-receipt refund fraud — **DEFERRED: the refund flow requires a located/scanned receipt; no "no-original" branch exists today (needs a new product surface). Not wired by Sub-Spec C.** |
| `pos.line_discount_applied` | PosSale / cart-session-id | `{ line_id, product_id, discount_type, discount_amount, discount_percent, has_approval_evidence: bool }` | `cartStore` line-discount setter | discount abuse |
| `pos.transaction_discount_applied` | PosSale / cart-session-id | `{ discount_type, discount_amount, discount_percent, reason, has_approval_evidence: bool }` | `cartStore.setTransactionDiscount` | cart-level discount abuse |
| `pos.went_offline` | PosSession / device_id | `{ online_duration_ms }` | `connectivityStore` online→offline transition | working offline to dodge checks |
| `pos.went_online` | PosSession / device_id | `{ offline_duration_ms, queued_receipts, queued_cash_ops }` | `connectivityStore` offline→online transition | extent of offline window |
| `pos.fiscal_chain_break` | FiscalChain / terminal_id | `{ receipt_number, acknowledged: bool }` | `syncStore.setChainBreak` / acknowledge | hash-chain integrity break |

## P2 — Strong secondary signals

| event_type | aggregate | payload | emit point | signal |
|---|---|---|---|---|
| `pos.refund_draft_created` | RefundDraft / draft_id | `{ receipt_number, return_items_count, total }` | `refundDraftStore.persistDraft` (first persist) | refund-amount probing |
| `pos.refund_draft_discarded` | RefundDraft / draft_id | `{ held_duration_ms }` | `refundDraftStore.discardDraft` | draft churn |
| `pos.voucher_double_spend_attempt` | Voucher / voucher_code | `{ attempted_amount, caught_by: 'idempotent_check' }` | `paymentStore.addVoucherPayment` duplicate guard | reuse attempt |
| `pos.cart_quantity_updated` | PosSale / cart-session-id | `{ line_id, product_id, old_qty, new_qty, kind }` | `cartStore.updateQuantity` | qty tricks (fractional/negative) — HIGH VOLUME |
| `pos.sync_failed_orphaned` | OfflineReceipt / receipt_id | `{ idempotency_key, retry_count, error }` | `syncStore.failSync` per-receipt terminal failure | receipts stuck unsynced |
| `pos.customer_attached` | Checkout / cart-session-id | `{ customer_id }` | `paymentStore.attachCustomer` | rapid account switching |
| `pos.customer_detached` | Checkout / cart-session-id | `{ customer_id, attached_duration_ms }` | `paymentStore.detachCustomer` | rapid account switching |
| `pos.operator_locked` | Operator / operator_id | `{ reason: 'inactivity'|'manual', idle_ms }` | `operatorStore.lock` (P0 covers lock; this carries idle detail) | idle→change transitions |
| `pos.operator_unlocked` | Operator / operator_id | `{ locked_duration_ms }` | `operatorStore.verifyPin` unlock path | idle→change transitions |

> Note: `pos.screen_lock` (P0) and `pos.operator_locked` (P2) overlap — implement as ONE `pos.screen_lock` event carrying `{ reason, idle_ms }` (avoid double emit). Catalog lists both for completeness; the spec collapses them.

---

## Cross-cutting design notes

- **`cart-session-id`**: cart-level events need a stable id per in-progress sale so a discard/line-remove can be correlated. The cart has no persistent id today; the client generates a `cart_session_id` (UUID) on first cart mutation, cleared on checkout/discard. (Spec C detail.)
- **Volume / noise (owner accepted P2):** `pos.cart_line_removed` and `pos.cart_quantity_updated` are HIGH-FREQUENCY (many per normal sale). They carry full context for server-side aggregation; we capture all per the owner directive. Operational follow-up: retention/partitioning on `audit_events`, and downstream aggregation rather than per-row alerting. Flagged, not solved here.
- **Detection, not enforcement:** emitting an event NEVER blocks the action (best-effort, fire-and-forget). Enforcement stays in Sub-Spec B's gates. An audit failure must never break a sale/login/refund.
- **No secrets in payloads:** never log PINs/hashes/tokens. `pos.manager_pin_failed` carries only context + attempt count.
- **Already-server-side (do NOT re-emit):** posted receipts, completed payments, approved overrides (`ManagerOverrideAuthorized` + fiscal `OVERRIDE_*`), shift open/close, Z-reports, void/refund of posted receipts, terminal activate/deactivate/training-mode, draft *document* lifecycle (B2B). This catalog is strictly the client-side gap.
