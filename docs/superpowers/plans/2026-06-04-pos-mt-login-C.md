# POS Audit / Fraud-Detection Pipeline (Sub-Spec C) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use `- [ ]`.

**Goal:** Offline-first event-sourced audit pipeline: POS actions → SQLite outbox → sync drain → backend ingest → tenant `audit_events`, idempotent; then wire ~20 fraud-relevant emit points.

**Spec:** `docs/superpowers/specs/2026-06-04-pos-mt-login-C-audit-pipeline-design.md`. **Catalog:** `docs/superpowers/specs/2026-06-04-pos-fraud-event-taxonomy.md`. **Codex spec reviews:** `...-C-spec-codex-review.md` (+ `-r2`).

**Phasing:** Phase 1 = foundation (Tasks 1–5, the reusable pipeline). Phase 2 = emit points (Tasks 6–12). Phase 3 = gate (Task 13). Phase 1 is a coherent shippable milestone on its own (pipeline works; emit points add coverage).

**Conventions:** backend tests = PHPUnit `RefreshDatabase` + real models (per CLAUDE.md); client tests = Vitest mocking `@/lib/api`/`@/lib/db`. Run backend `cd apps/api && ./vendor/bin/phpunit --filter X`; client `cd apps/pos && pnpm vitest run <path>`. PHPStan L8 + Pint on new PHP. Strings via `t()`.

---

# PHASE 1 — Pipeline foundation

## Task 1: Backend `AuditEvent::fromClientEnvelope()` factory

**Files:** Modify `apps/api/app/Modules/Compliance/Domain/AuditEvent.php`; Test `apps/api/tests/Unit/Compliance/AuditEventFromClientEnvelopeTest.php`.

- [ ] **Step 1: Read** the current `AuditEvent.php` (custom constructor lines ~78-130, `calculateHash` ~152-165, `$fillable`, `HasUuids`). Confirm `calculateHash()` hashes `$this->occurredAt`.

- [ ] **Step 2: Write the failing test** asserting the contract:

```php
public function test_from_client_envelope_preserves_id_and_occurred_at_and_hashes_over_client_time(): void
{
    $eventId = (string) Str::uuid();
    $occurred = '2026-06-04T10:00:00.000000+00:00';
    $e = AuditEvent::fromClientEnvelope([
        'event_id' => $eventId, 'tenant_id' => $tenantId, 'company_id' => $companyId,
        'operator_id' => $userId, 'event_type' => 'pos.login', 'aggregate_type' => 'PosSession',
        'aggregate_id' => 'device-1', 'payload' => ['multi_tenant' => true], 'metadata' => ['device_id' => 'd1'],
        'occurred_at' => $occurred,
    ]);
    $e->saveOrFail();
    $this->assertSame($eventId, $e->id);
    $this->assertSame($occurred, $e->occurred_at->toIso8601String() /* or format */);
    // hash recomputed over the CLIENT occurred_at must equal stored hash
    $this->assertNotEmpty($e->event_hash);
}
public function test_duplicate_event_id_raises_unique_violation(): void { /* save twice → expect QueryException/UniqueConstraintViolationException */ }
```

- [ ] **Step 3: Run → fails** (`cd apps/api && ./vendor/bin/phpunit --filter AuditEventFromClientEnvelope`).

- [ ] **Step 4: Implement the factory** on `AuditEvent` (per spec mechanism):

```php
/** @param array<string,mixed> $env */
public static function fromClientEnvelope(array $env): self
{
    $event = new self();                       // NOT the company-supplied custom ctor branch
    $event->id = $env['event_id'];             // HasUuids skips generation when key is set
    $event->forceFill([
        'tenant_id' => $env['tenant_id'],
        'company_id' => $env['company_id'],
        'user_id' => $env['operator_id'] ?? null,
        'event_type' => $env['event_type'],
        'aggregate_type' => $env['aggregate_type'],
        'aggregate_id' => $env['aggregate_id'],
        'payload' => $env['payload'] ?? [],
        'metadata' => $env['metadata'] ?? [],
        'occurred_at' => CarbonImmutable::parse($env['occurred_at']),
    ]);
    $event->event_hash = $event->calculateHashPublic($event->payload); // expose calculateHash or inline
    return $event;
}
```

If the custom constructor's `new self()` path with no args still stamps `occurred_at`/hash, adjust: gate that logic on a constructor arg (e.g. only when `$companyId !== null` positional) so the no-arg path is inert. Expose `calculateHash` as a `protected`→callable (add a thin `public function recomputeHash(): void { $this->event_hash = $this->calculateHash($this->payload); }` and call it in the factory). Keep `id` in `$fillable` OR set via property (HasUuids respects a non-empty key).

- [ ] **Step 5: Run → passes.** Then PHPStan L8 + Pint on the file.

- [ ] **Step 6: Commit** `feat(audit): AuditEvent::fromClientEnvelope factory (preserve client id/occurred_at)`.

## Task 2: Backend ingest endpoint + `pos.audit_sync` ability

**Files:** Create `apps/api/app/Modules/POS/Presentation/Controllers/AuditEventSyncController.php`; modify `apps/api/app/Modules/POS/routes.php`; register the `pos.audit_sync` ability (wherever POS abilities/permissions are defined — mirror `pos.operate_terminal`); Test `apps/api/tests/Feature/POS/AuditEventSyncTest.php`.

- [ ] **Step 1: Write failing feature tests** (RefreshDatabase, seeded permissions; authenticate a POS user/token with `pos.audit_sync`):
  - posts a batch of 2 events → 200 `{ data: { created: 2, duplicates: 0 } }`; rows in `audit_events` with PK == client event_id, `occurred_at` preserved, `metadata.ingested_at` present.
  - re-post the same batch → `{ created: 0, duplicates: 2 }`, still 2 rows total (idempotent).
  - event with foreign `tenant_id` → 422, zero rows written.
  - batch > 100 or missing field → 422.
  - **DB-per-tenant** (`config(['tenancy_resolver.db_per_tenant' => true])` or the test harness equivalent): write lands in the tenant DB.

- [ ] **Step 2: Run → fails** (route 404).

- [ ] **Step 3: Implement** the controller (per spec §Backend):

```php
public function sync(Request $request): JsonResponse
{
    Gate::authorize('pos.audit_sync');
    $validated = $request->validate([
        'events' => ['required','array','min:1','max:100'],
        'events.*.event_id' => ['required','uuid'],
        'events.*.event_type' => ['required','string','max:100'],
        'events.*.aggregate_type' => ['required','string','max:100'],
        'events.*.aggregate_id' => ['required','string','max:100'],
        'events.*.tenant_id' => ['required','uuid'],
        'events.*.company_id' => ['nullable','uuid'],
        'events.*.operator_id' => ['nullable','uuid'],
        'events.*.payload' => ['required','array'],
        'events.*.metadata' => ['required','array'],
        'events.*.occurred_at' => ['required','date'],
    ]);
    $user = $request->user();
    foreach ($validated['events'] as $e) {
        if ($e['tenant_id'] !== $user->tenant_id) {
            throw ValidationException::withMessages(['events' => ['Event tenant mismatch.']]);
        }
        // byte caps
        if (strlen(json_encode($e['payload'])) > 8192 || strlen(json_encode($e['metadata'])) > 8192) {
            throw ValidationException::withMessages(['events' => ['Payload too large.']]);
        }
    }
    $created = 0; $duplicates = 0;
    foreach ($validated['events'] as $e) {
        $e['metadata'] = array_merge($e['metadata'], [
            'ingested_at' => now()->toIso8601String(),
            'client_clock_skew_ms' => now()->diffInMilliseconds(CarbonImmutable::parse($e['occurred_at'])),
            'ip' => $request->ip(),
        ]);
        try {
            AuditEvent::fromClientEnvelope($e)->saveOrFail();
            $created++;
        } catch (UniqueConstraintViolationException) {
            $duplicates++;
        }
    }
    return response()->json(['data' => compact('created', 'duplicates')]);
}
```

Route in `POS/routes.php` (POS group, with the same middleware as `sync-pins`): `Route::post('audit-events/sync', [AuditEventSyncController::class, 'sync']);`. Register `pos.audit_sync` ability/permission.

- [ ] **Step 4: Run → passes.** PHPStan L8 + Pint.

- [ ] **Step 5: Commit** `feat(audit): POS audit-events sync ingest endpoint (idempotent, tenant-guarded)`.

## Task 3: Client SQLite v46 `queued_audit_events` + outbox repo

**Files:** Modify `apps/pos/src/lib/db/migrations.ts` (add v46); Create `apps/pos/src/lib/db/repositories/queuedAuditEventRepository.ts`; Test `apps/pos/src/lib/db/repositories/__tests__/queuedAuditEventRepository.test.ts`.

- [ ] **Step 1: Write failing repo tests** (use the in-memory test DB pattern from existing repo tests): enqueue→getPending returns it; markSynced removes from pending; markFailed increments retry_count and row STILL returned by getPending (until retry cap); rows at retry cap excluded; recoverStranded flips syncing→pending; prune deletes old synced only.

- [ ] **Step 2: Run → fails.**

- [ ] **Step 3: Add migration v46** (append to the migrations array; the DDL from spec §Client.1, with `event_id TEXT NOT NULL UNIQUE` + `idx_queued_audit_events_status`).

- [ ] **Step 4: Implement the outbox repo** (`MAX_RETRIES = 10`):

```ts
export interface QueuedAuditEvent { id:number; eventId:string; eventType:string; aggregateType:string; aggregateId:string; tenantId:string; companyId:string|null; operatorId:string|null; payload:string; metadata:string; occurredAt:string; status:'pending'|'syncing'|'synced'|'failed'; retryCount:number; }
export const MAX_AUDIT_RETRIES = 10;
export async function enqueueAuditEvent(db, row): Promise<void> { /* INSERT */ }
export async function getPendingAuditEvents(db, limit=100): Promise<QueuedAuditEvent[]> {
  /* SELECT * WHERE status IN ('pending','failed') AND retry_count < $MAX ORDER BY id ASC LIMIT $limit */
}
export async function markAuditEventsSyncing(db, ids): Promise<void> { /* UPDATE ... status='syncing' WHERE id IN (...) */ }
export async function markAuditEventSynced(db, id): Promise<void> { /* status='synced', synced_at */ }
export async function markAuditEventFailed(db, id, error): Promise<void> { /* status='failed', retry_count+1, sync_error */ }
export async function recoverStrandedSyncingAuditEvents(db): Promise<void> { /* UPDATE status='pending' WHERE status='syncing' */ }
export async function pruneSyncedAuditEvents(db, keepDays=14): Promise<void> { /* DELETE WHERE status='synced' AND created_at < datetime('now', '-N days') */ }
export async function countPendingAuditEvents(db): Promise<number> { /* COUNT pending+failed under cap */ }
```

- [ ] **Step 5: Run → passes.** `pnpm tsc --noEmit`.

- [ ] **Step 6: Commit** `feat(audit): queued_audit_events outbox table + repository (retry/recover/prune)`.

## Task 4: Client `recordAuditEvent` helper + typed catalog

**Files:** Create `apps/pos/src/lib/audit/recordAuditEvent.ts`, `apps/pos/src/lib/audit/eventTypes.ts`; Test `apps/pos/src/lib/audit/__tests__/recordAuditEvent.test.ts`.

- [ ] **Step 1: Write failing tests:** records with explicit context → enqueues a row with the right fields + a generated `event_id` (uuid) + `occurred_at`; **best-effort** — when `getDb()` throws OR tenant is missing → resolves without throwing and enqueues nothing; PIN/token never appear in payload (caller responsibility, but test the manager_pin_failed shape carries no secret).

- [ ] **Step 2: Run → fails.**

- [ ] **Step 3: Implement** `eventTypes.ts` (the `PosAuditEventType` union — all WIRED P0/P1/P2 from the taxonomy, excluding the 2 DEFERRED) and `recordAuditEvent.ts`:

```ts
export async function recordAuditEvent(input: RecordAuditEventInput): Promise<void> {
  try {
    const auth = useAuthStore.getState();
    const tenantId = input.tenantId ?? auth.user?.tenantId;
    if (!tenantId) return;                                  // drop if no tenant
    const term = useTerminalStore.getState();
    const row = {
      eventId: crypto.randomUUID(),
      eventType: input.type, aggregateType: input.aggregateType, aggregateId: input.aggregateId,
      tenantId, companyId: input.companyId ?? auth.companyId ?? null,
      operatorId: input.operatorId ?? useOperatorStore.getState().operator?.id ?? auth.user?.id ?? null,
      payload: JSON.stringify(input.payload ?? {}),
      metadata: JSON.stringify({ device_id: getDeviceId(), terminal_id: term.terminal?.id ?? null, shift_id: term.shift?.id ?? null, is_offline: !useConnectivityStore.getState().isOnline, app_version: APP_VERSION }),
      occurredAt: input.occurredAt ?? new Date().toISOString(),
      status: 'pending' as const, retryCount: 0,
    };
    const db = await getDb();
    await enqueueAuditEvent(db, row);
  } catch (e) {
    console.warn('[audit] recordAuditEvent failed (non-fatal):', e);
  }
}
```

- [ ] **Step 4: Run → passes.** tsc.

- [ ] **Step 5: Commit** `feat(audit): recordAuditEvent best-effort helper + typed event catalog`.

## Task 5: Client drain `pushQueuedAuditEvents` + boot recovery/prune + wire sync tick

**Files:** Modify `apps/pos/src/lib/sync/syncService.ts` (+ wherever boot-time recovery/prune is run, e.g. terminalStore.initialize / app boot); Test add to `apps/pos/src/lib/sync/__tests__/`.

- [ ] **Step 1: Write failing tests:** drain posts pending (≤100), marks synced on success; on failure marks failed (retried next time); **multi-batch** — with 250 pending it posts 3 batches in one drain call (loop to budget); offline → no-op; respects retry cap.

- [ ] **Step 2: Run → fails.**

- [ ] **Step 3: Implement** `pushQueuedAuditEvents(db)` (mirror `pushQueuedPinUpdates` but LOOP up to a budget, e.g. `MAX_BATCHES_PER_TICK=10`):

```ts
export async function pushQueuedAuditEvents(db: Database): Promise<number> {
  let total = 0;
  for (let i = 0; i < MAX_AUDIT_BATCHES_PER_TICK; i++) {
    const pending = await getPendingAuditEvents(db, 100);
    if (pending.length === 0) break;
    await markAuditEventsSyncing(db, pending.map(p => p.id));
    try {
      await apiPost('/pos/audit-events/sync', { events: pending.map(toEnvelope) });
      for (const r of pending) await markAuditEventSynced(db, r.id);
      total += pending.length;
    } catch (error) {
      const msg = coerceSyncError(error);
      for (const r of pending) await markAuditEventFailed(db, r.id, msg);
      break;                                  // stop this tick on error; retry next tick
    }
  }
  return total;
}
```

Wire into the sync tick AFTER receipts/PINs. On boot run `recoverStrandedSyncingAuditEvents(db)` then `pruneSyncedAuditEvents(db, 14)` (alongside the existing receipt recovery).

- [ ] **Step 4: Run → passes.** tsc + lint.

- [ ] **Step 5: Commit** `feat(audit): multi-batch audit drain + boot recovery/prune wired into sync`.

**>>> CHECKPOINT after Task 5: the pipeline is end-to-end functional (no emit points yet). Spec/quality/Codex review Phase 1 here before Phase 2. <<<**

---

# PHASE 2 — Emit points (mechanical: `void recordAuditEvent(...).catch(()=>{})` OUTSIDE any `set()` updater)

Each task: for each event, snapshot needed data, call the existing action's `set()`, then fire-and-forget `recordAuditEvent`. Add a test per event that the action enqueues the right `type` + key payload fields (mock `recordAuditEvent` or the repo) AND that an emit failure never breaks the action.

## Task 6: `cart_session_id` + cartStore line-discount actions
- Add `cartSessionId` to `cartStore` with the lifecycle from spec §4 (generate on first mutation of empty cart; include in `sale_held` before clear; regenerate on recall/replace; clear on checkout/discard/hold/shift-close/operator-switch).
- Add `applyLineDiscount`/`removeLineDiscount` actions; MOVE `HomePage`'s `useCartStore.setState(...)` line-discount logic (HomePage ~lines 920-965) into them. Tests: HomePage path goes through the action.
- Commit `feat(audit): cart_session_id lifecycle + cartStore line-discount actions`.

## Task 7: P0 auth/session emits
- `pos.login` (authStore.login success — pass resolved tenant/operator explicitly; payload `{multi_tenant, via_picker}`); `pos.device_unbind` (authStore.unbindDevice); `pos.operator_signin` (operatorStore.verifyPin success); `pos.operator_signoff` (operatorStore.clearOperator); `pos.screen_lock` (operatorStore.lock, `{reason, idle_ms}`); `pos.terminal_change` (Settings change-terminal). Commit.

## Task 8: Cart emits
- `pos.cart_discarded` (clearCart non-checkout, `{line_count, subtotal, discount_total, ...}`); `pos.cart_line_removed` (removeItem); `pos.cart_quantity_updated` (updateQuantity); `pos.line_discount_applied` (applyLineDiscount); `pos.transaction_discount_applied` (setTransactionDiscount). Commit.

## Task 9: Hold emits
- `pos.sale_held` (holdCurrentCart — include cart_session_id); `pos.sale_recalled` (recallTransaction — `{held_duration_ms, cross_operator, cross_shift}`); `pos.sale_hold_discarded` (discardTransaction). Commit.

## Task 10: Refund-draft + payment/voucher/customer emits
- `pos.refund_draft_created` (refundDraftStore.persistDraft first persist); `pos.refund_draft_discarded` (discardDraft); `pos.voucher_double_spend_attempt` (paymentStore.addVoucherPayment duplicate guard); `pos.customer_attached`/`pos.customer_detached` (paymentStore attach/detach). Commit.

## Task 11: Override/PIN-failure emits
- `pos.manager_pin_failed` (operatorStore.verifyPin bcrypt-mismatch catch — NO pin/hash in payload); `pos.manager_override_denied` (verifyScopedManagerPin failure/scope-mismatch). Commit.

## Task 12: Connectivity transition subscriber + chain break
- Add a transition module subscribed once at app init that tracks offline/online edges and emits `pos.went_offline {online_duration_ms}` / `pos.went_online {offline_duration_ms, queued_receipts, queued_cash_ops, queued_audit}` (counts from SQLite) — exactly once per edge.
- `pos.fiscal_chain_break` (syncStore.setChainBreak / acknowledge). `pos.sync_failed_orphaned` (syncStore.failSync per terminal-failed receipt). Commit.

---

# PHASE 3

## Task 13: Gate
- [ ] Backend: `cd apps/api && ./vendor/bin/phpunit --filter Audit` green; PHPStan L8 + Pint clean on new files.
- [ ] Client: `cd apps/pos && pnpm tsc --noEmit`; `pnpm eslint` changed files; `pnpm vitest run` new/changed; full `pnpm vitest run` no NEW failures vs baseline (2 pre-existing migrations.v37).
- [ ] Commit fixups.

---

## Self-review notes (author)
- Spec coverage: pipeline (T1-5), all WIRED catalog events (T6-12), idempotency/tenancy/best-effort/outbox/multi-batch all mapped. 2 DEFERRED events explicitly excluded (no code path).
- Highest risk: Task 1 (factory vs HasUuids/custom-ctor — Codex flagged; the test contract pins it) and Task 6 (cart_session_id lifecycle + HomePage refactor). These get extra review.
- Emit pattern invariant: fire-and-forget OUTSIDE `set()`; never throws into the host action — every Phase 2 task asserts "emit failure doesn't break the action".
