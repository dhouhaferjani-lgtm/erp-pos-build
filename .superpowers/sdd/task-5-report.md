# Task 5 report — supplier picker + VAT entry block

Status: **DONE**

## Outcome

- The generic expense form now pairs the canonical supplier `PartnerPicker` with an editable receipt-name snapshot.
- The gross amount is followed immediately by a quiet VAT block: configured percentage rate, editable currency amount, and 2-decimal deductible percentage defaulted to `100`.
- `computeVatFromInclusive()` uses only `bcadd`/`bcmul`/`bcdiv`/`bccomp` string helpers. No `Number` or `parseFloat` was introduced.
- Linked costs hide and clear the VAT trio.
- The detail page links the supplier and renders net, VAT amount/rate, deductible percentage, and gross total.
- English, French, and Arabic translations are complete.
- The API response now exposes the persisted supplier/VAT values needed by the real detail UI.

## TDD evidence

### Frontend RED

Command:

```text
pnpm --filter @autoerp/web test -- --run src/features/expenses/components/organisms/ExpenseFormFields.test.tsx src/features/expenses/pages/ExpenseDetailPage.test.tsx
```

Result: exit 1; 4 new failures and 15 existing passes. The form failures identified the missing supplier picker/VAT rate block; the detail failure identified the missing partner link/arithmetic.

### Backend response RED

Command:

```text
php artisan test tests/Feature/Expense/ExpenseShowTest.php tests/Feature/Expense/ExpenseRequestVatValidationTest.php --filter='response_contract|exposes_supplier'
```

Result: exit 1; 2 failed tests, each failing exactly at absent `data.partner_id`.

### GREEN

- Same focused frontend command: 2 files, 19/19 tests passed.
- Same focused backend command: 2 tests, 16 assertions passed.
- Full expense frontend path: 9 files, 65 passed, 3 todo.
- Full backend Expense cutoff: 65 passed, 266 assertions, 38.43s.

## Verification evidence

- `pnpm typecheck`: exit 0 across shared, POS, and web workspaces.
- Focused ESLint over every touched expense TS/TSX file: exit 0, 0 errors. The warning output is legacy/test hygiene and is not a blocking lint regression.
- `pnpm --filter @autoerp/web audit:design-system`: 753 acknowledged baseline, 0 new, 0 stale.
- Scoped PHPStan over `Document`, `ExpenseController`, and `ExpenseResource`: `[OK] No errors`.
- Scoped Pint `--test` over all touched PHP implementation/tests: `{"result":"pass"}`.
- `git diff --check`: run in the final verification bundle.

## React Doctor

- Required command: `npx react-doctor@latest --verbose --diff` (v0.7.6) ran to completion, but the deprecated flag compared the entire feature branch to `main`; it reported 143 branch-wide findings and 49/100.
- Authoritative task-pinned command: `npx react-doctor@latest apps/web --verbose --scope changed --base ef4cea39e --blocking none` exited 0 and scored **93/100**.
- Its only two findings are pre-existing whole-file diagnostics: the atoms barrel import in `ExpenseFormFields.tsx`, and `ExpenseDetailPage` being over 300 lines. At the Task 5 base those files were already 427 and 310 lines, respectively. Task 5 introduced no new React Doctor rule family or diagnostic.

## Design self-review

- Preserved AutoERP typography, page width, cards, inputs, and semantic color tokens.
- Added no font, gradient, promotional card, hardcoded color class, or decorative treatment.
- Kept receipt order legible: gross amount, VAT breakdown, then downstream payment details.
- Kept all new spacing direction-neutral/logical for RTL; Arabic copy is present.
- The only signature interaction is the decimal-safe suggested VAT amount that the bookkeeper can correct for receipt rounding.

## Necessary deviation

The planned frontend detail depended on values that were persisted but absent from `ExpenseResource`. With task-owner approval, the initial Task 5 commit added response-contract tests and minimal serialization/eager-loading for `partner_id`, partner `{id,name}`, `subtotal`, `tax_amount`, VAT rate, and deductible percentage. `Document`'s PHPDoc was also corrected to match the existing nullable database column, with the linkable-invoice partner name made null-safe. The later approved review fix to update persistence and safe loading is recorded below; posting logic, generated package types, and unrelated modules were not changed.

## Concerns

No functional concern. Existing expense tenant-scope tests emit `act(...)` warnings, and the Node test harness emits a local-storage warning; both predate Task 5 and all tests pass. React Doctor's two task-pinned warnings also predate Task 5 and are intentionally left out of this focused feature commit.

## Post-commit review fixes

### Corrected behavior

- `ExpenseService::update()` now preserves an omitted supplier, replaces an explicitly supplied `partner_id`, and clears it on explicit `null` using `array_key_exists` merge semantics.
- Every expense-response partner eager load now reuses one controller relation constraint: only `id` and `name` are selected and the partner must belong to the current company. `ExpenseResource` derives both `partner_id` and `partner` only from that safely loaded relation.
- The supplier detail link uses `entityRoutes.supplier()` and resolves to `/purchases/suppliers/{id}`.
- Legacy VAT rows render an exact `-` when net amount, rate, or deductible percentage is absent; no currency or `%` suffix is appended to the placeholder. A missing partner falls back to the editable receipt vendor snapshot.
- The canonical `PartnerPicker` now connects its visible label and combobox using generated `useId()` values.
- The form test picker is controlled and reflects its real `value`. Edit-mode supplier/snapshot behavior and linked-cost VAT clearing are pinned through submission.
- A stored nonempty VAT rate absent from active configurations is normalized with decimal-string helpers and retained as a translated historical option in English, French, and Arabic.

### Review-fix RED evidence

- Supplier persistence: `php artisan test tests/Feature/Expense/ExpenseServiceVatTest.php --filter='update_(replaces|clears)_partner'` exited 1 with 2/2 failures. Replacement retained the old UUID and explicit `null` retained the original UUID.
- Company isolation: `php artisan test tests/Feature/Expense/ExpenseShowTest.php --filter='does_not_disclose'` exited 1 at the foreign `data.partner_id` disclosure.
- Detail behavior: the real-router/null-display focused run exited 1 with the supplier link navigating to unmatched `/partners/supplier-1` and the legacy net row rendering `- TND`; the partner-null/vendor-snapshot case was already behaviorally green.
- Picker accessibility: the real `PartnerPicker` test exited 1 because the `Supplier` label did not give the combobox an accessible name.
- Historical VAT: the strengthened form suite exited 1 with 1 failure because stored `7.50` collapsed to the empty select option. The controlled edit synchronization and linked-cost submit-clearing cases were already green and required no production change.

### Review-fix GREEN and verification

- Supplier update focus: 2 passed.
- Same-company show, cross-company non-disclosure, and create response contracts: 3 passed, 20 assertions.
- Detail page: 19 passed; real `MemoryRouter` navigation catches the canonical route.
- Canonical `PartnerPicker`: 12 passed.
- Expense form: 5 passed, including edit synchronization, linked-cost submitted VAT clearing, and historical-rate persistence.
- Full backend cutoff: `php artisan test tests/Feature/Expense --display-warnings` exited 0 with 68 passed and 272 assertions in 62.79s.
- Full frontend cutoff plus the canonical picker: 10 files, 82 passed, 3 todo.
- Root `pnpm typecheck`: exit 0 across shared, POS, and web workspaces after correcting exact-optional test props and the local two-argument `bccomp` contract.
- Focused ESLint: exit 0 with 0 errors. Design audit: 753 acknowledged, 0 new, 0 stale.
- Scoped PHPStan: `[OK] No errors`. Scoped Pint was applied for controller formatting and its final `--test` is part of the final post-documentation bundle.
- Pinned React Doctor command remained `npx react-doctor@latest apps/web --verbose --scope changed --base ef4cea39e --blocking none`: exit 0, 93/100, with only the same two base-existing whole-file findings.

### Approved deviation

The task owner approved the minimal cross-layer correction needed for edit persistence and disclosure safety: only `Document.partner_id` merge persistence in `ExpenseService`, company-constrained response eager loading, and relation-derived supplier serialization changed. Posting, settlement, VAT split math, linked-cost capitalization, treasury movement, fiscal behavior, migrations, and generated package types remain unchanged.
