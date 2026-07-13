# Treasury Phase 4 — Gate 1 RC1 adversarial review

- Scope: Wave 1 / Tasks 1–6
- Diff reviewed: `origin/dev..HEAD`
- Reviewed HEAD: `806fceace`
- Model tier: Fable (`claude-fable-5`)

## Verdict

**APPROVE — no BLOCKER, HIGH, or MEDIUM findings.**

## Hard-gate verification

- **Port inviolate:** `git diff origin/dev..HEAD -- apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php` is empty.
- **Console safety:** `ExpenseService` has no `CompanyContext` reference. `create()` resolves the company from explicit `company_id` (`ExpenseService.php:74`), and `update()` resolves it from the stored document (`ExpenseService.php:166`). The cleared-context shape is pinned by `ExpenseServiceVatTest.php:325`.
- **Balanced journal entry and off-grid 422 behavior:** the string-fraction grid guard at `ExpenseService.php:276-287` catches values beyond the currency grid, including digits beyond `scale + 1`; `DomainException` renders as 422 through `bootstrap/app.php:356`. Subtotal is derived from total minus VAT, so the three-line split balances by construction.
- **GL 4456 equals declared deductible VAT:** `GeneralLedgerService.php:3410` and the tax-detail write at `ExpenseService.php:365` both consume `Shared\Domain\ExpenseVatSplit::deductible()`. The helper uses `scale + 2` intermediates and one `CurrencyScale::bcround()` at the posting boundary. Equality is asserted directly at `ExpenseVatPostingTest.php:156`.
- **Precision and perimeter sweeps:** no float money operations, literal bcmath scales, no-argument `scale()` calls, or hardcoded color regressions were found in the diff. Settlement, linked-cost capitalization, fiscal surfaces, and `TreasuryMovementService` remain untouched. The stale `document_tax_details` widening migration was not added. Zero VAT normalizes to the full VAT-less trio in create and update.
- **Pinned coverage:** every plan-pinned Task 1–5 assertion exists, with additional zero-decimal grid, beyond-`scale + 1`, collision-safe tax-detail identity, and cross-company partner-disclosure cases. The paid-VAT reconcile fixture pins `repository.gl_account_id` to the purpose-resolved Cash account and exercises the authoritative branch.
- **Recorded deviations:** the Task 5 response serialization expansion, the caught-and-reverted `Document` PHPDoc widening, and the VAT-less off-grid disposition are recorded in the progress file. The VAT-less disposition matches the binding brief.
- **Preflight caveat:** none of the 18 Pint-flagged files appear in the branch diff; the formatting drift belongs to `origin/dev` and is correctly not attributed to Phase 4.

## Findings

1. **LOW:** `is_numeric()` guards at `ExpenseService.php:81-83` and `ExpenseService.php:171-173` accept scientific-notation or whitespace forms that bcmath would reject with `ValueError`. HTTP request regexes and the planned Wave 2 command do not expose those forms, so this is not gate-blocking; optional defensive hardening can be considered later.
2. **LOW:** the `post()` tax-detail block uses an unguarded `$metadata->vat_deductible_percent` at `ExpenseService.php:364` next to `$metadata?->vat_rate` at line 366. The path is unreachable without expense metadata and is cosmetic rather than a functional defect.
3. **INFO:** frontend `computeVatFromInclusive` truncates its suggestion rather than rounding; the value is suggestion-only and user-editable, with no backend parity contract. The historical-rate option also renders a raw percent label instead of `formatPercent`.

## Review limitation

The reviewer session could inspect code and run read-only diff sweeps but could not execute the test suite in its sandbox. Runtime-green claims therefore rely on the exact commands, counts, and exit codes committed in `.superpowers/sdd/task-6-report.md`; code-level claims were verified directly. The reviewer session also lacked permission to persist this file, so the controller recorded the emitted review text without changing its substance.

VERDICT: APPROVE
