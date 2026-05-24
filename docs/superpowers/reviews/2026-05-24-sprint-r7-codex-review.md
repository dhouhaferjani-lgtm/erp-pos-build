# Sprint r7 Codex Adversarial Review — Auth+Identity Rewrite (v6/v6.1)

**Date:** 2026-05-24
**Round:** r7 (Codex adversarial, scoped to v6 auth+identity rewrite)
**Verdict:** NEEDS-REVISION

## Verdict Summary

v6 is materially better than v5: it names every pre-auth route, removes global `check-email`, moves reset/verification tokens tenant-side, and specifies Laravel 12 middleware wiring in `bootstrap/app.php`. I would not start Phase 0 from it unchanged, because the tenant-qualified link/token mechanics for verify/reset are still not concrete enough, and the central `personal_access_tokens` design omits the Sanctum model/connection pinning needed after tenancy is initialized.

## Prior-Round Resolution Verification

### r5 BLOCKER B-1: Pre-auth identity flows under tenant-side users
**Status:** PARTIALLY-RESOLVED

The spec now explicitly scopes all affected flows: T6 says the current controller assumes global `users` across login, register, check-email, verify-email, forgot-password, and reset-password, and Phase 0 rewrites the full surface (`docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:86-98`). The topology reclassifies `email_verification_tokens` and `password_reset_tokens` as tenant-side and correctly notes that password reset tokens are email-keyed with no FK (`docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:40`, `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:281-290`). Code confirms the old global assumptions: `checkEmail` still does `User::where('email')` globally (`apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:422-427`), verification looks up a token before any tenant context (`apps/api/app/Modules/Identity/Application/Services/EmailVerificationService.php:39-60`), and reset uses the default Laravel broker (`apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:568-604`).

The remaining gap is completeness. The current verification email and request carry only a random token (`apps/api/app/Modules/Identity/Application/Notifications/VerifyEmailNotification.php:39-45`, `apps/api/app/Modules/Identity/Presentation/Requests/VerifyEmailRequest.php:25-29`), while `email_verification_tokens` has `user_id` but no `tenant_id` or email (`apps/api/database/migrations/2025_12_21_125019_create_email_verification_tokens_table.php:16-26`). Similarly, password reset frontend/backend payloads carry only email + token and no organization key (`apps/web/src/features/auth/ResetPasswordPage.tsx:25-55`, `apps/api/app/Modules/Identity/Presentation/Requests/ResetPasswordRequest.php:21-28`). A central email index cannot deterministically choose a tenant from a token-only verification link, or from a reset link for the same email in multiple tenants, unless Phase 0 also tenant-qualifies those links/tokens.

### r5 P1-1: Tenancy resolver middleware wiring
**Status:** RESOLVED

The spec no longer tries to put Stancl request-data middleware on the auth path. It says authenticated requests use a token/session-bound resolver, registered before `auth:sanctum` on the global `api` group and the Identity `web` group (`docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:268-279`, `docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:93-95`). That matches this Laravel 12 app: middleware is configured in `bootstrap/app.php`, not `Kernel.php` (`apps/api/bootstrap/app.php:34-67`), and Identity auth routes are under `middleware('web')` (`apps/api/app/Modules/Identity/routes.php:21-43`).

Composer confirms `stancl/tenancy` v3.10.0 (`apps/api/composer.lock:8098-8099`), but the vendor source is not installed in this worktree (`apps/api/vendor` is absent), so the constructor-signature check is **UNVERIFIABLE — file not found** for `apps/api/vendor/stancl/tenancy/src/Middleware/InitializeTenancyByRequestData.php`. The spec's direction is still implementable because it avoids that middleware for this sprint.

### r5 P1-2: Phase 0 reference-data seeding ownership
**Status:** PARTIALLY-RESOLVED

The main Phase 0 deliverable is now correct: reference-data tenant-side seeding is explicitly Phase 0 work, not Phase 1A (`docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:99`, `docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:137-150`). The topology says the same and specifies seeding order (`docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:203-218`).

One contradiction remains: T6 coordination notes still list `TenantInitializationService` under "Reuses" as "untouched" (`docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:317-323`), while the same spec says Phase 0 must extend that service (`docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:145-150`). That stale line can still mislead an implementer.

## New Findings

### [BLOCKER] Verify/reset links are not concretely tenant-qualified, so the tenant-side token plan is incomplete
- **Where:** `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:281-290`; `apps/api/app/Modules/Identity/Application/Notifications/VerifyEmailNotification.php:39-45`; `apps/api/app/Modules/Identity/Presentation/Requests/VerifyEmailRequest.php:25-29`; `apps/web/src/features/auth/ResetPasswordPage.tsx:25-55`
- **Claim under review:** v6 says verification tokens resolve tenant via `central_identities` or embedded `tenant_id`, and forgot/reset resolves tenant via email plus selected org before invoking the password broker.
- **What I found:** Current verification links send only `?token=...`, and the API request validates only that token. The token table stores `user_id`, `token`, and expiry, but no tenant key or email. Current reset UI reads `token` and `email` query params and posts only those plus the new password. User invitations also build set-password links with only `token` and `email` (`apps/api/app/Modules/Identity/Application/Notifications/UserInvitation.php:48-56`).
- **Why it matters:** After `email_verification_tokens` and `password_reset_tokens` move tenant-side, token-only verification cannot know which tenant DB to open. For reset, the same email may exist in multiple tenants by design, so `email + token` is not a stable tenant selector unless the reset link or token repository is tenant-qualified.
- **Suggested fix:** Make the contract choose one mechanism and put it in acceptance criteria: add signed `tenant_id`/org slug to verification, reset, and invitation links; or add `tenant_id` to token tables; or store opaque central redemption rows with tenant pointers. Update `VerifyEmailRequest`, `ResetPasswordRequest`, `VerifyEmailNotification`, `UserInvitation`, and the React verify/reset pages accordingly.

### [BLOCKER] Central Sanctum PAT storage needs an explicit central-connection token model
- **Where:** `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:39`; `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:272-277`; `apps/api/database/migrations/2025_11_29_234638_create_personal_access_tokens_table.php:14-22`; `apps/api/config/sanctum.php:8-87`; `apps/api/app/Providers/AppServiceProvider.php:130-136`
- **Claim under review:** `personal_access_tokens` is central, and the pre-auth resolver reads the central PAT, initializes tenancy, then `auth:sanctum` resolves the tenant-side user.
- **What I found:** The code has only the default Sanctum PAT table migration and no custom `PersonalAccessToken` model pinned to a central connection. The only Sanctum customization I found is `authenticateAccessTokensUsing`, which changes token expiry validation but does not change where Sanctum queries PAT rows.
- **Why it matters:** The new resolver can manually read the bearer token from central before tenancy init, but `auth:sanctum` still performs its own token lookup. Once tenancy is initialized, a default Eloquent PAT model will use the current default connection unless Phase 0 pins the model/query to central. That can make bearer auth look for `personal_access_tokens` in the tenant DB even though the table is classified central.
- **Suggested fix:** Add a Phase 0 requirement for `CentralPersonalAccessToken extends Laravel\Sanctum\PersonalAccessToken` with `$connection = 'central'`, register it via Sanctum's custom token model hook, and test that bearer auth reads PAT rows from central while resolving `tokenable` users in tenant context.

### [P1] `central_identities` sync must include user-management create/delete, not only registration
- **Where:** `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:241-243`; `docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:87-89`; `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:171-184`; `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:211-217`; `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:340-346`
- **Claim under review:** The central index is kept in sync by registration, invite flows, and deletion.
- **What I found:** T6's concrete deliverables name register creating `central_identities`, but do not call out the existing admin user lifecycle. `UserController::store` creates invited tenant users and emails an invitation; `destroy` soft-deletes users by setting `Inactive`.
- **Why it matters:** Invited users are a normal production path. If Phase 0 only updates registration, invited users will not appear in the central email index and cannot use email-first login, organization recovery, or tenant-qualified password setup reliably.
- **Suggested fix:** Add an `IdentityIndexService` or equivalent Phase 0 deliverable used by register, `UserController::store`, email updates, and deactivate/delete. Acceptance should test invited-user login and removal/deactivation behavior.

### [P1] Reference-data seeding still has a stale "TenantInitializationService untouched" instruction
- **Where:** `docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:99`; `docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:137-150`; `docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:317-323`
- **Claim under review:** r5 P1-2 is fully resolved and reference-data seeding is owned by Phase 0.
- **What I found:** The detailed Phase 0 sections are correct, but the coordination notes still say T6 "reuses" `TenantInitializationService` and marks it "untouched."
- **Why it matters:** The whole reference-data fix requires modifying that service before tenant claim/signup. The stale "untouched" note contradicts the deliverable and can lead to exactly the Phase 1 deferral r5 flagged.
- **Suggested fix:** Replace the coordination note with "extends `TenantInitializationService` in Phase 0 for countries, country_tax_rates, country_payment_settings, and TN compliance seeders."

### [P2] The "11 module route files" blast-radius count is stale
- **Where:** `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:270`; `docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:93`; `apps/api/routes/api.php:49`; `apps/api/app/Modules/Product/routes.php:28`; `apps/api/app/Modules/POS/routes.php:30`; `apps/api/app/Modules/Document/Presentation/routes.php:33`; `apps/api/app/Modules/Accounting/Presentation/routes.php:25`
- **Claim under review:** Phase 0 wires the request-time resolver across all 11 module route files using `auth:sanctum`.
- **What I found:** There are more than 11 `auth:sanctum` route surfaces. Examples include root `routes/api.php`, Product, POS, Document, Accounting, and many more module route files.
- **Why it matters:** The implementation direction "global `api` group plus Identity `web` group" is correct, but the fixed count is not. If acceptance is based on auditing exactly 11 files, route-provider-loaded modules can be missed.
- **Suggested fix:** Replace the number with a grep-based acceptance check: every route using `auth:sanctum` must either include the `api` group after the pre-auth tenant resolver is appended/prepended there, or explicitly include the resolver before `auth:sanctum`.

## Finding Summary

| Severity | Count |
|----------|-------|
| BLOCKER  | 2     |
| P1       | 2     |
| P2       | 1     |
| SUGGESTION | 0   |
