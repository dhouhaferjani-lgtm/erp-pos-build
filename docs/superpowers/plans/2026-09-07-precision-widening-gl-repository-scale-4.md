# Lane brief — precision widening: GL lines and repository balances to decimal(15,4) (rev 1)

Lane slug: `precision-4` · branch `lane/precision-4` · worktree `.worktrees/precision-4` (off local `dev`, `db8fb1471`).
Ticket: `docs/superpowers/tickets/2026-09-06-gl-and-repository-balance-columns-scale-2-precision-debt.md`.
Owner rulings: `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md` A1 + A1 follow-up (2026-09-06, later).
Reviewers (code gate): `treasury-reviewer` + `stock-gl-interaction-reviewer`. Plan gate: Codex CLI read-only.
Consumer: W-CASH-1 P0 prerequisite (`docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md` §P0) — that plan still says (15,3)/`1.005`; the orchestrator session carries the (15,4)/`1.0005` correction into its next fix round. This lane is the implementation of that P0, program-level.

## 0. Premise correction (verified 2026-09-07, local dev + live tenant DB)

The ticket says the four columns are `decimal(15,2)` and "no later widening migration exists". That is **false**:

- `apps/api/database/migrations/tenant/2026_03_11_200000_widen_monetary_columns_to_scale_3.php` widens `journal_lines.debit/credit` (`:27-30`) and `payment_repositories.balance/last_reconciled_balance` (`:113-116`) to `decimal(15,3)`.
- Live shape on local tenant DB `tenant019fbe86-…` (PG 5433, `information_schema.columns`), 2026-09-07:

| column | precision | scale | nullable | default |
|---|---|---|---|---|
| `journal_lines.debit` | 15 | 3 | NO | `'0'::numeric` |
| `journal_lines.credit` | 15 | 3 | NO | `'0'::numeric` |
| `payment_repositories.balance` | 15 | 3 | NO | `'0'::numeric` |
| `payment_repositories.last_reconciled_balance` | 15 | 3 | YES | NULL |

- Consequence: the ticket's "TND third decimal is rounded at rest" narrative does not hold on a fully migrated tenant. The W-CASH-1 gate r3 already recorded this correction (plan rev 4 line 30). **The lane stands on the owner's A1 follow-up ruling only**: money storage capacity becomes 4 decimals maximum (4-decimal currencies / accounting practice), precision used stays the country preset (`countries.currency_decimal_places` via `CurrencyScaleResolver`, `apps/api/app/Shared/Infrastructure/CurrencyScaleResolver.php:31-40`).
- Model casts today: `JournalLine` `decimal:3` (`apps/api/app/Modules/Accounting/Domain/JournalLine.php:54-55`); `PaymentRepository` balance is a port-managed numeric-string (`apps/api/app/Modules/Treasury/Domain/PaymentRepository.php:36-38`, trigger `2026_07_08_160000_forbid_direct_payment_repository_balance_writes.php`).
- Precedent for scale-4 money storage in this repo: `pos_shifts.*` `decimal(16,4)` with `decimal:4` casts (`apps/api/app/Modules/POS/Domain/Shift.php:91`, guard `tests/Unit/POS/PosShiftsScale4Test.php`).

## 1. Scope — exactly this, nothing more

Widen **only** these four columns from `decimal(15,3)` to `decimal(15,4)`, keeping nullability and defaults byte-for-byte:

- `journal_lines.debit` → `decimal(15,4) NOT NULL DEFAULT 0`
- `journal_lines.credit` → `decimal(15,4) NOT NULL DEFAULT 0`
- `payment_repositories.balance` → `decimal(15,4) NOT NULL DEFAULT 0`
- `payment_repositories.last_reconciled_balance` → `decimal(15,4) NULL`

Plus: a read-only pre-widen census command, a raw `1.0005` round-trip test, a full-tuple schema ratchet, the precision-contract doc update, a follow-up ticket listing every other money column still below scale 4, and the non-additive push plan (documented, **not pushed**).

**Stated engineering assumptions (Codex gate: challenge them; owner may veto on return):**

- **A. Eloquent casts stay `decimal:3`** on `JournalLine` (and every other Treasury/Accounting model). Reason: every writer formats at the country scale (max 3 in the seeded presets), so no 4th decimal can be written today; 153 test assertions compare 3-decimal literals against `->debit/->credit/->balance`; changing casts is a separate "casts follow storage" lane, to be opened when a 4-decimal country preset is seeded. The contract doc records this as an explicit, tracked gap, not a silent one. The raw round-trip test therefore reads with `DB::table(...)`, never through the model.
- **B. FormRequest regex ceilings stay `{1,3}`** (rule 19) — the input ceiling is the country preset, not the storage capacity.
- **C. `repository_movements.amount/balance_after`, `accounts.balance`** and every other scale-3 money column are NOT widened here (owner: "only the four named columns … the rest become a follow-up ticket"). The census output is the ticket body.
- **D. `down()` is a forward-only no-op** (manifest: never roll a migration back over fiscal/GL history; W-CASH-1 P0: "no automatic schema downgrade on rollback").
- **E. Fresh tenants**: the CREATE migrations are left untouched (already-provisioned tenants ran them); the new migration is the single place that establishes scale 4, and it must be idempotent (`already_compliant`).

Do NOT touch: `TreasuryMovementService`, `RepositoryTransferService`, `PaymentRepository` schema beyond the two column types, `BatchExpiry`, counting, `StockTransferService`, `PosCoreReceiptProjection` (owned by the lot/cash slices).

## 2. Tasks (TDD, red first; ≤6)

### T1 — Census command (read-only): `treasury:census-money-precision`

Files (new):
- `apps/api/app/Modules/Treasury/Presentation/Console/MoneyPrecisionCensusCommand.php` — extends `App\Console\TenantScopedCommand` (`apps/api/app/Console/TenantScopedCommand.php`), constructor `__construct(CompanyContext $companyContext, MoneyPrecisionCensusService $census)` calling `parent::__construct($companyContext)`; `executeCommand(): int`. Signature `treasury:census-money-precision {--tenant=} {--json}`. No `--tenant` = iterate every tenant via `forEachTenant()` (a-per-tenant-iter); `--tenant=<uuid>` = that tenant only. Never run under `tenants:run` (it aggregates tenancy itself). Register in `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php` `$this->commands([...])` (`:237`).
- `apps/api/app/Modules/Treasury/Application/Services/MoneyPrecisionCensusService.php` — `inspect(string $tenantId): MoneyPrecisionCensusData`; one read-only `REPEATABLE READ` transaction on the bound tenant connection; no writes anywhere.
- `apps/api/app/Modules/Treasury/Application/DTOs/MoneyPrecisionCensusData.php` — `__construct(string $tenant_id, string $database_name, bool $complete, int $measurable_drift_count, array $checks, array $columns_below_scale_4)`; `checks` is `list<MoneyPrecisionCheckData>`, `columns_below_scale_4` is `list<MoneyColumnShapeData>`.
- `apps/api/app/Modules/Treasury/Application/DTOs/MoneyPrecisionCheckData.php` — `__construct(string $check, int $examined_count, int $drift_count, array $sample_ids)` (`sample_ids` `list<string>`, sorted, first 20).
- `apps/api/app/Modules/Treasury/Application/DTOs/MoneyColumnShapeData.php` — `__construct(string $table, string $column, int $precision, int $scale, bool $nullable, ?string $default, string $classification)`; `classification` is a PHP enum `MoneyColumnClassification { Money, Percent, Quantity, Geometry, Other }` (new, `apps/api/app/Modules/Treasury/Domain/Enums/`).

Checks (names are fixed report labels):
1. `target_shape` — the four target columns' full tuple `(numeric_precision, numeric_scale, is_nullable, normalized default)` from `information_schema.columns`; drift = any tuple ≠ expected pre-widen `(15,3,NO,0)/(15,3,YES,NULL)` **and** ≠ expected post-widen `(15,4,…)`. Normalize PG zero spellings (`'0'::numeric`, `0`, `(0)`) to `0`; SQL NULL stays NULL.
2. `fourth_decimal_present` — rows in `journal_lines` (debit or credit) and `payment_repositories` (balance, last_reconciled_balance) where `(value * 10000)::bigint % 10 <> 0`. Expected 0 pre-widen (impossible at scale 3) and 0 post-widen until a scale-4 preset exists; it is the measurable-drift detector for the future.
3. `repository_last_movement` — per `payment_repositories` row, `balance` vs the latest `repository_movements.balance_after` (order by the movement ordinal column; read `apps/api/database/migrations/tenant/2026_07_08_100100_create_repository_movements_table.php` for the exact column names); repositories with no movement and balance 0 are compliant; drift = `balance <> balance_after`.
4. `journal_entry_balance` — `journal_entries` whose `SUM(debit) <> SUM(credit)` over its lines (drafts included, reported separately in `sample_ids` prefix `draft:`).
5. `columns_below_scale_4` — every `information_schema.columns` row with `data_type='numeric' AND numeric_scale < 4` in the tenant schema, classified by a curated allowlist: `Percent` for `*_percent`, `*_rate`, `rate`, `earning_multiplier`, `vat_deductible_percent`; `Geometry` for `pos_tables.*`; `Quantity` for `weight_kg`, `*_hours*`, `avg_seconds_per_item`; everything else `Money`. Expected count on local dev 2026-09-07: 49 columns at scale 2 + 143 at scale 3 (list captured in §6). Only `Money` rows feed the follow-up ticket.

Output: one line per tenant `MONEY-PRECISION CENSUS tenant=<uuid> db=<name> status=clean|drift|incomplete measurable_drift=<n> money_columns_below_scale_4=<n>` followed by a table (or `--json`); exit 0 clean, 1 drift, 2 incomplete (any tenant DB not openable). Markers are grep-stable (manifest row H).

Tests (red first, PG lane): `apps/api/tests/Feature/Treasury/MoneyPrecisionCensusCommandTest.php` — `test_reports_clean_on_fresh_tenant()`, `test_detects_repository_balance_vs_last_movement_drift()` (write the drift under `SET LOCAL app.treasury_movement_port = 'on'` inside a transaction — never disable the trigger), `test_detects_unbalanced_journal_entry()`, `test_lists_money_columns_below_scale_4_with_classification()`, `test_json_output_matches_dto()`. Architecture: the command must satisfy `tests/Architecture/ConsoleCommandTenantContextTest.php`.

### T2 — Migration (pgsql-only, self-guarding, idempotent, forward-only)

File (new): `apps/api/database/migrations/tenant/2026_09_07_100000_widen_gl_and_repository_balance_columns_to_scale_4.php`.

- Driver ≠ pgsql → return (SQLite is type-agnostic; same pattern as `2026_05_30_000000_widen_wac_cost_columns_to_scale_6.php`).
- **Inspect all four tuples before any DDL.** Accept only `(15,3,NO,0)`, `(15,3,YES,NULL)` (legacy) or `(15,4,…)` (already compliant). Any other precision, scale, nullability or default → throw `RuntimeException('P0-PRECISION: status=failed reason=unexpected_column_shape column=<table.column> found=<tuple>')` **before** any ALTER (never silently "repair" nullability/default).
- All four already `(15,4)` → print `P0-PRECISION: status=already_compliant` and return without DDL.
- Otherwise one `ALTER TABLE journal_lines ALTER COLUMN debit TYPE decimal(15,4), ALTER COLUMN credit TYPE decimal(15,4)` and one `ALTER TABLE payment_repositories ALTER COLUMN balance TYPE decimal(15,4), ALTER COLUMN last_reconciled_balance TYPE decimal(15,4)`; defaults and NOT NULL are preserved by PG on `ALTER TYPE` — assert the post-tuple in code and throw if not. Print `P0-PRECISION: status=widened columns=4`.
- The `forbid_direct_balance_write_trg` row trigger does not fire on `ALTER TYPE`; no GUC needed. State this in the migration docblock and prove it in T3 (the test DB has the trigger).
- `down()`: pgsql no-op with a docblock explaining forward-only (assumption D).
- Docblock: cite the ruling, the premise correction, and the lock profile (`ALTER TYPE` on a numeric scale change rewrites the table under `ACCESS EXCLUSIVE`; bounded outage; money writes must be stopped for the window — see §5).

### T3 — Tests: raw round-trip `1.0005`, legacy → widened path, rerun, ratchet

- `apps/api/tests/Feature/Schema/GlAndRepositoryBalanceScale4Test.php` (PG lane; skip on sqlite like `tests/Feature/Schema/MonetaryColumnScalesTest.php`):
  - `test_target_columns_have_full_tuple_15_4()` — the four tuples after a fresh migrate.
  - `test_journal_line_round_trips_1_0005_raw()` — create a draft journal entry fixture with a real account (existing factories), insert a line with `debit='1.0005'` via `DB::table('journal_lines')`, read back raw text `1.0005` (not `1.001`, not `1.000`).
  - `test_repository_balance_round_trips_1_0005_raw_via_port_guc()` — create a repository at balance 0 (model default passes the INSERT branch), then inside `DB::transaction` run `SET LOCAL app.treasury_movement_port = 'on'` and `UPDATE payment_repositories SET balance='1.0005', last_reconciled_balance='1.0005'`; raw read back `1.0005` for both. A sibling assertion proves the trigger is still live (the same UPDATE without the GUC throws `23000`-class integrity violation).
  - `test_legacy_scale_3_shape_is_widened_and_reported()` — inside a transaction narrow the four columns back to `decimal(15,3)` (values fit), instantiate the migration class (`require` the file, call `->up()`), assert output marker `status=widened`, assert tuples `(15,4)`, assert defaults/nullability unchanged.
  - `test_rerun_on_compliant_schema_is_already_compliant_and_changes_nothing()` — call `->up()` on the compliant schema; marker `status=already_compliant`; tuples identical; row counts identical.
  - `test_unexpected_shape_refuses_before_ddl()` — in a transaction drop the default on `journal_lines.debit`; `->up()` throws with `reason=unexpected_column_shape`; all four tuples unchanged afterwards.
- `apps/api/tests/Architecture/MoneyStorageScale4RatchetTest.php` (PG lane): `money_storage_target_columns_have_scale_4_full_tuple()` — asserts the four expected tuples from live `information_schema`; liveness providers damage **precision, scale, nullability, default** separately (each in its own rolled-back transaction) and assert the checker names exactly the damaged property. Put the checker in `tests/Architecture/Support/MoneyStorageScaleChecker.php` so the migration pre-check and the ratchet share one tuple normalizer (production code may not depend on tests: duplicate the ~20-line normalizer in the migration, and add a unit test asserting both normalizers agree on the PG zero spellings).
- Existing guards that must stay green: `tests/Feature/Schema/MonetaryColumnScalesTest.php`, `tests/Unit/POS/PosShiftsScale4Test.php`, `tests/Feature/Treasury/*Movement*`, `tests/Feature/Accounting/OpeningBalanceBatchTest.php`, `tests/Feature/Accounting/ExpenseVatPostingTest.php`.

### T4 — Contract docs, CLAUDE.md rule 19, follow-up ticket

- `docs/architecture/precision-contract.md` § Storage tier: money storage floor is **`decimal(N,4)`** (owner ruling 2026-09-06 A1 follow-up); the four GL/repository columns are at scale 4 as of this lane; every other money column still at scale 3 is enumerated in the follow-up ticket and widened by a later lane; **new money columns are created at scale 4**; casts remain at the country-preset maximum (3) until a 4-decimal preset exists (assumption A, tracked); display/rounding rules unchanged (`bcround` at `getScale($currency)`); FormRequest ceilings unchanged. Update the table at `:7-10` and the bullet at `:17`; do not rewrite the emission sections.
- `CLAUDE.md` rule 19 line 72: `Storage: currency decimal(N,3) floor` → `Storage: money decimal(N,4) floor for new columns (GL lines + repository balances widened 2026-09-07; legacy scale-3 money columns tracked in the follow-up ticket), quantity decimal(N,4)`.
- New ticket `docs/superpowers/tickets/2026-09-07-money-columns-below-scale-4-followup.md`: body = the T1 census `Money` list from a fresh local run (table.column, tuple, module owner), grouped by module, with the note that `repository_movements.amount/balance_after` and `accounts.balance` are the highest-priority rows (they sit on the same ledger as the four widened columns), and that the casts lane (assumption A) goes with it.
- `docs/superpowers/plans/2026-09-06-WCASH-1-custody-config-transfer-doc.md`: **do not edit** (orchestrator-owned); instead write `docs/superpowers/reviews/2026-09-07-precision-4-handback-for-wcash-1.md` listing the delivered file names, markers, the (15,4)/`1.0005` correction, and the merge commit once merged.

### T5 — Deployment section (documented only; NO push in this window)

Deployment follows `docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md` (five-push sequence §2, gate checklist §4). This lane supplies only the variables below; it does not restate deploy mechanics.

| Variable | Value |
|---|---|
| `<slice>` | `precision-4` |
| Migrations | `2026_09_07_100000_widen_gl_and_repository_balance_columns_to_scale_4.php` — **non-additive** (column type change, table rewrite), self-guarding (tuple pre-check, idempotent), prerequisite for W-CASH-1 T3/T4 |
| Flags | none |
| Commands | `treasury:census-money-precision [--tenant=] [--json]` · standalone (NOT under `tenants:run`) · marker `MONEY-PRECISION CENSUS` |
| Censuses | `treasury:census-money-precision` before and after; `tenant:census-day-one`; `treasury:reconcile --tenant=<uuid>` |
| Web changes | no |
| Device build | no |
| Queues | none |
| Collapsed pushes | Push 3 (no flag) and Push 5 (no activation) omitted; this is **Push 1 (census, own commit) then a separate non-additive Push 2** — the manifest's Push 2 admits only additive DDL, so this lane is the explicitly requested exception and must be its own push, before any W-CASH push |
| Env path | none needed |

Sequence for the owner (paper, executed on return):
1. Push 1: census command only; run `treasury:census-money-precision` on staging; attach output (baseline).
2. Host-side backup of every tenant DB (manifest row I, `pg_dump -Fc` per tenant, non-zero size verified) — the migration rewrites `journal_lines` and `payment_repositories`.
3. Stop money writes for the ALTER window (worker paused: `horizon:pause`; API maintenance or a quiet hour).
4. Push 2: the migration; boot runs `tenants:migrate-rolling --force` (manifest row C) — read the boot log for `P0-PRECISION: status=widened|already_compliant` per tenant, then re-run `php artisan tenants:migrate-rolling --force; echo rolling-exit=$?` and `--tenant=<uuid>` per tenant. **Compatibility mode (U-2 false)**: rolling is a no-op; run `php artisan migrate --force --path=database/migrations/tenant` against the shared DB explicitly and capture `P0-PRECISION:` from its output; the physical post-check (`information_schema` tuples) is mandatory either way.
5. Post: `treasury:census-money-precision` again (status=clean, `target_shape` post-widen), `treasury:reconcile`, `tenant:census-day-one`; resume worker.
6. Rollback: none for the schema (forward-only). Failure inside the ALTER = transaction rollback, prior shapes retained, stop writes, correct forward.

### T6 — Verification and handoff

Commands (from the worktree `apps/api`, private PG container on port **5453**, DB `autoerp_test_p`):

```bash
docker run -d --name pgh-test-pg-p4 -e POSTGRES_USER=autoerp -e POSTGRES_PASSWORD=autoerp_secret -e POSTGRES_DB=autoerp_test_p -p 5453:5432 postgres:16-alpine
export DB_HOST=127.0.0.1 DB_PORT=5453 DB_DATABASE=autoerp_test_p DB_CENTRAL_DATABASE=autoerp_test_p DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret
php artisan test -c phpunit-pgsql.xml tests/Feature/Schema/GlAndRepositoryBalanceScale4Test.php tests/Architecture/MoneyStorageScale4RatchetTest.php tests/Feature/Treasury/MoneyPrecisionCensusCommandTest.php tests/Feature/Schema/MonetaryColumnScalesTest.php tests/Unit/POS/PosShiftsScale4Test.php
php artisan test tests/Feature/Accounting/OpeningBalanceBatchTest.php tests/Feature/Accounting/ExpenseVatPostingTest.php tests/Architecture/ConsoleCommandTenantContextTest.php
./vendor/bin/phpstan analyse --memory-limit=1G <changed files>     # needs the live-DB env above
./vendor/bin/pint --test <changed files>
```

Never the full suite. Never `git stash`. Path-scoped commits only. Lane-manifest / pgsql allowlist raises if the CI gate lists test classes (see `189fe7d8a` for the pattern). Handback file: `docs/handoff/HANDBACK-precision-4-2026-09-07.md` — what changed, commands run with output excerpts, census output from the local demo tenant (`--tenant=019fbe86-944a-7252-8a3b-8c341dfa9de9` on PG 5433, read-only), open items.

## 3. Reviewer gate contract

Merge only on explicit MERGE from **both** `treasury-reviewer` and `stock-gl-interaction-reviewer`. Reviewers verify: no writer changed; the trigger still live; casts untouched (assumption A) and documented; migration refuses unexpected shapes before DDL; idempotent rerun; `1.0005` raw round-trip; ratchet liveness per property; census read-only (no `INSERT/UPDATE/DELETE/DDL` in the service, provable by grep + a test that runs it inside a read-only transaction); docs updated; follow-up ticket present; nothing outside §1.

## 4. Convention-10 note

This is a storage-capacity change, not a user-facing flow; no ERP-behaviour decision is taken here (the owner ruled). Benchmark for the record: Odoo stores monetary fields at the currency's `decimal_places` (`res.currency.decimal_places`, `fields.Monetary`), ERPNext `Currency` fieldtype at system precision (`currency_precision`, up to 9), Dolibarr `MAIN_MAX_DECIMALS_UNIT/TOT` (default 5 / 2). All three keep display precision configurable per currency/system, independent of storage — consistent with "storage capacity ≥ any preset, precision = preset".

## 5. Owner notes for the return (recorded, not decided here)

- Assumption A (casts stay `decimal:3` until a 4-decimal preset exists) — veto → opens the casts lane now.
- The ticket's rounding-drift premise was wrong; W-CASH-1's P0 wording is corrected via the handback file (§T4), not by editing its plan.

## 6. Local census snapshot (2026-09-07, local tenant DB, `numeric_scale < 4`)

Scale 2 (49) are percent/rate/geometry/hours plus `inventory_counter_metrics.avg_seconds_per_item` and `marketplace_sellers.average_rating`; scale 3 (143) are money and loyalty-points columns. Full list is regenerated by T1 and pasted into the follow-up ticket; the raw pre-lane capture is in the session handback.
