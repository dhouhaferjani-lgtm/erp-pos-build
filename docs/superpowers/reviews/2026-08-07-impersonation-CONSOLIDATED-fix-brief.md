# Consolidated Fix Brief — Tenant Impersonation Final Gate — `codex/tenant-impersonation`

**Date:** 2026-08-07 · **Status:** all three final-gate reviews REJECT. Branch tip `f3466dadc` (WIP). DO NOT MERGE.
**Source verdicts (read all three):** `2026-08-07-impersonation-final-gate-{tenancy-authz,fiscal-audit,frontend}-verdict.md`

This brief deduplicates and severity-orders every finding into ONE fix pass so the lane does one round-trip. Fix in the numbered order below; the ordering is deliberate (the enforcement-perimeter blockers first, then the gate that would have caught the FE drift, then the rest).

## What all three reviewers verified CORRECT (do not touch — regressions here are worse than the bugs)
- Token tokenable IS the subject user; cross-tenant subject selection impossible; EnforceTokenTenantClaim holds.
- Fail-closed on every grant-resolution failure (→401); no grant caching; per-request re-check.
- Permission intersection never exceeds subject; no Gate::before; wildcard disabled.
- Pre-existing audit hash chain byte-identical; fiscal signed bytes completely untouched; new session-chain crypto + tamper test genuine.
- Route middleware rule-12 compliant; permissions seeded; FE route/permission gating clean; API unwrapping correct; AuthProvider path sound. PHPStan L8 clean; all targeted tests green; React Doctor honestly 92/100 (the commit-time "regression" was a hook base-selection artifact — `46fd7decd` is not an origin/dev ancestor — NOT real drift).

---

## TIER 1 — BLOCKERS (5 distinct; close all before any re-review)

**B1 — Self-referential permissions are intersectable → elevated session mints its own permanent access (PROVEN 201).**
Root: `config/support_access.php:36` `*.manage` elevation pattern matches `support-access.manage`, which guards the grant routes, which are only `RequiresElevation`. Three-part fix (all required):
1. `config/support_access.php`: add `support-access.*` to `hard_block_route_patterns` and `#/support-access(?:/|$)#i` to `hard_block_path_patterns`.
2. `EffectivePermissionService::intersect` (Domain/Services): strip `support-access.*`, `roles.manage`, `users.assign-roles` from the intersection unconditionally — a self-referential permission must never be intersectable.
3. `GrantLifecycleService::createPreGrantedWindow` + `authorizeTenantManager`: inject `ImpersonationContextProvider`, throw `AuthorizationException` when `current() !== null`.

**B2 — Elevated session can approve the operator's own pending grant (PROVEN 200).** Same root as B1; the impersonation-context refusal in `authorizeTenantManager` (B1 part 3) is load-bearing. Add a test proving an impersonated subject cannot reach `approveByTenant`.

**B3 — Fiscal write endpoints only `RequiresElevation`, not `HardBlocked`.** Missing from hard-block patterns (verified against `POS/routes.php`): `/pos/receipts/{id}/return`, `/pos/reports/z` + `/z/sync`, `/pos/audit-events/sync`, `/pos/shifts/{id}/close` + `/sync-close`, `/pos/voucher-ledger/sync`. Fix: add the patterns AND a route-table-enumerating test asserting every non-safe POS/Fiscal/Accounting route classifies `HardBlocked` — the allowlist rots on the next route otherwise.

**B4 — Denied attempts and ALL lifecycle transitions absent from the hash chain** (flagged by BOTH backend reviewers). Only `RequestAuthorized`/`Allowed` ever written; guard sits before auditor (`bootstrap/app.php:130-137`) so 403/401 refusals leave zero trace. Fix: emit chained `RequestDenied`/`Denied` from `ImpersonationWriteGuard` refusal paths, and `session_started/ended`, `grant_requested/approved/rejected/revoked`, `write_elevation_*` from their services. Either reorder audit before guard or have the guard append the terminating event itself.

**B5 — Persistent banner absent from POS terminal + entire admin console.** `DashboardLayout.tsx:45` is the only mount; `/pos/*`, `/company-onboarding`, `/admin/*` show no indicator under a live subject token. Fix: mount `<ImpersonationBanner/>` once in `App.tsx:31` above `<AppRoutes/>`, delete the DashboardLayout mount.

Plus B3's sibling hardening (fail-closed config): **hard-block list self-disables on one malformed config entry** (`ImpersonationActionClassifier::stringList:66-71` returns [] → drops ALL fiscal/tenant-delete/password-reset blocks). Throw at boot instead + test. (authz M9 — promoted here because it silently defeats B3.)

---

## TIER 2 — MAJORS

**Backend / security**
- Pre-granted windows have no max duration (authz B3): add `support_access.max_grant_window_hours` (≈168) in `validateRequest` + `before:now()+7d` in `CreateSupportWindowRequest`.
- Masking bypassed by every non-JSON response (authz M4): `*.view` matches payroll-export + document-PDF routes → unmasked payroll/IBAN/tax-id downloads. Non-JSON under active context → 403 `IMPERSONATION_EXPORT_BLOCKED` (or export allow-list) + test.
- `is_sensitive` four-eyes trigger unreachable (authz M6): writable nowhere → D3 ships inert, every tenant non-sensitive. Add audit-logged admin sensitivity endpoint or drop the dead columns.
- Four-eyes → two-eyes on pre-granted path (authz M7): `approveSecond:159` excludes only operator_id (null for windows); `authorizeStart` never compares operator vs `second_approved_by`. Add that comparison.
- Approver seeded as full fleet super-admin (authz M8, touches the business-partner decision): give a distinct `support_approver` role; gate grant/session creation on `super_admin`, approvals on the approver role.
- Chain records `outcome:allowed` pre-dispatch (fiscal M3): versioned `request_received` case + terminating event with real status after `$next()`.
- Raw `X-Request-ID` → PG uuid cast → client-triggerable 503 of the whole feature (fiscal M4): `Str::isUuid()` validation + PG-lane malformed-header test.
- Tenant mirror write not explicitly tenant-scoped (fiscal M5): shared explicit `$tenant->run()` resolver for write + verify command.
- Attribution only in `AuditService::record()` (fiscal M6): bypassed by `AuditEventSyncController` client envelopes (× B3 = injectable unattributed events), `FiscalSchemaCutoverService::create`, un-updated `AdminAuditService::log()`. Move to an `AuditEvent` `creating` observer + stamp `AdminAuditService`.
- Session-chain hash overloads `audit_events.event_hash` (fiscal M7): keep `event_hash` = `recomputeHash()`; chain hash stays in `impersonation_hash` only; document the `company_id === null` NF525-exclusion coupling.
- Global middleware-priority reorder untested against full suite (authz M10): run the FULL backend suite in CI on this branch and attach the result. **(This is the one place the lane must run the whole suite — get owner ack given the laptop constraint; run it in CI, not locally.)**

**Frontend**
- Impersonation token in localStorage while admin token is memory-only (fe M2): memory-only store or exclude `token` from partialize when impersonating.
- All feature forms validation-free (fe M3): zod + zodResolver + FormField translated errors; SupportWindowForm needs starts_at<expires_at.
- Every mutation failure silent incl. exit kill-switch (fe M4): `.catch → toast.error` (sonner); exit clears local state only on confirmed 2xx.
- New feature dir not enrolled in token/i18n eslint gates → 6 hardcoded white/alpha colors (fe M5): **do this FIRST of the FE fixes** — enroll `support-access/**` in `eslint.config.js:240-247` + `:358-382`, use `tokens.text.inverse`, EXTEND `designTokens.ts` with white-alpha tokens. It's the gate that would have caught the rest.
- Admin consent queue truncated to newest 20 (fe M6): thread page/per_page + pager from meta.
- Two hand-rolled dialogs duplicate canonical Modal (fe M7): render through `organisms/Modal`.

## TIER 3 — MINORS (land in the same pass)
fiscal: m8 attribution outside calculateHash (state explicitly; any inline fix = versioned V2, never mutate existing), m9 dead `impersonation_reveal_events` schema, m10 singleton-holds-scoped (Octane latent), m11 admin mirror drops operator IP, m12 `array_any()` PHP8.4 vs `^8.2` constraint.
authz: m11 dead four_eyes.write_elevation config, m12 SuperAdminSeeder `updateOrCreate` rotates primary super-admin password on re-seed (staging auto-reseeds — scope to partner row), m13 wildcard branch dropped, m14 token lookup on fiscal hot path, m15 reveal dead schema (dup of fiscal m9), m16 deploy note (permission:cache-reset + tenant migration ordering), m17 no POS banner (dup of B5).
fe: 8 StatusBadge, 9 dead tenantSupportAccessKeys (test-integrity gap), 10 unscoped invalidate, 11 meta mistype, 12 all-operators sessions + dead button, 13 nav sentinel-string i18n, 14 stale Sidebar comment, 15 missing plural forms en/fr/ar, 16 UUID in banner until /auth/me, 17 no auto-exit at expiry, 18 split the 3 POS + 1 inventory test repairs into a separate `fix(tests)` commit (they're legit pre-existing-red fixes, but scope creep), 19 replace the WIP tip commit with a real one.

## Re-review protocol
Re-run all THREE reviewers after the fix pass. Deny-path tests must run against the PRODUCTION `permissions.write` config, not the narrowed test override at `ImpersonationWriteGuardTest.php:67-68`. Still handback-only — never push origin/dev.
