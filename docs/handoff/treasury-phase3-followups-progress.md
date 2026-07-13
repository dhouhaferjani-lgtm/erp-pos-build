# Treasury Phase 3 follow-ups progress

Base: `a4ecd97182bf4aeade693d6f5801e361183d3361` (latest `origin/dev` after rebase)
Rebase bookkeeping: the branch was rebased from its initial `bb44387f195dd8ac9383803e30e5d43492f1c9ec` base onto latest `origin/dev` at `a4ecd97182bf4aeade693d6f5801e361183d3361`; this does not expand F4's behavior scope.
Branch: `chore/treasury-phase3-followups`
Worktree: `/Users/houssamr/Projects/syneriva/apps/erp.phase3-followups`

| Item | Status | Commit | Verification | Notes |
| --- | --- | --- | --- | --- |
| F-1 | Complete | This commit | `CACHE_STORE=array php artisan typescript:transform` (exit 0; 434 types; no generated drift) | FE-local interfaces remain authoritative for `RepositoryTransferResult` and notification response shapes. |
| F-2 | Complete | This commit | Focused Vitest: 2 files, 13 tests passed; focused ESLint: 0 errors; React Doctor: 98/100, no issues found | Permission-gated detail action opens the existing modal with a changeable, preselected source repository. |
| F-3 | Complete | `b4a02862e` + this review-fix commit | Focused PHPUnit: 20 tests, 62 assertions passed; focused PHPStan: no errors; Pint: clean | GL-bearing updates serialize on a tenant/company-scoped repository row lock, re-evaluate the change, then reject null-JE transfer legs with their count in the canonical 422 envelope. |
| F-4 | Complete | This commit | PostgreSQL-focused PHPUnit: 4 tests, 11 assertions passed | Replaced only the two `whereRaw` equality predicates with behavior-equivalent fluent `where` calls; existing test file stayed unmodified. |
| F-5 | Complete | This commit | Focused PHPUnit: 6 tests, 37 assertions passed | Added one concise comment that flow SUM exactness rests on `repository_movements.amount` being `decimal(15,3)`; SQL and behavior unchanged. |
| F-6 | Complete | This commit | Focused Vitest: 1 file, 6 tests passed; design audit: 753 acknowledged, 0 new, 0 stale | Replaced only the summary-card `bg-white` literal with the existing `colors.white` token imported from `@/lib/designTokens`. |

Final verification: pending.

Autonomous gate: pending.
