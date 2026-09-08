# Gate r1 — PR #218 "liste des comptages : le filtre affiché ment (status=active hors vocabulaire)"

- Reviewer: `inventory-costing-reviewer` (adversarial, code-grounded)
- Date: 2026-09-08
- Checkout reviewed: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/pr-218` (branch `gate/pr-218`, HEAD `09927c932`)
- Base: `origin/dev` `cdedc2830`; diff read = `git diff cdedc2830..HEAD`
- Scope of THIS gate: backend controller, backend test, `.github/workflows/ci.yml`, `apps/api/tests/feature-lane-manifest.json`, PHPStan. `apps/web/**` is a separate reviewer's gate (touched only in passing here).

## VERDICT: MERGE-WITH-FIXES

The backend change is correct, minimal and proven on both drivers. Two Major gaps are about completeness/coverage, not correctness at rest; neither regresses today's behaviour. No Blocker.

---

## What I verified (evidence)

### 1. Parity with the dashboard counters — 3 of 4 cards exact, 1 not

Dashboard counters (same controller):
- `active` — `apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:179` → `InventoryCounting::forCompany($companyId)->active()->count()`
- `overdue` — `:186-189` → `forCompany(...)->active()->where('scheduled_end', '<', now())->count()`
- `pending_review` — `:180` → `pendingReview()`
- `completed_this_month` — `:181-185` → `where('status', CountingStatus::Finalized)` + `whereNotNull('finalized_at')` + `whereMonth('finalized_at', now()->month)`

New index() branches:
- `:245` `$query->active()->where('scheduled_end', '<', now());` — **byte-for-byte the same predicate** as the dashboard `overdue` counter at `:187-188` (same scope, same operator, same `now()`).
- `:247` `$query->active();` — same scope call as `:179`.
- `:249` `$query->where('status', $statusInput)` guarded by `CountingStatus::tryFrom($statusInput) !== null` at `:248`. For `status=pending_review` this is the same predicate as `scopePendingReview` (`app/Modules/Inventory/Domain/InventoryCounting.php:212`).

`scopeActive` = the in-progress trio (`InventoryCounting.php:195-202`: `Count1InProgress`, `Count2InProgress`, `Count3InProgress`) — matches `CountingStatus::isActive()` (`app/Modules/Inventory/Domain/Enums/CountingStatus.php:21-28`). No hand-rolled status list, no magic status string in the exact-status branch: `tryFrom` is used (`:248`).

Unknown value tolerated: falls through all three `elseif`s → no `where` applied → full list. Confirmed by test `:233-244` and by local run.

Company/tenant scoping of `index()` is UNCHANGED: `InventoryCounting::forCompany($companyId)` at `:226` with `$companyId = $this->companyContext->requireCompanyId()` at `:224` — both outside the diff hunk (`git diff` shows the hunk starts at `:226`+ and only replaces the `status` block).

Single caller claim verified: `grep -rn "inventory/countings"` across `apps/web/src`, `apps/pos/src`, `packages/` returns only `apps/web/src/features/inventory-counting/api/countingApi.ts:12` for the list endpoint (the rest are e2e helpers hitting per-id sub-routes). No POS/mobile consumer can be broken by the tolerated-unknown behaviour.

### 2. Test file — real data-meaning assertions, runs green on both drivers

`apps/api/tests/Feature/Inventory/CountingIndexStatusFilterTest.php` — `RefreshDatabase` (`:38`), real `RolesAndPermissionsSeeder` (`:70`), real models, real HTTP route (`:110-111`). Every assertion is on the ROW IDS returned (`:118` maps `data[].id`), i.e. data meaning, not status codes. Seven cases: active alias, active covers all three in-progress, overdue excludes finalized-but-late, `status=overdue` ≡ `overdue=true` (set equality at `:195`), precedence, exact status verbatim, unknown tolerated.

Local run (as instructed, this file only, SQLite in-memory):
```
cd .worktrees/pr-218/apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/CountingIndexStatusFilterTest.php
OK (7 tests, 28 assertions)
```
(Note for whoever repeats this: the worktree has no `vendor/`. Symlinking the main checkout's `vendor` gives a FALSE RED — Composer's `autoload_psr4.php` sets `$baseDir = dirname($vendorDir)`, so the symlink loads the MAIN checkout's controller and 6/7 tests fail. I copied `vendor` into the worktree and ran `composer dump-autoload -o`; then 7/7 green. The 247 MB copy is left in place, gitignored by `apps/api/.gitignore`.)

CI, real PostgreSQL, this PR's run: `PASS Tests\Feature\Inventory\CountingIndexStatusFilterTest` appears in the `backend-test-pgsql` job log (run 34123092665, job 101766464785, 2026-09-07T14:13:17Z). So the class is genuinely selected AND green on the production driver — the manifest note's central claim holds.

### 3. CI + manifest — strictly additive, nothing weakened

`.github/workflows/ci.yml`: only two things change, both inside job `backend-test-pgsql` (job starts at `ci.yml:578`): six comment lines, and the `--filter` allowlist. I compared the two allowlists as SETS:
```
old 199 new 200 | removed [] | added ['CountingIndexStatusFilterTest'] | order preserved prefix: True
```
No job trigger, `if:`, `needs:`, timeout, `EXPECTED_JOBS` (`ci.yml:2766`) or check removal is touched.

`apps/api/tests/feature-lane-manifest.json`: `gated_ceiling` 1248→1249, `Inventory.classes` 125→126, plus two `*_raise_note_*` strings. `debt_ceiling` unchanged at 1. Checker run in the worktree:
```
php tools/feature-lane-manifest-check.php
tests/Feature lane manifest OK — 1513 Feature classes in 74 groups; ... every --filter entry is anchored and uniquely matched
  ⚠ PARKED ...: 70 group(s) / 1249 class(es)
```
Arithmetic and wiring are correct.

**Why a bug-fix PR touches CI, answered:** the `Inventory` feature lane is parked behind `vars.SELF_HOSTED_RUNNER_READY`, so a new class in that group would be selected by NOTHING. Adding it to the `backend-test-pgsql` allowlist is the established precedent (`StockTransferIdempotencyCollisionPostgresTest`, W4-1, G-12 — all already in that same string). This is the correct move, not a gate weakening.

### 4. "POS Tests (Vitest)" red — NOT caused by this PR

- The `pos-test` job is defined at `ci.yml:2533-2565`; it has no `needs:`, no shared step, and no input in common with the `backend-test-pgsql` hunk at `ci.yml:1113-1123`. A `--filter` string in a PHP job cannot reach a `pnpm test` in `apps/pos`.
- `git diff --name-only cdedc2830..HEAD` touches ZERO files under `apps/pos/` and no root/package/lockfile.
- The actual failure is `apps/pos/src/pages/__tests__/ReportsPage.test.tsx:174` — `screen.getByText('250.400 DT')` fired while the rendered DOM still shows `reports.dashboard.loading`. It is a two-async-states race in an UNTOUCHED test (last modified `71728ad15`, unrelated lane): `findByText('reports.netSalesExclRefunds')` resolves before the dashboard aggregate block does, and the next assertion is synchronous.
- Consistent with it being a flake: `pos-test` is **pass** on #217 and #219.
- Still: an unexplained red merge gate must be re-run green before anyone merges. Ask for a re-run of `pos-test`; if it reds twice, it is a genuine flaky-test ticket owned elsewhere, not by #218.

### 5. Other red checks on this PR — pre-existing on base

- `Backend Tests — PG-only invariants`: red on #218 AND #217 AND #219 (same base). Failures in the log are `Tests\Feature\Fiscal\*` / `Accounting\Reports\*` (RefundCompensationControllerTest, TaskPhase3AccountChargeFullFlowTest, PosCoreReceiptProjectionRefundDispositionStock…). None is `Counting*`. This PR's class PASSES inside that same red job.
- `Treasury Spine — PG-only`: red on all three PRs.
- `Frontend Lint (ESLint)`: red on all three PRs. Cause claimed by the PR body verified directly: `grep -c dueDateBeforeIssue apps/web/src/locales/{fr,en,ar}/sales.json` → `1 / 1 / 0`, and `sales.json` is not in this PR's diff. The keys this PR DOES add are at full parity: `counting.status.*` has the identical 13-key set in fr/en/ar including the new `active` and `overdue`.

### 6. PHPStan level 8 on the touched controller

```
./vendor/bin/phpstan analyse app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php --memory-limit=1G
 [OK] No errors
```

### 7. Precision contract (rule 19)

Not engaged: the diff touches no money or quantity path — no `(float)`, no `bcformat`, no scale resolution, no new persisted decimal. Confirmed by reading the whole hunk (`:229-250`).

---

## Findings

### MAJOR-1 — the 4th dashboard card still drills down to a lying list
`apps/api/.../InventoryCountingController.php:181-185` (counter) vs `:248-249` (filter), link at `apps/web/src/features/inventory-counting/pages/CountingDashboardPage.tsx:110-115`.
The "Terminés ce mois" card counts `finalized AND finalized_at NOT NULL AND month = current month`, but its `href` is `?status=finalized`, which the new code resolves to **every finalized counting ever**. The PR's stated invariant is "le filtre AFFICHÉ == le filtre APPLIQUÉ, toujours" and the body claims "parité carte↔liste **exacte**"; that claim is false for card 3 of 4, and unlike the four items in the PR's "HORS SCOPE" list this one is not declared.
Why it matters: the same drill-down deception the PR exists to kill — a card reading "3" opening a list of 200. It is strictly less bad than the reported bug (superset, not empty) and it is NOT a regression (base behaved identically), which is why this is Major and not Blocker.
Fix: either add a `finalized_this_month` alias mirroring `:182-184` (and point the card at it), or add one line to the PR's HORS SCOPE list with a ticket, so the parity claim in the body becomes true as written.

### MAJOR-2 — no second-company row in the only test that exercises `GET /api/v1/inventory/countings`
`apps/api/tests/Feature/Inventory/CountingIndexStatusFilterTest.php:50-89` provisions exactly one tenant, one company, one user.
`grep -rn "getJson('/api/v1/inventory/countings" tests/` returns **only** `CountingIndexStatusFilterTest.php:111` — this new file is the sole test in the whole suite that hits the index endpoint. `InventoryTenantIsolationTest` covers only `POST` routes on countings (`:259, :274, :289, :308, :331, :360, :400, :416, :432, :443, :563, :1408, :1464`).
Caveat, stated for honesty: `inventory_countings` is a transactional document, not one of the catalogue entities that make `docs/conventions/09-SECOND-OF-EVERYTHING.md` mandatory (the canonical list is `CATALOGUE_TABLES` in `tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php`). But rule 1 of that convention — "a list/lookup in company B never returns company A's row" — is exactly the shape of this diff, and the endpoint now has three distinct query branches whose company scoping nothing asserts anywhere.
Fix (4 lines): create a second `Company` in the same tenant with one `Count1InProgress` counting, then assert its id is absent from `status=active` and from `overdue=true`.

### MINOR-1 — the permanent manifest note misdescribes the test it justifies
`apps/api/tests/feature-lane-manifest.json` → `lanes.Inventory.raise_note_2026_09_07_pr218`: "*(status=open/closed/overdue, overdue flag, combined queries)*". There is no `open` and no `closed` in `CountingStatus` (`app/Modules/Inventory/Domain/Enums/CountingStatus.php:9-19`), and the test asserts neither. Manifest notes are this repo's audit trail for every ceiling raise; a wrong one poisons the next reviewer. Fix: reword to "`status=active` alias, `status=<exact CountingStatus>`, `overdue=true` / `status=overdue`, precedence, unknown-tolerated".

### MINOR-2 — the alias vocabulary now lives in two hand-maintained places
Controller string literals `'overdue'` (`:242`) and `'active'` (`:246`) vs the FE union `CountingStatusFilter` in `apps/web/src/features/inventory-counting/types.ts`. Neither derives from the other and no generated DTO covers it (`docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md`). Rule 9 (enums for status vocabulary) is honoured for the stored statuses via `tryFrom` but not for the two aliases. Fix (follow-up acceptable): a small backed enum `CountingStatusFilter: string { Active, Overdue }` in `Domain/Enums/`, exported to TS, so a rename cannot desync the two layers silently.

### MINOR-3 — unknown filter values are swallowed with zero signal
`:248` — `status=activee` (typo) now returns the FULL list with nothing in the response saying the filter was dropped. That is the better failure mode than an empty list and the FE whitelist means it should never happen in-product, but an API caller cannot tell "no filter" from "your filter was ignored". Related: `$request->boolean('overdue')` (`:239`) accepts `overdue=yes`/`on`/`banana`→ note `banana` is falsy for `filter_var`, but `yes`/`on` are truthy — no validation surface either way. Fix (cheap): echo the resolved filter in the response `meta` (e.g. `meta.applied_status`), which also gives the FE a machine-checkable "displayed == applied" assertion instead of a DOM-only one.

### MINOR-4 (pre-existing, adjacent, NOT introduced by this PR — do not fix in this lane)
Same method, immediately below the hunk: `:254` `$query->where('id', 'ilike', "%{$search}%")` is PostgreSQL-only syntax (would throw on the SQLite leg), and `:258-260` pipes unvalidated `sort_by` / `sort_dir` straight into `orderBy()`. Both predate the diff. Worth a separate ticket now that this method's input hygiene is on someone's mind.

---

## What to fix before merge
Add the second-company assertion to `CountingIndexStatusFilterTest` (MAJOR-2), correct the manifest raise note wording (MINOR-1), and either implement `finalized_this_month` or declare card 3 in the PR's HORS SCOPE list (MAJOR-1); then re-run the flaky `pos-test` job to green — the ci.yml edit provably cannot cause it.
