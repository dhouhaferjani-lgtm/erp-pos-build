# Tenant Impersonation Ground-Truth Delta Memo

**Reviewed:** 2026-08-06  
**Spec baseline:** `docs/superpowers/specs/2026-06-24-tenant-impersonation-support-access-design.md`  
**Code baseline:** local `dev` at `46fd7decde2b463fdd309d068e01eb212489ba5b`  
**Verdict:** **PROCEED.** No locked design decision is invalidated by code drift.

## Claim-by-claim re-verification

| Spec claim | Current-tree evidence | Delta / implementation consequence |
|---|---|---|
| Internal identities use a separate `SuperAdmin` model/table and `sanctum-admin` guard. | `apps/api/app/Models/SuperAdmin.php:14-21`; `apps/api/config/auth.php:46-53,73-81`; `apps/api/app/Http/Middleware/EnsureSuperAdmin.php:24-62`; `apps/api/routes/api.php:38-59`. | Still true. Admin endpoints must retain `auth:sanctum-admin` plus `super_admin`; tenant consent endpoints must use `auth:sanctum` plus `SetPermissionsTeam`. |
| Tenant bearer authentication can use a central PAT whose tokenable is a tenant `User`. | `apps/api/app/Modules/Identity/Infrastructure/CentralPersonalAccessToken.php:31-63` pins the token row centrally and deliberately keeps the related `User` on the active tenant connection. `apps/api/app/Providers/AppServiceProvider.php:148-171` registers that PAT model and respects per-token `expires_at`. `apps/api/tests/Feature/Identity/CentralPersonalAccessTokenPhase0bTest.php:78-122` exercises the exact central-token/tenant-user topology against PostgreSQL. | Stronger than the June spec: the required subject-token mechanism is now an explicit topology contract with a dedicated integration test. No alternate identity bridge is needed. |
| `ResolveTenancy` extracts `tenant:<uuid>` and initializes the tenant before Sanctum resolves the subject user. | `apps/api/app/Modules/Identity/Presentation/Middleware/ResolveTenancy.php:17-45,56-82,116-135`; `apps/api/bootstrap/app.php:67-103`. | Still true. It is now priority-pinned before authentication, so impersonation middleware must run route-level after `auth:sanctum`; it must not add a second tenancy initializer. |
| `EnforceTokenTenantClaim` accepts a matching subject-scoped token and rejects a mismatch. | `apps/api/app/Modules/Identity/Presentation/Middleware/EnforceTokenTenantClaim.php:47-105`; matching/mismatch coverage in `apps/api/tests/Feature/Identity/EnforceTokenTenantClaimTest.php:86-103` and the following mismatch case. | Still true. The middleware retains legacy grandfathering for tokens without a tenant claim, but impersonation tokens will always carry exactly one required claim and receive a stricter fail-closed check in `ImpersonationContext`. No exemption will be added. |
| `SetPermissionsTeam` scopes Spatie permissions to the authenticated tenant user. | `apps/api/app/Modules/Identity/Presentation/Middleware/SetPermissionsTeam.php:15-31`; required tenant route pattern in `docs/conventions/03-AUTHORIZATION.md:12-31`. | Still true. It cannot be placed on a `SuperAdmin` route because `SuperAdmin` has no `tenant_id`; the plan must split admin and tenant middleware groups explicitly. |
| Intentional cross-tenant admin operations use `#[CrossTenantRoute]`. | `apps/api/app/Shared/Architecture/CrossTenantRoute.php:10-41`; current admin controllers apply it method-by-method. | Still true. Every new central admin controller action will carry a non-empty, action-specific reason. The attribute is documentation/static-analysis metadata, not an authorization bypass. |
| Admin audit logging and viewer exist. | Central migration `apps/api/database/migrations/2025_12_01_194632_create_admin_audit_logs_table.php:12-31`; model `apps/api/app/Models/AdminAuditLog.php:13-55`; service `apps/api/app/Services/AdminAuditService.php:12-65`; viewer `apps/web/src/features/admin/pages/AuditLogsPage.tsx:10-140`. | Still true. The existing log has neither impersonation attribution nor chain fields. New migrations and services must add these without changing old-row semantics. |
| Tenant `audit_events` are automatically persisted from domain events. | Tenant migration `apps/api/database/migrations/tenant/2025_11_30_140000_create_audit_events_table.php:11-31`; `apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php:74-78,1013-1057`; `apps/api/app/Modules/Compliance/Services/AuditService.php:14-60`. | Still true, but `DomainEventSubscriber::persistEvent()` currently catches every throwable and continues. During impersonation, attribution and request audit must be written through a separate fail-closed path before allowing the action; subscriber enrichment remains defense-in-depth for domain events. |
| Existing tenant audit rows are tamper-evident. | `apps/api/app/Modules/Compliance/Domain/AuditEvent.php:78-124,188-235` computes a per-row SHA-256 integrity hash. | Partially true only in the broad sense: this is an isolated row hash, not a chain. The new session audit must introduce canonical serialization, `previous_hash`, per-session sequencing/locking, and an explicit verifier/tamper test. |
| A reusable fiscal hash-chain pattern exists. | `apps/api/app/Modules/Company/Domain/CompanyHashChain.php:13-28,47-68,81-87`. | Still true as a conceptual pattern, but not reusable as-is: it is tenant/company/fiscal-document specific and its model-level verifier compares a supplied expected hash only. The impersonation module needs its own central chain service and verifier. |
| Stancl `UserImpersonation` is present but disabled at `config/tenancy.php:175`. | `apps/api/config/tenancy.php:166-181`, specifically commented line 175. | Exact citation remains valid. It stays disabled. |
| The RoleController privilege-escalation prerequisite landed. | Commit `4e34a5152` is an ancestor of the branch. `StoreRoleRequest`, `UpdateRoleRequest`, and `DeleteRoleRequest` authorize `roles.manage`; `AssignRoleRequest` authorizes `users.assign-roles`. | Sequencing blocker is cleared. Impersonation must still apply the operator-support-scope ∩ subject-permissions rule; minting on the subject alone covers the upper bound but does not narrow the support scope, so the plan needs an explicit request-time support permission gate. |
| Admin and tenant frontend surfaces are net-new. | Admin routing/layout is in `apps/web/src/routes/index.tsx` and `apps/web/src/features/admin/components/AdminLayout.tsx`; tenant auth state is in `apps/web/src/stores/authStore.ts`; no source file contains an impersonation UI or context type. | Still true. Admin tokens are memory-only (`apps/web/src/features/admin/stores/adminAuthStore.ts`); the impersonation token must be handed to the tenant auth store without persisting the operator token. New tenant-data queries must use `tenantScopedKey`. |

## Code drift since 2026-06-24

- The backend identity/token spine cited by the spec has not been structurally displaced. The central PAT model, pre-auth resolver, tenant-claim enforcement, and admin guard remain the current path.
- `DomainEventSubscriber` gained additional event families after the spec. Its single `persistEvent()` choke point remains, so context enrichment can stay centralized.
- The web route table and admin pages expanded materially. This changes insertion points and test fixtures, not the locked UX or security decisions.
- Design-system enforcement is now stricter. New or touched TSX must use design tokens and all new user-visible copy must be translated through `t()`.
- The current `SuperAdminSeeder` creates only one account from `SUPER_ADMIN_EMAIL` / `SUPER_ADMIN_PASSWORD`. The owner's partner account is not represented yet. Implementation must add configurable approver identities and a deterministic partner-account seeding path without embedding credentials.

## Baseline execution evidence

- Backend path run: 49 tests, 166 assertions, exit 0 across the current central PAT, tenancy resolver, tenant claim, super-admin authorization, and domain-event subscriber test files. The first run emitted only the repository's missing-`.env` warning; the isolated worktree now points `.env` to `.env.example` for subsequent clean runs.
- Frontend path run: 4 files, 18 tests, exit 0 across admin API/auth and tenant-scoped query-key coverage.

## Gate 1 conclusion

The locked choices remain implementable on the current tree. The plan must treat the following as mandatory adaptations, not redesigns:

1. preserve the existing pre-auth tenancy and central-PAT topology;
2. apply different route stacks to central-admin and tenant-user endpoints;
3. add an explicit permission-narrowing gate, because subject tokenability alone does not express the operator support allowlist;
4. make impersonation request audit fail closed despite the legacy domain subscriber's best-effort behavior;
5. build a real append-only session chain rather than reusing the existing isolated `event_hash` field;
6. configure and seed the partner approver account through environment-backed settings while shipping four-eyes enforcement enabled.
