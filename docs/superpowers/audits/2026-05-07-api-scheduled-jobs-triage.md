# API Scheduled Jobs Cluster — Triage

**Date:** 2026-05-07
**Cluster ID:** `api.scheduled-jobs`
**Branch:** `feat/tenant-isolation-sweep-execution`
**HEAD at triage:** `c5150ec0` (console-commands lock)
**Inventory baseline:** 1530 events / 321 callsites (verify-history clean)

## Cluster invariant (master plan §14 invariant 2 + Codex Section 18 note)

Queue jobs and scheduled tasks legitimately run outside HTTP middleware, so
`CompanyContext` is empty by default. Every concrete `ShouldQueue` class
under `app/Modules/*/{Jobs,Application/Jobs,Infrastructure/Jobs}` MUST
EITHER:

- **(a)** Carry tenant context as a constructor argument (or an equivalent
  payload field), and rebind via
  `Tenant::find($this->tenantId)?->run(fn () => ...)` at the start of
  `handle()` *before any DB access*. Reference shape:
  `app/Modules/Scheduling/Infrastructure/Jobs/DispatchAppointmentReminder.php`
  (alternative manual-scoping pattern; see "Reference shape variance"
  below).
- **(b)** Be annotated `@cross-tenant-by-design <non-empty justification>`
  at the class level. Acceptable cases: jobs that operate on sweep /
  inventory infrastructure, jobs that explicitly iterate `Company::all()`
  as part of their purpose, fan-out dispatchers that re-dispatch
  per-tenant child jobs.

## Reference shape variance

`DispatchAppointmentReminder` (locked under `api.console-commands.002` at
commit `99b6f5aa`) uses the **manual per-query scoping** variant of
cat-(a): constructor carries `tenantId`; every `where()` clause inside
`handle()` re-asserts `where('tenant_id', $this->tenantId)`. This is
functionally equivalent to the trait-based `Tenant::find()->run()`
pattern but keeps the binding visible at every query site.

Per kickoff prompt: **do NOT refactor `DispatchAppointmentReminder` to
use the new trait** — that would touch a freshly-locked
`api.console-commands` callsite. Instead, defer it via the architecture
test's deferrals fixture. Trait extraction across the legacy class is
future-cluster cleanup.

For all *new* cat-(a) work in this cluster, the canonical pattern is the
trait-based `withTenantContext()` wrapper. Both shapes are tenant-safe;
the trait approach merely centralizes the binding logic.

## Inventory of in-scope classes

The kickoff prompt enumerates 9 `ShouldQueue` job classes in the three
canonical job directories, plus 2 scheduler registrations. A
project-wide grep for `implements ShouldQueue` confirms an additional
group of queued listeners and queued notifications also exist
(`Loyalty/Application/Listeners/EarnPointsOnReceiptCompleted`,
`Workshop/WorkOrder/Infrastructure/Listeners/LogPartsNeededForProcurement`,
`Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted`,
`Identity/Application/Notifications/*`, `Billing/Notifications/*`).
Those live outside the `Jobs/` / `Application/Jobs/` /
`Infrastructure/Jobs/` directory triplet and are therefore **out of scope**
for this cluster's architecture test (different mounting point, different
classification rules — listener queueing is anchored on the dispatching
event's tenant context; notifications carry their own notifiable). Any
cross-tenant gap there will surface in a future listener/notification
cluster.

## Job-by-job classification

| # | Class | Constructor anchor | DB access in `handle()` | Classification |
|---|---|---|---|---|
| 1 | `Import\Application\Jobs\ProcessImportJob` | `importJobId, companyId` (no `tenantId`) | YES — `ImportJob::find`, `Company::find`, `getValidRows`, per-row write, `importSingleRow`, `failJob` | **cat-(a)** |
| 2 | `Import\Application\Jobs\ProcessProductImageImport` | `importJobId, zipPath` (no tenant payload) | YES — `ImportJob::find`, `processZipImport`, `addRowsBatch` | **cat-(a)** |
| 3 | `BatchExpiry\Jobs\DailyExpiryCheck` | none | YES — `Batch::where(...)` global iteration; per-company notification dispatch | **cat-(b)** |
| 4 | `Scheduling\Infrastructure\Jobs\DispatchAppointmentReminder` | `reminderId, tenantId` | YES — manually re-scoped via `where('tenant_id', $this->tenantId)` everywhere | **cat-(a) — DEFERRED** (locked at `api.console-commands.002`) |
| 5 | `PlatformIntegration\Application\Jobs\ProcessEnrichmentWebhookJob` | `EnrichmentWebhookPayload` (carries `trackingId`, not tenant) | NO — only re-dispatches `EnrichmentWebhookReceived` event | **cat-(b)** |
| 6 | `Inventory\Application\Jobs\ExpireReservationsJob` | none | NO direct — delegates to `StockReservationService::expireReservations()` which iterates `StockReservation::expired()` globally | **cat-(b)** |
| 7 | `Product\Application\Jobs\GenerateImageVariants` | `imageId, storagePath, storageDisk` | NO DB — pure storage transformation (read original, write variants via `Storage` facade) | **cat-(b)** |
| 8 | `Marketplace\Infrastructure\Jobs\ReconcileListingsJob` | `sellerId` | YES — `MarketplaceSeller::find` (unscoped); downstream `Product`/`MarketplaceListing` queries explicitly filtered by `seller.company_id` | **cat-(b)** |
| 9 | `Marketplace\Infrastructure\Jobs\SyncSellerListingsJob` | `sellerId` | YES — same shape as #8 | **cat-(b)** |

**Counts:** 2 cat-(a) requiring code changes + manual rows · 6 cat-(b)
requiring annotation only · 1 cat-(a) deferred via fixture.

## Per-job rationale

### #1 — `ProcessImportJob` → cat-(a)

**Why cat-(a).** Constructor carries `companyId` but **not** `tenantId`.
The `handle()` body opens with `ImportJob::find($this->importJobId)` —
an unscoped UUID lookup. A malicious or buggy dispatcher could pass a
job ID belonging to a different tenant, and the worker would
unconditionally process it. Same hazard for `Company::find($this->companyId)`.
Inside `processImport`, `$importService->getValidRows($job)` reads
import rows that the row's per-job FK chains back to the (now possibly
wrong) tenant; `$importService->importSingleRow($job, $row, $this->companyId)`
writes per-row entities scoped to that companyId. None of this is
defended by an explicit tenant guard.

**Dispatcher.** `ImportController:435` —
`ProcessImportJob::dispatch($job->id, $companyId);`. The controller is
request-scoped, so `CompanyContext::tenantId()` is available at
dispatch. Adding a third constructor argument `string $tenantId` is
mechanical.

**Fix shape.** Add `string $tenantId` to constructor; wrap the entire
`handle()` body (except the initial `find`, which we'll re-key under
the trait) in `$this->withTenantContext(fn () => ...)`. The trait's
`withTenantContext()` internally calls `Tenant::find($this->tenantId)?->run($fn)`
so subsequent `ImportJob::find()` and `Company::find()` will be tenant-scoped
via the global `CompanyContext` once any model ever adds a global tenant scope.
For the immediate isolation guarantee (regardless of future scopes), the
binding makes `CompanyContext::tenantId()` resolvable for the duration of
`handle()`, which any per-tenant code path relies on.

**Manual row.** `manual:api.scheduled-jobs:process-import-job`.

### #2 — `ProcessProductImageImport` → cat-(a)

**Why cat-(a).** Constructor carries `importJobId, zipPath` — no tenant
payload at all. `handle()` opens with the same unscoped
`ImportJob::find($this->importJobId)`. Subsequent calls into
`ProductImageImportService::processZipImport()` and
`ImportService::addRowsBatch()` write tenant-derived data without any
tenant binding.

**Dispatcher.** `ImportController:555` —
`ProcessProductImageImport::dispatch($job->id, $fullPath);`. Same
request-scoped context as #1. Add `string $tenantId` constructor arg.

**Fix shape.** Same as #1: add tenantId, wrap `handle()` in
`$this->withTenantContext()`.

**Manual row.** `manual:api.scheduled-jobs:process-product-image-import`.

### #3 — `DailyExpiryCheck` → cat-(b)

**Why cat-(b).** Registered in `routes/console.php:33` —
`Schedule::job(DailyExpiryCheck::class)->dailyAt('01:30')->withoutOverlapping();`.
This is a **system-wide daily sweep** by design. `markExpiredBatches()`
iterates `Batch::where('expiry_date', '<', $today)->where('is_expired', false)->where('is_active', true)` globally
across every tenant. Per-row updates carry their batch's existing
`company_id` via the row, so writes are tenant-correct even though the
read is global. The downstream notification path uses an explicit
`User::whereRaw('company_id = ?', [$companyId])->permission(...)` filter
keyed on each batch's `company_id`, so notifications fan out correctly
per tenant.

**Annotation justification.** `Daily system-wide batch expiry sweep
queue job — iterates Batch rows across all tenants by design; per-row
writes inherit company_id from the row, per-company notifications use
explicit company_id filter.`

### #4 — `DispatchAppointmentReminder` → cat-(a) DEFERRED

**Why deferred.** This class was already classified, fixed, and locked
under cluster `api.console-commands` (callsite key
`api.console-commands.002`) at commit `99b6f5aa` /
verdict commit `206ec0b6`. Per kickoff prompt: do not refactor it to
use the new trait — it would touch a freshly-locked callsite. Verify
its shape conforms (it does — constructor carries `tenantId`; all
`AppointmentReminder` and `Appointment` queries inside `handle()`
re-assert `where('tenant_id', $this->tenantId)`).

**Architecture test handling.** Add to
`tests/Architecture/fixtures/queue-job-deferrals.json` with reason
"Locked by api.console-commands cluster (callsite api.console-commands.002);
manual per-query tenant scoping pattern intentionally retained — trait
extraction deferred to future cluster."

**No new manual row.** Already represented in the inventory under the
console-commands cluster.

### #5 — `ProcessEnrichmentWebhookJob` → cat-(b)

**Why cat-(b).** Pure event re-dispatcher:

```php
public function handle(): void
{
    EnrichmentWebhookReceived::dispatch(
        $this->payload->trackingId,
        $this->payload->status,
        $this->payload->enrichmentQuality,
        $this->payload->hasBarcodeAssigned,
        $this->payload->vertical,
    );
}
```

No DB access whatsoever inside `handle()`. The downstream listener
`Product\Application\Listeners\ProcessEnrichmentEventListener` resolves
`Product::where('platform_submission_id', $event->trackingId)->first()`
using the **globally-unique** platform-issued tracking ID, then operates
on that Product (whose `company_id` chains back to the correct tenant).

**Webhook entry context.** `EnrichmentWebhookController` is the public
endpoint a third-party platform calls — it is intentionally
tenant-agnostic at entry. Tenant resolution happens via the
`platform_submission_id → Product → company_id` chain inside the
listener, which is the correct boundary.

**Annotation justification.** `Webhook re-dispatcher queue job —
handle() does no DB access; downstream listener resolves tenant from
the globally-unique platform_submission_id encoded in the payload's
trackingId.`

**Open observation (NOT this cluster's scope).** The listener's
isolation depends on `platform_submission_id` being globally unique; if
two tenants could ever submit the same tracking ID, the listener would
mis-route. This is a contract-with-the-platform concern surfaced in the
audit but belongs to a future PlatformIntegration cluster.

### #6 — `ExpireReservationsJob` → cat-(b)

**Why cat-(b).** Registered in `routes/console.php:28` —
`Schedule::job(ExpireReservationsJob::class)->everyFifteenMinutes()->withoutOverlapping();`.
A **system-wide every-15-min sweep**. The job's `handle()` delegates to
`StockReservationService::expireReservations()` which iterates
`StockReservation::expired()->get()` across all tenants. Each
expired-reservation update is wrapped in `DB::transaction` and operates
on rows keyed by the reservation's own `batch_id` / `product_id` /
`location_id` FKs — so writes inherit the reservation's tenant. The
post-commit `ReservationExpired` event includes `companyId` derived
from the reservation row.

`StockReservation` carries no global tenant scope (verified — model has
`company_id` in `$fillable` but no `addGlobalScope` in `booted()` and
no `TenantScope`/`CompanyScope` use), so the global `::expired()` query
returns rows from every tenant by design.

**Annotation justification.** `Scheduled system-wide stock-reservation
expiry sweep queue job — delegates to StockReservationService::expireReservations()
which iterates expired reservations across all tenants by design; per-row
writes carry company_id via the reservation FK chain.`

### #7 — `GenerateImageVariants` → cat-(b)

**Why cat-(b).** Pure storage transformation. `handle()` reads the
original from `Storage::disk($this->storageDisk)`, generates WebP
variants at multiple widths, writes them back to derived storage paths
via `ImageVariantService::variantPath()`. Zero DB queries. Tenant
isolation is enforced upstream by the storage path's structure (the
dispatcher is responsible for handing in a tenant-anchored path; the
job has no DB to leak through).

**Annotation justification.** `Pure storage transformation queue job —
generates WebP variants from a tenant-anchored storage path; no DB
queries in handle(); tenant isolation is anchored by the upstream
storage path's tenant-scoped structure.`

### #8 — `ReconcileListingsJob` → cat-(b)

**Why cat-(b).** Registered as a per-seller fan-out from a system-wide
scheduler closure: `MarketplaceServiceProvider::boot()` registers
`marketplace:reconcile` which iterates `MarketplaceSeller::active()`
globally and dispatches one `ReconcileListingsJob` per seller. The
per-seller job opens with `MarketplaceSeller::find($this->sellerId)` —
unscoped, but `MarketplaceSeller` IDs are UUIDs and the seller's own
`company_id` is the trust anchor for every downstream query. All
subsequent `Product` and `MarketplaceListing` queries explicitly filter
by `where('company_id', $seller->company_id)` (`Product::where('company_id', $seller->company_id)->chunk(100, ...)`,
`MarketplaceListing::where('seller_id', $seller->id)->...->update(...)`).

The fan-out + per-seller-explicit-filter pattern is the canonical
cat-(b) shape per the kickoff prompt: *"Marketplace/Reconcile/SyncSellerListings:
jobs that legitimately iterate sellers across tenants (marketplace
listings are by-design fan-out) — likely cat-b."*

**Annotation justification.** `Per-seller fan-out queue job from
system-wide marketplace:reconcile scheduler — unscoped MarketplaceSeller::find
is gated by globally-unique seller UUID; downstream Product and
MarketplaceListing queries explicitly filter by seller.company_id.`

### #9 — `SyncSellerListingsJob` → cat-(b)

Same shape as #8. Registered as a per-seller fan-out from the
`marketplace:delta-sync` scheduler closure
(`MarketplaceServiceProvider:36`). Constructor takes only `sellerId`.
`Product::where('company_id', $seller->company_id)->where('is_active', true)->where('is_physical', true)`
is the explicit per-seller filter.

**Annotation justification.** `Per-seller fan-out queue job from
system-wide marketplace:delta-sync scheduler — unscoped MarketplaceSeller::find
is gated by globally-unique seller UUID; downstream Product queries
explicitly filter by seller.company_id.`

## Scheduler-only registrations

### #10 — `MarketplaceServiceProvider:36` (`marketplace:delta-sync` cron)

System-wide closure that iterates `MarketplaceSeller::active()` and
dispatches one `SyncSellerListingsJob` per seller. The closure itself
is not a `ShouldQueue` class, so it is **out of scope for the queue-job
architecture test**. The underlying job (#9) is annotated cat-(b),
which is the correct anchor.

A future architecture test that walks scheduler registrations could
audit closures themselves, but that is not in this cluster's scope.

### #11 — `HeldOrderServiceProvider:40` (`pos:expire-held-orders` everyFifteenMinutes)

Already locked under `api.pos-stabilization` cluster. The underlying
command `ExpireHeldOrdersCommand` extends `TenantScopedCommand` and
uses `forEachTenant()` to iterate tenants one at a time. Verified: no
new work needed.

## Summary

- **Cat-(a) needing code + manual rows:** 2 callsites
  - `manual:api.scheduled-jobs:process-import-job`
  - `manual:api.scheduled-jobs:process-product-image-import`
- **Cat-(b) needing annotation only:** 6 classes
  - `DailyExpiryCheck`
  - `ProcessEnrichmentWebhookJob`
  - `ExpireReservationsJob`
  - `GenerateImageVariants`
  - `ReconcileListingsJob`
  - `SyncSellerListingsJob`
- **Deferred via architecture-test fixture:** 1 class
  - `DispatchAppointmentReminder` (locked at `api.console-commands.002`)
- **Out-of-scope verified:** 2 scheduler registrations
  - `marketplace:delta-sync` closure (anchored on cat-(b) job #9)
  - `pos:expire-held-orders` (locked under POS cluster)

**Expected post-mutation counts:** baseline 1530 events / 321 callsites
+ 2 cat-(a) callsites + 2 history events ⇒ 1532 events / 323 callsites.

## Open observations (NOT in this cluster's scope)

1. **`platform_submission_id` global uniqueness contract.**
   `ProcessEnrichmentEventListener` resolves Product by
   `platform_submission_id` without a tenant guard; if two tenants
   could ever submit the same tracking ID, the listener would
   mis-route. Surface to a future PlatformIntegration cluster.

2. **`MarketplaceSeller::find` unscoped lookup.** Reconcile/Sync jobs
   look up seller by UUID without a tenant guard. UUIDs are
   collision-safe in practice, but a defense-in-depth seller-resolve
   helper that re-asserts the dispatcher's tenant would harden this. Surface
   to a future Marketplace cluster.

3. **Listener and notification ShouldQueue classes** (`Loyalty`,
   `Workshop`, `Inventory.Listeners`, `Identity.Notifications`,
   `Billing.Notifications`) live outside the `Jobs/` directory triplet
   and are therefore not covered by this cluster's architecture test.
   Future listener/notification cluster needed.

## Step 2 plan (BLOCKED on user approval)

After approval:

1. Append two manual rows to
   `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` under
   `cluster_id: api.scheduled-jobs`:
   - `manual:api.scheduled-jobs:process-import-job`
   - `manual:api.scheduled-jobs:process-product-image-import`
2. Append two history events for those manual rows in the same mutate
   cycle. Verify-history should report exactly +2 events / +2
   callsites.
3. Claim cluster + transition to `started` state.
4. Commit:
   `chore(scheduled-jobs): seed manual rows + claim/start cluster`
