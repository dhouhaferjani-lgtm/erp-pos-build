# api.super-admin-context + web.super-admin-frontend (paired) — Codex round-4 re-review

Reviewed commit: 28b8de47 (same as round 3)
Reviewer: codex
Date: 2026-05-08
Verdict: APPROVE

## Verdict rationale
APPROVE. The source-level round-2 closures remain sound: PurchaseHubOfferController::index honestly documents the global `purchase_hub:offers` cache leak as a known tenant-isolation gap, VoucherSyncController remains restored to its pre-cluster POS shape with no CrossTenantRoute import or attributes, the controller deferrals fixture contains the single VoucherSyncController wildcard entry with both OUT-OF-SCOPE and GAP rationale, the POS/Voucher scope diff is empty, the architecture suite is green, and inventory history verifies cleanly. The corrected mutation suite confirms the implementation's actual contract: deferrals short-circuit classification, attributes are independently valid classifications, and removing the VoucherSyncController deferral in the restored source state fails red on exactly the four deferred methods.

## Round-3 BLOCKER closure
The round-3 BLOCKER was caused by a checklist-design defect in the requested mutation, not by a production-source defect. With VoucherSyncController CrossTenantRoute attributes reintroduced, removing the deferral entry cannot fail red because the attributes satisfy classifier branch (a). The corrected mutation (a), which leaves the deferral entry in place while adding attributes, behaves correctly: `vendor/bin/phpunit tests/Architecture` stays green with `OK (10 tests, 53 assertions)`.

## Corrected round-4 mutation tests
Mutation (a), corrected: temporarily added the CrossTenantRoute import and four VoucherSyncController method attributes while leaving the class wildcard deferral in place. Result: architecture suite passed, `OK (10 tests, 53 assertions)`. This confirms the fixture is a short-circuit and not a contradiction with attribute classification.

Mutation (b), unchanged: temporarily edited PurchaseHubOfferController::index reason text back to clean service-trust wording. Result: architecture suite passed, `OK (10 tests, 53 assertions)`. This reconfirms reason-quality semantics are human-reviewed for this session.

Mutation (c), unchanged: temporarily emptied `tests/Architecture/fixtures/controller-tenant-context-deferrals.json` with VoucherSyncController restored to the reviewed source state. Result: architecture suite failed red in `ControllerTenantContextTest::test_every_controller_method_is_classified`, naming exactly `App\Modules\POS\Presentation\Controllers\VoucherSyncController::pullVouchers`, `pullVoucherLedger`, `pullReceiptQrIndex`, and `pushVoucherLedger`; failure summary was `Tests: 10, Assertions: 46, Failures: 1`. All temporary edits were restored before this verdict file was written, and the final architecture suite passed again with `OK (10 tests, 53 assertions)`.

## Scope-enforcement separation
The architecture test enforces the long-term invariant that every public method on every concrete controller is classified by CrossTenantRoute attribute, heuristic match, or deferrals fixture entry. It does not and should not enforce this paired-session's module boundary by permanently banning POS-scope attributes, because future POS/Voucher clusters may legitimately add those attributes. The session-bound invariant is enforced by diff review instead: POS, Voucher, apps/pos, and web POS paths must remain untouched relative to `fcb4c7ab` for this paired-cluster chain. That separation is explicit and is not a BLOCKER.

## Inventory state
`php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` passed at current HEAD:

```text
verified 1756 event(s) across 347 callsite(s); 0 problem(s).
```

Round 3 recorded 1744 events; the current 1756 count reflects the additional review/unblock history entries after `28b8de47`, with the same 347 callsites and 0 problems.

## Scope check
`git diff --stat fcb4c7ab..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher apps/web/src/features/pos` produced no output.

## BLOCKERs (if any)
None.

## NICE-TO-HAVEs (if any)
1. Future tooling can add coarse reason-tag semantics such as GAP / VERIFIED / STUB consistency checks, but this is out of scope for this paired-cluster session.

## Sign-off
Signed off. The corrected mutation suite passes in the expected shape, the previous BLOCKER is closed as a checklist defect, and no production source changes were left behind by the temporary mutation tests.
