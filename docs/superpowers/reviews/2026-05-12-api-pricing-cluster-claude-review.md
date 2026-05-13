# api.pricing Cluster — Claude Review

Cluster: `api.pricing`
Cluster aggregate status (inventory): `fixed`
Verdict: **APPROVE**

Reviewer: claude (opus)
Implementer (canonical owner): codex
Date: 2026-05-12

## Scope

Coupon application, pricing-rule evaluation, PricingController endpoints. Pre-sweep, coupon and price-list lookups were under-scoped at the service layer.

Inventory callsite total: **11 / 11 fixed**.

## Implementation summary

Top fix commits: `12ec1df9` (10 callsites), `da535327` (1).

Top files: `CouponApplicationService`, `PricingService`, `PricingController`.

## Verification

```
cd apps/api && php artisan sweep:inventory:verify-history
→ verified 6270 event(s) across 1205 callsite(s); 0 problem(s).
```

Cluster-level test: `apps/api/tests/Feature/Pricing/PricingTenantIsolationTest.php`.

## Per-round review trail

- `2026-05-04-api-pricing-cluster-codex-round2-review.md`
- `2026-05-04-api-pricing-cluster-opus-round2-review.md`
- `2026-05-04-api-pricing-reassigned-cluster-codex-round2-review.md`

## Gates evaluated

1. **Coupon application**: code + redemption scope tenant + company predicates.
2. **Pricing rule evaluation**: rule lookup + match-context scoping.

## Non-blocking follow-ups

None.

## Disposition

APPROVE for master PR.

## Cross-references

- Cluster-level test: `apps/api/tests/Feature/Pricing/PricingTenantIsolationTest.php`
