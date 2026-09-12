# Codex Desktop dispatch — RBAC wave 0a RESUME (paste as a NEW thread named `RBAC-W0a resume — Tasks 1-6`)

Self-contained packet. It supersedes `docs/handoff/CODEX-DISPATCH-RBAC-W0a-2026-09-10.md` for the remainder of the wave; that original brief is still worth reading for background, but every fact below was re-measured on 2026-09-12 and wins where the two differ. Threads do not survive account changes, so nothing here relies on a previous conversation.

Repo: `/Users/houssamr/Projects/syneriva/apps/erp`. Lane: branch `lane/rbac-w0a`, worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rbac-w0a` — **it already exists, do not create it again.** Plan authority: `docs/superpowers/plans/2026-09-10-rbac-wave-0a.md` **rev 5.1**, gate r5 = **DISPATCH-READY** (`docs/superpowers/reviews/2026-09-10-rbac-plan-0a-codex-gate-r5.md`). Every step's command and its expected output are written in the plan; this packet only tells you where you are and what moved.

## Where the lane is

| Fact | Value (measured 2026-09-12) |
|---|---|
| Lane tip | `ed88aa2ed23db17b006850e0a0f2466a163f5e2f` — **Task 0 is DONE** (`Phase 0.1.0: Report the per-tenant permission cache reset's outcome in the deploy entrypoint`; touches `apps/api/docker/entrypoint.sh` + `apps/api/tests/Architecture/EntrypointPermissionCacheResetShapeTest.php`) |
| Lane base when Task 0 was written | `630afa86f` (the tip the original brief named) |
| Local `dev` now | `477c877a341a8f228c01489c04243befc229d3e5` (`477c877a3`) — six commits later: guardrails G0 (CI aggregate completeness, fail-closed preflight, POS typecheck), two stale web fixtures, and the parties-import finalize currency fix. **None of them touches a wave-0a path and none touches any `routes.php`.** |
| `git merge-tree --write-tree dev lane/rbac-w0a` | **CLEAN** |
| Item 0a-1 (S-1 tenant-scoped permission cache) | MERGED into `dev` at `6415062b9` — verified ancestor. Not yours. |
| Item 0a-4 (four Identity read gates) | moved to wave 0b task **0b-15** by spec amendment A-1. Not yours. There is **no Task 3**. |
| Remaining work | **Tasks 1, 2, 4, 5, 6** of the plan, in that order, then the handback. |
| Worktree bootstrap | `apps/api/vendor/` is present and `composer.lock` is byte-identical to the main checkout — run `composer dump-autoload` once after the merge below and re-check the three-version gate; no `composer install` needed unless the gate fails. |

## Phase 0 — resume variant (replaces the plan's Phase 0 checklist, 10 minutes)

1. `cd /Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rbac-w0a && git status --short && git rev-parse HEAD` — expect a clean tree and `ed88aa2ed…`. If the tree is dirty or the SHA differs, **stop and report**; someone else has written to the lane.
2. **Bring the lane up to current `dev` with a merge, not a rebase:** `git merge --no-edit dev`. Expected: a clean merge commit with no conflict (the orchestrator proved this with `merge-tree`). Record the merge commit SHA and `git rev-parse --short dev` (`477c877a3`) in the handback as the **observed base**. The merge commit keeps its default subject; only implementation commits follow the `Phase 0.1.<task>` rule. **Do not rebase, do not reset, do not `git stash`** (the stash stack is shared across every worktree on this machine).
3. `cd apps/api && composer dump-autoload && ./vendor/bin/phpunit --version && ./vendor/bin/phpstan --version && ./vendor/bin/pint --version` — all three must print a version.
4. **Overlap check A — the literal 32-path array from plan Phase 0 (`:126-176`), with THREE lanes instead of two.** Copy the array verbatim from the plan and replace the inner loop head with:
   ```zsh
   for lane in lane/w-lot-a-1a lane/t2-receipt-spine lane/imp1-history-export; do
   ```
   Expected: `paths: 32`, no `CLAIMED` line, `claimed=0`. The orchestrator's run on 2026-09-12 at `1e19a0f37` / `208449350` / `4300d9ff3` was clean; if yours is not, **stop and report** which lane claimed which path — do not re-scope it yourself.
5. **Overlap check B — hunk location in `.github/workflows/ci.yml`, with the job range UPDATED.** G0 added three steps inside `backend-architecture`, so at `dev` `477c877a3` the job spans **`:143-243`** (`backend-architecture:` at `:143`, `backend-dpa-guard:` at `:244`), the `Event ratchets` step is at `:224` with its `run:` at **`:235`**, and `Run Deptrac architecture ratchet` follows at `:237`. Run:
   ```
   cd /Users/houssamr/Projects/syneriva/apps/erp
   for l in lane/w-lot-a-1a lane/t2-receipt-spine lane/imp1-history-export; do echo "-- $l"; git diff dev...$l -- .github/workflows/ci.yml | grep '^@@'; done
   ```
   Expected (measured 2026-09-12): `@@ -1130,7 +1130,7 @@` (W-LOT), `@@ -1138,8 +1138,12 @@` (T2), `@@ -1139,7 +1139,7 @@` (IMP-1). Intersection test on the **old-file** side: a hunk intersects the job iff `start <= 243 && start + count - 1 >= 143`. None of the three does. If one does, **stop** and report; Task 6 step 1 would then have to be sequenced after that lane's merge.
6. Lane accounting: `lane/w-lot-a-1a`, `lane/t2-receipt-spine` and `lane/imp1-history-export` are the active feature lanes; `lane/rbac-w0a` is the permissions lane. Do not open a second RBAC lane.

## Execute the plan — Tasks 1, 2, 4, 5, 6, checkbox by checkbox

Read the plan and the spec from the audit worktree (they are **not** on `dev` and must **not** be copied into your lane):

- Plan rev 5.1: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rbac-audit/docs/superpowers/plans/2026-09-10-rbac-wave-0a.md` (from your worktree: `../rbac-audit/docs/superpowers/plans/2026-09-10-rbac-wave-0a.md`)
- Spec rev 9.2 (rev 9 as accepted at gate r9 plus editorial amendments A-1 and A-2 in its own **Amendments** section — read that section first): `../rbac-audit/docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md`
- Programme plan: `../rbac-audit/docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md`
- Gate register you execute under: `../rbac-audit/docs/superpowers/reviews/2026-09-10-rbac-plan-0a-codex-gate-r5.md`

**Do not skip the red runs. Do not reorder. Capture the first failing assertion text before every fix.**

### Corrections to apply while reading the plan (the tree moved under it)

| Plan text | Read it as | Why |
|---|---|---|
| Task 6 step 1: "append one step immediately after `:228` (the `Event ratchets` step)" | append immediately after the `Event ratchets` step's `run:` line, which is now **`:235`**, i.e. between `Event ratchets` and `Run Deptrac architecture ratchet` (`:237`) | G0 inserted `Feature-lane checker liveness test` (`:193`), `Preflight status liveness test` (`:204`) and `Feature-lane local harness guards` (`:211`) above it |
| Phase 0 / Task 6 intersection range `143..236` | **`143..243`** | same insertion |
| `all-checks-pass` at `:2769` | `:2735` at `477c877a3`; still no `if:` guard on `backend-architecture`, still in the aggregate's `needs` — no aggregate wiring needed | G0 reflowed the tail of the file |
| Overlap loops name two lanes | three lanes (add `lane/imp1-history-export`) | IMP-1 opened after the plan was written |
| Task 6 step 3 runs `tests/Architecture/FeatureLaneManifestCheckerTest.php` alongside the six wave classes | still run it; it now carries the G0 liveness cases, so its test count is larger than the plan assumed. Expected: green. Do not edit it — it is G0's file | G0 |
| Every commit block in the plan ends with two trailers (`Co-Authored-By` + `Claude-Session`) | use **only** `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`; drop the `Claude-Session:` line — it names a session that is not authoring this lane | orchestrator rule since 2026-09-11 |
| Baseline arithmetic 167/148 → 152/146, 298 keys, universe 1054 / 38 skipped | unchanged expectation — no `routes.php` changed on `dev` since `630afa86f`. If the generator prints different numbers, **stop and reconcile** before writing the baseline; do not "fix" the ceilings to whatever came out | verified by `git diff --name-only 630afa86f..dev | grep routes` = empty |

Everything else in the plan is current: file list, the seven-route self-service allow-list, the five gating aliases, the 18-entry public allow-list, the tombstone fixture, the 25-file PHPStan/Pint lists in Task 6 step 4, and the three UI probes in Task 6 step 5.

## Rules

- **PHPUnit BY PATH ONLY** — never the full suite. Every command in the plan names its files.
- **No PostgreSQL leg is expected** — every wave-0a test is static or router-only. If one unexpectedly needs a database: `DB_DATABASE=autoerp_test_r` **and** `DB_CENTRAL_DATABASE=autoerp_test_r` (create it first; port 5433 container), serial, announced in the handback. Never the shared default.
- **PHPStan level 8 and `pint --test` over the explicit 25-file list only** (Task 6 step 4). Never `tests/Architecture` as a directory (45 inherited errors from deliberately-invalid fixtures).
- **Constructor injection only** (`private readonly`, never `app()`), **strict types**, **no `mixed`** except the one annotated `json_decode()` narrowing the plan permits.
- **TDD:** every red run in the plan is a deliverable.
- **Path-scoped commits only** (`git add <explicit paths>`; never `-A`, never `.`), subjects `Phase 0.1.<task>: <imperative summary>` exactly as the plan's commit table, body as the plan writes it, single `Co-Authored-By` trailer.
- **No push. No merge into `dev`. No rebase. No `git stash`.** Stay on `lane/rbac-w0a`. The one merge you make is step 2 above (`dev` **into** the lane).
- `CLAUDE.md` rules 2, 3, 4 (no scope creep), 6, 9, 12, 13.

## Commits still owed

| Task | Subject | `git add` paths (from the plan) |
|---|---|---|
| 1 | `Phase 0.1.1: Add the authz.self self-service marker, its alias and its exact seven routes` | plan `:914` |
| 2 | `Phase 0.1.2: Install the route-coverage ratchet with its scanner, tombstone behaviour test and tamper cases` | plan `:2641` |
| 4 | `Phase 0.1.4: Gate fifteen writes and two coupon reads with existing permission keys` | plan `:3023` |
| 5 | `Phase 0.1.5: Correct the two documents that teach authorization` | plan `:3281` |
| 6 (CI) | `Phase 0.1.6: Register wave 0a's Architecture classes in the always-on backend-architecture job` | `.github/workflows/ci.yml`, on its own commit, after `actionlint` (or the YAML-parse fallback, declared as UNVERIFIED semantics) and the re-run hunk check |
| 6 (handback) | `Phase 0.1.7: Record the wave 0a handback` | `docs/handoff/HANDBACK-rbac-w0a-2026-09-10.md` |

## Handback

Write `docs/handoff/HANDBACK-rbac-w0a-2026-09-10.md` exactly as plan Task 6 step 6 enumerates, and add at the top:

- the Task 0 commit SHA (`ed88aa2ed`) as already-done, the **merge commit SHA** from Phase 0 step 2, and the observed `dev` base (`477c877a3` or later — say which);
- the output of overlap checks A (three lanes) and B (three hunks, range `143..243`);
- which bootstrap form you used (`dump-autoload` only, or a fresh `composer install`).

Then everything the plan already lists: red/green tails per task with the first failing assertion; the missing-baseline red from Task 2 step 5; the four tombstone `source` pins; the Task 4 step 8b regeneration triple (failure message, `git hash-object` `before=… after=…` with `IDENTICAL`, `298 152 146`); baseline arithmetic 167/148 → 152/146 with universe 1054 / 38 skipped; manifest checker green with `feature-lane-manifest.json` unmodified; PHPStan `[OK] No errors` and Pint pass over the 25 files; `ci.yml` parses and the appended step names the same six classes as the local run; all **three** UI probes (admin, manager no-regression, `viewer` denial) with network and console captured, zero 5xx, zero new console errors; `git diff dev...HEAD --name-only` with no "Deliberately NOT touched" path and no `apps/web/` path; residuals R-0, R-2, R-3 with owner and wave; deviations; and the merge-readiness block (`rev-parse --short dev`, `merge-tree --write-tree`, `diff --stat`, and the 32-path `git diff <merge-base>..dev` expected empty).

Stop with **`Status: review`**. The orchestrator runs `tenancy-authz-reviewer` on the whole diff and merges. Do not merge into `dev`, do not push, do not run the reviewer yourself.

## Out of scope — do not touch

- Everything in wave 0b and later: any new permission key, the four Identity read gates (0b-15), **any `apps/web` file**, `RequireAnyPermission` message work, the counting-item gate, the protected-blob repository variable (0b-11; the anti-growth direction stays a `markTestSkipped`, residual R-3).
- Any migration, seeder edit (`RolesAndPermissionsSeeder.php` belongs to 0b and `lane/w-lot-a-1a`), new table/column/unique key, or `feature-lane-manifest.json` edit.
- Any file the three active lanes touch (`lane/w-lot-a-1a`, `lane/t2-receipt-spine`, `lane/imp1-history-export`), except `.github/workflows/ci.yml` under the hunk-location rule. Notably `RolesPage.tsx`, `permissionsMap.generated.ts`, `usePermissions.ts`, `routes/index.tsx`.
- **Anything under `apps/api/app/Modules/{Treasury,Accounting,Document,Inventory,Purchasing,POS,Fiscal}`** and their tests. The correctness campaign (payments not posting to GL, invoice posting durability, goods-receipt GL, PO receiving) is being repaired in separate lanes from the Codex audit branches; wave 0a gates seven other modules' route files and nothing in those.
- `SYNC_PERMISSIONS_ON_BOOT` in `entrypoint.sh`; fixing R-2 (per-tenant failure isolation) — Task 0 already made it visible, which is all this wave does.
- G0's files: `scripts/preflight.sh`, `scripts/tests/`, `apps/api/tools/feature-lane-manifest-check.php`, `apps/api/tests/Architecture/FeatureLaneManifestCheckerTest.php`. If one of them blocks you, report; do not edit.
