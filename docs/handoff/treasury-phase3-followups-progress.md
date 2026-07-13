# Treasury Phase 3 follow-ups progress

Base: `f2dc43f55` (latest `origin/dev` after rebase)
Rebase bookkeeping: the branch was rebased from its initial `bb44387f195dd8ac9383803e30e5d43492f1c9ec` base onto the latest documentation-only `origin/dev` commits through `f2dc43f55`; this did not change the follow-up implementation scope.
Branch: `chore/treasury-phase3-followups`
Worktree: `/Users/houssamr/Projects/syneriva/apps/erp.phase3-followups`

| Item | Status | Commit | Verification | Notes |
| --- | --- | --- | --- | --- |
| F-1 | Complete | `2f76584f6` | `CACHE_STORE=array php artisan typescript:transform` (exit 0; 434 types; no generated drift) | FE-local interfaces remain authoritative for `RepositoryTransferResult` and notification response shapes. |
| F-2 | Complete | `8b3fad0c6` + `04ebd110b` | Focused Vitest: 2 files, 16 tests passed; focused ESLint: 0 errors; React Doctor: 98/100, no issues found | Eligible active, non-virtual repositories expose the permission-gated detail action and open the existing modal with a changeable, preselected source. |
| F-3 | Complete | `5e959e4e8` + `cd2b23948` | Focused PHPUnit: 20 tests, 62 assertions passed; focused PHPStan: no errors; Pint: clean | GL-bearing updates serialize on a tenant/company-scoped repository row lock, re-evaluate the change, then reject null-JE transfer legs with their count in the canonical 422 envelope. |
| F-4 | Complete | `b99b4c9f1` + `ee2ce6011` | Focused PHPStan: no errors; PostgreSQL-focused PHPUnit: 4 tests, 11 assertions passed; Pint: clean | Retained both fluent `where` predicates and added a typed invokable callback factory after the full gate exposed Larastan's `Builder<Model>` inference. No inline override, suppression, baseline, raw SQL, runtime behavior, or test change. |
| F-5 | Complete | `dde3da3f2` | Focused PHPUnit: 6 tests, 37 assertions passed | Added one concise comment that flow SUM exactness rests on `repository_movements.amount` being `decimal(15,3)`; SQL and behavior unchanged. |
| F-6 | Complete | `8c6294626` | Focused Vitest: 1 file, 6 tests passed; design audit: 753 acknowledged, 0 new, 0 stale | Replaced only the summary-card `bg-white` literal with the existing `colors.white` token imported from `@/lib/designTokens`. |

## Final verification

- Backend Treasury feature suite: 594 tests, 2,382 assertions, 25 skips; green. The 39 PHPUnit deprecations are pre-existing.
- PHPStan: the default 512 MB invocation exhausted worker memory without producing code diagnostics; the established `--memory-limit=1G` rerun analyzed all 2,528 files with no errors.
- Pint `--dirty`: pass.
- Frontend typecheck and lint: pass (0 lint errors; existing warning baseline retained).
- Changed Treasury UI verification after the final rebase: 3 files, 22 tests passed (16 detail/modal tests plus 6 repository-list tests).
- Full Treasury Vitest: 34 files and 243 tests passed; 10 tests in 3 pre-existing files fail because `AddRepositoryModal` now requires `CompanyConfigProvider`. A clean detached `origin/dev` run reproduces the exact same 10 failures in the same 3 files (34 files and 240 tests passed), proving no branch regression. Per the no-scope-creep rule, those unrelated test harnesses were not changed.
- Design-system audit: 753 acknowledged, 0 new, 0 stale. TanStack-key audit: 0 findings.
- React Doctor, changed scope against `origin/dev`: 98/100, no issues.
- `TreasuryMovementService.php`: byte-untouched relative to `origin/dev`.

Autonomous gate: pending.
