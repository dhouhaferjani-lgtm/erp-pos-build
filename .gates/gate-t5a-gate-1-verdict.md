GATE VERDICT: APPROVE

I read every file in the Wave 1 diff, re-ran both new test files, and traced the surrounding code (chart seeders, `accounts` schema history, `instrument_events` creation + immutability trigger, chart-seeder country dispatch). No Critical and no Important defect survived verification. Findings below are all Minor, plus one verification-coverage caveat.

## Gate criteria — verified

**Exhaustive resolver cases, liability/system in all three charts — ✅**
`InstrumentAccountResolver.php:45,47` add both arms to a `match` with no `default`, so PHPStan L8 is the enforcement (as the plan intended). Seeds: `TunisiaChartOfAccountsSeeder.php:174-175`, `FranceChartOfAccountsSeeder.php:168-169`, `GenericChartOfAccountsSeeder.php:130-131` — all `'type' => 'liability', 'is_system' => true`. `PayableInstrumentAccountsTest.php:38-53` asserts `AccountType::Liability` **and** `is_system` on all three charts, not just code presence. Parent placement is accounting-correct: `403`/`4035` are siblings of `401` under `40` (PCG practice), not children of `401`.

**Backfill — ✅ on all four sub-criteria**
- Dry-run safe: the create path returns before any write (`:84-93`); the only other write is guarded by `! $dryRun` (`:75`). Test `:78-79` asserts the account still absent after dry-run.
- Idempotent: existing-account early-continue (`:66-83`); test runs it twice and asserts `->sole()` + `count() === 1` (`:85-87`).
- Explicit company iteration: `:29-32` iterates the `companies` table directly — no nullable `Company` param, correctly avoiding the `seeder_optional_company_container_trap`.
- Correct supplier parent: `:34-38` picks `'40'` for TN/FR else `'4000'`. I verified this **exactly** mirrors chart dispatch at `TenantInitializationService.php:195-201` and `ChartOfAccountsService.php:143-147` (both `strtoupper` + `TN`/`FR`/`default`), so the parent can never disagree with the chart that was actually seeded. Test asserts `$checks->parent?->code === '40'` (`:86`).
- Fails loudly without mutating: `:69-79` reports `wrong type`, `continue`s, and `handle()` returns `self::FAILURE` (`:127`). Test `:89-107` asserts the account is **still** `Asset` afterwards — a real non-mutation assertion, not just an exit-code check.

**Migrations rerunnable / self-guarding — ✅**
All three guard on `Schema::hasTable`/`hasColumn` before each `Schema::table` (`100000:12-20`, `100100:16-32`, `100200:16-25`), use `CREATE UNIQUE INDEX IF NOT EXISTS … WHERE … IS NOT NULL` for both partial uniques, and `100200:52-61` introspects `Schema::getForeignKeys()` before adding the FK. `test_linkage_migrations_are_rerunnable` calls `up()` twice per migration against an already-migrated schema. `down()` on `100100` drops the index before the columns; `100200` drops FK → index → column in the right order. No backfill DML, so the `instrument_events` append-only trigger (`2026_07_12_100300`) is not tripped.

**Action-key store supports replay-before-validation — ✅**
`journal_entry_id`/`movement_id` were already on the table from Phase ④ (`2026_07_12_100200_create_instrument_events.php:27-28`), so Wave 1 correctly adds only `action_key`/`semantic_digest`. `InstrumentEvent.php:54-59` gives exact-match success / mismatch-throw via `hash_equals`; `$guarded = []` (`:44`) lets the new columns be mass-assigned. Legacy rows carry `action_key IS NULL`, are excluded from the partial unique index, and are never replay anchors. Critically, the event row is written *after* the JE/movement exist, so the append-only UPDATE trigger never conflicts with recording produced ids.

**Presentation cycle starts at 1 — ✅** `100000:19` `unsignedInteger(…)->default(1)`; `PaymentInstrument.php:122` casts `integer`; test asserts `assertSame(1, …)` after `refresh()` (real DB default, not a PHP default).

**No weakening, no scope creep — ✅** `git diff --stat fd10632fb...HEAD` returns exactly the 16 reviewed files. Both test files are new (`A`); zero existing tests modified. The 6 plan-doc lines are `- [ ]` → `- [x]` only — I diffed it; no requirement text changed.

**Re-ran independently:** `php artisan test tests/Feature/Treasury/OutboundIdempotencyStoreTest.php tests/Feature/Treasury/PayableInstrumentAccountsTest.php` → **10 passed, 45 assertions**.

## Findings (all Minor)

1. `[MINOR] apps/api/app/Console/Commands/BackfillPayableInstrumentAccountsCommand.php:75` — dry-run does not preview the `is_system` promotion. The `! $dryRun` guard suppresses the write but nothing reports "would promote", and `$created` is not incremented, so a dry-run on a chart with a pre-existing non-system `403` reports "0 accounts would be created" while the real run silently writes. Matters because dry-run is the operator's only preview before a per-tenant production run. Fix: emit a `[DRY-RUN] would promote is_system` line and count it separately. (Note: the write itself is legitimate — it mirrors the seeders' own promotion at `TunisiaChartOfAccountsSeeder.php:47-57`.)

2. `[MINOR] apps/api/app/Console/Commands/BackfillPayableInstrumentAccountsCommand.php:29` — no tenant-context guard. Under db-per-tenant this must run via `tenants:run`; invoked bare against `synerivia_central` it dies with an opaque `relation "companies" does not exist`. The deploy note covers it operationally, but a `Schema::hasTable('companies')` pre-check with an explicit "run under tenants:run" message would fail readably.

3. `[MINOR → escalate before Wave 2 goes live] apps/api/app/Modules/Treasury/Application/Services/InstrumentAccountResolver.php:23-30` — `resolve()` matches on `company_id + code` only; it asserts neither `type = liability` nor `is_active`. The backfill *detects* a wrong-type `403` and refuses to repair it, but the resolver will still hand that asset account to the Wave-3 GL builders, producing a credit to an asset account and a silently wrong trial balance. This is pre-existing behaviour shared by all nine purposes and Wave 1 did not regress it, so it is not a gate blocker — but the payable purposes are the first where a wrong-type account is a *known reachable state* (the backfill leaves it in place by design).

4. `[MINOR] apps/api/database/migrations/tenant/2026_07_18_100100_add_action_key_to_instrument_events.php:24-26` + `InstrumentEvent.php:56` — `semantic_digest` is nullable with no CHECK tying it to `action_key`, while `assertSemanticDigest()` treats a null digest as a conflict. Fail-closed is the right default, but a Wave-2 code path that writes `action_key` and forgets `semantic_digest` would turn every replay into `InstrumentActionConflictException` — the exact failure the store exists to prevent, and unrecoverable because the row is append-only. Suggest `CHECK (action_key IS NULL OR semantic_digest IS NOT NULL)`.

5. `[MINOR — verification coverage] apps/api/phpunit.xml:41-42` — the green run (mine and the implementer's) is **SQLite in-memory**, not PostgreSQL. The gate asks for PG safety on the partial unique indexes and the expense FK; I verified those statically (valid PG syntax, `CREATE INDEX` is transactional, `unsignedInteger` → `integer`, `after()` ignored by `PostgresGrammar`) and rate the risk low, but I could not execute it: `phpunit-pgsql.xml` exists and PG answers on `127.0.0.1:5432`, yet this worktree's `.env` is a one-line stub (`APP_ENV=testing`) so the run dies on `role "root" does not exist`. **Cannot verify empirically.** Worth one `php artisan test -c phpunit-pgsql.xml` pass on these two files before Wave 2 stacks on the store.

VERDICT: spec ✅ + quality APPROVED

Before the next wave: nothing blocking — but land the `semantic_digest` CHECK (finding 4) and run these two test files once under `phpunit-pgsql.xml` (finding 5) while Wave 2 is still cheap to correct, and carry finding 3 (resolver must assert liability before the GL builders post) into the Task 3 acceptance criteria.
