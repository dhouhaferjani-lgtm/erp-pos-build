# Task D2 Report — Inter-repository transfer modal

## Outcome

Implemented the D2 transfer workflow on `RepositoryListPage`: a permission-gated modal selects eligible same-currency repositories, submits canonical decimal-string money with one UUID per modal open, and refreshes every affected repository, transaction, movement, and cash-position cache after success. The repository API now emits `currency`, the frontend repository type carries it, and en/fr/ar translations cover the complete form.

The touched repository list also replaces its prior monetary `parseFloat` aggregation and comparisons with `big.js`, keeping the page inside the precision contract.

## TDD evidence

- Hook RED: focused Vitest failed because `useTransferCash` did not exist. GREEN: 1 test asserts the POST contract and all eight cache invalidations.
- Backend RED: the list payload assertion received `null` for `data.0.currency`. GREEN: the focused list test returned `EUR`; the complete `PaymentRepositoryTest` finished with 14 tests and 39 assertions.
- Modal RED: focused Vitest failed because `TransferCashModal` did not exist. GREEN: 3 tests cover active/non-virtual repository eligibility, same-currency destination filtering, source exclusion, source-currency `MoneyInput`, balance labels, zero and 4-decimal rejection, notes, stable UUID reuse, toast, close, and success callback.
- Page RED: the transfer action was absent. GREEN: 6 page tests include independent `repositories.manage` and `treasury.transfer` gates while preserving both actions through fragment composition.

## Plan deviation

Rev2 required `tenantScopedKey(...)` for six invalidations and raw prefixes for two movement invalidations. The pre-existing CI audit from `d0620c90d` rejects scoped cache filters because scoped suffixes cannot act as general TanStack prefixes. With parent authorization, all eight invalidations use raw leading literal prefixes. The dated deviation is recorded in `docs/handoff/treasury-phase3-progress.md`; no money-path behavior changed.

## Verification

- `pnpm vitest run src/features/treasury` — 33 files, 234 tests passed. Existing `act(...)` warnings remain suite noise; no unhandled error remained in the final run.
- `pnpm typecheck` — exit 0.
- `pnpm lint` — exit 0; includes TanStack-key, design-system, and ESLint-rule audits.
- `node tools/audit-tanstack-keys.mjs` — 0 new violations.
- `node tools/audit-design-system.mjs` — 0 new violations.
- `./vendor/bin/phpunit tests/Feature/Treasury/PaymentRepositoryTest.php` — 14 tests, 39 assertions passed.
- `./vendor/bin/pint --dirty --test` — pass.
- `npx react-doctor@latest --verbose --scope changed --base 5a587790725a2b8a23d72ffb4a70d1e21d41aa09` — 100/100, no issues.
- Protected `RepositoryDetailPage`, `ExpenseDetailPage`, `BankPicker`, and bank feature files were not changed.
