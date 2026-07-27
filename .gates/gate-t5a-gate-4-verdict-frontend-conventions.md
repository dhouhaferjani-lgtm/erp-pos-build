GATE VERDICT: REJECT

## Scope of verification

Reviewed `t5a-gate-3..HEAD` (`5707ea46d` → `67fc81173`) across the listed FE files, plus the backend contract they target (`PayExpenseRequest.php`, `PayExpenseRequestData.php`, `ExpenseService::settleByInstrument`, `MaturingInstrumentsController`) and the token/guardrail sources.

**Evidence I could not independently reproduce:** every `pnpm` / `vitest` / lint invocation was denied by the sandbox in this session (4 attempts, 3 command forms). Per the reviewer protocol I do not accept the reported 10/10 + lint-0 counts as verified. The findings below are static and code-grounded, and two of them are invisible to the suite as written regardless of whether it is green.

---

## MAJOR — cheque submits a phantom maturity date carried over from effet

`apps/web/src/features/expenses/components/PayExpenseDialog.tsx:283-300` mounts `instrument_maturity_date` **only** when `instrumentKind === 'effet'`. The form is created with no `shouldUnregister` override (`PayExpenseDialog.tsx:76`), so react-hook-form's default `shouldUnregister: false` retains the value in form state after the field unmounts. The `instrumentKind` effect clears only the method (`PayExpenseDialog.tsx:100-102`), and the payload builder reads the field unconditionally:

```
PayExpenseDialog.tsx:115   maturity_date: form.instrument_maturity_date || null,
```

Repro: mode = instrument → kind = effet → enter maturity `2026-09-30` → switch kind to cheque → submit. Payload carries `instrument.maturity_date: "2026-09-30"` on a cheque.

Nothing downstream defends it. `apps/api/app/Modules/Expense/Presentation/Requests/PayExpenseRequest.php:78` validates `instrument.maturity_date` as `['nullable','date']` with no kind conditional, and `ExpenseService::settleByInstrument` passes it straight through to `ReceiveInstrumentData(maturityDate: $data->instrumentMaturityDate, …)`. The cheque is persisted with a fabricated maturity and then appears in `MaturingInstrumentsController::bucketFor` under a real bucket rather than the at-sight `d0_7` default.

Gate focus item 3 explicitly required "cheque does not send a phantom maturity date" — unmet. No test exercises the effet→cheque switch; `PayExpenseDialog.test.tsx:128-160` only asserts `maturity_date: null` for a cheque that was *never* effet.

**Fix:** clear `instrument_maturity_date` in the kind effect (`:100-102`), and null it at build time when `kind !== 'effet'`. Add the effet→cheque regression test.

## MAJOR — BankPicker fallback creates a filled field whose value is silently discarded

`PayExpenseDialog.tsx:268-279` wires the full fallback contract (`isFallback`, `fallbackValue`, `onFallbackChange`, `onFallbackValueChange`) backed by local state at `:64-65`. When the user takes that branch, `BankPicker.tsx:91-115` renders a plain text `Input` that **never calls `onChange`**, so `selectedBank` stays `null`. Submission then sends:

```
PayExpenseDialog.tsx:114   bank_id: selectedBank?.id ?? null,
```

The typed bank name is dropped with no warning, and there is no free-text field on the backend to receive it — `PayExpenseRequest.php:70-74` accepts only `instrument.bank_id` as a scoped UUID. The user sees a completed, valid-looking bank field and gets an instrument with no bank. This is precisely the "misleading value silently discarded" case gate focus item 4 asked me to inspect.

It is also structurally untestable as shipped: `PayExpenseDialog.test.tsx:26-35` replaces `BankPicker` with a one-line button stub, so the fallback branch, the picker's combobox semantics, and its keyboard/RTL behaviour are all outside the green suite.

**Fix:** either drop the fallback affordance in this dialog (pass a non-fallback-capable wrapper) or thread `instrument.bank_name` through request → DTO → `ReceiveInstrumentData`. Do not leave a writable field with no sink.

## MAJOR — cash mode still lists cheque/effet payment methods, bypassing the instrument path

`PayExpenseDialog.tsx:82-84`:

```ts
const availableMethods = mode === 'instrument'
  ? methods.filter((method) => method.instrument_kind === instrumentKind)
  : methods
```

Instrument mode is correctly narrowed, but cash mode renders the **unfiltered** active list — including the methods whose `instrument_kind` is `'cheque'` / `'effet'` (now exposed at `usePaymentMethods.ts:10` and served by `PaymentMethodController::formatMethod:236`). Selecting "Cheque" while mode = cash submits the legacy contract (`PayExpenseDialog.tsx:119-123` — no `mode`, no `instrument`), `PayExpenseRequest.php:52` defaults `mode` to `cash`, and `ExpenseService` takes the cash branch: the repository is debited and the expense records a cheque method with **no `PaymentInstrument` row, no `issued` event, and no `checks_to_pay` GL leg**. A cheque leaves the company untracked by the portfolio the rest of Phase ⑤a is built on.

Wave 4 is the change that makes the two modes semantically disjoint, so the cash list must now exclude instrument-kind methods (`method.instrument_kind === null`).

## MINOR — instrument kind has an untranslated, undisplayed validation rule

`PayExpenseDialog.tsx:233-246`: the `FormField` is marked `required` but has no `error` prop, and the rule is `register('instrument_kind', { required: true })` — a bare boolean, so any failure yields no message and no inline error. Unreachable today (the `Select` defaults to `cheque`), but it violates the translated-validation-message convention. Drop the rule or give it a translated message plus `error={errors.instrument_kind?.message}`.

## MINOR — unscoped invalidation prefixes (informational, not blocking)

`apps/web/src/features/expenses/hooks/useExpenses.ts:249-254` invalidates `['instruments']` and `['maturing-instruments']`, while the consumers key on `tenantScopedKey(['instruments', filterKey])` / `tenantScopedKey(['maturing-instruments', maturityFilterKey])` (`InstrumentListPage.tsx:132,144`). Since `tenantScopedKey` appends tenant/company as **suffixes**, the bare prefix matches every tenant's entries. That over-invalidates (a wasted refetch) rather than colliding, and it mirrors the adjacent pre-existing `['treasury-cash-position']` line — no cross-company data leak. Recording it so it isn't mistaken for scoped invalidation later.

## MINOR — redundant ARIA role

`InstrumentListPage.tsx:319-325`: `role="region"` on a `<section>` that already carries `aria-label` is redundant (a named `section` maps to `region`). Harmless; remove for cleanliness.

---

## Verified clean (no finding)

- **Cash contract is byte-identical to legacy** — `PayExpenseDialog.tsx:119-123` emits no `mode` key, coerces `''` → `null` for the method, and sends no amount; pinned by `PayExpenseDialog.test.tsx:96-115`.
- **Instrument contract matches the backend discriminated shape** — `PayExpenseDialog.tsx:105-118` against `PayExpenseRequest.php:52-80`; discriminated union typed at `types/index.ts:201-217`.
- **No numeric coercion anywhere in the diff** — zero `parseFloat` / `Number(` / unary `+`; the amount is never client-supplied, and `ExpenseService` derives it via `CurrencyScale::bcformatStrict($expense->total, …)`. Rule 19 satisfied.
- **No stale repository or mismatched method across mode/kind switches** — `PayExpenseDialog.tsx:95-102` clears both on mode change and the method on kind change; instrument repositories narrowed to `type === 'bank_account'` (`:79-81`) matching the union at `usePaymentRepositories.ts:12`.
- **Schedule counts are arithmetically sound** — `MaturingInstrumentsController::index` returns *all* matching rows (`->get()`, unpaginated), so the client-side `filter(direction && bucket).length` at `InstrumentListPage.tsx:339-341` is a true count, not a page count; directional totals read from server `meta.buckets[bucket][total_in|total_out]` and `meta.grand_total`.
- **Tokens only, no runtime class composition** — `borderFocus: 'border-blue-500'` (`designTokens.ts:214`) and `bgSubtle: 'bg-blue-50'` (`:196`) are complete static classes joined by a space at `PayExpenseDialog.tsx:165`; no `hover:${…}` or `${…}/50` composition, no raw color literals added, no arbitrary values.
- **i18n complete and in phase** — EN/FR/AR verified key-by-key across `expenses.pay.*` (incl. `modes.*`, `instrument.*`) and `treasury.instruments.schedule.*`; AR additionally gained a previously-absent `expenses.pay` block and `treasury.instruments.buckets`. No hardcoded user-facing strings; no directional visual hacks introduced (layout is `grid`/`flex`, RTL-safe).
- **No absorbed debt** — `git diff t5a-gate-3..HEAD -- apps/web/tools/ apps/web/eslint.config.js` is empty: the design-system baseline and query-key audit config are untouched, so no violation was written away.
- **Canonical components throughout** — `Input`, `Select`, `Button`, `FormField`, `StatusBadge`, `Radio` (atom exists at `components/atoms/Radio/`), `Modal`, and the existing `BankPicker`; no parallel picker, no raw form control.

---

VERDICT: REJECT

Before the ⑤a exit review: null `instrument.maturity_date` whenever kind ≠ effet (clear it on kind change and guard at payload build, with an effet→cheque regression test); resolve the BankPicker fallback — either remove the affordance or thread `bank_name` end to end — and stop mocking the picker away in the dialog test; and filter cash-mode methods to `instrument_kind === null` so a cheque can no longer be settled as untracked cash.
