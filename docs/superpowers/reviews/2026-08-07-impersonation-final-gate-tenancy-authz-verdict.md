# Final-Gate Adversarial Review — Tenancy/AuthZ — `codex/tenant-impersonation`

**Date:** 2026-08-07 · **Reviewer:** tenancy-authz-reviewer (Opus) · **Diff:** `46fd7decd..f3466dadc`
**VERDICT: REJECT.** 3 BLOCKER (two with proven exploits) / 7 MAJOR / 7 MINOR.

## Verified CORRECT (keeps fixes narrow)
- Tokenable IS the subject user; cross-tenant subject selection impossible (TenantSubjectTokenAdapter.php:48,76-79,100). EnforceTokenTenantClaim holds.
- Fail-closed is real — malformed/missing/throwing central lookup all → 401 (ImpersonationContext.php:53-71); `isLive()` re-checks every request, no cache.
- Permission intersection never exceeds subject (User.php:181-206 filters against token abilities recomputed per request); no Gate::before; wildcard disabled.
- Self-approval blocked (ElevationService.php:65, GrantLifecycleService.php:159); approver set enforced server-side, fails closed.
- Route middleware rule-12 compliant; permissions seeded; PHPStan L8 clean; 16 tests/114 assertions pass.

## BLOCKER 1 — Elevated session can mint itself a permanent tenant-wide window (PROVEN, status=201)
`config/support_access.php:36` `'*.manage'` elevation pattern matches `support-access.manage`; that route (`POST /support-access/grants`) is only `RequiresElevation`, and `TenantGrantController::store → createPreGrantedWindow` has NO impersonation check. Probe produced a 5-year `active` grant with `operator_id:null, subject_user_id:null` → any super-admin can then impersonate any user in that tenant for 5 years with no further consent. Fix: (1) hard-block `support-access.*` routes/paths; (2) deny-list `support-access.*`, `users.assign-roles`, `roles.manage` in `EffectivePermissionService::intersect` — self-referential perms must never be intersectable; (3) inject impersonation context into `createPreGrantedWindow`/`authorizeTenantManager`, throw when `current() !== null`.

## BLOCKER 2 — Elevated session can approve the operator's own pending grant (PROVEN, status=200)
Same root cause: `POST /support-access/requests/{grant}/approve → approveByTenant` checks only tenant match + `support-access.manage`, both satisfied by the impersonated subject. Consent handshake (spec §4.3) fully bypassable. Fix: the impersonation-context refusal in `authorizeTenantManager` is load-bearing here.

## BLOCKER 3 — Pre-granted windows have no maximum duration
`validateRequest:209` only checks start<expiry & expiry>now; FormRequest only `after:starts_at`. 5-year window accepted from a normal request → one tenant mis-click = permanent backdoor (contradicts §4.3 time-boxed). Fix: `support_access.max_grant_window_hours` (≈168) in validateRequest + `before:now()+7d` in CreateSupportWindowRequest.

## MAJOR
- **M4 — masking bypassed by every non-JSON response.** ImpersonationResponseMasking.php:24 short-circuits unless JsonResponse; `*.view` matches `workshop.payroll.view` (payroll export) and `documents.view` (PDF) → unmasked payroll/IBAN/tax-id downloads. Violates §3/§47. Fix: non-JSON under active context → 403 IMPERSONATION_EXPORT_BLOCKED (or explicit export allow-list) + test.
- **M5 — denied attempts & all lifecycle events absent from hash chain** (SAME as fiscal BLOCKER-2, independently confirmed). Only RequestAuthorized/Allowed ever written; guard sits before auditor (bootstrap/app.php:130-137) so 403s/401s leave no trace. Fix: record RequestDenied from guard refusals + session/grant/elevation lifecycle entries.
- **M6 — `is_sensitive` four-eyes trigger unreachable.** Written nowhere in app/ → every tenant non-sensitive → D3 four-eyes ships inert; pre-granted windows always land Active. Fix: admin sensitivity endpoint (audit-logged) or drop the dead columns.
- **M7 — four-eyes degenerates to two-eyes on pre-granted path.** approveSecond:159 excludes only operator_id; null for PreGrantedWindow, and authorizeStart never compares operator vs second_approved_by. Fix: refuse start when `second_approved_by === operator.id`.
- **M8 — approver seeded as full fleet super-admin** (SuperAdminSeeder.php:47-59 role super_admin) → can itself request/start/read all admin surfaces, inverting separation-of-duty. Fix: distinct `support_approver` role; gate grant/session creation on super_admin, approvals on approver role.
- **M9 — hard-block list self-disables on one malformed config entry.** stringList:66-71 returns [] if any element bad → drops ALL fiscal/tenant-delete/password-reset hard blocks to RequiresElevation. Fix: throw at boot (fail closed) + test.
- **M10 — global middleware-priority reorder untested against full suite.** bootstrap/app.php:118-125 hoists SetPermissionsTeam/EnforceTokenTenantClaim ahead of Throttle/SubstituteBindings/Authorize on EVERY route. Safer in principle, confirmed resolution order, but behaviour change API-wide. Fix: run full backend suite in CI on this branch before merge.

## MINOR
- **m11** four_eyes.write_elevation dead config + vacuous test.
- **m12** SuperAdminSeeder switched to updateOrCreate → rotates primary super-admin password on every re-seed (staging auto-deploys/re-seeds). Keep firstOrCreate for existing; scope updateOrCreate to partner row.
- **m13** hasPermissionTo override drops Spatie wildcard branch (latent; breaks if wildcards enabled later).
- **m14** hasImpersonationBearer adds CentralPersonalAccessToken::findToken to every POS void/refund/fiscal-sync hot path. Check currentAccessToken() first.
- **m15** reveal-in-full ships as dead schema (defensible per §10 Phase 3; document the limitation).
- **m16** DEPLOY: new permissions → must run permission:cache-reset after RolesAndPermissionsSeeder (tenant-blind cache) or existing tenants 403; tenant migration 2026_08_06_230200 must reach every tenant before first session or AuditService mirror 503s the feature.
- **m17** no impersonation banner in apps/pos (only web DashboardLayout) — §4.6 requires persistent banner; POS-only subject renders none.

**Pre-merge:** close BLOCKERs 1-3 (hard-block support-access.*, self-referential deny-list, impersonation-context refusal on tenant-side mutations, cap window duration), re-run full backend suite (M10), and add deny-path tests against the PRODUCTION permissions.write config, not the narrowed override at ImpersonationWriteGuardTest.php:67-68.
