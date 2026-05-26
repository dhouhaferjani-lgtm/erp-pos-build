# T3 Sync Hub Shared Infrastructure Adversarial Review

Requested reviewer: Opus

Executed reviewer: available Codex reviewer agent. This session does not expose an Opus model, so the review below records the strongest available adversarial pass.

## Initial Findings

### P1: Channel API was not tenant/company scoped

`GET /channels` returned all channels, and create/test/resync/product/order/sync routes trusted body or route IDs without anchoring them to the active `CompanyContext`.

Resolution: controllers now derive company scope from `CompanyContext`, channel lookups route through `ChannelService::findChannel()`, and list/order/sync endpoints scope by the selected company.

### P1: Product publishing could bind a product outside the channel company

`publishProduct()` loaded `Channel` and `Product` independently, allowing cross-company mappings when UUIDs were known.

Resolution: product lookup is now constrained to the channel's `company_id`. A regression test covers rejecting a foreign-company product.

### P1: Stock debouncing could drop the latest stock level

The stock listener debounced bursts by suppressing subsequent jobs for five seconds, but the queued job carried the first event's quantity. A burst could dispatch stale stock.

Resolution: the listener now stores the latest stock level in cache under the debounce key, and the queued job reads that latest value at execution time. A regression test verifies that a job enqueued with `10` pushes cached latest value `12`.

### P2: Manual resync was a no-op that reported success

`manualResync()` returned `Manual resync queued` without queuing work.

Resolution: it now resolves the adapter and dispatches `ChannelReconciliationJob`. A regression test asserts dispatch.

### P2: Sync operation failure state was not persisted

`DispatchStockChangeToChannelJob` left operations pending when the adapter failed.

Resolution: failures now persist `failed` status, increment `attempt_count`, set `next_retry_at`, then rethrow for queue retry behavior. A regression test covers adapter failure.

### P2: Tenant migrations used hard FKs to external core tables

Tenant migrations constrained to `companies`, `products`, and `documents`, which is fragile for isolated tenant topology and conflicts with the no cross-DB FK constraint.

Resolution: external hard FKs were removed from `company_id`, `product_id`, and `document_id`; local channel-owned relationships remain constrained where they stay within the channel tenant tables.

## Verdict

Ready after remediation, subject to final full preflight. The implementation preserves the shared-infrastructure-only boundary: no production WC/Shopify/PrestaShop/Paradeals adapters, no `automattic/woocommerce` dependency, generic adapter type strings, encrypted credentials, tenant migrations only, queue-backed webhooks/jobs, and a no-adapters admin UI state.

## Verification Notes

- `php artisan test tests/Unit/Channel tests/Feature/Channel` passed after fixes.
- Targeted PHPStan over `app/Modules/Channel`, channel tests, and channel fixtures passed.
- `php artisan test tests/Feature/Document/DNConsolidationTest.php` passed after the listener schema guard and debounce changes.
