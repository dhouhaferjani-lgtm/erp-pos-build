# T6 Phase 0a Adversarial Implementation Review
**Date:** 2026-05-25
**Reviewer:** Codex (gpt-5.5, high effort)
**Branch:** feat/t6-phase0a-auth
**Verdict:** NEEDS-REVISION

## Findings Summary
| Severity | Count |
|---|---|
| BLOCKER | 0 |
| P1 | 4 |
| P2 | 1 |
| SUGGESTION | 2 |

## Findings

### [P1] Multi-tenant password reset redemption is not tenant-bound in Phase 0a
- **Where:** apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:659
- **What I found:** `forgotPassword()` correctly iterates `CentralIdentity` memberships and sends a tenant-qualified reset link per tenant at lines 659-683. But redemption ignores the validated `tenant` field and calls `Password::reset()` with only `email`, `password`, `password_confirmation`, and `token` at lines 712-718. In the current single-public-schema phase, `TenancyResolver::initializeIfProvisioned()` returns false when the tenant schema/database does not exist at apps/api/app/Modules/Tenant/Application/Services/TenancyResolver.php:45-47, so Laravel's password broker resolves `users` by email through the default provider at apps/api/config/auth.php:103-107, not by `(tenant_id, email)`.
- **Why it matters:** Same-email-across-tenants is now supported via invitations and org picker. For a multi-tenant email, `password_reset_tokens` is still shared and email-keyed in Phase 0a; the last generated token wins, and reset redemption can update whichever `users` row Laravel's email-only provider returns first. That is a current wrong-tenant password reset risk, not only a Phase 0b concern.
- **Suggested fix:** Do not use the stock broker unqualified while the app remains single-schema. On reset, require and decrypt `tenant`, resolve that tenant, and perform token validation plus password update against `User::where('tenant_id', $tenantId)->where('email', $email)`. Alternatively bind a tenant-aware password broker/provider for Phase 0a, then simplify after token tables move tenant-side.

### [P1] Admin-triggered password reset still emits an unqualified Laravel reset link
- **Where:** apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:659
- **What I found:** The authenticated user reset endpoint creates a token and sends `Illuminate\Auth\Notifications\ResetPassword` at lines 659-661. The protected route is exposed at apps/api/app/Modules/Identity/routes.php:74. This bypasses the new `App\Modules\Identity\Application\Notifications\ResetPasswordNotification`, which is the only reset notification that carries the signed `tenant` parameter at apps/api/app/Modules/Identity/Application/Notifications/ResetPasswordNotification.php:40-48.
- **Why it matters:** The spec requires password-reset links to be tenant-qualified. This path sends a token-only reset URL, so after token tables move tenant-side the link cannot initialize the correct tenant before token lookup. In Phase 0a, it also feeds the same email-only broker behavior described above.
- **Suggested fix:** Replace the default notification with the new tenant-qualified reset notification and sign `$currentUser->tenant_id` for this endpoint. Add a regression test in `UserManagement/UserActionsTest` that asserts the admin-triggered reset URL contains a decryptable tenant qualifier.

### [P1] Removing check-email also removed the only per-IP email-enumeration throttle
- **Where:** apps/api/app/Providers/AppServiceProvider.php:185
- **What I found:** The login limiter is keyed by email when an email is present: `Limit::perMinute(5)->by($email ?: ($request->ip() ?? 'unknown'))` at lines 185-190. The diff deletes the previous `check-email` per-IP limiter; the removed comment explicitly said the broader login limiter could be defeated by rotating emails. The new login flow returns the org picker before password validation for multi-tenant emails at apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:200-222.
- **Why it matters:** The Balanced stance accepts showing memberships for a valid email, but only paired with meaningful rate limiting. As implemented, an attacker can enumerate many emails from one IP because each candidate email gets its own five-attempt bucket. The deleted per-IP `check-email` limiter was the only control that slowed rotating-email enumeration.
- **Suggested fix:** Add a second per-IP limiter to `auth.login`/org-discovery, or key the limiter on both normalized email and IP with an additional global per-IP cap. Add a test that rotates email addresses against `/api/v1/auth/login` and receives 429 after the per-IP budget.

### [P1] Tenancy resolver fails open when tenant initialization fails
- **Where:** apps/api/app/Modules/Tenant/Application/Services/TenancyResolver.php:52
- **What I found:** `initializeIfProvisioned()` catches every `Throwable` and returns false at lines 52-55. It also returns false when `databaseExists()` says the tenant database/schema is missing at lines 45-47. `ResolveTenancy` ignores that false return and continues the request at apps/api/app/Modules/Identity/Presentation/Middleware/ResolveTenancy.php:62-68.
- **Why it matters:** The no-op is correct for today's single public schema, but it is not sound post-flip. Once tenant databases are required, a request carrying a signed tenant, bearer `tenant:<uuid>` ability, or session tenant stamp must fail closed if that tenant cannot be initialized. Continuing on an un-switched default connection risks authenticating or querying against the wrong connection, or converting tenant-provisioning faults into confusing partial behavior.
- **Suggested fix:** Keep the Phase 0a skip only behind an explicit "single-schema compatibility" condition. When tenant DB mode is active, return a 401/403/503 before downstream middleware if the tenant source was present but `databaseExists()` or `tenancy()->initialize()` fails. Add a post-flip test that a missing tenant database never reaches `auth:sanctum`.

### [P2] Deactivate leaves inactive users in central_identities
- **Where:** apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:504
- **What I found:** `destroy()` deactivates and removes the index row at lines 361-371, but the separate `deactivate()` endpoint only revokes tokens and sets `UserStatus::Inactive` at lines 504-509. It never calls `IdentityIndexService::remove()`. The reconcile command defines the desired index as users with email and `status != Inactive` at apps/api/app/Modules/Tenant/Application/Commands/ReconcileIdentitiesCommand.php:45-50.
- **Why it matters:** Until reconciliation runs, deactivated users still appear in email-first org discovery and can receive forgot-password reset links. Login still blocks them with `isActive()`, so this is not an immediate login bypass, but it violates the index lifecycle invariant and leaks inactive memberships.
- **Suggested fix:** In `deactivate()`, call `$this->identityIndexService->remove($user->email, $currentUser->tenant_id)` after the status change. In `activate()`, decide whether reactivation should call `record()` so the index lifecycle matches the actual account lifecycle.

### [SUGGESTION] Tenant link qualifier is tamper-proof but not purpose-, token-, email-, or expiry-bound
- **Where:** apps/api/app/Modules/Tenant/Application/Services/TenantLinkSigner.php:29
- **What I found:** `TenantLinkSigner::sign()` encrypts only the tenant id at lines 29-31, and `extract()` returns only that tenant id at lines 38-46. The same opaque blob can be replayed indefinitely across verify-email, reset-password, and invitation flows for the same tenant.
- **Why it matters:** The flow tokens still carry the actual authorization, so I did not find a direct reset/verify bypass from this alone. But the review question asked whether the tenant binding is non-replayable across tenants. It is tamper-proof, but not non-replayable or context-bound.
- **Suggested fix:** Consider encrypting a small payload with `tenant_id`, `purpose`, `email` or `user_id`, issued-at/expiry, and optionally a hash of the flow token. Validate the purpose before initializing tenancy. This gives replay resistance and better diagnostics without adding tenant columns to token tables.

### [SUGGESTION] Register still globally rejects an email that could otherwise belong to multiple tenants
- **Where:** apps/api/app/Modules/Identity/Presentation/Requests/RegisterRequest.php:42
- **What I found:** Registration still validates `email` with `unique:users,email` at line 42. Invited users use tenant-scoped uniqueness at apps/api/app/Modules/Identity/Presentation/Requests/CreateUserRequest.php:33-38, so the same email can belong to multiple tenants by invitation but cannot self-register a second organization.
- **Why it matters:** This is the implementer's documented open product decision. Keeping the global rule reduces self-serve tenant spam, but it also makes registration inconsistent with the email-first identity model and blocks a legitimate owner from creating a second org with the same email.
- **Suggested fix:** Make the product decision explicit before Phase 0b. If same-email self-registration is allowed, remove the global rule and rely on tenant/domain creation controls and abuse throttles. If not, document the intentional exception to the multi-tenant identity model.

## Spec Compliance
- `central_identities`: PARTIAL. Migration and model exist, with no FK to tenants, but Phase 0a intentionally has no `central` DB connection and the model has no `$connection = 'central'`.
- `tenant:reconcile-identities`: PARTIAL. Command exists and prunes inactive/orphan rows, but it is still single-schema only and notes post-flip initialization as future work.
- `IdentityIndexService` lifecycle wiring: PARTIAL. Register, `UserController::store`, `update`, and `destroy` are wired. `deactivate()` is not wired even though it produces the same inactive state that reconciliation prunes.
- Email-first login: MOSTLY VERIFIED. 0/1/>1 resolution exists, org picker is implemented, explicit tenant login is supported, session tenant stamp exists, and suspended/archived tenants return 403 after valid credentials. Rate limiting needs revision because the new enumeration surface has no per-IP cap.
- `check-email` removal: VERIFIED. Route is removed from `Identity/routes.php`, request class is deleted, frontend debounce was removed, and the old limiter/test were removed.
- Tenant-qualified verify/reset/invitation links: PARTIAL. Public forgot-password reset links, verify-email links, and invitations carry `tenant`; admin-triggered reset does not. Reset redemption is not tenant-bound in current Phase 0a.
- Pre-auth ResolveTenancy middleware: PARTIAL. Middleware is wired onto `api` and `web` groups in `bootstrap/app.php`; grep found app route `auth:sanctum` usages under `api` or `web`, with super-admin using `auth:sanctum-admin`. Post-flip fail-open behavior needs revision.
- Bearer tenant source: VERIFIED for Phase 0a. Resolver extracts `tenant:<uuid>` from the PAT abilities and `EnforceTokenTenantClaim` remains post-auth. Central PAT model is explicitly deferred.
- Central Sanctum PAT model: DEFERRED WITH STUB. Skipped test exists at `CentralPersonalAccessTokenPhase0bTest.php` and documents the required Phase 0b implementation.
- Scope deferrals: VERIFIED. No diff in `config/tenancy.php`, `config/database.php`, `database/migrations/tenant/`, or `app/Modules/Fiscal`.
- Voucher ledger migration: VERIFIED behavior-preserving by inspection. The same self-FK is created after `Schema::create()` instead of inside it; final intended constraint is unchanged.

## Implementer Claims Verification
- "All five in-scope deliverables DONE + verified": PARTIAL. Most scaffolding exists, but password reset tenant binding, admin reset links, rate limiting, and deactivate/index drift need revision.
- "No Stancl flip / no migration moves / no central connection / no Fiscal touched": VERIFIED by diff.
- "voucher_ledger fresh-PG migration bug fixed, behavior-preserving": VERIFIED by diff; final self-FK is still declared.
- "ResolveTenancy has signed-link, bearer, and session branches": VERIFIED.
- "Resolver covers all auth:sanctum surfaces": VERIFIED by grep for route declarations; all non-comment app route declarations I found include `api` or the Identity `web` group before `auth:sanctum`. Super-admin surfaces use `auth:sanctum-admin` and are intentionally central.
- "Email-first login 0/1/>1, session stamp, suspended 403": VERIFIED, with the rate-limit caveat above.
- "Tenant-qualified signed links for verify-email, reset, invitation": PARTIAL. Public paths are covered; admin-triggered reset is not.
- "IdentityIndexService single writer wired into register/store/update/destroy": VERIFIED for those named methods, PARTIAL for lifecycle because `deactivate()` is unwired.
- "CentralPersonalAccessToken deferral stubbed": VERIFIED.
- "Register global unique email remains open product decision": VERIFIED.

## Verdict Rationale
The branch is directionally aligned with the email-first architecture and the Phase 0a deferral boundary is mostly respected. The resolver is wired broadly, the identity index exists, check-email is removed, and the central PAT deferral is documented instead of silently dropped.

It still needs revision before merging because password reset is the highest-risk pre-auth path and is not actually tenant-bound in the current single-schema phase. The new org-picker enumeration surface also lost the only per-IP mitigation, and the resolver's post-flip failure mode is fail-open. Those are security-relevant issues in the exact areas Phase 0a was meant to harden.
