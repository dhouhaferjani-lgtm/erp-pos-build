# Gate r1 — Session B lane Q-12: `treasury:orphan-census` (triage F5 / DS-1 census half)

- **Lens:** treasury-reviewer (adversarial, code-grounded). Round 1.
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/sb-q12-treasury-orphan-census`
- **Branch / commit:** `fix/sb-q12-treasury-orphan-census` @ **`45449c7a6`** (on dev merge `258f54ef5`)
- **Brief:** `docs/sessions/session-B-2026-08-23/BRIEF-Q12-treasury-orphan-census.md`
- **Evidence base:** `docs/handoff/TRIAGE-state-machine-audit-2026-08-23.md` §3 DS-1 (`:162-168`), §5 lane F5
- **Diff:** new `apps/api/app/Modules/Treasury/Presentation/Console/TreasuryOrphanCensusCommand.php` (404 L), `TreasuryServiceProvider.php:38,:212`, new `apps/api/tests/Feature/Treasury/TreasuryOrphanCensusCommandTest.php` (272 L), `apps/api/tests/feature-lane-manifest.json` (Treasury 119→120 + note). **No migration. No production write path.**

## Verdict

**VERDICT: spec ✅ (with one ruled deviation ACCEPTED) + quality CHANGES-REQUESTED**

The lane does what it claims: nine columns correct against the migration, orphan predicate correct, strictly read-only **proven by me in db-per-tenant mode** (not just in the single-schema test), JSON shape pinned, local fleet run independently reproduced digit-for-digit. It is blocked on three cheap conditions, all inside the two lane files — the headline one is that the command's own advertised failure mode ("a partial zero-orphans run must not read as a GO") is **not** triggered by the most likely operational miss: a run whose central directory yields **zero** tenants.

### Blocking conditions (r2)

- **C1 — `complete` must be false when `tenants_visited === 0`.** See F-1. One line in `buildPayload()`/`executeCommand()` + the human-render error branch already exists.
- **C2 — test the incomplete→FAILURE branch.** The deviation from the brief's "exit 0 always" is the lane's most consequential design decision and **nothing asserts it** (5 tests, none covers `complete:false`). Assert both: exit code non-zero and `"complete": false` (drive it via `--tenant=<uuid not in directory>`, which reaches the same return).
- **C3 — the read-only pin must not be able to pass vacuously.** `TreasuryOrphanCensusCommandTest.php:148-162` asserts `$offending === []`; it never asserts the listener observed anything. If the listener silently stopped firing the lane's central safety claim would go green forever. Add an observed-statement counter and `assertNotEmpty()`.

Non-blocking residuals are listed at the end (they belong on the LEDGER, not in this lane).

## Gate verified — per file, per driver

| Check | Driver | Result |
|---|---|---|
| `tests/Feature/Treasury/TreasuryOrphanCensusCommandTest.php` | sqlite (`phpunit.xml`) | **OK 5 tests / 39 assertions** |
| same | PostgreSQL (`phpunit-pgsql.xml`, own throwaway DB `autoerp_q12r1test` @127.0.0.1:5433) | **OK 5 / 39** |
| `tests/Feature/Treasury/AuditDiscountsCommandTest.php` (provider smoke) | sqlite | **OK 11 / 25** |
| same | PostgreSQL | **OK 11 / 25** |
| PHPStan L8 (`TreasuryOrphanCensusCommand.php`, `TreasuryServiceProvider.php`, the test) | `./vendor/bin/phpstan` (live-DB env) | **[OK] No errors** |
| Pint `--test` (same 3 files) | `./vendor/bin/pint --test` | **{"result":"pass"}** |
| `tests/Architecture/FeatureLaneManifestCheckerTest.php` | sqlite | **OK 76 / 431** |
| `php tools/feature-lane-manifest-check.php` from api root | php | **EXIT=0**, "lane manifest OK — 1407 Feature classes in 74 groups" |
| `tests/Architecture/ConsoleCommandTenantContextTest.php` | sqlite | **RED — inherited, NOT this lane** (see F-6) |

Throwaway DB `autoerp_q12r1test` created and **dropped**; no other session's `autoerp_*_test` DB touched. No file in the worktree was modified by this review; no stash/checkout/push.

## (1) READ-ONLY proof — VERIFIED, and stronger than the test proves

- Mechanism: `DB::listen` is **not** on `DatabaseManager` (no `listen` symbol in `vendor/laravel/framework/src/Illuminate/Database/DatabaseManager.php`); it forwards via `__call` (`DatabaseManager.php:485`) to `Connection::listen` (`vendor/.../Database/Connection.php:1075-1078`), which registers on the **shared event dispatcher** (`$this->events?->listen(QueryExecuted::class, …)`). Every connection the manager builds gets that same dispatcher (`DatabaseManager.php:242-244`). ⇒ a listener registered before the Stancl swap **does** see statements issued on the post-swap tenant connection. The reviewer objection ("a listener on `central` would miss tenant-side writes") does **not** hold.
- The test itself runs in single-schema compat mode (`phpunit.xml:49` / `phpunit-pgsql.xml:64` force `TENANCY_DB_PER_TENANT=false`), so `tenancy()->initialize()` never runs inside it. I therefore ran the real thing: booted the worktree app with `TENANCY_DB_PER_TENANT=true` (worktree `.env:32`, PG 5433), registered a collector, and called the command over the live local fleet → **261 statements, 0 non-SELECT** (excluding nothing; first statements `select * from "tenants"`, then `SELECT datname FROM pg_database WHERE datname = 'tenant…'`). No `BEGIN`, no `SET`, no DDL.
- **Mutation/liveness probe (the guard is live, not decorative):** rather than editing the lane file (forbidden by the gate rules), I proved the detector's power at its weakest point — after the connection swap. With the same collector registered, `tenancy()->initialize($tenant)` then a no-op `DB::table('payment_methods')->where('id', <random uuid>)->update([...])` produced `PROBE_UPDATES_CAUGHT=1` (`update "payment_methods" set "updated_at" = ? where "id" = ?`). A planted UPDATE inside the command would be caught by exactly the regex at `TreasuryOrphanCensusCommandTest.php:154`. **Caveat that becomes C3:** the assertion is `assertSame([], $offending)` only — it cannot distinguish "no writes" from "listener never fired".

## (2) The nine columns — derived independently, EXACT

Derived from `apps/api/database/migrations/tenant/2025_11_30_120000_create_treasury_tables.php` (not from the triage note), cross-checked against triage DS-1 `:163`.

| # | Column (migration line, verified by me) | Target in code `TreasuryOrphanCensusCommand.php:99-107` | Target table exists? | Verdict |
|---|---|---|---|---|
| 1 | `payment_repositories.location_id` **:34** | `locations.id` | yes (`2025_11_30_105000_create_locations_table.php`) | correct |
| 2 | `payment_repositories.responsible_user_id` **:35** | `users.id` | yes (`2025_11_30_000003_create_users_table.php`) | correct — writer validates `ScopedExists::tenant('users',…)` at `PaymentRepositoryController.php:93,:161` |
| 3 | `payment_repositories.account_id` **:38** | `accounts.id` | yes (`2025_11_30_090000_create_accounts_table.php`) | correct |
| 4 | `payment_methods.default_journal_id` **:75** | `journals.id` | **NO** | correct to list; reported `target_table_missing` (see F-4) |
| 5 | `payment_methods.default_account_id` **:76** | `accounts.id` | yes | correct |
| 6 | `payment_methods.fee_account_id` **:77** | `accounts.id` | yes | correct |
| 7 | `payments.instrument_id` **:144** | `payment_instruments.id` | yes (same migration) | correct |
| 8 | `payments.repository_id` **:145** | `payment_repositories.id` | yes | correct |
| 9 | `payments.journal_entry_id` **:160** | `journal_entries.id` | yes (`2025_11_30_100000_create_journal_entries_table.php`) | correct |

**None missing, none extra, all nine line numbers exact.** The migration's own FK-bearing siblings (`:110` `repository_id` on `payment_instruments` is `foreignUuid(...)->constrained`) are correctly NOT in the census.

**Orphan predicate — correct.** `censusForColumn():210-220` = `whereNotNull(col)` (`sourceQuery():244`) `AND NOT EXISTS (SELECT 1 FROM target WHERE target.id = source.col)`. That is exactly what `ADD CONSTRAINT FOREIGN KEY` validates: NULLs excluded, no scope, no company narrowing on the target side (an FK does not narrow by company either — correct). Source-side `tenant_id` narrowing (`:246-248`) is guarded by `Schema::hasColumn` and is a no-op under db-per-tenant. Sample query is deterministic (`orderBy(id)`, `:268`) — diffable across runs, which a go/no-go artifact needs.

## (3) Tenant iteration / rule 20 — VERIFIED

- Extends `TenantScopedCommand` (`:77`), drives `forEachTenantFiltered()` (`:128`) → `forEachTenantNarrowed()` (`TenantScopedCommand.php:261-361`): directory-narrowed query, database-existence probe, per-tenant `tenancy()->initialize()`/`end()`, continue-on-throw.
- **No `CompanyContext` use** — the base injects it (`TenantScopedCommand.php:70`) but the command never calls `setCompanyId()`; grep over the command for `app(|getScale|CurrencyScale|bc*(|float|companyContext` returns **nothing**. No money arithmetic at all ⇒ rule 19/20 scale-resolver hazard does not arise. Rule 13 (constructor injection) satisfied by inheritance; no `app()` helper.
- Not scheduled (grep for `orphan-census` in `routes/`, `app/Console/`, `bootstrap/` → no hits) — consistent with the docblock `:75`.

## (4) Exit-code deviation — RULED: **ACCEPT the deviation**

The brief says "exit code 0 always (census, not a gate)" (`BRIEF-Q12…:27`). The implementation returns FAILURE when coverage is incomplete (`:144-155`).

**Ruling: accept.** The brief's rationale for exit 0 is "don't paint an operator's pipeline red for FINDINGS" — that property is preserved exactly (`test_exit_code_is_success_even_when_orphans_are_found`, `:164-169`, and my own fleet run with a seeded orphan on PG). What the implementation adds is orthogonal and strictly safer for the artifact's declared consumer: the DS-1 constraint lane reads this JSON as a **go/no-go**, and `ADD CONSTRAINT` aborts a per-tenant migration mid-fleet on the first orphan. A run that silently missed tenants and reported zero is the exact false GO that would strand the fleet in mixed schema state (triage `:167`, the S-16 precondition pattern). An exit code is the only signal a CI/ops wrapper reads without parsing JSON.
**But acceptance is conditional on the deviation being complete and tested** — hence C1 (it does not fire on the zero-tenant case) and C2 (it is asserted nowhere).

## (5) Soft-deleted targets — RULED: **no `dangling_soft_deleted` state; keep as-is**

The docblock `:48-51` argues soft-deleted targets must not count as orphans. Correct in principle — **and moot in fact**: on a live local tenant DB, `information_schema.columns` returns **zero rows** for `deleted_at` across all three source tables and all six target tables (`locations`, `users`, `accounts`, `journal_entries`, `payment_instruments`, `payment_repositories`, `payments`, `payment_methods`). None of them uses `softDeletes()` in its create migration either. There is no soft-deleted-target population to distinguish today, so a `dangling_soft_deleted` status would be dead reporting surface. **Do not add it.**
The query-builder-over-models choice remains right for a different and still-live reason: models carry global scopes, casts and observers (company scoping in particular) that could hide a row Postgres will still see during FK validation. Keep it, and keep the docblock — but it should say the soft-delete case is *hypothetical in the current schema*, not describe it as a live hazard.

## (6) `journals` — CONFIRMED ABSENT, and there IS a live writer

- `grep "Schema::create('journals'" database/` → **no match anywhere** in 570 migrations. No model, no factory. `payment_methods.default_journal_id` (`:75`) points at nothing.
- Reported honestly as `status: 'target_table_missing'`, `orphans: null`, with the non-null population surfaced (`:195-208`) and pinned by the test (`:143-145`). Correct call — a zero here would have been a false GO. 8 of 9 columns are FK-shippable.
- **A writer exists** (this is a finding, F-4): `PaymentMethodController.php:95` (store) and `:206` (update) validate `default_journal_id` as `['nullable','uuid']` — no `exists` rule, because the previous `exists:journals,id` 500'd — and the value is persisted at `:147` (store) and via `$method->update($validated)` (update path). The API therefore **accepts and stores an arbitrary UUID into a column with no referent, today**.

## (7) My own census run — reconciles with the implementer's table, digit for digit

`CACHE_STORE=array php artisan treasury:orphan-census --json --limit=5`, worktree `.env` (PG 5433, `TENANCY_DB_PER_TENANT=true`), 2026-08-25:

```
complete=true  tenants_visited=4  tenants_skipped=0  columns_checked=32  columns_unresolvable=4  orphans=0
01a03028…f1cf1 ParaBio Tunisie SARL                     — account_id nn=2, payments.repository_id nn=1, journal_entry_id nn=1, all orphans=0
01a033c6…656b71 Parapharmacie Élégance & Santé SARL      — account_id nn=2, everything else nn=0, orphans=0
01a034af…bf6ab5 Parapharmacie Khémira & Frères SARL      — account_id nn=2, everything else nn=0, orphans=0
01a035ba…7e1d06 Parapharmacie Oléa & Frères SARL         — account_id nn=3, payments.repository_id nn=4, journal_entry_id nn=4, orphans=0
default_journal_id: target_table_missing on all 4 tenants (non_null=0)
```

Implementer claimed "4 tenants, 0 skipped, 0 orphans across 32 checked slots, 4 unresolvable" — **matches exactly**.
**Honesty note that must travel with the number:** the entire local fleet holds ~**18 non-null values across 32 checked slots** (13 of them `account_id`). A local "0 orphans" is nearly vacuous as go/no-go evidence. The staging/prod fleet run is the real gate, and it is a LEDGER deploy-time obligation, not something this lane discharged.

## Findings

### [Important] `TreasuryOrphanCensusCommand.php:144-155` (F-1) — an empty tenant directory reports `"complete": true` + exit 0
`$complete = $this->skippedTenantIds() === [] && $exit === self::SUCCESS;` — with **zero** directory rows, `forEachTenantNarrowed()` (`TenantScopedCommand.php:270`) never enters the loop, both lists stay empty and the aggregate stays SUCCESS. **Reproduced**, not theorised — pointing `DB_CENTRAL_DATABASE` at an empty (migrated, tenant-less) database:

```json
{"command":"treasury:orphan-census","complete":true,
 "totals":{"tenants_visited":0,"tenants_skipped":0,"columns_checked":0,"columns_unresolvable":0,"orphans":0},
 "tenants":[]}          EXIT=0
```

*Why it matters:* a wrong `DB_CENTRAL_DATABASE`/`DB_*` export in an ops shell — the single most common deploy-time mistake — produces the exact artifact the FK lane is told to read as GO: complete, zero orphans, exit 0. That is the failure class the command's own docblock (`:61-67`) says it exists to prevent, and the one it does not prevent.
*Fix:* `$complete = $this->visitedTenantIds() !== [] && $this->skippedTenantIds() === [] && $exit === self::SUCCESS;` (or a distinct `"reason": "empty_directory"`), plus surface `directory_tenants` in `totals`. **C1.**

### [Important] `TreasuryOrphanCensusCommandTest.php:68-169` (F-2) — the accepted exit-code deviation is untested
Five tests: clean tenant, seeded orphan, JSON shape, read-only, exit-0-on-findings. **None** exercises `complete:false` / exit FAILURE. The deviation from the brief's literal contract is precisely the behaviour a future reader will trust and a future refactor will silently drop. *Fix:* add a test driving `--tenant=<uuid absent from the directory>` (reaches `failIfTenantFilterUnvisited` → non-SUCCESS → `complete:false`) and assert both the payload flag and the exit code. **C2.**

### [Important] `TreasuryOrphanCensusCommandTest.php:148-162` (F-3) — the read-only pin can pass vacuously
`assertSame([], $offending, …)` is satisfied by an empty observation set. The listener firing is an unasserted precondition of the lane's core safety claim. (I confirmed empirically that it does fire — 261 statements observed in db-per-tenant mode — so this is a durability defect, not a live false-green.) *Fix:* collect all observed statements, `assertNotEmpty($observed)` and assert a plausible floor (≥ 9 SELECTs for nine columns). **C3.**

### [Important] `PaymentMethodController.php:95,:147,:206` + `$method->update($validated)` (F-4) — live writer into a column with no target table — **PRE-EXISTING, not this lane**
The store/update endpoints accept any UUID for `default_journal_id` and persist it. Combined with (6): the value can never be validated, never be constrained, and the census can only ever say `target_table_missing`. *Why it matters:* it is a permanent hole in the DS-1 constraint programme — 1 of 9 columns is unconstrainable indefinitely while the API keeps accepting new garbage into it. *Suggested fix (own lane, NOT here):* either drop the field from the request contract (and later the column), or introduce the Accounting `journals` table. **LEDGER row required.**

### [Minor] `TreasuryOrphanCensusCommand.php:393-403` (F-5) — docblock contradicts the code on negative `--limit`
"negatives collapse to 'counts only'" — but `ctype_digit('-5')` is false, so `sampleLimit()` returns `DEFAULT_SAMPLE_LIMIT` (10), i.e. negatives collapse to the *default*, not to counts-only. Harmless behaviour, wrong comment; fix the comment (or the branch). Also `--limit=abc` silently becomes 10 rather than a usage error — acceptable for a census, worth one clarifying word.

### [Minor] `tests/Architecture/ConsoleCommandTenantContextTest.php:81` (F-6) — RED, **inherited, not this lane**
Fails listing 12 unclassified commands: `ScanPercentScaleDrift`, `ExportFrontendPermissionsMap`, `ConfigureMethodRepositoryRoutingCommand`, `BackfillPayableInstrumentAccountsCommand`, `BackfillInventoryShrinkagePurposesCommand`, `BackfillTaxDetailsCommand`, `RunEnrichmentCommand`, `BackfillLocationAttributionCommand`, `BackfillMembershipsCommand`, `VerifyImpersonationAuditCommand`, `ExpireSupportAccessCommand`, `ReconcileImpersonationAuditCommand`. **`TreasuryOrphanCensusCommand` is NOT in the list** — it extends `TenantScopedCommand` and is correctly classified. Record as an inherited-red waiver; do not let this lane absorb it.

### [Minor] `apps/api/tests/feature-lane-manifest.json` Treasury note (F-7) — "120 on disk" is not the disk count
`tests/Feature/Treasury` holds **126** `.php` files, each declaring exactly one concrete `…Test` class, no subdirectories, no abstract classes (125 at the base commit `258f54ef5`, whose manifest already said 119). The +1 arithmetic is right and the drift is **inherited** (6 classes), but the note asserts a disk figure that is false. The field is informational for this group anyway — the lane `treasury-spine-pgsql/feature-treasury` selects the whole directory (`selector: ./vendor/bin/phpunit tests/Feature/Treasury`, `runs_on_pr_dev: true`), so the new test **will** run on PG in CI regardless. Reword the note ("+1 relative to the recorded 119; the recorded figure has pre-existing drift vs disk") or fix the base figure in its own housekeeping lane.

### [Minor] `TreasuryOrphanCensusCommand.php:210-226` (F-8) — production cost of the anti-join is unbounded by anything but table size
Three anti-joins over `payments` (indexes are `(tenant_id,partner_id)`, `(tenant_id,payment_date)`, `(tenant_id,status)` — none on `repository_id`/`instrument_id`/`journal_entry_id`), so each column is a full scan + hash anti-join, ×9 columns ×N tenants, on production databases. Read-only and non-locking, so this is an operations note, not a correctness defect: the LEDGER row that arms the fleet run should say off-peak + `statement_timeout`, and `--limit=0` for the first pass.

### [Minor] `TreasuryOrphanCensusCommandTest.php:102-146` (F-9) — JSON pin covers columns, not the tenant envelope
The shape test pins `command`, `generated_at`, `complete`, the five `totals` keys, the nine column pairs and each column's eight keys, plus the `target_table_missing` state — good. It does **not** pin `tenants[].tenant_id` / `tenant_name`, which is what a fleet consumer joins on. One `assertSame(['columns','tenant_id','tenant_name'], sortedKeys($payload['tenants'][0]))` closes it.

### [Minor] `TreasuryOrphanCensusCommandTest.php:82-100` (F-10) — 1 of 9 predicates is exercised with a real orphan
Only `payments.repository_id`. Attribution is checked negatively for two siblings, which is decent, but a mistyped `target_table` on, say, `responsible_user_id` would surface as `target_table_missing` (visible) while a mis-mapped-but-existing target would report a silent zero. A single extra seeded orphan on a `payment_repositories.*` column would cover the other source table.

## Residuals (LEDGER, not this lane)

1. **`journals` gap** — no table, no model, live writer (F-4). 8/9 DS-1 columns are FK-shippable; column 4 is unconstrainable until Accounting ships `journals` or the field is retired. The DS-1 constraint lane must carry this as an explicit carve-out, not discover it.
2. **Sibling unconstrained uuid columns the census does NOT cover** — the same three tables gained more bare `uuid()` columns after 2025: `payments.location_id` (`2026_07_16_110000_add_location_id_to_payments_and_instruments.php:20`), `payment_instruments.location_id` (`:28`), `payments.fiscal_event_id` (`2026_05_14_100006_add_origin_and_fiscal_event_id_to_payments.php:45`). Scope-correct for this lane (the brief says DS-1's nine), but the command's docblock calls its output "the go/no-go input to the constraint lane" (`:36-37`) — an FK lane that constrains *treasury uuid columns* would be flying blind on three of them. Either widen the census in the constraint lane or state the coverage boundary in the JSON/docblock. (Counter-evidence that the omission is deliberate elsewhere: `payment_repositories.bank_id` `2026_07_12_111000:18` and `payment_methods.default_repository_id` `2026_07_19_100000:14-17` **are** `foreignUuid(...)->constrained` — the FK-clean trajectory the triage noted at `:164`.)
3. **Directory-less tenant databases — CONFIRMED on local.** `pg_database` holds **12** `tenant*` databases; the central `iziposcentral.tenants` directory holds **4**. Eight databases have no directory row: `tenant019fbe86-…`, `tenant019fcf48-…`, `tenant019fe276-…`, `tenant01a01b77-…`, `tenant3f16ac36-…`, `tenant4c3a1260-…`, `tenantbe3cd47a-…`, `tenantf6c592ac-…`. Zero directory rows lack a database (the reverse direction is clean).
   **Ruling: not a defect of this lane, and not an FK-lane hazard** — `tenants:migrate` iterates the same central directory, so a directory-less database is never migrated and never served (resolution is directory-driven). It is a **deploy-hygiene hazard worth one LEDGER line**: on staging/prod, a surviving database for a deleted tenant row is fiscal/GL data outside every governance path (no migrations, no census, no retention, no chain verification) while still holding readable rows. Recommend a deploy-time `pg_database` vs `tenants` reconciliation check before the fleet census run — locally these are demo leftovers, but the same query on staging must return empty or be explained.
4. **Local census evidence is thin** — ~18 non-null values across 32 slots (see §7). The lane's product is the *detector*; the go/no-go number is still owed by the fleet run. Arm it as a LEDGER staging-owes row modelled on S-16, per triage `:168`.
5. **Test-mode blind spot** — both phpunit configs force `TENANCY_DB_PER_TENANT=false`, so no test ever exercises the command with a real connection swap. I covered it manually this round; it will not be covered on the next change. Not fixable in this lane (no db-per-tenant test harness exists), but worth knowing that the read-only pin's CI coverage is single-schema only.

## Scope check

Treasury only. No migration. No production write path. No POS/fiscal/Session-A matrix surface touched. `TreasuryServiceProvider.php:38,:212` is a two-line `commands([...])` registration inside the existing `$this->app->runningInConsole()` block. Manifest note carries the lane attribution.

## What to fix before merge

C1 (`complete=false` when zero tenants visited) + C2 (test the incomplete→FAILURE branch) + C3 (read-only pin must assert it observed statements); then re-gate — everything else is a LEDGER residual.
