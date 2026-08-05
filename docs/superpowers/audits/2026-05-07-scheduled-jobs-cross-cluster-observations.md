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

> **2026-08-05 — ERP HALF DONE, PLATFORM HALF PENDING (cat-(b) conversion wave 1).**
> The recommendation this finding closes on — "extend the `EnrichmentWebhookPayload` DTO to carry
> the originating `tenantId` … (the platform-side enrichment-submission API already knows which
> tenant submitted)" — is IMPLEMENTED on the ERP side, because the database-per-tenant flip turned
> it from defence-in-depth into a hard requirement. `EnrichmentWebhookPayload::$tenantId` exists,
> `ProcessEnrichmentWebhookJob` rebinds it via `BindsTenantContext`, and the listener now FAILS
> CLOSED when no tenant is bound instead of dying on a 42P01.
>
> Once the anchor is present the `->sole()` runs inside ONE tenant's database, so a cross-tenant
> `platform_submission_id` collision stops being reachable at all — which is a stronger outcome than
> the `where('company_id', …)` predicate this finding proposed, and needs no extra predicate.
>
> **Still open:** the platform does not send `tenant_id` yet. Until it does, every webhook takes the
> discard path and `enrichment:check-pending` resolves the submission per tenant instead. Platform
> contract + draft REALIGNMENT-LOG entry:
> `docs/superpowers/tickets/2026-08-05-enrichment-webhook-platform-contract.md`.

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

> **CLOSED 2026-08-05 — recommendation 1 ADOPTED, recommendation 2 REJECTED.**
> `SyncSellerListingsJob` and `ReconcileListingsJob` now take
> `public readonly string $tenantId` and wrap `handle()` in
> `BindsTenantContext::withTenantContext()`; both classes dropped their
> `@cross-tenant-by-design` tag and are now cat-(a). `MarketplaceDeltaSyncCommand`
> / `MarketplaceReconcileCommand` dispatch with the **ITERATING** tenant's id
> (`$tenant->id`), NOT `$seller->tenant_id` as this section's snippet suggested —
> `marketplace_sellers.tenant_id` is NULLABLE and external / Synerivia-owned
> sellers carry NULL, so `$seller->tenant_id` would dispatch a NULL anchor for
> exactly those rows. For the same reason recommendation 2 (scoping the seller
> lookup to `where('tenant_id', $this->tenantId)`) was **NOT** implemented: it
> would silently drop every external seller. Under database-per-tenant the seller
> table is physically isolated inside the bound tenant database, so the bind — not
> a WHERE clause — is the isolation boundary. Pinned by
> `tests/Feature/Marketplace/MarketplaceListingJobsTenantContextTest.php`
> (binding + fail-loud + the external-seller leg) and by the `tenantId` payload
> assertions in `tests/Feature/Marketplace/MarketplaceScheduledCommandsTest.php`.
> The MarketplaceListing `company_id` column remains a genuine open follow-up.

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
  - **2026-08-04 addendum.** `DailyExpiryCheck` and `ExpireReservationsJob` were
    converted to `TenantScopedCommand`s (`batch-expiry:daily-check`,
    `inventory:expire-reservations`) and DELETED — their cat-(b) justification
    assumed row-level tenancy and broke at the 2026-05-28 database-per-tenant
    flip. `ReconcileListingsJob` / `SyncSellerListingsJob` KEEP the cat-(b) tag
    but their justification text was rewritten: the two `marketplace:delta-sync`
    / `marketplace:reconcile` scheduler CLOSURES that used to fan them out from
    central context are now `TenantScopedCommand`s, so both jobs are dispatched
    under initialized tenancy and QueueTenancyBootstrapper stamps the tenant
    onto the payload. Finding B (BindsTenantContext hardening on the two
    marketplace jobs) remained the only defence-in-depth gap left on that pair.
  - **2026-08-05 addendum.** Finding B is CLOSED: `ReconcileListingsJob` /
    `SyncSellerListingsJob` DROPPED the cat-(b) tag, adopted
    `BindsTenantContext` with an explicit `tenantId`, and are now cat-(a). The
    cat-(b) annotation count for this cluster is therefore 4, not 6.
  - **2026-08-05 addendum #2 (cat-(b) conversion wave 1).**
    `ProcessEnrichmentWebhookJob` DROPPED its cat-(b) justification's central
    claim and adopted `BindsTenantContext`. Its old text said tenant resolution
    "chains through `platform_submission_id` → `Product` → `company_id`" — that
    chain's FIRST hop is a tenant-table read, so post-flip it cannot start: the
    unauthenticated webhook dispatches under central context and the synchronous
    `ProcessEnrichmentEventListener` raised a 42P01 `QueryException` its
    `catch (ModelNotFoundException)` never caught. The anchor now travels on
    `EnrichmentWebhookPayload::$tenantId`; an anchorless payload is DISCARDED
    (never processed under central) and `enrichment:check-pending` re-resolves
    it per tenant within 15 minutes. **This makes Finding A resolvable for free**
    once the platform echoes `tenant_id` back — see
    `docs/superpowers/tickets/2026-08-05-enrichment-webhook-platform-contract.md`.
    Remaining cat-(b) annotations for this cluster: 3
    (`GenerateImageVariants` + the two verified-safe entries).
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

## api.webhooks-incoming triage deferrals (2026-05-07)

Surfaced during `api.webhooks-incoming` cluster triage (see
`2026-05-07-api-webhooks-incoming-triage.md`). All four findings are
out-of-scope for the webhooks-incoming cluster's invariant (verify
signature → resolve tenant → bind context → defense-in-depth on a
controller-by-controller basis); they are tracked here for the named
target clusters to absorb. None is an exploitable cross-tenant data
leak in the systems audited; severity reflects either a logic-correctness
issue (D), a feature gap that becomes a security gap if amplified by
volume (E), a defense-in-depth gap covered by an external contract (F),
or a load-bearing future-modification blocker (G).

### Finding D — `StripeWebhookController::handleSubscriptionCreated` `orWhere('stripe_customer_id', …)` fallback (LOW / logic-correctness, not tenant-isolation)

**Severity**: LOW (functional, not security)

**Surface**: `apps/api/app/Modules/Billing/Presentation/Controllers/StripeWebhookController.php:100-103`

```php
$subscription = TenantSubscription::where('stripe_subscription_id', $stripeSubId)
    ->orWhere('stripe_customer_id', $stripeCustomerId)
    ->first();
```

**Issue**: The `orWhere` fallback resolves a subscription via the Stripe
customer ID when the subscription ID does not match. A Stripe customer
can hold multiple subscriptions in our DB (one per Synerivia plan tier,
or post-cancellation rows kept for audit), so the fallback can pick the
wrong row. The subsequent `$subscription->update(['stripe_subscription_id' => $stripeSubId, …])`
overwrites the matched row's stripe-subscription-id with the *new*
event's id, mis-attributing future events to the wrong subscription.

**Why LOW (not a tenant-isolation issue)**: a single Stripe customer is
1:1 with a Synerivia tenant by design (one billing account per tenant).
Multiple subscriptions on that customer all carry the same `tenant_id`,
so the cross-tenant invariant is preserved even on the wrong-row match.
The bug is logic-correctness on subscription state.

**Recommended fix** (for the future cluster owner): drop the `orWhere`
fallback. If `stripe_subscription_id` is the carrier-of-truth (which it
is for `customer.subscription.created` events — Stripe always populates
it on this event), match strictly on it. If no row matches, log a
warning and exit (the upstream `subscribed` flow should have
pre-created the row).

**Target cluster owner**: future `api.billing` hardening cluster (no
existing cluster covers Billing module logic per the master plan
inventory).

**Why deferred**: the `api.webhooks-incoming` cluster's invariant is
"verify signature → resolve tenant → bind context → defense-in-depth."
This finding does not violate that invariant — tenant resolution is
correct; the bug is in *which subscription row gets updated within the
correct tenant*. The fix lives in Billing-domain logic, not in webhook
plumbing.

### Finding E — `StripeWebhookController` missing event-id idempotency (MEDIUM / functional, not tenant-isolation)

**Severity**: MEDIUM (functional, not security)

**Surface**: `apps/api/app/Modules/Billing/Presentation/Controllers/StripeWebhookController.php` (entire `handle()` method, lines 35-88)

**Issue**: Stripe webhooks are at-least-once delivery — Stripe redelivers
events on any 5xx response, on dashboard "resend," and on signed-payload
retry. The controller has no `event_id` deduplication table; the same
`payment_intent.succeeded` or `charge.refunded` event will be processed
twice, causing:
- Double-stamped `paid_at` timestamps
- Double notifications (`InvoicePaidNotification`, `AdminPaymentAlertNotification`)
- Stale state if a redelivery races with a manual admin-side state change
- For refunds specifically, `$payment->refunded_amount` overwrites instead
  of accumulates (idempotent in numeric terms — same value re-stamped —
  but the `refunded_at` timestamp drifts)

**Why MEDIUM (not a tenant-isolation issue)**: idempotency is a
within-tenant concern. The duplicate event still resolves to the same
tenant via the same `stripe_subscription_id` / `stripe_invoice_id` /
`provider_payment_id`. The cross-tenant invariant is preserved.

**Recommended fix** (for the future cluster owner): introduce a
`stripe_webhook_events` dedup table keyed on `event_id` (Stripe's `evt_*`
identifier on the wrapper, NOT the resource id). On every event, attempt
an INSERT IGNORE / `INSERT … ON CONFLICT DO NOTHING`; if a row already
exists for that `event_id`, return 200 immediately without processing.
The dedup window can be 30 days (Stripe's redelivery window) with a TTL
sweep.

**Target cluster owner**: future `api.billing` hardening cluster.

**Why deferred**: same reason as Finding D — within-tenant correctness
issue, not webhook-tenant-binding. Adding event-id dedup is a Billing
schema change that touches migrations, not the webhooks-incoming
controller-discipline invariant.

### Finding F — `billing_payments.provider_payment_id` is composite-indexed but NOT UNIQUE (LOW / defense-in-depth)

**Severity**: LOW (defense-in-depth; no observed contract violation)

**Surface**: `apps/api/database/migrations/2025_12_16_100004_create_billing_payments_table.php:81`

```php
$table->index(['provider', 'provider_payment_id']);
```

**Issue**: The `(provider, provider_payment_id)` pair is indexed for
read performance but does not have a UNIQUE constraint. Two
`StripeWebhookController` callsites depend on the column being globally
unique:
- `Payment::where('provider_payment_id', $paymentIntentId)->first()`
  (handlePaymentIntentSucceeded:415, handlePaymentIntentFailed:439,
  handleChargeRefunded:467)
- `Payment::updateOrCreate(['provider_payment_id' => $paymentIntentId], …)`
  (handleInvoicePaid:243, handleInvoicePaymentFailed:319)

The uniqueness guarantee rests on Stripe's external contract that
`pi_*` IDs are globally unique. The DB does not enforce it. If two
tenants somehow ended up with the same `provider_payment_id` in
`billing_payments` (e.g., a future migration that imports historic data
without dedup, an admin tool that copies rows, a CSV-import tool that
trusts user-supplied values), `->first()` would silently pick one — the
canonical "first row matched without scope" leak shape.

**Why LOW**: the contract holds in the wild today. Stripe does not reuse
payment-intent IDs across tenants because Synerivia uses a single Stripe
account. The risk is amplified only by future schema changes that break
the assumption.

**Recommended fix** (for the future cluster owner): add a partial UNIQUE
constraint:

```php
$table->unique(['provider', 'provider_payment_id'], 'billing_payments_provider_id_unique');
```

…with a one-time data-cleanup migration that removes any pre-existing
duplicates (none expected today). Joins this finding with **Finding A**
(`platform_submission_id` global-uniqueness contract risk in
`ProcessEnrichmentEventListener`) under the same external-contract-trust
pattern.

**Target cluster owner**: `api.platform-integration` (carrying Finding A
already) **OR** a unified `api.external-id-uniqueness` cluster covering
both Finding A and this finding. Author's recommendation is the unified
cluster — both findings share the same defense-in-depth shape and the
fix is one schema migration each.

**Why deferred**: the `api.webhooks-incoming` cluster's controller-layer
invariant does not regulate DB schema constraints. This finding's
mitigation is a migration plus optionally a defense-in-depth filter on
the controller's `Payment::where(…)` lookups (which would be
tautological today — the resolved row's `tenant_id` cannot disagree
with itself — but would catch the corruption shape if a future row
managed to get into `billing_payments` with a duplicate id).

### Finding G — `PurchaseHubWebhookController` route lacks signature-verification middleware (HIGH future-state, LOW current-state stub)

**Severity**: HIGH if any DB / Bus / Event / Queue / Notification call
is added to the controller body, LOW today (controller is a verifiable
stub: `Log::info` + `200 OK`).

**Surface**:
- Controller: `apps/api/app/Modules/PurchaseHub/Presentation/Controllers/PurchaseHubWebhookController.php` (whole file, 25 LOC)
- Route: `apps/api/app/Modules/PurchaseHub/Presentation/routes.php:22-26`

```php
Route::prefix('api/webhooks')
    ->middleware(['api'])
    ->group(function () {
        Route::post('purchase-hub', PurchaseHubWebhookController::class);
    });
```

**Issue**: The route registers under `['api']` middleware only — no
signature-verification middleware (no `VerifySynerivaWebhookSignature`
analog exists for PurchaseHub), no `auth:sanctum`, no rate limit beyond
the `api` group's defaults. Today this is **acceptable** because the
controller body performs ZERO DB access, ZERO `Bus::dispatch`, ZERO
`Event::dispatch`, ZERO `Queue::push`, ZERO `Notification::send` — it
logs the event name and returns `{"status": "received"}`. The endpoint
is a noop.

The hint comment (`// Future: handle order.receipt_confirmed to
auto-create PO in ERP`) signals this stub WILL grow into a real handler.
If a developer later adds DB writes or job dispatches without first
wiring a signature middleware AND a tenant-resolution step, the result
is an unauthenticated cross-tenant write surface — a textbook external-
ingress leak.

**Why current-state LOW**: zero side-effects today. Verifiable by
reading the 25-LOC file end-to-end. The architecture test added in this
cluster (`WebhookControllerTenantContextTest`) enforces the stub shape
via reflection-based body inspection — any forbidden call pattern in
the controller body fails the test until the prerequisites below are
met.

**Why future-state HIGH**: external attackers can post arbitrary JSON
to the route today. The day a developer adds `OrderReceipt::create([…])`
inside `__invoke()`, that payload becomes unauthenticated cross-tenant
write capability.

**Recommended fix** (for the future cluster owner, BEFORE adding any
side-effect to the controller):

1. Mirror the Syneriva pattern: introduce
   `apps/api/app/Modules/PurchaseHub/Infrastructure/Middleware/VerifyPurchaseHubWebhookSignature.php`
   with HMAC-SHA256 over `timestamp.body`, 5-min freshness, constant-time
   `hash_equals`, dedicated `PURCHASE_HUB_WEBHOOK_SECRET` env var. Adapt
   the HMAC scheme to whatever PurchaseHub's outbound contract specifies
   (per-tenant secret or globally pinned).
2. Wire the middleware into `routes.php:22` so it runs before
   `__invoke()`.
3. Implement explicit tenant resolution from the verified payload
   following one of master plan §8 shapes (a/b/c).
4. Remove the stub-shape body-inspection guard from
   `WebhookControllerTenantContextTest` for this controller; replace it
   with a marker-interface (`WebhookController`) implementation that
   asserts the new tenant-resolution path.
5. Add a per-controller manual inventory row promoting this entry from
   cat-(b)/stub to cat-(a), with a regression test in
   `tests/Feature/Webhooks/`.

**Target cluster owner**: `api.purchase-hub` when the stub is fleshed
out, **OR** the architecture test catches the stub-violation transition
first (preferred — the test fires on any commit that adds a forbidden
call pattern, surfacing the issue at PR time rather than post-incident).

**Why deferred**: today's stub satisfies the cluster invariant
(no per-tenant operations performed). Pre-emptively wiring a signature
middleware that handles a payload-shape we don't have yet would be
speculative engineering. The architecture test is the load-bearing
guard — it converts "forgot to add signature middleware" from a silent
runtime risk into a deterministic CI failure.

## Resolution

The `api.webhooks-incoming` cluster closes against:
- 3 cat-(b) annotations (StripeWebhookController, EnrichmentWebhookController, PurchaseHubWebhookController), each with class-level `@cross-tenant-by-design <justification>`.
- New architecture test `WebhookControllerTenantContextTest` over the discovered webhook controller surface.
- Zero cat-(a) callsites (no controller-body code change required).

Findings D-G above are deferred to follow-up clusters as named.

## References

- `api.webhooks-incoming` triage: `docs/superpowers/audits/2026-05-07-api-webhooks-incoming-triage.md`
- Cross-cluster precedent for the deferral pattern (this same doc, Findings A-C): `api.scheduled-jobs` cluster triage `docs/superpowers/audits/2026-05-07-api-scheduled-jobs-triage.md`

---

## Static analyzer on closure bodies has unbounded attack surface (api.broadcast-channels round-1/2/3 trajectory, 2026-05-08)

**What happened**: Three rounds of Codex adversarial review surfaced
three distinct bypass classes against the PhpParser-based static
analyzer over `Broadcast::channel(...)` closures in
`apps/api/routes/channels.php`:

  - Round 1 (BLOCKER): `@cross-tenant-anchored` PHPDoc could substitute
    for the helper-call requirement. Closed structurally — annotation
    became documentation-only on tenant-named channels.
  - Round 2 (BLOCKERs ×2):
    - Control-flow blindness: `if (false) { return $user->canAccess...(); } return true;`
      passed because the helper-call existed somewhere in the AST.
      Closed by walking every `Return_` and requiring helper-or-denial.
    - Single-line bare-annotation regex captured the closing comment
      delimiter as non-empty justification. Closed by stripping
      docblock decorators before regex matching.
  - Round 3 (BLOCK-NOVEL): variable reassignment to an anonymous-class
    instance whose method returns a `Generator` (truthy, non-strict-false).
    Laravel's `Broadcaster::verifyUserCanAccessChannel()` accepts any
    truthy return. PhpParser cannot do data-flow / type-narrowing across
    the assignment. **Not closeable in pure static analysis.**

**Pivot applied** (per round-3 BLOCK-NOVEL escalation rule):

  - Static analyzer kept as a best-effort regression catcher with a
    class-level docblock honestly naming three known limits (data-flow
    over reassignment, late-binding via dynamic dispatch,
    truthy-non-bool returns). Round-3 NICE-TO-HAVE applied: outer-scope
    Return_ scan only.
  - Behavioral integration test added at
    `apps/api/tests/Feature/Broadcasting/BroadcastChannelAuthEndpointTest.php`
    as the LOAD-BEARING ground-truth check: 5 channels × 3 inputs =
    15 tests against POST /broadcasting/auth (own_tenant_own_company →
    200, cross_tenant → 403, cross_company_same_tenant → 403). Custom
    `TestBroadcaster` registered in setUp() to delegate auth to
    Laravel's real `verifyUserCanAccessChannel()` (the default `null`
    driver bypasses the closures).
  - Round-4 verdict: APPROVE (Codex confirmed the three round-1/2
    mutation shapes still fail the static lint AND that the behavioral
    tests are non-vacuous via direct mutation of `User::canAccessChannel`
    / `User::canAccessCompanyChannel`).

**Pattern lesson — informs future cluster design**:

When the invariant is "this closure body correctly authorizes," static
analysis is best-effort regression catcher; the load-bearing check is
a behavioral integration test against the actual runtime path. PhpParser
can verify structural shape (return-value matches a method-call shape)
but cannot verify runtime semantics (the called method gates correctly,
the receiver hasn't been reassigned, the return value is strict bool).
Closing the data-flow class would require PHPStan/Psalm-grade type
narrowing — heavy implementation cost, still incomplete (`call_user_func`,
reflection, variable-method-name dispatch, etc., remain unbounded).

**Implication for future clusters**: any similar invariant — middleware
closures in `routes/api.php`, gate definitions in
`AuthServiceProvider`, policy methods, broadcast auth callbacks — should
default to a behavioral integration test as the load-bearing check from
day one. Reach for static analysis only when the closure body's
**structural shape** is the invariant (not its **runtime behavior**).
Examples where static analysis IS appropriate: "every queue job
declares `BindsTenantContext` trait OR `@cross-tenant-by-design`"
(structural — `api.scheduled-jobs`); "every webhook controller body
contains no `DB::`/`Bus::`/etc. patterns when annotated `STUB:`"
(structural — `api.webhooks-incoming`). Examples where behavioral test
is required: "this closure correctly authorizes only same-tenant
subscribers" (runtime semantics — `api.broadcast-channels`).

**Severity**: PROCESS-LEVEL (informs future cluster design, not a code
defect).

**Target**: future clusters that touch closure-body invariants.

## References

- `api.broadcast-channels` triage: `docs/superpowers/audits/2026-05-08-api-broadcast-channels-triage.md`
- Round-1 review: `docs/superpowers/reviews/2026-05-08-api-broadcast-channels-cluster-codex-review.md`
- Round-2 review: `docs/superpowers/reviews/2026-05-08-api-broadcast-channels-cluster-codex-round2-review.md`
- Round-3 review (BLOCK-NOVEL): `docs/superpowers/reviews/2026-05-08-api-broadcast-channels-cluster-codex-round3-review.md`
- Round-4 review (APPROVE): `docs/superpowers/reviews/2026-05-08-api-broadcast-channels-cluster-codex-round4-review.md`

## Finding J — Platform-side X-Tenant-Id enforcement gap (MEDIUM, 2026-05-08)

**Severity**: MEDIUM (incomplete defense, not zero defense)

**Surface**: `apps/platform/app/Modules/Partners/Infrastructure/Middleware/AuthenticateApiKey.php:19`

```php
$key = $request->header('X-API-Key');
// … resolves to ApiKey + Partner; sets partner context on request
// NO read of X-Tenant-Id / X-Company-Id
```

**Issue**: The `api.platform-integration` cluster (apps/erp) adds `X-Tenant-Id` + `X-Company-Id`
headers to every outbound request via `PlatformHttpClient::buildRequest()` (mandatory,
fail-loud on empty `CompanyContext`). The platform-side `AuthenticateApiKey` middleware
reads only `X-API-Key` and resolves it to a partner-level credential. Per-tenant
attribution within the partner is **not validated, not logged, not enforced** today —
the headers travel on the wire but are ignored downstream.

**Why it matters**: a compromised tenant inside a partner could spoof a sibling tenant's
`X-Tenant-Id` header on outbound traffic and the platform would accept it
indistinguishably from legitimate traffic. The ERP-side fix establishes a clean audit
trail at the source (originating tenant binding via `requireTenantId()`), but
end-to-end enforcement requires the platform to validate the headers against the
authenticated partner's tenant boundary and reject mismatches.

**Why MEDIUM (not HIGH)**: partner-key auth still gates access — only authenticated
partners can submit traffic at all. The gap is tenant binding **inside** the partner,
not unauthenticated access.

**Why deferred**: cross-team boundary. `apps/platform/` is a separate codebase with
its own PR/review cadence; the fix belongs there, not in this sweep's apps/erp scope.

**Target cluster**: `platform.synerivia-tenant-enforcement` (apps/platform/ repo,
separate PR).

**Scope**:
- Extend `AuthenticateApiKey` middleware (or add a sibling middleware that runs after
  it) to read `X-Tenant-Id` + `X-Company-Id`, validate them against the resolved
  partner's tenant boundary, and reject requests with mismatched or missing headers
  in production.
- Decide partner→tenant binding model: one Synerivia partner = one tenant (likely),
  vs. partner = multi-tenant umbrella (requires partner-side `tenants` membership
  table). Current `apps/platform/` schema has the partner concept but not partner-
  to-tenant binding columns inspected here — surface in the platform-side cluster.
- Update `apps/platform/.../docs/04-API-CONTRACTS/01-catalog-api.md` and
  `apps/platform/.../docs/03-ERP-INTEGRATION/01-integration-overview.md` to document
  the new mandatory headers.

**Order dependency**: BLOCKED ON `api.platform-integration` (this cluster's
apps/erp PR) landing first — the headers must exist on the wire before platform-side
enforcement can be validated. Once apps/erp ships, platform-side cluster can begin.

**Reference**: `api.platform-integration` triage at
`docs/superpowers/audits/2026-05-08-api-platform-integration-triage.md` (Q1).

## Finding K — GrowthAdvisor outbound HTTP tenant-binding gap (MEDIUM, 2026-05-08)

**Severity**: MEDIUM (companyId-in-path is exploit-adjacent — URL-guessing,
log-leakage, no defense in depth)

**Surface**: `apps/api/app/Modules/Progression/Infrastructure/Http/GrowthAdvisorHttpClient.php:159-174`

```php
private function buildRequest(): PendingRequest
{
    return Http::timeout($this->timeout)
        ->connectTimeout($this->connectTimeout)
        ->retry(/* … */)
        ->acceptJson()
        ->withHeaders(['Content-Type' => 'application/json']);
        // ❌ NO X-API-Key, NO X-Tenant-Id, NO X-Company-Id
}
```

Tenant context is encoded only as `companyId` in the URL path
(`/api/v1/companies/{companyId}/milestones`, etc.). No auth header of any form;
Growth Advisor is an internal peer service with apparent network-trust assumptions.

**Why it matters**: any caller on the network with knowledge of a `companyId` UUID
can issue requests on behalf of that company. UUID-based path values surface in:
proxy/CDN access logs, third-party APM tools (Datadog/Sentry URL capture), browser
history (if ever called from frontend), error message bodies, etc. This is the
"URL-as-credential" anti-pattern — tenant binding leaks through the same channels
that legitimate request-tracing leaks through.

**Compare to RecommendationEngineHttpClient** (`apps/api/app/Modules/SmartPrompts/.../RecommendationEngineHttpClient.php:84-89`)
— that client correctly sends `X-Tenant-Id` + `X-Company-Id` as headers, demonstrating
the established pattern for ERP→ML peer services. GrowthAdvisor diverges from this
pattern.

**Why deferred**: out of `api.platform-integration` scope (different module, different
peer service, different fix shape — needs both header injection AND
GrowthAdvisor-side header reader). Folding it in would bloat the cluster.

**Target cluster**: `api.growth-advisor-tenant-binding`.

**Scope**:
- ERP side: inject `X-Tenant-Id` + `X-Company-Id` headers in `GrowthAdvisorHttpClient::buildRequest()`,
  mandatory via `CompanyContext::requireTenantId()` (same fail-loud pattern as
  `PlatformHttpClient` from this cluster).
- Growth Advisor side: add header-based auth + tenant verification middleware (analogous
  to Finding J for the platform side). Likely a separate cross-team coordination.
- Audit all callers: `Modules/Progression/.../GrowthAdvisorHttpClient` is consumed
  by Progression service classes — verify each call site has `CompanyContext` bound
  before calling.

**Reference**: `api.platform-integration` triage at
`docs/superpowers/audits/2026-05-08-api-platform-integration-triage.md` (Q4).

## Finding L — Stripe SDK metadata-based tenant attribution gap (LOW, 2026-05-08)

**Severity**: LOW (global-secret signing is sufficient for the current threat model;
metadata is defense-in-depth for webhook resolution + audit reconstruction)

**Surface**: `apps/api/app/Modules/Billing/Infrastructure/Providers/StripePaymentProvider.php:36-90+`

```php
public function __construct() {
    Stripe::setApiKey($this->secretKey);  // global Stripe secret, not per-tenant
}

public function createPayment(Money $amount, string $description, array $metadata = []): PaymentResult {
    $paymentIntent = PaymentIntent::create([
        'amount' => $amount->toCents(),
        'currency' => strtolower($amount->currency),
        'description' => $description,
        'metadata' => $metadata,  // ← caller-supplied; tenant_id/company_id population uninspected
    ]);
}
```

**Issue**: Stripe SDK calls authenticate via a single global `STRIPE_SECRET_KEY`. Tenant
context can only be encoded as Stripe `metadata` on PaymentIntents/Customers/etc.
Whether ERP callers reliably populate `metadata['tenant_id']` + `metadata['company_id']`
is uninspected in this triage. If they don't, then:
- Stripe Dashboard / API queries cannot filter by tenant.
- Webhook resolution at `StripeWebhookController` falls back to ERP-side DB lookup
  (which IS what triggers Finding F's UNIQUE-on-`provider_payment_id` requirement).
- Audit reconstruction across Stripe-side records and ERP-side records lacks a
  bidirectional tenant key.

**Why LOW**: Stripe's webhook signature verification (`Stripe\Webhook::constructEvent`)
gates inbound traffic with a global secret. Outbound creation is gated by
`STRIPE_SECRET_KEY` environment variable. The threat surface is "compromised ERP
internal call path corrupts metadata," not "external attacker bypasses authentication."
The Finding F fix (DB UNIQUE on `(provider, provider_payment_id)`) closes the
webhook-side resolution gap regardless of metadata population.

**Why deferred**: out of `api.platform-integration` scope. Stripe is a third-party
payment processor, not a Synerivia-owned platform — the fix shape (metadata population
audit + Stripe-API-side discipline) is orthogonal to ERP→Synerivia outbound traffic.
Folding in would require a full audit of every `Stripe\…\::create` callsite across
the Billing module and beyond.

**Target cluster**: `api.stripe-sdk-tenant-metadata`.

**Scope**:
- Audit all Stripe SDK callsites for outbound `create()` calls (PaymentIntent, Customer,
  Subscription, Invoice, Refund, etc.); identify which populate `metadata` with
  `tenant_id` + `company_id`, which don't.
- Standardize: a `StripeMetadataBuilder` helper (or Plain-Old-PHP method on
  `StripePaymentProvider`) that pulls from `CompanyContext::requireTenantId()` +
  `requireCompanyId()` and seeds the metadata array on every outbound call. Mandatory
  the same way `PlatformHttpClient::addTenantHeaders()` is mandatory.
- Optionally: backfill historical Stripe records with metadata via `Stripe\…\::update`
  (long tail; defer if not critical).
- Pair with Finding E (missing event-id idempotency) — same audit, same Billing
  cluster.

**Reference**: `api.platform-integration` triage at
`docs/superpowers/audits/2026-05-08-api-platform-integration-triage.md` (Q4).

## References (Findings J/K/L, 2026-05-08)

- `api.platform-integration` triage: `docs/superpowers/audits/2026-05-08-api-platform-integration-triage.md`
- Reference-good outbound HTTP pattern: `apps/api/app/Modules/SmartPrompts/Infrastructure/Http/RecommendationEngineHttpClient.php:84-89`
- Platform-side auth contract: `apps/platform/app/Modules/Partners/Infrastructure/Middleware/AuthenticateApiKey.php:19`

---

## Master plan §10 narrowing was scope-myopic — expanded api.module-gating cluster to absorb (2026-05-08)

### Pattern lesson (actionable, leading)

**Master plan section narrowings — when grounded in a Codex SN
module-bounded audit — must be paired with hostile-grep validation
across the surrounding modules BEFORE the cluster's triage commits to
scope.** The Codex SN audit's coverage limit IS the load-bearing
assumption; it's wrong to assume the audit's "narrowed-to-X" conclusion
means "no other module has the same gap." Validate by grep before
honoring.

Apply to every future cluster whose master-plan §-narrowing originated
in a Codex S<N>-style audit. Hostile-grep the relevant anti-pattern
across `apps/api/app` (with sensible exclusions for tests +
middleware/structural-protector layers). If the grep surfaces sibling
callsites in modules the SN audit didn't cover, that's not "out of
cluster scope" — it's prima facie evidence the narrowing was
coverage-limited, not deliberately scope-deciding.

### Historical narrative (supporting context)

- Master plan §10 narrowed `api.module-gating` to ProgressionService
  + 3 controller callers, based on Codex S4 audit of the Progression
  module.
- Hostile-grep at cluster Step 1 (this triage, 2026-05-08) surfaced
  12 sibling callsites in unaudited modules:
  - `Tenant/OnboardingController.php:20` (1 callsite — primary scoping)
  - `Tenant/CompanySettingsController.php:112,159,220` (3 callsites — audit attribution)
  - `Identity/UserController.php:177,263,330,392,468,526,559,620` (8 callsites — audit attribution)
- All 12 share the same `$request->header('X-Company-Id')` anti-pattern,
  the same `auth:sanctum` + `CompanyContextMiddleware` route stack, and
  the same uniform fix shape (constructor-inject `CompanyContext`,
  swap to `requireCompanyId()`).
- Codex S4 audit's coverage was bounded to the Progression module — it
  didn't cover Tenant or Identity. The §10 narrowing was incomplete
  coverage, not deliberate scope.
- **Orchestrator decision (2026-05-08)**: expand cluster scope to
  absorb all 15 method-level callsites (= 20 line-level callsites)
  with a per-callsite a/b/c/d classification verification before fix
  application. Final classification: 9 × (a-1) primary scoping + 11 ×
  (a-2) audit attribution = 20 × (a) SAME-PATTERN, all uniform fix
  shape. No (b), (c), or (d).
- Two invariants closed with the same mechanical change:
  - **(a-1) data-scoping invariant**: companyId controls which
    records are read/written. Raw-header-trust = direct cross-tenant
    data leak.
  - **(a-2) audit-attribution invariant**: companyId is recorded in
    `AuditEvent` for forensic reconstruction. Raw-header-trust =
    audit-trail evidence-tampering risk. For NF525 + AdminAuditLog
    + two-tier hash chain compliance, this is independently
    load-bearing (not "less severe than (a-1)" but "different
    invariant, equally load-bearing for compliance contexts").

### Layering with `api.super-admin-context`

The universal arch test added by `api.super-admin-context`
(`tests/Architecture/ControllerTenantContextTest::test_every_controller_method_is_classified`)
confirms every controller method is classified by structural
`CompanyContext` usage OR `#[CrossTenantRoute]`. That static check
correctly admits the 11 (a-2) callsites today (those controllers DO
reference `CompanyContext` somewhere — through the data-scoping path
or audit helper). The static heuristic CANNOT enforce that every
`companyId`-bearing parameter to a downstream call (service, helper,
audit log) derives from `CompanyContext` rather than raw
`$request->header('X-Company-Id')` — that's the data-flow analysis
wall PhpParser hits (api.broadcast-channels round-3 lesson).

This cluster (`api.module-gating`) is the behavioral source-pinning
layer that closes the residual gap the static layer can't reach. The
two clusters layer cleanly:

- **Static classification** (api.super-admin-context):
  reflection-walk every public method on every concrete controller +
  assert each is either `#[CrossTenantRoute(reason: <non-blank>)]` OR
  matches the `CompanyContext` heuristic regex.
- **Behavioral source-pinning** (api.module-gating): every
  `companyId`-bearing argument flowing OUT of these controllers
  derives from `CompanyContext::requireCompanyId()`, not from raw
  header reads.

### Severity

**PROCESS-LEVEL** — informs future master-plan-narrowing decisions, not
a code defect by itself. The 20 callsites this cluster fixes ARE code
defects (same shape as `api.compliance` round-2 fix), but the lesson
about narrowing-validation is the architectural takeaway.

### Target

Future cluster owners — apply the hostile-grep validation step
BEFORE committing to a master-plan §-narrowing as scope.

### References

- `api.module-gating` triage: `docs/superpowers/audits/2026-05-08-api-module-gating-triage.md`
- Codex S4 audit (the original narrowing source): see master plan §10
- `api.compliance` round-2 fix (the precedent template):
  `apps/api/app/Modules/Compliance/Presentation/Controllers/AuditController.php:18-26`
- Static-vs-behavioral layering precedent: api.broadcast-channels
  round-3 BLOCK-NOVEL (this same doc, "Static analyzer on closure
  bodies has unbounded attack surface" section).

---

## cat-(b) cross-tenant conversion — WAVE 1 (2026-08-05)

Follow-on to the 2026-08-04 session that converted `ExpireReservationsJob`,
`DailyExpiryCheck` and the two marketplace scheduler closures. Source audit (a
re-sweep of every remaining `@cross-tenant-by-design` class and every cat-(b)
class named in this document against the post-2026-05-28 topology):
`scratchpad/partA1-cat-b-resweep.md`.

Wave 1 took the six AUTOMATED surfaces — the ones that run without an operator
watching. The twelve operator/one-shot commands in the re-sweep's §1b (which
fail LOUD when run bare) are a later wave.

| Surface | Verdict | What shipped |
|---|---|---|
| `channels:reconcile` scheduler CLOSURE (`ChannelServiceProvider`) | **CONVERTED** | `ChannelReconcileCommand` (TenantScopedCommand), provider-registered, `withoutOverlapping(30)` + `onFailure()`. The `Schema::hasTable('channels')` guard was NOT carried across. |
| `LockExpiredFiscalPeriodsCommand` (`fiscal:lock-expired-periods`) | **CONVERTED** | `forEachTenant()`; the `catch (\Exception)` swallow removed; schedule entry gains `onFailure()` and drops `runInBackground()`. Locking business logic untouched. |
| `DetectFraudPatterns` (`fraud:detect`) | **CONVERTED** | `forEachTenant()` with the company enumeration inside, explicit `tenant_id` predicate, `--company` kept as an in-tenant filter that now fails loudly when it matches nothing. |
| `CheckPendingEnrichmentsCommand` (`enrichment:check-pending`) | **CONVERTED (+ a second, undiagnosed fault)** | See below. |
| Enrichment webhook chain | **CONVERTED — audit claim CONFIRMED, staging counter-evidence EXPLAINED** | See below. |
| `ChannelWebhookController` | **CONVERTED** | Central `channel_webhook_directory` pointer + fail-closed 404. See below. |

### Beyond the audit: `enrichment:check-pending` was never a registered command

`CheckPendingEnrichmentsCommand` lives in
`app/Modules/PlatformIntegration/Application/Commands/`, and Laravel only
auto-discovers `app/Console/Commands`. It was therefore never a resolvable
Artisan command — `php artisan list` never showed it. `Schedule::command()`
takes an unvalidated STRING, so `schedule:list` printed the entry and the
scheduler shelled out to a non-existent command on every 15-minute tick, with no
`onFailure()` to notice. **A `schedule:list` assertion does not prove a command
exists**; the regression test added with the fix asserts a resolvable name via
`Artisan::all()`.

Note the knock-on: this poller is the enrichment WEBHOOK's fallback path, so
both halves of that feature were dark at once.

### Staging counter-evidence on the enrichment queue — resolved, not a webhook bug

A staging `failed_jobs` row (queue `enrichment`, 2026-07-03) carried a payload
WITH `tenant_id` and a NOT NULL violation on `enrichment_results.tracking_id`,
which appeared to contradict the audit's "the webhook runs with no tenancy"
claim. It does not:

- the `enrichment` queue carries several jobs, not just `ProcessEnrichmentWebhookJob`;
- `ApplyCatalogEnrichmentJob` is dispatched from `ProductController` under an
  AUTHENTICATED request, so `QueueTenancyBootstrapper` stamps `tenant_id` — that
  is where the anchor in the payload came from;
- it writes `'tracking_id' => null` (`CatalogEnrichmentService`), against a then
  NOT NULL column;
- migration `2026_07_03_000001_make_enrichment_results_tracking_id_nullable.php`
  (`2b9b533d4`, same day) made the column nullable and fixed it.

So the row is a historical artifact of a DIFFERENT job on the same queue, already
fixed. **No `tracking_id` change was owed**, and the audit's verdict on the
webhook path stands unaltered.

### The listener's catch gap — closed with a guard, not a wider catch

`ProcessEnrichmentEventListener` caught `ModelNotFoundException` around a
`->sole()` that, on the central connection, raised `QueryException` (42P01)
instead — so the job died naming a missing relation rather than the real fault.
The catch was deliberately NOT widened: a query fault is an infra fault that must
stay loud and retryable, never be reinterpreted as "unknown tracking id". The
listener now refuses to run at all when no tenant is bound under db-per-tenant,
and says why.

### `ChannelWebhookController` — how channel webhooks identify the tenant: they don't

The channel id in the URL is the only identifier the request carries, `channels`
is a tenant table, and the signature cannot be checked first because verification
needs that same row's adapter and credentials. A new CENTRAL
`channel_webhook_directory` (`channel_id -> tenant_id`, and nothing else) makes
the callback routable; the tenant is bound through
`TenancyResolver::initializeIfProvisioned()` before anything tenant-side is read.
Unresolvable ids get a fail-closed 404 before the adapter registry is consulted —
**never a per-tenant fan-out**, which on an unauthenticated endpoint would let one
forged request cost N database switches. The directory is maintained by a Channel
model observer and self-healed by the nightly `channels:reconcile` sweep, since a
central migration cannot backfill across tenant databases.

### Cross-cutting lesson (re-sweep §4.1, restated with evidence)

Both `ConsoleCommandTenantContextTest` and `QueueJobTenantContextTest` were GREEN
with every surface above broken: they check that a justification STRING exists,
never that it is still true. Three of the six justifications converted here were
not merely stale but actively false post-flip (`ProcessEnrichmentWebhookJob`'s
resolution chain, `ChannelWebhookController`'s "resolved from the signed channel
secret", `LockExpiredFiscalPeriodsCommand`'s "across all companies"). That is the
structural reason this class of breakage survived 2026-05-28.
