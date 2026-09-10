# RBAC Programme Execution Plan — waves, entry conditions, and when each may run

> **For orchestrators:** this is the master sequencing document for the accepted roles &
> permissions design. It does **not** dispatch work. Each wave gets its own fully
> dispatchable plan file; wave 0a's is
> [`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md`](2026-09-10-rbac-wave-0a.md).
> This document answers: *what is in each wave, what must have merged first, what
> proves it done, who gates it, what the deploy does, and — today — which wave may
> start right now.*

**Goal:** Take the ungated-route, catalogue-drift and principal-model defects the
2026-09-09 audit found from their current state (326 uncovered routes, a
destructive seeder as the only catalogue writer, no machine principals) to a
code-owned permission catalogue with a shrinking enforcement ratchet, without ever
running more than two RBAC lanes at once and without colliding with the two
in-flight non-RBAC lanes.

**Architecture:** Permissions stay Spatie-backed and tenant-scoped (owner ruling
D1); company and location scope stay on `user_company_memberships`. Enforcement
moves to route middleware as the single action gate (spec §4.4.1 E-1..E-4), proven
by a live-router ratchet with two shrink-only ceilings. The catalogue becomes code
(`PermissionRegistry` + per-module manifests) with an additive template-delta sync
(D2), and principals gain a `principal_kind` so an MCP/API token can narrow — never
widen — its owner's permissions.

**Tech Stack:** Laravel 12 / PHP 8.4 / Spatie laravel-permission 6.25 / Stancl
Tenancy 3.10 / PHPUnit 11 / PHPStan L8 / Pint / React 19 / TanStack Query 5 / Vitest.

**Spec:** [`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md`](../specs/2026-09-10-roles-permissions-catalogue-design.md)
(rev 9, **ACCEPTED** — Codex spec gate r9, 0 BLOCKER / 0 MAJOR / 3 minor editorial,
register at [`docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r9.md`](../reviews/2026-09-10-rbac-spec-codex-gate-r9.md)).

**Measured at:** `dev` = `d64db9a0e`, 2026-09-10. Every lane SHA and every overlap
claim below was re-run at that tip; none is carried forward from the spec.

---

## 1. Standing rules for every RBAC lane

These are not per-wave choices. A lane that breaks one of them is not mergeable.

- [ ] **One worktree per lane.** `git worktree add .worktrees/<lane> -b lane/<lane> dev`
      from the shared checkout. Never edit or commit in the shared `dev` worktree —
      parallel sessions mutate branch refs between commands (CLAUDE.md rule 21).
- [ ] **Laptop lane cap is 3 concurrent lanes** (owner, 2026-09-09). No RBAC wave
      below plans more than two lanes at once, leaving one slot for non-RBAC work.
- [ ] **PostgreSQL legs use a private per-session database** `autoerp_test_<letter>`,
      setting **both** `DB_DATABASE` and `DB_CENTRAL_DATABASE`. Never the shared
      default. Never two PG legs in parallel on this laptop.
- [ ] **PHPUnit by path only.** The full backend suite is never run locally (laptop
      rule). A whole-suite gate runs on CI, on the lane branch, and is named in the
      wave's exit checklist when it is required.
- [ ] **Path-scoped commits.** `git add <explicit paths>` — never `git add -A`,
      never `git commit -a`. Every commit carries the two trailers
      (`Co-Authored-By:` and `Claude-Session:`).
- [ ] **No `origin` push without an explicit owner go-ahead: pushing `origin/dev`
      auto-deploys staging, including `tenants:migrate`.** Waves merge into **local**
      `dev` and are promoted in verified fast-forward batches.
- [ ] **Constructor injection only** (`private readonly`), strict types, no `mixed`,
      enums for every status/type column, PHPStan level 8 clean on touched paths.
- [ ] **Empirical evidence, not compilation** (owner rule, 2026-08-31). Every wave's
      exit checklist below names a **UI happy path** driven in the real app, with
      **5xx capture and browser-console capture on every probe**. "Tests pass" is
      never the evidence for a wave that changes what a live page can call.
- [ ] **Reviewer gates never auto-merge.** `tenancy-authz-reviewer` is **mandatory
      on every wave**. A gate returning anything but MERGE means a fix round, not a
      merge with notes.

---

## 2. Wave table

### Wave 0a — enforcement hardening (start-now subset)

| Field | Value |
|---|---|
| **Goal** | Stop the bleeding with permissions that already exist: mark the seven genuinely self-service routes, install the route-coverage ratchet with its baseline, and close 15 ungated writes + 6 ungated reads using only seeded keys. No migration, no new permission key, no seeder edit. |
| **Scope items (spec §8)** | `0a-1` land S-1 tenant-scoped permission cache — **DONE**, merged into local `dev` at `6415062b9` (lane `lane/rbac-w0a-s1-permission-cache`, tip `05455fa41`; gates r1/r2 at `docs/superpowers/reviews/2026-09-10-rbac-w0a-s1-gate-r{1,2}.md`). `0a-2` route-coverage ratchet + baseline + liveness fixture. `0a-3` `authz.self` middleware, alias and the two allow-list/shape tests. `0a-4` gate the four Identity read routes. `0a-5` gate Promotion ×4, Uom ×5, Menu ×3, Coupon ×3 writes + the two coupon reads. `0a-8` docs. Plus the S-1 follow-up minor (entrypoint per-tenant reset reports its outcome). |
| **Entry condition** | **None.** Every 0a path was re-measured on 2026-09-10 against `lane/w-lot-a-1a` (`5d847b2ba`) and `lane/t2-receipt-spine` (`d7123fa30`) with `git diff --name-only dev...<lane> -- <path>`: **every path returns empty for both lanes.** The spec's single accepted overlap — `apps/api/tests/feature-lane-manifest.json` — is **no longer an overlap**, because 0a-1 already merged (it consumed the `Identity.classes` 32→33 and `gated_ceiling` 1253→1254 raise) and every remaining 0a test lives under `tests/Architecture/`, which the feature-lane manifest does not govern (`apps/api/tools/feature-lane-manifest-check.php:205,328-334`). Wave 0a is now **fully disjoint**. |
| **Exit checklist** | ① Every task's named PHPUnit path green, red captured first with the exact failing assertion pasted into the handback. ② `php tools/feature-lane-manifest-check.php` green with **no** ceiling change (0a adds no `tests/Feature` class). ③ PHPStan L8 + Pint clean on touched paths. ④ **UI happy path, real app, console + network captured:** log in as `admin` → Settings → Roles (`GET /roles`, `GET /permissions` still 200) → Settings → Users (`GET /users`, `GET /roles`) → Promotions list, archive a promotion → UoM units create/edit/delete → Menus delete a category item → Coupons list + revoke. ⑤ **Denial probe, same capture:** log in as `manager` → Settings → Users loads with **no 403 in the network log** (T3b's guard) → Promotions archive still works (manager holds `promotions.manage`) → Coupons list still works. ⑥ Zero 5xx and zero new console errors across both probes. ⑦ Handback written at `docs/handoff/HANDBACK-rbac-w0a-2026-09-10.md`. |
| **Reviewer gates** | `tenancy-authz-reviewer` (**mandatory**) on the whole diff. `frontend-conventions-reviewer` (**mandatory**, because T3b touches `apps/web/src/features/settings/UsersPage.tsx`). No inventory/treasury/fiscal reviewer — 0a touches none of those modules. |
| **Deploy steps** | **None new.** 0a-1 already requires `permission:cache-reset` at boot and that is already wired (`apps/api/docker/entrypoint.sh:190-191`); the follow-up task only makes its per-tenant arm report its outcome. No permission row is added, so no tenant needs a catalogue run. |
| **Rollback point** | Each task is one self-contained commit; `git revert` of the task commit restores prior behaviour. The ratchet baseline reverts with its test (they are added in the same commit and every gating commit deletes its own baseline entries). |

**Named residual carried out of 0a (not a defect of the wave, a consequence of its
scoping):** the three new `tests/Architecture/` classes execute on **no CI event**
until a step in the `backend-architecture` job names them. That job runs named files
only — never the `tests/Architecture` directory (`.github/workflows/ci.yml:202,215,228`,
with the reason stated at `:223-227`). `.github/workflows/ci.yml` is edited by **both**
in-flight lanes, which is why the spec already moved 0a-2's protected-blob `env:`
line to **0b-11**. The CI *step* registration must ride in that same 0b-11 commit.
Until then the ratchet is real locally and in every lane's own run, and dark in CI —
recorded here, in the 0a plan's residual list, and in the 0b entry below so it cannot
be forgotten.

### Wave 0b — new permissions for the remaining ungated writes

| Field | Value |
|---|---|
| **Goal** | Declare and gate the ungated writes whose permission does **not** exist yet, plus the four items wave 0a had to defer for file-overlap reasons, and ship the one shared fleet runner. |
| **Scope items (spec §8)** | `0b-1` `credit-notes.cancel` (first). `0b-2..4` `services.*` (3), `service-categories.*` (3), `channels.*` (4), `categories.*` (4), `progression.*` (2), `purchase-hub.orders.create`, `companies.create`. `0b-5` the two `BatchExpiry` writes, gated **beside** the lane's `BatchActionAccess`, not instead of it. `0b-6` delete `PermissionSeeder.php` + its `ProductionSeeder.php:73-75` caller. `0b-7` fix `RefundResidualTenantIsolationTest:136-142` **in 0b-1's commit**. `0b-8` glossary rows on top of the lane's General-manager row. `0b-9` `permissions:ensure` + `permissions:ensure-fleet` + `TenantFleetRunner` + `LegacyRoleBaseline` (both predicates) + `PermissionWriteLock`. `0b-11` the `ROUTE_COVERAGE_BASELINE_PROTECTED_BLOB` `env:` line **and the `backend-architecture` step that names 0a's three Architecture classes**. `0b-12` `RequireAnyPermission` `error.details`. `0b-13` the counting-item gate. `0b-14` wire the lock into every runtime role-grant writer. |
| **Entry condition** | **`lane/w-lot-a-1a` merged into local `dev`.** Reason, by file: 0b edits `apps/api/database/seeders/RolesAndPermissionsSeeder.php` (`permissionNames()` and `rolePermissionGrants()`) — the lane rewrites that file and introduces a marked-tenant preservation branch in it; 0b-5 edits `apps/api/app/Modules/BatchExpiry/Presentation/routes.php` — the lane rewrites it wholesale and adds `BatchActionAccess`; 0b-8 edits `docs/glossary.md` — the lane adds the General-manager row and the sole-writer sentence there; 0b-11 edits `.github/workflows/ci.yml` — **both** lanes edit it; 0b-14 edits `RoleController.php` and `UserController.php` — the lane moves both. **Additionally: 0b-13 edits `apps/api/app/Modules/Inventory/Presentation/routes.php` and 0b-12 edits `apps/api/app/Http/Middleware/RequireAnyPermission.php` — both are in `lane/t2-receipt-spine`'s diff**, so 0b-12 and 0b-13 wait for **T2** as well; the rest of 0b needs only W-LOT. Split 0b into `0b-main` (W-LOT gated) and `0b-t2` (T2 gated) if T2 lands late. |
| **Exit checklist** | ① All named PHPUnit paths green by path, red captured first. ② `EnsurePermissionsFleetTest` and `TenantFleetRunnerSelectorTest` green — the latter **using a reachable cross-column collision** (a selector equal to tenant A's UUID and tenant B's slug), never two equal slugs, because `tenants.slug` is globally unique (`apps/api/database/migrations/2025_11_30_000001_create_tenants_table.php:16-20`); the snapshot primitive is `get(['id', 'slug'])`, not `pluck('id','slug')` (gate r9 m-1). ③ Convention-09 rows for this wave: `ServicePermissionsSecondCompanyTest`, `CategoryPermissionsSecondCompanyTest`, `StockAdjustmentLocationScopePermissionTest`, and `permissions:ensure` run twice reporting `created=0 granted=0` with zero row writes. ④ Route-coverage ceilings shrink (152 → ~127 writes) and no baseline entry is left stale. ⑤ **UI happy path with console + 5xx capture:** a Services CRUD round trip as `manager`, a Categories create/reorder, a Channels list, a company create refused for a non-admin, a batch delete as `admin`. ⑥ `tenant:census-day-one` clean. |
| **Reviewer gates** | `tenancy-authz-reviewer` (**mandatory**). `frontend-conventions-reviewer` (the regenerated `permissionsMap.generated.ts`, the label JSONs). `inventory-costing-reviewer` for 0b-13 and 0b-5 (a batch delete and a counting-item write). |
| **Deploy steps** | 1. `php artisan permissions:ensure-fleet --keys=… --grant-to=…` — additive, marker-safe, idempotent; **never** `tenants:seed RolesAndPermissionsSeeder` (on a marked tenant with `LOT_ACTION_PERMISSIONS_ENFORCE=false`, the shipped default, that seeder takes its preservation branch and writes nothing, so the 0b keys would never reach a tenant whose routes are already gated on them → 403 for everyone including `admin`). 2. `php artisan permission:cache-reset`. 3. `php artisan permissions:export-frontend-map`. **Health check:** the run exits zero **and** `/var/run/autoerp/permissions-ensure.status` reads `permissions_ensure=ok`; if not, grep the container log for the `PERMISSIONS-ENSURE-FLEET` aggregate line, which names each failed/blocked tenant id with its reason, and re-run with `--tenants=…` for those. |
| **Rollback point** | Revert the wave's commits. Added `permissions` rows are inert once no route references them; `permissions:prune-orphans` is **not** run as part of a rollback. |

### Wave 1 — catalogue as code

| Field | Value |
|---|---|
| **Goal** | Replace the seeder array with `PermissionRegistry` + per-module manifests, and make deploys apply an additive template delta that provably never touches a custom or customised role. |
| **Scope items (spec §8)** | `PermissionVerb` (32 cases), `PermissionDefinition`, `PermissionManifest`, `PermissionRegistry`, ~30 module manifests, `PermissionRenameMap`, `RoleMarkerRepository`, `PermissionSyncService::sync()`, `TemplateDeltaApplier`, the `roles` migration (§5.2) with the `customised_at` writer **in the same wave as its column**, `permissions:sync`, `permissions:sync-fleet`, `permissions:scaffold`, `permissions:export-label-skeleton`, the adoption pass, `RoleSyncedV1`, the entrypoint change with both status arms, the `permissions_sync` health check, the seeder shim, and the deletion of `EnsurePermissions.php` in the same commit that lands `permissions:sync`. Static guards `ForbidPermissionStringLiteral` and `ForbidRoleNameAuthorization` (shrink-only baseline + ESLint companion). |
| **Entry condition** | **Waves 0a and 0b merged, AND `lane/w-lot-a-1a`'s five-push staging protocol complete — not merely its merge.** Reason: that protocol asserts pre/post role-permission snapshots on staging boots; a registry-driven sync running between the snapshots invalidates the comparison. File reason for the merge half: wave 1 **owns** `apps/api/database/seeders/RolesAndPermissionsSeeder.php` end to end and cannot run concurrently with any lane that edits it. |
| **Exit checklist** | ① `PermissionsSyncTest::sync_creates_every_registry_permission_in_a_fresh_tenant_and_in_a_second_tenant` — **two physical tenant databases**, asserting data meaning (tenant B's `admin` holds exactly `activeKeys()`; tenant B's customised `manager` skipped independently of tenant A's). ② `permissions:sync` run twice → `ALREADY_CURRENT`, every `mutations` counter zero, **zero row writes asserted by query-log count**, `orphans` / `templates_skipped_customised` gauges asserted *unchanged* (not zero). ③ Sync on a tenant the W-LOT delta already ran against → no change to the two W-LOT keys, no change to `general_manager`'s grants, **no write to `provisioning_source`**. ④ `PermissionDeploySequenceTest` green — and it consumes a **versioned machine-readable runbook** at `apps/api/tests/Architecture/fixtures/permission-deploy-runbook.yaml`, **not** parsed Markdown prose (gate r9 m-3: prose parsing breaks the build on a wording change and can be evaded by equivalent prose). The wave rows in spec §8 and the deploy-runbook rows are validated *against* that YAML; the YAML is the authority. ⑤ `SyncPermissionsFleetTest::a_dry_run_that_finds_a_collision_writes_nothing_and_exits_non_zero`. ⑥ `TenantFleetRunnerSelectorTest` extended to `permissions:sync-fleet` with the same cross-column collision fixture as 0b. ⑦ **One-week staging soak with `SYNC_PERMISSIONS_ON_BOOT=true`**, comparing each tenant's role-permission snapshot before and after every deploy, requiring **zero** unintended diffs. ⑧ UI happy path: Roles page renders grouped by registry module with the template-version banner; *Re-apply template* on a pristine system role is a visible no-op; console + 5xx captured. |
| **Reviewer gates** | `tenancy-authz-reviewer` (**mandatory**). `frontend-conventions-reviewer` (the Roles page, generated types, label bundles). |
| **Deploy steps** | 1. `php artisan tenants:migrate-rolling` (the `roles` columns). 2. **`php artisan permissions:sync-fleet`** — never `tenants:run permissions:sync`, never the per-tenant `permissions:sync`; both are refused by `PermissionDeploySequenceTest`, and there is no `--force`. 3. `php artisan permission:cache-reset`. 4. `php artisan permissions:export-frontend-map`. **Health check:** `GET /api/v1/admin/monitoring/health` reports `permissions_sync.healthy = true` (the authenticated super-admin endpoint at `apps/api/routes/api.php:83`; the public `/v1/health` is unchanged). If false, grep the container log for the `PERMISSIONS-SYNC-FLEET` aggregate line and re-run `--tenants=…` for the named tenants. |
| **Rollback point** | The migration's `down()` drops the two partial uniques, three CHECKs and four columns, **refusing while any row has `template_key IS NOT NULL`**. The seeder shim reverts to the array **in the same revert commit**. `permissions:sync` is additive, so a rollback leaves extra inert permission rows. |

### Wave 2a — role management hardening

| Field | Value |
|---|---|
| **Goal** | Make role management safe to operate: no tenant can lose its last admin, role reads are gated **and shaped**, every role mutation and denial lands on the audit chain, and the eight role-name authorization sites become permission checks. |
| **Scope items (spec §8)** | `roles.is_system` consumption; the single `LastAdminFloor` service wired into all nine writers of §4.6.3 plus `LastAdminFloorWriterCensusTest`; F-1; **the D4 payload shaping of §4.6.4** (this is where `GET /users/{userId}/roles` stops returning the permission matrix to a bare `users.assign-roles` holder — wave 0a only *gates* those routes); `RoleCreated/RoleUpdated/RoleDeleted` on `DomainEvent`; `(principal_id, token_id)` attribution; denial events on `audit_events` with the two-key Redis dedup; `EffectivePermissionResolver` with `forSubject`/`forTarget`; `GET /users/{id}/effective-permissions[?token_id=]`; the effective-permissions panel; `RolesPage` grouped by registry module; the FE deletions of §4.5.4 (`uiAliasPermissions.ts` and the fail-open fallback); **and the conversion of the eight role-name sites** — `CreateDraftCountingRequest.php:35`, `InventoryCountingController.php:782,866,905,975,1297,1494`, `DiscountPermissionResolver.php:39`. |
| **Entry condition** | **Wave 1 merged and soaked.** |
| **Exit checklist** | ① `LastAdminFloorTest` with **two companies in one tenant**, asserting the floor is per *tenant*: removing the admin role from the only admin is refused even though company B has its own owner. ② The nine-writer census test enumerates every writer by name. ③ Denial dedup proven against a **real Redis**, not a fake. ④ Shaping asserted on **rows**: a `users.assign-roles`-only caller gets `{user_id, roles:[{id,name,is_system}]}` with the `permissions` array **absent**; a `roles.view` caller gets it. ⑤ `ScopedTokenIssuanceEntryConditionTest` green — this is what unlocks 2b. ⑥ UI happy path: role create/edit/delete as `admin` with the audit rows asserted; a manager's Users page unchanged; console + 5xx captured. |
| **Reviewer gates** | `tenancy-authz-reviewer` (**mandatory**), `frontend-conventions-reviewer`, plus **`inventory-costing-reviewer`** for the seven Inventory counting conversions and **`fiscal-pos-reviewer`** for `DiscountPermissionResolver`. |
| **Deploy steps** | `tenants:migrate-rolling`, then `php artisan permissions:sync-fleet`, then `permission:cache-reset`, then `permissions:export-frontend-map`. Same health check as wave 1. |
| **Rollback point** | Revert; no destructive migration in 2a. |

### Wave 2b — principals and tokens

| Field | Value |
|---|---|
| **Goal** | Make a service account a first-class principal whose MCP/API token can only ever narrow its owner's permissions. |
| **Scope items (spec §8)** | `PrincipalKind`; the `users` migration (§5.1 — nullable `password`, the service CHECK); the `principal_kind` census edits across every writer in §4.1.1; six `service-accounts.*` permissions and their routes; `CreateServiceAccountRequest` + `ServiceAccountProvisioningService` + `PUT /service-accounts/{id}/memberships`; the seat exclusion across **all six** counters plus `max_service_accounts`; `EnforceTokenScope` on the global `api` group with its coverage and no-op tests; `tokenScopePermissionNames()`; the `tenant:` claim at issuance; `TokenScopeData` on `/auth/me`; the `X-Company-Id` contract; `MembershipRevocationService` + the queued `RevokePrincipalTokens` retry; FE service-account and token screens. **Also: the `PrincipalKind::Service` arm of `AllowSelfService`** — wave 0a ships the middleware without it, because `principal_kind` does not exist until this wave. |
| **Entry condition** | **Wave 2a merged**, *and* `ScopedTokenIssuanceEntryConditionTest` green — no scoped token may be issuable while a role-name idiom can bypass the narrowing. `service-accounts.issue-token` stays unrouted until then. |
| **Exit checklist** | ① `ServiceAccountLocationScopeTest` — a service token whose principal's membership carries `allowed_location_ids` is refused a write at another location. ② Issuing the same token twice creates two distinct tokens; revoking a membership twice is idempotent. ③ `EnforceTokenScopeNoOpTest` proves an unscoped human token's behaviour is byte-identical to today. ④ A real MCP-shaped probe: issue a scoped token, call `/auth/me`, assert `token_scope`, then assert a permission the owner holds but the token excludes is refused **403**, not 500. ⑤ UI happy path on the service-account screens with console + 5xx captured. |
| **Reviewer gates** | `tenancy-authz-reviewer` (**mandatory**), `frontend-conventions-reviewer`. |
| **Deploy steps** | `tenants:migrate-rolling`, `permissions:sync-fleet`, `permission:cache-reset`, `permissions:export-frontend-map`. |
| **Rollback point** | The `users` migration `down()` is **four ordered operations and destroys data**: (1) refuse while any `personal_access_tokens` row belongs to a `principal_kind='service'` principal; (2) delete every service principal and its memberships/role rows, writing an `AuditEvent` per deletion first; (3) drop the CHECK then the three columns; (4) restore `password NOT NULL` — safe only because (2) ran. Rehearse this on staging before wave 2b promotes. |

### Wave 3 — consistency sweep

| Field | Value |
|---|---|
| **Goal** | One enforcement style everywhere; empty the exception lists. |
| **Scope items (spec §8)** | Move the 130 in-controller checks and the 46 FormRequest-only gates to route middleware, deleting baseline entries as it goes; empty the PHPStan ignore list; retire `LotActionPermissionDelta` as dead code; reconcile `SystemRoleName` with `SystemRoleTemplate`; delete the seeder static shims and point the exporter at the registry; POS legacy ladder removal and dead-`pos.*` pruning; `MembershipRole` reconciliation; `permissions:prune-orphans --confirm`; the `marketplace.admin` re-scoping ticket; the **per-role regression matrix** (role × action → 200/403, exercising **deny** paths, which today's `RBACTest` never does — it acts as `admin` throughout, so it proves an allow path only). Also: the two wave-3 tickets already filed — `docs/superpowers/tickets/2026-09-10-batch-writes-ignore-location-scope.md` and `docs/superpowers/tickets/2026-09-10-marketplace-admin-platform-scope.md`. |
| **Entry condition** | **Wave 2 merged.** |
| **Exit checklist** | Both ceilings at their floor for this programme; `permissions:prune-orphans` run twice deletes nothing on the second run; the role × action matrix is green with every deny path asserted; UI happy path across each converted module with console + 5xx captured. |
| **Reviewer gates** | `tenancy-authz-reviewer` (**mandatory**) + the owning-module reviewer for each converted cluster (`inventory-costing-reviewer`, `treasury-reviewer`, `fiscal-pos-reviewer`, `imports-reviewer`). |
| **Deploy steps** | None new. `permissions:prune-orphans --confirm` is a deliberate operator action, never a deploy step. |
| **Rollback point** | Per-module revert; each conversion cluster is its own commit. |

### Wave 4 — edge-case campaign

| Field | Value |
|---|---|
| **Goal** | Drive the §7 edge-case register as a real journey and make a green run a promotion precondition. |
| **Scope items (spec §8)** | The §7 register driven in the real UI on a fresh tenant with **two companies** and a **second `pos_enabled` location**, with 5xx and console capture on every probe, feeding `docs/qa/MANUAL-TESTING-LOOP.md`. |
| **Entry condition** | **Wave 3 merged.** |
| **Exit checklist** | A green campaign run **plus** a clean `tenant:census-day-one` — together the promotion precondition for the whole programme. The campaign is re-run end to end on the same tenant, asserting the second pass creates no duplicate role, permission or membership row and that every counter in `permissions:sync-fleet` reports `ALREADY_CURRENT`. |
| **Reviewer gates** | `tenancy-authz-reviewer` on any fix the campaign produces. |
| **Deploy steps** | None. |
| **Rollback point** | n/a — the campaign produces fixes, each its own revertible lane. |

### Sequencing

```
now ──► 0a ──┬─────────────────────────────────────────────────────► (independent)
             │
lane/w-lot-a-1a merged ──► 0b-main ──┐
lane/t2-receipt-spine merged ──► 0b-t2 ──┴──► 1 ──► 2a ──► 2b ──► 3 ──► 4
                                              ▲
       lane/w-lot-a-1a five-push staging protocol complete ┘
```

`lane/t1-transfers-edge` is already an ancestor of `dev` and blocks nothing. If T-2/T-3
merges **before** wave 1, its two keys (`inventory.transfers.reconcile`,
`inventory.transfers.close`) are declared in `InventoryPermissionManifest` like any
other; if **after**, wave 1's manifest already contains them and T-2/T-3's seeder edit
becomes a manifest edit. Either order works.

---

## 3. When can it run — today's state

Measured 2026-09-10 in the shared checkout `/Users/houssamr/Projects/syneriva/apps/erp`:

| Ref | SHA today | `git diff --shortstat dev...<ref>` | Merged into `dev`? | What it blocks |
|---|---|---|---|---|
| `dev` | `d64db9a0e` | — | — | — |
| `lane/rbac-w0a-s1-permission-cache` | `05455fa41` | — | **YES** — merged at `6415062b9` | nothing; 0a-1 is DONE |
| `lane/t1-transfers-edge` | `86273346a` | **empty** | **YES** — ancestor of `dev` | nothing |
| `lane/w-lot-a-1a` | `5d847b2ba` | 83 files, +4 674, −663 | **NO** | wave **0b-main** and (via its staging protocol) wave **1** |
| `lane/t2-receipt-spine` | `d7123fa30` | 100 files, +13 066, −495 | **NO** | wave **0b-t2** (items 0b-12, 0b-13 only) |

**The lane tips moved again since Codex spec gate r9 read them** (r9 saw
`e66ab5823` / `ede6a990a`; the spec text still pins `f8005243d` / `bd91f8de5`). That
is gate r9's minor m-2 and it is *why* the pins are re-measured here rather than
copied: a no-overlap claim against a moving lane has a shelf life of one measurement.
The functional conclusions are unchanged — re-run today, every wave-0a path is
disjoint from both lanes, and the previously accepted `feature-lane-manifest.json`
overlap is gone because 0a-1 already landed.

### Which wave may start, and under which condition

| Wave | May start | Trigger the orchestrator watches |
|---|---|---|
| **0a** | **NOW.** No entry condition, fully disjoint at today's tips, and the laptop lane cap allows it: one RBAC lane (`lane/rbac-w0a`) alongside the two in-flight lanes is exactly 3. | none — dispatch `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md` |
| **0b-main** | When W-LOT lands. | `git -C /Users/houssamr/Projects/syneriva/apps/erp branch --merged dev \| grep lane/w-lot-a-1a` returns a line |
| **0b-t2** (items 0b-12, 0b-13) | When T2 lands. | `git -C … branch --merged dev \| grep lane/t2-receipt-spine` returns a line |
| **1** | When 0a **and** 0b are merged **and** W-LOT's five-push staging protocol has completed. | the two `branch --merged dev` greps above **plus** the owner's confirmation that the five-push protocol finished — the protocol is a staging fact, not a repo fact, so no grep can prove it |
| **2a** | Wave 1 merged and its one-week staging soak shows zero unintended diffs. | soak evidence in the wave-1 handback |
| **2b** | Wave 2a merged **and** `ScopedTokenIssuanceEntryConditionTest` green. | the test, run by path on the 2a lane |
| **3** | Wave 2 merged. | `branch --merged dev` for both wave-2 lanes |
| **4** | Wave 3 merged. | as above |

**Lane-cap arithmetic while 0a runs:** `lane/w-lot-a-1a` + `lane/t2-receipt-spine` +
`lane/rbac-w0a` = 3. **Do not open a second RBAC lane until one of the two in-flight
lanes merges.** Wave 0b's two halves (`0b-main`, `0b-t2`) are sequenced by their own
entry conditions and therefore cannot both be open before their blocking lane lands.

---

## 4. Owner-owed items

| # | Item | Why it is the owner's |
|---|---|---|
| **O-1** | **Promote `6415062b9`** (the 0a-1 merge) to `origin/dev` in the next verified fast-forward batch. It is on local `dev` only. Pushing `origin/dev` auto-deploys staging including `tenants:migrate`, so it is an owner-timed action. | staging deploy |
| **O-2** | **Runner-flip checklist: remove the `.github/workflows/ci.yml:1271` allowlist entry** (`\|PermissionCacheTenantScopingTest` in the `t6-phase0b-pgsql` `--filter` alternation) **when `vars.SELF_HOSTED_RUNNER_READY` flips** and `feature-lane-tenancy` starts running the `Identity` group — otherwise the class runs twice. Lower `Identity.classes` and `gated_ceiling` in the same edit. Recorded in the manifest note at `apps/api/tests/feature-lane-manifest.json:820` and repeated here so it survives the note. | a repository variable only the owner sets |
| **O-3** | **OQ-1 — do service accounts count toward the last-admin floor?** Design is written against the recommended default **No** (a tenant whose only admin is a machine has nobody who can log in when the token is lost). The "yes" diff is bounded and named in spec §9.2: drop `principal_kind = human` from *A*, drop EC-4, re-word the Service-account glossary row. **Needed before wave 2b.** | product decision |
| **O-4** | **OQ-2 — default service-token TTL.** Default applied: **365 days, set explicitly at issue, no unlimited option**, plus `sanctum:prune-expired --hours=24` daily. Consequence stated: **human back-office tokens LENGTHEN from today's 30 days to 90**; POS terminal tokens keep their explicit one-year `expires_at`. **Needed before wave 2b.** | changes a live token policy |
| **O-5** | **OQ-3 — retire any of the eighteen baselined SoD combinations?** Default applied: retire **three** from `manager` (`invoices.create`+`.post`, `credit-notes.create`+`.post`, `payments.create`+`.refund`), keep the rest, change nothing on `accountant`. Second half of the question: does `general_manager` mirror the retirement (18 → 15 → 12)? **Needed before wave 1's `PermissionSodTemplateTest` baseline is pinned.** | narrows what a real manager can do on day one |
| **O-6** | **OQ-4 — does `SYNC_PERMISSIONS_ON_BOOT` default to `true` in production after the wave-1 soak?** Default applied: **yes**, after a one-week staging soak with zero unintended diffs. Conservative alternative: leave it `false` and run `permissions:sync-fleet` as a named deploy step. **Needed at the end of wave 1.** | production deploy behaviour |
| **O-7** | **Wave 0a scope addition to argue with or accept:** T3b adds `apps/web/src/features/settings/UsersPage.tsx` to wave 0a, which is **not** in the spec's verified 0a file list. Reason in §5 below. It makes `frontend-conventions-reviewer` mandatory for 0a. Alternative: gate as specified and ship a known 403-on-load for `manager`, deferring the guard to wave 2a. | a deliberate deviation from an accepted spec |

---

## 5. Findings this plan adds to the spec

Two things the spec's wave-0a scoping does not cover, found while writing the
dispatchable plan. Both are recorded here so a gate can argue with them rather than
discover them.

**F-1 — `GET /roles` is called by a page a `manager` can open, and gating it 403s
that call.** Wave 0a-4 gates `GET /api/v1/roles` on
`require.any.permission:roles.view,users.assign-roles` (spec §4.6.4). Neither key is
granted to any seeded role except `admin` — they appear in
`RolesAndPermissionsSeeder::permissionNames()` at `:379,382-383` and in **no**
`rolePermissionGrants()` block other than `'admin' => self::permissionNames()`
(`:582`). But `manager` holds `settings.view` and `users.view`, so a manager reaches
Settings → Users (`apps/web/src/routes/index.tsx:2333-2341`, gated
`moduleKey="settings"` → `settings.view`, `apps/web/src/hooks/usePermissions.ts:97`),
and `UsersPage` fires `GET /roles` unconditionally on mount
(`apps/web/src/features/settings/UsersPage.tsx:130-137`, `enabled` is only
`tenantId !== null && companyId !== null`). After 0a-4 that call returns **403 on
every manager page load** — a defect the wave's own empirical-evidence exit
checklist would catch. The one-line fix is in wave 0a's **T3b**: add
`&& hasPermission('users.assign-roles')` to that query's `enabled`. `hasPermission`
and `usePermissions` are already imported and destructured at `:38,102`, and
`'users.assign-roles'` is already in the generated map
(`apps/web/src/hooks/permissionsMap.generated.ts:272`), so it needs no new import,
no new string and no regenerated type. **`UsersPage.tsx` is disjoint from both
in-flight lanes** (measured 2026-09-10); `RolesPage.tsx` and `usePermissions.ts`
are **not** — `lane/w-lot-a-1a` edits both — which is why the guard goes in
`UsersPage.tsx` and nowhere else.

**F-2 — wave 0a's three new Architecture tests run on no CI event.** See the named
residual under wave 0a above. The fix is one step in the `backend-architecture` job
naming the three files, batched into **0b-11**'s `ci.yml` commit because that file is
edited by both in-flight lanes.

---

## 6. Gate r9's three editorial minors and where each lands

| Minor | Correction | Applied in |
|---|---|---|
| **m-1** | `TenantFleetRunnerSelectorTest`'s ambiguous-selector fixture must use a **reachable cross-column collision** — a selector equal to tenant A's UUID and tenant B's unique slug — because `tenants.slug` is globally unique (`apps/api/database/migrations/2025_11_30_000001_create_tenants_table.php:16-20`) and two equal slugs cannot exist. The snapshot primitive is `get(['id','slug'])`, not `pluck('id','slug')`, which would collapse duplicate keys rather than preserve an ambiguous match set. EC-39's settled *outcome* is untouched. | wave **0b** exit checklist ②, and wave 1's extension of the same test |
| **m-2** | Refresh the lane pins to current tips rather than trusting the spec's. Command: `git -C /Users/houssamr/Projects/syneriva/apps/erp rev-parse --short lane/w-lot-a-1a lane/t2-receipt-spine lane/t1-transfers-edge dev`. | §3 above, re-measured 2026-09-10 |
| **m-3** | `PermissionDeploySequenceTest` consumes a **versioned YAML runbook** at `apps/api/tests/Architecture/fixtures/permission-deploy-runbook.yaml` rather than parsing this document's or the spec's Markdown prose. Human-facing wave rows are *validated against* that file; the file is the authority. | wave **1** exit checklist ④ |

---

## 7. Handbacks and gate records

| Wave | Plan | Handback | Gate records |
|---|---|---|---|
| 0a-1 (done) | `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` Task 1 | `docs/handoff/HANDBACK-rbac-w0a-s1-2026-09-10.md` | `docs/superpowers/reviews/2026-09-10-rbac-w0a-s1-gate-r{1,2}.md` |
| 0a | `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md` | `docs/handoff/HANDBACK-rbac-w0a-2026-09-10.md` | `docs/superpowers/reviews/2026-09-10-rbac-w0a-gate-r*.md` |
| 0b | *(written when its entry condition is met)* | `docs/handoff/HANDBACK-rbac-w0b-*.md` | — |
| 1..4 | *(one plan per wave, written at its entry condition)* | — | — |

Spec gates r1..r9 are at `docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r{1..9}.md`;
the accepted revision is rev 9 at `c08a3db21`.
