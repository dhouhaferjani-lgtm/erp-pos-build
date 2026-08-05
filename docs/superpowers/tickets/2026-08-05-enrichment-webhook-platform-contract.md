# Ticket — Synerivia enrichment webhook must echo back the originating `tenant_id`

**Opened:** 2026-08-05
**Source:** cat-(b) cross-tenant conversion wave 1, surface 5 (enrichment webhook chain).
Audit: `scratchpad/partA1-cat-b-resweep.md` §1a + §3.3.
**Owner:** platform (Synerivia) — the ERP side is already shipped and tolerant.
**Status:** open — ERP ready, waiting on the platform.

---

## Why the ERP cannot resolve the tenant on its own

`POST /api/v1/webhooks/syneriva` runs `['api', VerifySynerivaWebhookSignature]`
(`apps/api/app/Modules/PlatformIntegration/Presentation/routes.php`). No `auth:sanctum`, no SPA
session, no signed link — so `ResolveTenancy::resolveTenantId()` returns `null` and
`tenancy()->initialize()` is never called. Under database-per-tenant (flipped 2026-05-28) that
leaves the whole request, and every job it dispatches, on the **central** connection.

The old justification on `ProcessEnrichmentWebhookJob` said the tenant "resolves downstream through
`platform_submission_id` → `Product` → `company_id`". That chain cannot start: `products` is a
tenant table, so its first hop is precisely the read that fails. `ProcessEnrichmentEventListener`'s
`Product::where('platform_submission_id', …)->sole()` raised `QueryException` 42P01, which its
`catch (ModelNotFoundException)` does not catch.

There is no central directory that maps a tracking id to a tenant, and building one would duplicate
state the platform already holds: the platform knows which tenant submitted each product, because
`ProductSubmissionService` sends `X-Tenant-Id` / `X-Company-Id` on the submission call
(`PlatformHttpClient::tenantHeaders()`).

## What the platform must change

Add `tenant_id` to the enrichment webhook body — the **same** tenant id the ERP sent on the
originating submission — for both event shapes:

```jsonc
// enrichment.resolved (single)
{
  "event": "enrichment.resolved",
  "tracking_id": "…",
  "tenant_id": "0198…-…",        // NEW — echo of the submitting tenant
  "status": "completed",
  "vertical": "parapharmacy",
  "enrichment_quality": "high",
  "has_barcode_assigned": true,
  "barcode": "…",
  "locale": "fr_FR",
  "timestamp": "2026-08-05T09:00:00+00:00"
}

// enrichment.batch_resolved — tenant_id belongs on EACH item, not on the envelope:
// a batch may legitimately span tenants, and the ERP fans out one job per item.
{ "event": "enrichment.batch_resolved", "items": [ { "tracking_id": "…", "tenant_id": "…", … } ] }
```

Contract notes:
- `tenant_id` is the **ERP tenant UUID** exactly as received in `X-Tenant-Id` at submission time.
  The platform must not derive or normalize it.
- It must be present on every enrichment event, including terminal failures
  (`failed` / `not_enrichable` / `rejected`) — those still move the product out of Pending.
- Empty string is treated as absent by the ERP.

## What the ERP side already does (shipped 2026-08-05)

- `EnrichmentWebhookPayload::fromWebhook()` reads `tenant_id`, mapping absent/empty to `null`.
  The property is **declared, not promoted**, with a class-level default — a promoted default is
  applied by the constructor, which `unserialize()` never calls, so in-flight queue payloads would
  restore it uninitialized and fatal.
- `ProcessEnrichmentWebhookJob` carries the anchor and rebinds it via `BindsTenantContext` before
  re-emitting `EnrichmentWebhookReceived`, so the synchronous listener runs in the right database.
- **Anchorless payloads are DISCARDED with a warning**, never processed under central. Safe because
  the webhook is an optimisation, not the system of record: `enrichment:check-pending` re-polls
  every product still Pending/Enriching every 15 minutes, inside each tenant's own database, and
  dispatches the same event. Until the platform ships this change, **every webhook takes that
  discard path** and results simply arrive on the poller's cadence instead of instantly.
- `ProcessEnrichmentEventListener` fails closed with an explicit message when no tenant is bound
  under db-per-tenant, instead of dying on a bare 42P01.

Tests: `apps/api/tests/Feature/PlatformIntegration/EnrichmentWebhookTenantContextTest.php`.

## Two properties of the contract the platform must know about (2026-08-05 wave-1 review)

**M6 — the ERP treats `tenant_id` as AUTHORITATIVE and does not cross-check it.** Nothing verifies
that the anchored tenant actually owns the `tracking_id` in the same payload. That is a deliberate
consequence of the design — the anchor exists precisely because the ERP cannot resolve the tenant
any other way, so there is nothing to check it against before the database switch. Physical
isolation contains the blast radius: a wrong anchor selects the wrong tenant database, where the
tracking id does not exist, and the job lands on the "unknown tracking id"
`ModelNotFoundException` path rather than writing one tenant's enrichment into another's catalogue.
The contract obligation is therefore on the platform: **`tenant_id` must be the exact value received
as `X-Tenant-Id` on the originating submission, never derived, normalized, or defaulted.** A
platform-side mix-up is not detectable by the ERP; it surfaces only as enrichments that silently
never arrive.

**The poller's fallback window does NOT cover terminal-status products.** While `tenant_id` is
absent the ERP discards each webhook and relies on `enrichment:check-pending`, which re-polls
products that are still `Pending`/`Enriching` with a non-null `platform_submission_id` and
`updated_at < now()-10min`. A webhook for a product whose LOCAL status is already terminal — a
re-enrichment echo, or a `Rejected`-after-review — never enters that window and is dropped for
good. Ordinary results arrive late; those specific events do not arrive at all. This is the one
case where "delayed, not lost" is inaccurate, and it stops being reachable the moment the platform
ships the anchor.

## Bonus: this closes Finding A for free

Finding A of `docs/superpowers/audits/2026-05-07-scheduled-jobs-cross-cluster-observations.md` is the
`platform_submission_id` **global-uniqueness** risk: the listener's `->sole()` treats a tracking id
as unique across the entire fleet, and `MultipleRecordsFoundException` is deliberately uncaught
because a collision would otherwise dispatch one tenant's enriched payload to another tenant's
company. Once the tenant anchor arrives with the payload, the lookup happens inside one tenant's
database and cross-tenant collision stops being reachable at all. One change, two findings.

---

## Draft REALIGNMENT-LOG entry

> To be appended to `docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md` (most-recent entry at top) **by the
> platform side**, once the change ships. Drafted here because this session is scoped to `apps/erp`
> and must not edit files outside it.

```markdown
## 2026-08-05: ADDITIVE — enrichment webhook echoes the originating `tenant_id`

**What changed:** The Synerivia enrichment webhook (`POST <erp>/api/v1/webhooks/syneriva`) now
includes `tenant_id` — the ERP tenant UUID received as `X-Tenant-Id` on the originating
`submit-for-enrichment` call — in the `enrichment.resolved` body and on **each item** of an
`enrichment.batch_resolved` payload. Purely additive; no field was removed or renamed.

**Why:** The ERP flipped to database-per-tenant on 2026-05-28. The webhook route is unauthenticated,
so the ERP has no tenant bound when the callback arrives, and the previously assumed resolution
chain (`tracking_id` → `Product` → `company_id`) starts with a read against a per-tenant table that
does not exist on the ERP's central connection. Without the echoed anchor the ERP cannot select the
correct database at all.

**ERP impact:** Already implemented and TOLERANT of the old shape — `tenant_id` is optional and maps
to `null` when absent. While it is absent the ERP DISCARDS each webhook (with a warning) and falls
back to its 15-minute `enrichment:check-pending` poller, so enriched results are delayed rather than
lost. Once the platform ships this field, webhook delivery becomes effective again and results land
immediately. No ERP deploy is required to consume it.

**Side effect:** closes the `platform_submission_id` global-uniqueness risk (Finding A,
`docs/superpowers/audits/2026-05-07-scheduled-jobs-cross-cluster-observations.md`) — with the anchor
present the product lookup happens inside one tenant's database, so a cross-tenant tracking-id
collision can no longer route an enriched payload to the wrong company.

**Owner:** platform
**Status:** pending
```
