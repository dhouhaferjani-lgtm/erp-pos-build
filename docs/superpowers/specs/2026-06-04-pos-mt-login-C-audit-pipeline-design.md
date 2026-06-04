# POS Event-Sourced Audit / Fraud-Detection Pipeline — Sub-Spec C of 3

- **Date:** 2026-06-04
- **Branch:** `feat/pos-multitenant-login` (worktree off `origin/dev`; A + B already merged to `dev`)
- **Status:** Design — pending Codex adversarial review before plan.
- **Catalog:** event types defined in `docs/superpowers/specs/2026-06-04-pos-fraud-event-taxonomy.md` (P0 + P1 + P2, owner-approved scope).
- **Predecessors:** A (login) + B (logout separation) merged. B's spec enumerated the auth/session events C must capture; this spec captures those PLUS the P1/P2 fraud signals.

## Goal

Offline-first audit pipeline: POS client actions emit fraud-relevant audit events → queued in local SQLite → drained by the existing sync tick → a new backend ingest endpoint → written idempotently to the tenant `audit_events` table (the existing compliance audit trail, queryable for the fraud-detection center + ML).

## Architecture

```
store action ──► recordAuditEvent(type, aggregate, payload)   [best-effort, never throws]
                      │ stamps event_id, context, occurred_at
                      ▼
              enqueueAuditEvent() ──► SQLite queued_audit_events (status=pending)
                      │
        syncService tick ──► pushQueuedAuditEvents() ──► POST /pos/audit-events/sync (batch ≤100)
                      │                                          │
              markSynced / markFailed                   idempotent write (event_id = audit_events.id)
                                                                 ▼
                                                   tenant audit_events table
```

The pipeline is **event-type-agnostic** — adding event types is just more `recordAuditEvent()` call sites + payload shapes; the queue, sync, and backend never change. The backend validates the envelope only (it does not know the `pos.*` catalog).

## Client design (`apps/pos`)

### 1. SQLite migration v46 — `queued_audit_events`

```sql
CREATE TABLE IF NOT EXISTS queued_audit_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id TEXT NOT NULL UNIQUE,          -- client UUID = audit_events.id (idempotency)
  event_type TEXT NOT NULL,
  aggregate_type TEXT NOT NULL,
  aggregate_id TEXT NOT NULL,
  tenant_id TEXT NOT NULL,
  company_id TEXT,
  operator_id TEXT,
  payload TEXT NOT NULL,                   -- JSON
  metadata TEXT NOT NULL,                  -- JSON (device_id, terminal_id, shift_id, is_offline, app_version)
  occurred_at TEXT NOT NULL,               -- ISO-8601, client wall-clock
  status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','syncing','synced','failed')),
  retry_count INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  synced_at TEXT,
  sync_error TEXT
);
CREATE INDEX IF NOT EXISTS idx_queued_audit_events_status ON queued_audit_events(status);
```

### 2. `queuedAuditEventRepository.ts`
Mirror `queuedPinUpdateRepository.ts`: `enqueueAuditEvent(db, row)`, `getPendingAuditEvents(db, limit=200)`, `markAuditEventSynced(db, id)`, `markAuditEventFailed(db, id, error)`. Plus a **retention prune**: `pruneSyncedAuditEvents(db, keepDays)` (synced rows older than N days deleted on boot) — high-volume P2 events must not grow the local DB unbounded.

### 3. `lib/audit/recordAuditEvent.ts` — the emit helper + typed catalog

```ts
export type PosAuditEventType =
  | 'pos.login' | 'pos.operator_signin' | ... ;   // the full P0/P1/P2 union from the taxonomy

export interface RecordAuditEventInput {
  type: PosAuditEventType;
  aggregateType: string;
  aggregateId: string;
  payload?: Record<string, unknown>;
  occurredAt?: string;                              // defaults to new Date().toISOString()
}

// Stamps event_id (uuid), tenant/company/operator + metadata (device/terminal/shift/is_offline),
// enqueues. BEST-EFFORT: wraps everything in try/catch; on any failure it console.warns and
// returns — it must NEVER throw into the calling store action.
export async function recordAuditEvent(input: RecordAuditEventInput): Promise<void>;
```

Context sources (read at emit time): `getDeviceId()`; `useTerminalStore.getState().terminal?.id`; `useTerminalStore.getState().shift?.id`; `useAuthStore.getState().user?.{tenantId,id}`; `useAuthStore.getState().companyId`; `useConnectivityStore.getState().isOnline`. If `tenant_id` is missing (not logged in) the helper drops the event (auth events stamp tenant from the just-resolved user — pass it explicitly where needed, e.g. `pos.login`).

### 4. `cart_session_id`
Cart-level events (`cart_discarded`, `cart_line_removed`, `cart_quantity_updated`, discount events) need a stable correlation id. Add a `cartSessionId: string | null` to `cartStore`, generated (uuid) on the first mutation of an empty cart, cleared on checkout success or discard. Cart events use it as `aggregate_id`.

### 5. Emission points
Wire `recordAuditEvent(...)` at every emit point in the taxonomy (P0+P1+P2). Each is a best-effort one-liner inside the existing action. Group by store for implementation (auth/operator, cart, hold, refund, payment/voucher/customer, connectivity/sync, drawer, settings). Collapse `screen_lock`/`operator_locked` into one `pos.screen_lock` with `{reason, idle_ms}`.

### 6. `pushQueuedAuditEvents()` in `syncService`
Mirror `pushQueuedPinUpdates`: pull pending (≤100 per batch), POST, mark synced/failed, `logSyncOperation`. Wire into the sync tick AFTER receipts/PINs (audit is lower priority than fiscal/receipt sync). On boot, run `pruneSyncedAuditEvents(db, 14)`.

## Backend design (`apps/api`)

### Route + controller
New `POST /api/v1/pos/audit-events/sync` under the POS middleware group (Sanctum + `EnforceTokenTenantClaim` + `SetPermissionsTeam`), `Gate::authorize('pos.operate_terminal')`. New `AuditEventSyncController` (or method on a POS sync controller). Request:

```
{ "events": [ { event_id(uuid), event_type, aggregate_type, aggregate_id,
                tenant_id(uuid), company_id(uuid|null), operator_id(uuid|null),
                payload(object), metadata(object), occurred_at(iso8601) }, ... ] }  // 1..100
```

Logic per request:
1. Validate (batch 1..100; types; `occurred_at` date; `event_type` ≤100).
2. **Tenant guard:** reject (422) if any event's `tenant_id` ≠ `$request->user()->tenant_id`.
3. **Idempotent write per event:** if `AuditEvent::whereKey($event_id)->exists()` → skip (already ingested); else create via the existing `AuditEvent` model with `id = event_id`, `user_id = operator_id`, `occurred_at` preserved, `metadata` merged with server-side `{ ip, ingested_via: 'pos-sync' }`. Server computes `event_hash`. Wrap the batch in a DB transaction; a single bad row should not 500 the whole batch — collect per-event `{event_id, status: 'created'|'duplicate'}`.
4. Response: `{ data: { synced, duplicates } }`.

### Idempotency / tenancy
- `event_id` (client UUID) = `audit_events.id` (PK) → dedup on resend without a schema change to the shared compliance table.
- Write runs in the token's tenant context (`audit_events` is on the tenant DB; the POS token carries the tenant claim → tenancy initialized by existing middleware). Per-event `tenant_id` validated against the token defends against a tampered client batch.

### No new server-side audit emission
The existing `DomainEventSubscriber` server-side path is untouched. This adds a client-ingest path alongside it. No hash chain (per-row hash, existing behavior).

## Scope

- **In:** `apps/pos` (migration, repo, helper, cart_session_id, ~24 emit points, sync drain, retention prune) + `apps/api` (one ingest endpoint/controller + route + tests).
- **Out:** `apps/web`; the fraud-detection ANALYSIS/alerting (downstream, separate — this only SOURCES events); changing existing server-side domain-event audit; sampling/aggregation (capture-all per owner, with retention prune as the only volume control).

## Testing

Client:
- repo: enqueue/getPending(limit)/markSynced/markFailed/prune.
- `recordAuditEvent`: stamps event_id+context+occurred_at and enqueues; **best-effort** — getDb failure / missing tenant → no throw, event dropped/warned; never throws into caller.
- `cartSessionId` lifecycle: generated on first mutation, stable across edits, cleared on checkout/discard.
- `pushQueuedAuditEvents`: success→synced; failure→failed+retry; batches ≤100; offline → no-op.
- a representative emit point per group actually enqueues the right type+payload (e.g. cart_discarded carries discount_total; manager_pin_failed carries NO pin; went_online carries queued counts) — and that emit failure never breaks the host action.

Backend:
- idempotent: same `event_id` twice → one row, second reports duplicate.
- tenant guard: event with foreign `tenant_id` → 422, nothing written.
- batch validation (size, required fields, occurred_at format).
- `event_hash` computed; `occurred_at` preserved; metadata merged with server fields; `user_id` = operator_id.
- writes land on the correct tenant DB.

## Acceptance criteria

1. The pipeline ingests POS audit events offline-first: enqueued locally, drained on sync, written once to tenant `audit_events`, idempotent on resend.
2. All P0+P1+P2 event types from the taxonomy are emitted at their points, best-effort (an audit failure never breaks the host action; no PIN/token/hash in any payload).
3. Cross-tenant events are rejected server-side; events carry operator/terminal/shift/occurred_at for ML features.
4. High-volume events captured (capture-all) with a local retention prune; `audit_events` write is event-type-agnostic.
5. No `apps/web` changes; existing server-side audit untouched; no hash-chain change.

## Open questions for review

- `cart_session_id` placement in `cartStore` — does any existing logic key off cart identity that this could collide with?
- Should the sync drain batch-cap (100) + per-tick frequency risk a backlog for very high P2 volume, and is `getPendingAuditEvents` ordering by `id ASC` (insertion order) sufficient given `occurred_at` is the canonical order?
- Is `Gate pos.operate_terminal` the right authorization for audit ingest, or should it be a dedicated ability?
- Best-effort emit reads several stores synchronously at call time — any re-entrancy/perf concern wiring it into hot paths like `cartStore.removeItem` / `updateQuantity`?
