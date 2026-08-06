# Codex Dispatch Brief — Tenant Impersonation / Consent-Gated Support Access (A→Z, autonomous)

**Date:** 2026-08-06 · **Executor:** Codex desktop, fully autonomous with quality gates
**Runs in parallel with:** the launch-program fix lanes — this feature is admin-side and additive; it does not touch the tenant-facing product surface, POS, or fiscal write paths.

## Why now
Support is impossible under db-per-tenant without a controlled access path; today staff are `SuperAdmin` (`sanctum-admin` guard) and super-admin tokens are rejected by tenant routes — no sanctioned support path exists. The design was LOCKED 2026-06-24 and deferred post-launch; its stated prerequisite — the RoleController privesc fix — has since landed (`4e34a5152` merge of `fix/role-controller-authz`, TD-015 closed), so the sequencing block is cleared.

## Source of truth
- **Spec (LOCKED — do not re-litigate its decisions):** `docs/superpowers/specs/2026-06-24-tenant-impersonation-support-access-design.md`
- Tenancy model: db-per-tenant (Stancl `PostgreSQLDatabaseManager`), central `synerivia_central` + `tenant_<uuid>` DBs.

## Locked design decisions (from the spec — implement, don't redesign)
- D1 consent = per-incident approval **+** optional tenant pre-granted window. D2 = read-only by default, explicit in-session write-elevation. D3 = four-eyes for sensitive tenants/actions. D4 = 60-min TTL; the grant is the real-time kill switch.
- Token mechanics: mint a **separate Sanctum token whose tokenable is the subject tenant user** (so `ResolveTenancy` / `SetPermissionsTeam` / `EnforceTokenTenantClaim` all just work), carrying an `impersonation:<operator_id>` ability to preserve the real actor (AWS `sourceIdentity` pattern). **Permission intersection — never escalation.**
- New middleware: `ImpersonationContext` (fail-closed on expired/revoked grant) + `ImpersonationWriteGuard`.
- Audit: BOTH `admin_audit_logs` and tenant `audit_events` get `impersonator_id` + `impersonation_session_id`; the session log is **hash-chained** (tamper-evidence is the differentiator).
- UX: persistent banner during impersonation; tenant notified + tenant-facing log.
- Hard blocks: fiscal records stay immutable (operator gets no path users lack); destructive/financial writes gated; tenant-delete and password-reset **hard-blocked**.
- Reuse note: Stancl `UserImpersonation` (config/tenancy.php:175, disabled) is a partial fit only — mint the scoped Sanctum token instead.

## A→Z flow (each numbered step is a gate; do not proceed past a failed gate)
1. **Re-verify ground truth.** Read the spec end-to-end; verify cited file:line facts against the current tree (43+ days old — code moved). Produce a delta memo; if any locked decision is invalidated by drift, STOP and surface it instead of improvising.
2. **Write the implementation plan** (TDD task list, module placement per hexagonal architecture, migrations for central grant/session tables, route middleware per rule 12).
3. **GATE — adversarial review of the plan** before any code (standing owner rule): attack tenancy isolation, token-claim enforcement, permission intersection, fail-closed paths, hash-chain correctness. Written verdict to a file; BLOCKER findings must be resolved in the plan first.
4. **Implement in an isolated worktree off `dev`** (never on shared dev), strict TDD: each middleware/service lands red→green. No `app()` helper; constructor injection; enums for statuses; DTOs for JSONB.
5. **Milestone gates** (adversarial review at each, verdicts to files):
   a. Grant lifecycle (request → tenant consent → active → expiry/revoke) — prove revocation kills a live session ≤ one request.
   b. Token + middleware — prove: no grant ⇒ 403; expired ⇒ 403; permission intersection cannot exceed subject-user perms; `EnforceTokenTenantClaim` still holds.
   c. Write-guard + hard blocks — prove tenant-delete/password-reset/fiscal mutations are refused even with write-elevation.
   d. Audit + hash chain — prove both logs carry the impersonator identity and the session chain verifies; tamper test must fail verification.
6. **Frontend (apps/web admin + tenant-facing log/banner):** i18n via `t()` only, design tokens, `tenantScopedKey` for tenant-data queries, RequirePermission gating.
7. **Full verification:** PHPUnit by path (never full suite), PHPStan level 8 clean, Pint, `pnpm typecheck`/lint/test for web, `./scripts/preflight.sh`. End-to-end: a real grant→impersonate→act→revoke→audit-verify flow against a live local tenant DB.
8. **Final adversarial security review** (tenancy/authz focus) of the whole branch. APPROVE required.
9. **Deliverable:** feature branch off `dev` + review verdicts + a deploy note (new tables/migrations, permission seeding + `permission:cache-reset`, Horizon queue coverage if any new queues). **Do NOT merge to shared dev or push to origin/dev** — hand back for the orchestrator's dual-gate promotion.

## Non-negotiables
- Fail-closed everywhere: an error resolving a grant means NO access, never fallback access.
- No scope creep into MFA/auth redesign (separate lane), no Stancl impersonation feature enablement, no fiscal-path edits.
- Any spec-vs-code contradiction discovered = STOP and report, not silent adaptation.
