# Codex Accounting Gaps C/G/H/I Report

Date: 2026-08-10
Branch: `codex/accounting-gaps-cghi`
Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/accounting-gaps-cghi`
Status: implemented, committed locally, not pushed, not merged into `dev`

## Dependency reconciliation

The dispatch stated that the accounting seeder lane was already on local `dev`, but `dev` at branch creation (`ed6fe896f`) did not contain it. The local completed lane `fix/dpa-seeder-gaps-accounting` ended at `c05812444`. It was merged into this feature branch only as `9ff9fc6eb` (`Merge completed accounting seeder dependency`), preserving root `dev` and its unrelated untracked files.

That dependency supplies the idempotent `accounting:backfill-chart-purposes` command and the TN/FR `SalesDiscount` mapping at code `7097`. The feature work then proves the operational dead-letter recovery against those real artifacts.

Local `dev` advanced independently to `7d423d162` while this task was running. That later unrelated merge was not pulled into this already-tested feature branch.

## Delivered scope

### C — SalesDiscount recovery

- Added validated `--event-type` filtering to `fiscal:retry-projections`.
- Dry runs now emit deterministic per-row inventory with tenant, event, event type, projector, status, attempts, and last error.
- Added a real `treasury_account_charge_bridge` flow that:
  1. submits a discounted `ACCOUNT_CHARGE`;
  2. fails because `SalesDiscount` is absent;
  3. records the projection as dead-lettered;
  4. runs the chart-purpose backfill twice to prove creation plus idempotence;
  5. synchronously replays only the targeted account-charge projection;
  6. proves the balanced `Dr AR 114 + Dr SalesDiscount 5 / Cr Revenue 100 + Cr VAT 19` entry.

### G — stamp-duty country capability

- Made `CountryTaxConfigurationRegistry` the single authority for both country seeder support and `supportsStampDuty()`; TN is supported and FR is not.
- Added `GET /api/v1/taxation/configurations/capabilities`, scoped through the current company.
- Store and update now reject:
  - stamp duty in unsupported countries (`is_stamp_duty` validation error);
  - any non-stamp `DOCUMENT_TOTAL` configuration (`applies_to` validation error).
- Update validation evaluates the merged final state and now validates `tax_type` and `applies_to` when supplied.
- The React modal reads the server capability, fails closed while unavailable, disables unsupported stamp/document-total controls, and explains the restriction in English and French.
- TN positive creation, FR negative create/update, tenant-scoped capability query keys, and the surrounding settings page are covered.

### H — stamp-only document totals

- `TaxCalculationService` now includes a document-level row only when `applies_to=DOCUMENT_TOTAL` and `is_stamp_duty=true`.
- A brownfield non-stamp document-total row is ignored even if inserted outside the guarded API.
- The TN exact-byte test pins:
  - `documentTaxTotal = 1.000`;
  - `total = 101.000`;
  - hash input `INV-H-BYTES|2026-08-10T00:00:00Z|101.000|TND`;
  - byte-identical calculation output before and after adding an invalid non-stamp row.
- The live credit-note create → confirm → post flow proves a non-stamp document-total row leaves `stamp_duty_amount=0.000`, total `50.000`, and creates no `PurchaseStampDuty` or `SalesStampDutyPayable` journal lines.
- The existing positive TN credit-note timbre flow remains green, including exact `0.600` persistence and GL legs.
- Hash-path inspection confirmed the sealed input includes finalized `total`, so exclusion occurs before sealing rather than after immutable bytes exist.
- Follow-up design boundary recorded in `docs/superpowers/tickets/2026-08-10-own-named-document-total-taxes.md`.

### I — fail-closed purpose-missing alert persistence

- The missing-purpose alert remains savepoint-contained so PostgreSQL can recover from a failed statement, but the error is now rethrown to the outer projection transaction.
- A real database-trigger failure proves:
  - the projection row returns to `pending` with `attempts=1` and no `applied_at`;
  - payment and journal writes roll back;
  - no audit row is falsely assumed to exist.
- After removing the trigger, the same projection job retries to `applied`, persists the operator alert, and commits the payment and receipt journal.
- The trigger fixture runs on both SQLite and PostgreSQL syntax.

## Local commits

- `ac924200e` — `Phase 0.0.0: Recover account charge projection dead letters`
- `c5c97ec5d` — `Phase 0.0.1: Enforce stamp duty country capability`
- `0e98d9ae6` — `Phase 0.0.2: Isolate stamp duty document totals`
- `5852f516d` — `Phase 0.0.3: Fail closed on missing purpose alert writes`
- `e163a9914` — `Phase 0.0.4: Align accounting gap tests with PostgreSQL`

## TDD and revert-replay evidence

Every production fix was first observed red and then mutation-checked by temporarily reverse-applying only its production patch while retaining the committed tests.

- C reverted: both recovery-command probes failed because `--event-type` did not exist. Restored: 2 passed, 10 assertions.
- G reverted: registry failed on missing `supportsStampDuty()` and the modal failed because unsupported controls remained enabled. Restored: registry/API/modal paths passed.
- H reverted: TN signed total changed `101.000 → 108.000`; live credit note changed `50.000 → 57.000`. Restored: both probes passed with 14 assertions.
- I reverted: the forced audit failure was swallowed and the projection was acknowledged. Restored: retry/rollback/replay probe passed with 12 assertions.

## Final verification evidence

No full PHPUnit suite was run, per dispatch. The final backend matrix was limited to the nine directly affected paths.

### SQLite

```text
php artisan test \
  tests/Feature/Accounting/BackfillChartPurposesCommandTest.php \
  tests/Feature/Fiscal/RetryFiscalProjectionsCommandTest.php \
  tests/Feature/Fiscal/TaskPhase3AccountChargeFullFlowTest.php \
  tests/Unit/Taxation/CountryTaxConfigurationRegistryTest.php \
  tests/Feature/Taxation/TaxConfigurationManagementTest.php \
  tests/Feature/Taxation/TaxConfigManageSeededRoleGrantHttpTest.php \
  tests/Unit/Taxation/TaxCalculationServiceTest.php \
  tests/Feature/Document/CreditNoteMoneyLaneTest.php \
  tests/Feature/Treasury/TreasuryReceiptBridgeRoundingGlTest.php

76 passed, 446 assertions, 34.41s
```

### PostgreSQL 15.15 on 127.0.0.1:5432

Dedicated database: `autoerp_cghi_test`; user: `houssamr`.

```text
DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=autoerp_cghi_test DB_CENTRAL_DATABASE=autoerp_cghi_test \
DB_USERNAME=houssamr DB_PASSWORD='' \
php artisan test -c phpunit-pgsql.xml <the same nine paths>

76 passed, 446 assertions, 49.33s
```

The first PG run exposed three test-fixture portability defects: a paired-null fiscal-event field, two over-length tax codes against `varchar(20)`, and PostgreSQL's required `ON audit_events` trigger-drop clause. Each root cause was corrected and the identical matrix was rerun green.

### Frontend

```text
pnpm exec vitest run \
  src/components/organisms/TaxConfigFormModal/TaxConfigFormModal.test.tsx \
  src/features/settings/hooks/__tests__/useTaxConfigurations.tenantScope.test.tsx \
  src/features/settings/TaxSettingsPage.test.tsx

3 files passed; 11 tests passed

pnpm typecheck
passed

pnpm exec eslint <changed TypeScript paths>
0 errors; 11 existing-line warnings

npx react-doctor@latest --verbose --scope changed --base dev
Score 90/100; 7 changed files scanned; no issues found
```

The React test run still prints the suite's existing `act(...)` warnings. No new React Doctor findings or ESLint errors were introduced.

### PHP formatting and static analysis

```text
./vendor/bin/pint <all touched PHP production and test paths>
passed

./vendor/bin/phpstan analyse --memory-limit=2G \
  app/Modules/Fiscal/Infrastructure/Commands/RetryFiscalProjectionsCommand.php \
  app/Modules/Taxation/Application/Registries/CountryTaxConfigurationRegistry.php \
  app/Modules/Taxation/Domain/Services/TaxCalculationService.php \
  app/Modules/Taxation/Presentation/Controllers/TaxConfigurationController.php \
  app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php

No errors
```

An exploratory PHPStan run including entire legacy test files reported 40 existing test-analysis findings (mainly nullable collection indexing, broad array annotations, and existing pending-command union types). Production touched files are clean. PHPUnit also reports the file's pre-existing doc-comment metadata deprecation warnings.

## Residual scope

- Non-stamp document-total charges remain intentionally unavailable until the own-named money lane ticket is designed and implemented end to end.
- The dedicated local PostgreSQL database `autoerp_cghi_test` remains available for reruns; no production or shared database was dropped or rewritten.
- The branch is intentionally local and unmerged. Because `dev` advanced independently during execution, integration should merge/rebase the then-current `dev` and rerun these same path-scoped matrices.

---

# Fix round 1 (2026-08-10)

Closes the three gate verdicts in `docs/sessions/gate-verdict-{CH-fiscal-pos,G-tenancy-authz,I-treasury}-reviewer-2026-08-10.md`. Seven commits, `06448c57c..807a92253`, 28 files. Branch still local and unpushed.

## Commits

| Commit | Scope |
|---|---|
| `06448c57c` | C1–C8 — `fiscal:retry-projections` `--sync` dead-letter hole + filter validation + tenant narrowing |
| `158595107` | G1, G5–G7 — symmetric stamp-duty rule, merged-state tests, backend i18n, typed registry const |
| `f6d848416` | H1, H2 — structured warning on skipped non-stamp DOCUMENT_TOTAL, hash-pin scope comment |
| `f88edd934` | G2–G4, G8 — modal create path, fail-closed states, capability-error hint, staleTime rationale |
| `56e4fd510` | I1–I3 — drawer-figure assertions, savepoint docblock correction, try/finally fixture |
| `2f847a785` | react-doctor: extract the stamp-duty hint helper out of the modal |
| `807a92253` | T-A…T-D tickets + own-named-document-total extension |

## Red-first evidence

| Fix | Observed red | Green |
|---|---|---|
| **C1 (P1)** | `--sync` replay of a re-failing projector left the row `Pending` — `assertSame(DeadLettered)` failed with `Enum #9753 (Pending, 'pending')`; row also invisible to `--dry-run` | `$job->failed($e)` in the catch; DeadLettered + `dead_lettered_at` + `last_error`, visible to the inventory |
| **C2** | `--projector=treasury_account_charge_bridg` and `--event-id=not-a-uuid` both exited `0` | validated against the injected registry / `Str::isUuid()`; exit `INVALID` |
| **C3** | 4 data-provider cases (`--tenant=`, `--event-type=`, `--projector=`, `--event-id=`) all exited `0` | explicit-empty is a usage error; `stringOption()` untouched fleet-wide |
| **C4** | n/a (structural) | `forEachTenantNarrowed($tenantFilter, …)`; narrowing moved into the directory query |
| **C5** | n/a — clearing `CompanyContext` did **not** break the flow, which is the useful finding: the projector genuinely works under worker reality, so the previously-bound context was masking nothing real. Now pinned. | context cleared before both `handle()` and `Artisan::call()` |
| **C6/C7/C8** | test-hardening; C8's second replay is a real behavioural assertion (the command refuses to re-drive an `Applied` row: not a `candidateRows()` candidate, and `isRetryable()` rejects it) | `assertCount(4, lines)` + bcmath Σdebits === Σcredits === `'119.000'`; `$this->fail()` lifted out of the swallowing catch; single journal entry after replay |
| **G1** | TN store `LINE_ITEMS`+stamp → **200**; partial PATCH `{is_stamp_duty:true}` on a LINE_ITEMS row → **200**; PATCH `{applies_to:'LINE_ITEMS'}` on a stamp row → **200** | all three 422 on `is_stamp_duty`, evaluated on merged state |
| **G2** | `expected [ 'SUBTOTAL', 'TOTAL_INCLUDING_PREVIOUS' ] to include 'BASE_AMOUNT'` — the modal's create payload | `stacks_on` default and type aligned to the backend enum |
| **G4** | capability `isError` rendered `stampDutyUnavailable` ("not available for this company's country") | new `stampDutyCapabilityUnavailable` key, en + fr |
| **G3** | n/a — passes without a production change, which is the point: `capabilities?.supports_stamp_duty === true` already fails closed on `undefined`. Now proven rather than assumed. | stamp checkbox + DOCUMENT_TOTAL option both disabled |
| **H1** | `Log::spy()` → warning "called 0 times" | structured warning with config id/code/name, country, company, document id/type/date; money behaviour unchanged (`documentTaxTotal '0'`, `total '100.000'`) |
| **I1** | no production change (test-coverage finding). The new assertions are demonstrably non-vacuous: the movement count first failed on a wrong column (`repository_id` vs `payment_repository_id`) and the balance first failed on SQLite's unpadded `'0'` vs `'0.000'` | after failure `balance '0.000'` + 0 movements; after retry `'9.950'` + exactly 1 movement, string-compared |

Mutation check on the item-I chain: removing the load-bearing `throw $e;` from `recordTolerancePurposeMissingAlertOrFail()` fails the test at `assertNotNull($thrown)` — confirming the rethrow, not the savepoint, is what makes the path fail-closed (the premise behind I2's docblock correction). File restored byte-identical (`git diff` empty) before proceeding.

## G6 finding — the API does have a backend i18n convention

The brief asked this to be checked and recorded. **It exists and is now used.** Evidence: `lang/en/` + `lang/fr/` hold eight parallel namespace files each; `SetLocale` is registered in the api middleware stack (`bootstrap/app.php:137`); ~12 of the 60 `ValidationException::withMessages()` call sites already resolve through it (`AuthController` → `__('auth.invalid_credentials')`, `ExpenseRecurrenceController` → `__('validation.after_or_equal')`). Adoption is partial, not absent.

All three `validateDocumentTotalPolicy()` messages therefore moved to a new `lang/{en,fr}/taxation.php`, mirroring the single-key `lang/fr/treasury.php` precedent. Tests assert the error **key**, not the message text, so this is non-breaking.

## Final verification

| Gate | Result |
|---|---|
| Backend, SQLite, 9-path matrix | **89 passed / 520 assertions** (was 76 / 446) |
| Backend, PostgreSQL 15.15, same 9 paths | **89 passed / 520 assertions** — identical |
| Frontend vitest, 3 paths | **14 passed** (was 11); pre-existing `act(...)` warnings unchanged |
| `pnpm typecheck` | pass |
| eslint, changed FE files | **0 errors**, 10 warnings — all on pre-existing lines (`step="0.01"`, type assertions); zero introduced |
| react-doctor `--scope changed --base dev` | **90 / 100, no issues** (transiently 89 with `no-giant-component`; resolved by extracting the hint helper rather than refactoring a 340-line modal mid-round) |
| `./vendor/bin/pint --test`, touched PHP | pass |
| phpstan level 8, 5 touched production files | **No errors** |

`tests/Feature/Fiscal/PosBridgeLocationAttributionTest.php` fails `pint --test` — pre-existing drift, untouched by this round and deliberately left alone (rule 4).

## Deviations from the brief

1. **C1's test lives in `RetryFiscalProjectionsCommandTest`, not the Phase-3 flow test.** The brief suggested forcing a second failure in `TaskPhase3AccountChargeFullFlowTest` via a second missing purpose. A dedicated always-throwing projector in the command's own test exercises the identical production line (`RetryFiscalProjectionsCommand.php` sync catch) far more directly, and additionally asserts the `--dry-run` re-visibility the verdict asked for. The Phase-3 test still received C5–C8.
2. **G2 required touching four extra test files.** `'BASE_AMOUNT'` appeared as a fixture literal in `TaxConfigurationSelect`, `TaxConfigurationField`, `AddQuickProductModal` and `useTaxConfigurations.tenantScope` tests; correcting the type without them fails `pnpm typecheck`. Mechanical literal swap only. `tax.ts` was confirmed hand-written (it lives in `src/features/settings/types/`, not the generated `packages/shared/types/`), so rule 7 does not apply.
3. **One extra commit (`2f847a785`)** beyond the planned groups, to keep react-doctor at its 90/100 baseline.

## Not done (explicitly out of scope, per the brief)

F-1 code fix (`country_code` immutability — owner ruling ticket T-B instead), the `--include-applied` re-post command (T-A), alert-discipline unification (T-C), the `code` column widening (T-D), and the typed service-layer refusal for non-stamp DOCUMENT_TOTAL (folded into the own-named-document-total ticket).

One in-scope observation deliberately **not** acted on: in `--sync` mode a projection that dead-letters through `ApplyFiscalEventProjectionJob`'s `NonRetryableProjectionException` branch returns normally (`InteractsWithQueue::fail()` is a no-op when `$this->job` is null), so the command counts it as `syncAppliedCount++` and reports success. The row's state is correct — `deadLetterImmediately()` already wrote it — only the console tally is wrong. Reporting rather than fixing, since no verdict finding covers it and it changes the command's exit-code contract.
