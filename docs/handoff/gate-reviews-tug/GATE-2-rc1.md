# ADVERSARIAL GATE REVIEW — Treasury UI Gaps, GATE 2 (Wave B: Expense Pay/Settle)

- **Reviewer:** Claude (Opus 4.8), adversarial gate review
- **Date:** 2026-07-12
- **Range reviewed:** `git diff tug-gate-1..HEAD` (commits `5812ef9cf` Gate-1 approval record, `a3ff4f345` expense settlement UI)
- **Brief:** `docs/handoff/CODEX-treasury-ui-gaps-2026-07-10.md` §1 ground rules + Wave B acceptance criteria
- **Convention applied:** 2026-07-12 post-sweep — reads use `tenantScopedKey([...])` (tenant/company as suffix); invalidations use bare prefixes / tenant-aware predicates so TanStack prefix-matching is a real match, not a no-op.

## Files in scope (14; source files reviewed line-by-line)

- `apps/web/src/features/expenses/api/expenseApi.ts` — `pay()` client
- `apps/web/src/features/expenses/hooks/useExpenses.ts` — `usePayExpense` + `flatErrorMessage`
- `apps/web/src/features/expenses/components/PayExpenseDialog.tsx` (new)
- `apps/web/src/features/expenses/pages/ExpenseDetailPage.tsx` — Pay button wiring
- `apps/web/src/features/expenses/types/index.ts` — `PayExpenseRequest`
- `apps/web/src/hooks/usePermissions.ts` — `expenses.pay`
- Tests: `usePayExpense.test.tsx`, `PayExpenseDialog.test.tsx`, `ExpenseDetailPage.test.tsx`, `usePermissions.expenses.test.ts`
- i18n: `en/expenses.json`, `fr/expenses.json`
- Docs: `gate-reviews-tug/GATE-1-rc2.md`, `treasury-ui-gaps-progress.md`

---

## Adversarial hunt — results

### 1. Error-envelope handling (the two 422 shapes) — ✅ CORRECT
`useExpenses.ts:8-16` defines a local `flatErrorMessage()` that guards `error.response.data.error` and returns it **only when it is a string** (the flat-string domain 422 that `ExpenseController::pay` emits via `response()->json(['error' => $e->getMessage()], 422)`). `onError` (`:213-216`) resolves `flatErrorMessage(error) ?? getErrorMessage(error)`, then falls to `t('expenses:pay.error')` if both are empty. This is exactly the defensive extractor the brief §B2/§A1 mandates — it does **not** rely on `getErrorMessage()` alone (which reads `error.error.message` and would yield `undefined` on the flat-string shape). Verified by `usePayExpense.test.tsx:73-90` (flat string rendered verbatim, `getErrorMessage` NOT called) and `:92-107` (canonical `{error:{message}}` → `getErrorMessage`, then empty → i18n fallback). No test asserts a raw/default exception envelope. Correct.

### 2. Double-unwrap — ✅ NONE
`expenseApi.pay` (`expenseApi.ts:71-77`) uses raw `api.post<ExpenseResponse>` and returns `response.data` (single unwrap of the `{message, data}` envelope) — matches the brief §B1 template verbatim and the existing `expenseApi.post()`. It does **not** use `apiPost` (which would already unwrap), so there is no double-unwrap.

### 3. parseFloat / Number on money — ✅ NONE
The expense total is rendered as the raw decimal string: `{expense.total} {expense.currency}` (`PayExpenseDialog.tsx:93`). No `MoneyInput` is bound for an amount — correct, because `PayExpenseRequest` has **no amount field** (the full total is settled server-side). No `parseFloat`/`Number()`/float coercion anywhere in the diff. The dialog test even asserts absence of a numeric spinbutton (`PayExpenseDialog.test.tsx:59`) and that the submitted payload has no `amount` property (`:88`, and `usePayExpense.test.tsx:65`).

### 4. Missing `tenantScopedKey` — ✅ CORRECT (per 2026-07-12 convention)
`usePayExpense` (`useExpenses.ts:196-211`) invalidates four things:
- `predicate: expensesInvalidationPredicate(tenantId, companyId)` — tenant-aware predicate (same as approved `usePostExpense`).
- `queryKey: [...expenseKeys.detail(paidExpense.id)]` = `['expenses','detail',id]` — bare prefix. Read key is `tenantScopedKey([...expenseKeys.detail(id)])` = `['expenses','detail',id,tenant,company]` (`useExpenses.ts:55`), so the bare prefix matches by TanStack default prefix matching. **Verified not a no-op.** This is byte-identical to the already-approved `usePostExpense` invalidation (`:173`), which drives the "Paid" badge refresh.
- `queryKey: ['payment-repository', variables.data.payment_repository_id]` — bare prefix; read key is `tenantScopedKey(['payment-repository', id])` (`usePaymentRepositories.ts:65`). Matches. Identical to the Gate-1-approved `useAdjustRepositoryBalance.ts:61`.
- `queryKey: ['treasury-cash-position']` — bare prefix; read key is `tenantScopedKey(['treasury-cash-position'])` (`useCashPosition.ts:48`). Matches.

The cross-feature invalidations (repository detail + cash position) required by §B2 — "the one most likely to be silently skipped" — are **present and correct**, asserted by `usePayExpense.test.tsx:68-69`.

### 5. Hardcoded colors / design-audit regression — ✅ NONE observed
`PayExpenseDialog.tsx` imports and uses only design tokens: `semanticColorTokens.border.subtle`, `semanticColorTokens.surface.muted`, `textColors.tertiary/.primary` (`:12,84-92`). Remaining classes are layout/typography only (`space-y-4`, `rounded-lg`, `text-sm`, `text-lg`, `font-semibold`, `tabular-nums`, `h-4 w-4 animate-spin`) — no hardcoded color literals. Component is built exclusively on canonical atoms/organisms: `Modal/ModalHeader/ModalContent/ModalFooter`, `FormField`, `Input`, `Select`, `Button` — no new bespoke component where a canonical one exists (§1.11 satisfied). Page wiring reuses `Button` + `CreditCard` icon consistent with the existing Post action.

### 6. Missing i18n (en + fr) — ✅ COMPLETE
Every `expenses:pay.*` key referenced in `PayExpenseDialog.tsx` / `useExpenses.ts` (`title, total, repository, selectRepository, repositoryRequired, method, noMethod, date, dateRequired, submit, success, error`) exists in **both** `en/expenses.json:103-116` and `fr/expenses.json:103-116` with real translations (no placeholder/echo). `common:cancel` reused. No hardcoded user-facing strings — all via `t()`.

### 7. Permission gating, both layers — ✅ CORRECT
FE button gated on `hasPermission('expenses.pay')` combined with the full eligibility set (`ExpenseDetailPage.tsx:152-155`): `isPosted && !expense.metadata?.is_paid && expense.metadata?.expense_kind !== 'linked_cost' && hasPermission('expenses.pay')` — matches §B4 exactly and mirrors the existing draft/update gate. `PERMISSIONS['expenses.pay'] = ['admin','manager','accountant']` added (`usePermissions.ts:48`), asserted against backend-authorized roles (`usePermissions.expenses.test.ts:51-53`). Backend route `can:expenses.pay` is pre-existing/out of scope. The dialog itself is only mounted when the gate passes (`ExpenseDetailPage.tsx:301-307`).

### 8. Contract fidelity (no-amount, eligibility) — ✅ CORRECT
`PayExpenseRequest` = `{ payment_repository_id: string; payment_method_id?: string | null; payment_date: string }` (`types/index.ts:141-145`) — exactly the three server fields, no amount. Optional method mapped to `null` when empty (`PayExpenseDialog.tsx:64`). Repository/method selects populate from the correct existing hooks (`useActivePaymentRepositories`, `useActivePaymentMethods`), both of which return `[] ` fallbacks (`usePaymentRepositories.ts:50`, `usePaymentMethods.ts:52`) so `.map` cannot crash during load. Date defaults to today (`format(new Date(),'yyyy-MM-dd')`).

### 9. TDD / test honesty — ✅ STRONG
Tests exist for the hook, dialog, page gating, and permission map, and assert behavior (rendered output, exact payload, invalidation keys, verbatim error) rather than internals. Page test covers the positive case plus hides-Pay for missing-permission / already-paid / linked-cost via `it.each` (`ExpenseDetailPage.test.tsx:188-197`).

---

## Findings (numbered, by severity)

**BLOCKER:** none.
**HIGH:** none.
**MEDIUM:** none.

**LOW-1 (test coverage gap, non-blocking).** The `it.each` hides-Pay matrix (`ExpenseDetailPage.test.tsx:188-191`) covers missing-permission, already-paid, and linked-cost, but not the **draft-status** branch of the gate. The code (`isPosted &&` at `:152`) is plainly correct and a separate test asserts the Post button on a posted expense, but the "draft hides Pay" acceptance bullet has no dedicated assertion. Recommend adding one `it.each` row (`draft`, `{...fixtureExpense, status:'draft'}`, `()=>true`) for completeness. Not merge-blocking.

**NIT-1 (accepted duplication).** `flatErrorMessage` in `useExpenses.ts:8-16` duplicates the equivalent local extractor added for Wave A. This is **explicitly sanctioned** by the brief §Out-of-scope ("work around [`getErrorMessage`'s] blind spot locally … do not 'fix' it globally"). No action required; noted only so the eventual global-consistency fix knows both call sites exist.

**ADVISORY-1 (evidence, not a code defect).** The §2 verification commands (`pnpm typecheck`, `pnpm lint` incl. TanStack + design-system audits, targeted `pnpm vitest run`) could not be executed inside this review sandbox (command approval was unavailable in the autonomous review context). Static review confirms the code satisfies every gate the audits enforce (scoped read keys, token-only colors, canonical atoms, no `any`), but the runner must attach the actual green output — `pnpm typecheck` clean, `pnpm lint` 0 errors with TanStack audit 0 and design-system audit **0 new**, and the targeted expense vitest paths passing — to `treasury-ui-gaps-progress.md` before tagging `tug-gate-2`, exactly as Gate 1 did. Live Playwright drive of the cross-feature invalidation bullet (repository balance reduced after pay) is likewise owed per repo rule 5.

---

## Summary

Wave B is a clean, faithful implementation of the brief. It correctly handles both 422 shapes, adds no double-unwrap, keeps money as strings with no float coercion, invalidates the expense detail + chosen repository + cash-position views using bare-prefix keys that provably match their `tenantScopedKey`-suffixed reads (identical to the Gate-1-approved patterns), gates the Pay action on the full server-side eligibility set plus `expenses.pay`, ships complete en+fr i18n, and uses only canonical atoms and design tokens. Tests are behavior-level and honest. The only items are a non-blocking draft-case test gap and the standard requirement to attach re-run verification evidence before tagging.

VERDICT: APPROVE
