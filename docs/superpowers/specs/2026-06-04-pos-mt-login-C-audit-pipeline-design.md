# POS Event-Sourced Audit / Fraud-Detection Pipeline — Sub-Spec C of 3

- **Date:** 2026-06-04 (revised after Codex adversarial review)
- **Branch:** `feat/pos-multitenant-login` (A + B already merged to `dev`)
- **Status:** Design — revised per Codex review
  (`docs/superpowers/reviews/2026-06-04-pos-mt-login-C-spec-codex-review.md`).
- **Catalog:** `docs/superpowers/specs/2026-06-04-pos-fraud-event-taxonomy.md` (P0+P1+P2).
- **Predecessors:** A (login) + B (logout separation) merged.

## Goal

Offline-first audit pipeline: POS client actions emit fraud-relevant audit events → queued in
local SQLite (durable **outbox**) → drained by the sync tick → a backend ingest endpoint →
written **idempotently** to the tenant `audit_events` table (queryable for the fraud-detection
center + ML). Detection only — never enforcement; emit is best-effort and never breaks the host
action.

## Architecture

```
store action (snapshot before/after, call set(), THEN outside the updater:)
   └─ void recordAuditEvent(type, aggregate, payload).catch(warn)   [best-effort, never throws]
          │ stamps event_id(uuid), tenant/company/operator, metadata, occurred_at
          ▼
       enqueueAuditEvent() ──► SQLite queued_audit_events (status=pending)   [OUTBOX]
          │
   syncService tick ──► pushQueuedAuditEvents() (drain MULTIPLE batches ≤100 to a budget)
          │                          │
   markSynced / markFailed(+retry)   └─► POST /pos/audit-events/sync
                                              │ per-event insert, dup-key→duplicate (race-safe)
                                              ▼  AuditEvent::fromClientEnvelope() (id=event_id,
                                                 client occurred_at preserved, hash over it)
                                         tenant audit_events
```

The pipeline is **event-type-agnostic** (backend validates the envelope only). Adding event
types = more `recordAuditEvent()` call sites; the queue/sync/backend never change.

## Client design (`apps/pos`)

### 1. SQLite migration v46 — `queued_audit_events` (outbox)
As before, with `event_id TEXT NOT NULL UNIQUE`. Indexed on `status`. (Full DDL in plan.)

### 2. `queuedAuditEventRepository.ts` — OUTBOX (not a PIN clone) [MAJOR 2]
Mirror the **fiscalEventRepository / cashDrawerRepository** retry pattern, NOT
`queuedPinUpdateRepository`:
- `enqueueAuditEvent(db, row)`
- `getPendingAuditEvents(db, limit)` → `WHERE status IN ('pending','failed') AND retry_count < MAX_RETRIES ORDER BY id ASC LIMIT ?` (failed rows are retried).
- `markAuditEventsSyncing(db, ids)` / `markAuditEventSynced(db, id)` / `markAuditEventFailed(db, id, error)` (increments retry_count).
- `recoverStrandedSyncingAuditEvents(db)` on boot → demote `syncing` → `pending` (crash recovery, mirrors receipt recovery).
- `pruneSyncedAuditEvents(db, keepDays)` on boot → delete `status='synced'` older than N days. Failed rows are NEVER pruned silently (they surface via a pending-audit count).
- `MAX_RETRIES` cap; rows exceeding it stay queryable (dead-letter) and counted, not dropped.

### 3. `lib/audit/recordAuditEvent.ts` — emit helper + typed catalog
Typed `PosAuditEventType` union (wired P0/P1/P2 from the taxonomy, excluding the two DEFERRED
events). `recordAuditEvent(input)` stamps `event_id` (uuid), context (tenant/company/operator
from stores; device/terminal/shift/is_offline in metadata) + `occurred_at` (client ISO), and
enqueues. **Best-effort:** the entire body is try/catch; a missing tenant or `getDb()` failure
→ `console.warn` + return; it NEVER throws into the caller. Callers `void
recordAuditEvent(...).catch(()=>{})` **outside** any Zustand `set()` updater [MINOR 2].
For `pos.login` the tenant/operator are passed explicitly (the just-resolved user), since the
store may not be committed yet.

### 4. `cart_session_id` lifecycle [MINOR 1]
Add `cartSessionId: string | null` to `cartStore`, owned like `pendingIdempotencyKey`:
- **Generate** (uuid) on the first mutation of an empty cart.
- **Include** in the `pos.sale_held` payload *before* `holdCurrentCart` clears the cart.
- **Regenerate** on `recallTransaction` / `replaceCart` / `replaceReturnItems` (a recalled/
  replaced cart is a new correlation), UNLESS restoring a persisted refund draft that
  intentionally keeps its own id.
- **Clear** on checkout success, discard (`clearCart` non-checkout), hold, shift close, operator switch.

### 5. Emission points (wire only REAL code paths) [MAJOR 4, MAJOR 5]
Wire `recordAuditEvent` at every emit point in the taxonomy whose action EXISTS. Notably:
- **Line discounts (MAJOR 5):** add `applyLineDiscount` / `removeLineDiscount` actions to
  `cartStore`, MOVE the current `HomePage` `useCartStore.setState(...)` discount logic into them,
  and emit `pos.line_discount_applied` there. (Direct-setState today would be missed.)
- **Manager-PIN / override failures:** emit `pos.manager_pin_failed` from `operatorStore.verifyPin`'s
  bcrypt-mismatch catch, and `pos.manager_override_denied` from `verifyScopedManagerPin`'s
  scope-mismatch / failure branch (real branches confirmed).
- **DEFERRED (no action exists — NOT wired by C):** `pos.no_sale_drawer_open` (no no-sale
  drawer action), `pos.refund_no_original_receipt` (refund requires a located/scanned receipt).
  Documented in the taxonomy as requiring a new product surface; tracked, not faked.
- Collapse `pos.screen_lock` (P0) + `pos.operator_locked` (P2) into ONE `pos.screen_lock` with `{reason, idle_ms}`.

### 6. `pushQueuedAuditEvents()` + scheduler [MAJOR 6]
In one sync tick, **drain multiple batches** (≤100 each) until the queue is empty or a
row/time budget is hit (e.g. ≤1000 rows or ≤2s per tick), then stop and resume next tick.
Wire AFTER receipts/PINs (audit is lowest priority). Expose `pendingAuditCount` for visibility.
On boot: `recoverStrandedSyncingAuditEvents` then `pruneSyncedAuditEvents(db, 14)`.

### 7. Connectivity transition subscriber [MAJOR 3]
`connectivityStore` only holds current state. Add a dedicated transition module (or extend the
store) that tracks `offlineStartedAt`/`onlineStartedAt`, compares previous↔current `isOnline`,
and emits **exactly once per edge**: `pos.went_offline { online_duration_ms }` on online→offline,
`pos.went_online { offline_duration_ms, queued_receipts, queued_cash_ops, queued_audit }` on
offline→online (counts read from SQLite). Subscribe once at app init.

## Backend design (`apps/api`)

### `AuditEvent::fromClientEnvelope()` factory [BLOCKER]
The existing custom constructor forces `occurred_at = now()` and `HasUuids` overwrites `id`. Add
a static factory that force-fills `id` (= client `event_id`), `tenant_id`, `company_id`,
`user_id` (= operator_id), `aggregate_*`, `payload`, `metadata`, and the **parsed client
`occurred_at`**, then computes `event_hash` over the preserved timestamp, WITHOUT triggering the
`HasUuids` overwrite (set the key explicitly and ensure the creating-hook respects a present key,
or insert via a path that bypasses uuid generation). Unit test: saved PK == client `event_id`,
saved `occurred_at` == client value, `event_hash` hashes the preserved timestamp.

### Route + controller
`POST /api/v1/pos/audit-events/sync` under the POS group (`api`, `auth:sanctum`,
`SetPermissionsTeam`, `EnforceTokenTenantClaim`; tenancy resolved by `ResolveTenancy`).
Authorize a **dedicated ability** `pos.audit_sync` (not `pos.operate_terminal`) [open-Q 3].
Per request:
1. Validate: `events` 1..100; required fields; `occurred_at` ISO; `event_type` ≤100; **payload +
   metadata byte caps** (e.g. ≤8 KB each) [NIT].
2. **Tenant guard:** reject 422 if any event `tenant_id` ≠ `$request->user()->tenant_id`, before
   writing anything.
3. **Per-event idempotent insert (race-safe) [MAJOR 1]:** `try { AuditEvent::fromClientEnvelope($e)
   ->saveOrFail(); $created++; } catch (UniqueConstraintViolationException) { $duplicates++; }`.
   **No batch-wide transaction** that could roll back valid rows on one duplicate.
4. Enrich `metadata` server-side with `ingested_at` (server time) and `client_clock_skew_ms`
   (server_now − client occurred_at) [NIT] + request `ip`.
5. Response: `{ data: { created, duplicates } }`.

### Tenancy [MINOR 3]
Write runs in the token's tenant DB (POS token carries the tenant claim → `ResolveTenancy`
initializes the tenant connection). Per-event `tenant_id` validated against the token. Feature
test with `TENANCY_DB_PER_TENANT=true`: post to the endpoint, assert the row lands in the TENANT
db, assert a foreign payload `tenant_id` is rejected before any write.

## Scope

- **In:** `apps/pos` (migration v46, outbox repo, recordAuditEvent helper + typed catalog,
  cart_session_id, line-discount actions, connectivity transition subscriber, ~20 real emit
  points, multi-batch drain, boot recovery+prune) + `apps/api` (`fromClientEnvelope` factory,
  ingest controller + route + `pos.audit_sync` ability + tests).
- **Out:** `apps/web`; the fraud ANALYSIS/alerting (downstream); existing server-side domain
  audit; the 2 DEFERRED events (need new product surfaces); sampling (capture-all + retention).

## Testing

Client: outbox repo (enqueue; getPending selects pending+failed under retry cap; markSyncing/
Synced/Failed; recoverStranded; prune synced only); `recordAuditEvent` best-effort (no throw on
getDb/missing-tenant; never breaks caller); `cartSessionId` lifecycle (create/include-in-hold/
regenerate-on-recall/clear); `pushQueuedAuditEvents` multi-batch drain + offline no-op + failed
retry; connectivity subscriber emits once per edge with counts; a representative emit per group
enqueues correct type/payload (no PIN/token/hash); line-discount via the new action emits even
when invoked from HomePage.

Backend: `fromClientEnvelope` (PK==event_id, occurred_at preserved, hash over it); idempotent
(same event_id twice → 1 row, second=duplicate; concurrent dup → caught, no 500); tenant guard
(foreign tenant_id → 422, nothing written); batch + byte-cap validation; metadata enriched with
ingested_at/skew; DB-per-tenant routing test.

## Acceptance criteria

1. Offline-first ingest: enqueued locally (durable outbox; failed rows retried, stranded
   recovered on boot), drained multi-batch on sync, written once to tenant `audit_events`,
   idempotent on resend (PK = client event_id; concurrent dup never 500s or double-writes).
2. Every WIRED P0/P1/P2 event (taxonomy minus the 2 DEFERRED) emits at a REAL code path,
   best-effort (never breaks the host action; no PIN/token/hash in payloads; emitted outside
   Zustand `set()` updaters).
3. Client `occurred_at` preserved as the business timestamp; server adds `ingested_at` +
   `client_clock_skew_ms`; payload/metadata byte-capped.
4. Cross-tenant events rejected before any write; ingest authorized by a dedicated
   `pos.audit_sync` ability; write lands on the correct tenant DB (tested in DB-per-tenant mode).
5. High-volume capture with multi-batch drain + retention prune; no `apps/web` changes; existing
   server-side audit + hash behavior untouched.

## Resolved review questions
1. `cart_session_id` lifecycle defined (§4), owned like `pendingIdempotencyKey`.
2. Multi-batch drain per tick to a budget; `id ASC` for retry fairness, `occurred_at` canonical
   business order; expose pending count.
3. Dedicated `pos.audit_sync` ability (not `pos.operate_terminal`).
4. Emit outside `set()` updaters, fire-and-forget with internal catch; tested that audit failure
   never changes cart/payment/operator behavior.
