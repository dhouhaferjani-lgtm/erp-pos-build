I could not execute the suites — every `vitest`/`npx`/`pnpm` form was denied by the sandbox (4 attempts), same as the first review. Findings below are static and code-grounded.

```
GATE VERDICT: REJECT
```

## Scope

Verified `67fc81173..e99d78ffd` across the seven listed files, plus the sources they depend on: `usePaymentMethods.ts`, `PaymentMethodController::formatMethod`, `PaymentMethodSeeder`, `PaymentInstrumentController`, and the three other `BankPicker` call sites.

**Evidence I could not reproduce:** the reported 16/16 Vitest, lint exit 0, typecheck, React Doctor 154→152, and 9/71 backend counts. Not accepted as verified; not disputed either.

---

## MAJOR — cash-mode filter over-corrects and orphans `instrument_kind: 'other'` methods

`apps/web/src/features/expenses/components/PayExpenseDialog.tsx:80-82`:

```ts
const availableMethods = mode === 'instrument'
  ? methods.filter((method) => method.instrument_kind === instrumentKind)
  : methods.filter((method) => method.instrument_kind === null)
```

The kind union is four-valued — `'cheque' | 'effet' | 'other' | null` (`apps/web/src/features/treasury/hooks/usePaymentMethods.ts:10`). Instrument mode narrows to `instrumentKind`, which the form type constrains to `'cheque' | 'effet'` (`PayExpenseDialog.tsx:28`). So a method with `instrument_kind === 'other'` matches **neither** branch and is now unselectable anywhere in the dialog.

That is not hypothetical. `apps/api/database/seeders/PaymentMethodSeeder.php:259-274` seeds `DIRECT_DEBIT` / "Prélèvement" with `'instrument_kind' => InstrumentKind::Other` and `'is_active' => true`, inside `getFrancePaymentMethods()` (`:190`–`:348`). Every FR company gets it. Before `e99d78ffd` it appeared in cash mode; after, it appears nowhere — an FR user can no longer record an expense settled by prélèvement.

The codebase already states that `Other` is *not* an instrument: `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentInstrumentController.php:172` rejects it explicitly —

```php
if (! $method->has_maturity || $kind === null || $kind === InstrumentKind::Other) {
    return $this->domainError(new DomainException('Payment method is not a supported paper instrument method.'));
}
```

So `Other` belongs on the cash side. The correct predicate mirrors that line: `method.instrument_kind === null || method.instrument_kind === 'other'`. The regression is uncovered — `PayExpenseDialog.test.tsx:96-97` asserts only that `Cheque` and `Effet` are absent; no fixture carries an `other` method, so the suite is green either way.

Note also that `apps/web/src/features/treasury/PaymentForm.tsx` applies no such filter, so Prélèvement remains usable for customer/supplier payments — the loss is specific to expense settlement, which reads as accidental rather than intended.

---

## Prior findings — resolved

**① Phantom cheque maturity — RESOLVED, four layers deep.** State clear at `PayExpenseDialog.tsx:96-101` (`setValue('instrument_maturity_date', '')` on kind change); payload guard at `:114-116` (`form.instrument_kind === 'effet' ? … : null`); backend `required_if:instrument.kind,effet` at `PayExpenseRequest.php:80-84`; domain guard before any mutation at `ExpenseService.php:637-639`.

The regression test at `PayExpenseDialog.test.tsx:187-207` does exactly what the gate asked: switches kind to `effet` (`:191`), enters `2026-09-30` into the mounted maturity field (`:192`), switches back to `cheque` (`:193`), then asserts the **nested** submitted contract — `data.instrument.maturity_date === null` via `expect.objectContaining` (`:200-204`). Because the assertion is on `mutate` having been *called*, it also implicitly proves the retained `validate` rule at `:293-297` does not block submission after unmount — consistent with RHF skipping unmounted fields (`_f.mount === false`). Backend counterpart at `ExpensePayByInstrumentTest.php:180-209` asserts 422 plus zero instruments and zero journal entries.

**② BankPicker fallback — RESOLVED and backward-compatible.** `allowFallback?: boolean` defaults to `true` (`BankPicker.tsx:19,33`) and gates the only `onFallbackChange(true)` call site (`:218-231`); the `isFallback` render branch at `:96-122` retains its `chooseDirectory` escape hatch, so existing callers are byte-identical in behaviour. The dialog opts out at `PayExpenseDialog.tsx:276-281` and hardcodes `isFallback={false}`, making the fallback input unreachable. The three other call sites — `PartnerBankAccountsSection.tsx:97`, `PaymentForm.tsx:1004`, `AddRepositoryModal.tsx:76` — pass no `allowFallback`; I confirmed the first two persist the typed name (`setValue('bank_name', …)` at `PartnerBankAccountsSection.tsx:112-114` and `AddRepositoryModal.tsx:83-85`), so the default is right for them.

The dialog test now renders the **real** picker: the component stub is gone and only the data hook is mocked (`PayExpenseDialog.test.tsx:39`, fixture at `:67-79`). It drives the genuine combobox and selects a directory option (`:143-145`), and `:214-221` proves `bank.notListed` is absent. `BankPicker.test.tsx:87-93` pins the prop at the component level.

**③ Cash mode listing cheque/effet — resolved for cheque/effet** (`PayExpenseDialog.tsx:82`, pinned at `PayExpenseDialog.test.tsx:96-97`); the `'other'` overshoot is the MAJOR above. `formatMethod` always emits the key (`PaymentMethodController.php:236`, `$method->instrument_kind?->value`), so the `=== null` identity check is sound against `undefined` — the predicate is wrong, not the comparison.

**④ Bare instrument-kind rule — RESOLVED without weakening anything.** `register('instrument_kind')` at `:241` drops the untranslated boolean; the `Select` still has three static options and a `'cheque'` default (`:47`), so nothing reachable lost validation. Effet maturity keeps translated inline UI validation (`:290` `error={errors.instrument_maturity_date?.message}`, `:293-297` `validate` returning `t('expenses:pay.instrument.maturityDateRequired')`) and now has backend enforcement too.

## MINOR — carried over, unchanged, non-blocking

- `apps/web/src/features/expenses/hooks/useExpenses.ts:249-254` — `['instruments']` / `['maturing-instruments']` remain unscoped prefixes against `tenantScopedKey` consumers. Over-invalidation, not a leak.
- `apps/web/src/features/treasury/InstrumentListPage.tsx:324` — `role="region"` still redundant on a labelled `<section>`.

## No new debt introduced

`git diff 67fc81173..HEAD -- apps/web/tools/ apps/web/eslint.config.js apps/web/src/lib/designTokens.ts` is empty — no baseline or audit-config was written away. Grep over the web diff returns zero `parseFloat` / `Number(` / raw Tailwind color literals / bare `queryKey` additions. `allowFallback` reuses existing token strings only; no i18n keys added or orphaned by the correction (`bank.notListed` still consumed by the three default-`true` callers).

---

```
VERDICT: REJECT
```
Before the ⑤a exit review: widen the cash-mode predicate to `instrument_kind === null || instrument_kind === 'other'` (mirroring `PaymentInstrumentController.php:172`) so seeded `Other` methods like FR Prélèvement stay payable, and add an `other` method to the `useActivePaymentMethods` fixture so `PayExpenseDialog.test.tsx:96-97` pins its presence in cash mode and absence in instrument mode.
