# api.platform-integration Cluster — Claude Review

Cluster: `api.platform-integration` (Platform Integration outbound)
Cluster aggregate status (inventory): `fixed`
Verdict: **APPROVE**

Reviewer: claude (opus)
Implementer (canonical owner): codex
Date: 2026-05-12

## Scope

Outbound HTTP client headers (tenant binding), Stripe billing webhook collision guards, per-iteration tenant rebind in the enrichment poller command, platform-submission-id uniqueness migrations.

Inventory callsite total: **6 / 6 fixed**.

## Implementation summary

Top fix commits: `38a89b0c` (4 callsites), `bc7b445d` (2).

Top files: `PlatformHttpClient`, `CheckPendingEnrichmentsCommand`, `2026_05_08_*_add_unique_to_products_platform_submission_id` migration, `2026_05_08_*_add_unique_to_billing_payments_provider_payment_id` migration, `ProcessEnrichmentEventListener`, `StripeWebhookController` (billing slice).

## Verification

```
cd apps/api && php artisan sweep:inventory:verify-history
→ verified 6270 event(s) across 1205 callsite(s); 0 problem(s).
```

Cluster-level test: `apps/api/tests/Feature/PlatformIntegration/PlatformIntegrationTenantIsolationTest.php`.

## Per-round review trail

- `2026-05-08-api-platform-integration-cluster-codex-review.md`
- `2026-05-08-api-platform-integration-cluster-codex-round2-review.md` (+ `-001-002` and `-003-005` callsite-split variants)

## Gates evaluated

1. **Outbound tenant headers**: `PlatformHttpClient::request` injects `X-Tenant-Id` from `CompanyContext` per call; no shared mutable state across tenants.
2. **Per-iteration tenant rebind**: `CheckPendingEnrichmentsCommand` re-resolves tenant context inside the iteration loop (api.platform-integration.002).
3. **Provider-payment-id collision guard**: unique migration + listener-side check guard against cross-tenant collisions on shared external identifiers (api.platform-integration.003-006).

## Non-blocking follow-ups

None.

## Disposition

APPROVE for master PR.

## Cross-references

- Cluster-level test: `apps/api/tests/Feature/PlatformIntegration/PlatformIntegrationTenantIsolationTest.php`
