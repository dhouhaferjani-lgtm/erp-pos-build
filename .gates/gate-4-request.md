# Gate 4 review request — Wave 4 analytics, dashboards, and Task 0 DTO follow-ups

Worktree: `/Users/houssamr/Projects/syneriva/apps/erp.multiloc`
Branch: `feat/multi-location`
Tip to review: `0945d39e0`

## Reviewer personas

### frontend-conventions-reviewer (copied from `.claude/agents/frontend-conventions-reviewer.md`)

```yaml
name: frontend-conventions-reviewer
description: Adversarial reviewer for apps/web frontend changes in AutoERP. Verifies design-system conventions (canonical components, tokens, RHF, i18n, tenant-scoped keys) against code with file:line citations. Gates merges — never auto-merges. Use at every FE milestone per the owner's standing review rule.
tools: Read, Grep, Glob, Bash
model: opus
```

Adversarially review React/TypeScript changes for canonical components, design tokens, i18n in en/fr/ar, strict typing, money/quantity rendering, and `locationScopedKey` on every scope-dependent query. Cite every finding with exact `file:line` evidence. Return `APPROVE` or `REJECT` (or the persona's documented equivalent), with findings ranked by severity. Do not merge, tag, or push.

### treasury-reviewer (copied from `.claude/agents/treasury-reviewer.md`)

```yaml
name: treasury-reviewer
description: Adversarial reviewer for treasury / payments / expense / GL changes in AutoERP. Verifies against code (cites file:line), never hallucinates, gates merges — never auto-merges.
tools: Read, Grep, Glob, Bash
model: opus
```

Task 0 consumes the treasury maturing-instruments contract and Wave 4 consumes the cash-position and maturing-instruments endpoints in the consolidated widgets. Verify exact DTO contracts, location scope enforcement, decimal-string handling, and attribution grain. Cite every finding with exact `file:line` evidence and return `APPROVE` or `REJECT` with severity-ranked findings. Do not merge, tag, or push.

## Required branch diff scope

Review exactly the Wave 4 and Task 0 paths below (the branch also contains the already-gated Waves 1–3; do not expand this review into unrelated paths):

```bash
git diff origin/dev...HEAD -- \
  .github/workflows/ci.yml \
  apps/api/app/Modules/Accounting/Application/DTOs/Reports/LocationReportBucketData.php \
  apps/api/app/Modules/Accounting/Application/DTOs/Reports/UpcomingPaymentsData.php \
  apps/api/app/Modules/Accounting/Application/Services/Reports/UpcomingPaymentsService.php \
  apps/api/app/Modules/Accounting/Presentation/Controllers/ReportsController.php \
  apps/api/app/Modules/POS/Application/Services/PosAnalyticsService.php \
  apps/api/app/Modules/POS/Presentation/Controllers/AnalyticsController.php \
  apps/api/app/Modules/POS/Presentation/Controllers/ReportController.php \
  apps/api/app/Modules/POS/Presentation/Requests/AnalyticsRequest.php \
  apps/api/app/Modules/POS/Presentation/Resources/ZReportResource.php \
  apps/api/app/Modules/Treasury/Application/DTOs/MaturingInstrumentBucketData.php \
  apps/api/app/Modules/Treasury/Application/DTOs/MaturingInstrumentBucketsData.php \
  apps/api/app/Modules/Treasury/Application/DTOs/MaturingInstrumentRowData.php \
  apps/api/app/Modules/Treasury/Application/DTOs/MaturingInstrumentsData.php \
  apps/api/app/Modules/Treasury/Application/DTOs/MaturingInstrumentsMetaData.php \
  apps/api/app/Modules/Treasury/Presentation/Controllers/MaturingInstrumentsController.php \
  apps/api/tests/Feature/POS/AnalyticsTest.php \
  apps/api/tests/Feature/POS/ZReportListTest.php \
  apps/api/tests/Feature/Treasury/LocationReconciliationTest.php \
  apps/api/tests/Feature/Treasury/MaturingInstrumentsTest.php \
  apps/api/tests/Feature/Accounting/Reports/UpcomingPaymentsTest.php \
  apps/api/tests/Feature/Accounting/UpcomingPaymentsRecurringTest.php \
  apps/api/tests/Feature/Accounting/UpcomingPaymentsInstrumentsTest.php \
  apps/web/src/features/finance/types.ts \
  apps/web/src/features/owner-dashboard \
  apps/web/src/features/pos/api/analyticsApi.ts \
  apps/web/src/features/pos/api/reportApi.ts \
  apps/web/src/features/pos/hooks/useAnalytics.ts \
  apps/web/src/features/pos/pages/AnalyticsDashboardPage \
  apps/web/src/features/pos/pages/ZReportListPage \
  apps/web/src/features/treasury/InstrumentListPage.tsx \
  apps/web/src/features/treasury/hooks/useMaturingInstruments.ts \
  apps/web/src/features/finance/pages/TreasuryOverviewPage.test.tsx \
  apps/web/src/features/treasury/InstrumentListPage.test.tsx \
  apps/web/src/features/treasury/__tests__/InstrumentListPage.filters.test.tsx \
  apps/web/src/locales/en/reports.json apps/web/src/locales/fr/reports.json apps/web/src/locales/ar/reports.json \
  apps/web/src/locales/en/pos.json apps/web/src/locales/fr/pos.json apps/web/src/locales/ar/pos.json \
  packages/shared/types/generated.d.ts
```

Enforce `docs/superpowers/plans/2026-07-16-multiloc-4-analytics-dashboards.md` in full and the Task 0 requirements in `docs/handoff/CODEX-multiloc-wave4-2026-07-22.md`: location-only POS analytics filters; grouped store comparison; scoped trend/leaderboard/Z-report surfaces; consolidated scoped widgets; DTO-generated `buckets_by_location`; and the GR-IR accrual reconciliation case. Confirm all new translation keys exist in en, fr, and ar, and that the Task 0 generated types have no hand-declared frontend duplicates.

## Verification evidence at the review tip

- SQLite affected backend paths: `./vendor/bin/phpunit -c phpunit.xml ...` — **43 tests, 258 assertions, passing**.
- PostgreSQL Task 0 paths: `DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 DB_DATABASE=autoerp DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret ./vendor/bin/phpunit -c phpunit-pgsql.xml ...` — **24 tests, 176 assertions, passing**.
- PostgreSQL Wave 4 POS paths (Analytics + Z-report): same runner — **19 tests, 82 assertions, passing**.
- Frontend named Wave 4/Task 0 paths — **14 files, 42 tests, passing**.
- `pnpm typecheck` — passing across workspace packages.
- `pnpm lint` — passing with existing repository warnings and zero errors; key and design-system audits report zero new/stale violations.
- Scoped PHPStan — no errors; Pint `--test` — passing.
- `CACHE_STORE=array php artisan typescript:transform` — completed; `git diff --exit-code ../../packages/shared/types/generated.d.ts` — zero diff.
- Playwright/visual archive scenarios have no Wave 4-specific existing spec or authenticated fixture in this worktree; controller review should run the deployment-environment e2e and light/dark visual checks before merge.

## Verdict demand

Return **APPROVE** or **REJECT** for Gate 4. Every finding must be classified by severity and supported by exact `file:line` evidence. A REJECT must identify the blocking defect and concrete fix required; an APPROVE must still list non-blocking minors. Do not self-approve, create `multiloc-gate-4`, merge, or push.
