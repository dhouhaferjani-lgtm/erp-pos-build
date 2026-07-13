# Task F2 implementation report

## Result

- Added a `treasury.transfer`-gated `Transfer cash` action to `RepositoryDetailPage` beside the balance-adjustment action.
- The action opens the existing `TransferCashModal` and passes the current repository as `initialFromRepositoryId`.
- Added the optional modal prop and used it as the React Hook Form source default; the source select remains enabled and changeable.
- Kept the existing open-only child component boundary, preserving one UUID for the lifetime of each modal open.
- Final-review fix: the detail action now also requires an active, non-virtual repository, matching the source eligibility enforced by `useActivePaymentRepositories` and `TransferCashModal`.

## TDD evidence

- RED: focused Vitest run failed 3 new expectations for the absent action/opening behavior and empty initial source value; 10 existing tests passed.
- GREEN: `pnpm vitest run src/features/treasury/RepositoryDetailPage.test.tsx src/features/treasury/components/TransferCashModal.test.tsx` passed 2 files and 13 tests.

## Final-review eligibility fix

- RED: the focused detail-page run had exactly two failures because inactive and virtual repositories still rendered the transfer action; the active physical control and all prior behaviors passed.
- GREEN: the focused detail/modal run passed 2 files and 16 tests, retaining opening, preselection, changeability, and stable-UUID coverage.
- The implementation only extends the permission gate with `repository.is_active && repository.type !== 'virtual'`; no modal, UUID, or translation behavior changed.

## Additional verification

- Focused ESLint on the four changed React/Test files exited 0 with 8 pre-existing warnings and no errors.
- React Doctor changed-scope scan found no issues and scored 98/100.
- `git diff --check` passed.

## Files changed

- `apps/web/src/features/treasury/RepositoryDetailPage.tsx`
- `apps/web/src/features/treasury/RepositoryDetailPage.test.tsx`
- `apps/web/src/features/treasury/components/TransferCashModal.tsx`
- `apps/web/src/features/treasury/components/TransferCashModal.test.tsx`
- `docs/handoff/treasury-phase3-followups-progress.md`

## Concerns

- None. No translation keys were added; existing English, French, and Arabic transfer action strings are reused.
