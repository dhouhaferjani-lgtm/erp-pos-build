# SG-3c-FU adversarial gate r2 — treasury + tenancy/authz

Date: 2026-08-30  
Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/sg3c-fu`  
Branch/base: `feat/sg3c-fu-repository-normalisation` / `e8d0ac870aa7e574e390372f6dc3ebc16393dabf`  
Review mode: source read-only; only this register was written. No full suite was run. PostgreSQL commands used `DB_DATABASE=autoerp_test_g6 DB_CENTRAL_DATABASE=autoerp_test_g6` and no `apps/api/autoerp_test_*` artifact was created.

The r1 register and the summary's `## Fix round 1` adjudication were reviewed first. R1-04 is ruled brief-wins: retain `TenantScopedCommand`'s per-company `CompanyContext` binding and prove service decisions are independent of ambient context. R1-05 is ruled accepted with the PostgreSQL company advisory transaction lock plus immediate pre-write recheck; the concurrency proof remains waived.

## R1 disposition

| R1 ID | status | r2 evidence |
|---|---|---|
| SG3CFU-R1-01 | OPEN | The original omitted instrument/custody surfaces were added at `RepositoryCensusService.php:26-42` and table-driven at `RepositoryNormalisationTest.php:48-65,213-274`, but the asserted exhaustive boundary still omits the second `PaymentInstrument` repository relation, `payment_instruments.deposited_to_id` (SG3CFU-R2-01). |
| SG3CFU-R1-02 | FIXED | Money-bearing findings are emitted before the per-location guard and control failure at `NormaliseRepositoriesCommand.php:139-162`. The combined PostgreSQL pre-index + money-bearing case now expects refusal/failure and unchanged rows at `RepositoryNormalisationTest.php:745-795`. |
| SG3CFU-R1-03 | FIXED | Both final metadata updates reassert `tenant_id`, `company_id`, and repository id at `NormaliseRepositoriesCommand.php:279-296`; the mismatched-tenant no-write proof is at `RepositoryNormalisationTest.php:565-596`. |
| SG3CFU-R1-04 | RULED | Brief-wins per orchestrator: both commands bind `CompanyContext` per company (`CensusRepositoriesCommand.php:64-68`; `NormaliseRepositoriesCommand.php:85-92`). The census uses repository currency explicitly at `RepositoryCensusService.php:194-200`, and the cleared-context TND-vs-JPY proof passes at `RepositoryNormalisationTest.php:276-313`. |
| SG3CFU-R1-05 | RULED | Accepted shape is present: PostgreSQL advisory xact lock and repository row locks at `NormaliseRepositoriesCommand.php:120-128,299-304`, then balance/movement/reference recheck immediately before action at lines 164-200 through `RepositoryCensusService.php:183-211`. The race-hook proof is at `RepositoryNormalisationTest.php:509-563`; the residual non-participating-writer window remains explicitly documented at command lines 31-34 and the concurrency proof is waived. |
| SG3CFU-R1-06 | FIXED | Every completed per-company `--apply` path logs `repositories.normalised`, including per-location skip, ordinary action, no-op, and refused-only paths (`NormaliseRepositoriesCommand.php:157-159,218-220,311-332`; `RepositoryNormalisationTest.php:433-507`). |

## Findings

| ID | severity | file:line | finding | change |
|---|---|---|---|---|
| SG3CFU-R2-01 | HIGH | `apps/api/app/Modules/Treasury/Application/Services/RepositoryCensusService.php:19-42,203-211`; `apps/api/database/migrations/tenant/2025_11_30_120000_create_treasury_tables.php:117-119`; `apps/api/app/Modules/Treasury/Domain/PaymentInstrument.php:176-181`; `apps/api/app/Modules/Treasury/Application/Services/InstrumentRemittanceService.php:169-174`; `apps/api/tests/Feature/Treasury/RepositoryNormalisationTest.php:48-65,213-274` | The 14-table reference census is not exhaustive. It checks `payment_instruments.repository_id` but omits `payment_instruments.deposited_to_id`, a bare UUID in the founding migration and an explicit `belongsTo(PaymentRepository::class, 'deposited_to_id')` relation. Remittance writes the bank repository into that column, and statement matching reads it (`StatementSuggestionService.php:295-300,640-644`). Thus the implementation covers 15 direct repository-id columns, not all 16 across the 14 tables. A legacy/hand-shaped zero-balance surplus safe referenced only there is misclassified `repo.safe.duplicate_clean` and can be deactivated. The data-provider also cannot falsify this omission. | Add `['table' => 'payment_instruments', 'column' => 'deposited_to_id']` to the authoritative boundary and add its table-driven census/refusal/apply proof: exact `repo.duplicate.money_bearing`, `RepositoryTransfer` hint, failure exit, active row unchanged, and no metadata action. Correct the runbook's exhaustive list. |

## Reference census — migration and relation cross-check

The gate's “14 surfaces” resolves to 14 tables containing 16 direct repository-id columns. The service currently checks 15 columns. POS shifts and cash-drawer operations have no direct repository-id column; their repository-bearing effects reach the checked payments, movements, and adjustments tables. GL is the repository's own `gl_account_id`, not a separate referencing surface; canonical linking uses the same `Account::findByPurpose($companyId, SystemAccountPurpose::Cash)` rule as G-3c (`NormaliseRepositoriesCommand.php:241-263`).

| Table surface | Direct column(s) | service status |
|---|---|---|
| `repository_movements` | `payment_repository_id` | checked |
| `payments` | `repository_id` | checked |
| `repository_adjustments` | `payment_repository_id` | checked |
| `bank_statements` | `payment_repository_id` | checked |
| `bank_statement_lines` | `payment_repository_id` | checked |
| `expense_metadata` | `payment_repository_id` | checked |
| `income_metadata` | `payment_repository_id` | checked |
| `payment_methods` | `default_repository_id` | checked |
| `payment_instruments` | `repository_id`; `deposited_to_id` | `repository_id` checked; **`deposited_to_id` missing** |
| `instrument_events` | `from_repository_id`; `to_repository_id` | both checked |
| `instrument_remittances` | `bank_repository_id` | checked |
| `statement_import_profiles` | `payment_repository_id` | checked |
| `bank_reconciliations` | `repository_id` | checked |
| `expense_recurrence_templates` | `payment_repository_id` | checked |

## Census code × action × refusal — verified

| Census code | `--apply` action | Refusal / hint | Operator step remaining |
|---|---|---|---|
| `repo.cash.location_null` | No action; neither repository command writes `payment_repositories.location_id`. | No money refusal. The hint correctly says `treasury:backfill-location-attribution` does not attribute repositories and routes unambiguous cases to N-12, ambiguous cases to an owner ruling and approved one-off fix. | Yes: N-12/owner attribution path. |
| `repo.safe.count_ne_1` | No independent write; eligible row-level findings determine actions. | No independent refusal. | Yes when other findings cannot safely converge the active-safe count. |
| `repo.safe.gl_unlinked` | Links only the canonical safe to the cash-purpose account. Missing purpose account is skipped and logged. | No independent money refusal; a surplus row that is money-bearing is separately refused. | Repair the chart and re-run when the purpose account is absent. |
| `repo.safe.duplicate_clean` | Deactivates the surplus safe by writing only `is_active=false` and `updated_at`. | No refusal once classified clean. **Classification is unsafe for `payment_instruments.deposited_to_id` until SG3CFU-R2-01 is fixed.** | None after the exhaustive clean predicate is restored. |
| `repo.duplicate.money_bearing` | Leaves the row unchanged; clean actions for the company may still commit in the same per-company transaction. | Prints `REFUSED` plus “post a `RepositoryTransfer` (`RepositoryTransferService`) from this safe to the canonical one, then re-run”; company/run exits failure. | Post the transfer, then re-run. |
| `repo.duplicate.per_location_type` | No auto-repair and no metadata action for that company. | The money-bearing refusal is emitted first and still causes failure when both codes coexist; otherwise prints the operator-review no-action hint. | Treasury/operator review. |

## Verification outputs

All test commands were by explicit path and serial in the accepted run. No full suite was run.

| Check | Result |
|---|---|
| SQLite `RepositoryNormalisationTest.php` | PASS — 24 passed, 1 PostgreSQL-only skipped, 209 assertions |
| SQLite `BackfillLocationAttributionTest.php` | PASS — 4 passed, 22 assertions |
| SQLite `PaymentRepositoryLocationTest.php` | PASS — 3 passed, 2 PostgreSQL-only skipped, 8 assertions |
| SQLite `CompanyPaymentRepositoryProvisioningTest.php` | PASS — 12 passed, 69 assertions |
| SQLite `tests/Feature/Seeders/PaymentRepositorySeederTest.php` | PASS — 7 passed, 39 assertions |
| PostgreSQL `RepositoryNormalisationTest.php` with `autoerp_test_g6` prefix | PASS — 25 passed, 225 assertions; partial-unique exit/entry and combined pre-index money refusal exercised |
| PostgreSQL `BackfillLocationAttributionTest.php` with `autoerp_test_g6` prefix | PASS — 4 passed, 22 assertions |
| PostgreSQL `PaymentRepositoryLocationTest.php` with `autoerp_test_g6` prefix | PASS — 5 passed, 17 assertions |
| `./vendor/bin/pint --test` | PASS — `{"result":"pass"}` |
| PHPStan on all 11 touched Treasury production PHP files | PASS — `[OK] No errors` |
| `php tools/feature-lane-manifest-check.php` | PASS — 1489 Feature classes / 74 groups; every filter anchored and uniquely matched against 1896 test classes |
| Manifest arithmetic vs current local `dev` (`4dfa60e0db0`) | Correct: current dev manifest Treasury 123 / total Feature classes 1488 / `gated_ceiling` 1226; lane adds exactly `RepositoryNormalisationTest`, yielding Treasury 124 / total 1489 / ceiling 1227 |
| CI filter | PASS — `RepositoryNormalisationTest` is immediately before the anchored close at `.github/workflows/ci.yml:1117`; required PostgreSQL/parked-lane comments are at lines 1114-1115; manifest checker parses it |
| Deptrac no-cache JSON analysis | 183 violations, 14510 allowed, 13595 uncovered; **zero touched-class violation edges**. Global ratchet remains inherited red: `SharedContracts on ModuleDomain` baseline 36 → 37 while `ModuleDomain on ModuleApplication` improves 54 → 53; total remains 183. |
| `git diff --check` | PASS |
| Migration/web/POS/artifact audit | PASS — no lane migration, web, or POS change; no `apps/api/autoerp_test_*` artifact |

One initial reviewer wrapper accidentally allowed two yielded PostgreSQL processes to overlap. The overlapping `BackfillLocationAttributionTest` attempt failed during `RefreshDatabase` schema teardown with PostgreSQL `40P01 deadlock detected`; this was a harness concurrency failure before the test body, not a lane assertion. The processes were then rerun one path at a time and produced the clean serial results above.

## Read-only, tenancy, and STOP-condition audit

- Census read-only: no create/update/save/delete/insert/upsert/`DB::statement`, transaction, movement, journal, or schema call exists in `CensusRepositoriesCommand`, `RepositoryCensusService`, its DTOs, or enums. The SELECT-only query-listener test passes on SQLite and PostgreSQL. Findings do not alter census exit status; incomplete and zero-company coverage do.
- Per-tenant execution: both commands extend `TenantScopedCommand`, select companies under `tenant_id`, bind context per company per the r1 ruling, pass explicit tenant/company ids into the service, and reassert both ids at the write boundary.
- N-12 fact: `BackfillLocationAttributionCommand.php:82-149` reads non-NULL repository locations and writes only `payment_instruments.location_id` and `payments.location_id`. It never writes `payment_repositories.location_id`; the twice-run legacy fixture passes.
- Normaliser writes: the only repository mutations are `is_active`, `gl_account_id`, and `updated_at` at `NormaliseRepositoriesCommand.php:279-296`. There is no delete, balance write, repository movement, GL posting/journal creation, or location write.
- Transactions/idempotency: each company apply is wrapped independently at command lines 85-99; the clean second-company/second-location second run makes no further change and exits success.
- Constraints/schema: no migration or constraint/index edit is in the lane. `unique(company_id, code)` and `payment_repositories_one_drawer_per_location_type` are unchanged. PostgreSQL proves deactivation leaves the partial predicate and canonical GL linking enters it without collision.

No STOP condition was hit.

## VERDICT

**CHANGES**

The r1 command-ordering, tenancy-boundary, context ruling, advisory-lock/recheck, and logging fixes are present and green. Promotion remains blocked because SG3CFU-R2-01 leaves the advertised exhaustive “unreferenced” predicate false: `payment_instruments.deposited_to_id` must participate in census, apply-time recheck, documentation, and the table-driven refusal proof.
