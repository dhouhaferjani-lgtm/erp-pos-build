# api.webhooks-incoming — Cluster Triage (2026-05-07)

**Branch:** `feat/tenant-isolation-sweep-execution`
**Owner:** claude
**Cluster invariant:** master plan §8 webhooks-incoming brief
**Inventory baseline (Step 0 verify-history):** 1588 events / 323 callsites, 0 problems
  *(Locally +48 events vs the last commit `3f158018` — those are uncommitted YAML
  rows from the parallel `api.pos-stabilization` administrative bulk-flip session;
  not touched by this triage.)*

---

## Summary

Three in-scope controllers + one signature middleware, exhaustive against
`find … -name "*Webhook*Controller*.php"` (no fourth handler exists in
the codebase). After end-to-end review against the cluster invariant
(verify signature → resolve tenant → bind context → defense-in-depth):

| # | Controller | Route | Signature gate | Tenant resolution | Verdict |
|---|---|---|---|---|---|
| 1 | `StripeWebhookController` | `POST /v1/webhooks/stripe` (`routes/api.php:27`) | Inline (Stripe SDK `Webhook::constructEvent`) — global secret `STRIPE_WEBHOOK_SECRET` | Shape (b) sub-form — globally-unique Stripe-issued IDs (`sub_*`, `in_*`, `pi_*`) → resource lookup → `resource.tenant_id` stamps downstream operations | **cat-(b)** |
| 2 | `EnrichmentWebhookController` | `POST /api/v1/webhooks/syneriva` (`Modules/PlatformIntegration/Presentation/routes.php:18`) | Middleware `VerifySynerivaWebhookSignature` (HMAC-SHA256, 5-min freshness, global secret `SYNERIVA_WEBHOOK_SECRET`) | Shape (b) sub-form — `tracking_id` (= `platform_submission_id`) globally unique by external contract; controller does ZERO DB access, dispatches `ProcessEnrichmentWebhookJob` whose downstream `EnrichmentWebhookReceived` listener resolves tenant via `Product.platform_submission_id → company_id` | **cat-(b)** |
| 3 | `PurchaseHubWebhookController` | `POST /api/webhooks/purchase-hub` (`Modules/PurchaseHub/Presentation/routes.php:25`) | **NONE** — route middleware is `['api']` only; no signature middleware registered yet | N/A — controller is a STUB (`Log::info` + `200 OK`); zero DB access | **cat-(b) — stub** |

**No cat-(a) callsites identified.** All three are structurally tenant-safe
today; the cluster work is annotation-only at the controller layer plus
a new architecture test that enforces the invariant going forward.

---

## 1. StripeWebhookController — cat-(b)

**File:** `apps/api/app/Modules/Billing/Presentation/Controllers/StripeWebhookController.php` (590 LOC)

### Invariant ordering

1. **Signature verification FIRST** — ✅ inline at `lines 53-64`. `Stripe\Webhook::constructEvent($payload, $signature, $webhookSecret)` validates timestamp tolerance + HMAC against `STRIPE_WEBHOOK_SECRET` BEFORE any DB read. On `SignatureVerificationException` returns 400 with no DB side-effects.
2. **Tenant resolution** — Shape (b) sub-form: each event handler looks up the per-resource record by a **Stripe-issued ID guaranteed globally unique by Stripe's external contract**:
   - `handleSubscriptionCreated/Updated/Deleted`: `TenantSubscription::where('stripe_subscription_id', …)`
   - `handleInvoicePaid/Failed/Finalized`: `Invoice::where('stripe_invoice_id', …)`, `TenantSubscription::where('stripe_subscription_id', …)`
   - `handlePaymentIntentSucceeded/Failed`: `Payment::where('provider_payment_id', …)`
   - `handleChargeRefunded`: `Payment::where('provider_payment_id', …)`
   The resolved row carries its own `tenant_id` column; downstream notifications and updates use `$resource->tenant_id` directly.
3. **CompanyContext binding** — **Not applicable as the cluster invariant defines it.** `tenant_subscriptions`, `billing_invoices`, `billing_payments` are **platform-level tables in the public schema** (see `database/migrations/2025_12_16_10000{1,2,4}_*`), not per-tenant schema-scoped tables. They are tenant-isolated by `tenant_id` column, not by `CompanyContext::setCompanyId(…)`. The "or equivalent" wording in the invariant covers this: equivalent here is "stamp the resolved resource's `tenant_id` on every downstream write," which the controller does.
4. **Defense-in-depth** — Soft. Lookups are by Stripe ID alone; no `where('tenant_id', …)` filter is layered on top. This is acceptable in this cluster because:
   - DB-level UNIQUE constraints exist on `stripe_subscription_id` (`tenant_subscriptions` migration line 35) and `stripe_invoice_id` (`billing_invoices` migration line 60).
   - `provider_payment_id` has only a composite `(provider, provider_payment_id)` **index, not UNIQUE** (`billing_payments` migration line 81). Today the column's uniqueness rests on Stripe's external contract that payment-intent IDs are globally unique. **Documented as a follow-up below.**

### Action

Add a class-level `@cross-tenant-by-design` annotation explaining shape (b)
sub-form (globally-unique Stripe-issued IDs → resource lookup → resource.tenant_id
stamps downstream). Reference the DB-level UNIQUE constraints on the
subscription/invoice columns and acknowledge the payment-intent column
relies on Stripe's external contract.

### Out-of-scope concerns (flag as cross-cluster, do **not** fix in this cluster)

- **Logic bug, not tenant-isolation:** `handleSubscriptionCreated` (lines 100-103) uses `->where('stripe_subscription_id', …)->orWhere('stripe_customer_id', …)` to fall back to a customer-ID match. If a Stripe customer ever has multiple `TenantSubscription` rows in our DB (Stripe permits a customer to have multiple subscriptions), the orWhere can pick the wrong row. Defer to a billing-cluster follow-up.
- **Idempotency missing, not tenant-isolation:** Stripe redelivers events on 5xx and on dashboard "resend." The controller has no `event_id` dedup table; double-processing a refund or invoice can cause double notifications, double-stamped `paid_at`, or stale state. Defer to a billing-cluster follow-up.
- **`provider_payment_id` non-UNIQUE:** weak DB-level defense; relies on Stripe's external-contract uniqueness. Track in the audits cross-cluster observations doc (similar to scheduled-jobs Finding A on `platform_submission_id` global-uniqueness).

---

## 2. EnrichmentWebhookController — cat-(b)

**File:** `apps/api/app/Modules/PlatformIntegration/Presentation/Controllers/EnrichmentWebhookController.php` (38 LOC)

### Invariant ordering

1. **Signature verification FIRST** — ✅ via route middleware at `Modules/PlatformIntegration/Presentation/routes.php:15`:
   ```php
   Route::middleware(['api', VerifySynerivaWebhookSignature::class])
       ->prefix('api/v1/webhooks')
       ->group(...);
   ```
   The middleware (`Infrastructure/Middleware/VerifySynerivaWebhookSignature.php`) enforces:
   - Required headers `X-Syneriva-Signature` + `X-Syneriva-Timestamp`
   - Required global secret `SYNERIVA_WEBHOOK_SECRET` (config `services.platform.webhook_secret`)
   - Timestamp freshness ≤ 300s
   - HMAC-SHA256 over `timestamp.body` matches `sha256=…` signature via `hash_equals`
   - Throws `HttpException(403)` on any failure — handled before controller body executes
2. **Tenant resolution** — Shape (b) sub-form: controller does ZERO DB access. It maps the request body to `EnrichmentWebhookPayload` DTO and dispatches `ProcessEnrichmentWebhookJob` per item. Tenant resolution happens downstream:
   - `ProcessEnrichmentWebhookJob::handle()` re-emits `EnrichmentWebhookReceived` with `payload->trackingId` (= `platform_submission_id`).
   - The synchronous downstream listener `ProcessEnrichmentEventListener` (referenced in the existing job-level annotation) looks up `Product` by `platform_submission_id`, derives `company_id` from the resolved Product, and binds context.
   - `platform_submission_id` global uniqueness is treated as the resolution key — matches the pattern documented in the scheduled-jobs cluster cross-observations (Finding A).
3. **CompanyContext binding** — Deferred to the downstream listener (per the resolution chain, not at the controller layer).
4. **Defense-in-depth** — Listener-layer concern, out of this cluster's controller scope.

### Existing annotation surface

Job-level annotation already exists at `ProcessEnrichmentWebhookJob.php:16`:
> *"Webhook re-dispatcher queue job — handle() does ZERO DB access … Webhook entry (EnrichmentWebhookController) is intentionally tenant-agnostic — third-party platform calls back about previously-tracked submissions, and tenant resolution chains through platform_submission_id → Product → company_id."*

The controller class itself has **no class-level annotation**. The kickoff brief stated the controller "already has `@cross-tenant-by-design`" — that is **stale**: the annotation is on the job, not the controller. The architecture test (Step 6) will require it at the controller class level too.

### Action

Add class-level `@cross-tenant-by-design` annotation pointing to:
- The signature middleware (request-time gate)
- The job-level annotation on `ProcessEnrichmentWebhookJob` (resolution chain)
- The cross-cluster observation on `platform_submission_id` uniqueness (already tracked in `docs/superpowers/audits/2026-05-07-scheduled-jobs-cross-cluster-observations.md` Finding A).

---

## 3. PurchaseHubWebhookController — cat-(b) (stub)

**File:** `apps/api/app/Modules/PurchaseHub/Presentation/Controllers/PurchaseHubWebhookController.php` (25 LOC)

### Invariant ordering

1. **Signature verification FIRST** — **NONE.** Route at `Modules/PurchaseHub/Presentation/routes.php:22-26` registers under `Route::prefix('api/webhooks')->middleware(['api'])` — no signature middleware, no auth. **Today this is acceptable** because the controller body is a stub:
   ```php
   $event = $request->input('event');
   Log::info('PurchaseHub webhook received', ['event' => $event]);
   return response()->json(['status' => 'received']);
   ```
   Zero DB access. Zero job dispatch. The route accepts unauthenticated POSTs but cannot mutate per-tenant state.
2. **Tenant resolution** — N/A (no per-tenant operations performed).
3. **CompanyContext binding** — N/A.
4. **Defense-in-depth** — N/A.

### Risk

The hint comment (`// Future: handle order.receipt_confirmed to auto-create PO in ERP`) signals this stub WILL grow into a real handler. If a developer later adds DB writes without first wiring a signature middleware AND a tenant-resolution step, the result is a textbook unauthenticated cross-tenant write surface.

### Action

Add class-level `@cross-tenant-by-design` annotation explicitly marking
the controller as a STUB and listing the **two prerequisites that must
be satisfied before any DB write is added**:

1. Register a signature-verification middleware on the route (e.g., a `VerifyPurchaseHubWebhookSignature` mirroring the Syneriva pattern, or align with whichever HMAC scheme PurchaseHub uses).
2. Implement explicit tenant resolution from the verified payload (shape a/b/c per the invariant).

The architecture test (Step 6) will keep enforcing the `@cross-tenant-by-design`
annotation; the comment-level prerequisites give the next contributor a clear
path forward.

---

## VerifySynerivaWebhookSignature middleware — verified

**File:** `apps/api/app/Modules/PlatformIntegration/Infrastructure/Middleware/VerifySynerivaWebhookSignature.php` (43 LOC)

- Uses **single global secret** `services.platform.webhook_secret` (env `SYNERIVA_WEBHOOK_SECRET`). Documented in this triage as resolution shape (b) (the secret is NOT per-tenant; tenant resolves through the chain via `platform_submission_id` downstream).
- Replay-safe within 300s window; rejects expired or missing-headered requests with 403 before controller body.
- Constant-time comparison via `hash_equals` ✓.
- No DB access in the middleware itself ✓.

No code changes required. The triage doc records the verified signature shape for downstream consumers (Codex review, future audits).

---

## Inventory implications

- **No cat-(a) manual rows to add** — all three controllers structurally satisfy the invariant.
- Step 2 (cluster claim/start) still applies, with **zero** manual cat-(a) inventory rows. The cluster aggregate for `api.webhooks-incoming` will move `not_started → in_progress` purely on the basis of annotation work + the architecture test.
- Step 3 (TDD red anchor) collapses into Step 6 (architecture test) — there are no per-controller cat-(a) regression tests to write because no cat-(a) callsite exists.
- Step 4 (cat-(a) implementation) becomes a no-op.
- Step 5 (cat-(b) annotations) is the substantive work for this cluster (3 annotations, all on already-correct controllers).
- Step 6 (architecture test) is the long-term invariant guard; it's now also serving the role Step 3 played in earlier clusters.

---

## Recommendation to orchestrator

**Approve as cat-(b) × 3, no cat-(a).** Proceed to Step 2 with cluster
claim only (no manual rows). Step 3 collapses into Step 6. Steps 4 and
5 merge into a single annotation-only commit. Architecture test
(Step 6) carries the regression-protection load for this cluster.

If orchestrator wants stronger cat-(a) coverage on Stripe (e.g., to
introduce explicit tenant-id defense-in-depth filters even though the
external uniqueness contract makes them redundant today), that's a
scope expansion to call out before Step 2.

---

**Stop point per the kickoff:** awaiting orchestrator approval before
adding manual rows / claiming the cluster (Step 2).
