# api.broadcast-channels cluster — Codex round-2 review

Reviewed commit: 9ed0f82a
Reviewer: codex
Date: 2026-05-08
Verdict: BLOCK-WITH-CHANGES-REQUIRED

## Verdict rationale

Round 2 fixes the original mutation-b blocker and tightens receiver binding for the direct helper-call shape, but the architecture test still admits two realistic bypasses: a tenant-named closure can contain an allowlisted helper call in dead/ungated control flow while returning `true`, and a single-line bare `@cross-tenant-by-design` docblock passes because the regex treats the closing `*/` as justification text. Both bypasses let the production scan stay green under mutations that should fail, so this remains blocked.

## Area 1 — Mutation-b verification (round-1 BLOCKER)

Baseline command `vendor/bin/phpunit tests/Architecture/BroadcastChannelTenantContextTest.php` passed: `OK (2 tests, 12 assertions)`. I then replaced the imports channel body with `return true;` while preserving the `@cross-tenant-anchored` PHPDoc. The test failed as intended under `tenant_named_without_helper` for `routes/channels.php:44 (tenant.{tenantId}.company.{companyId}.imports)`. The route file was restored afterward and rechecked with `git diff -- apps/api/routes/channels.php` showing no output.

## Area 2 — Receiver-binding tightness (round-1 NICE-TO-HAVE #1)

The zero-parameter non-tenant positive control is covered by `sample-channel-routes-clean.php` and the self-test passed. Temporarily renaming the first parameter from `$user` to `$authenticatedUser` and calling `$authenticatedUser->canAccessChannel(...)` stayed green, which confirms the check binds to the closure's first parameter name rather than the literal `$user`. A property-fetch receiver, `$this->user->canAccessCompanyChannel(...)`, failed under `tenant_named_without_helper`; this is the intended policy for Laravel broadcast route closures, where the authenticated user should be the first closure parameter. A non-allowlisted call, `$user->isAdmin()`, also failed under `tenant_named_without_helper`.

## Area 3 — Self-test fixture coverage

The six committed fixtures are realistic and cover the direct round-1 bypass, stray helper receiver, dynamic channel name, bare multiline annotation, non-tenant unannotated channel, and a clean positive control. A closure assigned to a variable and then passed to `Broadcast::channel(...)` fails closed because discovery sees a non-Closure second arg and reports `tenant_named_without_helper`. However, the fixtures do not cover control-flow reachability: mutating the imports channel to `if (false) { return $user->canAccessCompanyChannel($tenantId, $companyId); } return true;` passed the architecture test. Because `closureCallsAllowedAuthHelperOnFirstParam()` accepts any descendant method call, the helper does not have to gate the authorization result.

## Area 4 — Production source untouched

`git diff dc67bcdb..9ed0f82a -- apps/api/routes/channels.php apps/api/app/Modules/*/Infrastructure/Broadcasting` produced no output, confirming the round-2 commit did not touch production broadcast routes or broadcast classes.

## Area 5 — Architecture test correctness

The full architecture suite passed at baseline: `vendor/bin/phpunit tests/Architecture` returned `OK (7 tests, 30 assertions)`. Production mutation 1, the anchored-bypass `return true;`, failed under `tenant_named_without_helper`. Production mutation 2, adding `Broadcast::channel('global.something', fn () => true);`, failed under `non_tenant_without_by_design`. Production mutation 3, adding `/** @cross-tenant-by-design */` above that non-tenant channel, unexpectedly passed: `OK (2 tests, 12 assertions)`. The likely cause is the annotation regex capturing `*/` as non-empty justification on a single-line docblock.

## Area 6 — Inventory state integrity

`php artisan sweep:inventory:verify-history --inventory-path=../../docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` returned `verified 1655 event(s) across 339 callsite(s); 0 problem(s).` Spot-checking `api.broadcast-channels.001` through `.013` showed `status: under_review`, `owner: claude`, and `fix_commit: dc67bcdb` for all 13 rows.

## Area 7 — NICE-TO-HAVE #2 deferral

The cluster review gate at inventory lines 449-470 still contains `docs/superpowers/reviews/2026-05-XX-broadcast-channels-cluster-claude-review.md`. I agree with the deferral rationale for this round: direct YAML edits would break the history hash chain, verify-history is currently clean, and the per-callsite review-file linkage at lock time is the canonical recorded linkage.

## BLOCKERs (if any)

1. Tenant-named helper detection is still control-flow blind. A closure can include an allowlisted helper call in a dead or non-authorizing branch and still return `true`; the production scan passes even though the helper does not gate authorization.
2. Single-line bare `@cross-tenant-by-design` annotations pass. The mutation `/** @cross-tenant-by-design */` above a new non-tenant channel stayed green, so the bare-annotation guard only catches the multiline fixture shape and misses a plausible inline docblock.

## NICE-TO-HAVEs (if any)

None.

## Sign-off

This review approves the round-2 direction for the original anchored-annotation bypass and the receiver-binding tightening, but it does not approve locking the cluster yet. The next fix should make the tenant-named rule verify that the allowlisted helper result is actually returned or otherwise gates every successful path, and should normalize/extract docblock annotation text so single-line bare annotations do not count as justified.
