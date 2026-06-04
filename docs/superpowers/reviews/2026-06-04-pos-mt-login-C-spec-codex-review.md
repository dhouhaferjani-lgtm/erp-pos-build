# Adversarial Review — Sub-Spec C: POS Audit/Fraud Pipeline
**Reviewer:** Codex  
**Date:** 2026-06-04  
**Spec:** docs/superpowers/specs/2026-06-04-pos-mt-login-C-audit-pipeline-design.md  
**Verdict:** REQUEST-CHANGES  
**Confidence:** High

## Summary
The pipeline direction is sound, but Sub-Spec C currently overstates how safely it can reuse the existing audit model and POS emit surfaces. The largest risks are backend idempotency/timestamp mechanics, stranded local queue rows if the repo truly mirrors queued PIN updates, and several catalog emit points that do not exist as store actions today.

## Findings

### BLOCKER AuditEvent cannot preserve the client envelope through the constructor path
**File:** apps/api/app/Modules/Compliance/Domain/AuditEvent.php:78  
**Problem:** The spec says the sync endpoint should create an `AuditEvent` with `id = event_id` and preserve client `occurred_at`, but the existing constructor path generates server-side `occurred_at = now()` and computes the hash from that server timestamp before persisting. `id` is also not fillable, so a plain Eloquent create/mass-assignment path cannot set the client UUID.  
**Evidence:** The fillable list includes `occurred_at` but not `id` at lines 41-52. The custom constructor calls `parent::__construct($attributes)` at line 88, then when `$companyId` is supplied it sets `$this->occurredAt = now()` at line 97, calculates the hash at line 98, and writes `occurred_at` from that server value at line 123. `calculateHash()` hashes `occurred_at` from `$this->occurredAt` at lines 152-162.  
**Fix:** Add a dedicated ingest constructor/factory such as `AuditEvent::fromClientEnvelope(...)` that force-fills `id`, `tenant_id`, `company_id`, `user_id`, payload, metadata, and the parsed client `occurred_at` before calculating the hash. Test that the saved PK equals the client `event_id`, the saved timestamp equals the client timestamp, and `event_hash` is calculated over the preserved timestamp.

### MAJOR Check-then-insert idempotency is race-prone unless duplicate-key handling is explicit
**File:** apps/api/database/migrations/tenant/2025_11_30_140000_create_audit_events_table.php:14  
**Problem:** The table primary key is enough to prevent double insertion of the same `event_id`, so the spec's "no schema change" assumption is partly valid. However, `whereKey($event_id)->exists()` followed by `save()` is not atomic; two requests can both observe "missing", and one will hit a duplicate primary-key exception. If the batch is wrapped in one transaction as specified, that exception can roll back otherwise valid events instead of reporting a per-row duplicate.  
**Evidence:** `audit_events.id` is the primary key at line 14. The existing schema therefore has uniqueness on `id`, but not a per-event ingest operation that atomically maps conflicts to `{status: duplicate}`.  
**Fix:** Use `insertOrIgnore`/`upsert` keyed by `id`, or catch the database duplicate-key exception around each event and return `duplicate`. Do not rely on a preflight `exists()` check for correctness.

### MAJOR Mirroring queuedPinUpdateRepository would strand failed audit events
**File:** apps/pos/src/lib/db/repositories/queuedPinUpdateRepository.ts:50  
**Problem:** The spec says to mirror `queuedPinUpdateRepository`, but that repository only returns rows with `status = 'pending'`. Its sync function marks the whole batch failed on any post error, and those failed rows are not selected again. A high-volume audit queue using this exact pattern would silently stop retrying after the first transient endpoint/network failure.  
**Evidence:** `getPendingPinUpdates()` selects only `WHERE status = 'pending'` at lines 50-54. `markPinUpdateFailed()` sets `status = 'failed'` and increments `retry_count` at lines 66-76. `pushQueuedPinUpdates()` reads pending rows, posts once, then marks all rows failed on catch at lines 975-995. By contrast, fiscal/cash-drawer queues explicitly select failed rows for retry in `fiscalEventRepository.ts:87` and `cashDrawerRepository.ts:50`.  
**Fix:** Design `queuedAuditEventRepository` as an outbox, not a literal PIN clone: select `status IN ('pending','failed') AND retry_count < max`, recover stranded `syncing` rows on boot, and cap retry/backoff behavior. Keep the local retention prune for synced rows, but do not prune or ignore failed rows without an operator-visible path.

### MAJOR Connectivity edge emission assumes a hook that is not in connectivityStore
**File:** apps/pos/src/stores/connectivityStore.ts:25  
**Problem:** Sub-Spec C requires once-per-transition `went_offline` and `went_online` events with duration payloads, but `connectivityStore` only stores the current booleans and timestamps for checks. It does not keep previous transition times, queued counts, or an internal edge callback where emitting once is guaranteed.  
**Evidence:** `checkNow()` writes `isOnline`, `serverReachable`, and `lastCheckedAt` directly at lines 25-37. `startMonitoring()` polls and handles browser events, but `handleOffline()` also just sets current state at lines 40-59. The only existing edge subscriber is in `syncScheduler`, which triggers sync on online restoration at lines 37-45 of `apps/pos/src/lib/sync/syncScheduler.ts`; it does not emit audit events or track durations.  
**Fix:** Add explicit transition state in `connectivityStore` or a single subscribed audit module that compares previous/current state, records `offlineStartedAt`/`onlineStartedAt`, and emits exactly once per edge. Include queued receipt/cash/audit counts in the online transition payload from SQLite.

### MAJOR Several catalog emit points are placeholders, not real code paths
**File:** apps/pos/src/stores/cashDrawerStore.ts:4  
**Problem:** The catalog requires events such as `pos.no_sale_drawer_open`, `pos.refund_no_original_receipt`, and `pos.manager_pin_failed`, but these do not map cleanly to current POS actions. Implementing "all P0+P1+P2 event types" would require new UI/store surfaces or would produce dead placeholder emitters.  
**Evidence:** `cashDrawerStore` only persists drawer configuration fields and setters at lines 4-20; there is no no-sale drawer-open action. The refund flow starts from a local receipt lookup or scan: `ReceiptLocatorScreen` requires `findReceiptByNumber()` and then emits a pending scan result at lines 111-139, while `ReceiptScanConfirmationSheet` explicitly starts from a sale receipt entry at lines 20-27. Manager PIN failure is not a branch in `operatorStore.verifyPin`; the manager approval path is `verifyScopedManagerPin()`, which throws `manager_pin_scope_mismatch` when no scoped manager matches at lines 30-55 of `apps/pos/src/lib/operatorApproval/scopedManagerPin.ts`.  
**Fix:** Split the taxonomy into "implemented now" vs "requires new product path". Either remove these events from Sub-Spec C acceptance or add the missing no-sale drawer action, no-original-refund flow, and manager PIN failure instrumentation with concrete call sites.

### MAJOR Line-discount instrumentation cannot live only in cartStore today
**File:** apps/pos/src/stores/cartStore.ts:26  
**Problem:** The spec groups line discount events under `cartStore`, but `cartStore` has no line-discount setter. The current UI mutates the Zustand state directly from `HomePage`, so a one-liner in existing cart actions would miss line-discount apply/remove events.  
**Evidence:** `CartActions` includes add/update quantity/remove/clear/transaction discount/replace/return helpers at lines 26-49, but no line discount action. `HomePage` applies line discounts via `useCartStore.setState(...)` at lines 939-965 and removes line discounts via another direct `setState(...)` at lines 920-936.  
**Fix:** Add explicit `applyLineDiscount` and `removeLineDiscount` actions to `cartStore`, move the existing HomePage mutation logic into those actions, and emit `pos.line_discount_applied` there. Add tests proving direct UI calls go through the action.

### MAJOR Batch-100 per tick is not enough as the only volume control
**File:** apps/pos/src/lib/sync/syncScheduler.ts:11  
**Problem:** The catalog intentionally captures high-frequency `cart_line_removed` and `cart_quantity_updated` events, but Sub-Spec C only proposes a local synced-row prune and a batch cap of 100. A terminal can create more than 100 audit rows per minute during normal editing or after a long offline window, especially if every quantity tap emits. Backlog can grow even while online.  
**Evidence:** The scheduler base interval is 60 seconds at line 11, starts one tick at lines 30-35, and skips ticks while offline or already syncing at lines 65-70. The spec caps audit push batches at 100 and places audit after receipts/PINs at spec lines 87-88, so audit has the lowest priority and one capped opportunity per tick unless implementation loops until drained.  
**Fix:** In one sync tick, drain multiple audit batches up to a time/row budget, expose pending audit count, and add server-side retention/partitioning guidance for `audit_events`. At minimum, specify a realistic sustained events/minute target and test backlog drain after an offline burst.

### MINOR `cart_session_id` needs a lifecycle rule for hold/recall and direct replace paths
**File:** apps/pos/src/stores/holdStore.ts:138  
**Problem:** Adding `cartSessionId` to `cartStore` is reasonable, but the spec only says to clear it on checkout success or discard. Existing hold/recall/refund-resume paths replace or clear carts in ways that will affect correlation if not explicitly defined.  
**Evidence:** `holdCurrentCart()` persists a held transaction and then calls `cartState.clearCart()` at line 138. `recallTransaction()` returns the held transaction after deleting it at lines 146-162, and HomePage later restores carts with `replaceCart()` or `replaceReturnItems()` at lines 694-708. `paymentStore.discardPendingSubmission()` is already called after hold clear at lines 139-143, showing this store has special lifecycle coupling.  
**Fix:** Define the lifecycle precisely: create before the first cart mutation, include it in the `sale_held` payload before clearing, generate a new cart session on recall/replaceCart unless restoring a persisted draft that deliberately keeps one, and clear it on checkout success, discard, hold, shift close, and operator switch.

### MINOR Fire-and-forget audit writes are safe only if they never run inside a Zustand set updater
**File:** apps/pos/src/stores/cartStore.ts:231  
**Problem:** Reading other stores with `useXStore.getState()` is safe in Zustand, but putting async `recordAuditEvent()` calls inside synchronous `set((state) => ...)` updaters would mix state mutation with side effects and risks duplicate emits if an updater is refactored or replayed in tests.  
**Evidence:** Hot cart actions currently perform pure state updates in `set` updaters, for example `updateQuantity()` at lines 231-235 and `removeItem()` at lines 262-265. The spec's helper reads several stores synchronously and then awaits SQLite, so the safe pattern is to capture before/after data outside the updater and call `void recordAuditEvent(...).catch(...)` after `set` returns.  
**Fix:** Specify the safe emission pattern in the spec: compute payload snapshots before/after mutation, call `set`, then fire-and-forget the audit helper outside the updater. The helper must catch all `getDb()`/SQLite failures internally.

### MINOR Backend tenancy is plausible, but needs a route-level acceptance test in DB-per-tenant mode
**File:** apps/api/app/Modules/POS/routes.php:32  
**Problem:** The existing route group and tenancy middleware are the right pattern, but the spec should not claim DB-per-tenant correctness without a test for the new audit endpoint. The code has the pieces, but the new controller can still accidentally use a central connection, bypass the POS route group, or trust payload `tenant_id`.  
**Evidence:** POS routes are under `api`, `auth:sanctum`, `SetPermissionsTeam`, and `EnforceTokenTenantClaim` at line 32. `ResolveTenancy` is appended to the API group and prioritized before authentication at `bootstrap/app.php:72` and `bootstrap/app.php:94`. `TenancyResolver` initializes the tenant DB when DB-per-tenant mode is active at lines 51-77 of `apps/api/app/Modules/Tenant/Application/Services/TenancyResolver.php`. Sanctum tokens are pinned to the central token model at `apps/api/app/Providers/AppServiceProvider.php:124`.  
**Fix:** Add a feature test with `TENANCY_DB_PER_TENANT=true`/config override that posts to `/api/v1/pos/audit-events/sync`, asserts the write lands in the tenant database, and asserts a foreign payload `tenant_id` is rejected before any row is written.

### NIT The spec should name client-clock trust and payload size limits
**File:** apps/api/app/Modules/Compliance/Domain/AuditEvent.php:152  
**Problem:** The client timestamp is necessary for offline business ordering, but the spec should explicitly store server ingest time and bound payload/metadata sizes. Otherwise a compromised or clock-skewed terminal can poison ML windows, and large payloads can bloat SQLite/server JSON columns.  
**Evidence:** The existing hash includes `occurred_at` as part of the event payload at lines 152-162, while the proposed spec treats client `occurred_at` as canonical. The existing migration uses JSONB columns at lines 20-21 of `apps/api/database/migrations/tenant/2025_11_30_140000_create_audit_events_table.php` with no request-size limits in the spec beyond envelope types.  
**Fix:** Preserve client `occurred_at`, add server-side `ingested_at` in metadata, validate payload and metadata byte limits, and add skew flags such as `client_clock_skew_ms` when server time can be compared.

## Open Questions — Concrete Answers

1. `cart_session_id` belongs in `cartStore`, but it must be lifecycle-owned like `pendingIdempotencyKey`: generate on first cart mutation, emit before hold/discard clears, clear on checkout success/discard/hold/shift close/operator switch, and regenerate on recall/replace unless explicitly restoring a draft.
2. Batch 100 once per 60-second tick is not enough for capture-all P2 volume. Ordering by local `id ASC` is fine for delivery/retry fairness, while `occurred_at` remains the business timestamp; the spec should drain multiple batches per tick and monitor queue depth.
3. `Gate::authorize('pos.operate_terminal')` is acceptable for initial POS-only ingest, but a dedicated `pos.ingest_audit_events` or `pos.audit_sync` ability is better before production so audit ingestion can be granted/revoked independently of full terminal operation.
4. Synchronous `getState()` reads are fine in Zustand. The risk is async SQLite work in hot paths: emit outside `set` updaters, fire-and-forget with internal catch, and test that audit failure never changes cart/payment/operator behavior.

## Counts
- BLOCKER: 1
- MAJOR: 6
- MINOR: 3
- NIT: 1
