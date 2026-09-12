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
(**rev 9.2** — rev 9 ACCEPTED at Codex spec gate r9, 0 BLOCKER / 0 MAJOR / 3 minor editorial,
register at [`docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r9.md`](../reviews/2026-09-10-rbac-spec-codex-gate-r9.md);
plus **editorial amendment A-1 of 2026-09-10**, recorded in that spec's own **Amendments**
section, which moves item `0a-4` to wave 0b as `0b-15` and restates §8's wave-0a/0b rows and
ceilings — `152 / 146 / 298` at the end of 0a, `152 / 142 / 294` at the end of 0b-15;
**and editorial amendment A-2 of 2026-09-11**, which applies the wave-2 plan's **Q-w2-7**
ruling and re-spells the three keys that derive from neither `PermissionDefinition`
constructor — `pos.discount_unlimited` → **`pos.override_discount_limit`**,
`service-accounts.issue-token` → **`service-accounts.tokens.create`**,
`service-accounts.revoke-token` → **`service-accounts.tokens.delete`** — with no change to
any route, template default or entry condition).

**Measured at:** `dev` = `630afa86f`, 2026-09-10, **re-measured after the wave-0a plan's
Codex gate r2** (it was `d64db9a0e` when this document was written, `105b1b22b` when plan
gate r1 read it and `2f99fef26` at gate r2's fix round — `dev` moves several times a day).
Every lane SHA and every overlap claim below was re-run at that tip with
`git rev-parse --short` and `git diff --shortstat`; none is carried forward from the spec.

> **Revision note, 2026-09-10 (wave-0a plan gate r4).** Wave 0a's plan is now **rev 5**
> (register: `docs/superpowers/reviews/2026-09-10-rbac-plan-0a-codex-gate-r4.md`,
> CHANGES-REQUIRED, **0 B** / 5 M / 3 m). Three of its findings change **this** document:
> **M-5** — O-2's runner-flip procedure conflated flipping the repository variable with
> removing the static gate declarations the manifest checker actually reads, and told the
> owner to remove each owning job's whole `if:`, which would let a heavy self-hosted lane
> run on PR→`dev`; it is rewritten below as an explicit five-step transition. **m-1** —
> the revision labels here still called the wave plan rev 3 and pointed only at gate r2;
> they now say **rev 5 / gate r4**, in the note below, in the §3 dispatch row and in the
> §7 handback table. **Citation audit** — the wave-0b scope row's D4 anchor `spec :1610,
> 1616-1622` predated amendment A-1 and now lands before the current D4 table; it is
> re-pointed to `spec :1647-1658`. Wave 0a's Entry condition, ceilings, wave split and
> reviewer gates are **unchanged**: gate r4 raised no blocker and every major it did raise
> is operational.
>
> **Revision note, 2026-09-10 (wave-0a plan gate r2).** Wave 0a's plan was then **rev 3**
> (register: `docs/superpowers/reviews/2026-09-10-rbac-plan-0a-codex-gate-r2.md`,
> CHANGES-REQUIRED, 3 B / 6 M / 5 m). Three of its findings change **this** document:
> **M-1** — wave 0a's Entry condition below must not claim every path returns empty, because
> `.github/workflows/ci.yml` is an **accepted** file-level overlap and is verified by hunk
> location instead; **M-5** — wave 0b's scope row assigned `roles.view` to the `UsersPage`
> roles query, which contradicts owner ruling D4 (an assigner needs the role-name list, so
> that query's guard is **`users.assign-roles`**); and **m-1 / the citation audit** — the
> ref pins here were stale again and three citations needed a `dev:` qualifier. All are
> applied below.
>
> **Revision note, 2026-09-10 (wave-0a plan gate r1).** Wave 0a's plan was **rev 2**
> (register: `docs/superpowers/reviews/2026-09-10-rbac-plan-0a-codex-gate-r1.md`,
> CHANGES-REQUIRED, 3 B / 7 M / 5 m). Three of its findings change **this** document:
> **B-3** moved 0a's T3/T3b — the four Identity read-route gates and the `UsersPage`
> guard — into wave **0b** as task **0b-15**, together with the `RolesPage` / route /
> Settings-card guards they require; **B-2** brought the CI registration of 0a's
> Architecture classes **into 0a** (residual F-2/R-4 is closed, not deferred); and
> **M-2** corrected the claim that those classes ran on no CI event — they ran on
> `workflow_dispatch`, they did not gate PR→`dev`. The wave-0a row, the "which wave may
> start" table, §5's findings and §7's handback table are updated below.

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
| **Goal** | Stop the bleeding with permissions that already exist: mark the seven genuinely self-service routes, install the route-coverage ratchet with its baseline, **its tombstone behaviour test and its convention-08 tamper cases**, and close 15 ungated writes + **2** ungated reads using only seeded keys — then **register the whole set in CI** so it gates PR→`dev`. No migration, no new permission key, no seeder edit, **and no `apps/web` file** (rev 2, gate r1 B-3). |
| **Scope items (spec §8)** | `0a-1` land S-1 tenant-scoped permission cache — **DONE**, merged into local `dev` at `6415062b9` (lane `lane/rbac-w0a-s1-permission-cache`, tip `05455fa41`; gates r1/r2 at `docs/superpowers/reviews/2026-09-10-rbac-w0a-s1-gate-r{1,2}.md`). `0a-2` route-coverage ratchet + baseline + liveness fixture. `0a-3` `authz.self` middleware, alias and the two allow-list/shape tests. ~~`0a-4` gate the four Identity read routes~~ — **MOVED to wave 0b as `0b-15`** by plan gate r1 B-3, with the page guards it requires; **recorded as spec editorial amendment A-1 (rev 9 → rev 9.1, 2026-09-10)**, which also names the r9 Preserve bullet *"all six start-now 0a tasks"* as superseded and restates the post-0a ratchet state as **152 writes / 146 reads / 298 keys** (plan gate r3 B-1). `0a-5` gate Promotion ×4, Uom ×5, Menu ×3, Coupon ×3 writes + the two coupon reads. `0a-8` docs. **`0a-2b` (new, rev 2)** register the wave's six Architecture classes in the always-on `backend-architecture` CI job. Plus the S-1 follow-up minor (entrypoint per-tenant reset reports its outcome). |
| **Entry condition** | **None**, under a two-part overlap check — **not** "every path returns empty", which was rev 2's wording and contradicted the wave's own accepted overlap (plan gate r2 M-1). **(A)** Every 0a path **except `.github/workflows/ci.yml`** was re-measured on 2026-09-10 against `lane/w-lot-a-1a` (`52f5ad796`) and `lane/t2-receipt-spine` (`951a7637e`) with `git diff --name-only dev...<lane> -- <path>`: **every one returns empty for both lanes.** The spec's single accepted overlap — `apps/api/tests/feature-lane-manifest.json` — is **no longer an overlap**, because 0a-1 already merged (it consumed the `Identity.classes` 32→33 and `gated_ceiling` 1253→1254 raise) and every remaining 0a test lives under `tests/Architecture/`, which the feature-lane manifest does not govern (`apps/api/tools/feature-lane-manifest-check.php:205,328-334`). **(B)** `.github/workflows/ci.yml` **is** edited by both lanes and by wave 0a (gate r1 B-2), and is verified by **hunk location**: `git diff dev...<lane> -- .github/workflows/ci.yml \| grep '^@@'` returns exactly `@@ -1130,7 +1130,7 @@` for W-LOT and `@@ -1138,8 +1138,11 @@` for T2, while 0a appends after `dev:.github/workflows/ci.yml:228`, inside the `backend-architecture` job that spans `:143-236`. **The stop condition is a hunk reaching into `143..236`, not the file appearing in the diff.** Convention 08's same-lane rule (`docs/conventions/08-DETECTOR-LIVENESS.md:58-64`) outweighs the zero-overlap preference: a ratchet that gates no merge event is not a CI guard. |
| **Exit checklist** | ① Every task's named PHPUnit path green, red captured first with the exact failing assertion pasted into the handback. ② `php tools/feature-lane-manifest-check.php` green with **no** ceiling change (0a adds no `tests/Feature` class). ③ PHPStan L8 + `pint --test` clean on touched paths. ④ **The six Architecture classes named in one `backend-architecture` step**, the workflow parses, and the local run names the same six. ⑤ **UI happy path, real app, console + network captured:** log in as `admin` → Settings → Roles and Settings → Users behave **exactly as on `dev`** (`GET /roles`, `GET /permissions` still 200 — 0a does not gate them) → Promotions list, archive a promotion → UoM units create/edit/delete **plus one conversion** → Menus delete a category item → Coupons list + revoke. ⑥ **No-regression probe as `manager`** (every key 0a uses is one a manager already holds, so nothing may newly refuse) **and a real denial probe as `viewer`**: a UoM unit create and a coupon revoke each 403 with the standard envelope, while the UoM conversion still succeeds. ⑦ Zero 5xx and zero new console errors across all three probes. ⑧ Handback written at `docs/handoff/HANDBACK-rbac-w0a-2026-09-10.md`. |
| **Reviewer gates** | `tenancy-authz-reviewer` (**mandatory**) on the whole diff. **`frontend-conventions-reviewer` is NOT a gate for 0a as of rev 2** — the wave touches no `apps/web` file (gate r1 B-3); it becomes mandatory for wave 0b's task 0b-15. No inventory/treasury/fiscal reviewer — 0a touches none of those modules. |
| **Deploy steps** | **None new.** 0a-1 already requires `permission:cache-reset` at boot and that is already wired (`dev:apps/api/docker/entrypoint.sh:190-191` — **`dev:`**, because the per-tenant line arrived with the 0a-1 merge and does not exist at the audit worktree's HEAD; plan gate r2 citation audit); the follow-up task only makes its per-tenant arm report its outcome. No permission row is added, so no tenant needs a catalogue run. |
| **Rollback point** | Each task is one self-contained commit; `git revert` of the task commit restores prior behaviour. The ratchet baseline reverts with its test (they are added in the same commit and every gating commit deletes its own baseline entries). |

**Named residual carried out of 0a, rev 2 (wording corrected at plan gate r2 m-3):** the
ratchet's **anti-growth** direction — the baseline key set compared against a CI-pinned
blob — stays inert until **0b-11** registers `ROUTE_COVERAGE_BASELINE_PROTECTED_BLOB`. The
blocker is that the blob is an **owner-held repository variable**, and the `env:` line that
reads it is worthless before it is registered — **not** that wave 0a cannot edit
`.github/workflows/ci.yml`, which it demonstrably does (it appends the whole
`backend-architecture` step). Growth and stale are live from 0a's own merge. Wave 0a's
`RoutePermissionCoverageRatchetLivenessTest::matched_growth_defeats_the_checker_which_is_what_the_protected_blob_exists_for`
is that residual's executable record.

> **The former residual "the new Architecture classes execute on no CI event" is CLOSED,
> and it was also mis-stated (plan gate r1 B-2 / M-2).** Mis-stated: the classes *did*
> execute on `workflow_dispatch`, because the manual full-suite step runs
> `tests/Architecture` (`.github/workflows/ci.yml:473-501`); what they did not do is gate
> **PR→`dev`**, since `backend-architecture` names files only (`:191,202,215,228`, reason
> at `:223-227`) and `backend-test` is `if:`-skipped there (`:325-330`). Closed: **wave 0a
> now registers them itself**, as a discrete appended step after `:228` in its own
> path-scoped commit (0a plan Task 6 step 1), naming **six** classes — the four the wave
> originally planned plus `TombstoneRouteBehaviourTest` and
> `RoutePermissionCoverageRatchetLivenessTest`, which gate r1's two other blockers added.
> **0b-11 is narrowed to appending the `env:` block to that step.** Deferring a detector's
> registration while calling it a CI ratchet is exactly what convention 08 forbids.

### Wave 0b — new permissions for the remaining ungated writes

| Field | Value |
|---|---|
| **Goal** | Declare and gate the ungated writes whose permission does **not** exist yet, plus the four items wave 0a had to defer for file-overlap reasons, and ship the one shared fleet runner. |
| **Scope items (spec §8)** | `0b-1` `credit-notes.cancel` (first). `0b-2..4` `services.*` (3), `service-categories.*` (3), `channels.*` (4), `categories.*` (4), `progression.*` (2), `purchase-hub.orders.create`, `companies.create`. `0b-5` the two `BatchExpiry` writes, gated **beside** the lane's `BatchActionAccess`, not instead of it. `0b-6` delete `PermissionSeeder.php` + its `ProductionSeeder.php:73-75` caller. `0b-7` fix `RefundResidualTenantIsolationTest:136-142` **in 0b-1's commit**. `0b-8` glossary rows on top of the lane's General-manager row. `0b-9` `permissions:ensure` + `permissions:ensure-fleet` + `TenantFleetRunner` + `LegacyRoleBaseline` (both predicates) + `PermissionWriteLock`. `0b-11` the `ROUTE_COVERAGE_BASELINE_PROTECTED_BLOB` `env:` line, appended to the `backend-architecture` step **wave 0a already created** (the step registration is no longer 0b's — gate r1 B-2). `0b-12` `RequireAnyPermission` `error.details`. `0b-13` the counting-item gate. `0b-14` wire the lock into every runtime role-grant writer. **`0b-15` (new — plan gate r1 B-3, recorded as spec amendment A-1, rev 9.1) the four Identity read-route gates relocated from 0a, with their page guards. It is FIRST in wave 0b, because it is what closes I-18, and it takes the read ceiling `146 → 142` and the baseline `298 → 294` keys. The two keys are NOT interchangeable (plan gate r2 M-5, owner ruling D4): the `UsersPage` roles query is gated on **`users.assign-roles`** — `GET /roles` deliberately accepts `roles.view` OR `users.assign-roles` because a role *assigner* needs the role-name list (spec `:1647-1658` — the D4 route table and its three response shapes; the pre-A-1 anchor `:1610,1616-1622` now lands before it, plan gate r4 citation audit) — while the two `RolesPage` queries, the `/settings/roles` route and the Settings card are gated on **`roles.view`**. Plus a Playwright spec.** |
| **Entry condition** | **BOTH `lane/w-lot-a-1a` AND `lane/t2-receipt-spine` merged into local `dev`** — checked as `git merge-base --is-ancestor <lane> dev` for each, not a `branch --merged` grep. *(Changed by the wave-0b plan gate r2, **BLOCKER B2-1**, 2026-09-10: T2 was previously an entry condition for items 0b-12 and 0b-13 only, with an instruction to split 0b into `0b-main` and `0b-t2` if T2 landed late. **That split is withdrawn.** T2's diff overlaps **seven** wave-0b paths, not two, so the `0b-main` half would have been editing files an unmerged lane also rewrites — which wave 0b's own reviewer rule already forbids.)* **W-LOT, by file:** 0b edits `apps/api/database/seeders/RolesAndPermissionsSeeder.php` (`permissionNames()` and `rolePermissionGrants()`) — the lane rewrites that file and introduces a marked-tenant preservation branch in it; 0b-5 edits `apps/api/app/Modules/BatchExpiry/Presentation/routes.php` — the lane rewrites it wholesale and adds `BatchActionAccess`; 0b-8 edits `docs/glossary.md` — the lane adds the General-manager row and the sole-writer sentence there; 0b-14 edits `RoleController.php` and `UserController.php` — the lane moves both; **0b-15 edits `apps/web/src/features/settings/RolesPage.tsx`, `apps/web/src/routes/index.tsx` and `apps/web/src/hooks/usePermissions.ts` — all three are in the lane's diff, which is precisely why plan gate r1 B-3 put it here rather than in 0a.** **T2, by file — all seven, measured at `951a7637e`:** `apps/api/app/Modules/Inventory/Presentation/routes.php` (`:307-308`, item 0b-13); `apps/api/app/Http/Middleware/RequireAnyPermission.php` (`:25`, item 0b-12); `apps/api/database/seeders/RolesAndPermissionsSeeder.php` (two new transfer keys `:201-202`, the `manager` grant line `:604`) — **the file every 0b task edits**; `apps/api/tests/feature-lane-manifest.json` (`gated_ceiling` 1267 at `:9`, `Inventory.classes` 141 at `:836`, raise note `:843`) — recomputed by 0b's Task 14; `apps/web/src/hooks/permissionsMap.generated.ts` (`:119,:122`) — regenerated by 0b; `docs/glossary.md` (`:80-92`) — item 0b-8; and `.github/workflows/ci.yml` (`:1141-1145`, item 0b-11) — a **file-level** overlap only, since T2's hunk `@@ -1138,8 +1138,11 @@` is ~900 lines from the `backend-architecture` job at `:143-236` that 0b-11 appends to. The middle five are what make the split unsafe. |
| **Exit checklist** | ① All named PHPUnit paths green by path, red captured first. ② `EnsurePermissionsFleetTest` and `TenantFleetRunnerSelectorTest` green — the latter **using a reachable cross-column collision** (a selector equal to tenant A's UUID and tenant B's slug), never two equal slugs, because `tenants.slug` is globally unique (`apps/api/database/migrations/2025_11_30_000001_create_tenants_table.php:16-20`); the snapshot primitive is `get(['id', 'slug'])`, not `pluck('id','slug')` (gate r9 m-1). ③ Convention-09 rows for this wave: `ServicePermissionsSecondCompanyTest`, `CategoryPermissionsSecondCompanyTest`, `StockAdjustmentLocationScopePermissionTest`, and `permissions:ensure` run twice reporting `created=0 granted=0` with zero row writes. ④ Route-coverage ceilings shrink — writes **152 → 127**, reads **146 → 142** (0b-15, the first read closure) **→ 126** — and no baseline entry is left stale. ⑤ **0b-15's e2e:** `pnpm --filter @autoerp/web test:e2e` run and `apps/web/e2e/rbac-role-read-gates.spec.ts` green (`AGENTS.md:12-16`), asserting a `manager` issues **no** `/roles` or `/permissions` request at all — an absence, not a 403. ⑤ **UI happy path with console + 5xx capture:** a Services CRUD round trip as `manager`, a Categories create/reorder, a Channels list, a company create refused for a non-admin, a batch delete as `admin`. ⑥ `tenant:census-day-one` clean. |
| **Reviewer gates** | `tenancy-authz-reviewer` (**mandatory**). `frontend-conventions-reviewer` (**mandatory** — the regenerated `permissionsMap.generated.ts`, the label JSONs, **and 0b-15's four frontend surfaces**). `inventory-costing-reviewer` for 0b-13 and 0b-5 (a batch delete and a counting-item write). |
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
| **Scope items (spec §8)** | `roles.is_system` consumption; the single `LastAdminFloor` service wired into all nine writers of §4.6.3 plus `LastAdminFloorWriterCensusTest`; F-1; **the D4 payload shaping of §4.6.4** (this is where `GET /users/{userId}/roles` stops returning the permission matrix to a bare `users.assign-roles` holder — **wave 0b's task 0b-15** only *gates* those routes; the gating moved out of 0a by plan gate r1 B-3); `RoleCreated/RoleUpdated/RoleDeleted` on `DomainEvent`; `(principal_id, token_id)` attribution; denial events on `audit_events` with the two-key Redis dedup; `EffectivePermissionResolver` with `forSubject`/`forTarget`; `GET /users/{id}/effective-permissions[?token_id=]`; the effective-permissions panel; `RolesPage` grouped by registry module; the FE deletions of §4.5.4 (`uiAliasPermissions.ts` and the fail-open fallback); **and the conversion of the eight role-name sites** — `CreateDraftCountingRequest.php:35`, `InventoryCountingController.php:782,866,905,975,1297,1494`, `DiscountPermissionResolver.php:39`. |
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
| **Entry condition** | **Wave 2a merged**, *and* `ScopedTokenIssuanceEntryConditionTest` green — no scoped token may be issuable while a role-name idiom can bypass the narrowing. `service-accounts.tokens.create` stays unrouted until then. *(Re-spelled by the orchestrator's **Q-w2-7** ruling of 2026-09-11, recorded as spec editorial amendment **A-2**, rev 9.1 → 9.2 — the key derives as nested resource `service-accounts.tokens` + verb `Create`; the entry condition itself is unchanged.)* |
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
lane/w-lot-a-1a merged ──────────┐
                                 ├──► 0b (ONE lane) ──► 1 ──► 2a ──► 2b ──► 3 ──► 4
lane/t2-receipt-spine merged ────┘                       ▲
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
| `dev` | `630afa86f` | — | — | — |
| `lane/rbac-w0a-s1-permission-cache` | `05455fa41` | — | **YES** — merged at `6415062b9`, `--is-ancestor` verified | nothing; 0a-1 is DONE |
| `lane/t1-transfers-edge` | `86273346a` | **empty** | **YES** — `--is-ancestor` verified | nothing |
| `lane/w-lot-a-1a` | `52f5ad796` | 83 files, +4 714, −663 | **NO** | wave **0b-main** and (via its staging protocol) wave **1** |
| `lane/t2-receipt-spine` | `951a7637e` | 100 files, +13 144, −546 | **NO** | wave **0b-t2** (items 0b-12, 0b-13 only) |

**`dev` has now moved four times while the wave-0a plan was being gated** — `d64db9a0e`
when this document was written, `105b1b22b` when plan gate r1 read the tree, `2f99fef26`
when rev 2 was written, `630afa86f` now — and **this time both lanes moved too**:
`lane/w-lot-a-1a` `5d847b2ba` → `52f5ad796` (83 files, +4 674/−663 → +4 714/−663) and
`lane/t2-receipt-spine` `d7123fa30` → `951a7637e` (100 files, +13 066/−495 → +13 144/−546).
Neither moved a `ci.yml` hunk into the `backend-architecture` job, so wave 0a's accepted
overlap is unchanged — which is the check, not the SHA. `origin/dev` is `ad1d6ceb1` and
still does not contain `6415062b9` (`git merge-base --is-ancestor 6415062b9 origin/dev`
exits non-zero), so O-1 stands.

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
| **0a** | **NOW.** No entry condition; disjoint at today's tips **except the accepted `.github/workflows/ci.yml` file-level overlap**, whose hunks are ~900 lines from the job wave 0a appends to, and the laptop lane cap allows it: one RBAC lane (`lane/rbac-w0a`) alongside the two in-flight lanes is exactly 3. | none — dispatch `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md` (**rev 5**; revs 1–4 were CHANGES-REQUIRED at plan gates r1–r4) |
| **0b** (one lane, `lane/rbac-w0b`) | When **both** W-LOT and T2 have landed. **No `0b-main`/`0b-t2` split** — withdrawn by wave-0b plan gate r2 B2-1, because T2 overlaps seven 0b paths and the non-T2 half would have edited five of them. Task 0b-15 (the Identity read gates + page guards, relocated from 0a) rides here, because the three frontend files it needs are in W-LOT's diff. | both of these exit 0, from the shared checkout: `git -C /Users/houssamr/Projects/syneriva/apps/erp merge-base --is-ancestor lane/w-lot-a-1a dev` and `… --is-ancestor lane/t2-receipt-spine dev`. **Not** a `branch --merged` grep: it matches a substring and a deleted-then-recreated name |
| **1** | When 0a **and** 0b are merged **and** W-LOT's five-push staging protocol has completed. | the two `branch --merged dev` greps above **plus** the owner's confirmation that the five-push protocol finished — the protocol is a staging fact, not a repo fact, so no grep can prove it |
| **2a** | Wave 1 merged and its one-week staging soak shows zero unintended diffs. | soak evidence in the wave-1 handback |
| **2b** | Wave 2a merged **and** `ScopedTokenIssuanceEntryConditionTest` green. | the test, run by path on the 2a lane |
| **3** | Wave 2 merged. | `branch --merged dev` for both wave-2 lanes |
| **4** | Wave 3 merged. | as above |

**Lane-cap arithmetic while 0a runs:** `lane/w-lot-a-1a` + `lane/t2-receipt-spine` +
`lane/rbac-w0a` = 3. **Do not open a second RBAC lane until one of the two in-flight
lanes merges.** Wave 0b is **one** lane and cannot open until both blocking lanes have
merged, so by the time it starts the cap is not near.

---

## 4. Owner-owed items

| # | Item | Why it is the owner's |
|---|---|---|
| **O-1** | **Promote `6415062b9`** (the 0a-1 merge) to `origin/dev` in the next verified fast-forward batch. It is on local `dev` only. Pushing `origin/dev` auto-deploys staging including `tenants:migrate`, so it is an owner-timed action. | staging deploy |
| **O-2** | **Runner-flip procedure — FIVE steps, corrected again at plan gate r4 M-5. It is a REMOVAL plus a RECOMPUTE, never a decrement, and flipping the variable is only step one of five.** The checker **does not read the variable's runtime value.** A lane is added to `$gatedLanes` purely from its *declaration*: its manifest `execution_gate` must equal the owning job's full static `if:` expression, whitespace-normalised, and the job must reference exactly one `vars.*` (`apps/api/tools/feature-lane-manifest-check.php:704-721`). Until those declarations change, the per-group ceilings and the aggregate `gated_ceiling` keep being enforced at `:765-819` no matter what the variable is set to. Rev 3's *"a single variable flip empties the gated set"* was therefore wrong, and its instruction to remove the owning job's matching `if:` was worse: the `if:` is a **conjunction** — `vars.SELF_HOSTED_RUNNER_READY == 'true' && (github.event_name == 'workflow_dispatch' \|\| github.base_ref == 'main' \|\| (github.event_name == 'push' && github.ref == 'refs/heads/main'))` at `.github/workflows/ci.yml:1441-1444` (`feature-lane-pos`) and `:2031-2036` (`feature-lane-tenancy`), and identically on the other gated lane jobs — so deleting the whole expression would make a 90-minute self-hosted lane run on every PR→`dev`. Do exactly this, in order: **(1) Flip `vars.SELF_HOSTED_RUNNER_READY` to `true`** so the jobs begin executing on their event arm. Nothing in the manifest changes yet, and nothing needs to: the ceilings stay enforced and stay satisfied. **(2) In the cleanup PR, remove the `execution_gate` key from each manifest lane that is going live** (`apps/api/tests/feature-lane-manifest.json`). This — not the flip — is what takes a lane out of `$gatedLanes`. **(3) Strip ONLY the `vars.SELF_HOSTED_RUNNER_READY == 'true' &&` conjunct from each corresponding job's `if:`, retaining the `workflow_dispatch` / PR-to-`main` / push-to-`main` predicate verbatim.** The B4 equality check compares the manifest gate against the job `if:`, so steps 2 and 3 must land in the same commit or the checker fails on the mismatch it is designed to catch. **(4) Remove the ONE temporary allow-list entry** `\|PermissionCacheTenantScopingTest` from the `t6-phase0b-pgsql` `--filter` alternation at `dev:.github/workflows/ci.yml:1271` — otherwise `PermissionCacheTenantScopingTest` runs twice. **(5) Recompute `gated_ceiling` from the declarations that are STILL gated after steps 2–3, and delete the key only when the static gated set is empty.** The checker sums `count($members)` over every group whose lane is still gated and compares that sum with the single top-level `gated_ceiling` (`:765-819`), so the new value is *whatever remains*, never the old value minus one. For scale: the tenancy lane alone carries **eleven groups** — `Admin` 9, `Authorization` 2, `Billing` 2, `Company` 34, `CompanyConfig` 1, `Identity` 33, `Modules` 53, `Permissions` 3, `Subscription` 1, `SupportAccess` 13, `Tenant` 31 = **182 classes** of the 1 254 the ceiling covers today. **All 70 gated lanes currently declare the same variable**, so if steps 2–3 are applied to every one of them in a single PR the static gated set does become empty and the `gated_ceiling` key is deleted; if the cleanup is staged lane by lane, a smaller recomputed ceiling remains at each stage and `gated_ceiling` stays. **And leave every group's `classes` alone throughout.** `Identity.classes` is the **actual on-disk class count**, 33 (`dev:apps/api/tests/feature-lane-manifest.json:817-821`), not a budget: once a lane is live the checker stops *enforcing* that number and it becomes informational — it does not become smaller. Lowering it would falsify the manifest. Recorded in the manifest note at `dev:apps/api/tests/feature-lane-manifest.json:817-821` (the note itself is `:820`) and repeated here so it survives the note. **Both `dev:` citations are local-`dev` facts** — the S-1 allow-list and the `Identity.classes` 33 raise both arrived with the 0a-1 merge and exist at neither the audit worktree's HEAD nor `origin/dev` (plan gate r2 citation audit). | a repository variable only the owner sets |
| **O-3** | **OQ-1 — do service accounts count toward the last-admin floor?** Design is written against the recommended default **No** (a tenant whose only admin is a machine has nobody who can log in when the token is lost). The "yes" diff is bounded and named in spec §9.2: drop `principal_kind = human` from *A*, drop EC-4, re-word the Service-account glossary row. **Needed before wave 2b.** | product decision |
| **O-4** | **OQ-2 — default service-token TTL.** Default applied: **365 days, set explicitly at issue, no unlimited option**, plus `sanctum:prune-expired --hours=24` daily. Consequence stated: **human back-office tokens LENGTHEN from today's 30 days to 90**; POS terminal tokens keep their explicit one-year `expires_at`. **Needed before wave 2b.** | changes a live token policy |
| **O-5** | **OQ-3 — retire any of the eighteen baselined SoD combinations?** Default applied: retire **three** from `manager` (`invoices.create`+`.post`, `credit-notes.create`+`.post`, `payments.create`+`.refund`), keep the rest, change nothing on `accountant`. Second half of the question: does `general_manager` mirror the retirement (18 → 15 → 12)? **Needed before wave 1's `PermissionSodTemplateTest` baseline is pinned.** | narrows what a real manager can do on day one |
| **O-6** | **OQ-4 — does `SYNC_PERMISSIONS_ON_BOOT` default to `true` in production after the wave-1 soak?** Default applied: **yes**, after a one-week staging soak with zero unintended diffs. Conservative alternative: leave it `false` and run `permissions:sync-fleet` as a named deploy step. **Needed at the end of wave 1.** | production deploy behaviour |
| ~~**O-7**~~ | **CLOSED by the orchestrator, 2026-09-10, on plan gate r1 B-3.** The question was whether to accept `apps/web/src/features/settings/UsersPage.tsx` into wave 0a. The ruling took neither of the two options as posed: the **whole item** — the four Identity read gates *and* their page guards, now including `RolesPage`, the `/settings/roles` route and the Settings card — moved to wave **0b as task 0b-15**. Wave 0a touches no `apps/web` file, needs no `frontend-conventions-reviewer`, and ships no known 403-on-load. | — (closed) |

---

## 5. Findings this plan adds to the spec

Two things the spec's wave-0a scoping does not cover, found while writing the
dispatchable plan. Both are recorded here so a gate can argue with them rather than
discover them.

> **Both findings below are SUPERSEDED by the wave-0a plan's Codex gate r1 and are kept
> for the reasoning, not the disposition.** **F-1** was right about `UsersPage` and
> **incomplete**: gate r1 B-3 found a second, reachable caller the one-line fix did not
> close — `RolesPage` fires `GET /roles` **and** `GET /permissions` unconditionally
> (`apps/web/src/features/settings/RolesPage.tsx:73-80,82-89`) behind a route gated only on
> `moduleKey="settings"` (`apps/web/src/routes/index.tsx:2344-2351`) and a Settings card
> with no permission check at all (`apps/web/src/features/settings/SettingsPage.tsx:30-42`,
> whose section type has no permission field). Because `RolesPage.tsx`, `routes/index.tsx`
> and `usePermissions.ts` are all in `lane/w-lot-a-1a`'s diff, the **whole** item — API
> gates and page guards together — moved to wave **0b as task 0b-15**, and wave 0a now
> touches no `apps/web` file. That also closes **O-7** below: there is no frontend scope
> addition to wave 0a to accept or refuse. **F-2** is closed by 0a itself registering its
> Architecture classes in CI, and its premise was wrong (see the residual note under wave
> 0a): the classes ran on `workflow_dispatch`, they did not gate PR→`dev`.

**F-1 — `GET /roles` is called by a page a `manager` can open, and gating it 403s
that call.** Wave 0a-4 gates `GET /api/v1/roles` on
`require.any.permission:roles.view,users.assign-roles` (spec §4.6.4). Neither key is
granted to any seeded role except `admin` — they appear in
`RolesAndPermissionsSeeder::permissionNames()` at `:379,382` (**not** `:382-383` — `:383`
is `roles.manage`, a third key this claim does not concern; plan gate r2 citation audit)
and in **no**
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

**F-2 — wave 0a's three new Architecture tests run on no CI event.** *(Superseded — see
the box above and the residual note under wave 0a. The count was three, is now six; the
claim "no CI event" was wrong, the true gap was PR→`dev`; and the registration is in 0a,
not 0b-11.)*

---

## 6. Gate r9's three editorial minors and where each lands

| Minor | Correction | Applied in |
|---|---|---|
| **m-1** | `TenantFleetRunnerSelectorTest`'s ambiguous-selector fixture must use a **reachable cross-column collision** — a selector equal to tenant A's UUID and tenant B's unique slug — because `tenants.slug` is globally unique (`apps/api/database/migrations/2025_11_30_000001_create_tenants_table.php:16-20`) and two equal slugs cannot exist. The snapshot primitive is `get(['id','slug'])`, not `pluck('id','slug')`, which would collapse duplicate keys rather than preserve an ambiguous match set. EC-39's settled *outcome* is untouched. | wave **0b** exit checklist ②, and wave 1's extension of the same test |
| **m-2** | Refresh the lane pins to current tips rather than trusting the spec's. **One ref per invocation** — `git rev-parse --short A B C` exits 128 with `fatal: Needed a single revision`, so the single-line form printed here through plan gate r2 was not runnable (plan gate r3 m-1). Command: `for r in dev lane/w-lot-a-1a lane/t2-receipt-spine lane/t1-transfers-edge origin/dev; do printf '%-24s %s\n' "$r" "$(git -C /Users/houssamr/Projects/syneriva/apps/erp rev-parse --short "$r")"; done`. | §3 above, re-measured 2026-09-10, re-confirmed unchanged after plan gate r3 (`dev` `630afa86f`, `lane/w-lot-a-1a` `52f5ad796`, `lane/t2-receipt-spine` `951a7637e`, `lane/t1-transfers-edge` `86273346a`, `origin/dev` `ad1d6ceb1`) |
| **m-3** | `PermissionDeploySequenceTest` consumes a **versioned YAML runbook** at `apps/api/tests/Architecture/fixtures/permission-deploy-runbook.yaml` rather than parsing this document's or the spec's Markdown prose. Human-facing wave rows are *validated against* that file; the file is the authority. | wave **1** exit checklist ④ |

---

## 7. Handbacks and gate records

| Wave | Plan | Handback | Gate records |
|---|---|---|---|
| 0a-1 (done) | `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` Task 1 | `docs/handoff/HANDBACK-rbac-w0a-s1-2026-09-10.md` | `docs/superpowers/reviews/2026-09-10-rbac-w0a-s1-gate-r{1,2}.md` |
| 0a | `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md` (**rev 5**) | `docs/handoff/HANDBACK-rbac-w0a-2026-09-10.md` | plan gates: `docs/superpowers/reviews/2026-09-10-rbac-plan-0a-codex-gate-r*.md` · lane gates: `docs/superpowers/reviews/2026-09-10-rbac-w0a-gate-r*.md` |
| 0b | `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md` (**rev 3** — revs 1–2 were CHANGES-REQUIRED at plan gates r1–r2) | `docs/handoff/HANDBACK-rbac-w0b-*.md` | plan gates: `docs/superpowers/reviews/2026-09-10-rbac-plan-0b1-codex-gate-r1.md`, `docs/superpowers/reviews/2026-09-10-rbac-plan-0b-codex-gate-r2.md` |
| 1 | `docs/superpowers/plans/2026-09-10-rbac-wave-1.md` (rev 1) | — | — |
| 2a..4 | *(one plan per wave, written at its entry condition)* | — | — |

Spec gates r1..r9 are at `docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r{1..9}.md`;
the accepted revision is rev 9 at `c08a3db21`, amended to **rev 9.1** by editorial amendment A-1 (2026-09-10), which is recorded in the spec's own Amendments section rather than in any register — registers are immutable.
