# api.broadcast-channels Cluster — Claude Review

Cluster: `api.broadcast-channels`
Cluster aggregate status (inventory): `fixed`
Verdict: **APPROVE**

Reviewer: claude (opus)
Implementer (canonical owner): codex
Date: 2026-05-12

## Scope

Laravel broadcast channel definitions + the `Broadcast` event classes themselves (Product cost-price updates, partner balance updates, POS order events, import progress/completion). Pre-sweep, channel authorization callbacks did not consistently verify the authenticated user's tenant matches the resource's tenant before granting subscription.

Inventory callsite total: **13 / 13 fixed**.

## Implementation summary

Fix commit: `dc67bcdb` (all 13 callsites in one batch).

Top files: `channels.php`, `ProductCostPriceUpdatedBroadcast`, `PartnerBalanceUpdatedBroadcast`, `OrderSentToKitchenBroadcast`, `OrderReadyBroadcast`, `OrderLineStatusChangedBroadcast`, `TerminalActivatedBroadcast`, `ImportCompletedBroadcast`, `ImportProgressBroadcast`.

## Verification

```
cd apps/api && php artisan sweep:inventory:verify-history
→ verified 6270 event(s) across 1205 callsite(s); 0 problem(s).
```

Cluster-level test: `apps/api/tests/Feature/Broadcast/BroadcastChannelsTenantIsolationTest.php`.

## Per-round review trail

Four review rounds — the highest density outside fiscal-compliance:

- `2026-05-08-api-broadcast-channels-cluster-codex-review.md`
- `2026-05-08-api-broadcast-channels-cluster-codex-{round2,round3,round4}-review.md`

## Gates evaluated

1. **Channel authorization callback**: every `Broadcast::channel('foo.{id}', ...)` callback verifies `$user->tenant_id === $resource->tenant_id` AND `$user->currentCompany?->id === $resource->company_id` before granting.
2. **Event payload tenant tagging**: every broadcast event class carries `tenant_id` + `company_id` so the channel infrastructure can filter; payloads contain no cross-tenant identifiers.
3. **POS terminal channel** (high-throughput): the most-scrutinized channel due to the volume of order events; round-4 review specifically re-validated authorization under concurrent open shifts.

## Non-blocking follow-ups

None.

## Disposition

APPROVE for master PR.

## Cross-references

- Cluster-level test: `apps/api/tests/Feature/Broadcast/BroadcastChannelsTenantIsolationTest.php`
