# Sprint r8 Codex Spot-Check - v7 r7 Fix Closure

**Date:** 2026-05-25  
**Round:** r8 (Codex adversarial spot-check)  
**Scope:** Only the five r7 findings in `docs/superpowers/reviews/2026-05-24-sprint-r7-codex-review.md` against the v7 productization-sprint constitution/spec.  
**Verdict:** APPROVE-WITH-MINOR-EDITS

## Verdict Summary

The v7 fixes materially close all five r7 findings. The two blockers are closed: v7 chooses one tenant-qualified link mechanism, makes it mandatory across verify/reset/invitation, and adds the central Sanctum PAT model requirement with a PG integration test. The P1/P2 fixes are also closed: identity-index lifecycle coverage now includes admin-created users and deactivation, `TenantInitializationService` is consistently Phase 0 extension work, and route coverage is grep-based rather than a fixed file count.

One minor precision edit remains: the identity-index lifecycle text says "email change" but does not name the real code path, `UserController::update`, or its current lines. The requirement is still clear enough to implement, so this is not a revision blocker.

## Closure Checks

### B1 - Tenant-qualified verify/reset/invitation links

**Status:** CLOSED

v7 chooses a single concrete mechanism: every emailed pre-auth link embeds a signed `tenant_id`; it explicitly says this is mandatory and says not to also add `tenant_id` columns to token tables (`docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:92`). The mechanism is repeated for verify-email (`docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:93`), forgot/reset password (`docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:94`), and user invitation (`docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:291`).

The named files and acceptance are present. The spec names `VerifyEmailNotification`, `UserInvitation`, the forgot-password link builder, `VerifyEmailRequest`, `ResetPasswordRequest`, `ResetPasswordPage`, and verify-email React pages (`docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:92`). Acceptance requires signed `tenant_id` round-trip through requests, notifications, React pages, and PG tests proving correct tenant initialization before tenant-side token lookup for a multi-tenant email (`docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:121`).

This matches the real current code risk r7 flagged:
- Verification email currently sends only `token` (`apps/api/app/Modules/Identity/Application/Notifications/VerifyEmailNotification.php:39-45`).
- Verification request validates only `token` (`apps/api/app/Modules/Identity/Presentation/Requests/VerifyEmailRequest.php:25-29`).
- Verification service token lookup happens before any tenant qualifier (`apps/api/app/Modules/Identity/Application/Services/EmailVerificationService.php:39-60`).
- Reset UI reads/posts only `token` and `email` (`apps/web/src/features/auth/ResetPasswordPage.tsx:25-55`).
- Invitation link currently sends only `token` and `email` (`apps/api/app/Modules/Identity/Application/Notifications/UserInvitation.php:48-56`).
- The verify page currently posts only `token`, so it is correctly covered by the new "React verify-email pages" requirement (`apps/web/src/features/auth/VerifyEmailPage.tsx:27-36`).

### B2 - Central Sanctum PAT model and post-init lookup

**Status:** CLOSED

v7 classifies `personal_access_tokens` as central and keeps the tenant source as the existing `tenant:<uuid>` token ability, not a new PAT column (`docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:39`). That matches the real token minting code: login and register both prepend `tenant:<uuid>` to token abilities (`apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:220-223`, `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:377-380`).

The v7 fix explicitly handles the r7 failure mode: the pre-auth resolver can read central PAT first, but `auth:sanctum` then performs its own `PersonalAccessToken::findToken()` after tenancy initialization, so the PAT model must be pinned to central (`docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:97`; `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:274`). The required implementation is concrete: add `CentralPersonalAccessToken extends Laravel\Sanctum\PersonalAccessToken` with `protected $connection = 'central';`, register it with `Sanctum::usePersonalAccessTokenModel(CentralPersonalAccessToken::class)`, and add a PG integration test asserting PAT reads from central while `tokenable` resolves in tenant context (`docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:97`, `docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:122`).

The real code confirms why this is necessary. Current app code has only the default PAT table migration (`apps/api/database/migrations/2025_11_29_234638_create_personal_access_tokens_table.php:14-22`), no `CentralPersonalAccessToken` class under `apps/api/app`, and `AppServiceProvider` only customizes token validity through `Sanctum::authenticateAccessTokensUsing` (`apps/api/app/Providers/AppServiceProvider.php:130-136`), which does not pin the model connection. `apps/api/config/sanctum.php:8-87` also has no custom PAT model setting. `composer.lock` confirms this app is on Sanctum v4.3.1 (`apps/api/composer.lock:3007-3008`); `apps/api/vendor` is absent in this worktree, so I verified local app wiring rather than local vendor source lines.

### P1 - `central_identities` full lifecycle writer

**Status:** CLOSED, with one minor precision edit

v7 adds the central identity index shape and makes `IdentityIndexService` the single writer (`docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:225-243`). It now requires the service from `AuthController::register`, `UserController::store`, email change, and `UserController::destroy` deactivation (`docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:88`; `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:242`). Acceptance requires PG coverage for invited-user email-first login and deactivation (`docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:123`).

The cited user-management paths match the real controller:
- `AuthController::register` is the tenant/company/user signup path and currently creates everything in one default-connection transaction (`apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:251-389`).
- `UserController::store` creates invited tenant users (`apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:162-184`) and sends `UserInvitation` after commit (`apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:211-217`).
- `UserController::destroy` deactivates by setting `UserStatus::Inactive` (`apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:300-346`).
- The real email-change path is `UserController::update`; it accepts `UpdateUserRequest`, includes `email` in `$fieldsToUpdate`, and saves the user (`apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:232-279`). The request validates email uniqueness per tenant (`apps/api/app/Modules/Identity/Presentation/Requests/UpdateUserRequest.php:36-43`).

**Minor edit:** replace bare "email change" with `UserController::update` and cite `apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:232-279` in both lifecycle bullets. This avoids ambiguity for implementers.

### P1 - `TenantInitializationService` Phase 0 extension wording

**Status:** CLOSED

The stale "untouched" instruction is gone from the active T6 guidance. v7 says `TenantInitializationService` is modified in Phase 0 in the architecture grounding (`docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:36`), deliverable 9 (`docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:102`), the Phase 1 ops subsection placement note (`docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:144-152`), and the coordination notes (`docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:328`). The topology contract also says Phase 0 extends it to seed `countries`, `country_tax_rates`, and `country_payment_settings` (`docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:217`).

The remaining occurrences of "untouched" in the T6 spec are corrective text, not stale instructions: the header notes `TenantInitializationService "extended-not-untouched"` (`docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:4`), and the coordination note says it is **not** untouched (`docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:328`).

### P2 - Grep-based route coverage instead of stale 11-file count

**Status:** CLOSED

v7 replaces the fixed blast-radius count with grep-based acceptance. The T6 spec says the resolver must cover every `auth:sanctum` route surface and explicitly says not to rely on a fixed file count (`docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:95`). Acceptance now requires `grep -rn "auth:sanctum" apps/api/app apps/api/routes` and verifies every result is under a group that runs the pre-auth resolver first (`docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:120`). The topology contract says the same (`docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:270`).

The real code validates why grep-based coverage is the right closure. `auth:sanctum` appears in the root route file (`apps/api/routes/api.php:49`), Identity routes under both `web` and `api` groups (`apps/api/app/Modules/Identity/routes.php:21-60`), Product (`apps/api/app/Modules/Product/routes.php:28`), POS (`apps/api/app/Modules/POS/routes.php:30`), Document (`apps/api/app/Modules/Document/Presentation/routes.php:33`), Accounting (`apps/api/app/Modules/Accounting/Presentation/routes.php:25`), plus many other module route/provider surfaces found by `rg -n "auth:sanctum" apps/api/app apps/api/routes`. Laravel 12 middleware is indeed wired in `bootstrap/app.php`, where the current `api` group has only `EnsureFrontendRequestsAreStateful`, `SecurityHeaders`, `SetLocale`, and `CompanyContextMiddleware` today (`apps/api/bootstrap/app.php:41-67`), so v7's instruction to add the new resolver there is implementable and correctly placed.

I found no stale "11 files" / "11 module route files" count in the active T6 spec or topology contract. Historical r6/r7 review documents still quote the old count as prior findings, which is expected and not active sprint guidance.

## Required Minor Edit Before Merge

Update the identity-index lifecycle wording in:
- `docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:88`
- `docs/superpowers/specs/2026-05-24-t6-tenant-provisioning.md:123`
- `docs/superpowers/coordination/2026-05-24-migration-topology-contract.md:242`

Suggested replacement: `UserController::update` email changes (`apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:232-279`) instead of bare "email change".
