# Adversarial Re-Review Round 2 — Sub-Spec C
**Reviewer:** Codex  
**Date:** 2026-06-04  
**Spec:** docs/superpowers/specs/2026-06-04-pos-mt-login-C-audit-pipeline-design.md  

## 1. BLOCKER — AuditEvent::fromClientEnvelope()
RESOLVED — The revised spec now requires a static factory that force-fills `id`, tenant/company/user fields, parsed client `occurred_at`, and computes `event_hash` before save (spec:101-108); this is feasible because the real constructor only overwrites timestamp/hash when `$companyId !== null` (apps/api/app/Modules/Compliance/Domain/AuditEvent.php:78-124), `id` is not fillable but can be set inside the model/factory (AuditEvent.php:41-52), and Laravel `HasUniqueIds::setUniqueIds()` skips generation when the key column is non-empty (Laravel 12 source, HasUniqueIds.php:404-415; local `vendor` is not installed in this worktree).

## 2. MAJOR1 — Race Idempotency
RESOLVED — The spec now prescribes per-event `saveOrFail()` with `catch (UniqueConstraintViolationException)` and explicitly forbids a batch-wide transaction (spec:119-121), while `audit_events.id` is already the primary key (apps/api/database/migrations/tenant/2025_11_30_140000_create_audit_events_table.php:13-15); `composer.lock` pins `laravel/framework` to v12.58.0 (apps/api/composer.lock:2567-2568), and Laravel 12 documents `Illuminate\Database\UniqueConstraintViolationException` as a real class, though the local vendor file is absent.

## 3. MAJOR2 — PIN-Clone Stranded Rows
RESOLVED — The revised outbox design selects `pending` plus retryable `failed`, recovers stranded `syncing`, prunes only `synced`, and dead-letters over retry cap (spec:44-52), matching the durable queue shape in fiscal/cash paths: fiscal selects pending/failed and recovers syncing (apps/pos/src/lib/db/repositories/fiscalEventRepository.ts:75-107), cash selects pending/failed under retry cap and has synced cleanup plus stranded recovery (apps/pos/src/lib/db/repositories/cashDrawerRepository.ts:47-90,136-143), unlike PINs which only select pending (apps/pos/src/lib/db/repositories/queuedPinUpdateRepository.ts:50-54).

## 4. MAJOR3 — Connectivity Edge Hook
RESOLVED — The spec now says to add a transition module or extend the store, track previous/current `isOnline`, and subscribe once at app init (spec:92-97), which is feasible because `useConnectivityStore` is a normal Zustand store (apps/pos/src/stores/connectivityStore.ts:20-70) and the existing scheduler already uses `useConnectivityStore.subscribe((state, prev) => ...)` for online restoration edges (apps/pos/src/lib/sync/syncScheduler.ts:37-46).

## 5. MAJOR4 — Placeholder Emit Points
PARTIAL — The two non-existent product surfaces are correctly deferred in both the spec and taxonomy (`pos.no_sale_drawer_open`, `pos.refund_no_original_receipt`; spec:81-83, taxonomy:45-48), and `verifyScopedManagerPin` has real denial branches for scope miss/API rejection (apps/pos/src/lib/operatorApproval/scopedManagerPin.ts:53-74), but the spec still says `operatorStore.verifyPin` has a “bcrypt-mismatch catch” (spec:78-80) while the real code only has a DB-layer catch and an offline-miss → API catch that throws `Invalid PIN` (apps/pos/src/stores/operatorStore.ts:154-186,222-234).

## 6. MAJOR5 — cartStore Line Discount
RESOLVED — The revised spec correctly identifies that line-discount actions must be added to `cartStore` and the current `HomePage` direct mutations moved there (spec:73-80); the real cart actions still lack `applyLineDiscount`/`removeLineDiscount` (apps/pos/src/stores/cartStore.ts:26-49), and HomePage currently applies/removes line discounts via `useCartStore.setState(...)` (apps/pos/src/pages/HomePage.tsx:920-965).

## 7. MAJOR6 + MINORs + NITs
RESOLVED — The spec now covers multi-batch drain (spec:86-90), `cart_session_id` lifecycle (spec:64-72), emit-outside-`set()` updaters (spec:54-60,171-177), DB-per-tenant tests (spec:126-130), payload/metadata byte caps (spec:115-116), and clock skew/ingest metadata (spec:122-123), and these are feasible against the POS route/tenancy pattern (apps/api/app/Modules/POS/routes.php:32; apps/api/bootstrap/app.php:72-97; apps/api/app/Modules/Tenant/Application/Services/TenancyResolver.php:51-77).

## 8. NEW Issues / Hand-Wavy Areas
PARTIAL — The revision is close to implementable, but it leaves one real branch mismatch for manager PIN failure instrumentation and one catalog/spec consistency issue listed below.

## NEW FINDINGS
MAJOR — `pos.manager_pin_failed` still points at a non-existent `operatorStore.verifyPin` bcrypt-mismatch catch; update the spec to emit on the actual offline-miss/API-failure path and define how to avoid double-emitting when `verifyScopedManagerPin` also reports a failed manager PIN.

MINOR — The spec adds `queued_audit` to `pos.went_online` (spec:96-97), but the canonical taxonomy still lists only `{ offline_duration_ms, queued_receipts, queued_cash_ops }` (docs/superpowers/specs/2026-06-04-pos-fraud-event-taxonomy.md:51-52); update the taxonomy or remove the extra field from the spec.

NIT — The prompt asked to search `apps/web/src`, but the revised spec scopes client work to `apps/pos` and explicitly excludes `apps/web` (spec:134-139); I found no `connectivityStore`, `cartStore`, or `operatorStore` under `apps/web/src`, so future review prompts should name `apps/pos/src` for these surfaces.

## VERDICT
REQUEST-CHANGES + confidence high
