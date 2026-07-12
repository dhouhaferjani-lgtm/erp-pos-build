# CODEX brief — post-merge polish: bank directory + treasury UI gaps follow-ups

> All items originate from the 2026-07-12 final reviews (`docs/superpowers/audits/2026-07-12-{bank-directory,treasury-ui-gaps}-final-review.md`) — reviewer-specified, non-blocking, bundled into one pass. Both source branches are MERGED and their worktrees PRUNED: start fresh — `git worktree add ../erp.banks-polish -b chore/banks-tug-polish origin/dev`. TDD per item where behavior changes; tests by path only; one Opus gate at the end (`claude -p --model claude-opus-4-8`, review the whole diff vs this brief, verdict file in `docs/handoff/gate-reviews-polish/`). Do NOT push or merge; leave the worktree intact and report done.

## Items (each cites the review finding)

1. **Repository currency exposure** [TUG follow-up #1]: add `'currency' => $repository->currency` to `PaymentRepositoryController::formatRepository()` (apps/api, ~:262-278), add `currency: string` to the FE `Repository` interface in `RepositoryDetailPage.tsx`, pass `repositoryCurrency={repository.currency}` (currently `companyCurrency`, `:329`). Backend test pin: show-response contains currency.
2. **formatCurrency on pay-dialog total** [TUG #2]: `PayExpenseDialog.tsx:93` — render via `formatCurrency(expense.total, expense.currency)` instead of raw interpolation.
3. **Localized 4-decimal validation message** [TUG #3]: the adjust dialog's HTML5 `step` bubble (native, English) fires before RHF's localized `pattern` message — add `noValidate` on the dialog form (or drop the input step constraint) so the localized inline error renders; keep the no-network-fire behavior (test exists).
4. **FE distinguishes `unsupported_country`** [banks #4]: `PartnerBankAccountsSection.tsx` / `AddRepositoryModal.tsx` render the same red/caution state for `unsupported_country` as for a genuine `invalid_checksum` — show a neutral/informational state for `unsupported_country` (i18n en+fr, reuse existing warning components/tokens).
5. **BankPicker a11y + dedicated test** [banks #5]: add stable option `id`s + `aria-activedescendant` on the input tracking `activeIndex` (`BankPicker.tsx:149-170`); create `BankPicker.test.tsx` covering fallback toggle, keyboard select, empty/error states.
6. **`PartnerData::fromModel` validator param** [banks #3]: make the `?BankAccountValidatorInterface $bankAccountValidator = null` param non-optional (3 call sites, all in `PartnerController.php:161,217,298`, all already pass it) so future callers can't silently get empty `bank_accounts`. Run `CACHE_STORE=array php artisan typescript:transform` if any DTO signature/shape changes (it shouldn't).
7. **BanksSeeder preserves admin edits on re-run** [banks #2, unblocks repeatable backfill]: on UPDATE of an existing canonical row, do not overwrite `is_active` (and consider name) — only fill/refresh `bic`, `position`, `city`; keep create behavior unchanged. Pin with a test: deactivate a canonical bank, re-run seeder, still inactive. Update the caution note in `docs/handoff/bank-directory-deploy-checklist.md` §2 accordingly.

## Out of scope (do NOT touch)

- The instruments BankPicker/FK work — separate brief `TICKET-instruments-bankpicker-fk-2026-07-12.md`.
- Productizing the backfill artisan commands (pre-launch ticket, owner-scheduled).
- PaymentInstrument anything; movement port; GL logic.

## Verification before the gate

`pnpm typecheck`, `pnpm lint` (0 errors, audits 0 new), targeted vitest for every touched FE file; backend `./vendor/bin/phpunit` by path for touched tests; `./vendor/bin/phpstan` on touched modules; `./vendor/bin/pint` on touched files.
