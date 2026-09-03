# Handover — Request hygiene Phase A, Task 1: tenant-scoped Spatie permission cache (S-1)

**Dispatch:** owner, via Codex Desktop. **Lane:** `lane/rh-t1-permission-cache`. **Reviewer gate:** `tenancy-authz-reviewer` (orchestrator runs it). **Do not promote on a test day.**

## What you are fixing

Spatie's `PermissionRegistrar` obtains its cache repository through `CacheManager::store()`, a real method that Stancl's `__call`-based tenant tagging never intercepts. Under database-per-tenant, every tenant has its own `permissions` table but they all share the key `spatie.permission.cache`, so tenant A's registry can be served to tenant B and any role edit anywhere flushes everyone. Known since 2026-07-03 (memory `project_spatie_permission_cache_tenant_blind`), never fixed. Audit evidence: `docs/superpowers/audits/2026-09-02-request-hygiene/05-synthesis.md` finding S-1; gate confirmation in `docs/superpowers/reviews/2026-09-03-request-hygiene-phase-a-plan-gate-r4.md` (Task 1 resolved for the production topology).

## The brief

The full task, with failing tests, code, verification commands and commit message, is **Task 1 in `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md`** (revision 6, line ~60 onward). Execute it step by step in checkbox order. It is scoped to database-per-tenant mode only; compatibility mode keeps the shared key by design and the plan includes a test asserting that.

## Environment rules (non-negotiable)

1. `git worktree add .worktrees/rh-t1 -b lane/rh-t1-permission-cache dev` from `apps/erp`. Never edit in the shared `dev` checkout. No `git stash`.
2. Backend deps in the worktree: `cp -R ../../apps/api/vendor apps/api/vendor && cd apps/api && composer dump-autoload` (a symlinked vendor autoloads the main checkout's classes).
3. PHPUnit **by path only**, never the full suite. PG-lane tests (the two `ProvisionsTenantDatabases` tests in the task) need a per-session database: export `DB_DATABASE=autoerp_test_t1 DB_CENTRAL_DATABASE=autoerp_test_t1` (create it if missing) so you never touch the shared default. Announce the PG leg in the handback.
4. PHPStan level 8 on every touched file: `./vendor/bin/phpstan analyse app/Modules/Identity/Application/Listeners app/Providers/TenancyServiceProvider.php`.
5. Constructor injection only, strict types, no `mixed`.
6. Path-scoped commits only, the commit message from the plan, ending with the attribution trailer already used in this repo's recent commits.

## Deliverable (handback)

Write `docs/handoff/HANDBACK-request-hygiene-T1-2026-09-03.md` with: branch + commit hash; each plan step ticked with the command run and its output tail (red run, green run, PHPStan); the queue-lifecycle test output showing both tenants' keys and the central key afterwards; anything you deviated from and why; the deploy note (`php artisan permission:cache-reset` once after the batch deploys). Then stop. The orchestrator runs the reviewer gate and merges.

## Out of scope

Anything in compatibility mode beyond the by-design test; rate limiting (B-1); any other task in the plan.
