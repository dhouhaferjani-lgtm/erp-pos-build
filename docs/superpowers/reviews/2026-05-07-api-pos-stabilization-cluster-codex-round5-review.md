# api.pos-stabilization cluster — Codex round-5 review

Reviewer: codex
Reviewed at: 2026-05-07T09:15:06Z
Commit reviewed: 98c0d42982b4d7b2046a94c6c1e5702234fd7f7f
Verdict: REQUEST-CHANGES

## Summary

The round-5 targeted fixes for .050-.053 are directionally correct: `expireOrders()` no longer performs one fleet-wide UPDATE, the command calls the scoped service per tenant/company, HeldOrder show/recall/discard now include `tenant_id`, and the `ModelNotFoundException` recall path now bubbles as 404. The .048/.049 code-side classification flip is also sound: the targeted OrderController and KitchenDisplayController reloads now carry both predicates. I am still requesting changes because the requested phpstan gate is red on a touched test file, the inventory metadata for .048/.049 still points at the older company-only fix commit, and the reviewed route-read surface still has company-only list reads that violate the stated cluster invariant.

## Findings

### Finding 1 — Remaining list reads are still company-only
Severity: medium
Status: new
Where: apps/api/app/Modules/POS/Presentation/Controllers/OrderController.php:48; apps/api/app/Modules/POS/Presentation/Controllers/KitchenDisplayController.php:34; apps/api/app/Modules/POS/Application/Services/HeldOrderService.php:133
Description: The round-5 single-row reloads now use both predicates, but the same route-read surface still has list queries anchored only on `company_id`. `OrderController::index`, `KitchenDisplayController::index`, and `HeldOrderService::listHeldOrders()` can all return rows whose `company_id` matches the current company while `tenant_id` does not. That requires inconsistent data, so this is defense-in-depth rather than the original fleet-wide UPDATE bug, but it still violates the stated invariant: every route-anchored read in these files should include BOTH `tenant_id` and `company_id`. Add `CompanyContext::requireTenantId()` to these query builders and add a misconfigured-row denial test for at least the held-order list path.

### Finding 2 — Requested phpstan gate is red on a touched test file
Severity: low
Status: new
Where: apps/api/tests/Unit/POS/HeldOrderServiceTest.php:73
Description: The requested phpstan command fails on `tests/Unit/POS/HeldOrderServiceTest.php`, which was touched in this round for the `expireOrders()` signature change. Errors reported: line 73 redundant `assertNotNull()` on a PHPDoc-certain Carbon, line 103 possible null `expires_at` dereference, and lines 224/260 possible null `first()` dereferences. These may predate round-5, but the gate requested for this review is not clean on a touched file. Tighten the assertions so phpstan can prove non-null values before calling methods/properties.

### Finding 3 — .048/.049 inventory points to the old company-only fix commit
Severity: low
Status: new
Where: docs/superpowers/plans/tenant-isolation-sweep-inventory.yml:34147
Description: The .048/.049 classification flip itself is correct, and the current code includes the missing `tenant_id` predicates. However, both inventory records still have `fix_commit: de7078d0`, which is the round-4 company-only fix. Their `expected_scope` and `expected_fix` now describe the round-5 tenant-and-company remediation, so the fix commit should point at the round-5 code commit that actually added those predicates (`0ed0a3aa...`) or otherwise record a coherent history event explaining the split. As written, the inventory says the tenant-and-company fix was completed by a commit that did not contain it.

## Gates I ran

- phpunit: PASS — `vendor/bin/phpunit tests/Feature/POS/HeldOrderTenantIsolationTest.php` (5 tests, 17 assertions); `vendor/bin/phpunit tests/Unit/POS/HeldOrderServiceTest.php` (16 tests, 34 assertions); `vendor/bin/phpunit tests/Feature/POS/HeldOrderTest.php` (14 tests, 47 assertions); `vendor/bin/phpunit tests/Architecture` (1 test, 2 assertions)
- phpstan: FAIL — initial run hit sandbox TCP-listen restriction; rerun with `--debug` completed and found 4 errors in `tests/Unit/POS/HeldOrderServiceTest.php`
- pint: PASS — `./vendor/bin/pint --test app/Modules/POS app/Modules/Company/Services/CompanyContext.php tests/Feature/POS/HeldOrderTenantIsolationTest.php tests/Unit/POS/HeldOrderServiceTest.php`
- verify-history: PASS — `php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` verified 1516 events across 321 callsites with 0 problems

## Notes for the implementer

All four new callsites .050-.053 are correctly anchored on `fix_commit: dd8e9025...`, but they should not be flipped to fixed until the findings above are addressed. The .048/.049 classification flip is sound in code, but the inventory metadata is not coherent because it still points at the round-4 company-only fix commit. `git pull --ff-only` could not run in this sandbox because writing `.git/FETCH_HEAD` was denied, so this review is against the checked-out local HEAD `98c0d42982b4d7b2046a94c6c1e5702234fd7f7f`.
