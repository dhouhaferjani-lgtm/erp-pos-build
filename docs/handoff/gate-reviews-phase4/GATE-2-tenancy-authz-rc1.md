# Treasury Phase 4 — Gate 2 RC1 — tenancy/authz/isolation lane

- Scope: Wave 2 / Tasks 7–12 tenancy, authorization, and isolation
- Diff reviewed: `phase4-gate-1..phase4-gate-2-rc1`
- Reviewed HEAD: `d1a37439e`
- Model tier: Opus (`claude-opus-4-8`)

## Verdict

**APPROVE — no BLOCKER, HIGH, or MEDIUM findings and no money-path authorization break.**

## Verified contracts

- All seven recurrence routes carry the exact `can:expense-recurrences.{view|create|update|delete}` middleware. Pause and resume require update permission, and the route group enforces the token tenant claim.
- One controller `scopedQuery()` filters both `tenant_id` and `company_id`; `findScoped()->firstOrFail()` makes show, update, delete, pause, and resume fail as 404 for out-of-scope IDs without an IDOR oracle.
- All four foreign IDs use tenant-and-company-scoped existence validation derived from trusted context, never payload. The sibling-company FK rejection path is tested.
- The generation command iterates tenant then company, and template/document/metadata queries are bounded by both IDs. `ExpenseService::create()` receives explicit `company_id` and does not read `CompanyContext`.
- The fallback actor is active, same-tenant, and an admin; primary author lookup is tenant-scoped.
- Spatie team ID is set only to tenant ID, is restored in `finally`, and permission caches are reset around the temporary scope.
- Notification recipients require exact `expenses.post` permission and active membership in the owning company. Suspended and sibling-company memberships are excluded by tests.
- Forecast reads are company-scoped, and the HTTP caller supplies the required current-company ID.
- Backend seeder grants, frontend permission maps, and spec §8.5 match: manager/accountant receive full recurrence access and expense export; cashier/operator/viewer are recurrence view-only; admin has all permissions.
- The frontend recurrence route precedes the dynamic expense ID route. Query keys carry tenant/company suffixes, and deleted-detail invalidation matches ID, tenant, and company exactly.

## Non-blocking observations

1. **LOW:** Primary author lookup is tenant-scoped but does not additionally require active status; the fallback lookup does. This affects a same-tenant audit stamp, not isolation or money.
2. **LOW:** `['upcoming-payments']` invalidation is a bare prefix and can over-refetch cached company variants within one client. It does not expose data across tenants.
3. **INFO:** There is no dedicated notification-recipient case for an active company member lacking the permission, though the recipient service predicate and existing negative cases establish the behavior.
4. **INFO:** There is no dedicated Wave 2 sibling-company forecast leak test. Every forecast query is explicitly company-scoped; the binding explicit analytics/export leak tests belong to Wave 3.

## Review limitation

The reviewer completed its code-level audit but its sandbox required an unavailable permission grant to write this artifact. The controller persisted the emitted review without changing its substance.

VERDICT: APPROVE
