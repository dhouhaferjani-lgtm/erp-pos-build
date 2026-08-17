I ran the full gate myself rather than reading the report. Everything substantive reproduced.

---

# M7 Adversarial Merge-Gate Review — Round 2
**Base:** `7d85232cc` → **HEAD:** `03512d82d` · **Lenses:** treasury, tenancy-authz, frontend-conventions
**Owner authority:** `docs/handoff/reviews/country-defaults-phase-a/OWNER-RESUMPTION-M7-2026-08-17.md` (accepted as the resumption ruling; it bounds the fixture to a disposable DB and leaves G1–G5 open)

## What I verified independently (not taken from the report)

- **Backend union, both engines, reproduced to the assertion.** I extracted the 57 literal paths from the amended `$PHASE_A_TESTS` block and ran them by path: SQLite **357 passed + 10 skipped / 2,388 assertions**; PostgreSQL (`phpunit-pgsql.xml`, real PG on 5432) **367 passed / 2,489 assertions**. Both match the report's round-two figures exactly.
- **Static scope is now complete.** `git diff 7d85232cc..HEAD -- apps/api` changes **93** production PHP files; I mechanically matched every one of the 93 against the 36 scope roots — **zero uncovered**. `phpstan analyse` over the scope: `[OK] No errors`. `pint --test` over the scope: `{"result":"pass"}`.
- **Item 6 is now reproducible from the branch as committed.** I ran the committed `scripts/phase-a-authenticated-verifier-fixture.sh` end-to-end against `autoerp_country_defaults_migrate_test`: all four `2026_08_11_1003xx` migrations `DONE`, three authenticated HTTP publish+assign flows passed, `Country Defaults verification passed.`, exit 0, exit trap left no listener on 8211. Afterwards `iziposcentral` (5433) still reports **0** of the three Phase A tables — the configured central DB was not mutated.
- **Frontend:** 4 files / **42** tests green.
- **Tree is frozen:** `git status --porcelain` empty at HEAD, before and after my runs.

### Round-1 register — closure status (all verified, not accepted on assertion)

| # | R1 severity | Status |
|---|---|---|
| 1 | P1 union incompleteness | **CLOSED** — §1.1 gained the `M1–M5 compatibility` row and `$PHASE_A_TESTS` is 57 paths; both engines rerun and reproduced by me |
| 2 | P1 unattributed owner gate + untracked harness | **CLOSED** — runner committed (`scripts/phase-a-authenticated-verifier-fixture.sh`), owner resumption recorded, and I re-ran it myself to green |
| 3 | P2 vacuous assertion 8(6) | **CLOSED** — `ProvisioningFlagMatrixTest.php:36-40`. My bypass (`COUNTRY_DEFAULTS_PROVISIONING_ENABLED=true`) now **fails** the test; it did not before |
| 4 | P2 static scope gap | **CLOSED** — 93/93 covered, PHPStan + Pint clean |
| 5 | P2 `ext-intl` vs CI | **CLOSED** — all 8 `setup-php` lists carry `intl`; `grep "extensions:" .github/workflows/ \| grep -v intl` returns nothing |
| 6 | P2 unfrozen tree | **CLOSED** — clean |
| 7, 10 | P3 notes | unchanged, no action needed |

---

## Findings register (round 2)

**1 — P2 · CONFIRMED · `docs/handoff/reviews/country-defaults-phase-a/` (absent files) vs manifest item 9 + `docs/handoff/SELF-REVIEW-HARNESS.md:36,41`**
Manifest item 9 requires *"rerun any specialist reviewer who raised a finding after its final fix; **attach their final verdicts**."* The three claimed PASSes (treasury, tenancy/authz, frontend-conventions at `c6dc598c5`) exist **only** as implementer-authored prose at `docs/sessions/codex-country-defaults-phase-a-report.md:1801-1814`. `git diff --name-only 7d85232cc..HEAD -- docs/handoff` shows **no** M7 specialist artifact — the only M7 file is `M7-round1.md`, which is the adversarial register, not a specialist verdict. Every gate M0→M6 committed its reviewer files at the harness-mandated path.
*Failure scenario:* the branch is promoted on three specialist approvals that cannot be re-read, cannot be checked for scope, and cannot be confirmed to have run against the frozen diff — the same class of defect that made round-1 finding 2 a P1. Nothing in the branch distinguishes "three specialists passed" from "three specialists were not run."
*Fix:* commit the three verdict files under `docs/handoff/reviews/country-defaults-phase-a/` (harness `--out` path), or point item 9 at wherever their full output actually lives.

**2 — P3 · CONFIRMED · `docs/handoff/progress/country-defaults-phase-a.progress.yaml:88-96`**
At HEAD the M7 entry reads `status: pending`, `fix_rounds: 2`, `commit: ab86ea880`, `last_verdict: CHANGES-REQUIRED`, `verdict: …/M7-round1.md`. Round two was submitted at `c6dc598c5` and HEAD is `03512d82d`. The ledger does not describe the artifact under review, and `status` never moved to `review`. Bookkeeping only — no code impact.

**3 — P3 · CONFIRMED · `apps/api/database/seeders/TunisianParapharmacySeeder.php:462` and `apps/web/src/features/admin/country-defaults/pages/TemplateListPage.tsx:22,69-70`**
M7 declares *"no new implementation is permitted"*; this is the second consecutive round in which production code changed inside the gate (round 1 had the Pint-driven request-DTO normalization, R1 finding 7). Both changes are red-first covered and I could not break either:
- SKU scheme `rand(10,99)` → `Str::slug`: I computed all 21 explicit names — **21/21 unique**, max length **34** against `string('sku', 100)` (`database/migrations/tenant/2025_11_30_052910_create_products_table.php:20`). The obvious hazard of a deterministic scheme under `unique(['tenant_id','sku'])` (`:37`) is a re-seed collision — that bypass **FAILED**: `TunisianParapharmacySeeder.php:126-133` guards product creation behind `$productsExist`, and the company itself is looked up by `tax_id` (`:154-165`), so a rerun skips. `ParapharmacySeeder` uses a disjoint SKU scheme (`:937`), so no cross-seeder collision inside one tenant.
- `lifecycleMutationPending` correctly gates both Clone and Archive; canonical `Button`, no new strings, no token drift.
Recorded as envelope widening, not as a defect.

**4 — P3 · CONFIRMED (carry-over, disclosed) · `apps/api/database/seeders/ExpenseCategorySeeder.php:75-110`**
The loud-failure conversion is still not flag-gated: with `provisioning_enabled=false` (the Release-1 default) a company whose chart lacks a mapped expense code throws where it previously skipped. Live tenant-facing behavior change against non-goal N-D, disclosed in `OWNER-CHECKLIST-CONSOLIDATED-2026-08-01.md` §G5. Unchanged since round 1; visibility note.

**5 — P3 · CONFIRMED · `apps/api/tests/Feature/Accounting/DocumentCancellationGlReversalTest.php:326-347` vs `.github/workflows/ci.yml`**
The round-two fix narrows `connectionsToTransact()` to the single forked-concurrency case and adds a PG-only `migrate:fresh` tearDown. But `grep -c "DocumentCancellationGlReversalTest" .github/workflows/ci.yml` = **0** — the class appears in no PG lane's `--filter`, and `tests/Feature/Accounting` (`ci.yml:848`) runs under the default SQLite config, where the concurrency case self-skips (I reproduced: `1 skipped, 8 passed`). So the newly-reworked isolation and the destructive tearDown execute in **no CI gate**. Pre-existing coverage gap, not introduced here — but `ci.yml` was edited in this same round and this file's PG behavior was changed in it. The `ci.yml:600-620` comment block warns about exactly this trap by name.

**6 — P3 · CONFIRMED · `scripts/phase-a-authenticated-verifier-fixture.sh:22-29`**
The destructive allowlist is **name-only**: any `PGHOST` is accepted provided `PHASE_A_MIGRATE_DB` matches `autoerp_country_defaults_*_(test|scratch)`. A scratch-named database on a non-local host would be `migrate:fresh`-ed. Low residual risk given the name family; noted because every other guard in the runner is fail-closed.

---

## Bypasses I tried that FAILED to find a defect

- `PHASE_A_MIGRATE_DB=iziposcentral --preflight-only` → exit **64**, `Refusing non-disposable database name`.
- Inherited `DB_CENTRAL_URL=postgresql://…/iziposcentral` → exit **64** before any PHP/DB execution.
- Hostile `DB_CENTRAL_DATABASE=iziposcentral` with an allowlisted `PHASE_A_MIGRATE_DB` → runner re-pins every generic and `DB_CENTRAL_*` field, effective-config check confirms the scratch target, preflight passes cleanly (the override is neutralized, not honored).
- Re-seed collision on the new deterministic SKUs → blocked by the `$productsExist` guard.
- SKU length overflow / duplicate slug across the 21 explicit products → 21 unique, max 34 ≤ 100.
- Static-scope escape: matched all 93 changed production files against the 36 roots — none uncovered; PHPStan L8 and Pint both clean over the full scope.
- CI `config:cache` breaking the new subprocess tests (`VerifyCountryDefaultsCommandTest` refuses a cached config) → no `config:cache`/`optimize` step exists in `ci.yml`.
- Re-running the whole 57-file union on both engines to catch an inflated count → matched the report exactly on both.

## Lens coverage

- **treasury** — applied. COA/purpose content untouched this round; the union now actually executes `InstrumentAccountResolverTest`, `PayableInstrumentAccountsTest`, `SeedChartsCommandTest`, `CompanyTaxProvisioningServiceTest` and the four backfill suites that round 1 proved were excluded — all green on PG and SQLite. Assertion 8(6) is no longer half-vacuous. Rule 19: no float touches money or quantity anywhere in this round's diff; the pre-existing `CurrencyScale::bcformat($float, 3)` at `TunisianParapharmacySeeder.php:470` is present verbatim at base `7d85232cc:470` — not branch-introduced, and out of M7 scope.
- **tenancy-authz** — applied. No route, middleware, role or migration changes this round. The new central-admin-adjacent artifact is the fixture runner; its publication path traverses the real `POST /api/v1/admin/auth/login` + Sanctum-bearer publish/assign endpoints (I executed all three), and its destructive guards are fail-closed under every bypass I tried. No new named queues; no migrations added.
- **frontend-conventions** — applied. Single change is the shared `lifecycleMutationPending` busy state plus its covering test; canonical `Button`, no hardcoded strings, no token or query-key drift; 42/42 green.

## Required before merge

Only finding 1 blocks: attach (commit) the three M7 specialist verdicts at the harness path, or redirect item 9 to their actual location. Findings 2–6 are notes; 2 is worth correcting in the same commit.

VERDICT: CHANGES-REQUIRED
