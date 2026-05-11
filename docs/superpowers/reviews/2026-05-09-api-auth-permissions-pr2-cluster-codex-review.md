# Codex adversarial review - api.auth-permissions PR2 cluster

Review date: 2026-05-09
Reviewer: Codex
Cluster: `api.auth-permissions`
PR: PR2 - token-tenant-claim defense-in-depth
Branch: `feat/tenant-isolation-sweep-execution`
Branch tip commit: `e3b947fd`
Reviewed range: `f33318b3..e3b947fd`

Verdict: REQUEST-CHANGES

## Verdict

REQUEST-CHANGES

## Summary

PR2 adds `EnforceTokenTenantClaim`, updates normal and super-admin token issuance, mounts the middleware on the explicit `Route::middleware([...])` protected stacks, and extends `AuthLifecycleTest` plus feature coverage. The core middleware behavior is sound for the covered route stacks: mismatched PAT tenant claims return `401 TOKEN_TENANT_MISMATCH`, legacy tokens are grandfathered, session-cookie auth is exempt, and the modified route arrays consistently place the middleware after `SetPermissionsTeam`. However, one existing Sanctum-protected request path is outside those route arrays: `Broadcast::routes` still uses only `['api', 'auth:sanctum']`, so stale claimed PATs can reach broadcast channel auth without the new defense-in-depth check.

## Findings

| ID | Severity | File:Line | Description | Resolution |
|---|---|---|---|---|
| PR2-001 | MAJOR | `apps/api/app/Providers/BroadcastServiceProvider.php:36`; `apps/api/tests/Architecture/AuthLifecycleTest.php:100-114`, `:564-577` | `Broadcast::routes(['middleware' => ['api', 'auth:sanctum']])` remains a Sanctum PAT-protected endpoint but does not mount `EnforceTokenTenantClaim`. This is the same request-boundary class PR2 is meant to protect: a token whose `tenant:<uuid>` ability no longer matches live `User::tenant_id` is rejected on normal module routes, but can still reach `/broadcasting/auth`. The current architecture test will not catch this because its globs target route files/service providers and its matcher only recognizes `Route::middleware(...)`, not `Broadcast::routes(...)`. Channel callbacks are tenant/company anchored (for example `routes/channels.php:26-27`, `:44-45`, `:64-65`, `:85-86`, `:103-104`), so this is not a broad cross-tenant channel leak; it is a gap in Invariant D's token-claim enforcement surface. | Request changes: add `EnforceTokenTenantClaim::class` to the broadcast auth middleware stack after `auth:sanctum` (and add `SetPermissionsTeam::class` first only if broadcast auth needs Spatie team context), then extend `AuthLifecycleTest` or a focused architecture test to classify `Broadcast::routes(['middleware' => [...]])` protected stacks. |
| PR2-002 | MINOR | `apps/api/tests/Architecture/AuthLifecycleTest.php:164-174`, `:357-362`, `:470-474` | The architecture test enforces presence of `EnforceTokenTenantClaim::class`, but not the documented required order. The failure message says to add it "immediately after SetPermissionsTeam::class" at `:172-173`, while classification only checks `containsEnforceTokenTenantClaim()`. I verified the modified route stacks currently use `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, ...]` (for example `routes/api.php:49`, `ImportServiceProvider.php:57`, `ComplianceServiceProvider.php:104-108`, POS subroutes at `routes_orders.php:15`, `routes_tables.php:15`, `routes_held_orders.php:15`, `routes_kitchen.php:15`). This is a regression-test false negative, not a current runtime ordering bug. | Add an order assertion to the classifier so any protected stack with `EnforceTokenTenantClaim` before `SetPermissionsTeam` fails. Non-blocking relative to PR2-001. |

## Anticipated-Findings Cross-Reference

| # | Status | Notes |
|---|---|---|
| 1. Race window between Invariant A and Invariant D | ADDRESSED | The middleware docblock explicitly frames the stale-claim race window and rejects mismatched claimed PATs at `EnforceTokenTenantClaim.php:40-45`, with implementation at `:94-102`. PR2-001 is a separate coverage gap for broadcast auth. |
| 2. Grandfathering policy option (b) | ADDRESSED | Tokens with no `tenant:` ability pass at `EnforceTokenTenantClaim.php:90-92`, with feature coverage at `EnforceTokenTenantClaimTest.php:140-154`. |
| 3. Middleware order after `auth:sanctum` and `SetPermissionsTeam` | ADDRESSED FOR CURRENT ROUTE STACKS; TEST GAP NOTED | The modified `Route::middleware` stacks use the intended order, including representative lines `routes/api.php:49`, `ImportServiceProvider.php:57`, and POS subroutes at line 15. The architecture test does not enforce this order; see PR2-002. |
| 4. Super-admin handling | ADDRESSED | Non-`User` authenticatables pass at `EnforceTokenTenantClaim.php:60-62`; `super-admin` ability also passes at `:78-80`. Super-admin issuance uses `['super-admin']` at `SuperAdminAuthController.php:45-49`, and feature coverage is at `EnforceTokenTenantClaimTest.php:165-181`. |
| 5. TransientToken / session-cookie handling | ADDRESSED | Non-PAT auth is widened and exempted through `resolvePersonalAccessToken()` at `EnforceTokenTenantClaim.php:64-73`, `:108-111`, with session-cookie coverage at `EnforceTokenTenantClaimTest.php:193-203`. |
| 6. Glob expansion in `AuthLifecycleTest` | ADDRESSED WITH RESIDUAL BROADCAST GAP | POS subroutes and module service providers are included at `AuthLifecycleTest.php:100-114`, and the production floor remains at `:124`. `Broadcast::routes` is still outside this parser shape; see PR2-001. |
| 7. Issuance abilities preserve `'*'` for regular user tokens | ADDRESSED | Login and register now issue `['tenant:'.$user->tenant_id, '*']` at `AuthController.php:97` and `:239`. |
| 8. No POS/Voucher business-logic drift | ADDRESSED | PR2 changes route middleware imports/stacks only in POS/Voucher surfaces I inspected; no POS/Voucher controller/service behavior drift was present in the reviewed range. |
| 9. Behavioral DB-state assertions over mocks | ADDRESSED / NOT APPLICABLE TO PR2 | PR2's feature test uses real factories and real Sanctum tokens, not mocks, at `EnforceTokenTenantClaimTest.php:91-102`, `:114-129`, and `:142-154`. PR1 already covered lifecycle DB-state assertions. |

## Commit Linkage

- `60725289` - `feat(auth-permissions): EnforceTokenTenantClaim middleware + token tenant-claim issuance (PR2)`
- `e3b947fd` - `chore(auth-permissions): submit PR2 callsite (api.auth-permissions.005) for review at fix commit 60725289`

## Verification

Command run from `apps/api/`:

| Command | Result |
|---|---|
| `./vendor/bin/phpunit tests/Feature/Identity/EnforceTokenTenantClaimTest.php tests/Architecture/AuthLifecycleTest.php` | PASS - 7 tests, 30 assertions. PHPUnit also emitted the existing product unit migration summary. |

## Recommendation

Do not proceed to PR3 or lock the `api.auth-permissions` cluster yet. Patch the broadcast auth route so `Broadcast::routes` is covered by `EnforceTokenTenantClaim`, add architecture coverage for that registration shape, and add the small order assertion while touching the classifier.
