# Gate r2 — Session B lane Q-12: `treasury:orphan-census` (fix round for r1 C1/C2/C3)

- **Lens:** treasury-reviewer (adversarial, code-grounded). Round 2, focused re-gate.
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/sb-q12-treasury-orphan-census`
- **Branch / commits:** `fix/sb-q12-treasury-orphan-census` — r1 target **`45449c7a6`** → fix round **`0f8d00a2e`** ("orphan-census gate r1 C1/C2/C3 — empty directory is not a GO; pin the exit-code deviation; de-vacuum the read-only pin"), both on dev merge `258f54ef5`.
- **r1 record:** `docs/superpowers/reviews/2026-08-25-sb-q12-orphan-census-gate-r1.md` · **fix brief:** `docs/sessions/session-B-2026-08-23/BRIEF-Q12-fixround.md`
- **Diff scope — VERIFIED EXACT.** `git diff --name-only 45449c7a6..0f8d00a2e` returns **exactly two paths**: `apps/api/app/Modules/Treasury/Presentation/Console/TreasuryOrphanCensusCommand.php` (+100/−22-ish) and `apps/api/tests/Feature/Treasury/TreasuryOrphanCensusCommandTest.php` (+171). No migration, no provider change, no manifest change, no production write path.

## Verdict

**VERDICT: spec ✅ + quality APPROVED**

All three r1 blocking conditions are satisfied and — the part that matters — each is **bound to the code by a mutation probe I ran myself**: reverting the fix turns the new test RED, restoring it turns it GREEN. The C1 fix was additionally reproduced **live** on a tenant-less central database, i.e. the same way r1 reproduced the defect. Two new findings, both Minor and both about *diagnostics on an already-failing run* (never a false GO); they belong on the LEDGER, not in this lane.

## Gate verified — per file, per driver

| Check | Driver | Result |
|---|---|---|
| `tests/Feature/Treasury/TreasuryOrphanCensusCommandTest.php` | sqlite (`phpunit.xml`) | **OK 7 tests / 61 assertions** |
| same | PostgreSQL (`phpunit-pgsql.xml`, own throwaway DB `autoerp_q12r2test` @127.0.0.1:5433) | **OK 7 / 61** |
| `tests/Feature/Treasury/AuditDiscountsCommandTest.php` (provider smoke) | sqlite | **OK 11 / 25** |
| same | PostgreSQL | **OK 11 / 25** |
| PHPStan L8 — the two lane files | `./vendor/bin/phpstan analyse` (live-DB env) | **[OK] No errors** |
| Pint `--test` — the two lane files | `./vendor/bin/pint --test` | **`{"result":"pass"}`** |
| `php tools/feature-lane-manifest-check.php` | php, api root | **EXIT=0** — "1407 Feature classes in 74 groups"; `Treasury` group still `classes: 120`, lane `treasury-spine-pgsql/feature-treasury` (manifest untouched by this round) |
| Live fleet census (`.env`, PG 5433, `TENANCY_DB_PER_TENANT=true`) | php artisan | **complete=true, reason=null, visited 4 / skipped 0 / checked 32 / unresolvable 4 / orphans 0, EXIT=0** — digit-for-digit identical to r1 |
| `tests/Architecture/ConsoleCommandTenantContextTest.php` | sqlite | **RED — inherited, unchanged**; `grep OrphanCensus` over the failure output = **0 hits**, so the lane's command is still correctly classified (r1 F-6 waiver stands) |

Throwaway DBs `autoerp_q12r2test` and `autoerp_q12r2centraltest` created and **dropped** (`pg_database` re-checked after: only other sessions' `autoerp_*_test` remain, none touched). Worktree ends `git status --porcelain` **empty** — every mutation probe below was applied from a scratchpad backup and reverted; no lane file was left modified; no stash, no checkout, no push.

## C1 — empty tenant directory must not read as a GO — **SATISFIED**

Code: `TreasuryOrphanCensusCommand.php:192-207` (`incompletenessReason()`), consumed at `:159`/`:170` and surfaced at `:365-366` (`'complete' => $reason === null, 'reason' => $reason`).

**(a) Live repro, the r1 way.** Built a tenant-less but fully-shaped central DB (`autoerp_q12r2centraltest`, schema-only dump of `iziposcentral` via the container's pg16 `pg_dump`, 0 load errors, `select count(*) from tenants` = 0) and pointed the command at it:

```
CACHE_STORE=array DB_CENTRAL_DATABASE=autoerp_q12r2centraltest TENANCY_DB_PER_TENANT=true \
  php artisan treasury:orphan-census --json --limit=0
{ "command":"treasury:orphan-census", "complete":false, "reason":"no_tenants_in_directory",
  "totals":{"tenants_visited":0,"tenants_skipped":0,"columns_checked":0,"columns_unresolvable":0,"orphans":0},
  "tenants":[] }
EXIT_JSON=1   EXIT_HUMAN=1
```

r1 got `"complete":true … EXIT=0` from this exact setup. The human render also names it actionably (`renderHuman():423-427` + `reasonHint():434-444`): *"CENSUS INCOMPLETE (no_tenants_in_directory) — the central tenant directory yielded NO tenants, so nothing was measured — check DB_CENTRAL_DATABASE / the DB_* environment of this shell before re-running."*

**(b) The in-test method is honest.** `test_empty_tenant_directory_is_reported_incomplete_and_fails` (`:238-266`) empties the directory with `(new Tenant)->getConnection()->table('tenants')->delete()` — the same connection the command's `directoryTenants()` reads. Two-way proof rather than trust:
- **Mutation probe:** deleted the `visitedTenantIds() === [] → REASON_NO_TENANTS` branch from the command → the test goes **RED on the exit code**: *"a census that visited zero tenants must exit non-zero … Failed asserting that 0 is not identical to 0"* (`:250`). That is r1's F-1 artifact, reproduced in-test.
- Restored → **GREEN**. If the delete had been a no-op the tenant would have been visited and `complete` would be `true`, so the test cannot pass vacuously either.

## C2 — the accepted exit-code deviation is now pinned, through a real branch — **SATISFIED**

`test_incomplete_coverage_sets_complete_false_and_exits_failure` (`:290-315`) flips `config(['tenancy_resolver.db_per_tenant' => true])` — the opt-in idiom the harness itself documents (`phpunit-pgsql.xml:62-63`, `phpunit.xml:46-48`: *"DB-per-tenant fail-closed tests opt in via `config(['tenancy_resolver.db_per_tenant' => true])`"*). That drives `TenantScopedCommand::forEachTenantNarrowed():272-318`, whose real skip branches (`:281` probe fault, `:309` database not provisioned) fire against a tenant the single-schema harness never provisioned. **No stub, no test double.**

- The tenant genuinely lands on the SKIPPED path: asserted `totals.tenants_skipped === 1`, `tenants_visited === 0`, `reason === 'tenants_skipped'` — and confirmed by mutation probe (deleting the `skippedTenantIds() !== []` branch flips the observed reason to `no_tenants_in_directory`, i.e. the run really did have a skipped tenant to report).
- **The deviation itself is pinned:** with `executeCommand()` forced to `return self::SUCCESS`, the file goes **RED in two places** — `:250` (empty directory) and `:299` (*"incomplete coverage must fail the run"*). Both halves are asserted: `complete:false` + non-zero exit.
- **The other half of the deviation still holds:** `test_exit_code_is_success_even_when_orphans_are_found` (unchanged) stays green in every run above — FINDINGS keep exit 0. Independently re-confirmed on the live fleet (orphans reported, EXIT=0 path exercised at r1; this round the fleet is clean and exits 0).
- Nice touch worth recording: the test asserts `totals.orphans === 0` *while a real seeded orphan exists in that tenant* — the false GO in miniature, with only `complete`/`reason`/exit separating it from a clean fleet (`:311-314`).

## C3 — the read-only pin can no longer pass vacuously — **SATISFIED, and the tightening is real**

`test_command_issues_only_select_statements` (`:159-219`) now collects `$observed`, asserts `count($observed) > 0`, derives the **outer** `from` target of every observed SELECT (`preg_match('/\bfrom\s+"([a-z_]+)"/i')` on the first `from` in the statement) and requires all three DS-1 source tables, **before** `assertSame([], $offending)`.

Three probes, all run:

1. **Planted write.** `DB::table('payment_methods')->…->update(['updated_at' => now()])` inside `censusForTenant()` → **RED**: *"treasury:orphan-census must be strictly read-only … +0 => 'update "payment_methods" set "updated_at" = ? where "id" = ?'"* (`:218`). Removed → GREEN. (I saw 1 offending statement, not the implementer's 9 — that is just my planting site being outside the per-column loop; the detector's power is the same.)
2. **The substring trap the implementer claims to have closed — confirmed closed.** Made the census stop reading `payment_repositories` as a source table while every `payments` query still names `payment_repositories` inside its `not exists` subquery → **RED**: *"no SELECT was observed reading FROM payment_repositories — the census did not measure that table…"* (`:210`). A substring-based assertion would have stayed GREEN here. This is the strongest single piece of evidence in the round: the coverage assertion is genuinely outer-`from`.
3. Green baseline restored → 7/61 on both drivers, so the three-table coverage is satisfied by the real command on sqlite **and** PG (the regex is quoting-compatible with both).

The `assertGreaterThan(0, count($observed))` floor is weaker than r1's suggested "≥ 9 SELECTs", but it is now subsumed: a listener that stopped firing fails the three `assertContains` checks first, with a better message. Acceptable.

## New findings (this round)

### [Minor] `TreasuryOrphanCensusCommand.php:192-207` (N-1) — a mistyped `--tenant=` is reported as `no_tenants_in_directory`, and the hint sends the operator to the wrong place
`incompletenessReason()` checks `visitedTenantIds() === []` **before** `$exit !== self::SUCCESS`, so a `--tenant=<uuid not in the directory>` — which `forEachTenantFiltered()` correctly turns into a non-SUCCESS aggregate via `TenantScopedCommand::failIfTenantFilterUnvisited():558` — never reaches `REASON_ITERATION_FAILED`. Reproduced live against the real central DB:

```
php artisan treasury:orphan-census --json --limit=0 --tenant=11111111-1111-1111-1111-111111111111
… "complete": false, "reason": "no_tenants_in_directory" …            EXIT=1
human: CENSUS INCOMPLETE (no_tenants_in_directory) — the central tenant directory yielded NO tenants …
       check DB_CENTRAL_DATABASE / the DB_* environment of this shell before re-running.
```

*Why it matters:* the whole stated purpose of the new `reason` field (docblock `:88-91`: *"an incomplete run must say WHICH way it was incomplete"*) is defeated in the single most common single-tenant invocation error — the directory is fine, the flag is a typo, and the artifact accuses the operator's `DB_*` environment. **Not a safety defect:** exit is 1 and `complete` is false either way, and the base still prints *"Tenant … was not found in the central tenant directory"* one line above. *Fix (cheap, own commit or the DS-1 lane):* test the filter case first — e.g. `if ($tenantFilter !== null && $this->visitedTenantIds() === []) return 'tenant_filter_unreached';` — or simply move the `$exit !== SUCCESS` check above the zero-visited check when a filter was supplied.

### [Minor] `--json` is not a single JSON document when `--tenant=` misses (N-2) — **PRE-EXISTING, present at `45449c7a6` too**
The signature advertises *"emit a single machine-readable JSON document"* (`:135`). On a filter miss the base's not-found line is written to **stdout**, before the payload. Verified by stream, not by inference:

```
php artisan treasury:orphan-census --json … --tenant=<absent> 2>/dev/null | python3 -c 'import sys,json; json.load(sys.stdin)'
json.decoder.JSONDecodeError: Expecting value: line 1 column 1 (char 0)
```

*Why it matters:* a `… --json | jq` ops wrapper breaks on exactly the invocation an operator retries by hand. *Fails closed* (exit already 1, so no GO can be inferred), which is why this is Minor and not a blocker. The healthy fleet path and the skipped-tenant path are clean JSON (skips go to `Log::warning`, not the console) — I parsed both. *Fix:* route non-payload console output to stderr when `--json` is set.

## Carried-over r1 residuals — status after the fix round

- **F-4** (`PaymentMethodController.php:95,:147,:206` writes `default_journal_id` into a column with no `journals` table) — untouched, pre-existing, **LEDGER row still owed**.
- **F-5** (`sampleLimit()` docblock says negatives collapse to "counts only"; `ctype_digit('-5')` is false so they collapse to the default 10) — **not addressed**, still Minor.
- **F-7** (manifest note asserts "120 on disk" while `tests/Feature/Treasury` holds 126 files; the +1 arithmetic is right, the disk figure is inherited drift) — **not addressed**, manifest untouched this round, still Minor/housekeeping.
- **F-9** (JSON pin does not pin `tenants[].tenant_id` / `tenant_name`, nor the top-level key set — `reason` is asserted by key + null, but the payload's full top-level shape is still unpinned) — **partially improved** (`:118-119`), still Minor.
- **F-10** (only 1 of 9 predicates is exercised with a real seeded orphan) — **not addressed**, still Minor.
- **Residuals 1–5** (journals gap; three sibling uncovered uuid columns; 8 directory-less tenant databases on local; thin local census evidence — ~18 non-null values across 32 slots, so the real go/no-go is the staging/prod fleet run; no db-per-tenant test harness) — all unchanged and all still LEDGER items. In particular the lane still has **not** discharged the fleet-census evidence; it shipped the detector.

## Scope check

Treasury only, two files, both inside the lane. No migration, no provider/route/permission change, no money arithmetic anywhere in the command (no `getScale()`, no bcmath, no float — re-grepped this round), no `CompanyContext` use, no POS/fiscal/Session-A surface. Rule 19/20 hazards do not arise. Rule 13 satisfied by inheritance.

## What to fix before merge

Nothing blocking — merge as is; carry N-1 (reason misnames a `--tenant` typo) and N-2 (`--json` stdout pollution on filter miss) to the LEDGER with the r1 residuals, and keep the staging/prod fleet-census run as the lane's outstanding deploy-time obligation.
