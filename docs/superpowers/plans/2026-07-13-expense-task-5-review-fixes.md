# Expense Task 5 Review Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the Task 5 review gaps in supplier persistence/security, expense presentation, picker accessibility, and edit-mode VAT behavior.

**Architecture:** Keep persistence in `ExpenseService`, company-safe loading in `ExpenseController`, and conditional serialization in `ExpenseResource`. Keep frontend behavior in the existing expense form/detail components and strengthen behavioral tests around real controlled picker values and router output.

**Tech Stack:** Laravel 12, Pest/PHPUnit feature tests, React 19, React Hook Form, React Router, Vitest, Testing Library.

## Global Constraints

- Strict red-green TDD for every production change.
- Preserve decimal-string arithmetic; do not add `Number` or `parseFloat` VAT math.
- Preserve company scoping, response envelopes, eager loading, design tokens, and existing component APIs.
- Do not stage `.superpowers/sdd/progress.md`.
- Produce one conventional Task 5 fix commit.

---

### Task 1: Supplier update persistence

**Files:**
- Modify: `apps/api/tests/Feature/Expense/ExpenseServiceVatTest.php`
- Modify: `apps/api/app/Modules/Expense/Application/Services/ExpenseService.php`

**Interfaces:**
- Consumes: `ExpenseService::update(Document $expense, array $data): Document`
- Produces: omission preserves `partner_id`; explicit string replaces it; explicit `null` clears it.

- [ ] Add tests that create a draft expense, update with a different `partner_id`, then update with explicit `null`.
- [ ] Run the focused tests and verify both fail on the unchanged partner.
- [ ] Add `'partner_id' => array_key_exists('partner_id', $data) ? $data['partner_id'] : $expense->partner_id` to the document update payload.
- [ ] Re-run the focused service tests and verify green.

### Task 2: Company-safe supplier response

**Files:**
- Modify: `apps/api/tests/Feature/Expense/ExpenseShowTest.php`
- Modify: `apps/api/app/Modules/Expense/Presentation/Controllers/ExpenseController.php`
- Modify: `apps/api/app/Modules/Expense/Presentation/Resources/ExpenseResource.php`

**Interfaces:**
- Consumes: current company ID from `CompanyContext` and the `Document::partner()` relation.
- Produces: a loaded `partner` relation constrained to `partners.company_id = currentCompanyId` and selected as `id,name`; resource emits partner ID only when that relation safely resolves.

- [ ] Add a show test that directly stores an inconsistent cross-company `partner_id` and asserts both `data.partner_id` and `data.partner` are null.
- [ ] Run that test and verify it exposes the foreign ID/name before the fix.
- [ ] Add a reusable controller relation closure and use it for every expense partner eager/load path.
- [ ] Make `ExpenseResource` derive both fields from the safely loaded relation only.
- [ ] Re-run show/create response tests and verify green with unchanged envelope.

### Task 3: Canonical route and legacy/null presentation

**Files:**
- Modify: `apps/web/src/features/expenses/pages/ExpenseDetailPage.test.tsx`
- Modify: `apps/web/src/features/expenses/pages/ExpenseDetailPage.tsx`

**Interfaces:**
- Produces: supplier links at `/purchases/suppliers/{id}` and exact `-` placeholders for absent subtotal/rate/deductible values.

- [ ] Replace the router mock with `MemoryRouter` plus a route destination probe; add vendor fallback and null VAT-row tests.
- [ ] Run the detail test and verify route and placeholder failures.
- [ ] Change the link target and conditionally append currency/percent only to non-null values.
- [ ] Re-run the detail test and verify green.

### Task 4: PartnerPicker accessible label

**Files:**
- Modify: `apps/web/src/components/molecules/pickers/PartnerPicker.test.tsx`
- Modify: `apps/web/src/components/molecules/pickers/PartnerPicker.tsx`

**Interfaces:**
- Preserves: `PartnerPickerProps` and selection behavior.
- Produces: a generated input ID connected to the rendered label's `htmlFor`.

- [ ] Add a real picker test asserting the combobox is named by a caller label and the label `for` equals its `id`.
- [ ] Run the focused picker test and verify failure.
- [ ] Generate the input ID with `useId`, set label `htmlFor`, and set input `id`.
- [ ] Re-run the picker suite and verify green.

### Task 5: Controlled edit synchronization and linked-cost clearing

**Files:**
- Modify: `apps/web/src/features/expenses/components/organisms/ExpenseFormFields.test.tsx`
- Modify: `apps/web/src/features/expenses/components/organisms/ExpenseFormFields.tsx`

**Interfaces:**
- Consumes: controlled `PartnerPicker` value and `expense.partner`/`expense.partner_id`.
- Produces: visible selected supplier in edit mode, editable vendor snapshot, and submitted linked-cost payload without VAT trio.

- [ ] Replace the picker button mock with a controlled mock that renders its `value`, supports selection and clear, and add edit/submission assertions.
- [ ] Run form tests and verify edit synchronization and VAT clearing failures.
- [ ] Initialize picker state from `expense.partner` when available and keep explicit clear/update writes synchronized with RHF.
- [ ] Re-run form tests and verify green.

### Task 6: Historical VAT rate fallback

**Files:**
- Modify: `apps/web/src/features/expenses/components/organisms/ExpenseFormFields.test.tsx`
- Modify: `apps/web/src/features/expenses/components/organisms/ExpenseFormFields.tsx`
- Modify: `apps/web/src/locales/en/expenses.json`
- Modify: `apps/web/src/locales/fr/expenses.json`
- Modify: `apps/web/src/locales/ar/expenses.json`

**Interfaces:**
- Produces: one stable fallback option when the stored nonempty `vat_rate` is absent from active configurations.

- [ ] Add an edit test with a stored rate not returned by active tax configuration hooks.
- [ ] Run it and verify the select cannot retain that option.
- [ ] Compare normalized decimal strings and prepend a translated historical-rate option only when missing.
- [ ] Re-run form tests and verify the stored rate remains visible and submittable.

### Task 7: Documentation, verification, and commit

**Files:**
- Modify: `.superpowers/sdd/task-5-report.md`
- Modify: `docs/handoff/treasury-phase4-progress.md`

**Interfaces:**
- Produces: auditable RED/GREEN evidence and one fix commit.

- [ ] Append each red/green result and the approved cross-layer deviations to the report and handoff.
- [ ] Run focused tests, full Expense backend/frontend cutoffs, root typecheck, focused lint, design audit, scoped PHPStan/Pint, pinned React Doctor at `ef4cea39e`, and diff checks.
- [ ] Stage only Task 5 review-fix files, explicitly excluding `.superpowers/sdd/progress.md`.
- [ ] Commit once with a conventional Task 5 fix message.

## Self-Review

- Spec coverage: all seven findings map to Tasks 1–6; required evidence/docs/commit map to Task 7.
- Placeholder scan: no deferred implementation placeholders remain.
- Type consistency: backend uses the existing `Document`/`ExpenseResource` contracts; frontend preserves existing `PartnerPickerValue`, `Expense`, and `CreateExpenseDTO` types.
