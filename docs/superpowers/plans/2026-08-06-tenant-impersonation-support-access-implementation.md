# Tenant Impersonation / Support Access Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver consent-gated, time-boxed, four-eyes support impersonation with subject-scoped Sanctum tokens, permission intersection, hard write blocks, dual audit attribution, a tamper-evident session chain, and complete admin/tenant UI.

**Architecture:** Add a hexagonal `SupportAccess` module. Central Eloquent adapters persist grants, sessions, elevation requests, reveal records, and the authoritative session-event chain; tenant adapters are entered only through the existing pre-auth tenancy/token path. A request-scoped context contract in `Shared` lets Identity, Compliance, and admin audit code consume attribution without importing the module. Global API middleware detects impersonation tokens, validates the central grant/session on every request, narrows permissions, applies read/write/hard-block policy, records a dual-log authorization event before the controller runs, and masks sensitive response fields.

**Tech Stack:** Laravel 12, PHP 8.2+ strict types, Sanctum 4, Stancl database-per-tenant, Spatie Permission/Data/TypeScript Transformer, PostgreSQL 16, React 19, TanStack Query 5, Zustand 5, Vitest, PHPUnit 11.

## Global Constraints

- The 2026-06-24 design and 2026-08-06 owner rulings are locked; this plan implements them and does not enable Stancl `UserImpersonation`.
- A session expires no later than 60 minutes after start and no later than its grant; the grant is checked on every request and is the live kill switch.
- The Sanctum tokenable is the subject tenant `User`; abilities include exactly one `tenant:<uuid>`, one `impersonation:<operator_id>`, one `impersonation-session:<session_id>`, the current `support:readonly|support:write`, and the effective `permission:<name>` intersection.
- Effective permissions are recomputed per request as configured support scope ∩ the subject user's current permissions; a support session never inherits a permission solely from the operator or from a stale token row.
- Four-eyes ships enabled. The second approver must be an active, different `SuperAdmin` in the configured partner approver set. Sensitive-tenant entry and every write elevation require that approval.
- Tenant deletion/deprovisioning, password reset/recovery, secret rotation, and every fiscal mutation are hard-blocked even when the session is write-elevated.
- Impersonation request audit is fail-closed: the controller is not called until the authoritative chain event, central `admin_audit_logs` mirror, and tenant `audit_events` mirror exist.
- New status/type/code columns use PHP backed enums. New JSONB fields use Spatie Data DTO casts. No float touches money or quantity.
- Controllers and services use constructor injection with `private readonly`; no new `app()` helper calls.
- Tenant routes use `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]`. Central admin routes use `['api', 'auth:sanctum-admin', 'super_admin']`; `SetPermissionsTeam` is intentionally absent because `SuperAdmin` has no tenant id.
- New TSX uses design tokens and `t()` for every user-facing string. Tenant-owned TanStack keys use `tenantScopedKey`; tenant routes use `RequirePermission`.
- PHPUnit is run by explicit path only. Each behavior follows a recorded red→green cycle.
- Break-glass is out of scope. Real Tunisia tenants remain operationally prohibited until the E-4 Law 2004-63 / INPDP validation is recorded.

## File and Interface Map

### Shared contracts

- `apps/api/app/Shared/Contracts/SupportAccess/ImpersonationContextProvider.php` — read-only context access for other modules.
- `apps/api/app/Shared/Contracts/SupportAccess/TenantSubjectTokenPort.php` — load the tenant subject, current permissions, mint/update/delete a central PAT, and notify the tenant user while inside the correct tenant DB.
- `apps/api/app/Shared/Contracts/SupportAccess/AdminImpersonationAuditWriter.php` and `TenantImpersonationAuditWriter.php` — composition-bound mirror ports; the tenant port accepts tenant id directly and never requires a company context.
- `apps/api/app/Shared/DTOs/SupportAccess/ImpersonationContextData.php` — immutable runtime/banner DTO.
- `apps/api/app/Shared/DTOs/SupportAccess/TenantSubjectData.php` and `MintedImpersonationTokenData.php` — cross-module return values.
- `apps/api/app/Shared/DTOs/SupportAccess/ImpersonationAuditMirrorData.php` — immutable mirror payload shared by the SupportAccess chain, admin audit, and Compliance audit adapters.

```php
interface ImpersonationContextProvider
{
    public function current(): ?ImpersonationContextData;
}

interface TenantSubjectTokenPort
{
    public function subject(string $tenantId, string $subjectUserId): ?TenantSubjectData;
    /** @return list<string> */
    public function permissions(string $tenantId, string $subjectUserId): array;
    /** @param list<string> $abilities */
    public function mint(string $tenantId, string $subjectUserId, string $name, array $abilities, CarbonImmutable $expiresAt): MintedImpersonationTokenData;
    /** @param list<string> $abilities */
    public function replaceAbilities(int $tokenId, array $abilities): void;
    public function revoke(int $tokenId): void;
    public function notify(string $tenantId, string $subjectUserId, SupportAccessNotificationData $notification): void;
}

interface AdminImpersonationAuditWriter
{
    public function write(ImpersonationAuditMirrorData $event): void;
}

interface TenantImpersonationAuditWriter
{
    public function write(ImpersonationAuditMirrorData $event): void;
}
```

### SupportAccess module

- `Domain/Enums`: `GrantType`, `GrantStatus`, `SessionAccessLevel`, `SessionEndReason`, `ElevationStatus`, `SessionEventType`, `AuditOutcome`.
- `Domain/Entities`: `ImpersonationGrant`, `ImpersonationSession`, `ImpersonationElevation`, `ImpersonationSessionEvent`, `ImpersonationRevealEvent`, `ImpersonationSessionPermission` — central-connected models only.
- `Domain/Repositories`: grant/session/event repository interfaces.
- `Domain/Services`: `EffectivePermissionService`, `ImpersonationActionClassifier`, `SessionChainHasher`, `SessionChainVerifier`, `SensitiveResponseMasker`.
- `Application/DTOs`: request/response data plus `ImpersonationAuditDetailsData` for the session-event JSONB column.
- `Application/Services`: `GrantLifecycleService`, `SessionLifecycleService`, `ElevationService`, `SessionAuditService`, `SupportAccessQueryService`.
- `Infrastructure/Repositories`: central Eloquent implementations using explicit central transactions and row locks.
- `Infrastructure/Identity/TenantSubjectTokenAdapter` — implementation of the shared token port.
- `Presentation/Middleware`: `ImpersonationContext`, `ImpersonationWriteGuard`, `ImpersonationAudit`, `ImpersonationResponseMasking`.
- `Presentation/Controllers` and `Presentation/Requests`: separate admin and tenant entry points.
- `Providers/SupportAccessServiceProvider.php` and `Presentation/routes.php`.

### Schema

- Central `impersonation_grants`: UUID id; tenant/subject/operator; enum type/status; reason/ticket; requested/start/expiry timestamps; tenant approver; second approver; rejection/revocation fields; timestamps.
- Central `impersonation_sessions`: UUID id; grant/operator/subject/tenant; central PAT id; enum access level/end reason; start/expiry/end/elevation timestamps and approvers; chain sequence/previous hash/head hash; timestamps.
- Central `impersonation_elevations`: UUID id; session/requester/approver; enum status; reason and timestamps.
- Central `impersonation_session_permissions`: session id + permission primary pair.
- Central `impersonation_session_events`: UUID id; session and sequence; enum type/outcome; both identities; tenant/request/route/method/path/status/error; `details` JSONB cast to `ImpersonationAuditDetailsData`; previous hash/hash; occurred timestamp; unique `(session_id, sequence)`.
- Central `impersonation_reveal_events`: append-only record only; no reveal endpoint is enabled until a resource-specific resolver exists, so the safe default is stricter than the optional Phase-3 reveal path.
- Central `tenants`: `is_sensitive`, `support_access_starts_at`, `support_access_expires_at`.
- Central `admin_audit_logs` and tenant `audit_events`: `impersonator_id`, `impersonation_session_id`, `impersonation_event_id`, sequence/previous hash/hash mirror columns.
- Audit and session identity pointers preserve UUID evidence without cascading deletes; deleting an operator or tenant must not erase retained support-access evidence.

---

### Task 1: Persistence, enums, JSONB DTO, and append-only invariants

**Files:**
- Create: `apps/api/tests/Feature/SupportAccess/SupportAccessMigrationTest.php`
- Create: `apps/api/database/migrations/2026_08_06_230000_create_impersonation_access_tables.php`
- Create: `apps/api/database/migrations/2026_08_06_230100_add_support_access_fields_to_tenants_and_admin_audit_logs.php`
- Create: `apps/api/database/migrations/tenant/2026_08_06_230200_add_impersonation_fields_to_audit_events.php`
- Create: `apps/api/app/Modules/SupportAccess/Domain/Enums/GrantType.php`
- Create: `apps/api/app/Modules/SupportAccess/Domain/Enums/GrantStatus.php`
- Create: `apps/api/app/Modules/SupportAccess/Domain/Enums/SessionAccessLevel.php`
- Create: `apps/api/app/Modules/SupportAccess/Domain/Enums/SessionEndReason.php`
- Create: `apps/api/app/Modules/SupportAccess/Domain/Enums/ElevationStatus.php`
- Create: `apps/api/app/Modules/SupportAccess/Domain/Enums/SessionEventType.php`
- Create: `apps/api/app/Modules/SupportAccess/Domain/Enums/AuditOutcome.php`
- Create: `apps/api/app/Modules/SupportAccess/Domain/Entities/ImpersonationGrant.php`
- Create: `apps/api/app/Modules/SupportAccess/Domain/Entities/ImpersonationSession.php`
- Create: `apps/api/app/Modules/SupportAccess/Domain/Entities/ImpersonationElevation.php`
- Create: `apps/api/app/Modules/SupportAccess/Domain/Entities/ImpersonationSessionEvent.php`
- Create: `apps/api/app/Modules/SupportAccess/Domain/Entities/ImpersonationRevealEvent.php`
- Create: `apps/api/app/Modules/SupportAccess/Domain/Entities/ImpersonationSessionPermission.php`
- Create: `apps/api/app/Modules/SupportAccess/Application/DTOs/ImpersonationAuditDetailsData.php`

**Produces:** enum-backed, central-pinned models and the complete schema used by all later tasks.

- [ ] **Step 1: Write the failing schema/model test.** Assert all six central tables, tenant/admin audit attribution columns, `tenants.is_sensitive`, enum casts, the DTO cast for `details`, central connection names, unique session sequence, and update/delete rejection on `impersonation_session_events`.
- [ ] **Step 2: Run the red test.**

Run: `cd apps/api && php artisan test tests/Feature/SupportAccess/SupportAccessMigrationTest.php --no-coverage`

Expected: FAIL because `impersonation_grants` and the module classes do not exist.

- [ ] **Step 3: Add migrations and minimal enum/model/DTO implementations.** Use PostgreSQL check constraints generated from enum values, SQLite-compatible tests, `CentralConnection` on every central entity, `ImpersonationAuditDetailsData::class` as the Eloquent cast, and database triggers that reject session-event UPDATE/DELETE.
- [ ] **Step 4: Run the green test and Pint on the created paths.**

Run: `cd apps/api && php artisan test tests/Feature/SupportAccess/SupportAccessMigrationTest.php --no-coverage && ./vendor/bin/pint app/Modules/SupportAccess database/migrations/2026_08_06_230000_create_impersonation_access_tables.php database/migrations/2026_08_06_230100_add_support_access_fields_to_tenants_and_admin_audit_logs.php database/migrations/tenant/2026_08_06_230200_add_impersonation_fields_to_audit_events.php`

Expected: PASS; no formatting diff on a second Pint run.

### Task 2: Configuration, partner approver seeding, permissions, and provider wiring

**Files:**
- Create: `apps/api/tests/Feature/SupportAccess/SupportAccessConfigurationTest.php`
- Create: `apps/api/config/support_access.php`
- Modify: `apps/api/.env.example`
- Modify: `apps/api/database/seeders/SuperAdminSeeder.php`
- Modify: `apps/api/database/seeders/RolesAndPermissionsSeeder.php`
- Create: `apps/api/app/Modules/SupportAccess/Providers/SupportAccessServiceProvider.php`
- Modify: `apps/api/bootstrap/providers.php`

**Produces:** enabled four-eyes policy, environment-backed partner account, `support-access.view/manage`, and interface bindings.

- [ ] **Step 1: Write failing tests.** Prove `four_eyes.enabled`, `sensitive_tenants`, and `write_elevation` default true; an empty/malformed approver set denies approval; the configured partner email is seeded as an active super-admin when its password is supplied; support permissions are present and only the tenant admin role receives `support-access.manage`.
- [ ] **Step 2: Run red.**

Run: `cd apps/api && php artisan test tests/Feature/SupportAccess/SupportAccessConfigurationTest.php --no-coverage`

Expected: FAIL because config and permissions are absent.

- [ ] **Step 3: Implement config and seeders.** Use `SUPPORT_ACCESS_APPROVER_EMAILS`, `SUPPORT_ACCESS_PARTNER_NAME`, `SUPPORT_ACCESS_PARTNER_EMAIL`, and `SUPPORT_ACCESS_PARTNER_PASSWORD`; never hard-code a password. Seed with `updateOrCreate`, refuse to create the partner if password is absent, and keep approval fail-closed unless the account exists and is active.
- [ ] **Step 4: Bind repositories, scoped context store, and token adapter in the new provider, register it, then run green.**

### Task 3: Per-incident and pre-granted grant lifecycle

**Files:**
- Create: `apps/api/tests/Feature/SupportAccess/GrantLifecycleTest.php`
- Create: `apps/api/app/Modules/SupportAccess/Domain/Repositories/ImpersonationGrantRepository.php`
- Create: `apps/api/app/Modules/SupportAccess/Infrastructure/Repositories/EloquentImpersonationGrantRepository.php`
- Create: `apps/api/app/Modules/SupportAccess/Application/Services/GrantLifecycleService.php`
- Create: `apps/api/app/Modules/SupportAccess/Application/DTOs/GrantRequestData.php`
- Create: `apps/api/app/Modules/SupportAccess/Application/DTOs/GrantData.php`
- Create: `apps/api/app/Modules/SupportAccess/Application/DTOs/SupportAccessNotificationData.php`
- Create: `apps/api/app/Modules/SupportAccess/Presentation/Requests/RequestIncidentAccessRequest.php`
- Create: `apps/api/app/Modules/SupportAccess/Presentation/Requests/CreateSupportWindowRequest.php`
- Create: `apps/api/app/Modules/SupportAccess/Presentation/Requests/DecideGrantRequest.php`
- Create: `apps/api/app/Modules/SupportAccess/Presentation/Requests/RevokeGrantRequest.php`
- Create: `apps/api/app/Modules/SupportAccess/Presentation/Controllers/AdminGrantController.php`
- Create: `apps/api/app/Modules/SupportAccess/Presentation/Controllers/TenantGrantController.php`
- Create: `apps/api/app/Modules/SupportAccess/Presentation/routes.php`

**Interfaces:**

```php
final class GrantLifecycleService
{
    public function requestIncident(SuperAdmin $operator, GrantRequestData $data): GrantData;
    public function createPreGrantedWindow(User $tenantAdmin, GrantRequestData $data): GrantData;
    public function approveByTenant(User $tenantAdmin, string $grantId): GrantData;
    public function rejectByTenant(User $tenantAdmin, string $grantId, string $reason): GrantData;
    public function approveSecond(SuperAdmin $approver, string $grantId): GrantData;
    public function revoke(User|SuperAdmin $actor, string $grantId, string $reason): GrantData;
}
```

- [ ] **Step 1: Write failing lifecycle tests.** Cover mandatory reason/ticket/subject, tenant-id isolation, pending tenant consent, rejection, non-sensitive activation, sensitive `PendingInternal`, configured partner approval, operator self-approval denial, pre-granted window creation by `support-access.manage`, expiry, and idempotent revocation.
- [ ] **Step 2: Run red by path.** Expected failures are missing routes/classes, not validation typos.
- [ ] **Step 3: Implement repository/service, thin FormRequest controllers, database notification, and exact route groups.** Every admin method gets `#[CrossTenantRoute]`. Tenant decisions derive tenant id from the authenticated user, never request input. No queued notification is introduced.
- [ ] **Step 4: Run the test green and inspect `php artisan route:list --path=support-access`.** Assert the admin and tenant middleware stacks in the test.

### Task 4: Subject token minting and per-request permission intersection

**Files:**
- Create: `apps/api/tests/Feature/SupportAccess/SessionTokenTest.php`
- Create: `apps/api/app/Shared/Contracts/SupportAccess/TenantSubjectTokenPort.php`
- Create: `apps/api/app/Shared/DTOs/SupportAccess/TenantSubjectData.php`
- Create: `apps/api/app/Shared/DTOs/SupportAccess/MintedImpersonationTokenData.php`
- Create: `apps/api/app/Modules/SupportAccess/Infrastructure/Identity/TenantSubjectTokenAdapter.php`
- Create: `apps/api/app/Modules/SupportAccess/Domain/Services/EffectivePermissionService.php`
- Create: `apps/api/app/Modules/SupportAccess/Application/Services/SessionLifecycleService.php`
- Create: `apps/api/app/Modules/SupportAccess/Presentation/Requests/StartSessionRequest.php`
- Create: `apps/api/app/Modules/SupportAccess/Presentation/Controllers/AdminSessionController.php`
- Modify: `apps/api/app/Modules/Identity/Domain/User.php`
- Modify: `apps/api/app/Modules/Identity/Application/DTOs/AuthUserData.php`

**Produces:** a 60-minute-or-shorter central PAT whose tokenable is the tenant user, plus filtered permission behavior for Gate, direct Spatie checks, and `/me`.

- [ ] **Step 1: Write failing tests.** Prove no active grant returns 403; wrong operator/tenant/subject returns 403; expired grant returns 403; tokenable is the subject `User`; required abilities are present once; token/session expiry is `min(now+60m, grant expiry)`; readonly permissions equal configured readonly scope ∩ current subject permissions; subject permission removal takes effect on the next request; a support permission absent from the subject never appears in the token or `AuthUserData`.
- [ ] **Step 2: Run red.**
- [ ] **Step 3: Implement the tenant adapter with `Tenant::run`, session service, and effective permission service.** Store permission rows for evidence, but recompute against live subject permissions in middleware. Alias Spatie trait methods in `User` so impersonation `hasPermissionTo()` and `getAllPermissions()` are filtered by in-memory `permission:*` abilities while ordinary tokens remain unchanged.
- [ ] **Step 4: Run green plus existing tenant-claim tests.**

Run: `cd apps/api && php artisan test tests/Feature/SupportAccess/SessionTokenTest.php tests/Feature/Identity/EnforceTokenTenantClaimTest.php tests/Feature/Identity/CentralPersonalAccessTokenPhase0bTest.php --no-coverage`

### Task 5: Fail-closed context middleware and grant kill switch

**Files:**
- Create: `apps/api/tests/Feature/SupportAccess/ImpersonationContextMiddlewareTest.php`
- Create: `apps/api/app/Shared/Contracts/SupportAccess/ImpersonationContextProvider.php`
- Create: `apps/api/app/Shared/DTOs/SupportAccess/ImpersonationContextData.php`
- Create: `apps/api/app/Modules/SupportAccess/Application/Services/RequestImpersonationContext.php`
- Create: `apps/api/app/Modules/SupportAccess/Presentation/Middleware/ImpersonationContext.php`
- Modify: `apps/api/bootstrap/app.php`

**Produces:** global detection and validation after Sanctum auth, before controller authorization.

- [ ] **Step 1: Write failing request tests.** Exercise ordinary token no-op; malformed/duplicate operator or session abilities; operator/session/grant/tenant/subject/token-id mismatch; expired or ended session; expired/revoked grant; central lookup exception; live grant success; matching `EnforceTokenTenantClaim`; and revocation between two requests with the same live token.
- [ ] **Step 2: Run red.** Expected failure: probe controller is still reached after revocation.
- [ ] **Step 3: Implement middleware.** On any impersonation-shaped token error return 401 `IMPERSONATION_ENDED`; never downgrade it to an ordinary user request. Recompute live effective permissions and replace the current token's abilities in memory before FormRequest/Gate checks. Register it globally in the `api` group with middleware priority after authentication.
- [ ] **Step 4: Run green twice.** The revocation test must prove request 1 succeeds, revoke occurs, and request 2 cannot reach the probe.
- [ ] **Step 5: Write `docs/superpowers/reviews/2026-08-06-tenant-impersonation-grant-lifecycle-verdict.md` and `docs/superpowers/reviews/2026-08-06-tenant-impersonation-token-middleware-verdict.md`.** Include test evidence and an adversarial check of tenant/token identifiers and permission loss.

### Task 6: Write elevation, four-eyes, and hard-block classifier

**Files:**
- Create: `apps/api/tests/Feature/SupportAccess/ImpersonationWriteGuardTest.php`
- Create: `apps/api/app/Modules/SupportAccess/Application/Services/ElevationService.php`
- Create: `apps/api/app/Modules/SupportAccess/Domain/Services/ImpersonationActionClassifier.php`
- Create: `apps/api/app/Modules/SupportAccess/Presentation/Middleware/ImpersonationWriteGuard.php`
- Create: `apps/api/app/Modules/SupportAccess/Presentation/Requests/RequestElevationRequest.php`
- Create: `apps/api/app/Modules/SupportAccess/Presentation/Requests/DecideElevationRequest.php`
- Create: `apps/api/app/Modules/SupportAccess/Presentation/Controllers/AdminElevationController.php`
- Create: `apps/api/app/Modules/SupportAccess/Presentation/Controllers/TenantSessionController.php`
- Modify: `apps/api/config/support_access.php`
- Modify: `apps/api/bootstrap/app.php`

**Produces:** readonly-by-default sessions, partner-approved write elevation, and non-bypassable hard blocks.

- [ ] **Step 1: Write failing tests.** Safe methods pass readonly; unsafe methods return 403 `IMPERSONATION_READ_ONLY`; elevation requires a reason; operator cannot approve; unconfigured/inactive approver cannot approve; configured partner approval changes DB/token mode; exit is the only readonly POST safelist and ends session + revokes grant + deletes token. Even elevated sessions must return 403 `IMPERSONATION_ACTION_BLOCKED` for tenant deletion/deprovisioning, forgot/reset password, secret rotation, every `/fiscal/*` mutation, invoice/credit-note posting or cancellation, POS receipt void/refund, and payment void/refund/reverse.
- [ ] **Step 2: Run red against real test routes and current named production routes.**
- [ ] **Step 3: Implement service/classifier/guard and global ordering after context.** Classifier defaults unsafe methods to `RequiresElevation`; hard-block patterns are explicit and tested; unknown fiscal-controller namespaces fail closed as hard-blocked.
- [ ] **Step 4: Run green and write `docs/superpowers/reviews/2026-08-06-tenant-impersonation-write-guard-verdict.md`.**

### Task 7: Hash-chained, dual-log, fail-closed audit

**Files:**
- Create: `apps/api/tests/Feature/SupportAccess/ImpersonationAuditTest.php`
- Create: `apps/api/tests/Unit/SupportAccess/SessionChainVerifierTest.php`
- Create: `apps/api/app/Modules/SupportAccess/Domain/Services/SessionChainHasher.php`
- Create: `apps/api/app/Modules/SupportAccess/Domain/Services/SessionChainVerifier.php`
- Create: `apps/api/app/Modules/SupportAccess/Application/Services/SessionAuditService.php`
- Create: `apps/api/app/Modules/SupportAccess/Presentation/Middleware/ImpersonationAudit.php`
- Create: `apps/api/app/Modules/SupportAccess/Presentation/Console/VerifyImpersonationAuditCommand.php`
- Create: `apps/api/app/Shared/Contracts/SupportAccess/AdminImpersonationAuditWriter.php`
- Create: `apps/api/app/Shared/Contracts/SupportAccess/TenantImpersonationAuditWriter.php`
- Create: `apps/api/app/Shared/DTOs/SupportAccess/ImpersonationAuditMirrorData.php`
- Modify: `apps/api/app/Services/AdminAuditService.php`
- Modify: `apps/api/app/Models/AdminAuditLog.php`
- Modify: `apps/api/app/Modules/Compliance/Services/AuditService.php`
- Modify: `apps/api/app/Modules/Compliance/Domain/AuditEvent.php`
- Modify: `apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php`
- Modify: `apps/api/bootstrap/app.php`

**Produces:** one authoritative per-session chain mirrored into both audit stores before an action can run, plus automatic domain-event attribution.

- [ ] **Step 1: Write the verifier red test.** Hand-derive two canonical hashes. Assert a valid chain verifies; changing method/path/operator/session/details/sequence/previous hash makes verification return a failure result naming the first bad sequence; head mismatch fails.
- [ ] **Step 2: Run red, implement canonical serializer/hasher/verifier, run green.** Never hash localized text, raw secrets, bearer tokens, or mutable model serialization.
- [ ] **Step 3: Write the HTTP red test.** An impersonated GET and elevated allowed write must each create a session event plus central and tenant mirrors with identical event id/sequence/previous hash/hash and both identity columns. Force central or tenant mirror persistence to throw and assert the probe controller is not called and 503 `IMPERSONATION_AUDIT_UNAVAILABLE` is returned.
- [ ] **Step 4: Implement locked append under a central transaction, then mirrors in fail-closed order before `$next`.** `AdminAuditService` implements the admin mirror port. `AuditService` implements the tenant mirror port through an explicit `recordImpersonationAccess(ImpersonationAuditMirrorData $event)` path that sets tenant id directly and permits nullable company id, so `/auth/me` and other company-less routes remain auditable. Context-thread `AdminAuditService`, `AuditService`, and `DomainEventSubscriber`; old non-impersonated calls retain null columns.
- [ ] **Step 5: Add and test `support-access:audit-verify --session=<uuid>`.** Exit non-zero on chain or mirror mismatch.
- [ ] **Step 6: Run both tests green, then mutate an in-memory persisted snapshot and prove the tamper test fails verification.**
- [ ] **Step 7: Write `docs/superpowers/reviews/2026-08-06-tenant-impersonation-audit-verdict.md`.**

### Task 8: Sanitized queries, `/me` banner context, notifications, and masking

**Files:**
- Create: `apps/api/tests/Feature/SupportAccess/SupportAccessQueryTest.php`
- Create: `apps/api/tests/Feature/SupportAccess/ImpersonationResponseMaskingTest.php`
- Create: `apps/api/app/Modules/SupportAccess/Application/DTOs/SessionData.php`
- Create: `apps/api/app/Modules/SupportAccess/Application/DTOs/SupportAccessLogEntryData.php`
- Create: `apps/api/app/Modules/SupportAccess/Application/DTOs/SupportAccessOverviewData.php`
- Create: `apps/api/app/Modules/SupportAccess/Application/Services/SupportAccessQueryService.php`
- Create: `apps/api/app/Modules/SupportAccess/Domain/Services/SensitiveResponseMasker.php`
- Create: `apps/api/app/Modules/SupportAccess/Presentation/Middleware/ImpersonationResponseMasking.php`
- Create: `apps/api/app/Modules/SupportAccess/Presentation/Controllers/AdminSupportAccessQueryController.php`
- Create: `apps/api/app/Modules/SupportAccess/Presentation/Controllers/TenantSupportAccessQueryController.php`
- Modify: `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php`
- Modify: `apps/api/app/Modules/Identity/Application/DTOs/AuthUserData.php`
- Modify: `apps/api/app/Modules/SupportAccess/Presentation/routes.php`

**Produces:** admin work queues, tenant-sanitized history, banner context, and equal-or-stricter response redaction.

- [ ] **Step 1: Write failing query/context tests.** Tenant history derives tenant id from auth and never exposes operator email, IP, user agent, hashes, internal approval notes, or raw details; admin list is central and paginated; `/auth/me` returns subject/purpose/ticket/access level/expiry/remaining seconds/session id only while impersonating.
- [ ] **Step 2: Write failing masking tests.** During impersonation, recursively redact password/token/secret/API-key/CVV/full PAN/full IBAN/national-id/bank-account keys, preserving only permitted last-four values; ordinary users receive unchanged payloads. No generic reveal endpoint is exposed.
- [ ] **Step 3: Implement DTOs/query service/controllers/context mapping/masking middleware and run green.** Register masking after the controller in execution order while keeping context until response transformation completes.
- [ ] **Step 4: Regenerate TypeScript types and run the backend paths again.**

Run: `cd apps/api && php artisan typescript:transform && php artisan test tests/Feature/SupportAccess/SupportAccessQueryTest.php tests/Feature/SupportAccess/ImpersonationResponseMaskingTest.php --no-coverage`

### Task 9: Admin support-access UI

**Files:**
- Create: `apps/web/src/features/support-access/types.ts`
- Create: `apps/web/src/features/support-access/api/adminSupportAccessApi.ts`
- Create: `apps/web/src/features/support-access/hooks/useAdminSupportAccess.ts`
- Create: `apps/web/src/features/support-access/pages/AdminSupportAccessPage.tsx`
- Create: `apps/web/src/features/support-access/components/RequestAccessDialog.tsx`
- Create: `apps/web/src/features/support-access/components/GrantQueue.tsx`
- Create: `apps/web/src/features/support-access/components/ActiveSessionPanel.tsx`
- Create: `apps/web/src/features/support-access/components/ElevationDialog.tsx`
- Create: `apps/web/src/features/support-access/__fixtures__/supportAccess.ts`
- Create: `apps/web/src/features/support-access/__tests__/AdminSupportAccessPage.test.tsx`
- Modify: `apps/web/src/features/admin/components/AdminLayout.tsx`
- Modify: `apps/web/src/routes/index.tsx`
- Modify: `apps/web/src/locales/en/admin.json`
- Modify: `apps/web/src/locales/fr/admin.json`
- Modify: `apps/web/src/locales/ar/admin.json`

- [ ] **Step 1: Write failing UI tests with typed factories.** Prove request fields and validation, grant status/actions, configured approver action, start session stores subject + impersonation token without altering the memory-only admin token, elevation state, expiry warning, and all visible copy resolved through i18n.
- [ ] **Step 2: Run red.**

Run: `pnpm --filter @autoerp/web test -- src/features/support-access/__tests__/AdminSupportAccessPage.test.tsx`

- [ ] **Step 3: Implement API/hooks/page/components with admin query keys, design tokens, pessimistic mutations, and translated nav.** Admin central data does not use `tenantScopedKey`; switching into a tenant clears tenant query cache and writes only the impersonation token to `useAuthStore`.
- [ ] **Step 4: Run green, typecheck, and lint touched web code.**

### Task 10: Tenant settings, history, persistent banner, and exit

**Files:**
- Create: `apps/web/src/features/support-access/api/tenantSupportAccessApi.ts`
- Create: `apps/web/src/features/support-access/hooks/useTenantSupportAccess.ts`
- Create: `apps/web/src/features/support-access/pages/TenantSupportAccessPage.tsx`
- Create: `apps/web/src/features/support-access/components/ImpersonationBanner.tsx`
- Create: `apps/web/src/features/support-access/components/SupportWindowForm.tsx`
- Create: `apps/web/src/features/support-access/components/IncomingRequests.tsx`
- Create: `apps/web/src/features/support-access/components/SupportAccessHistory.tsx`
- Create: `apps/web/src/features/support-access/__tests__/TenantSupportAccessPage.test.tsx`
- Create: `apps/web/src/features/support-access/__tests__/ImpersonationBanner.test.tsx`
- Create: `apps/web/src/features/support-access/__tests__/TenantSupportAccessQueryKeys.test.tsx`
- Modify: `apps/web/src/features/auth/AuthProvider.tsx`
- Modify: `apps/web/src/stores/authStore.ts`
- Modify: `apps/web/src/components/layout/Layout.tsx`
- Modify: `apps/web/src/hooks/usePermissions.ts`
- Modify: `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`
- Modify: `apps/web/src/routes/index.tsx`
- Create: `apps/web/src/locales/en/support-access.json`
- Create: `apps/web/src/locales/fr/support-access.json`
- Create: `apps/web/src/locales/ar/support-access.json`
- Modify: `apps/web/src/lib/i18n.ts`

- [ ] **Step 1: Write failing tenant page tests.** `RequirePermission permission="support-access.view"` gates the route; manage actions require `support-access.manage`; pre-grant, approve/reject/revoke, active-session indicator, and sanitized history render; every tenant query key uses `tenantScopedKey`.
- [ ] **Step 2: Write failing banner tests.** Banner is present on every tenant layout while context exists, has no dismiss control, shows subject/purpose/ticket/read-write state and a ticking remaining time, warns at five minutes, and Exit calls the session-end endpoint, clears tenant state/query cache, and navigates to `/admin/support-access` while the admin token remains in memory.
- [ ] **Step 3: Implement components/hooks/store/context mapping/translations using only design tokens and logical RTL-safe layout.**
- [ ] **Step 4: Run targeted Vitest, typecheck, lint, and `npx react-doctor@latest --verbose --diff`; fix any regression.**

### Task 11: Milestone consolidation, live E2E, final security review, and handback

**Files:**
- Create: `apps/api/tests/Feature/SupportAccess/SupportAccessEndToEndTest.php`
- Create: `docs/superpowers/reviews/2026-08-06-tenant-impersonation-final-security-verdict.md`
- Create: `docs/handoff/2026-08-06-tenant-impersonation-support-access-handback.md`

- [ ] **Step 1: Write the end-to-end test first.** Against a real PostgreSQL central DB and freshly migrated tenant DB: create operator + configured partner + tenant admin + subject; request; tenant approve; second approve when sensitive; start; call `/me`; perform one allowed read; request/approve elevation; perform one allowed non-fiscal write probe; revoke; prove the next request is rejected; verify chain and both mirrors.
- [ ] **Step 2: Run red before the final wiring fix, then green by path.**
- [ ] **Step 3: Run all SupportAccess PHPUnit paths only, the existing identity/audit regression paths, PHPStan level 8, Pint, frontend typecheck/lint/test, React Doctor diff, and `./scripts/preflight.sh`.** A failed gate is fixed, not waived. If preflight invokes the repository-wide PHPUnit suite internally, record that it is the mandated preflight wrapper; direct PHPUnit invocations remain path-only.
- [ ] **Step 4: Run the same grant→impersonate→act→revoke→audit-verify flow against the live local tenant database and capture commands/results in the handback.**
- [ ] **Step 5: Adversarially review the whole branch for tenancy/authz, token claim parsing, stale permissions, grant/session races, write-guard coverage, dual-log failure ordering, chain concurrency/tampering, sensitive response leakage, and frontend credential handling.** Write an APPROVE/REJECT verdict; fix every BLOCKER/IMPORTANT issue and rerun affected gates before APPROVE.
- [ ] **Step 6: Complete handback.** List central/tenant migrations and tables, `support-access.view/manage` seeding and mandatory `php artisan permission:cache-reset`, partner approver environment values, no new queue/Horizon entry (database notifications are synchronous), the Tunisia E-4 prohibition, reveal-in-full remaining disabled, deployment order, test evidence, and any unfixed non-blocking discovery.
- [ ] **Step 7: Verify clean status, inspect the complete diff, and commit with repository-format messages without pushing or merging `dev`.**

## Plan self-review

- **Spec coverage:** per-incident and pre-grant consent, sensitive approval, readonly/write elevation, 60-minute TTL, live grant kill switch, subject token, tenant claim, permission intersection, hard blocks, persistent banner, tenant notification/history, masking, dual identity audit, hash chain, and Tunisia/break-glass rulings each map to a task.
- **Placeholder scan:** no implementation step delegates an unspecified behavior; endpoint state transitions, error codes, middleware ordering, schemas, and verification commands are named.
- **Type consistency:** shared context/token ports are defined once above and consumed by named services; grant/session/elevation/event enums correspond one-to-one with schema casts; generated frontend types originate from PHP DTOs.
