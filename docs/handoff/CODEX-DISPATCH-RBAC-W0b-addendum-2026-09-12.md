# Addendum to the RBAC wave 0b dispatch brief (2026-09-12) — paste THIS file's path together with `docs/handoff/CODEX-DISPATCH-RBAC-W0b-2026-09-11.md` into the new Codex Desktop thread `RBAC-W0b`

The 2026-09-11 brief is still the authority (plan rev 6.3, gate r8 DISPATCH-READY). This addendum records the facts that moved since it was written. Where the two differ, this addendum wins.

## Entry condition: MET on local `dev` (measured 2026-09-12)

| Lane | Merge commit on local `dev` |
|---|---|
| `lane/rbac-w0a` (wave 0a, tip `3d583364d`) | `f90ece298` |
| `lane/t2-receipt-spine` (T-2 S1, tip `089d98e75`) | `474e38e2a` |
| `lane/w-lot-a-1a` (tip `1e19a0f37`) | `c8ad8fd40` |
| `docs/rbac-audit-2026-09-09` (the plans/spec/registers — now ON `dev`) | `a041c2315` |

Phase 0's three `git merge-base --is-ancestor` checks print `MERGED`. Re-run them anyway. `lane/imp1-history-export` is being landed after this addendum was written; it touches nothing wave 0b edits (imports module + import feature only), but re-run the overlap loop against it too: `git diff --name-only dev...lane/imp1-history-export -- <path>` for every 0b path must be empty.

## Facts that changed

- **The plan and spec now live on `dev`.** `docs/superpowers/plans/2026-09-10-rbac-wave-0b.md`, `…-rbac-programme-execution-plan.md`, `docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md` (rev 9.2) and every gate register are in your worktree once you branch off `dev`. The brief's `../rbac-audit/...` sibling-path instructions are obsolete; read the files in place. Do not edit them.
- **Seeder shape after the merges.** `RolesAndPermissionsSeeder` is W-LOT-A-1a's restructured seeder: `legacyPermissionNames()`/`legacyRolePermissionGrants()` feed the NULL-team/unmarked arms AND are spread into the delta path; T-2 S1's `inventory.transfers.reconcile`/`inventory.transfers.close` were carried into the LEGACY tier at the merge (`:281-282`, `:684`). `LotActionSeededRoleMatrixTest` pins admin/manager hashes recomputed at that merge. Wave 0b's nineteen new keys follow the plan's rule for where a new key goes; if the plan's instruction predates the W-LOT seeder shape, stop and report rather than guess.
- **Wave 0a's route-coverage ratchet is live on `dev`**: baseline 152 writes / 146 reads at `apps/api/tests/Architecture/baselines/route-permission-coverage-baseline.json`, gating alias set of five, `BatchActionAccess` deliberately excluded. Wave 0b's 0b-15 (four Identity reads) lowers reads to 142 (294 keys) exactly as the plan says; every other route wave 0b gates must move the baseline by exactly the number of routes it closes, regenerated, never hand-edited.
- **`.github/workflows/ci.yml` moved.** G0 (2026-09-11) inserted three steps into `backend-architecture` (now `:143-~264`); the first PostgreSQL allowlist alternation is at `~:1174` (230 names) and the second at `~:1303`; `all-checks-pass` at `~:2735+`. Re-measure every ci.yml line number the plan cites before editing.
- **`apps/api/tests/feature-lane-manifest.json`**: `gated_ceiling` is **1282** (+1 more once IMP-1 lands = 1283); Identity is **40**. Recompute against the live values, never textually merge.
- **PostgreSQL:** the port-5433 container named in the brief is DOWN; native PostgreSQL 15.15 answers at `127.0.0.1:5432` (credentials in `apps/api/.env`). Use `DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_test_r DB_CENTRAL_DATABASE=autoerp_test_r` (the database exists) with `-c phpunit-pgsql.xml`, one class per invocation, serially.
- **Commit trailer:** `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>` only; drop any `Claude-Session:` line the plan's commit blocks carry.
- **Preflight** now exits 2 when `PREFLIGHT_SCOPE=paths` has no `PREFLIGHT_TEST_PATHS` — always name your test paths.
- **Owner-owed before the origin/dev push that carries waves 0a+0b (unchanged from the wave 0a gate M-1):** existing tenants need the catalogue synced (seeder rerun or `SYNC_PERMISSIONS_ON_BOOT=true` for that deploy) then `permission:cache-reset`; wave 0b's `permissions:ensure-fleet` is precisely the tool that replaces that manual step — say in the handback whether it does.

Everything else in the 2026-09-11 brief stands: worktree `.worktrees/rbac-w0b` off `dev`, plan rev 6.3 tasks in order, red runs are deliverables, no push/merge/rebase/stash, stop at `Status: review` for the `tenancy-authz-reviewer` + `frontend-conventions-reviewer` gates (0b-15 ships page guards and a Playwright spec).
