# Inventory Transfer Remediation Plan — Codex Adversarial Review

**Verdict:** BLOCKER
**Confidence:** high
**Date:** 2026-05-28

## Summary

The multi-event cost-stream architecture is directionally the right answer to the original WAC order-dependence finding, but the plan still has two execution-blocking defects:

1. The `on_hand + in_transit` denominator is not read atomically. `recordCostEvent()` reads `in_transit` before `WeightedAverageCostService::recordCostAdjustment()` locks the product and stock rows, so a different transfer for the same product can complete/cancel/initiate between the two reads and make the denominator double-count or under-count.
2. Batch A/B create tenant-folder migrations before the test harness is changed to load tenant migrations. `RefreshDatabase` will not run those migrations, so the plan's early tests either false-pass migration verification or fail as soon as the new tables/columns are used.

The prompt's same-transfer "complete has written destination stock but not status yet" double-count scenario is not the actual issue: both `complete()` and `recordCostEvent()` lock the same `stock_transfers` row, and the destination write and status change are in one DB transaction. A concurrent transaction cannot observe the half-complete state for the same transfer. The cross-transfer denominator race is the real blocker.

## BLOCKERS (the plan would ship broken code; fix before execution)

### 1. `recordCostEvent()` reads the two halves of the denominator under different concurrency windows

Task A7 computes `inTransitQty` in `StockTransferService` before calling WAC adjustment (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:746` and `docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:750`). The product row and `stock_levels` rows are locked later inside `recordCostAdjustment()` (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:603` and `docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:610`). Existing `complete()` also locks the product before receiving destination stock (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:168`) and only flips status after receipt and cost capitalization in the current implementation (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:198`).

Two-process scenario:
- Start with product P: 100 owned units, 90 on hand and 10 in transit on transfer A.
- Process B records an in-transit cost event on transfer A. It locks transfer A only (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:678`, `docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:784`) and reads `inTransitQty = 10` before taking the product lock.
- Process C completes a different transfer C for product P. It locks product P, increments destination stock, changes transfer C from `in_transit` to `completed`, and commits. Net owned denominator is still 100.
- Process B now enters `recordCostAdjustment()`, locks product P, sums `stock_levels` as 100, and adds the stale `inTransitQty = 10`. It capitalizes against 110 even though the company owns 100 units.

The reverse interleaving under-counts: B reads `inTransitQty = 0`, then another transfer initiates and commits a source decrement plus `in_transit` status before B sums `stock_levels`, so B sees 90 + 0 instead of 100.

Fix the plan so one lock protects the whole denominator. The practical shape is: acquire the product lock first, then read both `stock_levels` and the in-transit transfer-line sum inside the same locked WAC transaction. Because initiate/complete/cancel already take the product lock for stock motion (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:304`, `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:168`), this serializes denominator reads against state transitions for that product.

### 2. Tenant-folder migrations in Batch A/B will not run under the current PHPUnit bootstrap

Task A1 creates `stock_transfer_cost_events` under `database/migrations/tenant/` (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:124`). Task B1 creates the idempotency-column migration there too (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:1104`). But the PHPUnit config only points at the normal Laravel bootstrap and SQLite in-memory DB (`apps/api/phpunit.xml:4`, `apps/api/phpunit.xml:40`); there is no custom tenant migration path in `phpunit.xml` or `tests/bootstrap.php` (`apps/api/tests/bootstrap.php:21`).

The codebase already proves tenant migrations require a custom test harness: channel tests manually glob `database/migrations/tenant/2026_05_24_12000*_*.php` (`apps/api/tests/Feature/Channel/CreatesChannelSchema.php:58`). The Stancl `tenants:migrate` path is also explicitly tenant-only (`apps/api/config/tenancy.php:195`).

Execution failure:
- A1 Step 2 runs `php artisan migrate:fresh --env=testing` and expects "clean" (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:177`). That command will not run the tenant migration, so this is a false verification.
- A7/A12 tests that insert `StockTransferCostEvent` will fail with missing `stock_transfer_cost_events`.
- B3 tests/code that write `complete_idempotency_key`, `cancel_idempotency_key`, or `idempotency_payload_hash` will fail because B1's tenant migration was not loaded.
- If `tenants:migrate` is run before Batch G, A1's tenant migration references `stock_transfers` while the stock-transfer table is still in the central migration folder (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:1973`), so the tenant DB cannot satisfy the FK.

Move Batch G/test-bootstrap work before A1, or keep all new remediation migrations in the same folder until the relocation lands. The conditional "if FAIL, add a trait" note in G1 (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:1990`) is too late; Batch A/B already depend on it.

## P1 (strong concerns; the plan probably ships subtly wrong code without these fixes)

### 1. Idempotency lookups can return a different transfer/event than the route target

Task B3 looks up an existing completion by `(tenant_id, company_id, complete_idempotency_key)` and returns the first match (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:1231`). It does not assert `id = $transferId`. A bad client can complete transfer A with key `k`, then call `/stock-transfers/B/complete` with the same key; the service locks B, finds A by key, returns A, and leaves B untouched. The cost-event endpoint has the same shape: it returns an existing event by company+key only (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:683`).

Make idempotency replay scoped to the resource addressed by the route. Same key on a different transfer should be a 409, not a successful response for the wrong aggregate.

### 2. Batch D changes the service signature after Batch B, but does not reconcile idempotency argument order

Batch B changes `complete()` to `complete(string $transferId, string $userId, ?string $idempotencyKey = null)` (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:1223`). Batch D then changes `lockTransfer()` and service callers to require tenant/company scope (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:1757`), but the shown signature/test only passes `tenantId` and `companyId` after `userId` (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:1789`) and does not show where the idempotency key lands.

This will either create a PHP signature with required scope parameters after an optional key, drop idempotency from controller calls, or force a second signature rewrite. Specify the final signature once, e.g. `complete(string $transferId, string $userId, string $tenantId, string $companyId, ?string $idempotencyKey = null)`, and update all tests/controller snippets accordingly.

### 3. Cancel cost semantics are a policy decision, but the plan gives users no reversal path

The plan makes cost events positive-only at the migration/DTO boundary (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:164`, `docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:430`) and adds a test that cancelled transfers reject new cost events (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:1090`). Therefore an `at_initiate` cost capitalized before cancellation remains in WAC forever.

That may be correct if the freight cost was actually paid; it is wrong if the quote was voided with the cancelled transfer. The plan should state the policy and provide a clear compensating workflow if reversal is expected. Today the user cannot add a negative cost event, cannot add a cost event after cancellation, and the cancel flow has no "reverse cost events" option.

### 4. `TransferReversed` is added as inbound, but outbound/reporting semantics are not reviewed

Batch F adds `TransferReversed` and makes it inbound (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:1949`, `docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:1951`). Existing enum semantics only distinguish inbound/outbound lists (`apps/api/app/Modules/Inventory/Domain/Enums/MovementType.php:16`). That may be correct for stock quantity, but reports filtering for "transfers in" vs "reversals" need a distinct category. The plan should grep/report call sites for `MovementType::TransferIn` and `isInbound()` before adding a new audit category.

## P2 (worth fixing during execution)

### 1. Mixed per-event distribution modes make `allocated_transfer_cost` ambiguous unless documented

Task A7 lets every event carry its own distribution and then accumulates each event's rounded allocation into `line.allocated_transfer_cost` (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:723`, `docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:738`). It also keeps `stock_transfers.transfer_cost` as a simple event amount sum (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:761`). Existing UI/reporting reads both transfer-level cost and line allocations (`apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx:129`, `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx:215`).

The sums still conceptually match across mixed distributions, aside from rounding drift, but the line field no longer answers "how was this transfer's default distribution allocated?" It answers "sum of all event allocations, each possibly using a different mode." Document that the event ledger is source of truth and line allocation is a convenience projection.

### 2. `AtInitiate.allowedTransferStatuses()` is unreachable or bypassed

The enum says `AtInitiate` allows Draft/InTransit (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:231`), but public `recordCostEvent()` rejects `AtInitiate` (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:674`) and `initiate()` calls `recordCostEventInternal()` specifically to bypass the public phase guard (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:818`). Either have the internal path run the guard after `moveSourceToInTransit()`, or remove `AtInitiate` from the public guard map and document that it is service-internal.

### 3. The same-key-different-payload fingerprint is order-sensitive and incomplete

The fingerprint includes lines in submitted order (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:1490`). Because the service rejects duplicate product rows (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:496`), line order is not semantically meaningful for the current transfer. The same products/quantities in a different order should probably replay, not 409. The fingerprint also omits `transferNumber` and `transferType`, which exist on the DTO (`apps/api/app/Modules/Inventory/Application/DTOs/InitiateTransferData.php:30`, `apps/api/app/Modules/Inventory/Application/DTOs/InitiateTransferData.php:31`).

Canonicalize the line list and explicitly include or exclude every DTO field with a comment explaining why.

### 4. Batch B4's "parallel" test does not exercise the race it claims to cover

The planned concurrency test calls `initiate()` twice serially (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:1358`, `docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:1365`) and the plan admits it does not fork or force the read-before-insert miss (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:1372`). That only covers the existing short-circuit path, not the unique-violation catch path. Add a seam/fake repository, two DB connections, or a targeted unit test that forces `StockTransfer::create()` to throw `UniqueConstraintViolationException` and asserts the reload path.

### 5. The plan says it will reject sub-cent quantities, but no task does it

The "deliberately does NOT cover" section says this PR adds a regex validator to reject sub-cent quantities (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:2136`). No task modifies `StoreStockTransferRequest`, whose current rule still accepts `min:0.0001` (`apps/api/app/Modules/Inventory/Presentation/Requests/StoreStockTransferRequest.php:43`). Either add the validator/test or remove the claim.

### 6. Docs scope is internally inconsistent

D3 says the master-docs rewrite is deferred and only a forward-pointer lands (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:65`). The file map still lists direct edits to `CLAUDE.md`, architecture docs, database docs, and memory (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:101`). Batch H later only adds a forward-pointer (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:2012`). Clean this up before dispatch so workers do not edit deferred docs.

### 7. `recordCostEvent()` idempotency does not compare payloads

Create idempotency gets a payload hash in Batch B5 (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:1485`), but cost-event idempotency returns an existing event solely by key (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:683`). A client can retry the same key with a different amount/distribution and get the previous event as if the new cost was accepted. Apply the same fingerprint policy to cost events.

## P3 (nits, future)

- Several tasks do not end with a commit step even though the prompt asks to check commit boundaries: A6/A7/A8, B2, C1, D1-D5, E1-E4, and F1/F2 are batch-committed or left implicit (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:629`, `docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:631`, `docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:792`).
- Some snippets still contain placeholders or incomplete bodies, e.g. `// ...` in the WAC implementation (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:624`) and the Playwright cancel test (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:2100`).
- The plan uses `apps/erp/docs/...` in some doc file paths (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:101`, `docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:2018`), but this worktree's paths are rooted directly at `docs/...`.

## Architectural assessment — does the multi-event cost stream actually solve BLOCKER #1?

The architecture solves the algebraic part of BLOCKER #1 if the denominator is read consistently. For pure committed states:

- Initiate Draft -> InTransit: source stock decreases by Y and in-transit lines/status add Y, so owned denominator is unchanged. The plan records `at_initiate` after `moveSourceToInTransit()` (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:797`), matching the intended 90 + 10 = 100 case.
- Complete InTransit -> Completed: destination stock increases by Y and the transfer stops counting as in transit, so owned denominator is unchanged.
- Cancel InTransit -> Cancelled: source stock increases by Y and in-transit count drops by Y, so owned denominator is unchanged.
- Cancel Draft -> Cancelled: no stock motion and no in-transit count, so denominator is unchanged.
- Sale/non-transfer issue: stock decreases and in-transit is unchanged, so future cost events see a smaller owned denominator. That is correct if the sale means the company no longer owns those units. A cost event before the sale capitalizes across the sold units; a cost event after the sale does not.

At the exact moment of transitions, the same-transfer half-state double-count described in the prompt is not visible to another transaction because row locks and transaction atomicity serialize it. The cross-transfer stale-denominator race in BLOCKER 1 remains and must be fixed before the architecture is sound.

Two close-successive `initiate()` calls for the same product are mostly serialized by the existing product lock in `moveSourceToInTransit()` (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:304`). Multi-product transfers can still deadlock if two transfers lock overlapping products in opposite user-supplied line order; sorting lines/product locks by product ID before stock motion would reduce that risk.

## Coverage assessment — does the plan address every finding from both prior reviews?

Codex findings:

1. BLOCKER #1 WAC order-dependence: partially addressed by Batch A, but blocked by the stale denominator race above.
2. BLOCKER #2 complete retry safety: addressed by Batch B2/B3, but the idempotency lookup must be resource-scoped.
3. P1-1 parallel create: addressed by B4 in implementation, but the test does not exercise the catch path.
4. P1-2 transfer number race: addressed by B6; acceptable if the insert retry is implemented around the actual create.
5. P1-3/P1-4 re-label/event drift: addressed by Batch C. Threading movement type into `issue()`/`receive()` closes the audit-corruption window and event payload drift (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:1664`, `docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:1681`).
6. P2-1 frontend create idempotency: addressed by E1.
7. P2-2 same-key-different-payload: addressed by B5, but fingerprint needs canonicalization and field coverage.
8. P2-3 service scoping: addressed by D1, but signature drift with idempotency must be resolved.
9. P2-4 ProRataValue zero-value fallback: addressed by D3 test/doc note.
10. P2-5 batch preservation: explicitly deferred (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:2139`); the plan does not make it harder than the current `unique(transfer_id, product_id)` already does (`apps/api/database/migrations/2026_05_28_120000_create_stock_transfers_table.php:82`).
11. P2-6 migration placement: addressed by G, but sequenced too late for A/B migrations and tests.
12. P2-7 create error details: addressed by E2.
13. P2-8 test gaps: addressed across B5, D3, D5.
14. P3-1 `-CANCEL` suffix: addressed by F2.
15. P3-2 cancel modal duplication: explicitly deferred (`docs/superpowers/plans/2026-05-28-inventory-transfer-remediation.md:2137`).
16. P3-3 negative transferCost DTO path: addressed by D4.

Opus findings:

- P1 complete/cancel idempotency and frontend create idempotency are mapped to B/E.
- Pagination and error-message UX are mapped to E3/E2.
- Quantity precision drift is claimed as mitigated, but no validator task exists.
- Cross-tenant product rejection is mapped to D2.
- Cancel audit semantics are mapped to F.
- AR locale fallback is mapped to E4.
- Migration placement is mapped to G, but needs earlier bootstrap handling.

## What I verified by reading the plan + the code it references

- Existing `initiate()` writes the transfer, creates lines, then calls `moveSourceToInTransit()` inside one transaction (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:86`, `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:114`, `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:146`).
- `moveSourceToInTransit()` locks product/source stock, issues stock, then flips status to InTransit (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:304`, `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:313`, `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:356`).
- Existing `complete()` locks transfer, receives destination stock, capitalizes cost, then marks Completed (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:160`, `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:174`, `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:193`, `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:198`).
- Existing WAC adjustment locks product and sums stock levels, but only after the caller enters the WAC method (`apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:470`, `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:480`).
- Existing `StockAdjustmentService::getOrCreateStockLevel()` supports destination rows that did not exist before receipt (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:482`).
- `transfer_cost` is currently read by stock-transfer list/detail UI and controller serialization (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:235`, `apps/web/src/features/stock-transfers/pages/StockTransferListPage.tsx:131`, `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx:129`).
- `tenants:migrate` is configured to run only `database/migrations/tenant` (`apps/api/config/tenancy.php:195`), while normal PHPUnit bootstrap has no equivalent tenant migration loader (`apps/api/phpunit.xml:4`, `apps/api/tests/bootstrap.php:21`).

## What I could not verify

- I did not run the existing tests; the prompt said this is a plan review and test execution was optional.
- I did not run a two-connection database concurrency harness. The concurrency findings are transaction-order analyses against the plan and existing lock placement.
- I did not verify PR #147 metadata or remote CI.
