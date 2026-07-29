# Task 8 — Productize Phase ② staging backfills as artisan commands

**Status: DONE** (research → recoverable → productized two commands, TDD, gates green).

## Research verdict: RECOVERABLE

The Phase ② staging backfill semantics are fully recoverable from the repo. Both "one-off
tinker" steps were never bespoke SQL — they were thin loops over **existing, idempotent
seeder/service code** that still ships in the tree. No semantics had to be invented.

### Sources found (exact)

1. **Chart seeding** — `docs/handoff/treasury-phase2-deploy-checklist.md` §2 (lines 18-48).
   The corrected tinker form (2026-07-12 staging remediation) is:
   ```
   tenancy()->runForMultiple(null, fn($t) =>
     Company::query()->each(fn($c) =>
       app(ChartOfAccountsService::class)->seedForCompany($c)));
   ```
   Source of truth: `app/Modules/Accounting/Application/Services/ChartOfAccountsService.php`
   `seedForCompany(Company)` → wraps the locale seeder (`Tunisia/France/GenericChartOfAccountsSeeder`)
   in a DB transaction. Additive/idempotent; adds portfolio/fee accounts (§2 table: 5312/5112,
   413, 5313/5113, 5314/5114, 6275/627, 43666/44566, 416) without replacing existing accounts,
   assigns no balance/movement/journal entry. The checklist §46 ticket explicitly asks to
   productize this as `accounting:seed-charts`.

2. **Banks backfill** — `docs/handoff/bank-directory-deploy-checklist.md` §2 (lines 26-47) +
   `🎫 Pre-launch ticket` (lines 57-59). The corrected tinker form is:
   ```
   tenancy()->runForMultiple(null, fn($t) =>
     Company::query()->each(fn($c) => (new BanksSeeder)->run($c)));
   ```
   Source of truth: `database/seeders/BanksSeeder.php` `run(?Company)` → reads
   `database/data/banks/{COUNTRY}.json` (only `TN.json` ships, 32 rows) and upserts `banks`.
   Idempotency contract (documented + verified in code lines 35-63): row existence matched on
   `(tenant_id, country_code, rib_bank_code)` — name-keyed for the null-code banks; canonical
   rows refresh ONLY `bic`/`position`/`city`; `is_custom=true` banks are never touched;
   admin-managed `is_active`/`name`/`short_name` preserved on re-run.

3. **Pattern followed** — `app/Console/Commands/BackfillPayableInstrumentAccountsCommand.php`
   (`treasury:backfill-payable-instrument-accounts`): plain `Command`, run per tenant via
   `tenants:run`, `--dry-run`, `Schema::hasTable(...)` fail-loud guard, iterates
   `companies` in the bound tenant. Its test file
   `tests/Feature/Treasury/PayableInstrumentAccountsTest.php` was the test template.

4. **The `?Company` container trap** (bank checklist §2 lines 31-33 + MEMORY
   `project_seeder_optional_company_container_trap.md`): `db:seed --class=BanksSeeder`
   silently no-ops because the container resolves the seeder's `?Company` param to an empty
   `Company`. Both new commands avoid it by iterating **explicit** `Company` Eloquent instances
   and passing each to the seeder/service — exactly what the checklist ticket asked for.

## What was built

Two artisan commands (both in `app/Console/Commands/`, matching the named pattern's location),
each delegating writes to the existing single-source-of-truth seeder/service (zero semantic
duplication):

| Command | File | Delegates to |
|---|---|---|
| `treasury:backfill-banks {--dry-run}` | `app/Console/Commands/BackfillBanksCommand.php` | `BanksSeeder::run($company)` |
| `accounting:seed-charts {--dry-run}` | `app/Console/Commands/SeedChartsCommand.php` | `ChartOfAccountsService::seedForCompany($company)` |

Shape (per the pattern command, fleet-driven via
`php artisan tenants:run <cmd> [--option=dry-run=1]` — a bare `--dry-run` ERRORS under
`tenants:run`; options must be forwarded with `--option=`):
- **Guarded:** `Schema::hasTable(...)` fail-loud error + `FAILURE` if run outside a tenant context.
- **Idempotent:** delegate to the idempotent seeder/service; re-run creates 0 net-new rows
  (proven by tests).
- **Dry-run capable:** wraps the delegated write in `beginTransaction()` … `rollBack()` and
  reports the created-count delta (before/after row count) without persisting — honest preview
  with no reimplementation of the seeder's diff logic.
- **Fail-loud + tenant-aware:** per-company `try/catch` that `Log::error`s with
  `tenant_id`/`company_id`/`country_code` context and aborts (no silent swallow); banks command
  additionally surfaces the seeder's otherwise-silent "no directory for this country" skip.
- **Constructor injection only** (rule 13): `DatabaseManager` + `BanksSeeder` /
  `ChartOfAccountsService` injected; no `app()`.
- **`@cross-tenant-by-design`** class annotation so `ConsoleCommandTenantContextTest` stays green
  (see below).

### Deliberate design choice
Followed the **plain-Command + `tenants:run`** shape of the named pattern
(`treasury:backfill-payable-instrument-accounts`) rather than the `TenantScopedCommand::forEachTenant`
"batch contract" the checklist ticket loosely suggested. Rationale: the brief pins that command
"as the pattern"; it is the newest and cleanest exemplar; and its shape is trivially testable
under the sqlite `:memory:` harness (the `forEachTenant` path branches on
`config('tenancy_resolver.db_per_tenant')` and calls `tenancy()->initialize()`, which does not
fit the single-DB test harness cleanly). Fleet invocation is identical (`tenants:run …`).

## Tests (TDD, run by path — never full suite)

`tests/Feature/Treasury/BackfillBanksCommandTest.php` (4) +
`tests/Feature/Accounting/SeedChartsCommandTest.php` (3):
- dry-run previews without writing (banks + charts)
- seeds + idempotent re-run (banks: 32 rows stable; charts: account count stable, `403` present)
- banks: re-run preserves admin `is_active`/`name` + never touches custom banks (33 total)
- banks: company with no directory file (US) reported + skipped, 0 rows
- charts: dry-run reports "would be created" then real run creates

```
php artisan test tests/Feature/Treasury/BackfillBanksCommandTest.php \
                 tests/Feature/Accounting/SeedChartsCommandTest.php
→ Tests: 7 passed (42 assertions)
```

## Quality gates (touched paths)

- `pint --test <4 paths>` → `{"result":"pass"}`
- `phpstan analyse <2 command paths> --no-progress` → `[OK] No errors`
- `php artisan list` → both commands registered.
- `ConsoleCommandTenantContextTest`: **pre-existing red** on the base branch — 7 unclassified
  commands INCLUDING the pattern command `BackfillPayableInstrumentAccountsCommand` (empty
  deferrals fixture). Not introduced by this task. Verified my two new commands are NOT in the
  unclassified list (count stays 7, neither `BackfillBanks*` nor `SeedCharts*` appears).

## Follow-ups / concerns (out of scope, flagged not fixed)

- **Pre-existing arch-test failure** (`ConsoleCommandTenantContextTest`, 7 unclassified incl. the
  pattern command). Separate from this task; a classification cleanup for another cluster.
- The checklist tickets also owe an **opening-balance backfill** command — a different track
  (not Phase ② treasury), intentionally untouched.
- No `--tenant`/`--all` flags were added (the checklist's nice-to-have); fleet drive is via
  `tenants:run`, consistent with the named pattern. Easy to add later if the operator prefers a
  self-driving single invocation.

---

## Codex Round-1 REJECT — fix round (commit after `6351fd9a3`)

Review record: `docs/superpowers/reviews/2026-07-28-burndown-task8-codex.md`. Controller
adjudicated 3 findings OUT of scope (pre-existing platform/seeder behavior, ticketed:
`tenants:run` exit-code discard; BanksSeeder custom-bank identity collision; chart seeder
unconditional parent-link re-issue). Fixed the six IN-scope items on the two commands + tests:

1. **Bank dry-run batched preview (Important).** The tenant-scoped directory meant per-company
   rollback overcounted when companies share a tenant (2×32 → reported 64). Now the WHOLE
   company batch previews inside ONE rolled-back transaction; per-company counts accumulate
   (`BackfillBanksCommand::handle` — single `beginTransaction`/`rollBack` around the loop).
   Test: `test_multi_company_same_tenant_dry_run_does_not_overcount` asserts
   `32 created, 0 updated across 2 company/companies`.

2. **Bank output honesty (Important).** Reports UPDATED rows (bic/position/city refreshes) via a
   before/after snapshot diff, in both dry-run and apply summaries.
   Test: `test_apply_reports_updated_rows_honestly` (drift a canonical bic → `0 created, 1 updated`).

3. **Chart output honesty (Important).** Reports `is_system` PROMOTIONS and `parent_id` REWRITES
   alongside creations (snapshot before/after within the preview transaction; cheap column
   compare). Summary: `N created, N promoted, N reparented`.

4. **Docs (Important).** Fixed the fleet form everywhere to `--option=dry-run=1` (bare `--dry-run`
   errors under `tenants:run`). Added an `## Operational contract` block to each docblock listing
   the grep-gate markers (`Bank directory backfill:` / `Chart provisioning:`, `[DRY-RUN]`,
   `skipped (no directory)`, `No further companies were processed.` + the paired log literals),
   `tenants:run --tenants=` scoping, the missing-directory-skip = exit-0-with-marker design, and
   the bank-FK ordering assumption.

5. **Tests (Important).** Added: delegate-throw → exit 1 + tenant-aware log literal
   (`treasury:backfill-banks failed for a company; aborting.` /
   `accounting:seed-charts failed for a company; aborting.`) + abort marker (banks via a real
   malformed `data/banks/ZZ.json` with try/finally cleanup; charts via a container-bound throwing
   `ChartOfAccountsService` subclass); multi-company same-country dry-run accuracy; chart test
   asserting ALL SEVEN Phase-② TN codes (`5312 413 5313 5314 6275 43666 416`) + a non-TN Generic
   chart case; second-apply zero-creations at command level; `across 0 company/companies` marker.

6. **Exit semantics (Minor).** exit 1 only on delegate failure / unavailable tables; skips and
   empty-company stay exit 0 with stable markers asserted in tests
   (`test_no_companies_reports_stable_marker_exit_zero`,
   `test_company_without_a_bank_directory_is_reported_and_skipped_exit_zero`).

### Fix-round gates

```
php artisan test tests/Feature/Treasury/BackfillBanksCommandTest.php \
                 tests/Feature/Accounting/SeedChartsCommandTest.php
→ Tests: 14 passed (83 assertions)

pint --test <2 commands + 2 tests>                          → {"result":"pass"}
phpstan analyse <2 commands + 2 tests> --no-progress        → [OK] No errors
ConsoleCommandTenantContextTest unclassified count          → 7 (unchanged; neither new command)
```

The Log-spy assertion follows the repo's phpstan-clean idiom
(`$logSpy = Log::spy(); assertInstanceOf(LegacyMockInterface::class, $logSpy);
$logSpy->shouldHaveReceived('error', [Mockery::on(...), Mockery::type('array')])`) from
`tests/Unit/Console/TenantScopedCommandForEachTenantTest.php`.

---

## Codex Round-2 REJECT — fix round

Full record incl. Codex's verbatim findings and the controller disposition:
`docs/superpowers/reviews/2026-07-28-burndown-task8-codex.md`.

Round 2 confirmed findings 3 and 6 FIXED, rated 1/2/4/5 PARTIALLY fixed, and raised two new
`[Important]` defects introduced by the round-1 fix commit. All six items actioned:

1. **Missing `finally` on the preview rollback (new, Important).** Round 1 moved the
   snapshot reads outside the delegate `try/catch`, so a query failure there bypassed
   `rollBack()` and could hand `tenants:run` a connection with an open — on PostgreSQL,
   *aborted* — transaction, poisoning every later tenant. Both commands now wrap the
   company loop in `try { … } finally { if ($dryRun) rollBack(); }`. Defense-in-depth with
   no dedicated test (see the record for why).
2. **Non-injective bank snapshot (new, Important).** `sprintf(… $bank->bic ?? '' …)`
   collapsed `NULL` and `''`, so a real `'' → NULL` refresh reported **0 updated**.
   `snapshot()` now returns a typed tuple, mirroring the chart command. Proven by a genuine
   RED (see below) — and note the collapse is **unreachable with today's `TN.json`**, where
   every row has a non-empty `bic`/`city`; the fix guards future directory content.
3. **Stale fleet form in THIS report (Important).** Line 63 still documented
   `tenants:run <cmd> [--dry-run]` while §4 claimed it was fixed everywhere. Corrected to
   `[--option=dry-run=1]`.
4. **Four test gaps (Important).** Exact summary markers instead of the substring
   `'0 created'` (which also matches `'10 created'`); log-context KEY assertions instead of
   `Mockery::type('array')`; a Generic-chart test that actually discriminates (it asserted
   only `413`/`416`, which TN defines too — now Generic-only `5112/5113/5114/44566` present
   AND TN-only `5312/5313/5314/6275/43666` absent); plus new dry-run bank-update and
   chart promotion/reparent reporting coverage.
5. **`ZZ.json` fixture (Minor).** Both synthetic-directory tests now assert the path is
   free before writing. SIGKILL residue accepted.
6. **Eager `get()` snapshots (Minor).** Accepted, not changed — 32 bank rows / a
   low-hundreds chart over 3-4 narrow columns.

### Self-correction worth recording

My first regression test for item 2 drifted a TN row's `city` to `''` and asserted
`1 updated` — and **passed against the unfixed code**, because the canonical value is
non-empty so the refresh was `'' → 'Tunis'` (visible under either encoding). Rewritten to
seed a synthetic `ZY.json` whose canonical `bic` is `null`:

```
with fix    → 1 passed (8 assertions)
without fix → 1 failed at "Bank directory backfill: 0 created, 1 updated" (reported 0 updated)
```

### Round-2 fix gates

```
php artisan test tests/Feature/Treasury/BackfillBanksCommandTest.php \
                 tests/Feature/Accounting/SeedChartsCommandTest.php
→ Tests: 18 passed (121 assertions)      [round 1: 14 passed / 83 assertions]

pint --test <2 commands + 2 tests>       → {"result":"pass"}
phpstan analyse <2 commands + 2 tests>   → [OK] No errors
ConsoleCommandTenantContextTest          → 7 unclassified (unchanged pre-existing red;
                                            neither new command appears)
```
