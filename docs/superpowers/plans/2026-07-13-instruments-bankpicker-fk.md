# Instruments BankPicker and Bank Foreign Key Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Link payment instruments to the tenant bank directory in the existing payment form and enforce that reference at both HTTP-validation and database layers.

**Architecture:** Add a guarded tenant migration that removes unresolvable `bank_id` values before creating a `nullOnDelete` foreign key. Keep instrument lifecycle services untouched; validate all three HTTP write paths with `ScopedExists::tenant`, and adapt only `PaymentForm` to use the existing `BankPicker` plus warn-only RIB/IBAN helpers.

**Tech Stack:** Laravel 12 migrations and feature tests, PostgreSQL/SQLite-compatible schema APIs, React 19, React Hook Form, TanStack Query, Vitest, Testing Library.

## Global Constraints

- Do not add an instrument edit-details UI; `InstrumentDetailPage` remains lifecycle-only.
- Do not modify instrument lifecycle, GL, remittance, movement, or money logic.
- Checksum failures warn but never prevent submission.
- POS never writes `payment_instruments.bank_id` and is out of scope.
- The FK migration deploys only after the existing-tenant banks backfill.

---

### Task 1: Guarded bank foreign-key migration

**Files:**
- Create: `apps/api/database/migrations/tenant/2026_07_13_090000_add_bank_foreign_key_to_payment_instruments.php`
- Create: `apps/api/tests/Feature/Treasury/PaymentInstrumentBankForeignKeyTest.php`

**Interfaces:**
- Consumes: existing `payment_instruments.bank_id` and `banks.id` UUID columns.
- Produces: `payment_instruments_bank_id_foreign`, referencing `banks.id` with `ON DELETE SET NULL`.

- [ ] **Step 1: Write failing migration tests**

Cover four observable behaviors: an orphan is nulled and the count is logged; a valid bank reference survives; deleting the bank nulls the instrument reference; and a nonexistent bank insert is rejected after `up()`. Call `down()` before arranging an orphan, then invoke `up()` twice to prove re-runnability.

```php
Log::spy();
$migration->down();
DB::table('payment_instruments')->whereKey($instrument->id)->update(['bank_id' => $orphanId]);
$migration->up();
$migration->up();

$this->assertNull($instrument->fresh()->bank_id);
Log::shouldHaveReceived('warning')->once()->with(
    'Migration: nulled orphaned payment instrument bank references.',
    ['orphan_count' => 1],
);
```

- [ ] **Step 2: Run the migration test and verify RED**

Run: `cd apps/api && php artisan test tests/Feature/Treasury/PaymentInstrumentBankForeignKeyTest.php`

Expected: FAIL because the migration file and bank FK do not exist.

- [ ] **Step 3: Implement orphan cleanup and guarded FK creation**

The migration must return if either table or the column is absent, find the FK through `Schema::getForeignKeys('payment_instruments')`, null only rows whose non-null `bank_id` has no `banks.id`, log only when the affected count is positive, and add/drop only the FK (never the existing column or index).

```php
$orphanCount = DB::table('payment_instruments')
    ->whereNotNull('bank_id')
    ->whereNotExists(fn (Builder $query) => $query
        ->selectRaw('1')
        ->from('banks')
        ->whereColumn('banks.id', 'payment_instruments.bank_id'))
    ->update(['bank_id' => null]);

if ($orphanCount > 0) {
    Log::warning(
        'Migration: nulled orphaned payment instrument bank references.',
        ['orphan_count' => $orphanCount],
    );
}
```

- [ ] **Step 4: Run the migration test and verify GREEN**

Run: `cd apps/api && php artisan test tests/Feature/Treasury/PaymentInstrumentBankForeignKeyTest.php`

Expected: PASS on the default SQLite suite; PostgreSQL enforcement is covered by the same declarative foreign key in deployment.

### Task 2: Validate every instrument bank write path

**Files:**
- Modify: `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentInstrumentController.php`
- Modify: `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php`
- Modify: `apps/api/tests/Feature/Treasury/PaymentInstrumentTest.php`
- Modify: `apps/api/tests/Feature/Treasury/DeferredTenderPaymentTest.php`

**Interfaces:**
- Consumes: `ScopedExists::tenant('banks', $tenantId)`.
- Produces: HTTP 422 validation responses for nonexistent bank UUIDs from direct store, direct update, and nested `/payments` instrument creation.

- [ ] **Step 1: Add three failing 422 tests**

Use a well-formed absent UUID so the tests distinguish existence validation from UUID-shape validation. Assert the exact error path: `bank_id` for direct store/update and `instrument.bank_id` for `/payments`.

```php
$response->assertUnprocessable()->assertJsonValidationErrors('bank_id');
$nestedResponse->assertUnprocessable()->assertJsonValidationErrors('instrument.bank_id');
```

- [ ] **Step 2: Run the focused controller tests and verify RED**

Run: `cd apps/api && php artisan test tests/Feature/Treasury/PaymentInstrumentTest.php tests/Feature/Treasury/DeferredTenderPaymentTest.php --filter=bank_id`

Expected: FAIL because UUID-only rules allow the requests to reach persistence.

- [ ] **Step 3: Add tenant-scoped bank existence rules**

Append the same tenant rule to all three current UUID rules without touching downstream services:

```php
ScopedExists::tenant('banks', $company->tenant_id)
```

For `PaymentController`, use its existing `$tenantId`; for direct instrument store/update, use the required company’s `tenant_id`.

- [ ] **Step 4: Run the focused controller tests and verify GREEN**

Run: `cd apps/api && php artisan test tests/Feature/Treasury/PaymentInstrumentTest.php tests/Feature/Treasury/DeferredTenderPaymentTest.php --filter=bank_id`

Expected: PASS for all three paths.

### Task 3: PaymentForm BankPicker integration

**Files:**
- Modify: `apps/web/src/features/treasury/PaymentForm.tsx`
- Modify: `apps/web/src/features/treasury/__tests__/PaymentForm.instrument.test.tsx`

**Interfaces:**
- Consumes: `BankPicker`, `Bank`, `useBankAccountValidation`, and the company `country_code`.
- Produces: nested `instrument.bank_id`, synchronized bank-name directory state, fallback free text, a local derived-IBAN display that clears with an invalid RIB, and warning-only checksum feedback. Instruments continue to persist their existing `bank_account` field; this ticket does not add an IBAN column.

- [ ] **Step 1: Repair the pre-existing focused test harness failure**

Mock the modules that `PaymentForm` actually imports directly:

```tsx
vi.mock('@/components/organisms/AddPartnerModal/AddPartnerModal', () => ({ AddPartnerModal: () => null }))
vi.mock('@/components/organisms/AddRepositoryModal/AddRepositoryModal', () => ({ AddRepositoryModal: () => null }))
```

Run: `pnpm --filter @autoerp/web test -- src/features/treasury/__tests__/PaymentForm.instrument.test.tsx --run`

Expected: the original four tests PASS, proving the baseline failure was isolated to the stale mock target.

- [ ] **Step 2: Add failing picker/payload and warn-but-allow tests**

Render with company country `TN` and mocked `/banks` results. Select a directory bank, enter a valid RIB, verify derived IBAN, submit, and assert `bank_id` in the nested payload. Then enter an invalid 20-digit RIB, verify the warning remains visible, submit, and assert the API call still occurs. Also cover clearing a previously auto-derived IBAN when the RIB becomes invalid.

```tsx
expect(mockApiPost).toHaveBeenCalledWith('/payments', expect.objectContaining({
  instrument: expect.objectContaining({ bank_id: amenBank.id }),
}))
```

- [ ] **Step 3: Run the frontend test and verify RED**

Run: `pnpm --filter @autoerp/web test -- src/features/treasury/__tests__/PaymentForm.instrument.test.tsx --run`

Expected: FAIL because the form still renders a free-text bank name and omits `bank_id`.

- [ ] **Step 4: Implement the existing BankPicker pattern in PaymentForm**

Add form state for `bank_id`, selected bank, fallback mode, and derived `iban`; get `country_code` from `useCompanyConfig`; use the existing RIB validator on `bank_account`; mirror `AddRepositoryModal` selection/fallback behavior and auto-derived IBAN ref logic; add translated warning/status text already used by repository bank validation; include `bank_id: data.bank_id || undefined` in `instrument`.

- [ ] **Step 5: Run the frontend test and verify GREEN**

Run: `pnpm --filter @autoerp/web test -- src/features/treasury/__tests__/PaymentForm.instrument.test.tsx --run`

Expected: PASS with selection, payload, derivation/clear, fallback, and invalid-checksum submission covered.

### Task 4: Regression and quality verification

**Files:**
- Verify all files changed in Tasks 1–3.

**Interfaces:**
- Consumes: focused backend/frontend changes.
- Produces: evidence that no lifecycle, money, GL, movement, or POS behavior regressed.

- [ ] **Step 1: Run focused backend tests**

Run: `cd apps/api && php artisan test tests/Feature/Treasury/PaymentInstrumentBankForeignKeyTest.php tests/Feature/Treasury/PaymentInstrumentTest.php tests/Feature/Treasury/DeferredTenderPaymentTest.php tests/Feature/Treasury/PaymentInstrumentPortfolioColumnsTest.php`

Expected: PASS.

- [ ] **Step 2: Run focused frontend tests**

Run: `pnpm --filter @autoerp/web test -- src/features/treasury/__tests__/PaymentForm.instrument.test.tsx src/components/organisms/AddRepositoryModal/AddRepositoryModal.test.tsx src/components/molecules/pickers/BankPicker.test.tsx --run`

Expected: PASS.

- [ ] **Step 3: Run static checks and formatting**

Run: `cd apps/api && ./vendor/bin/pint --test app/Modules/Treasury/Presentation/Controllers/PaymentInstrumentController.php app/Modules/Treasury/Presentation/Controllers/PaymentController.php database/migrations/tenant/2026_07_13_090000_add_bank_foreign_key_to_payment_instruments.php tests/Feature/Treasury/PaymentInstrumentBankForeignKeyTest.php tests/Feature/Treasury/PaymentInstrumentTest.php tests/Feature/Treasury/DeferredTenderPaymentTest.php`

Run: `pnpm --filter @autoerp/web typecheck && pnpm --filter @autoerp/web lint`

Expected: PASS.

- [ ] **Step 4: Run repository preflight**

Run: `./scripts/preflight.sh`

Expected: PASS; if an unrelated baseline gate fails, record the exact command and failure without changing out-of-scope code.
