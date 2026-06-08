# POS Audit Pipeline Phase 2 Adversarial Review

- Date: 2026-06-04
- Reviewer: Codex
- Scope: Phase 2, approximately 20 POS audit emit points across `apps/pos`
- Diff reviewed: `git diff 28560e9a7..b3bc4c309 -- apps/pos`
- Spec reference: `docs/superpowers/specs/2026-06-04-pos-mt-login-C-audit-pipeline-design.md` sections 4, 5, and 7
- Taxonomy reference: `docs/superpowers/specs/2026-06-04-pos-fraud-event-taxonomy.md`

## Test Run Results

Command 1:

```text
$ cd /Users/houssamr/Projects/syneriva/apps/erp/.claude/worktrees/pos-multitenant-login/apps/pos && pnpm vitest run src/stores/__tests__/cartStore.audit.test.ts src/lib/audit/__tests__/connectivityAuditSubscriber.test.ts 2>&1 | tail -8
 ✓ src/lib/audit/__tests__/connectivityAuditSubscriber.test.ts (9 tests) 20ms
 ✓ src/stores/__tests__/cartStore.audit.test.ts (19 tests) 6ms

 Test Files  2 passed (2)
      Tests  28 passed (28)
   Start at  22:52:12
   Duration  4.12s (transform 401ms, setup 1.07s, collect 723ms, tests 26ms, environment 5.03s, prepare 309ms)
```

Command 2:

```text
$ cd /Users/houssamr/Projects/syneriva/apps/erp/.claude/worktrees/pos-multitenant-login/apps/pos && pnpm tsc --noEmit 2>&1 | tail -3
<no output; command exited 0>
```

## 1. cart_session_id Lifecycle

### MAJOR: Removing the last cart line leaves `cartSessionId` alive, so the next sale can reuse the prior sale correlation id.

Problem: `removeItem` filters the item array but never clears `cartSessionId` when the result becomes empty. `updateQuantity(..., 0)` delegates to `removeItem`, so the common "set qty to zero / remove last line, then add another product" flow keeps the old session id. The next `addItem` calls `ensureCartSessionId(state.cartSessionId)`, which reuses the stale id instead of generating a new one for the first mutation of the new empty cart.

Evidence: `apps/pos/src/stores/cartStore.ts:276` delegates zero quantity to `removeItem`; `apps/pos/src/stores/cartStore.ts:338` only returns filtered `items`; `apps/pos/src/stores/cartStore.ts:217` reuses an existing `state.cartSessionId` on the next add.

Recommended fix: In `removeItem`, compute `nextItems`, and set `cartSessionId: nextItems.length === 0 ? null : state.cartSessionId`. Do the same for other actions that can make the cart empty (`clearReturnItems` if only return lines existed). Add a regression test: add item -> capture session -> remove last item -> add item -> session changes.

### MINOR: `pos.cart_discarded` omits taxonomy payload field `line_count_removed_before`.

Problem: The taxonomy requires `pos.cart_discarded` to carry `line_count_removed_before`, but the emitted payload only includes `line_count`, `subtotal`, `discount_total`, and `had_return_items`. This weakens the fraud signal for "build sale, remove lines, discard" patterns.

Evidence: `apps/pos/src/stores/cartStore.ts:380` builds the `pos.cart_discarded` payload without any removed-line count.

Recommended fix: Track a per-cart-session removed-line counter, increment it in `removeItem`, reset it whenever `cartSessionId` is cleared/regenerated, and include it as `line_count_removed_before`.

Correct: `holdCurrentCart` snapshots `cartSessionId` before `clearCart('hold')`, and includes it in the `pos.sale_held` payload (`apps/pos/src/stores/holdStore.ts:113`, `apps/pos/src/stores/holdStore.ts:156`). The checkout path now calls `clearCart('checkout')`, so checkout success no longer emits `pos.cart_discarded` (`apps/pos/src/pages/HomePage.tsx:962`, `apps/pos/src/stores/cartStore.ts:375`).

## 2. Line-Discount Action Refactor

No behavior-changing finding from the HomePage move. The old inline `useCartStore.setState` math was moved into `cartStore.applyLineDiscount` / `removeLineDiscount` with the same parse/percentage/fixed/clamp/toFixed/tax recalculation shape. HomePage now calls those actions directly (`apps/pos/src/pages/HomePage.tsx:920`, `apps/pos/src/pages/HomePage.tsx:928`), and the store implementation preserves the old gross-total, discount, clamped line-total, and tax recompute flow (`apps/pos/src/stores/cartStore.ts:397`, `apps/pos/src/stores/cartStore.ts:404`, `apps/pos/src/stores/cartStore.ts:409`, `apps/pos/src/stores/cartStore.ts:413`).

Residual concern is covered by finding 1: if a line exists while `cartSessionId` is unexpectedly null, `applyLineDiscount` falls back to `itemId` as the aggregate (`apps/pos/src/stores/cartStore.ts:392`, `apps/pos/src/stores/cartStore.ts:423`). That is not a normal path once the session lifecycle bug is fixed.

## 3. Emit Safety

No blocking emit finding. The emit helper wraps its whole body in `try/catch` and returns without throwing on missing tenant or DB/enqueue failure (`apps/pos/src/lib/audit/recordAuditEvent.ts:45`, `apps/pos/src/lib/audit/recordAuditEvent.ts:87`). The reviewed emit points use `void recordAuditEvent(...).catch(() => {})` outside Zustand updater callbacks; representative examples include cart quantity (`apps/pos/src/stores/cartStore.ts:286`, `apps/pos/src/stores/cartStore.ts:294`), hold (`apps/pos/src/stores/holdStore.ts:142`, `apps/pos/src/stores/holdStore.ts:156`), operator PIN failure (`apps/pos/src/stores/operatorStore.ts:267`), and payment/customer emits (`apps/pos/src/stores/paymentStore.ts:1291`, `apps/pos/src/stores/paymentStore.ts:1334`).

## 4. No Secrets / PII

No secret-leak finding in the reviewed audit payloads. `manager_pin_failed` carries context and attempt count only, not the PIN/hash (`apps/pos/src/stores/operatorStore.ts:267`). `manager_override_denied` payloads omit the PIN/hash on both no-match and server-denied branches (`apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:78`, `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:123`). Voucher double-spend emits the voucher code as the aggregate id per taxonomy and only `attempted_amount` plus `caught_by` in payload; no PAN/card data is included (`apps/pos/src/stores/paymentStore.ts:1291`). Customer attach/detach payloads include only `customer_id` and duration, not name/email/phone/tax number (`apps/pos/src/stores/paymentStore.ts:1334`, `apps/pos/src/stores/paymentStore.ts:1351`).

## 5. Connectivity Subscriber

### MAJOR [HYPOTHESIS]: Initial boot health-settle can be misclassified as a real online/offline edge.

Problem: `MainApp` starts connectivity monitoring, then starts the audit subscriber. `startMonitoring()` immediately fires an async `checkNow()`. The subscriber seeds `previousIsOnline` from the current store value and subscribes. If the initial browser hint says online but the first server health check resolves as unreachable after the subscriber is armed, the subscriber will emit `pos.went_offline` even though this was boot-state discovery, not a genuine observed online -> offline transition. This violates the "no emit on initial subscribe / settle" requirement.

Evidence: `apps/pos/src/App.tsx:382` starts monitoring before `apps/pos/src/App.tsx:389` starts the audit subscriber; the subscriber seeds from current state at `apps/pos/src/lib/audit/connectivityAuditSubscriber.ts:121` and treats any later value change as an edge at `apps/pos/src/lib/audit/connectivityAuditSubscriber.ts:70`.

Recommended fix: Either start the subscriber after the first `checkNow()` settles, or add an explicit "initialized/settled" gate in the subscriber so the first health-derived correction seeds the window without emitting.

Correct: `startConnectivityAuditSubscriber` is idempotent while subscribed (`apps/pos/src/lib/audit/connectivityAuditSubscriber.ts:118`), cleanup unsubscribes (`apps/pos/src/lib/audit/connectivityAuditSubscriber.ts:139`), and queued-count reads degrade to null instead of throwing through the emit path (`apps/pos/src/lib/audit/connectivityAuditSubscriber.ts:54`, `apps/pos/src/lib/audit/connectivityAuditSubscriber.ts:63`).

## 6. Import Cycles

No runtime init-order finding. `syncStore` now statically imports `terminalStore`, and `terminalStore` statically imports `syncStore`, but the dereferences are inside action/function bodies rather than top-level execution. `resolveTerminalId` only calls `useTerminalStore.getState()` when an event action runs (`apps/pos/src/stores/syncStore.ts:12`), while `terminalStore` uses `useSyncStore.getState()` inside boot/scheduler functions (`apps/pos/src/stores/terminalStore.ts:335`). This cycle is not clean, but it is runtime-safe as written.

## 7. Not-Emit Cases

No finding for the requested negative cases. `pos.login` only emits after `completeAuthentication` has committed a successful login (`apps/pos/src/stores/authStore.ts:280`). Successful PIN paths reset the failure counter and emit `pos.operator_signin`, while `pos.manager_pin_failed` is only in the catch branch (`apps/pos/src/stores/operatorStore.ts:198`, `apps/pos/src/stores/operatorStore.ts:242`, `apps/pos/src/stores/operatorStore.ts:261`). Refund draft re-persist is gated by `isFirstPersist` (`apps/pos/src/stores/refundDraftStore.ts:98`, `apps/pos/src/stores/refundDraftStore.ts:118`). Checkout-path clear does not emit discard because the condition requires `reason === 'discard'` (`apps/pos/src/stores/cartStore.ts:370`, `apps/pos/src/stores/cartStore.ts:375`). `setChainBreak(false, ...)` clears resolution state but only emits when `broken` is true (`apps/pos/src/stores/syncStore.ts:137`, `apps/pos/src/stores/syncStore.ts:148`).

## 8. Required Fields / Bad Aggregate IDs

### MAJOR: `pos.sync_failed_orphaned` is emitted as an aggregate sync failure with null receipt fields, not the taxonomy's per-receipt orphan signal.

Problem: The taxonomy says `pos.sync_failed_orphaned` is `OfflineReceipt / receipt_id` with `{ idempotency_key, retry_count, error }` emitted per receipt terminal failure. The implementation emits once from `failSync`, uses the terminal id as aggregate id, and sets `receipt_id`, `idempotency_key`, and `retry_count` to null. This produces low-value events and loses the exact orphaned receipt the fraud pipeline needs.

Evidence: `apps/pos/src/stores/syncStore.ts:85` explicitly documents the aggregate failure behavior; `apps/pos/src/stores/syncStore.ts:90` emits `pos.sync_failed_orphaned`; `apps/pos/src/stores/syncStore.ts:93` uses `resolveTerminalId()` as aggregate id; `apps/pos/src/stores/syncStore.ts:95` sets receipt/idempotency/retry fields to null.

Recommended fix: Move this emit to the per-receipt terminal-failure site in the sync drain where receipt id, idempotency key, retry count, and terminal error are known. Keep `failSync` as UI state only or introduce a separate aggregate event type if desired.

### MAJOR: `pos.sale_recalled` emits `cross_shift: null` even though the taxonomy requires a boolean.

Problem: `sale_recalled` needs `cross_shift: bool`. The held-sale row/state does not store the original shift, so the implementation emits `null` on every recall. That makes the field unusable for cross-shift recall detection.

Evidence: `apps/pos/src/stores/holdStore.ts:193` builds the `pos.sale_recalled` payload; `apps/pos/src/stores/holdStore.ts:197` comments that shift cannot be derived; `apps/pos/src/stores/holdStore.ts:200` emits `cross_shift: null`.

Recommended fix: Persist the active shift id when holding the sale, include it in `HeldTransaction`, and compute `cross_shift` by comparing held shift id to current shift id at recall.

### MAJOR: Normal wrong-PIN flow emits `aggregate_id: 'unknown'`.

Problem: A wrong operator PIN is a normal fraud-relevant path, but the event uses `aggregateId: 'unknown'`. The spec/taxonomy allows "attempted context"; it does not require a fake unknown id. This violates the review requirement to catch normal flows with garbage aggregate ids and makes grouping by aggregate unreliable.

Evidence: `apps/pos/src/stores/operatorStore.ts:267` emits `pos.manager_pin_failed`; `apps/pos/src/stores/operatorStore.ts:269` uses aggregate type `Operator`; `apps/pos/src/stores/operatorStore.ts:270` sets `aggregateId: 'unknown'`.

Recommended fix: Use a stable attempted-context aggregate such as `PosSession / device_id` or `Terminal / terminal_id`, or change the taxonomy implementation to an explicit attempted-context aggregate type. Do not emit the literal string `'unknown'`.

### MINOR: `pos.fiscal_chain_break` acknowledgement can emit a null `receipt_number`.

Problem: `acknowledgeChainBreak` emits regardless of whether `chainBreakReceiptNumber` is populated. The scheduler can call `setChainBreak(true, lastSynced)` where `lastSynced` is nullable, so the later acknowledgement can legitimately emit `{ receipt_number: null, acknowledged: true }` for a required context field.

Evidence: `apps/pos/src/lib/sync/syncScheduler.ts:113` reads a nullable last synced receipt number; `apps/pos/src/lib/sync/syncScheduler.ts:114` passes it into `setChainBreak`; `apps/pos/src/stores/syncStore.ts:167` reads `chainBreakReceiptNumber`; `apps/pos/src/stores/syncStore.ts:172` emits it as `receipt_number`.

Recommended fix: Require a non-null receipt number before setting/acknowledging a break, or emit a different explicit context field when the receipt is unavailable.

## Final Verdict

REQUEST-CHANGES

Confidence: High for findings 1, 2, and 8; medium for the connectivity boot-settle finding because it depends on initial health-check timing but is directly supported by the current startup ordering.

Counts by severity:

- BLOCKER: 0
- MAJOR: 5
- MINOR: 2
- NIT: 0

