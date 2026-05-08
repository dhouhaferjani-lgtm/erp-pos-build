# api.super-admin-context + web.super-admin-frontend (paired) — Codex round-3 re-review

Reviewed commit: 28b8de47
Reviewer: codex
Date: 2026-05-08
Verdict: BLOCK

## Verdict rationale
The production-source fixes close the three round-2 BLOCKERs: PurchaseHubOfferController::index now honestly documents the global offers-cache tenant-isolation gap, VoucherSyncController has been restored to its pre-cluster POS state, and the POS gap is represented as an out-of-scope class-level deferral. The architecture suite and inventory history both pass, and the POS scope diff is empty. However, the requested mutation test (a) did not fail in its second red-path check: after re-adding valid CrossTenantRoute attributes to VoucherSyncController and removing the deferral entry, the architecture suite still passed because those attributes satisfy classifier branch (a). Per the checklist, that failed-to-fail mutation is a BLOCKER.

## Round-2 BLOCKER closure
BLOCKER 1 is closed at the source/reason-text level. PurchaseHubOfferController::index begins with "KNOWN TENANT-ISOLATION GAP", cites the global `purchase_hub:offers` key, names the cache-hit leak path, and tracks the future api.purchase-hub cache-key fix. PurchaseHubOfferController::show still delegates to uncached `PurchaseHubService::getOffer()`. Spot-checking PurchaseHubService confirms only `getOffers()` uses `Cache::get` / `Cache::put`; `placeOrder()` only calls `Cache::forget`, while `getOffer()`, `getOrders()`, and `getOrder()` make direct PlatformHttpClient calls.

BLOCKERs 2 and 3 are closed at the source/scope level. `git diff --stat fcb4c7ab..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher apps/web/src/features/pos` is empty. VoucherSyncController contains no CrossTenantRoute attributes, no `use App\Shared\Architecture\CrossTenantRoute;` import, and matches `fcb4c7ab` for that file. The deferrals fixture has exactly one VoucherSyncController class-wildcard entry whose reason explicitly covers both OUT-OF-SCOPE and the KNOWN TENANT-ISOLATION GAP around unvalidated `terminal_id` ownership.

## Round-3 mutation tests
Mutation a, first half: re-added the VoucherSyncController CrossTenantRoute import and four method attributes. `vendor/bin/phpunit tests/Architecture` passed: `OK (10 tests, 53 assertions)`.

Mutation a, second half: with those four valid attributes still present, removed the VoucherSyncController deferral entry. Expected red per checklist, but actual result was green: `OK (10 tests, 46 assertions)`. This is consistent with the classifier implementation because branch (a) accepts the method attributes once the deferral no longer short-circuits classification.

Mutation b: changed PurchaseHubOfferController::index reason text back to clean service-trust wording without the gap prefix. The architecture suite stayed green: `OK (10 tests, 53 assertions)`. This confirms reason-quality remains a human review concern. That is acceptable for this round, but a future architecture test should validate at least coarse GAP semantics when an attribute documents a known isolation gap.

Mutation c: emptied the deferrals fixture with VoucherSyncController restored to the round-3 state. The architecture suite failed red on exactly `pullVouchers`, `pullVoucherLedger`, `pullReceiptQrIndex`, and `pushVoucherLedger`. The temporary edits from all mutations were restored before this verdict file was written.

## Reason text quality
PurchaseHubOfferController::index is high quality for the round-3 purpose: it names the global key, TTL, fresh-fetch tenant-tagging, cache-hit leak path, and the future CompanyContext-suffixed cache-key fix.

The VoucherSyncController deferral entry is also explicit enough: it names the POS scope exclusion, the terminal-ownership validation gap in `requireTerminalId()`, the permission-only nature of `Gate::authorize('pos.operate_terminal')`, and why the later SQL filters do not prove tenant ownership.

## Inventory state
`php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` passed with:

```text
verified 1744 event(s) across 347 callsite(s); 0 problem(s).
```

The six callsite rows are `status: under_review` with `fix_commit: e51d3951`: `api.super-admin-context.001`, `api.unmapped.020`, `api.unmapped.021`, `api.unmapped.022`, `api.unmapped.023`, and `web.super-admin-frontend.001`.

## Scope check
Final scope diff:

```text
git diff --stat fcb4c7ab..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher apps/web/src/features/pos
```

Output was empty.

## BLOCKERs (if any)
1. Mutation test (a) fails to fail in the requested second red-path check. With VoucherSyncController attributes reintroduced and the deferral removed, the controller methods still satisfy classifier branch (a), so `vendor/bin/phpunit tests/Architecture` passes instead of naming the four VoucherSyncController methods. This means the architecture suite does not catch a future reintroduction of POS-scope CrossTenantRoute attributes by itself; the scope boundary remains enforced by human review / diff checks, not by the architecture invariant.

## NICE-TO-HAVEs (if any)
1. Add a scope-boundary guard for this paired cluster if future reviews are expected to catch POS-source edits mechanically.
2. Consider adding coarse reason semantics checks for CrossTenantRoute reasons that claim known-gap status versus clean service-trust status.

## Sign-off
Not signed off because mutation (a)'s required red-path assertion did not fail.
