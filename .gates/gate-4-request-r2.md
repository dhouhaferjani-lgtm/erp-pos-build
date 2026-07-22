# Gate 4 review request — remediation round 2 (B1–B4)

Worktree: `/Users/houssamr/Projects/syneriva/apps/erp.multiloc`
Branch: `feat/multi-location`
Tip to review: `62035d815`

## Review scope and personas

Re-run the Gate 4 lanes from `.gates/gate-4-verdict.md` and its linked lane verdicts:

- `frontend-conventions-reviewer` from `.claude/agents/frontend-conventions-reviewer.md` (Opus): verify the company-currency and direction-separated Due This Week widget, all en/fr/ar keys, design tokens, and targeted frontend behavior.
- `fiscal-pos-reviewer` from the POS lane: verify fail-closed analytics/Z-report scope, unrestricted-only `orWhereNull` handling for nullable F&B orders, and real restricted/zero-allowed PostgreSQL behavior.
- `treasury-reviewer` from `.claude/agents/treasury-reviewer.md` (Opus): verify the widget’s maturing-instrument contract and decimal-string direction totals; no Task 0 writer/backfill work is being changed.

Review the Wave 4 plan `docs/superpowers/plans/2026-07-16-multiloc-4-analytics-dashboards.md`, the handoff `docs/handoff/CODEX-multiloc-wave4-2026-07-22.md`, the prior Gate 4 request, and this remediation diff:

```bash
git diff origin/dev...HEAD -- \
  .github/workflows/ci.yml \
  apps/api/app/Modules/POS/Application/Services/PosAnalyticsService.php \
  apps/api/app/Modules/POS/Presentation/Controllers/AnalyticsController.php \
  apps/api/app/Modules/POS/Presentation/Controllers/ReportController.php \
  apps/api/tests/Feature/POS/AnalyticsTest.php \
  apps/api/tests/Feature/POS/ZReportListTest.php \
  apps/web/src/features/owner-dashboard/components/DueThisWeekWidget.tsx \
  apps/web/src/features/owner-dashboard/components/__tests__/DueThisWeekWidget.test.tsx \
  apps/web/src/locales/en/reports.json \
  apps/web/src/locales/fr/reports.json \
  apps/web/src/locales/ar/reports.json
```

## B1–B4 remediation evidence

- **B1:** `PosAnalyticsService` now applies a location predicate through every analytics query unconditionally. Empty effective IDs therefore produce an empty result. The nullable `pos_orders.location_id` F&B branches add `orWhereNull` only when `LocationScopeBoundary::isUnrestricted()` is true. `ReportController::listZReports` applies the same unconditional qualified terminal-location predicate, with unrestricted-only NULL inclusion. Both controllers use constructor-injected `LocationScopeBoundary`.
- **B2:** Added restricted-membership/no-param, out-of-scope, terminal+location narrowing, location-only narrowing, and zero-allowed empty-result tests. The zero-allowed analytics and Z-report tests were run before B1 and failed with company-wide counts, then passed after B1.
- **B3:** `DueThisWeekWidget` reads the active company currency from `useCompanyStore`, computes inbound and outbound totals separately from the DTO’s `total_in`/`total_out`, and renders both labeled amounts. Its test supplies `EUR` with nonzero inbound and outbound values and asserts EUR-formatted output.
- **B4:** `AnalyticsTest` is now explicitly included in the `backend-test-pgsql` `--filter` allowlist. No new backend Feature test class was created; `ZReportListTest` was already allowlisted.

## Final verification at `62035d815`

- SQLite affected paths (`AnalyticsTest`, `ZReportListTest`, Task 0 reconciliation/report paths): **51 tests, 276 assertions, passing**.
- PostgreSQL same affected paths: **51 tests, 276 assertions, passing**.
- Frontend named Wave 4/Task 0 paths: **14 files, 42 tests, passing**.
- `pnpm typecheck`: passing across workspace packages.
- `pnpm lint`: passing with existing repository warnings and zero errors; TanStack key audit reports 0 new violations and design-system audit reports 745 acknowledged, 0 new, 0 stale.
- Scoped PHPStan: no errors. Scoped Pint `--test`: passing.
- `CACHE_STORE=array php artisan typescript:transform`: completed; generated types diff: zero.

## Verdict demand

Return **APPROVE** or **REJECT** for Gate 4 round 2. Every finding must include severity and exact `file:line` evidence. A REJECT must identify the blocking defect and concrete fix; an APPROVE must still list non-blocking minors. Do not self-approve, create `multiloc-gate-4`, merge, or push.
