# Round-2 Re-Review: api.auth-permissions PR2 Cluster
Date: 2026-05-09
Fix commit: ef1ccbe0
Commit reviewed: ef1ccbe0
Branch tip: 5d53c512
Verdict: APPROVE

## PR2-001 Resolution Assessment (BroadcastServiceProvider coverage)

PR2-001 is closed.

`BroadcastServiceProvider` now imports both auth-permissions middlewares at `apps/api/app/Providers/BroadcastServiceProvider.php:7-8` and registers `/broadcasting/auth` through a literal `Broadcast::routes([...])` attributes array at `:47-54`. The middleware order is correct: `'api'` at `:49`, `'auth:sanctum'` at `:50`, `SetPermissionsTeam::class` at `:51`, and `EnforceTokenTenantClaim::class` at `:52`.

The architecture scan now reaches top-level service providers via `app/Providers/*ServiceProvider.php` in `ROUTE_FILE_GLOBS` at `apps/api/tests/Architecture/AuthLifecycleTest.php:114-118`. Production discovery separately finds `Broadcast::routes(...)` calls at `:654-666`, using `isBroadcastRoutesCall()` at `:737-744`; that matcher correctly limits itself to static `Broadcast::routes` calls by checking `StaticCall`, method name `routes`, and class last segment `Broadcast`.

The `Broadcast::routes` middleware extraction path is also wired correctly. `extractMiddlewareArrayFromBroadcastRoutes()` reads the first argument, treats no-arg `Broadcast::routes()` as an empty literal middleware list at `AuthLifecycleTest.php:813-820`, flags dynamic/non-array attributes as unclassifiable at `:827-830`, locates the associative `'middleware'` key at `:833-837`, requires its value to be a literal array at `:838-842`, and returns that middleware array at `:844`. If the middleware key is absent, it returns an empty literal array at `:847-851`, which keeps non-`auth:sanctum` broadcast registrations exempt.

I verified the regression path is covered by fixture: `Broadcast::routes(['middleware' => ['api', 'auth:sanctum']])` is parsed at `AuthLifecycleTest.php:356-359` and asserted to populate both the missing `SetPermissionsTeam` bucket at `:360-363` and missing `EnforceTokenTenantClaim` bucket at `:364-367`. Removing the claim middleware from `BroadcastServiceProvider` would therefore fail the architecture test.

## PR2-002 Resolution Assessment (middleware order enforcement)

PR2-002 is closed.

The classifier now includes an `enforce_token_tenant_claim_before_set_permissions_team` violation bucket in its return shape at `AuthLifecycleTest.php:416-420`, initializes it at `:425-428`, fills it for protected groups whose order helper returns false at `:461-463`, returns it at `:466-470`, and asserts it empty in the production scan at `:181-192`.

The order helper implements the intended index comparison. `enforceTokenClaimAfterSetPermissionsTeam()` records the first `SetPermissionsTeam::class` index at `AuthLifecycleTest.php:595-600`, records the first `EnforceTokenTenantClaim::class` index at `:601-603`, skips order enforcement when either middleware is absent at `:606-608`, and requires `$claimIndex > $teamIndex` at `:610`. That matches the requested behavior: correct order passes, inverted order fails, and missing middleware remains owned by the existing presence buckets.

## Self-Test Fixture Verification

The four new fixture classes behave as documented.

- Broadcast clean fixture: `Broadcast::routes(['middleware' => ['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]])` is defined at `AuthLifecycleTest.php:338-343` and asserted clean for both presence buckets at `:344-351`.
- Broadcast missing-both fixture: `Broadcast::routes(['middleware' => ['api', 'auth:sanctum']])` is defined at `:356-359` and asserted non-empty for both missing `SetPermissionsTeam` and missing `EnforceTokenTenantClaim` buckets at `:360-367`.
- Broadcast no-args fixture: `Broadcast::routes()` is defined at `:372-375` and asserted empty for both protected-route violation buckets at `:376-383`; this aligns with the extraction helper's empty-array exemption at `:813-820`.
- Inverted-order fixture: `Route::middleware(['api', 'auth:sanctum', EnforceTokenTenantClaim::class, SetPermissionsTeam::class])` is defined at `:387-392`; it is asserted clean for presence at `:393-400` and non-empty for the order bucket at `:401-404`.

One adjacent positive-control note: the existing route clean fixture at `AuthLifecycleTest.php:217-225` does not assert the new order bucket empty, but the inner protected fixture does assert correct-order cleanliness at `:329-332`, and the inverted-order fixture proves the negative case at `:401-404`. This is sufficient; no finding.

## New Findings (if any)

No new blocking or major findings.

I reviewed the edge cases called out in the prompt. The `Broadcast::routes()` no-args case is intentionally exempt through an empty synthetic middleware array at `AuthLifecycleTest.php:813-820`. Dynamic/non-literal broadcast attributes are not silently accepted: non-array first args return `null` at `:827-830`, and non-array middleware values return `null` at `:838-842`, which the classifier routes into `dynamic_middleware` at `:434-444`. The runtime broadcast stack includes `SetPermissionsTeam` for contract uniformity even though channel callbacks already anchor tenant/company access, as documented in `BroadcastServiceProvider.php:39-46`.

## Summary

The round-1 findings are resolved at fix commit `ef1ccbe0`. Broadcast auth now uses the same protected stack as tenant user routes, the architecture test sees `Broadcast::routes(...)`, and the classifier enforces `EnforceTokenTenantClaim::class` after `SetPermissionsTeam::class` when both are present.

Verification run from `apps/api/`: `./vendor/bin/phpunit tests/Architecture/AuthLifecycleTest.php tests/Feature/Identity/EnforceTokenTenantClaimTest.php` passed with 7 tests and 41 assertions.

Commit linkage:

- `ef1ccbe0` - `fix(auth-permissions): Codex round-1 PR2-001 + PR2-002 — broadcast auth + arch-test order assertion`
- `5d53c512` - `chore(auth-permissions): record Codex round-1 PR2 REQUEST-CHANGES + resubmit at fix commit ef1ccbe0`
