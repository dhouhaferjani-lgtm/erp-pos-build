# Codex adversarial review - api.auth-permissions PR1 cluster

Review date: 2026-05-09
Reviewer: Codex
Cluster: `api.auth-permissions`
Owner: claude
Branch: `feat/tenant-isolation-sweep-execution`
Reviewed commits: `cf7668d4`, `6d1e4a70`, `a474bb6d`
Commit reviewed: 6d1e4a70

## Findings

| Severity | Surface | File:line | Finding | Recommendation / status |
|---|---|---|---|---|
| LOW | Architecture test scan reach | `apps/api/tests/Architecture/AuthLifecycleTest.php:100` | The parser is strong for the literal `Route::middleware([...])->group(...)` shapes it elects to scan, but it is not truly universal across all protected route registrations in this repository. The configured globs omit POS auxiliary route files (`apps/api/app/Modules/POS/routes_*.php`, each currently protected with `SetPermissionsTeam` at line 14) and provider-registered route groups such as `apps/api/app/Modules/Compliance/Providers/ComplianceServiceProvider.php:103` and `apps/api/app/Modules/Import/Providers/ImportServiceProvider.php:56`. `Broadcast::routes(['middleware' => ['api', 'auth:sanctum']])` at `apps/api/app/Providers/BroadcastServiceProvider.php:36` is also outside this parser shape; current channel authorization is tenant/company anchored in `apps/api/routes/channels.php:26`, `:44`, `:64`, `:85`, and `:103`, so I do not treat it as a current PR1 blocker for the route-group contract. | Non-blocking residual risk. Current out-of-glob POS/provider `Route::middleware` groups I checked already include `SetPermissionsTeam`; add a follow-up scanner expansion if the desired contract is "every auth:sanctum route registration", not only the route-file literal groups documented in this PR. No in-band edit applied. |

No blocking findings remain.

## Hostile-grep result

Token issuance/deletion sweep:

```bash
rg -n "createToken\\(|tokens\\(\\)->delete\\(|personal_access_tokens|currentAccessToken|auth:sanctum|auth:sanctum-admin|SetPermissionsTeam|middlewareGroup|Route::group|Route::middleware\\(" apps/api/app apps/api/routes -g '*.php'
```

Relevant result:

- Sanctum issuance remains the three known callsites: `AuthController.php:94`, `AuthController.php:233`, and `SuperAdminAuthController.php:45`. PR2 owns tenant-claim changes; PR1 correctly leaves these alone.
- Token deletion callsites are the existing logout/password/user-deactivation flows plus the new lifecycle hooks at `apps/api/app/Observers/TenantObserver.php:97` and `apps/api/app/Observers/UserObserver.php:34`.
- `BroadcastServiceProvider.php:36` registers a protected broadcast auth route outside the new architecture parser's route-file shape. The broadcast channel callbacks themselves anchor tenant/company access through `User::canAccessChannel` / `User::canAccessCompanyChannel`.

Route-parser bypass sweep:

```bash
rg -n "Route::middleware\\([^\\[]|Route::middlewareGroup|Route::group\\(\\['middleware'|middleware\\(\\$|Broadcast::routes\\(\\['middleware'" apps/api/app apps/api/routes -g '*.php'
```

Relevant result:

- No `Route::middlewareGroup`, variable middleware arrays, or `Route::group(['middleware' => ...])` shapes were found in live route files.
- Single-string inner groups exist in `apps/api/app/Modules/Product/routes.php:104` and `:108`, but they are permission-only subgroups under a parent protected route group, not standalone `auth:sanctum` groups.
- `BroadcastServiceProvider.php:36` remains the only protected registration shape outside `Route::middleware`.

Scope-discipline check:

```bash
git diff --name-only 2ec486df..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher
```

Result: no output. PR1 made zero POS/Voucher changes after the module-gating lock.

## Anti-vacuousness checks

- A.1 is not vacuous: `SuperAdminController::suspendTenant` writes `status = 'suspended'` through Eloquent at `apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php:206`; `TenantObserver::updated` checks `wasChanged('status')` and `TenantStatus::Suspended` at `apps/api/app/Observers/TenantObserver.php:47`.
- The suspension race-window framing is honest: the observer docblock explicitly names the status-commit to token-revocation window at `TenantObserver.php:24`, and the implementation does not pretend to enforce a pre-commit lock or token tenant claim. That stricter defense is correctly left to PR2 / future transactional tightening.
- A.2 is correctly on `Tenant::deleting`, not `Tenant::deleted`: `users.tenant_id` cascades at `apps/api/database/migrations/2025_11_30_000003_create_users_table.php:33`, while Sanctum tokens are polymorphic `morphs('tokenable')` with no user FK at `apps/api/vendor/laravel/sanctum/database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php:16`.
- The deletion-path DB assertion is meaningful. If `TenantObserver::deleting` did not run, DB cascade would remove users but leave orphaned `personal_access_tokens` rows for `tokenable_type = User::class`; the test's `tokenable_id` assertion at `AuthPermissionsTenantIsolationTest.php:170` would still see the leaked row.
- A.3 is selective: `UserObserver::updated` only revokes on `wasChanged('tenant_id')` at `apps/api/app/Observers/UserObserver.php:33`, and `test_user_non_tenant_change_does_not_revoke_tokens` covers adjacent user updates at `AuthPermissionsTenantIsolationTest.php:235`.
- The forward-compat framing is accurate. I found the live tenant suspension endpoint, but no tenant deletion endpoint or live `users.tenant_id` move endpoint; current `UserController` queries are tenant-pinned to the authenticated user's tenant.
- Spatie team consistency is structurally satisfied for normal protected requests: `SetPermissionsTeam` reads `$request->user()->tenant_id` live at `apps/api/app/Modules/Identity/Presentation/Middleware/SetPermissionsTeam.php:27`, Spatie teams are enabled with `tenant_id` as the team key at `apps/api/config/permission.php:99` and `:134`, and role/user pivot tables include `tenant_id` in their primary keys at `apps/api/database/migrations/2025_11_29_231806_create_permission_tables.php:85`.
- The architecture test is not a zero-discovery pass: it enforces a protected-group floor at `AuthLifecycleTest.php:131` and self-tests clean, missing, super-admin, public, dynamic, chained, and inner protected shapes at `AuthLifecycleTest.php:173`.

## Manual rows

All five manual rows are present under `cluster_id: api.auth-permissions` in both the manual callsites file and generated inventory:

| ID | Stable key | PR scope | Status before this review |
|---|---|---|---|
| `api.auth-permissions.001` | `manual:api.auth-permissions:tenant-suspension-token-revocation` | PR1 | `under_review` |
| `api.auth-permissions.002` | `manual:api.auth-permissions:tenant-deletion-token-revocation` | PR1 | `under_review` |
| `api.auth-permissions.003` | `manual:api.auth-permissions:user-tenant-change-token-revocation` | PR1 | `under_review` |
| `api.auth-permissions.004` | `manual:api.auth-permissions:setpermissionsteam-universal-coverage-arch-test` | PR1 | `under_review` |
| `api.auth-permissions.005` | `manual:api.auth-permissions:token-tenant-claim-defense-in-depth` | PR2 | `in_progress` |

History is append-only before review processing:

```bash
php artisan sweep:inventory:verify-history
```

Result: `verified 1801 event(s) across 356 callsite(s); 0 problem(s).`

After `sweep:inventory:review`, rows `api.auth-permissions.001` through `.004` are `fixed` with reviewer `codex`, verdict `APPROVE`, review file `../../docs/superpowers/reviews/2026-05-08-api-auth-permissions-pr1-cluster-codex-review.md`, and review commit `6d1e4a70`. Row `.005` remains `in_progress` with no review fields populated.

## Verification

Commands run from `apps/api/` unless noted:

| Command | Result |
|---|---|
| `./vendor/bin/phpunit tests/Feature/Identity/AuthPermissionsTenantIsolationTest.php tests/Architecture/AuthLifecycleTest.php` | PASS - 8 tests, 28 assertions. PHPUnit emitted the existing product-unit migration seeder summary. |
| `php artisan sweep:inventory:verify-history` | PASS before review processing - 1801 events across 356 callsites, 0 problems. PASS after review processing - 1805 events across 356 callsites, 0 problems. |
| `git diff --name-only 2ec486df..HEAD -- apps/api/app/Modules/POS apps/pos apps/api/app/Modules/Voucher` | PASS - no output. |

## Justification

PR1 implements the three token lifecycle hooks in the correct lifecycle locations, registers both observers, and exercises the behavior with DB-state assertions that would fail on the relevant leaks. The cascade rationale is accurate: user rows are DB-cascaded, but Sanctum tokens are not. The accepted suspension race window is called out directly in the observer and is consistent with the stated PR1/PR2 split.

The architecture test is useful regression protection for its declared literal route-file shape and has a vacuous-pass floor plus classifier fixtures. Its "universal" wording should be read with the residual scan-reach limitation noted above, but I did not find a current protected `Route::middleware` group missing `SetPermissionsTeam`.

Verdict: APPROVE

APPROVE
