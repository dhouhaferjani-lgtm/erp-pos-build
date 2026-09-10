# Codex Desktop dispatch — RBAC wave 0a (paste as a NEW thread named `RBAC-W0a enforcement hardening`)

Repo: `/Users/houssamr/Projects/syneriva/apps/erp`. Plan authority: `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md` **rev 5.1**, gate r5 = **DISPATCH-READY** (`docs/superpowers/reviews/2026-09-10-rbac-plan-0a-codex-gate-r5.md`, 0 BLOCKER / 0 MAJOR / 5 minor, all five applied editorially in rev 5.1). Read the plan before you touch anything: **every step's command and its expected output are already written there.**

## Purpose

Wave 0a of the roles & permissions programme — *enforcement hardening with the permissions that already exist*. It closes the gap class the 2026-09-09 audit found, without a migration, a new permission key, a seeder edit, a manifest edit or a single `apps/web` file:

1. **Self-service allow-list** — the seven routes whose resource *is* the caller get a new `authz.self` marker, so they stop reading as enforcement gaps. The marker is deliberately **not** self-certifying: classification comes from an exact `(method, uri)` allow-list in test support code, never from the middleware stack.
2. **Route-coverage ratchet + baseline** — a live-router scanner, its 298-key baseline, the tombstone behaviour test and the convention-08 tamper cases, making every remaining gap countable and shrink-only (separate write/read ceilings).
3. **Write/read gates with existing keys** — 15 ungated writes and 2 ungated coupon reads closed with keys already in `RolesAndPermissionsSeeder::permissionNames()`.
4. **Entrypoint reset isolation fix** — the per-tenant permission cache reset reports its outcome and stops suppressing stderr, and its abort message tells the operator the truth about the tenants the abort *skipped*.
5. **Docs** — `docs/conventions/03-AUTHORIZATION.md` and `.claude/commands/add-permissions.md` are corrected so the two documents that teach authorization stop teaching the `permission:` alias that has never existed in this repository.
6. **CI registration** — all six new Architecture classes are registered in the always-on `backend-architecture` job, so the ratchet gates PR→`dev` from the moment this lane merges.

**Already done, not yours:** item **0a-1 (S-1, tenant-scoped Spatie permission cache) is MERGED into local `dev` at `6415062b9`** — Task 0 only records that fact and makes the reset's outcome visible. **Item 0a-4 moved out of this wave** to wave 0b as **0b-15** by spec **amendment A-1** (rev 9.1): the four Identity read gates ship there together with the page guards they require. There is **no Task 3** in this wave.

## Base

Local `dev`, currently **`630afa86f`** (full `630afa86ff4e6ee7ccb7dcc86242fbe834dbcc87`) — the same tip the plan's Phase 0 expects. The tip moves several times a day: the plan's **Phase 0 re-measures it** (`rev-parse HEAD` in the new worktree) and, if it has moved, requires you to **re-run both overlap checks before touching anything** and to write the observed SHA into the handback. Do that; do not assume `630afa86f`.

## Worktree and bootstrap

From the shared checkout, create the lane:

```
git -C /Users/houssamr/Projects/syneriva/apps/erp worktree add .worktrees/rbac-w0a -b lane/rbac-w0a dev
```

Work **only** inside `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rbac-w0a`. Never edit in the shared `dev` checkout. **No `git stash`** — the stash stack is shared across every worktree on this machine.

**Dependency bootstrap — exactly as the plan's Phase 0 step 2 (`:108-124`), before any test command.** A fresh worktree carries **no `apps/api/vendor/`** (`apps/api/.gitignore:22`), so the first thing an un-bootstrapped Task 0 prints is `no such file or directory: ./vendor/bin/phpunit`, not the planned red. Either form is acceptable, and the plan gives both verbatim:

- **CI-identical (authoritative, slower)** — mirrors `.github/workflows/ci.yml:159-171`:
  `cd …/.worktrees/rbac-w0a/apps/api && composer install --no-interaction --prefer-dist`
- **Local fast path** — valid only when `cmp` shows the two `composer.lock`s are byte-identical: `cp -R` the main checkout's `vendor`, then **`composer dump-autoload`** (mandatory — `optimize-autoloader: true` at `apps/api/composer.json:109-112` bakes absolute class→file paths for the *main* checkout, so the lane's new `tests/Architecture/Support/*` classes would otherwise resolve to files that do not exist).

**Gate:** `./vendor/bin/phpunit --version && ./vendor/bin/phpstan --version && ./vendor/bin/pint --version` must all print before Task 0 starts. **Record which of the two bootstrap forms you used in the handback** — a re-run of your reds depends on it. **`pnpm install` is NOT needed in wave 0a**: the wave touches no `apps/web` file, ships no Playwright spec and runs no frontend command.

## Rules

- **PostgreSQL test database: `autoerp_test_r`.** Set **both** `DB_DATABASE=autoerp_test_r` and `DB_CENTRAL_DATABASE=autoerp_test_r` for **any** PG leg (create the database first if it does not exist; port 5433 container). Run PG legs serially. Never the shared default database. Announce the PG leg explicitly in the handback.
- **PHPUnit BY PATH ONLY** — never the full suite, on this laptop or in this worktree. Every command in the plan already names its files.
- **PHPStan level 8 on the touched files** — the explicit file list of Task 6 step 4. **Never `tests/Architecture` as a directory:** it exits 1 on `dev` with 45 inherited errors from deliberately-invalid detector fixtures (gate r2 M-2).
- **Pint on the touched files** — `--test` over the same explicit list; never over a directory this lane does not own, and never through a `$(git diff …)` pathspec evaluated from `apps/api` (it resolves to nothing).
- **Constructor injection only** (`private readonly`; never the `app()` helper), **strict types**, **no `mixed`**.
- **TDD:** every red run in the plan is a deliverable. Capture the first failing assertion text before the fix.
- `CLAUDE.md` rules 2, 3, 4 (no scope creep), 6, 9, 12, 13.

## The plan

`docs/superpowers/plans/2026-09-10-rbac-wave-0a.md` — **rev 5.1**.

**Execute Tasks 0, 1, 2, 4, 5, 6 in order, checkbox by checkbox. Every step's command and expected output are in the plan. Do not skip the red runs. Do not reorder.** (There is no Task 3 in this wave — a tombstone stands where it was.)

**Where to read the plan from.** The plan and the spec live on branch `docs/rbac-audit-2026-09-09` and are **not on `dev` yet**, so they will **not** exist inside your new worktree. Read them from the audit worktree, which is a sibling directory:

- Plan (rev 5.1): `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rbac-audit/docs/superpowers/plans/2026-09-10-rbac-wave-0a.md` — from `.worktrees/rbac-w0a` that is `../rbac-audit/docs/superpowers/plans/2026-09-10-rbac-wave-0a.md`.
- Spec (rev **9.1** — rev 9 as accepted at gate r9, **plus editorial amendment A-1**, which is recorded in the spec's own **Amendments** section at the top; read that section first): `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rbac-audit/docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md` — from your worktree, `../rbac-audit/docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md`.
- Programme plan (for the Entry condition and Exit checklist the wave answers to): `../rbac-audit/docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md`.
- Gate register you are executing under: `../rbac-audit/docs/superpowers/reviews/2026-09-10-rbac-plan-0a-codex-gate-r5.md`.

**Read them, do not copy them into your lane.** Those four documents are owned by the orchestrator's audit branch; your lane must not contain them.

## Commits

Seven commits, each **path-scoped** (`git add <explicit paths>`, never `git add -A`, never `git add .`), each carrying **both trailers** exactly as the plan's commit blocks show. Subjects follow `AGENTS.md:15-16` (`Phase <major.minor.patch>: <imperative summary>`):

| Commit | Subject | `git add` paths (from the plan) |
|---|---|---|
| Task 0 | `Phase 0.1.0: Report the per-tenant permission cache reset's outcome in the deploy entrypoint` | plan `:381` — `apps/api/docker/entrypoint.sh` + `apps/api/tests/Architecture/EntrypointPermissionCacheResetShapeTest.php` |
| Task 1 | `Phase 0.1.1: Add the authz.self self-service marker, its alias and its exact seven routes` | plan `:914` — the middleware, `bootstrap/app.php`, the three route files, `Support/SelfServiceRouteRegistry.php` and the two self-service tests |
| Task 2 | `Phase 0.1.2: Install the route-coverage ratchet with its scanner, tombstone behaviour test and tamper cases` | plan `:2641` — the nine `tests/Architecture/Support/*` classes, the three test classes, the baseline JSON and the two fixtures |
| Task 4 | `Phase 0.1.4: Gate fifteen writes and two coupon reads with existing permission keys` | plan `:3023` — the four module route files, `RoutePermissionCoverageRatchetTest.php`, the baseline JSON |
| Task 5 | `Phase 0.1.5: Correct the two documents that teach authorization` | plan `:3281` — `docs/conventions/03-AUTHORIZATION.md` `.claude/commands/add-permissions.md` |
| Task 6 (CI) | `Phase 0.1.6: Register wave 0a's Architecture classes in the always-on backend-architecture job` | plan `:3380` — `.github/workflows/ci.yml`, **on its own commit** |
| Task 6 (handback) | `Phase 0.1.7: Record the wave 0a handback` | plan `:3518` — `docs/handoff/HANDBACK-rbac-w0a-2026-09-10.md` |

Use each task's commit **body** as the plan writes it — only the subject line is governed by `AGENTS.md`.

**No push. No merge. No rebase onto anything. No `git stash`.** Stay on `lane/rbac-w0a`.

## Overlap rule

Two lanes are in flight: `lane/w-lot-a-1a` (`52f5ad796`) and `lane/t2-receipt-spine` (`951a7637e`).

- **`.github/workflows/ci.yml` is the single accepted overlap.** Both lanes touch that file, so *absence* is the wrong test; what must hold is that neither lane's hunk lands inside the `backend-architecture` job this wave appends to (`.github/workflows/ci.yml:143-236` at `dev` `630afa86f`). Measured 2026-09-10: exactly one hunk each — W-LOT `@@ -1130,7 +1130,7 @@`, T2 `@@ -1138,8 +1138,11 @@` — both ~900 lines below the job, so all three edits merge without a textual conflict.
- **Phase 0 check A and check B must be green before Task 0 starts.** Check A is the literal 32-path `w0a_paths=( … )` zsh array in the plan (`:126-176`): expected `paths: 32`, **no `CLAIMED` line**, `claimed=0`. Check B is the hunk-location test (`:178-189`): a lane intersects iff `start <= 236 && start + count - 1 >= 143` on the **old-file** side of its `@@` header. **If either check fails, STOP** — a `CLAIMED` path means a lane has claimed a 0a file and that item must be re-scoped to 0b; an intersecting hunk means Task 6 step 1 must be sequenced *after* that lane's merge, never merged blind. Report it and stop; do not decide the re-scope yourself.
- Re-run check A at the merge moment as well (Verification checklist, plan `:3546`).

## Handback

Write `docs/handoff/HANDBACK-rbac-w0a-2026-09-10.md` (contents enumerated at plan Task 6 step 6), carrying the plan's **Verification checklist** (`:3529-3546`) as evidence:

- lane branch, base SHA (the one you **observed**, plus which bootstrap form you used), and each task's commit SHA;
- **red/green tails per task** — the exact first failing assertion captured before each fix, then the green run: `EntrypointPermissionCacheResetShapeTest` (3 tests), `SelfServiceRouteAllowListTest` + `SelfServiceRouteShapeTest` (7), `TombstoneRouteBehaviourTest` (4, zero skips), `RoutePermissionCoverageRatchetTest` (green, exactly 2 skips), `RoutePermissionCoverageRatchetLivenessTest` (green, zero skips, 6 cases), `AuthLifecycleTest` (green, unchanged), and `tests/Feature/{Promotion,Uom,Menu,Coupon}` — any failure there explained as a fixture-actor fix, **never** as a relaxed gate;
- the **missing-baseline red** from Task 2 step 5 — paste the text you actually observe; the first line (the guard message naming the missing baseline path) is the load-bearing part, the second is PHPUnit 11's own rendering of `assertIsString` (gate r5 m-4);
- the four regenerated **tombstone `source` pins** (Task 2 step 5b);
- the **Task 4 step 8b regeneration check**, all three outputs: the regeneration failure message, the `git hash-object` `before=… after=…` pair with **`IDENTICAL`**, and **`298 152 146`**. **Not** a `git diff --numstat` — step 8b forbids it, because it compares the working tree with the index rather than the two regeneration states (gate r4 M-4 / gate r5 m-1);
- **baseline arithmetic**: generated **167 writes / 148 reads** (315 keys), final **152 writes / 146 reads**, **298** keys, plus the live-router universe numbers (expected 1054 in universe, 38 skipped). `152 / 142 / 294` is where **wave 0b task 0b-15** leaves it, **not** this wave — if you see it, stop and reconcile;
- **`php tools/feature-lane-manifest-check.php`** green with `feature-lane-manifest.json` **unmodified** by this lane (manifest check);
- **PHPStan** `[OK] No errors` and **Pint** `--test` pass, both over the explicit Task 6 step 4 file list (never a directory);
- `.github/workflows/ci.yml` parses and the appended step names **the same six classes** the local run does;
- **all three UI probes'** network and console output — admin (Probe A), manager (Probe B, no-regression) and the **`viewer` denial probe** — zero 5xx, zero new console errors, every expected status observed;
- `git diff dev...HEAD --name-only` containing **no** path from the plan's "Deliberately NOT touched" list and **no `apps/web/` path at all**;
- the residuals R-0, R-2, R-3 (live) with owner and wave — R-1 relocated with T3, R-4 closed in this wave;
- **deviations** — anything you did differently and why;
- the merge-readiness block the plan names (`rev-parse --short dev`, `git merge-tree --write-tree`, `git diff dev...<tip> --stat`, and the 32-path `git diff <merge-base>..dev` expected empty).

Then stop with **`Status: review`**. **The orchestrator runs the reviewer gate (`tenancy-authz-reviewer`, mandatory, on the whole diff) and merges.** Do not merge into `dev`, do not push to origin, do not run the reviewer yourself.

## Out of scope

- **Everything in wave 0b and later**: any **new permission key**, the **role-read gates** (the four Identity reads — `GET /roles`, `GET /roles/{id}`, `GET /permissions`, `GET /users/{id}/roles` — which are **0b-15** with their page guards, per amendment A-1), **any `apps/web` change at all**, the `RequireAnyPermission` message work, the counting-item gate, and 0b-11's protected-blob repository variable (the ratchet's anti-growth direction stays a `markTestSkipped` here — that is residual R-3, not a bug to fix).
- **Any migration, seeder edit, new table/column/unique key, or `feature-lane-manifest.json` edit.** This wave adds none.
- **Any file the two in-flight lanes touch** — `lane/w-lot-a-1a` and `lane/t2-receipt-spine` — with the single accepted exception of `.github/workflows/ci.yml` under the hunk-location rule above. Notably: `RolesPage.tsx`, `permissionsMap.generated.ts`, `usePermissions.ts`, `routes/index.tsx` are W-LOT's; `permissionsMap.generated.ts` is also T2's. Do not touch them.
- D4 response shaping (a bare `users.assign-roles` holder gets role **names**, not the matrix) — **wave 2a**.
- The `SYNC_PERMISSIONS_ON_BOOT` block in `entrypoint.sh` — untouched, later wave.
- Fixing R-2 (per-tenant failure isolation for `tenants:run permission:cache-reset`). Task 0 makes it **visible**; it does not fix it.

## Lane cap

The cap is **3** concurrent lanes. T-1 has merged; `lane/w-lot-a-1a` and `lane/t2-receipt-spine` are active — **one slot free, and `lane/rbac-w0a` takes it.** Do not open a second RBAC lane, and confirm no manual test day overlaps the merge window (plan Phase 0, `:190-191`).
