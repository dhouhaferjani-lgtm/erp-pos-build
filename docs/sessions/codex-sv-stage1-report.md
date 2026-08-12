# Codex shift-variance Stage 1 report

## Session identity

- Brief: `docs/handoff/CODEX-DISPATCH-sv-stage1-2026-08-11.md`
- Branch: `codex/sv-stage1`
- Worktree: `.worktrees/sv-stage1`
- Reviewed content baseline: `df85d43f404a9e55fd29b0e3c7533852966652df`
- Administrative dispatch HEAD: `a5520f23ca39209f5b517723037e9516808f2bca`
- Local `dev` record at dispatch: `a5520f23ca39209f5b517723037e9516808f2bca 2026-08-12 08:23:06 +0100 harness: M0 base check = ancestor+digest+admin-only-delta (fixes self-referential pin equality)`
- Evidence stamp: `a5520f23c` (commit marker; no wall clock used)

## M0 — preflight

### Amended base check

The worktree was created from the current local `dev` tip, with `df85d43f4` retained as the reviewed content baseline.

Command results:

```text
git merge-base --is-ancestor df85d43f404a9e55fd29b0e3c7533852966652df HEAD
ancestor_check=PASS

contract_digest=04760455ac3f80b96502884e9efc2a8f00c35785d2126a9f9786d96d97e20540
digest_check=PASS

git diff --stat df85d43f404a9e55fd29b0e3c7533852966652df..HEAD
 .../handoff/CODEX-DISPATCH-es-wave-a0-2026-08-11.md | 18 ++++++++++++++++--
 docs/handoff/CODEX-DISPATCH-sv-stage1-2026-08-11.md | 21 ++++++++++++++++++---
 docs/handoff/progress/es-wave-a0.progress.yaml      | 20 ++++++++++++++------
 docs/handoff/progress/sv-stage1.progress.yaml       | 20 ++++++++++++++------
 4 files changed, 62 insertions(+), 17 deletions(-)

changed_paths:
docs/handoff/CODEX-DISPATCH-es-wave-a0-2026-08-11.md
docs/handoff/CODEX-DISPATCH-sv-stage1-2026-08-11.md
docs/handoff/progress/es-wave-a0.progress.yaml
docs/handoff/progress/sv-stage1.progress.yaml
admin_delta_check=PASS
clean_worktree_check=PASS
```

No non-administrative path occurs in the baseline-to-dispatch delta.

### Binding rulings and stage boundary

- Whole-drawer counting is confirmed. The cashier counts everything physically in the drawer. Variance is counted total minus expected, where expected equals opening float plus net cash movements: `variance = counted total − (opening float + net cash movements)`. Takings are derived and displayed, not counted.
- Blind counting is ruled on everywhere, meaning both the Otospex/mechanic and IziPOS/retail verticals.
- `ReportGenerationService::buildExpectedPerMethod()` is takings-only dead code. It must be buried and must not be used to reinterpret production count semantics.
- Stage 1 contains exactly SV-1, SV-9, SV-10, and SV-11. It ships independently of the GL-flag prerequisites.
- The wave must not flip `TREASURY_SHIFT_VARIANCE_GL_ENABLED`, add/retire/rewire events, change GL posting shape, or answer D-3/D-4/D-5/D-6/D-7/D-17.

### Citation inventory

The `old` column is the citation printed in the dispatch/dossier. The `resolved` column is the symbol/anchor re-derived at the reviewed baseline and unchanged administrative HEAD. Source files did not change between `df85d43f4` and `a5520f23c`.

| Row | File | Old citation → resolved line | Symbol and semantic anchor |
|---|---|---|---|
| SV-1 | `apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php` | `84-89 → 84-89` | `assertServerReportAuthoringAllowed()` rejects schema v3+ server authoring. |
| SV-1 | same | `219 → 219` | `generateZReport()` assigns live report `expected_cash` from `CashDrawerService::calculateExpectedCash()`. |
| SV-1 | same | `233-241 → 233-244` | Nullable cash-count branch calls `buildExpectedPerMethod()` before validation. |
| SV-1 | same | `492-583 → 491-583` | Takings-only docblock and private `buildExpectedPerMethod()` implementation. Receipt sum is line 528, change aggregation is 532-555, and zero-fill is 576-581. |
| SV-1 | `apps/api/app/Modules/POS/Domain/Services/CashDrawerService.php` | `387-413 → 387-413` | Live whole-drawer `calculateExpectedCash()` walks OPENING/SALE/REFUND/DEPOSIT/PAYOUT drawer operations. Scope fence: untouched. |
| SV-1 | `apps/api/app/Modules/POS/Presentation/Requests/GenerateZReportRequest.php` | `61-68 → 61-68` | `cash_counts` remains nullable and its row fields are validated. |
| SV-1 | `apps/web/src/features/pos/api/shiftApi.ts` | `68-70 → 68-70` | `ZReportData` contains `terminal_id` only. |
| SV-1 | `apps/pos/src/api/reportApi.ts` | `246 → 242-246` | Deprecated `generateZReportServer()` posts `terminal_id` only. |
| SV-1 | `apps/api/config/treasury.php` | `19-25 → 19-25` | `shift_variance_gl_enabled` policy comment states the refuted premise. |
| SV-1 | `apps/api/app/Modules/Treasury/Application/Listeners/PostShiftCashVarianceAdjustment.php` | `44-52 → 44-52` | Class docblock `SHIPS DISABLED` repeats the refuted premise; the actual kill-switch branch is 152-157. |
| SV-1 | `docs/superpowers/tickets/2026-08-08-g3-shift-variance-gl-deploy-notes.md` | `53-58 → 51-58` | G-1 states the same refuted pre-enable rationale. |
| SV-1 | `apps/api/tests/Feature/POS/CashCountToleranceVarianceRegressionTest.php` | `79-81 → 79-81` | Historical schema-vs-business-meaning parenthesis to preserve durably. |
| SV-1 | `docs/superpowers/plans/2026-05-12-pos-go-live-plan-v1.md` | `52 → 52` | Earlier plan identifies the recomputation as zero-client production POS code. |
| SV-1 | `docs/superpowers/plans/2026-05-12-pos-go-live-plan-v2.md` | `7 → 7` | Later plan retains the method on the now-refuted caller premise. |
| SV-1 | `apps/api/tests/Feature/POS/ServerReportAuthoringUnreachabilityTest.php` | named guard → class line 49 | Chokepoint regression guard. |
| SV-1 | `apps/api/tests/Feature/Fiscal/ZReportServerAuthoringChokepointTest.php` | named guard → class line 12 | Fiscal call-site inventory/chokepoint guard. |
| SV-9 | `apps/api/app/Modules/Compliance/Domain/CompanyFraudSettings.php` | `58,120,144,179,190-193,201 → same` | Model attribute, fillable, cast, `getDefaults()`, vertical docblock, and `defaultsForVertical()` override. |
| SV-9 | `apps/api/database/migrations/tenant/2026_04_25_000004_add_cash_variance_settings_to_company_fraud_settings.php` | `19 → 19` | Column default is false. |
| SV-9 | `apps/api/database/migrations/tenant/2026_04_25_100001_seed_company_fraud_settings_for_existing_companies.php` | `40 → 37-43` | One-off seed derives vertical defaults and creates a row once. |
| SV-9 | `apps/api/app/Modules/POS/Application/Services/FraudSettingsResolver.php` | `30,45 → 30,45` | No-row default and persisted-row value. |
| SV-9 | `apps/api/app/Modules/Compliance/Presentation/Controllers/FraudSettingsController.php` | `35,120 → 35,120` | Admin key allowlist and boolean update validation. |
| SV-9 | `apps/api/app/Modules/Compliance/Application/DTOs/CompanyFraudSettingsData.php` | `44,75,105 → same` | DTO property, persisted mapping, default mapping. |
| SV-9 | `apps/web/src/features/compliance/components/CashDrawerControlsSection.tsx` | `13,52,68,76,90,99,146,291,297 → same` | Typed value, local/fallback state, controlled update, and translated toggle. |
| SV-9 | `apps/web/src/features/compliance/pages/FraudSettingsPage.tsx` | `55,396 → 55,396` | Initial false and row-less form fallback that can silently persist false. |
| SV-9 | `apps/web/src/locales/{en,fr}/compliance.json` | `109 → 109` | Blind-count toggle translations. The web Arabic tree exists, but `ar/compliance.json` does not yet exist and will be created/registered in M3 as required. |
| SV-9 | `apps/pos/src/lib/db/migrations.ts` | `518 → 518` | Device cache `require_blind_cash_count INTEGER NOT NULL DEFAULT 0`. |
| SV-9 | `apps/pos/src/lib/db/companyFraudSettingsCacheRepository.ts` | named surface → repository symbols resolved | Cache reader/writer owns the post-sync setting; the SQLite default governs only pre-first-sync behavior. |
| SV-9 | `apps/pos/src/components/pos/CashReconciliationSection.tsx` | `84,87,229,260 → same` | Blind flag, committed initial state, committed payload flag, and Commit Counts reveal control. |
| SV-9/SV-10 | `apps/pos/src/components/pos/organisms/CashCountTable.tsx` | `64-65 → 64-65` | `showExpected` and `showVariance` reveal only when non-blind or committed. |
| SV-9 | `apps/api/tests/Unit/Compliance/CompanyFraudSettingsVerticalDefaultsTest.php` | `53 → 53` | Retail false assertion is explicitly red-by-design for M3. |
| SV-10 | `apps/pos/src/components/pos/EndOfDayPreviewModal.tsx` | `239-262 → 240-262` | Security comment and `!cashCountEnabled` guard suppress the legacy expected-cash card. |
| SV-10 | `apps/pos/src/components/pos/CashReconciliationSection.tsx` | `137,165-180 → 137-157,164-188` | Per-tender variance magnitude and signed aggregate severity derivations to audit by render path. |
| SV-11 | `apps/pos/src/locales/{en,fr}/pos.json` | `cash_count.* → object begins 906; expected/actual/variance 912-914` | Device translation namespace and existing Écart wording. |
| SV-11 | `apps/pos/src/components/pos/EndOfDayPreviewModal.tsx` | `252-260 → 252-260` | Existing non-blind opening and expected figures. |
| SV-11 | `apps/pos/src/lib/offline/endOfDayPreview.ts` | named symbol → `EndOfDayPreview` 90-113; formula 406-480 | Existing presentation inputs: opening cash, net cash sales, drawer net, and expected cash; UI must not recompute them. |

Unresolved citations: **0** (in-repository scope).

The SV-9 dossier note also names `project_live_counting_completion_lane.md:40`, a memory-directory reference that is not present in this repository. It cannot be resolved from the dispatched tree and is not counted as an in-repository citation.

### Device Arabic finding

At the reviewed baseline:

- `apps/pos/src/locales/` contains only `en/` and `fr/`.
- `apps/pos/src/lib/i18n.ts` imports and registers only those two languages.
- It sets `lng: 'en'`, `fallbackLng: 'en'`, and four namespaces: `common`, `pos`, `smart-prompts`, `fiscal`.
- Creating a device `ar` tree would open a new RTL surface across touch layout, logical direction, and numeric/currency rendering. M2 therefore ships en+fr, files `docs/superpowers/tickets/2026-08-11-pos-device-arabic-locale.md`, and proceeds. Gate `sv11-arabic-device-locale` is informational only.

### M3 prerequisite guard plan

Before M3's first code commit, each item will be walked and recorded against the same question: does this change genuinely require G-3 (the disabled-window backfill command) or another Stage-5 policy gate? If any answer is yes, M3 becomes `blocked_owner`; otherwise the recorded answer permits Stage 1 to proceed.

1. Backend model attributes and `getDefaults()` default.
2. `defaultsForVertical()` override and the red-by-design retail test.
3. Self-guarding data migration of already-persisted false settings rows.
4. Historical column default in the 2026-04-25 schema migration (optional touch point).
5. Web initial state and row-less form fallback/round-trip.
6. Device SQLite cache default, which controls pre-first-sync behavior only.

The migration will additionally be checked for unattended `tenants:migrate` safety, idempotency, table/column guards, warning-level completion token, and per-tenant changed/skipped counts. It will touch settings rows only.

### Declared regression set and baseline

All commands are path-scoped. The full PHPUnit suite is forbidden.

- API: 23 explicit cash-count/fraud-settings/fiscal-hash/shift-variance files across the resource-scoped commands below. This is a declared file-level narrowing from the dispatch's suite-directory wording: a directory-wide run would include hundreds of unrelated fiscal, POS, and Treasury tests plus known broken neighbors, while Stage 1 changes only the enumerated symbols and payload surfaces. The narrowing is based on searches for `cash_counts`, `buildExpectedPerMethod`, `expected_per_method`, `require_blind_cash_count`, and the named Stage-1 classes; every matching covering file found for the M1 removal branch is included below. `GenerateZReportWithCountsTest.php` covers the legacy branch itself, and `FiscalStatusFilterTest.php` pins its pending-seal semantics.
- Device: eight explicit files across the commands below. The search basis is `require_blind_cash_count|requireBlindCashCount|blindCount|isBlind` plus the named M2/M4 presentation components and `endOfDayPreview`; every matching covering test file is included, while unrelated fixture-only hits are excluded. This covers the migration-v22 DDL, fraud-settings cache/API mapping, device-authored `report_data.cash_counts` and `blindCountUsed`, and reveal presentation.
- Web: `src/features/compliance/components/CashDrawerControlsSection.test.tsx`; M3 will add the row-less `FraudSettingsPage` round-trip path.

Baseline results:

```text
Aggregate declared API baseline: 108 test cases, 9302 assertions across four resource-scoped PostgreSQL commands

Core command on fresh dedicated `autoerp_sv_stage1_m0r2_test`:

  Tests:    63 warnings (9117 assertions)
  Duration: 57.80s

Device Vitest:
Test Files  6 passed (6)
Tests       74 passed (74)

Device payload/API expansion:
Test Files  2 passed (2)
Tests       40 passed (40)

Web Vitest:
Test Files  1 passed (1)
Tests       8 passed (8)
```

Literal current API baseline command (M0 review fix round 2; exit 0):

```bash
APP_ENV=testing LOG_LEVEL=warning \
DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=autoerp_sv_stage1_m0r2_test \
DB_CENTRAL_DATABASE=autoerp_sv_stage1_m0r2_test \
DB_USERNAME=houssamr DB_PASSWORD='' \
php artisan test -c phpunit-pgsql.xml \
  tests/Feature/Fiscal/ZReportServerAuthoringChokepointTest.php \
  tests/Feature/Fiscal/ZReportServerAuthoringDispositionTest.php \
  tests/Feature/POS/ServerReportAuthoringUnreachabilityTest.php \
  tests/Feature/POS/CashCountToleranceVarianceRegressionTest.php \
  tests/Feature/POS/FraudSettingsPosControllerContractTest.php \
  tests/Feature/POS/FraudSettingsPosControllerTest.php \
  tests/Feature/POS/GenerateZReportWithCountsTest.php \
  tests/Unit/POS/FraudSettingsResolverTest.php \
  tests/Unit/POS/CashCountInputDTOTest.php \
  tests/Unit/Compliance/CompanyFraudSettingsCashControlsTest.php \
  tests/Unit/Compliance/CompanyFraudSettingsVerticalDefaultsTest.php \
  tests/Feature/Compliance/FraudSettingsControllerCashControlsTest.php \
  tests/Feature/Compliance/FraudSettingsControllerContractTest.php \
  tests/Feature/Treasury/ShiftCashVarianceOfflineDevicePayloadTest.php \
  tests/Feature/Treasury/ShiftCashVarianceTriggerPathsTest.php
```

Literal current device baseline command (exit 0):

```bash
pnpm vitest run \
  src/lib/offline/__tests__/endOfDayPreview.test.ts \
  src/components/pos/CashReconciliationSection.test.tsx \
  src/components/pos/organisms/CashCountTable.test.tsx \
  src/components/pos/EndOfDayPreviewModal.test.tsx \
  src/lib/db/__tests__/migration22.integration.test.ts \
  src/lib/db/repositories/__tests__/companyFraudSettingsCacheRepository.test.ts
```

Literal device payload/API expansion (exit 0):

```bash
pnpm vitest run \
  src/lib/offline/__tests__/zReportService.test.ts \
  src/api/__tests__/fraudSettingsApi.test.ts
```

```text
Test Files  2 passed (2)
Tests       40 passed (40)
```

Literal M1 symbol guard command (fresh `autoerp_sv_stage1_m0r3_guard_test`; exit 0):

```bash
APP_ENV=testing LOG_LEVEL=warning \
DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=autoerp_sv_stage1_m0r3_guard_test \
DB_CENTRAL_DATABASE=autoerp_sv_stage1_m0r3_guard_test \
DB_USERNAME=houssamr DB_PASSWORD='' \
php artisan test -c phpunit-pgsql.xml \
  tests/Feature/POS/FiscalStatusFilterTest.php
```

```text
  Tests:    3 warnings (7 assertions)
  Duration: 12.96s
```

One combined 16-file diagnostic run observed a transient PostgreSQL lock-table exhaustion (`SQLSTATE[53200]: out of shared memory`) under concurrent host load; the following `25P02` was the aborted-transaction consequence. The round-4 reviewer later reproduced the combined 16-file set green (66 cases / 9124 assertions), so this is recorded as a one-off observation, not a standing limit or justification for future narrowing. The separately recorded fresh-database commands are green, and no application or test code was changed to mask host-capacity noise.

Literal six-file fiscal/request/persistence expansion (fresh `autoerp_sv_stage1_m0r4_six_test`; exit 0):

```bash
APP_ENV=testing LOG_LEVEL=warning \
DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=autoerp_sv_stage1_m0r4_six_test \
DB_CENTRAL_DATABASE=autoerp_sv_stage1_m0r4_six_test \
DB_USERNAME=houssamr DB_PASSWORD='' \
php artisan test -c phpunit-pgsql.xml \
  tests/Feature/POS/ZReportSyncControllerSchema2Test.php \
  tests/Feature/POS/GenerateZReportEndToEndTest.php \
  tests/Feature/POS/GenerateZReportRequestValidationTest.php \
  tests/Feature/POS/HashGoldenByteTest.php \
  tests/Integration/POS/HashGoldenByteTest.php \
  tests/Unit/POS/HashInputScale4EquivalenceTest.php
```

```text
  Tests:    41 warnings (174 assertions)
  Duration: 68.49s
```

Literal cross-tenant `cash_counts.payment_method_id` guard (fresh `autoerp_sv_stage1_m0r4_tenant_guard_test`; exit 0):

```bash
APP_ENV=testing LOG_LEVEL=warning \
DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=autoerp_sv_stage1_m0r4_tenant_guard_test \
DB_CENTRAL_DATABASE=autoerp_sv_stage1_m0r4_tenant_guard_test \
DB_USERNAME=houssamr DB_PASSWORD='' \
php artisan test -c phpunit-pgsql.xml \
  tests/Feature/POS/PosStabilizationTenantIsolationTest.php \
  --filter test_generate_z_report_refuses_cross_tenant_payment_method_id_in_cash_counts
```

```text
  Tests:    1 warning (4 assertions)
  Duration: 33.23s
```

The full tenant-isolation file has five unrelated PostgreSQL fixture failures in order-management cases: helper lines 1557/1704 write string values such as `SHIFT-B-jqsY` into the integer `pos_shifts.shift_number` column. The exact Stage-1 cash-count tenant guard above is green and is the only test in that broad file selected by the M1 payload search.

Literal current web baseline command (exit 0):

```bash
pnpm vitest run src/features/compliance/components/CashDrawerControlsSection.test.tsx
```

The scoped commands intentionally omit these known-broken neighboring tests from green claims:

- `tests/Unit/POS/CashCountValidationServiceTest.php` — stale 8-argument `FraudSettingsDTO` construction versus the current 11-argument contract.
- `tests/Feature/Treasury/ShiftCashVarianceAdjustmentTest.php` — a PostgreSQL fixture directly updates the trigger-protected repository balance.
- Five order-management methods in `tests/Feature/POS/PosStabilizationTenantIsolationTest.php` — stale string `shift_number` fixtures conflict with the PostgreSQL integer schema. The Stage-1 cash-count tenant method is included explicitly and passes.

There is no PHPUnit `--exclude` switch in the literal command because every included file is named explicitly; the two omissions above are therefore mechanically visible rather than hidden by a directory-wide selection.

The API command exits zero, but every test is marked WARN because `apps/api/.env` is absent in the linked worktree and `vlucas/phpdotenv` emits `file_get_contents(.../apps/api/.env): Failed to open stream` from `tests/TestCase.php:26`. This is test-environment noise, not source-inventory noise, and the baseline is not claimed pristine. The command explicitly sets `APP_ENV=testing` and `LOG_LEVEL=warning`; `TREASURY_SHIFT_VARIANCE_GL_ENABLED` remains unset and `config/treasury.php` therefore supplies its false default. Other environment-driven settings use their PHPUnit/process/framework defaults.

For M3, the migration test will continue to force `LOG_LEVEL=warning` and will spy on the logging facade to assert that the exact completion token and changed/skipped counts are sent through `Log::warning()`. That proves warning-level visibility directly rather than inferring it from the default logging threshold. Test failures and assertion counts remain the regression signal; WARN-count deltas are not used because the missing `.env` marks every case WARN.

### Forward work discovered during M0 review

- M1's enumerated consumer grep must include and repair the stale production comment at `apps/api/app/Modules/Accounting/Application/Services/Reports/SalesReportService.php:258-259`, which hard-codes a line range into `buildExpectedPerMethod()`.
- M1 deletion must distinguish the 233-311 legacy cash-count branch from the private takings-only formula it calls. That branch also contains `buildTransactionCountsPerMethod()`, variance-reason and manager-PIN orchestration, the cross-tenant/permission `assertManagerCanOverride()` guard, and sealed hash-input keys `schema_version`, `cash_counts`, `variance_summary`, and `tolerance_summary`. Delete only the dead server-authoring branch after its existing behavior tests turn red as expected; do not move or reinterpret those keys on live device/sync paths.
- M3 must update the retired vertical-split docblock at `apps/api/app/Modules/Compliance/Application/Services/CompanyFraudSettingsService.php:22-25` when both verticals become blind-by-default.
- M3 must inspect `apps/pos/src/components/Header.tsx:130,152,197,380`, the orchestrator that syncs `require_blind_cash_count` into SQLite and stamps `blindCountUsed` alongside device-authored count payloads. M4's render-path audit starts from this component, although its existing test stubs the modal and cannot cover the Stage-1 presentation changes.
- The dispatch brief's amended M0 admin allowlist describes the dispatch-time comparison only. Required post-dispatch evidence under `docs/handoff/reviews/sv-stage1/` and `docs/sessions/` is not reclassified as baseline contamination; every subsequent review still independently verifies that the branch delta contains no production path before M1.

An intentionally broader candidate run also exposed two pre-existing out-of-scope reds, neither caused by this branch:

1. `tests/Unit/POS/CashCountValidationServiceTest.php`: 13 tests construct `FraudSettingsDTO` with 8 arguments although the current constructor requires 11.
2. `tests/Feature/Treasury/ShiftCashVarianceAdjustmentTest.php::a shortfall larger than the till balance...`: the PostgreSQL fixture directly updates `payment_repositories.balance`, which the database trigger correctly refuses because balances may change only through `TreasuryMovementService`.

Those files are not weakened or repaired in Stage 1. Relevant passing Treasury trigger-path and device-payload tests remain in the regression set. Any Stage 1 change that directly reaches either broken contract must add an in-scope covering path rather than claiming the pre-existing red is green.

### M0 files touched

- `docs/handoff/progress/sv-stage1.progress.yaml`
- `docs/sessions/codex-sv-stage1-report.md`
- `docs/handoff/reviews/sv-stage1/M0-round*.md` (reviewer-generated registers)

No production code changed.
