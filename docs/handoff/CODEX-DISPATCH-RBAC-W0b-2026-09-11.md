# Codex Desktop dispatch — RBAC wave 0b (paste as a NEW thread named `RBAC-W0b new permission keys`)

Repo: `/Users/houssamr/Projects/syneriva/apps/erp`. Plan authority: `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md` **rev 6.3**, gate r8 = **DISPATCH-READY** (`docs/superpowers/reviews/2026-09-11-rbac-plan-0b-codex-gate-r8.md`, 0 BLOCKER / 0 MAJOR / 1 minor, applied editorially in rev 6.3). Read the plan before you touch anything: **every step's command and its expected output are already written there.**

---

## ENTRY CONDITIONS — DO NOT START UNTIL ALL TRUE

**This wave is DISPATCH-READY but NOT START-READY.** Three merges must have landed in local `dev` first. These are **execution blocks, not plan defects** (gate r8, Rejected false positives). Run all four checks from the shared checkout, in order, before you create a worktree or read a line of code:

```
cd /Users/houssamr/Projects/syneriva/apps/erp
git fetch --all --prune

# 1. W-LOT
git merge-base --is-ancestor lane/w-lot-a-1a dev      && echo "W-LOT MERGED" || echo "W-LOT NOT MERGED — STOP"

# 2. T2
git merge-base --is-ancestor lane/t2-receipt-spine dev && echo "T2 MERGED"    || echo "T2 NOT MERGED — STOP"

# 3. wave 0a
git merge-base --is-ancestor lane/rbac-w0a dev         && echo "0a MERGED"    || echo "0a NOT MERGED — STOP"
#    corroborating check the plan also names (Phase 0), in case the 0a branch was deleted after merge:
git log dev --oneline -- apps/api/tests/Architecture/RoutePermissionCoverageRatchetTest.php | head -1
#    Expected: a commit. Empty output means wave 0a has not merged — STOP.
```

**All three must print `MERGED`. Any `STOP` means the wave does not start — report it and stop.** The check is `git merge-base --is-ancestor`, **never** a `git branch --merged` grep: a grep matches a substring and matches a deleted-then-recreated branch name (plan Global Constraints, gate r2 **B2-1**).

**There is no split-the-lane fallback.** Rev 2's *"run this plan without Tasks 8 and 9 as `lane/rbac-w0b-main`"* is **withdrawn**: T2's diff touches **seven** paths this wave edits (`Inventory/Presentation/routes.php`, `RequireAnyPermission.php`, `.github/workflows/ci.yml`, `RolesAndPermissionsSeeder.php`, `feature-lane-manifest.json`, `permissionsMap.generated.ts`, `docs/glossary.md`), only two of which the split isolated. Every one of those seven is edited **on top of the merged T2 state**, never on top of `dev` as it stands today.

**4. Phase 0 re-measurement is itself an entry condition.** Every SHA, line number and count in the plan is a **rev-6.3-time value** — `dev` `33796cc08` (pin `630afa86f`), `lane/w-lot-a-1a` `a7010fe4d`, `lane/t2-receipt-spine` `208449350`, `lane/rbac-w0a` `ed88aa2ed`, `gated_ceiling: 1254`, `Identity.classes: 33`, and every `lane/…:file:line` citation. **After the three merges they are stale by construction**, and the plan's **Phase 0 re-checks all of them** (`:259-325`): the entry conditions, the lane-citation re-derivation greps (seven backend + two frontend), the `lot_action_permissions.php` default, and the manifest's current values. Do not assume any pinned number. The seeder citations move a **third** time — `dev` → W-LOT shifted them +80, and T2 adds two more keys *above* that block — which is exactly what the re-derivation step exists for. **Write every observed value into the handback.**

---

## Purpose

Wave 0b of the roles & permissions programme — *the nineteen permission keys that do not exist yet, the routes that need them, and the three pieces of machinery those keys cannot reach a live tenant without.*

1. **The oracles (Task 1)** — `PermissionRenameMap`, `LegacyRoleBaseline` and its two non-collapsing predicates, above all **`matchesPreWave0b()`**: the equality guard that decides whether a template role is still what the lane left it and may therefore be granted, or has been customised by the operator and must be skipped. Pure functions over arrays, no database, so they are provable before any writer exists.
2. **`PermissionWriteLock` (Task 2) — and every runtime writer wired into it (Task 4)**. The lock **adopts `lane/w-lot-a-1a`'s advisory key and row order verbatim**: key `'wlota1a:'.$tenantId` hashed with `hashtextextended(?, 0)`, rows ordered `roles.name` ASC then `roles.id` ASC. Two advisory namespaces are two serialization domains, i.e. no serialization at all. **Every** runtime role-grant writer is wired into that lock in the **same** commit — including **`ResetTenantCommand`** — because an equality check that is not serialized against the operator edit path is not a guard. Coverage is proven by **`PermissionWriterCensus`**, a **method-level** nikic/php-parser AST census (20 writer methods at `dev`, +3 in the lane) partitioned into `LOCKED` / `LOCK_INHERITED_FROM` / initialization-only, with **tree-wide** caller enumeration and per-method acquire/write and row-order assertions. File-level coverage is not acceptable (gate r1 **B0b-3**).
3. **`TenantFleetRunner` (Task 3) + `permissions:ensure` / `permissions:ensure-fleet` (Task 4)** — the one fleet iterator with its step-1a selector contract (nine runner cases including the cross-column collision and `migrations_behind`), and the additive deploy command whose whole run sits inside one transaction holding that same lock. The grant distribution is a **`--grant=<key>:<roles>` map** (`array<string, list<string>>`), repeatable, parsed by a shared trait; **`--grant-to` is REMOVED and must be refused loudly if passed**. The runner inverts the map to per-role key lists **before** the loop so the eligibility snapshot stays once-per-role. A missing admin role is `FAILED reason=admin_role_missing` on **both** the apply and the dry-run path, rolls back and exits non-zero. **`scripts/permissions-ensure-0b.sh`** (Task 14) is the atomic two-invocation wrapper: it writes `pending`, records `failed:<half>` on either failure, and writes `ok` **only** after both invocations succeed — a successful second invocation must not be able to overwrite a failed first.
   - **Why `tenants:seed RolesAndPermissionsSeeder` is not in the deploy at all:** after `lane/w-lot-a-1a` merges, that seeder writes **nothing** on a marked tenant with `LOT_ACTION_PERMISSIONS_ENFORCE=false` (its shipped default), so the 0b keys would never reach a tenant whose routes are already gated on them — 403 for everyone, `admin` included.
4. **The nineteen keys and their route gates (Tasks 5, 6, 7, 8, 13b)** — grouped **8 admin-only / 10 manager-only / 1 shared**, and **not a twentieth**:
   - **8 admin-only:** `channels.view/create/update/operate`, `progression.view/manage`, `purchase-hub.orders.create`, `companies.create`;
   - **10 manager / general-manager-only:** `credit-notes.cancel`, the three `services.*`, the three `service-categories.*`, `categories.create/update/delete`;
   - **1 broadly shared:** `categories.view`, which reaches **every seeded `products.view` role** (`admin`, `manager`, derived `general_manager`, `cashier`, `viewer`, `technician`, `operator`). `accountant` holds no 0b addition at all, because it holds no `products.view`.
   - **`WAVE_0B_ADDITIONS['admin']` is all nineteen** — that is wave 1's **adoption oracle input**, not a line in the deploy grant map, and the two must not be conflated (gate r1 **B0b-1**).
   - **Task 5** declares `credit-notes.cancel` **and fixes the masking test** that hid its absence.
   - **Task 7 (0b-5)** gates the two **BatchExpiry** writes (`DELETE /batches/{uuid}`, `POST /batches/{uuid}/recall`) **BESIDE** `BatchActionAccess`, never instead of it: a flag-conditional middleware that returns `$next($request)` with no check while `LOT_ACTION_PERMISSIONS_ENFORCE` is false **is not a gate**.
   - **Task 8 (0b-13)** gates the counting-item submit, **edited on top of the merged T2 state**.
   - **Task 13b (0b-15)** gates the four Identity **read** routes (`GET /roles`, `GET /roles/{id}`, `GET /permissions`, `GET /users/{id}/roles`) **together with the Users/Roles page guards they require** — relocated here from wave 0a by spec amendment **A-1**. The page guard must use the **same key** the route does, and the Playwright arm asserts the **absence of the request**, not a 403.
5. **`RequireAnyPermission` (Task 9)** — the failing permission list in `error.details`, **edited on top of the merged T2 state**. The message half is **verification-only**: T2 already ships the exact generic string; do not re-word it.
6. **`PermissionSeeder` deletion (Task 10)** — the file, its production caller, two tests, and five re-points.
7. **Glossary rows (Task 11)** — the Identity and authorization rows, added **on top of the lane's General-manager row**.
8. **CI registration (Task 12, 0b-11)** — the `env:` line that switches on wave 0a's anti-growth direction (`ROUTE_COVERAGE_BASELINE_PROTECTED_BLOB`), so the ratchet **fails closed**.
9. **Convention-09 rows (Task 13)** — second-company, second-location and re-run/idempotency tests on **rows**, never on status alone.
10. **The frontend (Task 6 + Task 13b)** — the scripted **87-row / 17-file** guard census (`scripts/census_0b.py`), covering the service-category call sites, **`ServicePicker`**, **`ServiceDetailPage`**, `CategorySelector`, `AddCompanyModal`, `CommandPalette`, `InventoryHubPage` and the rest; **`ServiceForm` update moves PUT → PATCH** (`routes.php` defines PATCH only) and `/service-categories` is canonical; guarded transitive callers; `permissionsMap.generated.ts` regenerated **inside Task 6's commit** (not Task 14) so `pnpm typecheck` passes there in isolation.
11. **The e2e (Task 6 + Task 13b)** — `apps/web/e2e/rbac-module-gates.spec.ts` with **five actor arms**, each with its own network **and** browser-console capture, plus `apps/web/e2e/rbac-role-read-gates.spec.ts` for 0b-15.
12. **Task 14** — ceiling recompute (writes **152 → 127**; reads **146 → 142** via 0b-15 **first**, then **→ 126**), **manifest recompute** (`feature-lane-manifest.json` is **recomputed, never textually merged**), frontend-map regeneration proven byte-identical across two generations, the two UI probes, and the handback.

---

## Base

Local `dev`, **whatever it is when the three entry merges have landed**. The plan's pins are rev-6.3-time values (`dev` `33796cc08`, kept pin `630afa86f`) and will have moved. **Phase 0 re-measures** (`rev-parse HEAD` in the new worktree) and requires the observed SHA in the handback. Do not assume a SHA.

---

## Worktree and bootstrap

From the shared checkout, create the lane:

```
git -C /Users/houssamr/Projects/syneriva/apps/erp worktree add .worktrees/rbac-w0b -b lane/rbac-w0b dev
git -C /Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rbac-w0b rev-parse HEAD
```

Work **only** inside `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rbac-w0b`. Never edit or commit in the shared `dev` checkout (CLAUDE.md rule 21). **No `git stash`** — the stash stack is shared across every worktree on this machine.

**Dependency bootstrap — do this before any test command**, exactly as wave 0a's Phase 0 step 2 prescribes. A fresh worktree carries **no `apps/api/vendor/`** (`apps/api/.gitignore:22`), so the first thing an un-bootstrapped Task 1 prints is `no such file or directory: ./vendor/bin/phpunit`, not the planned red. **Either form is acceptable:**

- **CI-identical (authoritative, slower)** — mirrors `.github/workflows/ci.yml`:
  `cd …/.worktrees/rbac-w0b/apps/api && composer install --no-interaction --prefer-dist`
- **Local fast path** — valid **only** when `cmp` shows the two `composer.lock`s are byte-identical: `cp -R` the main checkout's `vendor`, then **`composer dump-autoload`** (mandatory — `optimize-autoloader: true` at `apps/api/composer.json:109-112` bakes absolute class→file paths for the *main* checkout, so this lane's new `tests/Architecture/Support/*` and `tests/Feature/**` classes would otherwise resolve to files that do not exist).

**Gate:** `./vendor/bin/phpunit --version && ./vendor/bin/phpstan --version && ./vendor/bin/pint --version` must all print before Task 1 starts. **Record which of the two forms you used in the handback** — a re-run of your reds depends on it.

**Unlike wave 0a, this wave DOES touch `apps/web`**, ships **two** Playwright specs and runs `pnpm vitest` / `pnpm typecheck` / `pnpm lint`. Bootstrap the frontend too: `pnpm install` at the repo root of the worktree, and install the Playwright browsers if they are not already cached. Record that in the handback as well.

---

## Rules

- **PostgreSQL test database: `autoerp_test_r`.** Set **both** `DB_DATABASE=autoerp_test_r` and `DB_CENTRAL_DATABASE=autoerp_test_r` for **any** PG leg (create the database first if it does not exist; port 5433 container). **Run PG legs serially**, never two in parallel on this laptop. Never the shared default database. Announce the PG leg explicitly in the handback.
- **Every PG command passes `-c phpunit-pgsql.xml`.** `apps/api/phpunit-pgsql.xml:2-16` is the harness CI uses; exporting the database variables and then running the *default* configuration silently runs SQLite and the six PG-only tests (two-connection races, two real tenant databases, advisory locks) prove nothing (gate r1 **M0b-5**). The plan writes the full prefix at every PG command site.
- **PHPUnit BY PATH ONLY** — never the full backend suite, on this laptop or in this worktree. Every command in the plan already names its files.
- **PHPStan level 8 on the touched files** — Task 14 Step 4's **exact** path list. **Never `tests/Architecture` as a directory:** it exits 1 on `dev` with inherited errors from deliberately-invalid detector fixtures.
- **Pint on the touched files** — `--test` over the same explicit list plus `scripts/permissions-ensure-0b.sh`; never over a directory this lane does not own, and never through a `$(git diff …)` pathspec evaluated from `apps/api` (it resolves to nothing).
- **Constructor injection only** (`private readonly`; never the `app()` helper in production code), **strict types** (`declare(strict_types=1);` in every new PHP file), **no `mixed`**, **no `any`**, enums for every status/type value introduced.
- **`can:` only.** The action gate is `can:` (or `require.any.permission:` for an any-of). Never `permission:` — that alias has never existed in this repository. Never a controller check or a FormRequest `authorize()` as the *only* gate.
- **Middleware set (CLAUDE.md rule 12):** every module `routes.php` group keeps `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]` plus whatever `module:<Name>` gate it already carries. This wave **adds** `can:`; it never rewrites, reorders or removes an existing middleware.
- **No commit in this wave is knowingly red** (gate r1 **B0b-2**). Every task's commit must be green in isolation against its own stated run list. Catalogue coverage lands with Task 6.
- **TDD: every red run in the plan is a deliverable.** Author and observe each test *before* its implementation; capture the first failing assertion text before the fix; record branch-removal reds honestly.
- **Empirical evidence, not compilation** (owner rule, 2026-08-31). "Tests pass" is never the evidence for a wave that changes what twenty-five live pages can call. Task 14's two UI probes with 5xx and console capture are mandatory.
- `CLAUDE.md` rules 2, 3, 4 (no scope creep), 6, 9, 12, 13.

---

## The plan

`docs/superpowers/plans/2026-09-10-rbac-wave-0b.md` — **rev 6.3**.

**Execute the tasks in order, checkbox by checkbox. Every step's command and expected output are in the plan. The red runs are mandatory. Do not reorder.** The order is Task 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 13b, 14 — note that **0b-12 is Task 9 and 0b-13 is Task 8** (they were mislabelled in an earlier revision), and that **Task 13b commits as `Phase 0.2.15` before Task 14's `Phase 0.2.14`**, which is deliberate: the reads go 146 → 142 in 13b **first**, then → 126 in Task 14.

**Where to read the plan from.** The plan and the spec live on branch `docs/rbac-audit-2026-09-09` and are **not on `dev`**, so they will **not** exist inside your new worktree. Read them from the audit worktree, which is a sibling directory:

- **Plan (rev 6.3):** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rbac-audit/docs/superpowers/plans/2026-09-10-rbac-wave-0b.md` — from `.worktrees/rbac-w0b` that is **`../rbac-audit/docs/superpowers/plans/2026-09-10-rbac-wave-0b.md`**.
- **Spec (rev 9.1** — rev 9 as accepted at gate r9, **plus editorial amendment A-1**, recorded in the spec's own **Amendments** section at the top; read that section first**):** `../rbac-audit/docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md`.
- **Programme plan** (Entry condition and Exit checklist this wave answers to): `../rbac-audit/docs/superpowers/plans/2026-09-10-rbac-programme-execution-plan.md`.
- **Gate register you are executing under:** `../rbac-audit/docs/superpowers/reviews/2026-09-11-rbac-plan-0b-codex-gate-r8.md`.

**Read them, do not copy them into your lane.** Those documents are owned by the orchestrator's audit branch; your lane must not contain them.

**Gate r8's one minor (M8-1) is already applied in rev 6.3** — the rev-6.1 status-pass retrospective now correctly says M7-1 was an **executable PHPUnit assertion defect** fixed in rev 6.2 (the `[403, 422]` set became `assertForbidden()` + `error.code = LOCATION_ACCESS_DENIED`), with the other two r7 findings being prose propagation misses. Nothing else moved between rev 6.2 and rev 6.3. There is no outstanding gate finding for you to apply.

---

## Commits

Fifteen commits, each **path-scoped** (`git add <explicit paths>` — never `git add -A`, never `git add .`, never `git commit -a`), each carrying **both trailers** exactly:

```
Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01Bp1WuD39YqaMWiHR8vPDNN
```

Subjects follow `AGENTS.md:15-16` in the form **`Phase 0.2.<task>: <imperative summary>`** (wave 0a used `Phase 0.1.<task>`). Every task states its final subject in the plan; they are:

| Task | Subject | Plan line |
|---|---|---|
| 1 | `Phase 0.2.1: Add PermissionRenameMap, LegacyRoleBaseline and its two non-collapsing oracles` | `:1225` |
| 2 | `Phase 0.2.2: Add PermissionWriteLock, adopting lane/w-lot-a-1a's advisory key and row order` | `:1426` |
| 3 | `Phase 0.2.3: Add TenantFleetRunner, the one fleet iterator, with the step-1a selector contract` | `:2148` |
| 4 | `Phase 0.2.4: Add permissions:ensure(-fleet) and wire the write lock into every runtime role-grant writer` | `:4536` |
| 5 | `Phase 0.2.5: Declare credit-notes.cancel and stop the test that masked its absence` | `:4861` |
| 6 | `Phase 0.2.6: Declare eighteen permission keys, gate their routes, and guard every caller` | `:7097` |
| 7 | `Phase 0.2.7: Gate the batch delete and recall writes BESIDE BatchActionAccess` | `:7191` |
| 8 | `Phase 0.2.8: Gate the counting-item submit on can:inventory.adjust` | `:7256` |
| 9 | `Phase 0.2.9: Name the satisfying permissions in RequireAnyPermission's 403 details` | `:7434` |
| 10 | `Phase 0.2.10: Delete PermissionSeeder, its production caller and two tests; re-point five` | `:7493` |
| 11 | `Phase 0.2.11: Add the Identity and authorization glossary rows on top of the lane's General-manager row` | `:7537` |
| 12 | `Phase 0.2.12: Register the route-coverage protected blob so the anti-growth direction fails closed` | `:7595` |
| 13 | `Phase 0.2.13: Add the convention-09 second-company and second-location rows for the new permission layer` | `:8296` |
| 13b | `Phase 0.2.15: Gate the four role/permission read routes and the pages that call them (I-18)` | `:8565` |
| 14 | `Phase 0.2.14: Recompute the coverage ceilings and lane manifest, regenerate the frontend map, and add the 0b deploy wrapper` | `:8772` |

Use each task's **staging list and commit body as the plan writes them** — only the subject line is governed by `AGENTS.md`. Note in particular that **`permissionsMap.generated.ts` is staged in Task 6**, not only Task 14.

**No push. No merge. No rebase onto anything. No `git stash`.** Stay on `lane/rbac-w0b`.

---

## Overlap rule

The three entry merges remove the two lanes that previously blocked this wave, and the **lane cap is 3**, so `lane/rbac-w0b` opens into an empty RBAC slot. Do not open a second RBAC lane. **If a *new* lane has been opened since you checked the entry conditions**, re-run the overlap check (Reviewer gate item 10) against it before committing — any hit is a blocker. Confirm no manual test day overlaps the merge window.

---

## Staging deploy — ORCHESTRATOR-OWNED, NOT YOURS

**You do NOT run `scripts/permissions-ensure-0b.sh` against staging. You do NOT push to `origin`.** `origin/dev` **auto-deploys staging including `tenants:migrate`** — a push *is* a deploy. This wave merges into **local** `dev` and is promoted by the owner in a verified fast-forward batch.

Your deliverable is the **wrapper script and its rehearsal**, both local:

- both `permissions:ensure-fleet --dry-run` invocations: `exit=0`, a marker per tenant carrying `mode=DRY_RUN`, an aggregate line, **zero rows written**;
- `scripts/permissions-ensure-0b.sh` rehearsed with a **deliberately failed first invocation**: the status file must read `permissions_ensure=failed:invocation_1_admin_only_keys` and the script must exit non-zero — and a *successful second invocation must not be able to overwrite it with `ok`*.

Paste both rehearsals into the handback. The real two-invocation staging run is the orchestrator's, after the merge and promotion.

---

## Reviewer gates — orchestrator runs them, you do not

Three mandatory reviewers, all on the whole diff, none of them yours to run:

1. **`tenancy-authz-reviewer`** — mandatory, the primary gate (lock domain, NULL-team tolerance, `can:` correctness, the eleven prompt items, and the four open questions Q-0b-1..Q-0b-4, which must be answered and not merged as discovered decisions).
2. **`inventory-costing-reviewer`** — mandatory, for **0b-5** (batch delete + batch recall, i.e. **Task 7**) and **0b-13** (counting-item submit, **Task 8**): does gating the batch writes change *when* stock moves or only *who* may move it, and is `can:inventory.adjust` the right key for the counting submit or is `inventory.adjustments.create` truer?
3. **`frontend-conventions-reviewer`** — mandatory **and a PRECONDITION for the Task 6 and Task 13b commits, not a review of them afterwards** (owner's standing rule, and the plan states it as a precondition). **Record its verdict before you commit those two tasks.** It re-runs `scripts/census_0b.py` itself against the merged tree and diffs its output against the table in the plan.

No treasury and no fiscal reviewer: this wave touches neither module.

---

## Handback

Write `docs/handoff/HANDBACK-rbac-w0b-<date>.md` — use the date the lane actually opens; the master plan's row is a glob (the plan writes it as `HANDBACK-rbac-w0b-2026-09-10.md`). Contents are enumerated at **Task 14 step 8**, and it must carry the plan's **Verification checklist** (`:8805-8857`) as evidence, including:

- lane branch, **observed** base SHA, which bootstrap form you used (backend and frontend), and each task's commit SHA;
- **the four entry-condition check outputs** and the **Phase 0 re-derivation** results — every re-measured SHA, line number, ceiling and manifest value, next to the rev-6.3-time value it replaced;
- **red/green tails per task** — the exact first failing assertion captured before each fix, then the green run;
- the **method-level writer census** (20 + 3 lane writer methods), with no method exempted that the AST still reports as a writer, and the **six-row row-order table** reproduced;
- the PG legs, each named and each run with `-c phpunit-pgsql.xml` on `autoerp_test_r`: `TenantFleetRunnerSelectorTest` (nine runner cases + the Task 4 data-provider case), `EnsurePermissionsCustomisationGuardTest` (**nine** cases, including `a_tenant_with_no_resolvable_admin_role_is_failed_and_exits_non_zero` on **both** apply and dry-run), `EnsurePermissionsIdempotencyTest` (second run `created=0 granted=0`, **zero** row writes by query-log count), `EnsurePermissionsRaceTest` (EC-20b/EC-20c), `EnsurePermissionsFleetTest` (isolation, named failure, non-zero exit);
- the **census re-run** on the merged tree: no `UNASSIGNED` file, table C exactly one entry (`features/documents/DocumentForm.tsx:391`) **which you have READ, with your verdict**, and a row count matching the plan's 87;
- the **e2e evidence**: `rbac-module-gates.spec.ts` green with **five arms**, each with its own network *and* console capture — the manager arm **redirected to `/dashboard`** issuing **no** request to Channels/Growth/company-onboarding while reading and mutating categories normally (`GET /categories/tree` 200, `POST` 201, `DELETE` 204) — plus **both** `DELETE /api/v1/service-categories/{id}` **204** and `DELETE /api/v1/categories/{id}` **204**; and `rbac-role-read-gates.spec.ts` green with the manager arm asserting the **absence** of any `/roles` or `/permissions` request, **not** a 403;
- **ceiling arithmetic**: writes **152 → 127**, reads **146 → 142** (0b-15 first) **→ 126**, or the deviation explained;
- `php tools/feature-lane-manifest-check.php` green with ceilings **recomputed** against `dev`'s current values; `php artisan permissions:export-frontend-map` twice, **byte-identical**; `actionlint .github/workflows/ci.yml` clean; `tenant:census-day-one` clean;
- **PHPStan** `[OK] No errors` and **Pint** `--test` pass over the explicit Task 14 Step 4 list; `pnpm typecheck` and `pnpm lint` green (including the TanStack key audit);
- **Probe A (admin) and Probe B (manager)**: zero 5xx, zero new console errors, **every expected status observed per verb** — Probe B's service categories are `GET` **200**, `POST` **201**, `PATCH` **200**, with **no 404**;
- both wrapper rehearsals (dry-run pair, and the deliberately-failed first invocation);
- `git diff dev...HEAD --name-only` containing **no** path from the plan's "Deliberately NOT touched" list;
- **every commit green in isolation** — check out each task's commit and run its stated run list;
- the **residuals** R-0b-1 … R-0b-9 and R-0b-11 (R-0b-10 is **closed** by the `categories.view` ruling and struck), each with owner and wave. Call out especially **R-0b-5**: `ROUTE_COVERAGE_BASELINE_PROTECTED_BLOB` must be set by the **owner** as a repository variable, CI is red on that job until they act — **put the blob value in the handback**;
- the **ensure CLI signature change recorded for wave 1** — `EnsurePermissionsRunner::run()`'s third parameter (`list<string> $grantTo` → `array<string, list<string>> $grantMap`) and the CLI (`--grant-to` **removed**, `--grant=<key>:<role,role>` added). Wave 1 **inherits none of it**, because it *deletes* the runner, both commands and the parser trait in the commit that lands `permissions:sync` — that is **R-0b-2**. The one test-fixture change is `PermissionWriteLockCoverageTest::LOCK_INHERITED_FROM` (a **private** constant) becoming `array<string, list<string>>`, and three additions wave 1 should consume rather than re-invent are named in the plan's **Signature changes for wave 1** section;
- **deviations** — anything you did differently and why;
- the merge-readiness block the plan names (`rev-parse --short dev`, `git merge-tree --write-tree`, `git diff dev...<tip> --stat`, and the Phase-0 entry-condition and overlap checks **re-run at the merge moment**).

Then stop with **`Status: review`**. **The orchestrator runs the three reviewer gates and merges into local `dev`.** Do not merge, do not push to origin, do not run the reviewers yourself.

---

## Out of scope

- **Everything in wave 1 and later** — the permission **registry**, `permissions:sync`, `permissions:export-label-skeleton`, the template-delta applier, and the deletion of this wave's stopgap (`permissions:ensure`, `permissions:ensure-fleet`, `EnsurePermissionsRunner`, `DryRunRollback`, `AdminRoleMissing`, `EnsurePermissionsResult`, `EnsurePermissionsOutcome`, `scripts/permissions-ensure-0b.sh`). This wave writes the catalogue through `RolesAndPermissionsSeeder`'s two arrays; the registry is wave 1.
- **Anything inside the `lane/w-lot-a-1a` or `lane/t2-receipt-spine` lanes beyond consuming their merged state.** You edit *on top of* what those two lanes merged; you do not re-open, re-word or re-implement any of it. Notably: `LotActionPermissionDelta.php`, the `provisioning_source` migration, `config/lot_action_permissions.php` and the lane's `RolesAndPermissionsSeeder::run()` preservation branch are on the plan's **"Deliberately NOT touched"** list, and Task 9's message half is **verification-only** because T2 already settled the string.
- **Any new table, column, unique key or migration.** This wave adds none. `feature-lane-manifest.json` is **recomputed**, not hand-edited or textually merged.
- **A twentieth permission key.** Adding one means editing `wave0bAdditions`, the deploy `--keys` list and `Wave0bAdditionConstantTest` in the same commit — see Q-0b-2, and ask the orchestrator first.
- **The staging deploy** (above) and **any `origin` push**.
- **D4 response shaping** — a bare `users.assign-roles` holder still receives the full permission matrix where owner ruling D4 says role **names**. 0b-15 **gates** `GET /users/{userId}/roles`; it does not **shape** it. That is **wave 2a** (**R-0b-8**).
- **Location scope on batch writes** (**R-0b-3**, wave 3), **`ResetTenantCommand`'s** reset concurrency as a whole (**R-0b-4**, separate ticket — this wave makes the role assignment at its end safe, nothing more), **deleting the dead UI** `AddCompanyModal` (**R-0b-9**) or `CategoryManagementPage` (**R-0b-11**) — both are guarded here and **not** deleted.
- **French and Arabic labels** if the new module/action labels ship as English placeholders — record as **R-0b-1** for wave 1's `permissions:export-label-skeleton`; do not invent translations.

---

## Lane cap

The cap is **3**. The two lanes that blocked this wave are merged by the time you start, so `lane/rbac-w0b` is the only RBAC lane. Do not open a second one, and confirm no manual test day overlaps the merge window.
