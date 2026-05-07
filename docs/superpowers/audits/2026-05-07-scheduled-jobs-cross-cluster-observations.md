# Scheduled-jobs cluster — cross-cluster observations from triage (3 findings — DEFERRED)

Audit date: 2026-05-07
Reporter: Claude Opus 4.7 (orchestrator) — surfaced during `api.scheduled-jobs` triage
Status: DEFERRED — out of `api.scheduled-jobs` scope per kickoff brief; tracked here for follow-up clusters

## Context

The `api.scheduled-jobs` cluster (cluster_id `api.scheduled-jobs`) classifies the
9 ShouldQueue job classes under `app/Modules/*/{Jobs,Application/Jobs,Infrastructure/Jobs}`
into cat-(a) "carries tenant context, rebinds in handle()" and cat-(b)
"@cross-tenant-by-design with non-empty justification". During triage
(see `2026-05-07-api-scheduled-jobs-triage.md`), three observations
surfaced that fall outside the cluster's natural surface and are
therefore tracked here for follow-up cluster owners.

Per the kickoff brief: cross-cluster blind spots are documented and
deferred, not silently absorbed.

All three findings are LOW severity — none represents a live exploitable
cross-tenant data leak in the systems audited. They are
defense-in-depth tightenings or scope-extension reminders.

## Finding A — `platform_submission_id` global-uniqueness contract risk in `ProcessEnrichmentEventListener` (LOW / defense-in-depth)

**Severity**: LOW (defense-in-depth; no observed contract violation)

**Surface**: `apps/api/app/Modules/Product/Application/Listeners/ProcessEnrichmentEventListener.php:22`

```php
$product = Product::where('platform_submission_id', $event->trackingId)->first();
```

**Issue**: `ProcessEnrichmentWebhookJob` (api.scheduled-jobs cat-(b),
classified as a pure event re-dispatcher with zero DB access in
`handle()`) re-dispatches `EnrichmentWebhookReceived` to this synchronous
listener. The listener resolves the inbound webhook's `trackingId`
to a `Product` *without* a tenant guard — the entire isolation contract
rests on the platform's `platform_submission_id` being globally unique
across all tenants for all time.

If two tenants could ever submit the same tracking ID (operator error,
platform-side ID collision, malicious header tampering on a tenant-A
admin who has Product write privileges for tenant-A but spoofs a
tenant-B tracking ID), the listener would mis-route the platform's
status update to a foreign tenant's Product row. The downstream
`$product->update(['enrichment_status' => $enrichmentStatus])` on
line 35, the `$this->enrichmentReviewService->fetchAndStore()` on
lines 45-48, and the `EnrichmentResultReadyEvent::dispatch()` on
lines 52-59 would all execute against the wrong tenant's data.

**Why LOW**: in the system audited (`apps/erp` against the platform
service contract), `platform_submission_id` is platform-issued and
treated as an opaque external token. There is no evidence of
collision in the wild, and the platform's API contract presumably
guarantees uniqueness. But the contract is a runtime-trust assumption
with no defense-in-depth in this codebase.

**Recommended fix** (for the future cluster owner):

```php
$product = Product::where('platform_submission_id', $event->trackingId)
    ->where('company_id', $expectedCompanyId)  // derived from payload or upstream context
    ->first();
```

…or extend the `EnrichmentWebhookPayload` DTO to carry the originating
`tenantId` / `companyId` (the platform-side enrichment-submission API
already knows which tenant submitted) and require the listener to
match both predicates.

**Target cluster owner**: `api.platform-integration` (or
`api.product.enrichment-listener` if scope is narrowed). The fix
spans the `EnrichmentWebhookPayload` DTO, `ProcessEnrichmentEventListener`,
and the platform's outbound webhook contract — touching multiple
modules and an external API contract puts it firmly outside the
`api.scheduled-jobs` "classify ShouldQueue Job classes" surface.

**Why deferred**: the `api.scheduled-jobs` cluster's invariant
(constructor-arg + rebind OR @cross-tenant-by-design) is satisfied by
the queue-job class itself (`ProcessEnrichmentWebhookJob` is a thin
re-dispatcher, no DB in handle, cat-(b) annotation justified). The
listener is downstream of the event boundary, not a queue job, and
the fix requires schema/contract changes that exceed the cluster's
"annotate or wrap" mandate.

## Finding B — `MarketplaceSeller::find` unscoped lookup in marketplace fan-out jobs (LOW / defense-in-depth)

**Severity**: LOW (defense-in-depth on UUID-anchored entity lookup)

**Surface**:
- `apps/api/app/Modules/Marketplace/Infrastructure/Jobs/ReconcileListingsJob.php:31`
- `apps/api/app/Modules/Marketplace/Infrastructure/Jobs/SyncSellerListingsJob.php:29`

```php
$seller = MarketplaceSeller::find($this->sellerId);
```

**Issue**: Both Marketplace fan-out jobs (api.scheduled-jobs cat-(b) ##8
and #9 — per-seller fan-out from system-wide schedulers
`marketplace:reconcile` and `marketplace:delta-sync`) open `handle()`
with an unscoped `MarketplaceSeller::find($this->sellerId)`. The seller
is then trusted as the tenant anchor for every downstream `Product` and
`MarketplaceListing` query (`where('company_id', $seller->company_id)`).

The isolation guarantee currently rests on:
1. `MarketplaceSeller` IDs being UUIDs (collision-safe in practice).
2. The `seller->company_id` column being trusted (no row-level audit).
3. The dispatcher (the system-wide scheduler closure in
   `MarketplaceServiceProvider:32-43`) only iterating `MarketplaceSeller::active()`,
   which is itself an unscoped global query.

**Why LOW**: UUID collisions are vanishingly improbable, and the
scheduler runs server-side (no user-input path to inject a foreign
sellerId). But a defense-in-depth helper that re-asserts the
dispatcher's intended tenant against `seller.tenant_id` would convert
"trust the FK" into "verify the FK matches the dispatch context."

**Recommended fix** (for the future cluster owner):

Two complementary improvements:

1. Add `tenantId` to the job constructor signature (mirrors the cat-(a)
   pattern used elsewhere) and dispatch with the seller's tenant from
   the scheduler closure:

   ```php
   MarketplaceSeller::active()->each(function (MarketplaceSeller $seller): void {
       SyncSellerListingsJob::dispatch($seller->id, $seller->tenant_id);
   });
   ```

2. Inside `handle()`, scope the seller lookup to the dispatched
   `tenantId`:

   ```php
   $seller = MarketplaceSeller::where('tenant_id', $this->tenantId)
       ->where('id', $this->sellerId)
       ->first();
   ```

This shifts the classification from cat-(b) (fan-out by-design) to
cat-(a) (per-tenant constructor anchor + rebind), which is the
canonical cluster pattern for jobs that touch per-tenant data.

**Target cluster owner**: `api.marketplace` (or a dedicated
`api.marketplace.seller-jobs` follow-up). The fix touches both
Marketplace job constructors AND the `MarketplaceServiceProvider`
scheduler closures — a Marketplace-module concern, not a queue-job
classification concern.

**Why deferred**: the kickoff brief explicitly anticipated this:
*"For Marketplace/Reconcile/SyncSellerListings: jobs that legitimately
iterate sellers across tenants (marketplace listings are by-design
fan-out) — likely cat-b."* The `api.scheduled-jobs` cluster honored
that classification. Tightening the per-seller lookup to a
defense-in-depth dual-predicate query is a Marketplace-cluster
ergonomics improvement, not a queue-job classification gap.

## Finding C — Queued listeners and notifications outside `Jobs/` directory triplet need their own cluster (REAL SCOPE DISCOVERY)

**Severity**: REAL SCOPE DISCOVERY — this is not a defense-in-depth
nice-to-have, this is a confirmed scope gap. The `api.scheduled-jobs`
cluster's architecture test (Step 6, `QueueJobTenantContextTest`)
deliberately scopes to the `Jobs/` / `Application/Jobs/` /
`Infrastructure/Jobs/` directory triplet because the kickoff brief
enumerates exactly those 9 classes. A project-wide grep for
`implements ShouldQueue` surfaces additional queued classes that live
outside that triplet and are NOT covered by this cluster's invariant.

**Surface** (`grep -rn "implements ShouldQueue" app/`):

| # | Class | Path | Kind |
|---|---|---|---|
| 1 | `LogPartsNeededForProcurement` | `app/Modules/Workshop/WorkOrder/Infrastructure/Listeners/LogPartsNeededForProcurement.php:19` | Queued listener |
| 2 | `EarnPointsOnReceiptCompleted` | `app/Modules/Loyalty/Application/Listeners/EarnPointsOnReceiptCompleted.php:20` | Queued listener |
| 3 | `ApplyStockAdjustmentsOnCountingCompleted` | `app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php:15` | Queued listener |
| 4 | `UserInvitation` | `app/Modules/Identity/Application/Notifications/UserInvitation.php:14` | Queued notification |
| 5 | `VerifyEmailNotification` | `app/Modules/Identity/Application/Notifications/VerifyEmailNotification.php:13` | Queued notification |
| 6 | `PaymentSucceededNotification` | `app/Modules/Billing/Notifications/PaymentSucceededNotification.php:14` | Queued notification |
| 7 | `InvoicePaidNotification` | `app/Modules/Billing/Notifications/InvoicePaidNotification.php:14` | Queued notification |
| 8 | `PaymentFailedNotification` | `app/Modules/Billing/Notifications/PaymentFailedNotification.php:14` | Queued notification |
| 9 | `SubscriptionCancelledNotification` | `app/Modules/Billing/Notifications/SubscriptionCancelledNotification.php:13` | Queued notification |
| 10 | `AdminPaymentAlertNotification` | `app/Modules/Billing/Notifications/AdminPaymentAlertNotification.php:14` | Queued notification |

(Plus any others added since this audit — the `grep -rn "implements ShouldQueue"`
sweep is the canonical discovery query.)

**Issue**: Both queued listeners and queued notifications are
serialized into the queue payload and execute in a worker process
where `CompanyContext` is empty by default — *exactly* the same
runtime hazard that motivates the `api.scheduled-jobs` cluster's
cat-(a)/cat-(b) classification. But:

- **Queued listeners** are dispatched by an event. The event's
  payload is the only carrier of tenant context. Without a per-listener
  classification + invariant, a listener that reads
  `auth()->user()->company_id` inside its `handle()` is silently broken
  on the queue worker.

- **Queued notifications** are dispatched against a notifiable
  (typically a User). The notifiable is serialized as a foreign-key
  reference, so per-recipient context is reconstructable. BUT the
  notification's `toMail` / `toBroadcast` / etc. methods may execute
  arbitrary DB reads, and any unscoped read carries the same
  cross-tenant risk as a job's `handle()`.

**Why this is a REAL scope discovery and not a deferral nicety**: the
queue-job architecture test added in Step 6 of `api.scheduled-jobs`
will explicitly NOT cover these classes (its iterator filters by the
`Jobs/` directory triplet). The hostile-grep blind-spot is therefore
LARGE: 10 queued classes (at this audit) walk through the queue
worker's empty `CompanyContext` without an enforced classification.

**Empirical observation** (a quick spot-check, not exhaustive):
`EarnPointsOnReceiptCompleted` and `ApplyStockAdjustmentsOnCountingCompleted`
both look like per-tenant business-logic listeners that could very
plausibly read tenant-scoped tables. They need the same cat-(a) /
cat-(b) classification the queue jobs got.

**Recommended fix** (for the future cluster owner):

Spin up a follow-up cluster (proposed: `api.queued-listeners` or split
into `api.queued-listeners` + `api.queued-notifications`). Apply the
same machinery:

1. Triage each queued listener / queued notification class.
2. Cat-(a) classes: extend the `BindsTenantContext` trait
   established by `api.scheduled-jobs`. The listener's event payload
   must carry `tenantId` (or be reconstructable from the event's
   anchor entity); the notification's notifiable must be a
   tenant-anchored entity.
3. Cat-(b) classes: annotate with `@cross-tenant-by-design <listener|notification queue invocation: ...>`.
4. Add a sister architecture test — `QueuedListenerTenantContextTest`
   and (separately) `QueuedNotificationTenantContextTest` — that walks
   `app/Modules/*/{Listeners,Application/Listeners,Infrastructure/Listeners}`
   and `app/Modules/*/{Notifications,Application/Notifications,Infrastructure/Notifications}`,
   filters to `implements ShouldQueue`, and applies the same
   classification check.
5. Reuse the deferrals-fixture pattern (one fixture per arch test) so
   future clusters can cleanly defer cross-cluster classes.

**Target cluster owner**: `api.queued-listeners` (proposed name) +
optionally `api.queued-notifications`. Listener concerns and
notification concerns are sibling problems with the same root
diagnosis but different mitigation surfaces (event payload vs.
notifiable serialization), so splitting may be cleaner for ergonomics.

**Why deferred**: the kickoff brief enumerates exactly 9 ShouldQueue
job classes plus 2 scheduler registrations and explicitly defines the
cluster surface as `Jobs/` directories. Expanding to 10 additional
queued classes mid-cluster would either (a) violate the kickoff scope
and require a re-classification + manual-row regeneration, or (b)
under-classify those classes silently. The clean path is to lock
`api.scheduled-jobs` on its declared surface and open a follow-up
cluster for the listener/notification surface.

## Resolution

The `api.scheduled-jobs` cluster closes against:
- 2 cat-(a) callsites (manual rows
  `manual:api.scheduled-jobs:import-process-import-job` and
  `manual:api.scheduled-jobs:import-process-product-image-import`).
- 6 cat-(b) annotations (DailyExpiryCheck, ProcessEnrichmentWebhookJob,
  ExpireReservationsJob, GenerateImageVariants, ReconcileListingsJob,
  SyncSellerListingsJob).
- 1 deferral fixture entry (DispatchAppointmentReminder, locked at
  `api.console-commands.002`).
- New architecture test `QueueJobTenantContextTest` over the `Jobs/`
  directory triplet.

The three findings above are deferred to follow-up clusters as named.

## References

- `api.scheduled-jobs` triage: `docs/superpowers/audits/2026-05-07-api-scheduled-jobs-triage.md`
- `api.console-commands` lock commit (DispatchAppointmentReminder reference): `c5150ec0` (verdict: `206ec0b6`)
- Cross-cluster precedent for the deferral pattern:
  - `docs/superpowers/audits/2026-05-04-taxation-cross-cluster-blind-spots.md`
  - `docs/superpowers/audits/2026-05-04-loyalty-cross-cluster-blind-spots.md`
  - `docs/superpowers/audits/2026-05-06-catalog-pos-cluster-residuals.md`

## Workflow gap: manual-callsites stub file accumulates post-fix entries (2026-05-07)

When a manual inventory row flips to `fixed` (via either the artisan
`sweep:inventory:review` workflow or a one-shot mutate), its corresponding
entry in `docs/superpowers/plans/tenant-isolation-sweep-manual-callsites.yml`
is **NOT auto-removed**. The `ManualScanner` keeps emitting the entry's
`stable_key` from that file on every drift re-scan, and the drift detector
matches that against the now-`fixed` YAML callsite, surfacing it as
`yaml_says_fixed_code_unsafe` despite the underlying code being safe.

### Symptom

`sweep:inventory:status --drift` reports false-positive drift signals that
conflate scanner-mechanism noise with real code regressions. Signal-to-noise
degrades as more clusters lock — every fixed manual stub becomes a permanent
"drift" line until manually scrubbed.

### Today's drift breakdown (after `api.pos-stabilization` cleaned its own-cluster entries at lock time, dropping drift 25 → 9)

- **1 × scanner blind spot on `api.pos-stabilization.012`**
  (`StoreReceiptRequest.php:74` `Rule::exists('modifiers', 'id')->where(closure)`).
  The `PhpPresentationExistsScanner` regex matches the bare `Rule::exists`
  pattern and does not understand closure-based scoping (the closure
  filters by parent `modifier_groups.tenant_id` + `company_id`). The fix
  is structurally sound and Codex-reviewed; the scanner just can't parse
  it. Different shape than the manual-stub gap.
- **6 × `api.compliance` leftover stubs** (`api.compliance.006-.011`).
  Owned by `api.compliance`; their stub-file entries persist post-fix.
- **2 × `api.scheduled-jobs` leftover stubs** (`api.scheduled-jobs.001-.002`).
  Owned by `api.scheduled-jobs`; their stub-file entries persist post-fix
  (they just locked at `3f158018`).

All 9 are owned by other clusters. Each has a documented explanation; none
represent a real regression.

### Recommended fix

Add stub-file cleanup as a post-lock step in the cluster-close cadence.
Two options:

1. **Extend `sweep:inventory:review`** to optionally remove the
   corresponding entry from `tenant-isolation-sweep-manual-callsites.yml`
   when a manual row flips to `fixed`. This makes the cleanup automatic
   and atomic with the verdict mutation.
2. **Add a post-lock chore commit pattern** to the cluster-close runbook:
   "After Codex APPROVE flips your cluster's manual rows to fixed, also
   remove their entries from the manual-callsites stub file." This keeps
   the workflow tooling unchanged but requires discipline at lock time.

### Severity

**LOW** — cosmetic. Doesn't affect correctness; the underlying fixes are
real and reviewer-approved. The risk is signal degradation: a real
regression eventually hides among noise.

### Target

Workflow tooling micro-chore or sweep-runbook update.

### Note

`api.pos-stabilization` sets the precedent of cleaning its own-cluster
manual stubs at lock time (lock commit removes 16 entries spanning Group 4
LoyaltyPOS, round-2 Findings 1-3, round-3 Findings 1-4, round-4 finding,
round-5 closures). Future clusters should follow this pattern until
option 1 above is implemented.
