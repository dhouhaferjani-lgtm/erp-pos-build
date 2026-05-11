# api.platform-integration — Step 1 Triage

**Date:** 2026-05-08
**Branch:** feat/tenant-isolation-sweep-execution @ 58cb06fa
**Cluster:** api.platform-integration (last bespoke cluster of the sweep)
**Cross-cluster anchors:** Finding A (platform_submission_id) + Finding F (provider_payment_id) from `2026-05-07-scheduled-jobs-cross-cluster-observations.md`
**Author:** opus-claude
**Status:** READ-ONLY — awaiting orchestrator approval before Step 2

---

## Executive summary

Two concerns folded into one cluster. Triage surfaced **one major architectural surprise** + **two scope-expansion candidates** that need orchestrator decisions before Step 2.

**Concern 1 (outbound HTTP tenant tagging)** — `PlatformHttpClient::buildRequest()` (line 205-217) attaches a single global `X-API-Key` and **no tenant context whatsoever**. Every ERP→platform call carries the same partner-level credential, so the platform receives "this partner submitted X" but cannot attribute X to a specific tenant within the partner. **Critical surprise:** the platform-side `AuthenticateApiKey` middleware (`apps/platform/.../AuthenticateApiKey.php:19`) reads only `X-API-Key` and resolves it to a `Partner` entity. There is no documented or implemented `X-Tenant-Id` / `X-Company-Id` reader on the platform side today. ERP-side header injection is **necessary but not sufficient** for end-to-end enforcement.

**Concern 2 (external-id-as-tenant-anchor)** — Hostile-grep confirmed the brief's two known instances (`products.platform_submission_id`, `billing_payments.provider_payment_id`) and ruled out the broader Stripe surface (`stripe_subscription_id`, `stripe_invoice_id` are already DB-`unique()` per migrations). One billing-side wrinkle: the three `Payment::where('provider_payment_id', …)` callsites in `StripeWebhookController` do NOT also filter on `provider`, so a UNIQUE on `(provider, provider_payment_id)` requires both a migration AND code changes in three call-sites.

---

## Concern 1 — Outbound HTTP tenant tagging

### A. PlatformHttpClient surface (the load-bearing point)

| Method | Path | Tenant context attached? | Classification |
|---|---|---|---|
| `get(path, queryParams)` | varies | ❌ none | cat-(a-fix-required) |
| `post(path, data)` | varies | ❌ none | cat-(a-fix-required) |
| `postRaw(path, data, headers)` | varies | ❌ none (caller-supplied `headers` is the only bypass) | cat-(a-fix-required) |
| `getRaw(path, queryParams)` | varies | ❌ none | cat-(a-fix-required) |
| `buildRequest()` (private, line 205-217) | n/a | only global `X-API-Key` + retry policy | **single fix point** |

**Fix point:** add a constructor-injected `CompanyContext` and have `buildRequest()` attach `X-Tenant-Id` + `X-Company-Id` on every request. **One change, all callers benefit.**

### B. Service-layer callsites (4 services)

| Service | Method | Outbound endpoint | Per-call tenant binding | Risk |
|---|---|---|---|---|
| `BarcodeLookupService::lookup` | `postRaw` | `/api/v1/products/lookup` | `vertical` derived from `CompanyContext` (line 35) → only vertical sent, no tenant id | cat-(a) |
| `CatalogBrowseService::getManufacturers` | `cachedGet → get` | `/api/v1/automotive/manufacturers` | `vertical` + `country` query params only | cat-(a) — read-only catalog, lower-severity |
| `CatalogBrowseService::getModelSeries` | `cachedGet → get` | `/api/v1/automotive/manufacturers/{id}/model-series` | same | cat-(a) — same |
| `CatalogBrowseService::getVehicles` | `cachedGet → get` | `/api/v1/automotive/model-series/{id}/{type}` | same | cat-(a) — same |
| `CatalogBrowseService::getVehicle` | `cachedGet → get` | `/api/v1/automotive/vehicles/{type}/{id}` | same | cat-(a) — same |
| `CatalogBrowseService::getVehicleArticles` | `get` | `/api/v1/automotive/vehicles/{type}/{id}/articles` | same | cat-(a) — same |
| `CatalogBrowseService::searchArticles` | `get` | `/api/v1/automotive/articles` | same | cat-(a) — same |
| `CatalogBrowseService::getArticle` | `get` | `/api/v1/automotive/articles/{id}` | same | cat-(a) — same |
| `CatalogBrowseService::getArticleLinkages` | `get` | `/api/v1/automotive/articles/{id}/linkages` | same | cat-(a) — same |
| `CatalogBrowseService::searchByCriteria` | `post` | `/api/v1/automotive/articles/search-by-criteria` | same | cat-(a) — same |
| `CatalogBrowseService::getCriteria` | `cachedGet → get` | `/api/v1/automotive/criteria` | same | cat-(a) — same |
| `CatalogBrowseService::getSuppliers` | `cachedGet → get` | `/api/v1/automotive/suppliers` | same | cat-(a) — same |
| `CatalogBrowseService::getSearchTreeRoots` | `cachedGet → get` | `/api/v1/automotive/search-tree/roots` | same | cat-(a) — same |
| `CatalogBrowseService::getSearchTreeChildren` | `cachedGet → get` | `/api/v1/automotive/search-tree/{id}/children` | same | cat-(a) — same |
| `CatalogBrowseService::getSearchTreeArticles` | `get` | `/api/v1/automotive/search-tree/{id}/articles` | same | cat-(a) — same |
| `ProductSubmissionService::requestUploadUrl` | `postRaw` | `/api/v1/products/upload-url` | ❌ none | cat-(a) |
| `ProductSubmissionService::uploadPhoto` | `Http::withBody->put` | platform-issued pre-signed URL | ❌ none — direct `Http::` bypass of `PlatformHttpClient`; pre-signed URL IS the auth | **special case — see decision Q3** |
| `ProductSubmissionService::submit` | `postRaw` | `/api/v1/products/submit` | ❌ none in headers (vertical+brand in body); `Idempotency-Key` UUID added | cat-(a) — emits `platform_submission_id` |
| `ProductSubmissionService::bulkSubmit` | `postRaw` | `/api/v1/products/bulk-submit` | ❌ none | cat-(a) |
| `ProductSubmissionService::bulkLookup` | `postRaw` | `/api/v1/products/bulk-lookup` | ❌ none | cat-(a) |
| `ProductSubmissionService::checkStatusRaw` | `getRaw` | `/api/v1/products/lookup-status/{trackingId}` | ❌ none — trackingId is platform's | cat-(a) |
| `ProductSubmissionService::triggerEnrichment` | `postRaw` | `/api/v1/products/{trackingId}/enrich` | ❌ none | cat-(a) |
| `ProductSubmissionService::getCategoryAttributes` | `getRaw` | `/api/v1/verticals/{v}/categories/{c}/attributes` | ❌ none | cat-(a) |
| `CheckPendingEnrichmentsCommand::handle` | `checkStatusRaw` (per-iteration) | `/api/v1/products/lookup-status/{trackingId}` | ❌ none — fleet-wide poll, NO `CompanyContext` injected | **special case — see decision Q2** |

### C. Controller surface (5 controllers, mostly pass-through)

| Controller | Outbound? | Notes |
|---|---|---|
| `BarcodeLookupController` | indirect via service | thin wrapper; tenant fix at service layer covers it |
| `CatalogBrowseController` | indirect via service | thin wrapper; injects `CompanyContext` for vertical/country resolution but service is the load-bearing point |
| `ProductSubmissionController` | indirect via service | thin wrapper; injects `CompanyContext` (line 19) for vertical resolution |
| `VinDecodeController` | ❌ none | Phase-4 placeholder; returns hardcoded `not_found` (line 22-35) and 501 (line 56). No outbound. SKIP. |
| `EnrichmentWebhookController` | ❌ none — INBOUND only | already cat-(b) per docblock (line 14-43); no outbound paths. SKIP. |

### D. Outbound HTTP outside PlatformIntegration (scrutiny vector #7)

Hostile-grep `Http::|file_get_contents.+http|curl_exec` outside `PlatformIntegration`, `tests/`, `StripeWebhookController` returned **two hits**:

1. **`Modules/SmartPrompts/Infrastructure/Http/RecommendationEngineHttpClient.php:84`** — `buildRequest(tenantId, companyId)` already sends `X-Tenant-Id` + `X-Company-Id` headers (lines 87-88). **REFERENCE-GOOD pattern.** No fix needed; useful as a model for `PlatformHttpClient`.

2. **`Modules/Progression/Infrastructure/Http/GrowthAdvisorHttpClient.php:161`** — `buildRequest()` (line 159-174) sends NO auth headers, NO tenant headers. Tenant context is encoded only as `companyId` in the URL path (e.g. `/api/v1/companies/{companyId}/milestones`). No `X-Tenant-Id`. **Scope-expansion candidate — see decision Q4.**

3. **`Modules/Billing/Infrastructure/Providers/StripePaymentProvider.php:36`** — `Stripe::setApiKey()` then SDK calls (`PaymentIntent::create`, `Customer::create`, `Refund::create`, `Subscription::create`, `Invoice::create`). Tenant context can only be encoded as Stripe `metadata`. Whether callers populate metadata with `tenant_id` + `company_id` is uninspected here. **Scope-expansion candidate — see decision Q4.**

---

## Concern 2 — External-id-as-tenant-anchor

### Hostile-grep results

```
where('platform_submission_id'  → 1 hit (Product listener) — cat-(uniqueness-needed) — FIX
where('idempotency_key'         → 1 hit (POS receipt sync)  — cat-(within-tenant-scope) — SKIP
where('provider_payment_id'     → 3 hits (Stripe webhook)   — cat-(uniqueness-needed) — FIX
where('stripe_subscription_id'  → 5 hits (Stripe webhook)   — cat-(structurally-protected) — SKIP
where('stripe_invoice_id'       → 2 hits (Stripe webhook)   — cat-(structurally-protected) — SKIP
where('idempotency_key'         (other modules) — none
```

### Per-column triage

| Column | Resolver | DB schema state | Classification | Fix needed |
|---|---|---|---|---|
| `products.platform_submission_id` | `Product::where('platform_submission_id', $event->trackingId)->first()` at `ProcessEnrichmentEventListener.php:22` | `uuid nullable, no unique, no direct index — only composite (tenant_id, enrichment_status)` per migration `2026_03_28_100000_add_enrichment_columns_to_products_table.php:15-21` | **cat-(uniqueness-needed)** | (a) Add `UNIQUE` constraint via new migration; (b) Change `->first()` to `->sole()` or `->count() <= 1` guard. Note: source platform's `barcode_submissions.id` IS UUID PK (`apps/platform/database/migrations/2026_03_26_000003_create_barcode_submissions_table.php:14`), so cross-tenant collision is mathematically negligible — defense-in-depth. |
| `billing_payments.provider_payment_id` | `Payment::where('provider_payment_id', $paymentIntentId)->first()` at `StripeWebhookController.php:449, 473, 502` (3 callsites) | `string nullable, composite INDEX (provider, provider_payment_id) — NOT unique` per migration `2025_12_16_100004_create_billing_payments_table.php:20+81` | **cat-(uniqueness-needed)** + **callsites missing `provider` filter** | (a) Add `UNIQUE` constraint on `(provider, provider_payment_id)` via new migration; (b) Update 3 callsites to also filter `where('provider', PaymentProviderCode::Stripe)`; (c) Change `->first()` to `->sole()`. |
| `tenant_subscriptions.stripe_subscription_id` | `TenantSubscription::where('stripe_subscription_id', …)->first()` at `StripeWebhookController.php:135, 171, 222, 272, 336, 393` (6 callsites) | `string nullable, ->unique()` per migration `2025_12_16_100001_create_tenant_subscriptions_table.php:35` | cat-(structurally-protected) | SKIP — already DB-enforced. Defense-in-depth `->sole()` is optional polish, not isolation gap. |
| `billing_invoices.stripe_invoice_id` | `Invoice::where('stripe_invoice_id', …)->first()` at `StripeWebhookController.php:254, 325` (2 callsites) | `string nullable, ->unique()` per migration `2025_12_16_100002_create_billing_invoices_table.php:60` | cat-(structurally-protected) | SKIP — already DB-enforced. |
| `pos_receipts.idempotency_key` | `Receipt::query()->where('tenant_id', …)->where('company_id', …)->where('idempotency_key', …)->first()` at `ReceiptSyncService.php:135-139` | tenant + company predicates already applied (Codex round-3 closure docblock at lines 128-133) | cat-(within-tenant-scope) | SKIP — structurally tenant-scoped. |

### Pre-migration data check (deferred to Step 5; surfaced for STOP-condition awareness)

Per scrutiny vector #3, the pre-migration `COUNT(*) - COUNT(DISTINCT …)` check needs to run on the production schema before the UNIQUE migrations land. **Cannot run this from a worktree against prod;** the migration must include the count assertion as part of its `up()` so deploy fails loud rather than corrupts. Will encode as a safety guard in the migration file itself per Step 5's gotcha #1.

---

## Decisions needed before Step 2

### Q1. Concern 1 platform-side enforcement (CRITICAL)

The platform's `AuthenticateApiKey` middleware reads only `X-API-Key` and resolves it to a `Partner`. There is **no** `X-Tenant-Id`/`X-Company-Id` reader on the platform side today. Three options:

- **Option A (recommend):** Inject `X-Tenant-Id` + `X-Company-Id` from the ERP side now. Platform ignores them today; audit trail is preserved at request source. File a follow-up issue for the platform team to add a reader middleware. ERP fix is **immediately load-bearing for audit/billing reconstruction** even before platform-side enforcement.
- **Option B:** Coordinate cross-team — land the platform-side reader and the ERP-side sender in lockstep.
- **Option C:** Defer the entire cluster until cross-team coordination is scheduled.

Recommend **A**. Document the platform-side enforcement gap as a follow-up; do not block this cluster on it.

### Q2. CheckPendingEnrichmentsCommand fleet-wide poll

The command iterates all pending enrichments fleet-wide (no tenant filter at line 39) and per-iteration calls `checkStatusRaw($dto->platformSubmissionId)`. There IS a per-record tenant the command knows about (the Product's `company_id` is implicit in `PendingEnrichmentDTO`). Three options:

- **Option A:** Re-bind `CompanyContext` to the per-record `company_id` before each iteration, so per-call `X-Tenant-Id`/`X-Company-Id` headers reflect the originating tenant.
- **Option B:** Attach a fixed `X-System: enrichment-poller` header and skip per-record tenant context. Treats the command as a system-level fleet operation.
- **Option C:** Skip — cross-tenant-by-design docblock at line 16 is sufficient.

Recommend **A** (small N — `limit:50`). It satisfies the invariant strictly, reuses the per-record tenant the command already has, and keeps the audit trail consistent. Requires `PendingEnrichmentDTO` to carry `tenantId` + `companyId` (verify in Step 1.5 if approved).

### Q3. uploadPhoto() direct Http::withBody bypass

`ProductSubmissionService::uploadPhoto` (line 38-44) bypasses `PlatformHttpClient` and PUTs to a platform-issued pre-signed URL. The pre-signed URL IS the credential — tenant context is implicit (the pre-signed URL was minted in response to a `requestUploadUrl` call that is itself tenant-bound). Two options:

- **Option A:** Leave as-is. Pre-signed URL semantics make tenant headers redundant.
- **Option B:** Add tenant headers anyway for end-to-end audit consistency.

Recommend **A**. The pre-signed URL is a one-shot upload credential and tenant attribution lives at the prior `requestUploadUrl` call.

### Q4. Scope expansion (outside PlatformIntegration)

Two paths surfaced that aren't in the cluster brief but match the same pattern:

- **GrowthAdvisorHttpClient** — no auth headers AT ALL, no tenant headers; companyId in URL path. Likely a separate `api.progression` or `api.growth-advisor` cluster.
- **StripePaymentProvider** — Stripe SDK with global `secret_key`; metadata is the only mechanism to encode tenant. Need to audit metadata callers — likely a separate `api.billing` cluster (which Finding D + Finding E from the cross-cluster observations doc are already pointing toward).

Recommend **DEFER both**. File as follow-up clusters in inventory. This cluster's scope already absorbs Findings A + F per the brief's up-front decision; expanding further bloats the cluster.

### Q5. UNIQUE migrations on existing data (per scrutiny vector #3)

Production data may contain duplicates:

- For `products.platform_submission_id`: source IDs are platform UUID PKs, so duplicates would be a data-integrity emergency (manual writes, restore conflicts, etc.). The migration's `up()` must run a count check before adding the constraint and fail loud.
- For `billing_payments.provider_payment_id`: Stripe's `pi_*` namespace is per-account-globally-unique by contract. Duplicates would indicate a webhook deduplication bug (Finding E in cross-cluster observations is exactly this — missing event-id idempotency).

Recommend: encode the count check INSIDE the migration's `up()` so deploy aborts on a non-zero result. Surface to orchestrator if the check fails.

---

## Test surface estimate

If approved per Q1=A, Q2=A, Q3=A, Q4=defer, Q5=fail-loud:

**Concern 1 RED tests** (`OutboundHttpTenantTaggingTest.php`):
- 4-6 PlatformHttpClient methods × `Http::fake()` capture × assert headers carry `CompanyContext` tenant_id + company_id
- Cross-tenant control: switch `CompanyContext` mid-test, assert second call carries new tenant
- `CheckPendingEnrichmentsCommand` per-iteration tenant rebind test (if Q2=A)

**Concern 2 RED tests** (`ExternalIdUniquenessTest.php`):
- Migration test: assert `unique` constraint exists on `products.platform_submission_id`
- Migration test: assert `unique` constraint exists on `(provider, provider_payment_id)` of `billing_payments`
- Collision test: seed two products with same `platform_submission_id` BEFORE migration, assert resolver throws via `->sole()` after code change
- Provider-filter test: seed Stripe + Mollie payments with same `provider_payment_id` value; assert `StripeWebhookController` resolver returns Stripe row (not Mollie)

**Estimated total:** ~12-15 new test methods.

---

## Files in scope (assuming Q1=A, Q2=A, Q3=A, Q4=defer)

### Code changes

- `apps/api/app/Modules/PlatformIntegration/Infrastructure/Http/PlatformHttpClient.php` — inject `CompanyContext`, add `addTenantHeaders()` to `buildRequest()`
- `apps/api/app/Modules/PlatformIntegration/Application/Commands/CheckPendingEnrichmentsCommand.php` — per-iteration `CompanyContext` rebind (if Q2=A); requires `PendingEnrichmentDTO` to carry tenant+company
- `apps/api/app/Shared/DTOs/PendingEnrichmentDTO.php` — add `tenantId` + `companyId` (if Q2=A)
- `apps/api/app/Shared/Contracts/EnrichmentQueryInterface.php` + Eloquent impl — populate the new fields (if Q2=A)
- `apps/api/app/Modules/Product/Application/Listeners/ProcessEnrichmentEventListener.php` — `->first()` → `->sole()` (or count guard)
- `apps/api/app/Modules/Billing/Presentation/Controllers/StripeWebhookController.php` — add `provider` filter to 3 callsites (449, 473, 502); `->first()` → `->sole()` for those

### New migrations

- `apps/api/database/migrations/2026_05_08_xxxxxx_add_unique_to_products_platform_submission_id.php`
- `apps/api/database/migrations/2026_05_08_xxxxxx_add_unique_to_billing_payments_provider_payment_id.php`

### New tests

- `apps/api/tests/Feature/PlatformIntegration/OutboundHttpTenantTaggingTest.php`
- `apps/api/tests/Feature/PlatformIntegration/ExternalIdUniquenessTest.php`

### Inventory rows (Step 2)

- `manual:api.platform-integration:outbound-http-tenant-tagging-platform-http-client` (covers 4 methods + buildRequest)
- `manual:api.platform-integration:outbound-http-tenant-tagging-check-pending-enrichments-command` (if Q2=A)
- `manual:api.platform-integration:external-id-uniqueness-products-platform-submission-id` (covers listener + migration)
- `manual:api.platform-integration:external-id-uniqueness-billing-payments-provider-payment-id` (covers controller + migration)

---

## Stop conditions

None triggered by this triage. Surfacing decision points Q1-Q5 to the orchestrator before proceeding to Step 2.
