# Wave 1 Task 7 report — `viewScopeStore`

- Base: `ac053aa2f116e600860af18f1a88adb29d9652b6`
- Head: `18c10d23c` (`feat(multiloc): viewScopeStore — per-company persisted view scope (§1 FE)`)

## Files

- `apps/web/src/stores/viewScopeStore.ts`
- `apps/web/src/stores/viewScopeStore.test.ts`

## TDD evidence

- RED: `cd apps/web && pnpm vitest run src/stores/viewScopeStore.test.ts` — failed during collection because `./viewScopeStore` did not exist (expected missing-feature failure).
- GREEN: same command — `4 tests passed`.

## Verification

- `cd apps/web && pnpm typecheck` — pass.
- `cd apps/web && pnpm lint` — pass (0 errors; existing repository warnings remain; TanStack key and design-system gates pass).
- `cd apps/web && pnpm exec eslint src/stores/viewScopeStore.ts src/stores/viewScopeStore.test.ts` — pass, 0 warnings.
- `cd apps/web && npx react-doctor@latest --verbose --diff` — exit 1, score 49/100; 149 findings are pre-existing/unrelated to the two Task 7 files (no findings on `viewScopeStore.ts` or its test). The command reported the deprecated `--diff` flag.
- `cd apps/web && npx react-doctor@latest --verbose --scope changed` — exit 1, score 49/100; the same 149 pre-existing findings, with no diagnostics on the Task 7 files.
- `git diff --check` — pass.

## Behavior delivered

- Scope state is `'all' | string[]` and persists under `autoerp-view-scope:<companyId>`.
- Initial company load hydrates that company’s valid persisted scope.
- A real company change resets in-memory scope to `'all'` without touching either company’s key.
- Cross-tab valid payloads are adopted; malformed, invalid, absent, and removal payloads are ignored so the current selection is preserved.
- Storage read/write failures are safely ignored while in-memory state remains usable.

## Deviations and concerns

- No implementation deviation from the pinned Task 7 contract.
- The pre-existing tracked `.superpowers/sdd/task-7-report.md` (an unrelated expense-schema report) was replaced with this Wave 1 report as requested by the task; no plan checkboxes were modified.
