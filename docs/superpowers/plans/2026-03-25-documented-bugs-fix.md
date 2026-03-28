# Documented Bugs Fix Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix 6 production bugs discovered during the Tier 1 remediation audit, each documented with `markTestSkipped` in the test suite.

**Architecture:** All fixes are surgical changes to existing production code — no new modules, no architectural changes. Each bug is independent and can be fixed in any order. TDD: un-skip the existing test (or write a new one), verify it fails, apply the fix, verify it passes.

**Tech Stack:** Laravel 12, PHP 8.2+, PHPUnit, PostgreSQL 16

**Working directory:** `/Users/houssamr/Projects/syneriva/apps/erp/apps/api`

---

## File Structure

| Bug | Production Files | Test Files |
|-----|-----------------|------------|
| 1. Payment.is_reconciled | `app/Modules/Treasury/Domain/Payment.php` | `tests/Feature/Treasury/BankReconciliationTest.php` |
| 2. OpeningBalance postBatch | `app/Modules/Accounting/Application/Services/AccountingOpeningService.php` | `tests/Feature/Accounting/OpeningBalanceBatchTest.php` |
| 3. MultiPayment on-account | `app/Modules/Treasury/Domain/Payment.php` | `tests/Feature/Treasury/MultiPaymentTest.php` |
| 4. TaxCalculation type safety | `app/Modules/Taxation/Domain/TaxConfiguration.php` | `tests/Unit/Taxation/TaxCalculationServiceTest.php` |
| 5. Partner exemption warnings | `app/Modules/Partner/Domain/Partner.php` | `tests/Feature/Partner/PartnerTaxStatusTest.php` |
| 6. Company formatCompany | `app/Modules/Company/Presentation/Controllers/CompanyController.php` | `tests/Feature/Company/CreateCompanyTest.php` |

---

## Task 1: Fix Payment.is_reconciled Not in $fillable

**Context:** The bank reconciliation workflow calls `$payment->update(['is_reconciled' => true, 'reconciled_at' => now()])` but Eloquent silently ignores both fields because they're not in `$fillable`. Payments are never actually marked as reconciled. The DB columns exist (added by `2025_12_14_150000_create_bank_reconciliations_table.php`).

**Files:**
- Modify: `app/Modules/Treasury/Domain/Payment.php`
- Modify: `tests/Feature/Treasury/BankReconciliationTest.php`

- [ ] **Step 1: Un-skip the existing test**

In `tests/Feature/Treasury/BankReconciliationTest.php`, find the test `test_complete_reconciliation_marks_matched_payments_as_reconciled` which is currently wrapped in `$this->markTestSkipped(...)`. Remove the `markTestSkipped` call so the test runs.

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter="test_complete_reconciliation_marks_matched_payments_as_reconciled" --without-tty
```

Expected: FAIL — `is_reconciled` is still `false` after completing reconciliation because the field isn't in `$fillable`.

- [ ] **Step 3: Fix the Payment model**

In `app/Modules/Treasury/Domain/Payment.php`:

Add to the `$fillable` array:
```php
'is_reconciled',
'reconciled_at',
```

Add to the `casts()` method return array:
```php
'is_reconciled' => 'boolean',
'reconciled_at' => 'datetime',
```

Add to the class docblock:
```php
@property bool $is_reconciled
@property \Illuminate\Support\Carbon|null $reconciled_at
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test --filter="test_complete_reconciliation_marks_matched_payments_as_reconciled" --without-tty
```

Expected: PASS

- [ ] **Step 5: Run all Treasury tests for regressions**

```bash
php artisan test --filter=Treasury --without-tty
```

Expected: All pass.

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Treasury/Domain/Payment.php tests/Feature/Treasury/BankReconciliationTest.php
git commit -m "fix(treasury): add is_reconciled and reconciled_at to Payment fillable

Reconciliation completion called update() on these fields but Eloquent
silently ignored them due to mass-assignment protection. Payments were
never actually marked as reconciled in the database."
```

---

## Task 2: Fix OpeningBalance postBatch() Ordering

**Context:** `AccountingOpeningService::postBatch()` creates journal entries and marks rows as posted, but never locks the batch for immutability. The method calls `markRowsPosted()` then `markBatchValidated()`, but the lifecycle should end with the batch in `Locked` status (immutable with hash). The `lockBatch()` call is missing entirely.

The batch lifecycle is: `Draft` → `Validated` → `Locked`. After posting GL entries, the batch should be locked to prevent tampering.

**Files:**
- Modify: `app/Modules/Accounting/Application/Services/AccountingOpeningService.php`
- Modify: `tests/Feature/Accounting/OpeningBalanceBatchTest.php`

- [ ] **Step 1: Un-skip the existing tests**

In `tests/Feature/Accounting/OpeningBalanceBatchTest.php`, find these 3 skipped tests:
- `test_post_batch_creates_journal_entry_and_lines`
- `test_cannot_post_already_posted_batch`
- `test_opening_balance_posted_event_dispatched_on_post`

Remove the `markTestSkipped` calls.

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --filter="test_post_batch_creates_journal_entry_and_lines|test_cannot_post_already_posted_batch|test_opening_balance_posted_event_dispatched_on_post" --without-tty
```

Expected: FAIL — `markRowsPosted` then `markBatchValidated` fails because of ordering issues.

- [ ] **Step 3: Read the current postBatch method**

Read `app/Modules/Accounting/Application/Services/AccountingOpeningService.php` — find `postBatch()` and understand the full flow. Also read `OpeningBalanceBatchService::markBatchValidated()`, `markRowsPosted()`, and `lockBatch()` to understand each method's preconditions.

- [ ] **Step 4: Fix postBatch() to include lockBatch()**

In the `postBatch()` method, after the journal entry creation succeeds:

1. Call `markRowsPosted()` — marks individual rows as posted
2. Call `markBatchValidated()` — transitions batch from Draft to Validated
3. Call `$this->batchService->lockBatch($batch, $userId)` — transitions batch from Validated to Locked (immutable with hash)

The exact implementation depends on the current code structure. Read the method carefully and add the `lockBatch()` call at the right point — after the transaction succeeds and after validation.

- [ ] **Step 5: Update test assertions if needed**

The tests may need to assert that the batch ends in `Locked` status (not `Validated`). Update assertions to match the correct final state:
- `$batch->status` should be `BatchStatus::Locked` (or equivalent)
- `$batch->locked_at` should not be null
- `$batch->hash` should not be null

- [ ] **Step 6: Run tests to verify they pass**

```bash
php artisan test --filter="test_post_batch|test_cannot_post_already_posted_batch|test_opening_balance_posted_event_dispatched_on_post" --without-tty
```

Expected: PASS

- [ ] **Step 7: Run all Accounting tests for regressions**

```bash
php artisan test --filter=Accounting --without-tty
```

Expected: All pass.

- [ ] **Step 8: Commit**

```bash
git add app/Modules/Accounting/Application/Services/AccountingOpeningService.php tests/Feature/Accounting/OpeningBalanceBatchTest.php
git commit -m "fix(accounting): lock opening balance batch after posting

postBatch() created journal entries and marked rows as posted but
never locked the batch for immutability. Added lockBatch() call
after validation to transition batch to Locked status with hash.
Lifecycle: Draft -> Validated -> Locked."
```

---

## Task 3: Fix MultiPayment on-account Docblock

**Context:** The `Payment` model's docblock declares `@property string $payment_method_id` (non-nullable), but on-account payments legitimately have `null` payment_method_id. The DB column was already made nullable by migration `2026_03_02_100000_make_payment_method_id_nullable_on_payments_table.php`. The `$fillable` array already includes it. This is a documentation/static-analysis fix only.

**Files:**
- Modify: `app/Modules/Treasury/Domain/Payment.php`
- Modify: `tests/Feature/Treasury/MultiPaymentTest.php`

- [ ] **Step 1: Write a test for on-account payment with null payment_method_id**

In `tests/Feature/Treasury/MultiPaymentTest.php`, check if there's a skipped test for on-account payment. If so, un-skip it. If not, add a test:

```php
public function test_record_payment_on_account_has_null_payment_method(): void
{
    // Create a payment on account (advance without document allocation)
    // Assert the payment is created successfully
    // Assert payment_method_id is null
    // Assert the payment can be retrieved and serialized without errors
}
```

- [ ] **Step 2: Run test to verify current behavior**

```bash
php artisan test --filter="test_record_payment_on_account" --without-tty
```

Check if it passes or fails. If it passes, the code works — just fix the docblock. If it fails, investigate the actual error.

- [ ] **Step 3: Fix the Payment model docblock**

In `app/Modules/Treasury/Domain/Payment.php`, change:
```php
// From:
@property string $payment_method_id
// To:
@property string|null $payment_method_id
```

- [ ] **Step 4: Run Treasury tests for regressions**

```bash
php artisan test --filter=Treasury --without-tty
```

Expected: All pass.

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Treasury/Domain/Payment.php tests/Feature/Treasury/MultiPaymentTest.php
git commit -m "fix(treasury): Payment.payment_method_id docblock allows null

On-account payments have null payment_method_id. DB column already
nullable since migration 2026_03_02. Fix docblock for PHPStan."
```

---

## Task 4: Fix TaxCalculation Type Safety

**Context:** `TaxConfiguration::calculateAmount()` may return inconsistent types (int vs string) depending on the tax type (percentage vs fixed). `CalculatedTax::__construct($rate)` expects `?string`. The `TaxCalculationService` tests are currently skipped with `markTestSkipped` due to type errors at runtime.

The root fix is ensuring `TaxConfiguration` model properly casts its monetary fields so they always return strings.

**Files:**
- Modify: `app/Modules/Taxation/Domain/TaxConfiguration.php`
- Modify: `tests/Unit/Taxation/TaxCalculationServiceTest.php`

- [ ] **Step 1: Read the current TaxConfiguration model**

Read `app/Modules/Taxation/Domain/TaxConfiguration.php` to understand:
- The `casts()` method — are `percentage_rate` and `fixed_amount` cast?
- The `calculateAmount()` method — what does it return for each tax type?
- What types does the model return for `percentage_rate` and `fixed_amount`?

- [ ] **Step 2: Read the CalculatedTax DTO**

Find and read `CalculatedTax` (likely in `app/Modules/Taxation/Domain/DTOs/` or similar) to understand what types its constructor expects.

- [ ] **Step 3: Read the skipped tests**

Read `tests/Unit/Taxation/TaxCalculationServiceTest.php` to find the skipped tests and understand what type errors they encounter.

- [ ] **Step 4: Un-skip the tests**

Remove the `markTestSkipped` / try-catch wrappers from the skipped tests.

- [ ] **Step 5: Run tests to verify they fail with type errors**

```bash
php artisan test --filter=TaxCalculationServiceTest --without-tty
```

Expected: FAIL with TypeError.

- [ ] **Step 6: Fix TaxConfiguration**

Add proper casts for monetary fields:
```php
'percentage_rate' => 'decimal:4',
'fixed_amount' => 'decimal:3',
```

Ensure `calculateAmount()` always returns `string` — use `(string)` cast or `bcmul`/`CurrencyScale::bcformat()` for all return paths.

- [ ] **Step 7: Run tests to verify they pass**

```bash
php artisan test --filter=TaxCalculationServiceTest --without-tty
```

Expected: PASS

- [ ] **Step 8: Run all Taxation tests for regressions**

```bash
php artisan test --filter=Taxation --without-tty
```

Expected: No new failures from our changes (pre-existing failures in incomplete Taxation features are OK).

- [ ] **Step 9: Commit**

```bash
git add app/Modules/Taxation/Domain/TaxConfiguration.php tests/Unit/Taxation/TaxCalculationServiceTest.php
git commit -m "fix(taxation): add decimal casts to TaxConfiguration monetary fields

percentage_rate and fixed_amount were not cast, causing inconsistent
return types (int vs string) from calculateAmount(). Added decimal
casts to guarantee string types for bcmath compatibility."
```

---

## Task 5: Fix Partner getTaxExemptionWarnings Carbon Sign Bug

**Context:** `Partner::getTaxExemptionWarnings()` uses `$this->tax_exemption_valid_until->diffInDays(now())` which returns a **signed** value in Carbon 3 (Laravel 11+). When `valid_until` is far in the future, `diffInDays` returns a negative number (e.g., -275), which satisfies `<= 30`, causing false "expiring_soon" warnings for certificates that don't expire for months.

The `isPast()` check on line 332 catches actually-expired certificates, so the fix only needs to address the "expiring soon" branch.

**Files:**
- Modify: `app/Modules/Partner/Domain/Partner.php`
- Modify: `tests/Feature/Partner/PartnerTaxStatusTest.php`

- [ ] **Step 1: Write a test for the false positive**

In `tests/Feature/Partner/PartnerTaxStatusTest.php`, add:

```php
public function test_no_expiring_soon_warning_for_certificate_far_in_future(): void
{
    // Create a partner with tax_status = exempt
    // Set tax_exemption_valid_until to 275 days from now
    // Get tax status
    // Assert NO "expiring_soon" warning appears
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter="test_no_expiring_soon_warning_for_certificate_far_in_future" --without-tty
```

Expected: FAIL — the "expiring_soon" warning incorrectly fires because `diffInDays` returns -275 which is <= 30.

- [ ] **Step 3: Fix the Carbon call**

In `app/Modules/Partner/Domain/Partner.php`, find the line (around 338):

```php
// Current (buggy):
} elseif ($this->tax_exemption_valid_until->diffInDays(now()) <= 30) {

// Fixed — use absolute value:
} elseif ($this->tax_exemption_valid_until->diffInDays(now(), absolute: true) <= 30) {
```

The `absolute: true` parameter ensures `diffInDays` always returns a positive number. The `isPast()` guard above this branch already handles expired certificates, so `absolute: true` is safe here — it only affects the "expiring soon" path for future dates.

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test --filter="test_no_expiring_soon_warning_for_certificate_far_in_future" --without-tty
```

Expected: PASS

- [ ] **Step 5: Verify existing tests still pass**

The existing `test_tax_status_warnings_for_missing_and_expired_certificate` test includes an "expiring soon" scenario with a certificate expiring in 15 days. Verify it still triggers the warning correctly:

```bash
php artisan test --filter=PartnerTaxStatus --without-tty
```

Expected: All pass — 15 days still triggers warning, 275 days does not.

- [ ] **Step 6: Run all Partner tests for regressions**

```bash
php artisan test --filter=Partner --without-tty
```

Expected: All pass.

- [ ] **Step 7: Commit**

```bash
git add app/Modules/Partner/Domain/Partner.php tests/Feature/Partner/PartnerTaxStatusTest.php
git commit -m "fix(partner): Carbon diffInDays sign bug in tax exemption warnings

diffInDays() returns signed values in Carbon 3 (Laravel 11+).
Future dates returned negative values, satisfying the <= 30 check
and causing false 'expiring_soon' warnings for certificates months
away from expiry. Fixed with absolute: true parameter."
```

---

## Task 6: Fix CompanyController.formatCompany tax_status Null

**Context:** `CompanyController::store()` creates a company but doesn't include `tax_status` in the `create()` call. The DB default is `'REGISTERED'`, but the in-memory Eloquent model has `null` for `tax_status` until refreshed from DB. When `formatCompany()` accesses `$company->tax_status->value`, it crashes because `tax_status` is null on the in-memory model.

This only affects the `store()` method — `show()` and `update()` load from DB where the default is populated.

**Files:**
- Modify: `app/Modules/Company/Presentation/Controllers/CompanyController.php`
- Modify: `tests/Feature/Company/CreateCompanyTest.php`

- [ ] **Step 1: Read the current store() and formatCompany() methods**

Read `app/Modules/Company/Presentation/Controllers/CompanyController.php` to find:
- The `store()` method — how it creates the company
- The `formatCompany()` method — where it accesses `$company->tax_status->value`
- The `CompanyTaxStatus` enum — what's the default value

- [ ] **Step 2: Un-skip the relevant CreateCompanyTest tests**

In `tests/Feature/Company/CreateCompanyTest.php`, find tests that were skipped due to this bug (likely wrapped in `skipIfFormatCompanyBug` or `markTestSkipped`). Un-skip them.

- [ ] **Step 3: Run tests to verify they fail**

```bash
php artisan test --filter=CreateCompanyTest --without-tty
```

Expected: FAIL — TypeError or null property access on `tax_status->value`.

- [ ] **Step 4: Fix the store() method**

The cleanest fix is to include `tax_status` explicitly in the `create()` call. Find where `Company::create()` is called in the `store()` method and add:

```php
'tax_status' => CompanyTaxStatus::REGISTERED,
```

This is preferred over `$company->refresh()` because it's explicit and avoids an extra DB query.

Import the enum at the top of the file:
```php
use App\Modules\Company\Domain\Enums\CompanyTaxStatus;
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test --filter=CreateCompanyTest --without-tty
```

Expected: PASS

- [ ] **Step 6: Run all Company tests for regressions**

```bash
php artisan test --filter=Company --without-tty
```

Expected: All pass.

- [ ] **Step 7: Commit**

```bash
git add app/Modules/Company/Presentation/Controllers/CompanyController.php tests/Feature/Company/CreateCompanyTest.php
git commit -m "fix(company): include tax_status in Company::create() call

formatCompany() accessed tax_status->value but the in-memory model
had null after create() because the DB default wasn't loaded.
Explicitly set CompanyTaxStatus::REGISTERED in the create call
instead of relying on DB default."
```

---

## Task 7: Final Verification

- [ ] **Step 1: Run full test suite**

```bash
php artisan test --without-tty
```

Expected: Fewer failures than before (110 → fewer). The remaining failures should only be in unfinished modules (Vehicle, Service, Loyalty POS, Import, Identity, PlatformIntegration, Migrations).

- [ ] **Step 2: Verify no new skipped tests**

Check that all the `markTestSkipped` calls we targeted have been removed. Grep:

```bash
grep -r "markTestSkipped" tests/ | grep -v "vendor" | grep -v ".git"
```

Review: any remaining skips should be for genuinely unimplemented features, not bugs.

- [ ] **Step 3: Push to GitHub**

```bash
git push origin main
```

---

## Summary

| Task | Bug | Risk | Fix Type |
|------|-----|------|----------|
| 1 | Payment.is_reconciled not in $fillable | Low | Add to fillable + casts |
| 2 | OpeningBalance postBatch() missing lockBatch() | Medium | Add lockBatch() call after posting |
| 3 | Payment.payment_method_id docblock | Low | Docblock fix only |
| 4 | TaxConfiguration type safety | Medium | Add decimal casts to model |
| 5 | Partner exemption warnings Carbon sign | Low | Add `absolute: true` to diffInDays |
| 6 | CompanyController.formatCompany null | Low | Include tax_status in create() |

### Parallelization

All 6 tasks are independent EXCEPT:
- Tasks 1 and 3 both modify `Payment.php` — run sequentially or combine into one task
- All other tasks can run in parallel

Recommended: Combine Tasks 1+3 (both Payment model changes), then run Tasks 2, 4, 5, 6 in parallel.
