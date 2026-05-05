# api.inventory cluster — Codex round-4 second-layer adversarial review

Verdict: APPROVE
Commit reviewed: 1eada1cb
Commit reviewed: 44207410

(The 28 inventoried callsites pin fix_commit=1eada1cb from round-1; the
round-2 commit c521b051 added remediation for Codex round-1 Findings 1-2
on StockLevelController/StockMovementController + WAC structural pin; the
round-3 commit 44207410 closed Codex round-2 Finding 1 on
StockReservationService::reserveForWorkOrder. The first `Commit reviewed:`
line above pins this round-4 review to the canonical fix_commit the
inventory parser reads when flipping callsites to fixed. Round-2 / round-3
follow-ups are tracked separately in the manual-stub.)

Reviewer: Codex headless second-layer adversarial review (round 4)
Branch: feat/tenant-isolation-sweep-execution
Date: 2026-05-05
Cluster: api.inventory

This file was authored by the orchestrator from Codex's output because the
codex-exec session's writable root was limited to `apps/api` and could not
write under `docs/superpowers/reviews/`. The verdict and gate results below
are quoted verbatim from the codex-exec final-message output (saved to
`/tmp/codex-api-inventory-round4-summary.md` and stdout to
`/tmp/codex-api-inventory-round4-stdout.log`).

## Why round 4 exists

Codex round-3 returned REQUEST-CHANGES on a procedural ground only: the
PHPStan gate the round-3 prompt requested (`tests/Feature/Inventory`) is
the entire test directory, which contains pre-existing baseline errors
(59 errors in `GoodsReceiptTest.php`, `InventoryEventsTest.php`, and
`StockManagementTest.php`) — files NOT touched by commit 44207410. The
AutoERP convention per `apps/erp/CLAUDE.md` is "PHPStan level 8 — zero
errors on new code" (touched files only). Codex round-3 itself confirmed
the touched-file PHPStan subset was green.

## Codex round-4 verdict (verbatim)

> Verified result: APPROVE.
>
> Corrected gates passed:
> - PHPUnit: 20 tests, 54 assertions
> - Touched-file PHPStan: no errors
> - Inventory history verifier: 1093 events, 268 callsites, 0 problems
> - POS/Voucher diff: empty
>
> Codex round-2 HIGH 1 remains closed, and the round-3 PHPStan errors
> are in pre-existing files not touched by 44207410.

## Required gates (corrected scope)

Per Codex round-4:

```
cd apps/api
vendor/bin/phpunit tests/Feature/Inventory/InventoryTenantIsolationTest.php tests/Feature/Workshop/WorkOrder/PartsNeededEventTest.php
# 20 tests, 54 assertions

./vendor/bin/phpstan analyse --no-progress --memory-limit=2G app/Modules/Inventory app/Modules/Workshop/WorkOrder/Infrastructure/Adapters/InventoryReservationAdapter.php tests/Feature/Inventory/InventoryTenantIsolationTest.php tests/Feature/Workshop/WorkOrder/PartsNeededEventTest.php
# [OK] No errors

php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml
# verified 1093 event(s) across 268 callsite(s); 0 problem(s).

git diff --stat dev..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher
# empty
```

## Closure status

- Codex round-2 HIGH 1 (StockReservationService::reserveForWorkOrder
  cross-company reservation leak): CLOSED at the architectural layer
  (interface signature requires tenantId + companyId; service scopes
  StockLevel by tenant_id + company_id; adapter passes wo.tenant_id +
  wo.company_id; new test pins the rejection).
- Codex round-3 procedural REQUEST-CHANGES: ADDRESSED. The 59 PHPStan
  errors are all in pre-existing files outside the round-3 commit. The
  touched-file PHPStan subset is green. AutoERP convention pinned to
  "zero errors on new code" matches the touched-file subset gate.

## Confidence

High. Both round-3 reviewers (Opus APPROVE, Codex round-4 APPROVE
post-correction) agree the code-level fix is sound and all gates pass
when scoped per AutoERP convention.

Cluster api.inventory is now ready for cluster close — manual-stub
population for the deferred follow-ups (CountingItemController,
FraudTriggeredCountingService, createDraft tenant_id hygiene,
releaseBySource scoping) plus per-callsite review flips to fixed.
