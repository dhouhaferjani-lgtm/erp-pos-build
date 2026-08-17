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

## M1 — SV-1 takings-only retirement

### Retirement choice

M1 uses the permitted `@deprecated` shape rather than deleting the 233-311 branch. The enumeration shows that the private formula has one production call site, inside the nullable legacy server cash-count branch, while that branch also owns manager authorization and sealed hash-input fields. Both shipped server-authoring clients omit `cash_counts`, and schema-v3 terminals are rejected before the branch. Retaining the compatibility branch avoids deleting unrelated permission and hash behavior; the annotation states that the helper is takings-only, has no shipped client, and production is whole-drawer via the device.

### Enumerated grep proof

Commands use the required literal include and extended-regex forms, print one line after every match, and end with known-match controls proving the syntax:

```bash
grep -R -n -E -A1 --include='*.php' 'buildExpectedPerMethod' apps/api/app apps/api/tests
grep -R -n -E -A1 --include='*.php' 'cash_counts' \
  apps/api/app/Modules/POS/Presentation/Requests/GenerateZReportRequest.php \
  apps/api/app/Modules/POS/Presentation/Controllers/ReportController.php
grep -n -E -A1 --include='*.ts' --include='*.tsx' \
  'ZReportData|generateZReportServer|cash_counts' \
  apps/web/src/features/pos/api/shiftApi.ts apps/pos/src/api/reportApi.ts
```

Actual production symbol output:

```text
apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:234:                $expectedPerMethod = $this->buildExpectedPerMethod($shift, $cashCountInputs);
apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php-235-                $transactionCounts = $this->buildTransactionCountsPerMethod($shift, $cashCountInputs);
--
apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:518:    private function buildExpectedPerMethod(Shift $shift, array $inputs): array
apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php-519-    {
```

Actual request/client output:

```text
apps/api/app/Modules/POS/Presentation/Requests/GenerateZReportRequest.php:61:            'cash_counts' => 'nullable|array',
apps/api/app/Modules/POS/Presentation/Requests/GenerateZReportRequest.php-62-            // api.pos-stabilization.029 — payment_methods (T+C).
--
apps/api/app/Modules/POS/Presentation/Controllers/ReportController.php:138:            // Build cash-count input DTOs when cash_counts is present (non-empty array).
apps/api/app/Modules/POS/Presentation/Controllers/ReportController.php-139-            $cashCountInputs = null;
apps/web/src/features/pos/api/shiftApi.ts:68:export interface ZReportData {
apps/web/src/features/pos/api/shiftApi.ts-69-  terminal_id: string
--
apps/web/src/features/pos/api/shiftApi.ts:108:export async function generateZReport(data: ZReportData): Promise<void> {
apps/web/src/features/pos/api/shiftApi.ts-109-  return apiPost<void>('/pos/reports/z', data)
--
apps/pos/src/api/reportApi.ts:245:export async function generateZReportServer(terminalId: string): Promise<ZReportResponse> {
apps/pos/src/api/reportApi.ts-246-  return apiPost<ZReportResponse>('/pos/reports/z', { terminal_id: terminalId });
```

The wider device matches at `reportApi.ts:81` and `:204` are the live offline-report sync payload, not calls to deprecated `generateZReportServer()`; they must retain device-authored `cash_counts`.

Known-match controls returned:

```text
apps/api/app/Modules/POS/Application/Services/ReportGenerationService.php:234:                $expectedPerMethod = $this->buildExpectedPerMethod($shift, $cashCountInputs);
245:export async function generateZReportServer(terminalId: string): Promise<ZReportResponse> {
```

PHPUnit-only references were also enumerated: the new reflection contract plus `FiscalStatusFilterTest`, `GenerateZReportWithCountsTest`, and two Treasury regression comments. No second executable production caller exists.

### Red/green evidence

- Red: the new annotation/no-shipped-client contract failed because the reflection docblock did not contain `@deprecated` (1 failed, 2 assertions).
- Green on fresh PostgreSQL database `autoerp_sv_stage1_m1_green2_test`: `ZReportServerAuthoringChokepointTest.php`, `ServerReportAuthoringUnreachabilityTest.php`, and `FiscalStatusFilterTest.php` — 10 cases, 8790 assertions, exit 0.
- The first green attempt caught a test-only regex defect: TypeScript omits semicolons in that interface. The assertion was corrected to match the actual source style and rerun green; no production change was made for that failure.
- Revert-replay after commit `2d7503018`: reversing only the five implementation/artifact files while retaining the new contract test reproduced the missing-`@deprecated` failure (exit 1, 2 assertions); replaying the exact non-empty patch restored a clean tree. The first replay command used root-relative pathspecs from `apps/api` and therefore produced no patch; that invalid attempt was discarded before the validated root-level replay.

### Artefact correction and scope fence

The config block, listener `SHIPS DISABLED` docblock, and G-1 deploy note now state the real gate: whole-drawer semantics are settled, but opening float and drawer operations remain unbooked in Treasury (SV-3/SV-4), so the flag stays off. The ticket preserves the historical schema/business-meaning distinction and the fourth-reader warning. The stale hard-coded line reference in `SalesReportService` and a second internal helper name were removed without altering behavior.

`TREASURY_SHIFT_VARIANCE_GL_ENABLED` remains false by default. `CashDrawerService::calculateExpectedCash()` is byte-untouched. No event, posting shape, or Stage-2+ behavior changed.

### M1 review fix round 1

The first review found that the retired premise still appeared in the listener's double-count explanation, the sibling legacy-close and kill-switch tickets, and two regression-test docblocks; it also required the missing-join/fourth-reader history in the code annotation because the helper was retained. The fix round:

- rewrites the listener to reason from the live device whole-drawer receipt term and explicitly labels the schema-v2 helper retired;
- collapses the sibling legacy-close ticket's open takings/whole-drawer fork to the settled whole-drawer ruling and the real SV-3/SV-4 prerequisite;
- updates the kill-switch-window ticket and test docblocks so none present the takings-only helper as a live drawer basis;
- adds the missing-join/fourth-reader explanation to the annotation and pins both phrases in the retirement contract test;
- names the deprecated compatibility symbol in the Sales report's row-fan-out cross-reference; and
- skips the shipped-client portion of the contract honestly when an API-only checkout lacks the sibling web/device apps.

The multi-line stale-premise scan over the required artifacts, all `2026-08-08-g3-*` tickets, and the two test mirrors returned no match for the refuted premise or open owner-ruling language.

Fresh PostgreSQL verification:

- chokepoint, unreachability, pending-seal, and Treasury trigger paths: 15 cases, 8815 assertions, exit 0;
- `GenerateZReportWithCountsTest.php`: 13 cases, 84 assertions, exit 0.

Pint passed for every touched PHP file. The fix round is documentation/test-contract only; runtime money, authorization, event, and GL behavior remain unchanged.

M1 round 2 accepted the milestone. M5 carries one close-before-merge report cleanup from that register: the G-2 block in the deploy-notes ticket is a verbatim historical reviewer quote, so the report must not claim every open-ruling phrase vanished; the prose following that quote must explicitly state that whole-drawer semantics now supersede the quoted question. M5 will also recheck the P3 hardening notes (split the monorepo-dependent client inventory from the always-on annotation test, tighten the device `@deprecated` regex, cross-reference the remaining deploy gates, and avoid calling the v2 branch literally unreachable).

## M2 — SV-11 whole-drawer count-screen copy

### Red/green evidence

The initial rendered-output contract was added first. Its red run had 9 component cases total, with the first three SV-11 cases failing because `cash-count-instruction`, `cash-count-reveal-summary`, and the locale keys did not exist; the six pre-existing cases passed. Two additional rendered cases were added during the same implementation pass. The full five-case surface and two preview-field assertions were then proved non-vacuous together by the 7-failure revert-replay below.

```text
Test Files  4 passed (4)
Tests       66 passed (66)
```

The initial four explicit files were `endOfDayPreview.test.ts`, `CashReconciliationSection.test.tsx`, `CashCountTable.test.tsx`, and `EndOfDayPreviewModal.test.tsx`. M2 review expanded the declared formula regression set with `cashTenderedFormula.test.ts` and `refundReportingEndToEnd.test.ts`; the current six-file run is 75/75. `pnpm typecheck` exits zero.

### Presentation contract

- The instruction above the count remains visible before and after blind commit, interpolating the already-formatted `opening_cash` string.
- The float disclosure and six-line summary render only after commit in blind mode.
- In non-blind mode, the float disclosure renders immediately beside the already-visible expected figure, even before a count is entered.
- English and French rendered-output assertions pin the exact instruction, float disclosure, all six reveal lines, and every line-6 variant (`Over`, `Short`, `No difference`, `Excédent`, `Manquant`, `Aucun écart`); the French test includes the exact `y compris le fonds de caisse` phrase. The locale contract separately pins `Écart`.
- A balanced tender renders `No difference` / `Aucun écart` instead of a bare zero in both the tender table and reveal.
- Line 2 contains only cash sales net of change. There is no rounding label, placeholder, reserved slot, empty container, or hidden sibling. Its structural extension remains deferred to SV-12.

React does not recompute money. The existing preview aggregation now exposes the two already-computed terms as formatted decimal strings: `cash_sales_net` and `drawer_movements_net`. The established `expected_cash` expression and its rounding boundaries remain byte-for-byte intact; a fractional-input regression pins the intentionally different display-term and authoritative-expected rounding results. No UI `parseFloat` or `Number` conversion was introduced, and the Counted display uses the existing decimal formatter.

### Device Arabic record

The device still ships only `en` and `fr`. `docs/superpowers/tickets/2026-08-11-pos-device-arabic-locale.md` records the exact baseline locale tree, `i18n.ts` registration, four namespaces, RTL/touch/numeric implications, and informational owner gate `sv11-arabic-device-locale`. Per the dispatch, this does not stop M2.

### React regression scan

The first broad React Doctor invocation compared the whole long-lived wave branch with `main` and surfaced the repository's existing backlog. The correctly pinned `--scope changed --base HEAD` scan covered the uncommitted M2 files. It initially identified the enlarged reconciliation component; extracting `CashDrawerRevealSummary` removed that finding. The final changed-file scan reports no issues and exits zero.

### Revert-replay

After implementation commit `54e1341b6`, the exact production/locales patch was reversed while the new tests stayed in place. The focused component and preview run failed 7 of 33 cases: the whole-drawer instruction, disclosure/reveal, locale keys, named zero, and exposed preview terms all disappeared as intended. Reapplying that same non-empty patch restored 33/33 green and a clean tree. An initial command used invalid `pnpm --dir` argument ordering and did not run tests; it was discarded before the valid red run above.

### M2 review fix round 1

Round 1 found that exposing the display decomposition had also regrouped the authoritative `expected_cash` expression. Because decimal helpers round at each supplied scale, that presentation-only refactor could move a cent for fractional source strings. The original expression is restored exactly, while `cash_sales_net` remains a separate display field; a regression fixture pins `cash_sales_net = 10.00` and the legacy expected result `110.01` for the reviewer's fractional example.

The same fix round adds rendered English and French over/short cases, formats Counted at currency scale, replaces the undefined `bg-surface-subtle` class with `bg-surface-raised`, and expands the formula regression run to the two previously omitted suites. The reviewer-noted refund/account-payment label breadth is recorded but not changed because the six labels are exact dossier copy.

Fix-round revert-replay reversed the three production files while retaining all fix tests. Three of 38 cases failed: the fractional expected value returned to `110.00`, and English/French Counted returned to raw `100`. The four over/short parameter cases remained green because their locale/default-value coverage already exercises production branches introduced in the main M2 commit. Replaying the production patch restored 38/38. A root-level `pnpm vitest` attempt could not resolve the workspace binary and was discarded; the valid run executed from `apps/pos`.

M2 round 2 accepted the milestone. M5 carries the accepted P3s: pin all seven summary values directly from both locale JSON files; record that separately rounded display line 2 can differ by one minor unit from the byte-preserved authoritative expected value for structurally possible sub-scale input (no live writer found); and retain the owner-facing notes about exact dossier labels being narrower than refund/account-payment contents and the instruction's muted presentation.

## M3 — SV-9 blind counting on everywhere

### R-6 prerequisite guard outcome

The conditional owner gate does not fire. Each required touch point was walked before the first M3 code commit:

| Touch point | G-3 / Stage-5 prerequisite? | Reason |
|---|---|---|
| Backend model attributes and `getDefaults()` | No | Supplies creation/no-row count-screen policy only; it neither reads nor writes Treasury state. |
| `defaultsForVertical()` and vertical tests | No | Removes the retired vertical split in the same settings default; no posting or backfill dependency. |
| Persisted-false settings migration | No | Updates only `company_fraud_settings.require_blind_cash_count`; it does not touch shifts, receipts, fiscal rows, repositories, accounts, or the GL flag. |
| Historical schema column default | No | Governs newly inserted settings rows at the database boundary only. |
| Web initial state and row-less fallback | No | Prevents the admin form from round-tripping an absent value back to false; it is presentation/settings transport. |
| Device SQLite cache default | No | Governs pre-first-sync concealment behavior only; server sync still overwrites the cached setting. |

G-3 backfills Treasury opening float and drawer-operation accounting during a disabled GL window. None of these count-screen concealment settings consumes that accounting data or enables `TREASURY_SHIFT_VARIANCE_GL_ENABLED`. The dossier's independent Stage-1 placement therefore permits M3 to proceed.

### Red-first evidence

- The explicitly declared red-by-design retail test was renamed from “disabled” to “enabled” and failed because the persisted setting remained false. The resolver no-row and admin/POS controller default assertions were likewise changed to true before production changes.
- The real-SQLite v22 test failed with `dflt_value` `0` instead of `1`.
- After the web store mocks were corrected to exercise the page, the row-less round-trip test reached submission and failed because `require_blind_cash_count` was `undefined`, not true. The earlier `getState is not a function` failure was scaffolding-only and is not counted as behavioral evidence.
- The new migration contract did not exist at red time. Its green contract seeds one false and one true row, proves `changed=1/skipped=1`, reruns the migration, and proves `changed=0/skipped=2`; a separate case drops the table and proves the schema guard completes with `changed=0/skipped=0 schema=missing`.

### Implementation and deploy shape

- `CompanyFraudSettings` model attributes and `getDefaults()` are true; `defaultsForVertical()` now returns that shared policy for automotive and retail.
- The historical tenant column default and one-off seed documentation now match the all-vertical ruling.
- The new settings-only migration updates persisted false rows, changes the database default to true, and emits the distinct warning token `SV-9 BLIND COUNT BACKFILL COMPLETE:` with tenant, changed, and skipped counts. It has table/column guards, a forward-only no-op down, and no catch: its single guarded update remains inside migration transaction handling and genuine errors fail loudly.
- The web initial state, row normalization, and control fallback all use true, so an omitted row-less value cannot be submitted as false/undefined. The save-path test proves the mutation payload contains true.
- The device cache's shipped v22 schema retains `DEFAULT 0`. Its only production upsert binds the setting explicitly, and the end-of-day section is withheld while the cache row is absent, so the active pre-sync defence is the null-settings gate rather than an inert column default. Repository sync supplies the ruled server value.
- Web English and French strings remain intact, and the new Arabic compliance bundle supplies and registers the blind-count label. The Arabic path is covered through the real i18next registration and rendered page output.

Deployment instructions and the warning-token grep contract are recorded in `docs/superpowers/tickets/2026-08-12-sv9-blind-count-default-deploy.md`.

### Green evidence

- Focused PostgreSQL acceptance run: 18 cases / 67 assertions, exit 0.
- Expanded PostgreSQL settings/controller regression: 31 cases / 199 assertions, exit 0. An earlier pass caught one stale POS-controller false assertion; it was rewritten to the ruled true default before this clean run.
- Web: `FraudSettingsPage.test.tsx` + `CashDrawerControlsSection.test.tsx`, 10/10; typecheck green.
- Device: migration v22, cache repository, fraud-settings API, reconciliation, and preview modal, 58/58; after correcting a test-only TypeScript row shape, POS typecheck and v22's 8 cases are green.
- Pint passes on all touched PHP files. Changed-file React Doctor scans the two web implementation files with no issues (score 93); focused ESLint has no errors, only pre-existing warnings in the edited legacy files.

### Revert-replay

After commit `b73a353f5`, only the nine M3 production/default/migration/locale files were reversed while the new and rewritten tests remained. PostgreSQL failed four of ten selected cases (retail default false, resolver no-row false, and both missing-migration cases); the web suite failed to resolve the removed Arabic compliance bundle; and SQLite reported default `0` instead of the then-implemented `1`. Reapplying the exact patch restored the tree, and the web page test plus typecheck reran green. The later resumed review established that changing the already-shipped v22 migration was not a valid field-device mechanism; fix round 1 restored that historical byte and replaced the assertion with an immutability guard.

M3 review round 1 was a bridge tool error: the Claude invocation exited nonzero with an empty stderr/register. Per the self-review harness it is recorded fail-closed as CHANGES-REQUIRED and consumes one fix round. No implementation finding was emitted, so no speculative code change was made before round 2.

M3 review round 2 failed identically at the bridge layer with an empty error register. It is likewise recorded fail-closed and consumes the second fix round; the locally installed Claude CLI still responds at version 2.1.228.

M3 review round 3 also failed before producing reviewer content. It is recorded fail-closed and consumes the third fix round; no application changes are inferred from an empty tool-error register.

M3 review round 4 repeated the same empty bridge failure. It is recorded fail-closed and consumes the fourth fix round.

M3 review round 5 again failed at invocation with an empty register. It consumes the fifth and final allowed fix round; the harness permits one terminal round-6 attempt, as in M0.

The terminal M3 round-6 review also failed in the Claude invocation with empty stderr and no reviewer content. The implementation remains locally verified and the worktree is clean, but the fail-closed harness forbids treating tool silence as acceptance. M3 and the wave are therefore `blocked_review` under STOP condition A. M4 and M5 have not started.

The user explicitly authorized continuation after that STOP. The six original tool-error registers remain unchanged; a fresh M3 review allowance is opened with registers named `M3-resume1-round<n>.md` so the failed invocation history is not overwritten. No implementation claim or prior local verification is promoted to acceptance without a new parseable reviewer verdict.

### M3 resumed review fix round 1

`M3-resume1-round1.md` returned a parseable `CHANGES-REQUIRED`. Its blocking P2 was a tautological locale-file assertion that never exercised Arabic i18n registration or rendered output. The replacement changes the real i18n language to `ar`, renders `FraudSettingsPage`, waits for the row-less API response to settle, and asserts the literal Arabic blind-count label in the DOM. Temporarily replacing the Arabic compliance resource with the English fallback made exactly this test fail (1 failed / 1 passed); restoring the production registration returned 2/2 green. The row-less round-trip test now also waits for the distinctive API value before saving and for the invalidation refetch afterward, removing its React `act(...)` warnings and proving it does not submit the initial-state value early.

The review also found that changing the long-shipped device v22 migration would split fresh and upgraded schemas while the column default has no current consumer. A red-first immutability assertion observed `1` instead of the shipped `0`; v22 was restored to `DEFAULT 0`, and all eight migration-v22 cases passed. The deploy ticket now records the actual null-row/upsert reasoning and explicitly discloses that the data migration re-enables every false row, including a deliberate administrator choice, because no provenance discriminator exists and the ruling is "ON everywhere". The now-dead company-vertical query and its custom service-provider factory were removed; company creation uses the shared defaults directly.

Verification for commit `41da762c1`:

- Real PostgreSQL on `127.0.0.1:5432`, database `autoerp_sv_stage1_m3r1_test`: migration + vertical defaults + resolver, 10 cases / 32 assertions, exit 0. An earlier command that inherited PHPUnit's SQLite setting was rejected as evidence and its disposable 6.1 MB database file was moved to Trash before the explicit PostgreSQL rerun.
- Web focused run: `FraudSettingsPage.test.tsx` + `CashDrawerControlsSection.test.tsx`, 10/10; full `pnpm lint` exits 0 with 6,530 repository-baselined warnings and no errors, all three ratchets report zero new findings, ESLint rule tests pass, and `pnpm typecheck` exits 0.
- Device focused run: migration v22, cache repository, fraud-settings API, reconciliation, and preview modal, 58/58; `pnpm typecheck` exits 0.
- PHPStan on the touched `app/` files reports no errors; Pint test mode passes.
- The direct deptrac ratchet reports 116 violations against the stale checked-in count of 99. The identical ratchet executed from the pinned content base also reports the same 116 violations (category counts identical), proving this lane added zero; no baseline was rewritten or unrelated architecture debt absorbed.
- React Doctor's deprecated `--diff` compared the long-lived branch to `main` and was discarded as a regression signal. The tool-prescribed `--scope changed --base HEAD` scan covered the actual uncommitted React fix: one file, score 100, no issues.

M3 resumed round 2 accepted the milestone after independently rerunning PostgreSQL (21 cases / 86 assertions), the Arabic DOM test, the v22 immutability guard, PHPStan, Pint, ESLint, deptrac attribution, and a real-PG probe of the column-default change. Four non-blocking P3 notes carry to M5: permanently assert the migrated PostgreSQL column default; record the now-orphaned vertical-query contract without deleting it in this lane; preserve the pinned-base deptrac comparison and avoid claiming the stale ratchet is globally green; and scope web Vitest claims by path because unrelated Arabic coverage tests are already red.

## M4 — SV-10 blind-mode leak audit

The render-path audit is recorded at `docs/handoff/reviews/sv-stage1/M4-sv10-leak-audit.md`. It starts at the production `Header` caller, verifies that every prop required for the cash-count flow is supplied, follows the guarded legacy card, expected/variance table columns, severity-derived reason and manager-PIN prompts, six-line reveal, success-only thermal receipt, and then enumerates sibling pages and orphan components. No reachable cashier pre-commit path directly renders an expected-cash or variance magnitude under blind mode, so M4 required no production-code fix and left the existing SECURITY defence byte-unchanged.

A rendered regression enters a critical `-60.00` variance with blind counting and hard-threshold manager authorization enabled. Before Commit Counts it proves that Expected/Variance headings, the magnitude, reveal summary, reason prompt, and PIN prompt are absent; after commit it proves the exact magnitude and both prompts appear. Removing the two `committed` prompt guards as a temporary mutation made that test fail on the pre-commit reason section (1 failed / 15 skipped); restoring the guards returned the complete focused surface to 49/49 green.

The sibling manager-only `ShiftClosurePage` is a static/demo route that hard-codes expected cash and renders a locally computed variance before its inert Close Shift button. It is not connected to the production close flow and has no fraud-policy input, so choosing whether to remove it, define it as a manager report, or replace it with the real close workflow is larger than SV-10's narrow-fix permission. `docs/superpowers/tickets/2026-08-17-shift-closure-page-blind-count-parity.md` records that ownership decision and route-level acceptance. The unused `ZReportModal`, manager-only Z list, retired `CloseShiftModal`, and payment/sales summaries were also enumerated in the audit.

Verification for commit `39a72abc0`:

- POS rendered surface: `CashReconciliationSection.test.tsx`, `CashCountTable.test.tsx`, and `EndOfDayPreviewModal.test.tsx`, 49/49, exit 0.
- POS `pnpm typecheck`, exit 0.
- Focused ESLint on the changed React test, exit 0.
- React Doctor changed-file scan: one file, score 100/100, no issues.
- `git diff --check`, exit 0; `CashReconciliationSection.tsx` has no committed diff.

### M4 review fix round 1

Round 1 found a real parent-level bypass: `EndOfDayPreviewModal` repeated every payment method's amount below the guarded tender table. A physical non-cash method's payment total is its exact expected count, while the CASH total plus the now-visible opening float exactly reconstructs expected cash on a no-movement shift. It also found that the electronic tender's non-editable Actual cell repeated its expected value before commit. The audit's original clean classification was corrected rather than defended.

Two red-first rendered regressions cover the complete paths. The modal test supplies CASH, electronic CARD, and physical CHECK rows and failed because `30.00`, `15.00`, and `430.00` rendered before Commit Counts. The table test failed because electronic `50.0000` rendered in Actual. The narrow fix sends the section's Commit Counts transition to its parent; while the active flow is blind and uncommitted, every payment-method amount cell renders a dash. The electronic Actual cell follows the same gate. On commit, all values reveal immediately. Non-blind and legacy preview-only rendering are unchanged, and no money, readiness, authorization, or persistence logic changed.

Green evidence after the fix:

- focused M4 surface: 51/51 rendered tests, exit 0;
- POS typecheck, exit 0;
- focused ESLint: zero errors and one pre-existing `set-state-in-effect` warning in `CashReconciliationSection`;
- React Doctor scanned the five changed React files and reported one warning at the modal's pre-existing close/reset effect. The reset-on-close pattern predates M4; adding the new disclosure state to the same lifecycle does not introduce the pattern, and replacing the established modal reset architecture is outside the narrow security fix.

The required production-only revert/replay removed the parent callback/gate and electronic-cell gate while retaining both regressions. Both selected tests failed on their pre-commit absence assertions (2 failed / 33 skipped). Reapplying the exact production changes restored 2/2 selected green and a clean worktree.

### M4 review fix round 2

Round 2 found that the local-SQLite preview could win a race against the async fraud-policy lookup on first open, temporarily rendering the legacy expected-cash card while `fraudSettings` was still null. It also found that gross sales plus the always-visible opening float exactly reconstructs expected cash on an ordinary cash-only/no-movement shift, that X Report was missing from the sibling enumeration, and that a stale non-blind policy could mount the section before a false-to-true refresh completed.

The production Header now clears settings/managers and marks policy unresolved before every open, marks the online-or-cache lookup resolved in its completion path, and passes that signal to the modal. The modal renders only a loading state while unresolved and a fail-closed policy-unavailable error when resolution produces no settings; the preview block cannot mount in either state. This closes both the first-open race and the stale false-to-true initializer path. During a resolved blind pre-commit phase, the same gate now withholds gross/net/tax cards and VAT breakdown money as well as payment totals. Transaction counts and VAT rates remain visible; all monetary preview values reveal at Commit Counts.

Rendered evidence is 61/61 across Header, reconciliation section, tender table, and preview modal. Two Header integration tests prove first-open and stale false-to-true policy state is `unresolved:none` until refresh completes. The modal test proves unresolved and unavailable policy states never render preview values, and the expanded financial test proves summary, VAT, and tender amounts are absent pre-commit and present post-commit. POS typecheck exits zero; focused ESLint reports zero errors and only the previously recorded unrelated reconciliation warning; React Doctor reports no issue in the four changed React files (score 91).

The round-2 production-only revert/replay retained these tests while removing the Header policy lifecycle and modal policy/financial gates. All four selected security cases failed (first-load, stale-policy, unavailable-policy, and financial-summary assertions). Reapplying the exact production patch restored 4/4 green and a clean tree. The audit now enumerates the manager-only X Report and explains its access class instead of silently omitting it.

### M4 review fix round 3

Round 3 confirmed the modal-level defences but found a cashier-accessible cross-navigation derivation: Today's Sales exposes gross sales, which combines with the count instruction's opening float to reconstruct expected cash on a cash-only/no-movement shift. The audit now states that residual risk explicitly and `docs/superpowers/tickets/2026-08-17-today-sales-blind-count-derivation.md` records the required product/authorization decision. The closing result is scoped to the active End of Day modal rather than claiming the whole device is clean. The audit also adds explicit clean-for-literal-SV-10 verdicts for the tolerance drill-down and net cash-rounding rows.

The round-2 fail-closed state has an availability cost: if both the online policy fetch and local cache are unavailable, the device cannot close the shift or generate a Z report until it reconnects. That deliberate disclosure-safe behavior is covered through the real Header trigger and is recorded for fiscal operations ownership in `docs/superpowers/tickets/2026-08-17-blind-count-policy-unavailable-close.md`; M4 does not invent an unaudited fallback.

Three narrow defects were fixed red-first. Policy resolution is now tied to the exact terminal object, so a terminal-record refresh makes the modal unresolved synchronously and prevents stale false-to-true policy rendering. The unavailable-policy instruction has pinned English and French resources. A real preview-build error takes precedence over the policy-unavailable message so support receives the actual failure. The complete M4 surface is 65/65 green; POS typecheck exits zero; focused ESLint has zero errors and the one previously recorded reconciliation warning; React Doctor scans four changed files with no issues (score 91).

The production/locales-only revert/replay retained the three new regressions and produced exactly three failures: terminal refresh remained `true:true` instead of unresolved, both locale resources lost the key, and `preview exploded` was masked by the policy message. Reapplying the exact patch restored 3/3 selected green and a clean worktree.

### M4 review fix round 4

Round 4 found a second-state-atom bypass across a full policy refresh. After Commit Counts, the modal's parent reveal flag survived while the reconciliation child unmounted during policy loading. When the child remounted pre-commit, physical expected totals and summary money could render from the stale parent flag. A red rendered regression commits CASH and CHECK, rerenders through unresolved and a fresh resolved policy snapshot, and observed CHECK `430.00` still rendered; the paired Header test observed the new terminal as `false:true`, retaining old settings.

The fix associates Header's settings and manager snapshots with the exact terminal object that produced them. A changed terminal reads as unresolved with no settings immediately; failed online and cache refreshes finish as unavailable rather than reviving the old policy. Inside the modal, payload, readiness, and commit state are one object bound to the policy snapshot that produced them. State from an older snapshot is invalid during render, so there is no post-render reveal window. The selected regressions are 2/2 green. A transient refresh still discards entered counts, reason, and verification; the existing availability ticket now records the operator-workflow decision and requires any future draft preservation to be policy-versioned and disclosure-safe.
