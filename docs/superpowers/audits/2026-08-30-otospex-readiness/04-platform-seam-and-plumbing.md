# 04 — Platform seam & plumbing for an AI Sourcing/RFQ agent

**Date:** 2026-08-30 · **Type:** read-only architecture audit · **Scope:** where an AI sourcing/RFQ agent
("understand a parts need → request quotes from suppliers over their linked channels → collect replies →
compare and mix-and-match for best price") would sit, and what plumbing already exists.

**Repo root for all paths below:** `/Users/houssamr/Projects/syneriva`
(ERP paths are under `apps/erp/`; sibling `apps/erp.*` worktrees were excluded from every grep).

**Method:** file reads + greps only. No code was modified, no service started, no test suite run.
Every claim below carries a `path:line`. Absences are stated as absences with the grep that proved them.

---

## 0. Headline findings (read these first)

1. **The RFQ concept already exists — in the ERP, not the platform.** `purchase_quote_request` is a
   first-class `documents` type with a supplier fan-out (`group_id`), a send transition, a structured
   response recorder and an **award → PO converter**:
   `apps/erp/apps/api/app/Modules/Procurement/Presentation/routes.php:44-73`,
   `apps/erp/apps/api/app/Modules/Procurement/Application/CreateRfqData.php:13-18`,
   `apps/erp/apps/api/app/Modules/Procurement/Domain/Dto/RfqPayload.php:12-20`,
   `apps/erp/apps/api/app/Modules/Procurement/Application/PurchaseQuoteRequestAwardService.php:26-86`.
   The platform has **no** RFQ concept at all (`grep -rniE "\brfq\b|request for quot|quote request"` over
   `apps/platform`, `docs/erp-pipeline`, `docs/03-ERP-INTEGRATION`, `claude` → no hits).
   ⇒ Any new sourcing service must treat the ERP's `purchase_quote_request` as the **existing surface for
   this concept** (`apps/erp/docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md`, ERP CLAUDE.md rule 22), not
   invent a second one.

2. **Award today is single-winner, not mix-and-match.** `PurchaseQuoteRequestAwardService::award()`
   converts *one* winning RFQ document into a PO and cancels every sibling with `closed_reason: 'lost'`
   (`…/PurchaseQuoteRequestAwardService.php:59-82`). "Mix-and-match for best price" — award line A to
   supplier 1 and line B to supplier 2 — is **not implementable against this service as written**; it needs
   a per-line award path. This is the single largest functional gap in the target feature.

3. **`POST /purchase-quote-requests/{id}/send` does not send anything.** It only stamps
   `payload.rfq.sent_at` (`…/Presentation/Controllers/PurchaseQuoteRequestController.php:172-183` →
   `PurchaseQuoteRequestService::markSent()`; `grep -n "Mail::|Notification::"` over
   `PurchaseQuoteRequestService.php` → zero hits). Actual supplier delivery would have to go through the
   generic document mailer at `apps/erp/apps/api/app/Modules/Document/Presentation/routes.php:460`.

4. **There is no machine credential anywhere in the ERP.** `grep -rn "tokenCan"` over
   `apps/erp/apps/api/app/` → **zero hits**; `config/auth.php:41-54` defines only `web`, `sanctum` (users),
   `sanctum-admin`. Every `createToken(` call site is a human-login or impersonation flow. An external agent
   would today have to hold a *person's* Sanctum PAT with that person's full permission set.

5. **No outbound comms provider is provisioned anywhere.** `MAIL_MAILER=log` in both
   `apps/platform/.env.example:62` and `apps/erp/apps/api/.env.example:124`; `.env.production.example` has
   **no `MAIL_*` block at all**; `grep -rniE "SMTP|SENDGRID|MAILGUN|POSTMARK|RESEND|TWILIO|WHATSAPP|WABA|GRAPH_API|TELEGRAM|VONAGE|IMAP"`
   over all `.env*` / compose files → only the eight `MAIL_*` lines above. `config/services.php:17-27`
   declares `postmark`/`ses`/`resend` keys but they are unset and no mailer is wired to them.
   **Inbound** email (reading supplier replies) has no plumbing whatsoever — no IMAP, no inbound-webhook route.

6. **There is no durable workflow engine in any Python service.** Zero matches for
   `temporal|prefect|airflow|statemachine|transitions|saga` across `apps/platform-ml`, `apps/erp-ml`,
   `apps/data-acquisition`, `apps/growth-advisor`. The three "job" mechanisms that exist are an append-only
   audit row (`agent_runs`), an **in-process dict** (`apps/data-acquisition/app/api/routes/enrichment.py:77`,
   comment: *"In-memory state (simple for now — could move to Redis)"*), and a `status` string column.
   Nothing survives a restart; nothing can wait for days. A sourcing agent that waits for supplier replies
   over days **cannot be built on the current Python state model** without new durable state.

7. **Human-in-the-loop is a dead end today.** `apps/data-acquisition/app/automotive/toolbelt/human_tool.py:8`
   defines a `request_human_review` tool that returns `{"action": "escalate", …}` — and *nothing consumes it*.
   `AgentStatus` (`apps/data-acquisition/app/shared/agents/types.py:12-17`) has no `AWAITING_*` member.

8. **The best-in-repo async pattern is in PHP, not Python.** `EnrichBarcodeSubmissionJob`
   (`apps/platform/app/Modules/ProductLookup/Infrastructure/Jobs/EnrichBarcodeSubmissionJob.php:20-219`) is a
   complete, restart-safe, queued, retrying, tracking-id-addressable, **human-review-gated**
   (`BarcodeLookupStatus::ReadyForReview`) job with HMAC-signed webhook callback to the partner. Its state
   machine is a real enum with explicit transitions
   (`…/Domain/Enums/BarcodeLookupStatus.php:43-65`). This is the template to copy.

---

## 1. Platform architecture — actuals vs docs

### 1.1 Modules that actually exist

`apps/platform/app/Modules/` (10 dirs), each registered explicitly in `apps/platform/bootstrap/providers.php:6-14`:

| Module | Layer (per `claude/vertical-boundaries.md`) | DB / connection | Routes | Evidence |
|---|---|---|---|---|
| `Catalog` | platform | `pgsql` (default) | `api/v1/catalog/*` | `app/Modules/Catalog/Presentation/routes.php:10-22` |
| `Partners` | platform | `pgsql` | none (Filament-only) | `app/Modules/Partners/Presentation/routes.php:7-9` |
| `DataManagement` | platform | `pgsql` | `api/v1/contributions/*` | `app/Modules/DataManagement/Presentation/routes.php:8-14` |
| `DataPlatform` | platform | `pgsql` | none (models only) | `app/Modules/DataPlatform/` — 8 files, all Domain |
| `ProductLookup` | platform | `pgsql` | `api/v1/products/*`, `api/v1/brands/*` | `app/Modules/ProductLookup/Presentation/routes.php:13-35` |
| `PurchaseHub` | platform | `pgsql` | `api/v1/purchase-hub/*` | `app/Modules/PurchaseHub/Presentation/routes.php:16-52` |
| `Automotive` | automotive vertical | `car_parts` | `api/v1/automotive/*` | `app/Modules/Automotive/Presentation/routes.php:14-51` |
| `VinDecoding` | automotive vertical | `pgsql` (pre-Plan-B2) | `api/v1/vin/*` | `app/Modules/VinDecoding/Presentation/routes.php` |
| `Parapharmacy` | parapharmacy vertical | `parapharmacy` | `api/v1/parapharmacy/*` | `app/Modules/Parapharmacy/Presentation/routes.php` |

DB connections: `apps/platform/config/database.php:34` `pgsql`, `:49` `car_parts`, `:64` `parapharmacy`,
`:80` `parapharmacy_local`, `:95` `tecdoc_source` (MySQL), `:116` `mysql_otospex` (MySQL).

### 1.2 Module registration convention

There is **no auto-discovery**. A module ships a `ServiceProvider` that does exactly one thing —
`Route::middleware('api')->group(__DIR__.'/../Presentation/routes.php')` — and is added by hand to
`bootstrap/providers.php`. Canonical example: `app/Modules/PurchaseHub/Providers/PurchaseHubServiceProvider.php:17-26`.
Console commands are registered in the same `boot()` under `runningInConsole()`
(`app/Modules/Partners/PartnersServiceProvider.php:27-31`).

Hexagonal layout is specified in `claude/shared-patterns.md:9-35` (`Domain/ Application/ Infrastructure/
Presentation/ + {Module}ServiceProvider.php`) and is followed in practice — `PurchaseHub` and `ProductLookup`
are the closest to the ideal.

### 1.3 Auth available to external callers

**One mechanism only: a partner API key.** There is no OAuth, no per-tenant key, and — despite the docs —
no inbound service-token guard.

- `AuthenticateApiKey` (`app/Modules/Partners/Infrastructure/Middleware/AuthenticateApiKey.php:17-66`):
  `X-API-Key` → `sha256` → `api_keys.key_hash`, requires `is_active` and an active `Partner`
  (`:30-42`). Increments `request_count` and touches `last_used_at` (`:45-46`). Sets request attributes
  `partner`, `partner_id`, `api_key`, `tenant_id` (`:60-63`).
- **Tenant identity is an opaque, UNAUTHENTICATED discriminator.** `AuthenticateApiKey::tenantIdFrom()`
  (`:76-89`) reads `X-Tenant-Id`, allows `''` (partner-global), rejects >64 chars or control chars. The
  doc-block at `:68-75` is explicit: *"Not authenticated — it only namespaces the partner's own data
  (brand mappings), never widens access."* **The platform cannot today tell one tenant of a partner from
  another in any security-relevant sense.** This is the single most important constraint on a sourcing
  agent that would act on a tenant's behalf.
- Aliases registered at `apps/platform/bootstrap/app.php:22-29`: `auth.api_key`, `enforce_api_quota`,
  `enforce_country_access`, `throttle.automotive`, `log_api_usage`, `resolve_locale`.
- `laravel/sanctum` is in `composer.json:12` but **is not used** — `grep -rn "sanctum"` over
  `apps/platform/{app,config,bootstrap}` returns only the composer line.

### 1.4 Rate limiting / quota

- `EnforceApiQuota` (`app/Modules/Partners/Infrastructure/Middleware/EnforceApiQuota.php:19-72`) — monthly
  cap from `SubscriptionTier::monthlyRequestLimit()` (`Domain/Enums/SubscriptionTier.php:46-54`:
  10k/100k/1M/unlimited), 429 + `Retry-After` + `X-RateLimit-*` headers.
- `LogApiUsage` (`…/LogApiUsage.php:19-47`) — writes a row per request to Postgres `api_usage_logs`.
- `SubscriptionTier::rateLimitPerHour()` (`:33-41`, 100/1k/10k/100k) is **declared but never enforced** —
  `Partner::getRateLimitPerHour()` (`Domain/Partner.php:151-154`) has no caller in any middleware.

### 1.5 Where a "Sourcing" module would go

Apply the `claude/vertical-boundaries.md:47-53` test — *"Does this module's core value depend on a specific
vertical's data model?"* Sourcing/RFQ depends on: a need (quantity + a product reference), a set of
suppliers, and a price comparison. None of that is automotive- or parapharmacy-specific; the existing
cross-vertical marketplace module `PurchaseHub/*` is listed as platform-layer for exactly this reason
(`claude/vertical-boundaries.md:17`). ⇒ **Sourcing is platform-core, `platform` DB, `pgsql` connection.**

**Table naming.** `claude/database-topology.md:21-43` gives Tier 1 (cross-vertical → canonical *unprefixed*
name in `platform`) and Tier 3 (vertical + generic name → module prefix). Sourcing is Tier 1, so the rule
literally permits unprefixed `quotes` / `offers` / `messages`. **Do not do that** — `quotes`, `offers`,
`messages`, `threads` are on the magnet-word list at `:41`, and the `platform` DB already carries unprefixed
`purchase_orders`, `campaigns`, `supplier_profiles` from PurchaseHub, so a bare `quotes` would be ambiguous
against them. Existing platform-layer practice already resolves this with compound, self-disambiguating
names: `api_usage_logs`, `webhook_deliveries`, `partner_features`, `barcode_submissions`,
`partner_brand_mappings`. Follow that: `sourcing_requests`, `sourcing_request_lines`, `supplier_quotes`,
`supplier_quote_lines`, `sourcing_channel_messages`, `sourcing_awards`.
**Rule gap to log:** `database-topology.md` has no tier for *"platform-layer module with a generic name."*
Tier 1 as written would sanction a bare `quotes`. Worth a one-line amendment.

### 1.6 Doc ↔ code drift found (platform side)

| # | Doc claim | Reality | Evidence |
|---|---|---|---|
| D1 | Tier-1 platform tables include `audit_log`, `feature_flags`, `webhook_subscriptions` | **None of the three exist.** Actual equivalents: `api_usage_logs`, `partner_features`, `webhook_deliveries` | `claude/architecture.md:10`, `claude/database-topology.md:26` vs `grep -rln "audit_log\|feature_flags\|webhook_subscriptions" apps/platform/database/migrations/` → no hits; `…/2026_02_28_000001_create_api_usage_logs_table.php`, `…/2026_03_26_000002_create_partner_features_table.php`, `…/2026_03_27_000003_create_webhook_deliveries_table.php` |
| D2 | An **Internal API** exists behind a service token (`POST /api/v1/internal/enrich`, `/internal/scraper/ingest`), and routes should use `auth.service_token` | **No `auth.service_token` middleware, no `internal` route, no inbound service-token guard exists.** The service token is *outbound only* (platform → data-acquisition) | `claude/platform-api.md:252-257`, `claude/shared-patterns.md` routes-pattern §; vs `grep -rn "service_token" apps/platform/app config bootstrap` → only `config/services.php:40` + two outbound callers (`ProductLookup/…/EnrichBarcodeSubmissionJob.php:56`, `VinDecoding/VinDecodingServiceProvider.php:45`); `grep -rn "internal" --include=routes.php app/Modules/` → no hits |
| D3 | "Rate limiting tracked per partner **in ClickHouse**" | Tracked in **Postgres** (`api_usage_logs` row per request) and in `api_keys.request_count`. ClickHouse `api_requests` table exists but has no writer | `claude/shared-patterns.md` §Rate Limiting vs `LogApiUsage.php:34-43`; `data/migrations/001_create_tables.sql:63-85` and see §4.1 |
| D4 | Modules `MarketIntelligence/`, `Marketplace/`, `GroupBuy/` | Do not exist (marked "Phase 2" in the same doc, so low-severity, but the tree is presented as current) | `claude/platform-api.md:41-43` vs `ls apps/platform/app/Modules` |
| D5 | `Partners/Infrastructure/Middleware/AuthenticateApiKey` sample omits the tenant discriminator | Real middleware rejects malformed `X-Tenant-Id` with 400 and sets a `tenant_id` attribute | `claude/platform-api.md:277-311` vs `AuthenticateApiKey.php:48-63` |
| D6 | `Catalog` module tree shows `Application/Queries/`, `Infrastructure/Repositories/`, `Presentation/Requests/` | None of those dirs exist in `Catalog` | `claude/platform-api.md:79-95` vs `find app/Modules/Catalog -type d` |
| D7 (ERP-side, ratified elsewhere) | "Tenant identity … Not transmitted by ERPs" | ERP sends `X-Tenant-Id` **and** `X-Company-Id` as mandatory headers on every call | `docs/03-ERP-INTEGRATION/01-integration-overview.md:392,405-406` vs `apps/erp/apps/api/app/Modules/PlatformIntegration/Infrastructure/Http/PlatformHttpClient.php:266-272` (applied `:242`) — already ratified in `docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md:79-93`, so the overview is the stale half |
| D8 (ERP-side) | Documented endpoint surface (`/catalog/barcode/{barcode}`, `/contributions/products`, `/catalog/products?updated_since=`) | Not one of those paths is called by the ERP. Real calls are `/products/*`, `/brands/*`, `/verticals/*`, `/automotive/*`, `/purchase-hub/tenant/*` | `docs/03-ERP-INTEGRATION/01-integration-overview.md:99,120,175,194,212,221` vs `apps/erp/apps/api/app/Modules/PlatformIntegration/Application/Services/{BarcodeLookupService,CatalogBrowseService,ProductSubmissionService}.php` |
| D9 (ERP-side) | Webhook contract | `/api/v1/webhooks/syneriva` + `X-Syneriva-Signature`/`X-Syneriva-Timestamp` HMAC appears in **no** `03-ERP-INTEGRATION` doc; and `SYNERIVA_WEBHOOK_SECRET` (required by `config/services.php:107`, missing secret ⇒ every webhook 403 at `VerifySynerivaWebhookSignature.php:22-24`) is **absent from `.env.example:144-149`** | as cited |
| D10 (ERP-side) | — | `POST /api/webhooks/purchase-hub` is **unauthenticated and unsigned** (middleware `['api']` only) — a deliberate logging stub guarded by an architecture test, but undocumented in the integration docs | `apps/erp/apps/api/app/Modules/PurchaseHub/Presentation/routes.php:23-27`, `…/Controllers/PurchaseHubWebhookController.php:69-76`, `apps/erp/apps/api/tests/Architecture/WebhookControllerTenantContextTest.php` |

---

## 2. The ERP ↔ platform call path today

### 2.1 ERP → platform (outbound)

`apps/erp/apps/api/app/Modules/PlatformIntegration/` (29 files). Client:
`Infrastructure/Http/PlatformHttpClient.php`.

| Concern | Value | Evidence |
|---|---|---|
| Base URL | `config('services.platform.url')` ← `SYNERIVA_PLATFORM_URL`, default `http://localhost:8080` | `PlatformHttpClient.php:237,240`; `apps/erp/apps/api/config/services.php:105`; `.env.example:146` |
| Auth | `X-API-Key: config('services.platform.api_key')` — **one partner-level shared key for the whole ERP install** | `PlatformHttpClient.php:238,241`; `config/services.php:106` |
| Tenant identity | `X-Tenant-Id` + `X-Company-Id` from `CompanyContext`, **mandatory**, `require*()` throws if unbound | `PlatformHttpClient.php:266-272`, applied `:242`, rationale `:250-265` |
| Timeouts | `timeout(10)`, `connectTimeout(5)` | `:243-244` |
| Retries | `retry(3, 200ms)` on `ConnectionException` or 429/500/502/503/504 | `:245-247` |
| Circuit breaker | 3 failures / 30 s → open 30 s (`platform:circuit_breaker`, `platform:circuit_failures`) | `:17-25, :230-233, :290-303` |
| Caching | per-service, not in client (`CatalogBrowseService.php:317,325`, TTL 86400 at `:29`) | as cited |

Endpoints consumed (complete list): `POST /api/v1/products/{lookup,upload-url,submit,bulk-submit,bulk-lookup}`,
`GET /api/v1/products/lookup-status/{trackingId}`, `POST …/feedback`, `POST /api/v1/products/{trackingId}/enrich`,
`POST /api/v1/brands/{id}/external-mapping`, `GET /api/v1/verticals/{v}/categories/{c}/attributes`
(`BarcodeLookupService.php:63`, `ProductSubmissionService.php:36,76,119,134,163,179-180,214-215,270,286`);
14 `api/v1/automotive/*` paths (`CatalogBrowseService.php:29-230`); five
`api/v1/purchase-hub/tenant/*` paths (`apps/erp/apps/api/app/Modules/PurchaseHub/Application/Services/PurchaseHubService.php:45,64,90,104,118`).

**Per-tenant identity the platform knows:** an *opaque string* the ERP chose, scoped inside one partner.
Not authenticated (see §1.3), not a platform-side row, no `tenants` table on the platform holding it
(`claude/architecture.md:10` and `claude/database-topology.md:9,26` list a tier-1 `tenants` table; **no
migration creates one** — `grep -rn "Schema::create('tenants'" apps/platform/database/migrations/` → no hits.
Add this to drift D1.)

### 2.2 Platform → ERP (inbound callbacks)

- **`POST /api/v1/webhooks/syneriva`** — `apps/erp/apps/api/app/Modules/PlatformIntegration/Presentation/routes.php:17-22`,
  middleware `['api', VerifySynerivaWebhookSignature::class]` (no `auth:sanctum`, by design at `:16`).
  HMAC: `'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret)`, 300 s freshness, `hash_equals`
  (`Infrastructure/Middleware/VerifySynerivaWebhookSignature.php:14,18-38`). Controller does no DB work;
  dispatches `ProcessEnrichmentWebhookJob` on queue `enrichment`, tenant anchored from the body
  (`Presentation/Controllers/EnrichmentWebhookController.php:37-49,63-68`).
- **`POST /api/webhooks/purchase-hub`** — unauthenticated logging stub (see D10).
- **No outbound subscription registration.** `grep -rni "webhook_url|webhookUrl|subscribe.*webhook|register.*webhook"`
  over `apps/erp/apps/api/app/` → no non-Stripe hits. The callback URL is configured **platform-side** on
  the `Partner` row (`apps/platform/app/Modules/Partners/Domain/Partner.php:26-27,67-68,94` — `webhook_secret`
  is `encrypted`, `webhook_url` plain).

### 2.3 What the agent would need to call back INTO the ERP — and whether it can

| Capability the agent needs | ERP endpoint today | Usable by a machine caller? |
|---|---|---|
| Read supplier list + contacts | `GET /api/v1/partners` (`can:partners.view`), `GET /api/v1/partners/{p}/contacts` — `apps/erp/apps/api/app/Modules/Partner/routes.php:22,26,66`; group middleware `:20`, no `module:` gate | **Yes**, with a user PAT |
| Read a parts need from a work order | `GET /api/v1/workshop/work-orders`, `…/{id}` — `apps/erp/apps/api/app/Modules/Workshop/WorkOrder/Presentation/routes.php:28-38`, middleware incl. `module:Workshop` at `:26` | **Partially** — must enumerate work orders; no aggregate "parts needed" read |
| Read replenishment need | `GET /api/v1/replenishment-requests` (`can:replenishment.view`) — `apps/erp/apps/api/app/Modules/Replenishment/Presentation/routes.php:14` | **Yes** |
| Read low stock | `GET /api/v1/stock-levels`, `/inventory/stock-matrix` — `apps/erp/apps/api/app/Modules/Inventory/Presentation/routes.php:58,70`, `module:Inventory` at `:31`. No low-stock endpoint (`grep -rn "low-stock\|lowStock\|below-min\|stock-alerts" --include=routes.php app/Modules/` → no hits) | **Partially** — "low" must be computed client-side |
| Create an RFQ fan-out | `POST /api/v1/purchase-quote-requests` (`can:purchase-quote-requests.create`) — `Procurement/Presentation/routes.php:51` | **Yes** |
| Mark RFQ sent | `POST …/{id}/send` — `:66` | **Yes**, but it only stamps `sent_at` (§0.3) |
| Record a supplier's quoted prices | `PUT …/{id}` — `:62`; fields `lines[].unit_price` (≤3 dp), `validity_date`, `supplier_reference`, `lead_time_days` (`Presentation/Requests/UpdatePurchaseQuoteRequestRequest.php:34-56`) | **Yes** |
| Attach the supplier's PDF/email | `POST /api/v1/documents/{document}/attachments` — `apps/erp/apps/api/app/Modules/Media/routes.php:30` | **Yes** |
| Award **one** supplier → PO | `POST …/{id}/convert-to-po` — `Procurement/Presentation/routes.php:70` | **Yes** |
| Award **per line** (mix-and-match) | — | **NO — does not exist** (§0.2) |
| Create a PO directly | `POST /api/v1/purchase-orders` (`can:purchase-orders.create`) — `apps/erp/apps/api/app/Modules/Document/Presentation/routes.php:287`; required input per `Document/Presentation/Requests/CreateDocumentRequest.php:66,80-81,100,119-124` | **Yes** |
| Email a document to a supplier | `POST /api/v1/documents/{document}/email` — `Document/Presentation/routes.php:460`, `throttle:document-email` | **Yes** — but `MAIL_MAILER=log` (§0.5) |

**Auth verdict.** Every row above is reachable **only with a human user's Sanctum PAT** carrying that user's
full `can:` set. `EnforceTokenTenantClaim`
(`apps/erp/apps/api/app/Modules/Identity/Presentation/Middleware/EnforceTokenTenantClaim.php:36,47-60`)
rejects a *mismatched* `tenant:<uuid>` ability but lets tokens with **no** `tenant:` ability through
(grandfathering). Sanctum abilities are never checked (`tokenCan` — zero hits).

**Supplier contact channels.** `partners` has `email` + `phone` only
(`apps/erp/apps/api/database/migrations/tenant/2025_11_30_052119_create_partners_table.php:16-34`), plus
per-person `contacts.{email,phone,mobile}`
(`…/2026_03_10_100001_create_contacts_table.php:16-31`, joined via `…/2026_03_10_100002_create_party_contacts_table.php:18-33`).
`grep -rn "whatsapp\|whats_app"` over `Partner`, `Contact`, `database/migrations/` → **zero hits.**
There is **no channel-preference field** on a supplier — "their linked channels" does not exist as data.

**Adjacent prior art worth knowing:** the ERP's `Channel` module already implements a
per-tenant, credential-storing, adapter-registry integration pattern —
`Application/Contracts/ChannelAdapter.php:17-37`, `Application/Services/AdapterRegistry.php:11-54`,
`Domain/Models/ChannelCredential.php`, `Domain/Enums/CredentialType.php:7-13`
(`oauth_token|api_key|consumer_key_secret|custom`), `Presentation/Controllers/ChannelWebhookController.php`.
It is aimed at *sales* channels (push product/stock/price, pull orders) and ships **no concrete adapters**
(`AdapterRegistry.php:35`: *"Concrete adapters ship in a follow-up sprint"*), but the shape — registry +
per-tenant encrypted credentials + webhook ingress + sync-operation audit — is exactly what a supplier
comms-channel layer needs.

---

## 3. Python services — what exists and what does not

| | `apps/platform-ml` | `apps/erp-ml` | `apps/data-acquisition` | `apps/growth-advisor` |
|---|---|---|---|---|
| Real code? | **No** — routes return hardcoded placeholders (`app/api/routes/predictions.py:122-136`) | Partial — recommendations + Claude extraction real; forecasting/churn stubbed | **Yes** — the only real agent service | Yes |
| FastAPI entry | `app/api/main.py:49` | `app/api/main.py:60` | `app/api/main.py:47` | `app/api/main.py:83` |
| Celery app | **none** (`app/tasks/` is an empty `__init__.py`) | **none** (same) | `app/celery_app.py:10`, broker Redis db 5 (`:8`) | none |
| Queues | — | — | **none declared** — no `task_routes`, no `queue=`, no `apply_async`; everything on default `celery` | — |
| Beat | — | — | `celery_app.py:30-41` — `validation-nightly-tn` 02:30 UTC, `composite-curation-weekly-tn` Mon 03:15 | — |
| Retries | — | — | **none** — no `autoretry_for`, no `max_retries`; LLM adapter explicitly does not retry (`app/automotive/llm/adapter.py:55-56`) and no caller wraps it | — |
| LLM | none | `anthropic==0.63.0` (`requirements.txt:27`), `app/services/extraction/claude_extractor.py:108,213,219` | `anthropic==0.69.0` (`requirements.txt:27`); 5 call sites incl. `app/automotive/llm/adapter.py:9,39,66` | `anthropic==0.43.0`, **sync client in async handlers** (`app/ai/providers/claude_provider.py:12,48,157`) |
| Agentic tool loop | — | — | **Hand-rolled and working**: `app/automotive/llm/adapter.py:43` `run_with_tools(..., max_iterations=8)`, full `tool_use`/`tool_result` loop `:65-127`, cost accounting `:74-76` | — |
| Tool registry | — | — | `app/automotive/toolbelt/registry.py:11` `ToolRegistry`; tools in `car_parts_tools`, `decoder_tools`, `registry_tools`, `composite_tool`, `scorer_tool`, `human_tool` | — |
| Budget guard | — | — | `app/automotive/budget/guard.py:10,40` — `SUM(cost_usd) FROM agent_runs` per agent/country/month vs `AGENT_MONTHLY_CAP_USD` (default 200); **fail-open** | — |
| Durable state | — | — | `agent_runs` audit row only (`app/shared/agents/base.py:52-102`, `app/shared/agents/writer.py:27,38,88,111`) | `status` string column (`app/db/models.py:79,141`), **Alembic migrations** (only Python service with them) |
| Scrape cache | — | — | `scrape_extracts`, 180-day TTL (`app/automotive/toolbelt/decoder_tools.py:70,111,166`) | — |
| HTTP/retry | `httpx`+`tenacity` declared, **never imported** | same | `httpx` used (`app/adapters/scrapfly_adapter.py:41` t=30 s, `app/identity/providers/claude_vision.py:49` t=15 s); `tenacity==8.2.3` (`requirements.txt:31`) **never imported** | — |
| Rate limiting | — | — | one fixed `asyncio.sleep` (`app/pipeline/catalog_image_enricher.py:159`) + a per-run Scrapfly cap (`app/core/config.py:72`). No semaphore, no token bucket, no 429 handling | — |
| Comms (email/WA/SMS) | **none** | **none** | **none** | **none** |

**Two ghost workers:** `docker-compose.yml:339` and `:464` launch `celery -A app.tasks.celery_app worker`
for platform-ml / erp-ml where **no such module exists** — those containers cannot boot. Conversely the one
service with a real Celery app + beat (`data-acquisition`) has **no worker/beat container in
`docker-compose.yml`** (only the API at `:470`), so its beat schedule never runs locally. The Dokploy compose
*does* have them: `docker-compose.dokploy.yml:391` `da-worker`, `:414` `da-beat`.

**The agent-run observability schema is genuinely reusable.** `agent_runs`
(`apps/platform/database/migrations/2026_04_21_000002_create_agent_runs_table.php:13-36`) carries
`agent_name, phase, trigger_source, trigger_payload jsonb, country_code, started_at, completed_at, status,
items_{processed,committed,flagged,rejected}, cost_usd decimal(10,4), errors jsonb, metadata jsonb`, with
Filament read-only resources at `apps/platform/app/Filament/Resources/{AgentRunResource,ValidationLogResource,DataSourceResource}`.
A sourcing agent gets cost tracking, per-run audit and an ops dashboard for free.

---

## 4. Data + search assets

### 4.1 ClickHouse — schema exists, **no writer exists**

`data/migrations/001_create_tables.sql` creates `price_observations` (`:12-34`, MergeTree, `ORDER BY
(vertical_code, country_code, product_id, event_time)`, TTL 2 y, carries `price Decimal64(4)`, `price_type`,
`source_type`, `source_partner_id`, `source_tenant_hash`, `confidence_score`), `stock_observations` (`:39-58`),
`api_requests` (`:63-85`), `market_events` (`:90-110`), `confidence_history` (`:115-132`), plus MVs
`price_daily_stats` (`:139-157` — min/max/avg per product per day per country) and `api_usage_hourly` (`:160-175`).
`data/migrations/002_erp_business_events.sql` adds `erp_business_events` (`:10-39`) + `company_daily_metrics`
(`:45-77`) + `company_monthly_metrics` (`:83-101`).

**Nothing writes to any of them.** `grep -rn "price_observations|erp_business_events|market_events|stock_observations"`
across the repo (excluding `data/migrations`) returns **only docs and plans** —
`claude/platform-ml.md:283`, `docs/01-ARCHITECTURE/01-system-overview.md:44-47`,
`docs/superpowers/plans/2026-03-23-growth-advisor-plan1-foundation.md:137,364`, etc. The only ClickHouse
*client* in code is a read-side wrapper: `apps/platform-ml/app/data/clickhouse.py:13-31`.
⇒ **`price_daily_stats` is an empty should-cost baseline. It is a designed slot, not an asset.**

### 4.2 Postgres — the price/cross-reference assets that DO have data paths

| Asset | Where | Notes |
|---|---|---|
| **TecDoc list prices** — `car_parts.prices` | `apps/platform/database/migrations/2026_05_30_000017_fix_prices.php:18-36`; unique `(article_id, country_id, price_type, valid_from)` at `:35` | **This is the real should-cost baseline that exists.** Per-article, per-country, typed, dated, currency-coded, with a discount group. Automotive only. |
| **Part cross-references** — `car_parts.article_cross_references` | `…/2026_05_30_000016_fix_article_cross_references.php:17-50` (widened `reference_number` to 255, COALESCE-sentinel unique index at `:46-50`); pattern index `…/2026_07_09_000001_add_article_cross_references_reference_number_pattern_index.php` | OE↔aftermarket↔EAN. Exposed as `GET /api/v1/automotive/articles/cross-reference` (`app/Modules/Automotive/Presentation/routes.php:34`). **Directly usable** — this is how the agent turns "this part" into "every supplier's number for this part". |
| **Retail price observations** — `parapharmacy.product_prices` | `…/2026_06_19_000001_create_product_prices_table.php:11-34` (`price decimal(15,3)`, `currency`, `in_stock`, `scraped_at`, `source_url`, FK `shop_id`) | Scraped competitor retail prices, parapharmacy only. Sell-side, not buy-side — a market reference, not a should-cost. |
| **PurchaseHub supply side** | `supplier_profiles` (`…/2026_03_27_100000…:13-32`), `campaigns`, `campaign_items`, `campaign_fulfillment_partners`, `campaign_notifications` (`…/100004`), `purchase_orders` (`…/100005:13-52`), `purchase_order_lines` | See §5. |
| **Contribution/conflict pipeline** | `contributions`, `conflicts` (renamed `2026_04_25_000003/000002`) | Partner-submitted data + conflict resolution — the write-back path for anything the agent learns. |

⚠ **Naming trap:** `car_parts.suppliers`
(`apps/platform/database/migrations/2026_02_27_000005_create_car_parts_suppliers_table.php:15-25`:
`tecdoc_id, brand, slug, data_source, is_verified`) is a **TecDoc part-manufacturer brand** (Bosch, TRW), NOT
a trade supplier you can send an RFQ to. The trade supplier is `platform.supplier_profiles` (a `Partner`
extension) or the ERP-tenant-local `partners` row. Three different things called "supplier" across two DBs
and the ERP — worth a `claude/glossary.md` entry before any sourcing spec is written.

### 4.3 Meilisearch

`SCOUT_DRIVER` defaults to `collection` (`apps/platform/config/scout.php:16`, `.env.example:39-42`), prefix
`syneriva_` (`:18`), queue off (`:20`). **Exactly one indexed model**: `Catalog\Domain\Product`
(`apps/platform/app/Modules/Catalog/Domain/Product.php:56` `use Searchable`, `:170` `toSearchableArray`).
Meilisearch runs in both composes (`docker-compose.yml:176`, `docker-compose.dokploy.yml:215`).
No supplier index, no offer index, no quote index.

### 4.4 ETL + sources

`data/etl/` — two standalone jobs, no scheduler: `vintn_import` (MySQL `vindb.vintn` :3308 → Postgres
`vehicle_registrations`, 1.77 M rows) and `bootstrap_legacy_mappings` (→ `vehicle_mappings`)
(`data/etl/README.md:7-10`). Column mapping is declarative YAML
(`data/etl/app/sources/otospex_vintn.yml:9-52`) with PII-discard rules (`:67-68`) — a good precedent for a
declarative supplier-price-list importer. Guardrail worth copying: `PLATFORM_DATABASE_URL` must win over
settings, after a 2026-04-21 incident leaked 100 rows into the production DB (`README.md:29,33`).

`data/sources/oto_para_cosmo_db_extra.sql` — a 23-line MySQL DDL for a `scrape_results` staging table
(`raw_html`, `extracted_price`, `extracted_in_stock`, `extraction_confidence`, `processing_status`,
`data_source` enum). Legacy staging, not a live supplier catalogue.

MySQL source connections in the platform: `tecdoc_source` (`config/database.php:95-114`, unbuffered
server-side cursor for 100 M+ rows) and `mysql_otospex` (`:116-135`).

**Bottom line for should-cost:** the agent has `car_parts.prices` (automotive TecDoc list price, real) and
`parapharmacy.product_prices` (scraped retail, real, wrong side of the market). It does **not** have a
historical *purchase* price baseline — the obvious one, the tenant's own posted supplier invoices, lives in
each ERP tenant DB (`apps/erp/apps/api/app/Modules/Procurement/Application/SupplierInvoicePostingService.php`)
and is not aggregated anywhere.

---

## 5. `PurchaseHub` — the closest existing thing, and why it is the wrong shape

`apps/platform/app/Modules/PurchaseHub/` (35 files) implements a **supplier-push** marketplace:
a supplier creates a `Campaign` with `CampaignItem`s → `TargetingService` resolves an audience →
`DeliverCampaignNotificationsJob` inserts `campaign_notifications` rows → tenants read offers and place
`purchase_orders`. Routes: `Presentation/routes.php:16-52` (tenant side `:17-26`, supplier side `:29-45`,
fulfilment `:48-51`), all behind `AuthenticateApiKey` with a `EnsureSupplierProfile` gate on the supplier side.
`purchase_orders` even carries an `erp_document_id` column (`…/100005:30`) — the ERP-side link is already anticipated.

**Why it is the wrong shape for sourcing:** it is push (supplier advertises → buyer picks), whereas an RFQ is
**pull** (buyer asks → suppliers answer). And its notification layer is in-app only —
`DeliverCampaignNotificationsJob.php:19-43` does nothing but bulk-`insert` rows with `status = 'pending'`;
`CampaignNotification` (`Domain/Models/CampaignNotification.php:31-41`) has `delivered_at`/`seen_at` but
**no channel, no address, no delivery job**. There is no supplier-facing outbound message anywhere in the
platform.

**Right shape to reuse:** `SupplierProfile` (a `Partner` extension with `country_code`, `regions`, `vertical`,
`commission_rate`, `settings` jsonb — `Domain/Models/SupplierProfile.php:35-68`) is a perfectly good
supplier registry for RFQ targeting, and `TargetingService` is a perfectly good audience resolver.

---

## 6. Deployment

**Model:** Dokploy on AX42 (`176.9.139.218`, serverId `qGHTlaxZiOsEMUUE99dio`, project
`-zxxTMAPEHfqiBvDtNeXf`, prod env `WqstMy3xYELBtDPtApQD2`) — `claude/deploy-runbook.md:7-14`.
App-per-service, not compose-mode (`docs/superpowers/plans/2026-04-25-hetzner-dokploy-app-per-service.md:7`).

**Adding a new service — the pattern already exists, verbatim, for a Python worker trio:**
`docker-compose.dokploy.yml:367` (`data-acquisition`: build context `./apps/data-acquisition`, healthcheck
`/health`, `syneriva-internal` network), `:391` (`da-worker`: same image, `command: ["celery","-A","app.celery_app","worker"]`,
1 G memory limit), `:414` (`da-beat`: same image, `celery … beat`). A new Python service is three near-identical
blocks. A new PHP module needs **no** new service — it ships inside the existing `platform` app
(`:298`) with `platform-worker` (`:343`) already consuming its queues.

**Migrations:** the platform image's entrypoint runs `php artisan migrate --force` before supervisord since
2026-07-07; **only the API container migrates** (worker/scheduler override `command`), and
`AUTO_MIGRATE=false` opts out (`claude/deploy-runbook.md:56-65`).

**Dokploy gotchas to inherit:** `application.create` needs `serverId` or you get a misleading 401 (`:34-36`);
`application.saveEnvironment` MCP wrapper is broken — use `application-update` with `env` (`:38-40`);
`appName` gets a random 5-char suffix that becomes the internal hostname (`:46-48`).

**Secrets:** `.env.production.example` is the canonical Dokploy secret list (85+ lines: Postgres ×3 + admin
roles, Redis, Meilisearch, MinIO, ClickHouse). Per-service secrets from the first deploy are still in
`/tmp/syneriva-deploy-secrets/` on the operator's laptop pending a password manager
(`claude/deploy-runbook.md:89-91`).

**Comms providers: none provisioned** — see §0.5. Adding email means (a) picking a provider, (b) adding
`MAIL_*`/`RESEND_KEY`/`POSTMARK_TOKEN` to `.env.production.example` **and** to the Dokploy env of *both* the
platform app and the worker, (c) SPF/DKIM/DMARC on the sending domain. WhatsApp means a Meta WABA + a BSP —
zero groundwork exists.

**Existing worker/queue estate:** platform Laravel queues in use are `webhooks`
(`ProductLookup/Infrastructure/Jobs/DispatchWebhookJob.php:29`) and `enrichment`
(`EnrichBarcodeSubmissionJob.php:35`), consumed by `platform-worker` (`docker-compose.dokploy.yml:343`).
Note the ERP's rule 20 analogue: a new named queue needs a matching worker, or it is silently never consumed.

---

## 7. MCP

**There is no MCP server code anywhere in this repo.**
`grep -rniE "\bmcp\b|model.context.protocol|fastmcp"` over `apps/{platform,platform-ml,erp-ml,data-acquisition}`,
`claude/`, `docs/`, `data/`, `infra/` (excluding `node_modules`, `vendor`, `.venv`, `site-packages`,
`.playwright-mcp`, JSON fixtures) returns **only**: (a) operational notes about *consuming* the Dokploy and
Playwright MCP servers as a client (`claude/deploy-runbook.md:30,38`,
`docs/superpowers/plans/2026-06-09-tecdoc-v3-ingest-kickoff.md:18`,
`docs/superpowers/plans/2026-04-25-hetzner-dokploy-app-per-service.md:22,40,55,58,64`), and (b) design/idea docs.
`grep -rn "fastmcp|modelcontextprotocol|mcp\["` over every `requirements*.txt`, `pyproject.toml`,
`package.json` → **no hits**. No `.mcp.json` in the repo (only `.playwright-mcp` browser-profile dirs).

**There is, however, an explicit plan:**
- `docs/superpowers/specs/2026-04-18-synerivia-ecosystem-roadmap-design.md:180` — Phase 3 "Agentic surface"
  exit criterion: *"ERP CLI (artisan + MCP server) with agent-friendly commands (batch import, SKU/barcode
  generation, catalog sync, report trigger)"*; `:184` task **G1 "ERP CLI redesign for agents (surface design
  + MCP server)"**, `:308` on the checklist. `:182` also has **G2 "WhatsApp operator bot"** — the WhatsApp
  ambition is already on the roadmap, unstarted.
- `docs/ideas-vault/industrial-parts-app/08-agentic-ai-system-design.md` is a full agent-swarm design spec
  (orchestrator + scout/extract/normalize/cross-ref/QA agents over a shared Postgres+Redis state store,
  `:26-51`) — the closest thing to a written agent architecture in the repo.

**Where an MCP server should sit.** Python/FastMCP, co-located with the sourcing service, not a Node package:
every LLM call in this repo is already Python + `anthropic` (5 call sites in `data-acquisition`, 1 each in
`erp-ml` and `growth-advisor`; **no OpenAI/LangChain/agent framework anywhere**), the tool-registry pattern
already exists in Python (`apps/data-acquisition/app/automotive/toolbelt/registry.py:11`), and there is no
Node service in the backend estate at all — a Node package would be the only one, with its own image, its own
Dokploy app and no shared idioms. An MCP server is a thin transport over the same tool functions the agent
loop calls; it should live beside them.

---

## 8. Placement recommendation

### Candidate A — everything inside the platform Laravel app (`app/Modules/Sourcing/*`)

*Shape:* a new platform-layer module beside `PurchaseHub`, `sourcing_*` tables in the `platform` DB, queued
Laravel jobs for the agent loop, Filament for the ops UI, `AuthenticateApiKey` for the public API.

| Pros | Cons |
|---|---|
| Zero new infrastructure — no Dokploy app, no image, no compose block (§6) | **No LLM SDK in PHP.** `apps/platform/composer.json` has no Anthropic client; every LLM call in the repo is Python |
| Reuses partner auth, quota, usage logging, HMAC webhooks, Filament ops UI, `platform-worker` as-is | The tool-loop, prompt-management and cost-accounting machinery would be rebuilt from scratch in a language that has none of it |
| Restart-safe durable state comes free (Postgres + Laravel queues + the `BarcodeLookupStatus` state-machine pattern, §0.8) | MCP server would still have to be a separate process — MCP has no PHP SDK in use here |
| Strictly obeys `claude/vertical-boundaries.md` | A multi-day, multi-party conversation loop is not what Laravel queue jobs are shaped for |

### Candidate B — extend `PurchaseHub` and run the agent in `data-acquisition`

*Shape:* add RFQ tables to `PurchaseHub`, add a `SourcingAgent` to `apps/data-acquisition/app/`, reuse its
Celery app + `agent_runs` writer.

| Pros | Cons |
|---|---|
| The agent framework already exists there (`run_with_tools` `:43`, `ToolRegistry`, `BudgetGuard`, `agent_runs`) | **Concept collision.** PurchaseHub is supplier-push; RFQ is buyer-pull (§5). Merging them breaks one-surface-per-concept before the first line is written |
| `da-worker`/`da-beat` are already deployed (`docker-compose.dokploy.yml:391,414`) | `data-acquisition` is a *catalogue-data* service (its README: agents that "ingest external data into the platform + car_parts catalogs"). Sourcing is a *transactional* service touching a tenant's money — different blast radius, different on-call, different budget |
| Fastest to a demo | It has **no retries, no queues, no rate limiting, no durable job state** (§3). Adding all of that inside it degrades the existing service |
| — | Would put commercial/tenant transaction data in the service that runs untrusted scraping |

### Candidate C — thin platform module + a new Python service ✅ **RECOMMENDED**

*Shape:* **two** pieces, split on the state/reasoning line.

1. **`apps/platform/app/Modules/Sourcing/*`** — the **system of record and the public seam**.
   Owns `sourcing_requests`, `sourcing_request_lines`, `supplier_quotes`, `supplier_quote_lines`,
   `sourcing_channel_messages`, `sourcing_awards` in the `platform` DB (`pgsql`). Owns the state machine as a
   PHP enum with explicit `allowedTransitions()`, copying `BarcodeLookupStatus`
   (`…/Domain/Enums/BarcodeLookupStatus.php:43-65`) and adding the members that pattern is missing —
   `AwaitingSupplierReplies`, `PartiallyQuoted`, `ReadyForAward`. Exposes `api/v1/sourcing/*` behind
   `['auth.api_key','enforce_api_quota','log_api_usage']` (the `ProductLookup` route stanza,
   `…/ProductLookup/Presentation/routes.php:13-22`). Emits results back to the ERP over the **existing**
   HMAC webhook path (`WebhookDispatchService` + `DispatchWebhookJob` + `webhook_deliveries`). Filament
   resources for the ops console.
2. **`apps/sourcing-agent/`** (new FastAPI + Celery service) — the **reasoning and channel layer**.
   The LLM tool loop (port `app/automotive/llm/adapter.py:43`), the channel adapters (email out, email in,
   WhatsApp later), the quote parser, the comparison/mix-and-match optimiser, the budget guard
   (`app/automotive/budget/guard.py:10`) and — in the same image, same tool functions — the
   **FastMCP server** for the standalone/agentic surface. **Holds no durable state of its own**: every step
   reads and writes the platform module over HTTP, so a restart loses nothing.

| Pros | Cons |
|---|---|
| Durable, restart-safe, multi-day state lives in Postgres behind a real state machine — the one thing no Python service in this repo has (§0.6) | Two deployables instead of one; a new Dokploy app (mitigated: the `data-acquisition`/`da-worker`/`da-beat` triple is a copy-paste template, §6) |
| The LLM/tool/cost machinery lives where the only LLM SDK in the repo already lives (§3, §7) | A service boundary to design and version between the two halves |
| Transactional sourcing data is isolated from the untrusted-scraping service | Needs a service-token auth path platform↔agent — which **does not exist yet** (drift D2) |
| MCP server sits next to the tool functions it exposes, in Python, per §7 | |
| Fits `claude/architecture.md:30-37` — sourcing is universal, so it lives on the platform; and `vertical-boundaries.md:47-53` puts the module in the platform layer | |
| Reuses `agent_runs`/`validation_log` + their Filament dashboards for observability with zero new code | |

### Why C, in the repo's own words

- `claude/architecture.md:30-37` — *"Universal — every vertical benefits … Lives on platform."* Sourcing is
  not automotive-specific. The **state** must therefore be a platform-layer module (rules out a
  vertical module, and rules out the state living only inside a Python service).
- `claude/vertical-boundaries.md:52` — *"Yes, multiple verticals equally → platform layer … or create a new
  top-level module."* A new top-level `Sourcing` module is explicitly sanctioned.
- `apps/erp/docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md` (ERP CLAUDE.md rule 22) — the RFQ noun already
  has a table, a write path and an operator surface **in the ERP** (§0.1). Candidate B would create a second.
  Candidate C's platform module must therefore be scoped as *"multi-supplier sourcing across the platform's
  supplier network"*, with the ERP's `purchase_quote_request` remaining the surface for *"this tenant's own
  RFQ document."* The seam between them belongs in `claude/glossary.md` **before** any spec is written.
- `claude/shared-patterns.md:455-470` (**Platform-as-Source-of-Truth**) — the platform owns the canonical
  resource shape; consumers adapt. So the platform module defines the quote/award DTOs and the ERP writes the
  adaptation in `PlatformIntegration/Application/DTOs` — exactly where the ERP already does this work.
- `docs/superpowers/specs/2026-04-18-…:180,184` — an MCP server is already the planned agentic surface; C
  delivers it as a by-product rather than as a fourth deployable.

**Sequencing implied by the audit:** the blockers are not in the agent. They are (i) no machine credential
either side, (ii) no email transport, (iii) no per-line award. All three are small, and all three are on the
critical path before any agent work is worth starting.

---

## 9. Reuse table

| # | Plumbing | Path | Verdict |
|---|---|---|---|
| 1 | Hexagonal module skeleton + provider registration | `claude/shared-patterns.md:9-35`; `apps/platform/app/Modules/PurchaseHub/Providers/PurchaseHubServiceProvider.php:17-26`; `apps/platform/bootstrap/providers.php:6-14` | **As-is** |
| 2 | Partner API-key auth | `apps/platform/app/Modules/Partners/Infrastructure/Middleware/AuthenticateApiKey.php:17-66` | **As-is** for partner identity |
| 3 | Per-tenant identity (`X-Tenant-Id`) | same, `:76-89` (+ ERP side `PlatformHttpClient.php:266-272`) | **Needs extension** — opaque and unauthenticated (`:68-75`). A sourcing agent acting on a tenant's behalf needs a real, verifiable tenant principal |
| 4 | Quota + usage logging | `EnforceApiQuota.php:19-72`; `LogApiUsage.php:19-47` | **As-is** (note per-hour limit is declared, never enforced — `SubscriptionTier.php:33-41`) |
| 5 | Outbound HMAC webhooks w/ retry + backoff | `apps/platform/app/Modules/ProductLookup/{Application/Services/WebhookDispatchService.php,Infrastructure/Jobs/DispatchWebhookJob.php:29-91,Domain/Models/WebhookDelivery.php}` + `webhook_deliveries` (`migrations/2026_03_27_000003…:13-33`) | **As-is** — but it lives inside `ProductLookup`. **Promote to `app/Shared/`** before a second consumer, or Sourcing will fork it |
| 6 | ERP-side webhook receiver + HMAC verifier | `apps/erp/.../PlatformIntegration/Infrastructure/Middleware/VerifySynerivaWebhookSignature.php:14-38`; route `…/Presentation/routes.php:17-22` | **As-is** — add a `sourcing.*` event branch in `EnrichmentWebhookController`, or a sibling controller |
| 7 | Async job + explicit state machine + human-review gate + tracking-id status endpoint | `EnrichBarcodeSubmissionJob.php:20-219`; `BarcodeLookupStatus.php:43-65`; `GET /api/v1/products/lookup-status/{trackingId}` (`ProductLookup/Presentation/routes.php:18`) | **As-is as a template.** Add `AwaitingSupplierReplies`/`PartiallyQuoted`/`ReadyForAward` members |
| 8 | Platform → Python service call (service token, `Http::withToken`) | `EnrichBarcodeSubmissionJob.php:55-72`; `apps/platform/config/services.php:38-42` | **As-is** for platform → sourcing-agent |
| 9 | Python → platform **inbound** service token | — | **NOT USABLE — does not exist.** `auth.service_token` is documented (`claude/platform-api.md:252-257`) but unimplemented (drift D2). Must be built |
| 10 | LLM tool-use loop + cost accounting | `apps/data-acquisition/app/automotive/llm/adapter.py:43,65-127,74-76` | **Needs extension** — port it; add retries (`:55-56` says it has none) |
| 11 | Tool registry | `apps/data-acquisition/app/automotive/toolbelt/registry.py:11` | **As-is** — also the natural MCP tool surface |
| 12 | Budget guard (monthly $ cap per agent) | `apps/data-acquisition/app/automotive/budget/guard.py:10,40` | **Needs extension** — currently **fail-open** (`app/automotive/api/routes/vin_resolve.py:162`) |
| 13 | `agent_runs` / `validation_log` observability + Filament dashboards | `apps/platform/database/migrations/2026_04_21_000002…:13-36`; `apps/data-acquisition/app/shared/agents/writer.py:27,88`; `apps/platform/app/Filament/Resources/{AgentRunResource,ValidationLogResource}` | **As-is** |
| 14 | Celery app + beat | `apps/data-acquisition/app/celery_app.py:10,30-41` | **Needs extension** — no `task_routes`, no named queues, no retries (§3) |
| 15 | Celery worker/beat deployment blocks | `docker-compose.dokploy.yml:367,391,414` | **As-is as a template** |
| 16 | Durable multi-day / human-in-the-loop workflow | — | **NOT USABLE — does not exist.** No workflow engine; job state is an in-process dict (`apps/data-acquisition/app/api/routes/enrichment.py:77`); `request_human_review` has no consumer (`app/automotive/toolbelt/human_tool.py:8`) |
| 17 | Scrape cache (source+key+prompt_version, 180 d TTL) | `apps/data-acquisition/app/automotive/toolbelt/decoder_tools.py:70,111,166`; `scrape_extracts` migration `2026_04_21_000004` | **As-is** — good fit for caching supplier catalogue pages |
| 18 | Supplier registry + audience targeting | `apps/platform/app/Modules/PurchaseHub/Domain/Models/SupplierProfile.php:35-68`; `Application/Services/TargetingService.php`; `supplier_profiles` migration `…100000:13-32` | **As-is** for choosing whom to ask |
| 19 | PurchaseHub campaign/offer/order flow | `apps/platform/app/Modules/PurchaseHub/Presentation/routes.php:16-52` | **Not usable for RFQ** — push, not pull (§5). Keep separate |
| 20 | Campaign notification delivery | `Infrastructure/Jobs/DeliverCampaignNotificationsJob.php:19-43`; `Domain/Models/CampaignNotification.php:31-41` | **NOT USABLE** — in-app rows only; no channel, no address, no send |
| 21 | Should-cost baseline — TecDoc list price | `car_parts.prices` (`migrations/2026_05_30_000017_fix_prices.php:18-36`) | **As-is (automotive only)** — per article/country/type/date |
| 22 | Part cross-references (OE ↔ aftermarket ↔ EAN) | `car_parts.article_cross_references` (`…2026_05_30_000016…:17-50`); `GET /api/v1/automotive/articles/cross-reference` (`Automotive/Presentation/routes.php:34`) | **As-is** — core to "which supplier number is this part" |
| 23 | ClickHouse `price_observations` + `price_daily_stats` | `data/migrations/001_create_tables.sql:12-34,139-157` | **NOT USABLE — schema only, zero writers** (§4.1) |
| 24 | Scraped retail price store (parapharmacy) | `parapharmacy.product_prices` (`migrations/2026_06_19_000001…:11-34`) | **Needs extension** — sell-side, single vertical |
| 25 | Meilisearch / Scout | `apps/platform/config/scout.php:16-40`; `Catalog/Domain/Product.php:56,170` | **Needs extension** — one indexed model, driver defaults to `collection` |
| 26 | Declarative source-mapping ETL | `data/etl/app/sources/otospex_vintn.yml:9-68`; `data/etl/README.md:29,33` | **As-is as a template** for supplier price-list import |
| 27 | ERP RFQ document + group fan-out + response recorder | `apps/erp/.../Procurement/Presentation/routes.php:44-73`; `CreateRfqData.php:13-18`; `RfqPayload.php:12-20` | **As-is** |
| 28 | ERP RFQ → PO award converter | `apps/erp/.../PurchaseQuoteRequestAwardService.php:26-86` | **Needs extension** — single-winner only; no per-line award (§0.2) |
| 29 | ERP document email (PDF attach, CC, queued) | `apps/erp/.../Communication/Application/Services/DocumentEmailService.php:29-76`; routes `Document/Presentation/routes.php:460,464` | **Needs extension** — works, but `MAIL_MAILER=log`; no transport provisioned |
| 30 | ERP `Channel` adapter registry + encrypted per-tenant credentials + webhook ingress | `apps/erp/.../Channel/Application/Contracts/ChannelAdapter.php:17-37`; `Application/Services/AdapterRegistry.php:11-54`; `Domain/Enums/CredentialType.php:7-13`; `Domain/Models/ChannelCredential.php` | **Needs extension** — right shape, wrong direction (sales channels); **ships no concrete adapters** (`AdapterRegistry.php:35`) |
| 31 | ERP↔platform supplier identity bridge | `apps/erp/apps/api/database/migrations/tenant/2026_03_10_400004_create_platform_supplier_mappings_table.php` | **As-is** — the join between a platform supplier and the tenant's local `partners` row |
| 32 | ERP machine/service credential | — | **NOT USABLE — does not exist.** No `tokenCan` anywhere; no service guard (`config/auth.php:41-54`) |
| 33 | Outbound email/WhatsApp/SMS provider | — | **NOT USABLE — nothing provisioned** anywhere (§0.5) |
| 34 | Inbound email ingestion (supplier replies) | — | **NOT USABLE — nothing exists.** No IMAP, no inbound-mail webhook, no parser |
| 35 | MCP server | — | **NOT USABLE — does not exist**; planned at `docs/superpowers/specs/2026-04-18-…:180,184,308` (§7) |
| 36 | Dokploy deploy pattern for a new Python service | `docker-compose.dokploy.yml:367,391,414`; `claude/deploy-runbook.md:34-48,56-65` | **As-is as a template** |

---

## 10. ERP endpoints the agent needs that are MISSING

Ordered by how hard they block the feature. Each row states what exists today.

| # | Missing capability | Why the agent needs it | Nearest thing today | Severity |
|---|---|---|---|---|
| M1 | **A scoped machine credential** — a service/agent token with abilities (e.g. `sourcing:read-need`, `sourcing:write-quote`, `sourcing:create-po`) that is not a person's PAT | Everything else. An agent holding a human PAT inherits that human's whole permission set and dies when they are deactivated | Only `auth:sanctum` user PATs. `grep -rn "tokenCan"` over `apps/erp/apps/api/app/` → **zero hits**; `config/auth.php:41-54` has no machine guard; `EnforceTokenTenantClaim.php:36` even lets tokens with no tenant claim through | **Blocker** |
| M2 | **`POST /api/v1/purchase-quote-requests/groups/{groupId}/award`** — per-line award across suppliers, producing one PO per winning supplier in a single transaction | This *is* "mix and match for best price". Without it the agent can only pick one supplier for the whole basket | `POST …/{id}/convert-to-po` awards one whole RFQ and cancels the siblings — `Procurement/Presentation/routes.php:70`, `PurchaseQuoteRequestAwardService.php:26-86` | **Blocker** |
| M3 | **A real send on `POST …/{id}/send`** (or a new `…/{id}/dispatch`) that delivers the RFQ to the supplier over a chosen channel and records the outbound message | Otherwise the agent must reach outside the ERP to contact a supplier, and the ERP has no record of what was sent | `send` only stamps `payload.rfq.sent_at` (`PurchaseQuoteRequestController.php:172-183`); `POST /api/v1/documents/{document}/email` (`Document/routes.php:460`) exists but is generic and `MAIL_MAILER=log` | **Blocker** |
| M4 | **`GET /api/v1/sourcing/needs`** — one aggregate read returning open parts demand (work-order lines not yet sourced + replenishment requests + below-min stock) with product/OE refs, quantities, location and target date | "Understand a parts need" is step 1. Today it takes N+1 calls across three modules and a client-side "is this low?" computation | `GET /workshop/work-orders` + `…/{id}` (`Workshop/WorkOrder/Presentation/routes.php:28-38`), `GET /replenishment-requests` (`Replenishment/routes.php:14`), `GET /stock-levels` (`Inventory/routes.php:58`). No low-stock endpoint (`grep -rn "low-stock\|lowStock\|below-min\|stock-alerts" --include=routes.php app/Modules/` → no hits) | **High** |
| M5 | **Supplier channel preferences on `partners`** — `preferred_channel`, `whatsapp_number`, `rfq_email`, `channel_settings jsonb` (+ read/write endpoints) | "their linked channels" is not modelled. `partners` has one `email` and one `phone`, both untyped | `partners.{email,phone}` (`migrations/tenant/2025_11_30_052119_create_partners_table.php:16-34`); `contacts.{email,phone,mobile}` (`…2026_03_10_100001…:16-31`). `grep -rn "whatsapp\|whats_app"` over Partner/Contact/migrations → **zero hits** | **High** |
| M6 | **`POST /api/v1/purchase-quote-requests/{id}/quotes`** — record an *inbound* supplier reply as a first-class event: channel, raw message, parsed lines, confidence, received-at, attachment | Today a reply can only be squashed into the RFQ document's own fields, destroying provenance and making "which reply did this price come from?" unanswerable | `PUT …/{id}` overwrites `lines[].unit_price` + `supplier_reference` + `lead_time_days` (`UpdatePurchaseQuoteRequestRequest.php:34-56`); `POST /documents/{document}/attachments` (`Media/routes.php:30`) stores a file with no structure | **High** |
| M7 | **Purchase price history read** — e.g. `GET /api/v1/products/{id}/purchase-price-history` (last N posted supplier-invoice unit prices per supplier) | The only true should-cost baseline for a tenant. Without it the agent judges quotes against TecDoc list price or nothing | Data exists in posted supplier invoices (`Procurement/Application/SupplierInvoicePostingService.php`) but is not exposed as a series; ClickHouse `price_observations` has no writer (§4.1) | **High** |
| M8 | **RFQ state webhooks out of the ERP** (`rfq.sent`, `rfq.quote_received`, `rfq.awarded`) | The agent must not poll a multi-day process. The ERP currently only *receives* webhooks | ERP has no outbound webhook registration at all (`grep -rni "webhook_url\|register.*webhook"` → no non-Stripe hits); platform-side `webhook_deliveries` machinery exists and could be mirrored | **Medium** |
| M9 | **Inbound email ingestion endpoint** (`POST /api/v1/inbound/email` with provider signature verification) | Suppliers reply by replying. Nothing in either codebase can receive an email | Nothing. No IMAP, no inbound-mail route, no parser, in the ERP or the platform | **Medium** (unblocked only after M3 picks a provider) |
| M10 | **`GET /api/v1/procurement-policies` exposed to the agent** — approval thresholds, bill-control mode, match enforcement | The agent must know when it may auto-award vs must escalate | Exists (`Procurement/Presentation/routes.php:33`) but is gated `can:settings.view` — a very broad permission for a machine caller; needs a narrower ability under M1 | **Medium** |
| M11 | **Idempotency keys on the write endpoints** (`POST /purchase-quote-requests`, `/convert-to-po`, `/purchase-orders`) | An agent that retries must not create a duplicate PO. Platform-side precedent exists (`migrations/2026_07_02_000003_scope_barcode_submission_idempotency_to_partner.php`) | No idempotency header handling on any ERP procurement write | **Medium** |
| M12 | **`can:` permissions for the new surfaces** + a machine role | `purchase-quote-requests.{view,create,update,convert}` exist (`Procurement/routes.php:45-71`); a per-line award and a needs-read would each need their own | — | **Low (mechanical)** |

**Also missing on the platform side** (not ERP endpoints, but on the same critical path):
`auth.service_token` inbound middleware (drift D2) — without it the Python agent cannot authenticate back to
the platform module at all.
