# VERDICT: PASS

Final review gate for worktree `chore/banks-tug-polish` (delta vs `origin/dev`). All seven brief items are implemented to spec, the concurrent owner clarification on `origin/dev` is preserved, no out-of-scope files are touched, and every behavioral change carries a test. A PASS means no required work remains.

## Requirements (items 1–7)

| # | Item | Status | Notes |
|---|------|--------|-------|
| 1 | Repository currency exposure | PASS | Backend `'currency' => $repository->currency` added to `formatRepository()`; FE `Repository` interface gains `currency: string`; `repositoryCurrency={repository.currency}` replaces `companyCurrency`. Backend show test pins `data.currency`; FE tests updated. `companyCurrency` remains used elsewhere. |
| 2 | `formatCurrency` on pay-dialog total | PASS | Uses the real typed API `formatCurrency(expense.total, { currency: expense.currency })` (options object, not positional). Test asserts against `formatCurrency(...)` output rather than a hardcoded string. |
| 3 | Localized 4-decimal validation | PASS | `noValidate` added to the form; RHF's localized pattern message renders; the step constraint remains; the test asserts `novalidate`, the inline message, and no mutation call. |
| 4 | Distinguish `unsupported_country` | PASS | Both bank-account surfaces branch on `status === 'unsupported'` and render `intent.info.textStrong` with `CircleAlert`, distinct from invalid caution state. English and French translations and tests cover both surfaces. |
| 5 | BankPicker a11y + dedicated test | PASS | Stable option IDs and `aria-activedescendant` track the active option. The dedicated test covers fallback toggle, keyboard selection, and empty/error states. |
| 6 | `PartnerData::fromModel` validator non-optional | PASS | The validator is non-null and required, the nullable guard is removed, all three callers pass it, and a reflection test pins the signature. |
| 7 | BanksSeeder preserves admin edits on re-run | PASS | Existing rows refresh only `bic`, `position`, and `city`; admin-managed fields remain unchanged. The test pins inactive/renamed preservation while BIC refreshes, and the deploy note is updated. |

## Blocking findings

None.

## Non-blocking findings

1. The Opus sandbox could not execute the targeted test commands, so the gate verified by inspection. The executing Codex session ran the required test and static-analysis commands locally before and after this gate.
2. The seeder create path explicitly spreads identity plus canonical attributes. This is behavior-equivalent to the former `updateOrCreate` create path.
3. Local `HEAD` is one documentation commit behind `origin/dev`. The concurrent owner clarification is present verbatim in the working copy, so the only checklist delta against `origin/dev` is the requested re-seed behavior update.

## Evidence

- Item 1: `PaymentRepositoryController.php`, `RepositoryDetailPage.tsx`, and their targeted tests.
- Item 2: `PayExpenseDialog.tsx`, `format.ts`, and `PayExpenseDialog.test.tsx`.
- Item 3: `AdjustBalanceDialog.tsx`, treasury translations, and `AdjustBalanceDialog.test.tsx`.
- Item 4: `AddRepositoryModal.tsx`, `PartnerBankAccountsSection.tsx`, English/French treasury and sales translations, and both targeted tests.
- Item 5: `BankPicker.tsx` and the new `BankPicker.test.tsx`.
- Item 6: `PartnerData.php`, the three `PartnerController.php` callers, and `PartnerBankAccountTest.php`.
- Item 7: `BanksSeeder.php`, `BankDirectoryTest.php`, and `bank-directory-deploy-checklist.md`.
- Scope inspection found no changes to PaymentInstrument, movement-port, GL, or instruments BankPicker/FK work.

Gate command: `claude -p --model claude-opus-4-8`.
