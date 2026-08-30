# SG-3c-FU adversarial gate r1 — treasury + tenancy/authz

Date: 2026-08-30  
Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/sg3c-fu`  
Branch/base: `feat/sg3c-fu-repository-normalisation` / `e8d0ac870aa7e574e390372f6dc3ebc16393dabf`  
Review mode: source read-only; only this register was written. No full suite was run.

The named brief was absent from the worktree and repository root. Its full task text was recovered from the supplied lane log; the supplied gate prompt and implementation summary were also reviewed.

## Findings

| ID | severity | file:line | finding | change |
|---|---|---|---|---|
| SG3CFU-R1-01 | HIGH | `apps/api/app/Modules/Treasury/Application/Services/RepositoryCensusService.php:19-29,119-137`; `apps/api/database/migrations/tenant/2025_11_30_120000_create_treasury_tables.php:89-110`; `apps/api/database/migrations/tenant/2026_07_12_100200_create_instrument_events.php:17-29`; `apps/api/database/migrations/tenant/2026_07_12_100400_create_instrument_remittances.php:19-30` | The normaliser's safety claim is broader than its reference census. A zero-balance surplus safe referenced by `payment_instruments.repository_id` is currently emitted as `repo.safe.duplicate_clean` and deactivated. Instrument custody also has `instrument_events.from_repository_id` / `to_repository_id` and `instrument_remittances.bank_repository_id`, none of which are checked. The eight surfaces named in the original brief are implemented, but the adversarial gate explicitly requires instrument coverage. No test exercises `hasReference()` at all: both refusal fixtures use only a non-zero `balance` (`RepositoryNormalisationTest.php:136-141,224-231`). | Extend the authoritative reference census to instrument/custody surfaces (and document the exhaustive boundary). Add table-driven tests proving each reference surface emits `repo.duplicate.money_bearing`, prints the `RepositoryTransfer` hint, remains active and unchanged under `--apply`, and makes no metadata action. |
| SG3CFU-R1-02 | HIGH | `apps/api/app/Modules/Treasury/Presentation/Console/NormaliseRepositoriesCommand.php:132-151`; `apps/api/tests/Feature/Treasury/RepositoryNormalisationTest.php:437-474` | `repo.duplicate.per_location_type` returns before money-bearing findings are emitted. A pre-index pair that also contains a non-zero/reference-bearing surplus safe therefore prints only `NO ACTION repo.duplicate.per_location_type`, suppresses the mandatory `repo.duplicate.money_bearing` + `RepositoryTransfer` refusal, and returns success. The PG test explicitly asserts that success at line 465. | Report/refuse all money-bearing duplicates before applying the per-location no-auto-repair guard. Preserve the no-write behavior, but return failure when any money-bearing row exists. Add a PG fixture combining the pre-index duplicate and money-bearing conditions. |
| SG3CFU-R1-03 | MEDIUM | `apps/api/app/Modules/Treasury/Presentation/Console/NormaliseRepositoriesCommand.php:83-88,234-246` | Tenant selection is re-asserted when companies and census rows are read, but the two acting updates constrain only `company_id` + repository `id`; `tenant_id` is dropped at the final write boundary. Database-per-tenant mode and UUID ids reduce practical exposure, but this does not meet the gate's explicit per-company tenant re-assertion requirement and weakens single-schema compatibility defense-in-depth. | Pass `tenantId` into `applyAction()` and add `where('tenant_id', $tenantId)` to both updates. Pin a mismatched-tenant row/no-write case. |
| SG3CFU-R1-04 | MEDIUM | `apps/api/app/Modules/Treasury/Presentation/Console/CensusRepositoriesCommand.php:35-40,64-68`; `apps/api/app/Modules/Treasury/Presentation/Console/NormaliseRepositoriesCommand.php:44-49,81-88`; `apps/api/tests/Feature/Treasury/RepositoryNormalisationTest.php:34-178` | The gate asks for operation with no `CompanyContext`, but both commands explicitly set it per company and no test clears/asserts context independence. The underlying census correctly passes repository currency explicitly to `CurrencyScaleResolverInterface::getScale($safe->currency)` (`RepositoryCensusService.php:119-123`) and does not read CompanyContext, so this is a console-contract/test gap rather than observed cross-company data leakage. The recovered original brief, notably, asked to mirror `TenantScopedCommand` CompanyContext binding; the gate requirement is stricter and conflicts with that older text. | Resolve the requirement conflict explicitly. If no-context is authoritative, remove the per-company context dependency/set and test with `CompanyContext::clear()`. Otherwise amend the gate wording and add a test proving all census/normalisation decisions remain independent of ambient context. |
| SG3CFU-R1-05 | MEDIUM | `apps/api/app/Modules/Treasury/Presentation/Console/NormaliseRepositoriesCommand.php:114-125,153-176,234-246`; `apps/api/app/Modules/Treasury/Application/Services/RepositoryCensusService.php:174-183` | The transaction locks repository rows, then checks references with independent `exists()` queries and later updates metadata. It does not lock/reference-serialize writers such as payment-method defaults or instrument custody. A new reference can be committed between census and deactivation, so “unreferenced” is not an atomic acting invariant. | Use an advisory/company maintenance lock or an atomic guarded update/recheck covering every reference surface immediately before deactivation. Add a PostgreSQL concurrency proof that a competing reference writer cannot leave a newly referenced safe deactivated. |
| SG3CFU-R1-06 | LOW | `apps/api/app/Modules/Treasury/Presentation/Console/NormaliseRepositoriesCommand.php:169-177`; `apps/api/tests/Feature/Treasury/RepositoryNormalisationTest.php:282-287` | `repositories.normalised` is logged only when the planned action list is non-empty. An acting `--apply` run that is a no-op, refused-only, missing-purpose-account, or per-location duplicate has no structured acting-run audit record. The brief says to log on every acting run with company id and action list. | On every per-company `--apply`, log `repositories.normalised` with `company_id`, the possibly empty `actions` list, and refusal/skip counts or codes. Pin no-op and refused-only logs. |

## Census code × action × refusal — verified actual behavior

| Census code | `--apply` action | Refusal behavior / hint | Operator step |
|---|---|---|---|
| `repo.cash.location_null` | No repository action; prints `NO ACTION`. Neither repository command writes repository `location_id`. | Not a money refusal. Hint correctly says `treasury:backfill-location-attribution` does not attribute repositories and routes unambiguous cases to N-12, ambiguous cases to owner ruling + approved one-off fix. | Yes: N-12/owner attribution path. |
| `repo.safe.count_ne_1` | No direct action; row findings drive any eligible metadata action. | No independent refusal. | Yes if safe-count drift remains. |
| `repo.safe.gl_unlinked` | Links only the canonical safe to `Account::findByPurpose($companyId, SystemAccountPurpose::Cash)`. Missing purpose account is skipped. | No money refusal; missing-account warning says repair the chart and re-run. | Only when account is absent or the unlinked safe is non-canonical. |
| `repo.safe.duplicate_clean` | Deactivates the surplus safe by writing only `is_active=false` and `updated_at`, except that any `repo.duplicate.per_location_type` finding makes the command return before all actions. | No refusal once classified clean. Classification currently omits instrument/custody surfaces (SG3CFU-R1-01). | No after a safe/atomic clean classification. |
| `repo.duplicate.money_bearing` | Leaves the row unchanged while other clean actions may commit in the same company transaction. | Normally prints `REFUSED` and `post a RepositoryTransfer (RepositoryTransferService) from this safe to the canonical one, then re-run`; company exit is failure. This refusal is suppressed when `repo.duplicate.per_location_type` is also present (SG3CFU-R1-02). | Yes: post the transfer and re-run. |
| `repo.duplicate.per_location_type` | No automatic repair; current implementation returns before actions/refusals for the company. | Prints a no-action operator-review hint and returns success, even if a money-bearing code also exists. | Yes: treasury/operator review. |

## Reference census

The original brief's eight listed surfaces are present verbatim in `RepositoryCensusService.php:20-29`:

| Surface | Column | Verified |
|---|---|---|
| repository movements | `repository_movements.payment_repository_id` | yes |
| payments | `payments.repository_id` | yes |
| repository adjustments | `repository_adjustments.payment_repository_id` | yes |
| bank statements | `bank_statements.payment_repository_id` | yes |
| bank statement lines | `bank_statement_lines.payment_repository_id` | yes |
| expense metadata | `expense_metadata.payment_repository_id` | yes |
| income metadata | `income_metadata.payment_repository_id` | yes |
| payment-method default drawer | `payment_methods.default_repository_id` | yes |

Adversarially relevant direct repository references not covered include:

- `payment_instruments.repository_id` (`2025_11_30_120000_create_treasury_tables.php:108-110`)
- `instrument_events.from_repository_id` / `to_repository_id` (`2026_07_12_100200_create_instrument_events.php:25-26`)
- `instrument_remittances.bank_repository_id` (`2026_07_12_100400_create_instrument_remittances.php:27`)
- `statement_import_profiles.payment_repository_id` (`2026_07_19_110000_create_statement_import_profiles.php:18-24`)
- `bank_reconciliations.repository_id` (`2025_12_14_150000_create_bank_reconciliations_table.php:14-32`)
- `expense_recurrence_templates.payment_repository_id` (`2026_07_14_110000_create_expense_recurrence_templates.php:14-21`)

No direct POS shift-to-repository foreign-key column was found; POS-originated cash legs ultimately reference repositories through payments/movements. GL is not posted by either new command. The canonical link uses the same cash-purpose resolution as G-3c.

## Verification outputs

All test commands were run serially and by explicit path. No full suite was run.

| Check | Result |
|---|---|
| SQLite `RepositoryNormalisationTest.php` | PASS — 5 passed, 1 PG-only skipped, 43 assertions |
| SQLite `BackfillLocationAttributionTest.php` | PASS — 4 passed, 22 assertions |
| SQLite `PaymentRepositoryLocationTest.php` | PASS — 3 passed, 2 PG-only skipped, 8 assertions |
| SQLite `CompanyPaymentRepositoryProvisioningTest.php` | PASS — 12 passed, 69 assertions |
| SQLite `PaymentRepositorySeederTest.php` | PASS — 7 passed, 39 assertions |
| SQLite aggregate | 31 passed, 3 skipped, 181 assertions |
| PostgreSQL `DB_DATABASE=autoerp_test_g6 DB_CENTRAL_DATABASE=autoerp_test_g6 php artisan test -c phpunit-pgsql.xml tests/Feature/Treasury/RepositoryNormalisationTest.php` | PASS — 6 passed, 56 assertions; partial-unique transition exercised |
| `./vendor/bin/pint --test` | PASS — `{"result":"pass"}` |
| PHPStan on 11 touched Treasury production PHP files | PASS — `[OK] No errors` |
| `php tools/feature-lane-manifest-check.php` | PASS — 1489 Feature classes / 74 groups; every filter anchored and uniquely matched against 1896 test classes |
| Manifest arithmetic vs current `dev` (`e8d0ac870`) | Correct: Treasury 123 → 124; `gated_ceiling` 1226 → 1227; exactly one new Feature class |
| CI filter | Parseable/anchored by manifest checker; `RepositoryNormalisationTest` appended at `.github/workflows/ci.yml:1117` with the required parked-PG comments at lines 1114-1115 |
| `git diff --check` | PASS |
| Migration/web/POS/test-artifact audit | PASS — no lane migration, web or POS diff; no `apps/api/autoerp_test_*` artifact |
| Deptrac ratchet | Global command exits 1: inherited/stale category `SharedContracts on ModuleDomain` is 36 → 37 while `ModuleDomain on ModuleApplication` improves 54 → 53. Direct violation report contains no touched SG-3c-FU class; no touched-class edge was found. The checked-in category ratchet remains globally red and was not modified. |

## Read-only and STOP-condition audit

- Census: no `create`, `update`, `save`, `delete`, insert/upsert, or `DB::statement` call in the command/service/DTO/enum path. The query-listener test passed on SQLite and PostgreSQL. Findings do not alter its exit status; incomplete/zero-company coverage does.
- N-12 command fact: `BackfillLocationAttributionCommand.php:82-149` reads `payment_repositories.location_id` and writes only `payment_instruments.location_id` / `payments.location_id`. It never writes `payment_repositories.location_id`; the legacy-shaped twice-run test passed.
- Normaliser: no repository delete, direct balance write, repository movement creation, journal entry/line creation, GL posting, or repository `location_id` write was found. Its only repository updates are `is_active`, `gl_account_id`, and `updated_at` (`NormaliseRepositoriesCommand.php:234-246`).
- Constraints/schema: no migration or constraint/index change is in the lane. The N-12 partial unique predicate is unchanged. PostgreSQL proves deactivation drops a row from the predicate and canonical GL linking enters it without collision.

No STOP condition was hit.

## VERDICT

**CHANGES**

Runtime verification is green and the hard treasury prohibitions hold, but the lane is not safe to promote until SG3CFU-R1-01 and SG3CFU-R1-02 are fixed and pinned. SG3CFU-R1-03 should be fixed in the same round to satisfy the tenancy write-boundary requirement. The remaining medium/low items should be resolved or explicitly ruled before re-gate.
