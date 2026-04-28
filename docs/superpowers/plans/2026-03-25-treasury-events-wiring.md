# Treasury Domain Events Wiring Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire 8 existing Treasury domain events into their dispatch locations and add integration tests proving they fire during real business operations.

**Architecture:** All 8 event classes already exist and follow the `DomainEvent` base pattern correctly. This plan only adds `event()` dispatch calls to the services/controllers where the business actions happen, plus integration tests using `Event::fake()`. The existing `PaymentRecorded` dispatch in `PaymentController::store()` (using `DB::afterCommit`) is the reference pattern.

**Tech Stack:** Laravel 12, PHP 8.2, PHPUnit, Spatie Event Sourcing

**Key Reference Files:**
- Event base: `app/Shared/Domain/Events/DomainEvent.php`
- Existing dispatch pattern: `PaymentController.php:191-202` (`DB::afterCommit` + `event(new PaymentRecorded(...))`)
- Existing integration test pattern: `tests/Unit/Document/ReturnNoteServiceTest.php:140-156`

**Convention:** Services that run inside `DB::transaction()` must dispatch events via `DB::afterCommit()` to ensure events only fire if the transaction succeeds. Controllers that don't use explicit transactions can dispatch directly after the model update.

**Routes (verified from `app/Modules/Treasury/Presentation/routes.php`):**
- Instruments: `/api/v1/payment-instruments/{instrument}/deposit|clear|bounce|transfer`
- Payments: `/api/v1/payments` (store)
- Payment store requires: `partner_id`, `payment_method_id`, `amount`, `payment_date` (all required)

---

### Task 1: Wire PaymentRefunded in PaymentRefundService::refundPayment()

**Files:**
- Modify: `app/Modules/Treasury/Domain/Services/PaymentRefundService.php` — add import + dispatch in `refundPayment()` and `partialRefund()`
- Create: `tests/Feature/Treasury/TreasuryEventDispatchTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Treasury/TreasuryEventDispatchTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Events\InstrumentBounced;
use App\Modules\Treasury\Domain\Events\InstrumentCleared;
use App\Modules\Treasury\Domain\Events\InstrumentDeposited;
use App\Modules\Treasury\Domain\Events\InstrumentTransferred;
use App\Modules\Treasury\Domain\Events\PaymentRefunded;
use App\Modules\Treasury\Domain\Events\PaymentReversed;
use App\Modules\Treasury\Domain\Events\ReconciliationCompleted;
use App\Modules\Treasury\Domain\Events\RepositoryBalanceChanged;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\Services\PaymentRefundService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TreasuryEventDispatchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Company $company;
    private User $user;
    private Partner $partner;
    private PaymentMethod $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->company = Company::factory()->for($this->tenant)->create();

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'Password1!',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->paymentMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        // Bind CompanyContext for service-level tests
        $context = $this->createMock(CompanyContext::class);
        $context->method('requireCompanyId')->willReturn($this->company->id);
        $context->method('requireCompany')->willReturn($this->company);
        $this->app->instance(CompanyContext::class, $context);
    }

    private function createCompletedPayment(string $amount = '100.000'): Payment
    {
        return Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => $amount,
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'reference' => 'PAY-' . Str::random(6),
        ]);
    }

    private function createInstrument(InstrumentStatus $status = InstrumentStatus::Received): PaymentInstrument
    {
        return PaymentInstrument::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'reference' => 'CHK-' . Str::random(6),
            'amount' => '500.000',
            'currency' => 'TND',
            'status' => $status,
            'received_date' => now(),
            'maturity_date' => now()->addDays(30),
        ]);
    }

    private function createBankRepository(): PaymentRepository
    {
        return PaymentRepository::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'BNK-' . Str::random(4),
            'name' => 'Bank Account',
            'type' => RepositoryType::BankAccount,
            'balance' => '0.000',
            'currency' => 'TND',
        ]);
    }

    // --- Task 1: PaymentRefunded ---

    public function test_refund_payment_dispatches_payment_refunded_event(): void
    {
        Event::fake([PaymentRefunded::class]);

        $payment = $this->createCompletedPayment();
        $service = app(PaymentRefundService::class);
        $refund = $service->refundPayment($payment, 'Customer request');

        Event::assertDispatched(PaymentRefunded::class, function (PaymentRefunded $event) use ($refund, $payment) {
            return $event->paymentId === $refund->id
                && $event->originalPaymentId === $payment->id
                && $event->amount === $refund->amount
                && $event->reason === 'Customer request';
        });
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_refund_payment_dispatches_payment_refunded_event`
Expected: FAIL — "The expected [PaymentRefunded] event was not dispatched"

- [ ] **Step 3: Add import and dispatch to PaymentRefundService**

In `app/Modules/Treasury/Domain/Services/PaymentRefundService.php`:

Add import:
```php
use App\Modules\Treasury\Domain\Events\PaymentRefunded;
```

In `refundPayment()`, after the `$payment->update(...)` block (line ~84) and before `return $refund;`, add:

```php
            DB::afterCommit(function () use ($refund, $payment, $reason): void {
                event(new PaymentRefunded(
                    paymentId: $refund->id,
                    tenantId: $refund->tenant_id,
                    companyId: $refund->company_id,
                    originalPaymentId: $payment->id,
                    amount: $refund->amount,
                    currency: $refund->currency,
                    reason: $reason,
                    refundedAt: $refund->created_at->toIso8601String(),
                ));
            });
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_refund_payment_dispatches_payment_refunded_event`
Expected: PASS

- [ ] **Step 5: Also dispatch from partialRefund()**

Same pattern — in `partialRefund()`, after `$payment->update(...)` and before `return $refund;`:

```php
            DB::afterCommit(function () use ($refund, $payment, $reason): void {
                event(new PaymentRefunded(
                    paymentId: $refund->id,
                    tenantId: $refund->tenant_id,
                    companyId: $refund->company_id,
                    originalPaymentId: $payment->id,
                    amount: $refund->amount,
                    currency: $refund->currency,
                    reason: $reason,
                    refundedAt: $refund->created_at->toIso8601String(),
                ));
            });
```

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Treasury/Domain/Services/PaymentRefundService.php tests/Feature/Treasury/TreasuryEventDispatchTest.php
git commit -m "feat(treasury): wire PaymentRefunded event dispatch in refund service"
```

---

### Task 2: Wire PaymentReversed in PaymentRefundService::reversePayment()

**Files:**
- Modify: `app/Modules/Treasury/Domain/Services/PaymentRefundService.php` — add import + dispatch in `reversePayment()`
- Modify: `tests/Feature/Treasury/TreasuryEventDispatchTest.php` — add test

- [ ] **Step 1: Write the failing test**

Add to `TreasuryEventDispatchTest.php`:

```php
public function test_reverse_payment_dispatches_payment_reversed_event(): void
{
    Event::fake([PaymentReversed::class]);

    $payment = $this->createCompletedPayment();
    $service = app(PaymentRefundService::class);
    $service->reversePayment($payment, 'Entry error');

    Event::assertDispatched(PaymentReversed::class, function (PaymentReversed $event) use ($payment) {
        return $event->paymentId === $payment->id
            && $event->amount === $payment->amount
            && $event->currency === 'TND';
    });
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_reverse_payment_dispatches_payment_reversed_event`
Expected: FAIL

- [ ] **Step 3: Add dispatch to reversePayment()**

Add import:
```php
use App\Modules\Treasury\Domain\Events\PaymentReversed;
```

In `reversePayment()`, after `$payment->update(...)` (line ~217) and before the transaction closure ends:

```php
            DB::afterCommit(function () use ($payment): void {
                event(new PaymentReversed(
                    paymentId: $payment->id,
                    tenantId: $payment->tenant_id,
                    companyId: $payment->company_id,
                    amount: $payment->amount,
                    currency: $payment->currency,
                    reversedAt: now()->toIso8601String(),
                ));
            });
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_reverse_payment_dispatches_payment_reversed_event`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Treasury/Domain/Services/PaymentRefundService.php tests/Feature/Treasury/TreasuryEventDispatchTest.php
git commit -m "feat(treasury): wire PaymentReversed event dispatch in refund service"
```

---

### Task 3: Wire 4 Instrument events in PaymentInstrumentController

**Files:**
- Modify: `app/Modules/Treasury/Presentation/Controllers/PaymentInstrumentController.php` — add imports + dispatch in `deposit()`, `clear()`, `bounce()`, `transfer()`
- Modify: `tests/Feature/Treasury/TreasuryEventDispatchTest.php` — add 4 tests

**Important:** These controller methods do NOT use `DB::transaction()`, so dispatch events directly (no `DB::afterCommit` needed).

- [ ] **Step 1: Write the 4 failing tests**

Add to `TreasuryEventDispatchTest.php`:

```php
public function test_deposit_instrument_dispatches_instrument_deposited_event(): void
{
    Event::fake([InstrumentDeposited::class]);

    $instrument = $this->createInstrument(InstrumentStatus::Received);
    $repository = $this->createBankRepository();

    $this->actingAs($this->user, 'sanctum')
        ->postJson("/api/v1/payment-instruments/{$instrument->id}/deposit", [
            'repository_id' => $repository->id,
        ])
        ->assertOk();

    Event::assertDispatched(InstrumentDeposited::class, function (InstrumentDeposited $event) use ($instrument, $repository) {
        return $event->instrumentId === $instrument->id
            && $event->repositoryId === $repository->id
            && $event->amount === '500.000';
    });
}

public function test_clear_instrument_dispatches_instrument_cleared_event(): void
{
    Event::fake([InstrumentCleared::class]);

    $instrument = $this->createInstrument(InstrumentStatus::Deposited);

    $this->actingAs($this->user, 'sanctum')
        ->postJson("/api/v1/payment-instruments/{$instrument->id}/clear")
        ->assertOk();

    Event::assertDispatched(InstrumentCleared::class, function (InstrumentCleared $event) use ($instrument) {
        return $event->instrumentId === $instrument->id
            && $event->amount === '500.000';
    });
}

public function test_bounce_instrument_dispatches_instrument_bounced_event(): void
{
    Event::fake([InstrumentBounced::class]);

    $instrument = $this->createInstrument(InstrumentStatus::Deposited);

    $this->actingAs($this->user, 'sanctum')
        ->postJson("/api/v1/payment-instruments/{$instrument->id}/bounce", [
            'reason' => 'Insufficient funds',
        ])
        ->assertOk();

    Event::assertDispatched(InstrumentBounced::class, function (InstrumentBounced $event) use ($instrument) {
        return $event->instrumentId === $instrument->id
            && $event->reason === 'Insufficient funds';
    });
}

public function test_transfer_instrument_dispatches_instrument_transferred_event(): void
{
    Event::fake([InstrumentTransferred::class]);

    $originalRepo = $this->createBankRepository();
    $instrument = $this->createInstrument(InstrumentStatus::Received);
    $instrument->update(['repository_id' => $originalRepo->id]);

    $targetRepo = $this->createBankRepository();

    $this->actingAs($this->user, 'sanctum')
        ->postJson("/api/v1/payment-instruments/{$instrument->id}/transfer", [
            'to_repository_id' => $targetRepo->id,
        ])
        ->assertOk();

    Event::assertDispatched(InstrumentTransferred::class, function (InstrumentTransferred $event) use ($instrument, $originalRepo, $targetRepo) {
        return $event->instrumentId === $instrument->id
            && $event->fromRepositoryId === $originalRepo->id
            && $event->toRepositoryId === $targetRepo->id;
    });
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="test_deposit_instrument|test_clear_instrument|test_bounce_instrument|test_transfer_instrument"`
Expected: All 4 FAIL

- [ ] **Step 3: Add imports to PaymentInstrumentController**

At top of `PaymentInstrumentController.php`, add:

```php
use App\Modules\Treasury\Domain\Events\InstrumentBounced;
use App\Modules\Treasury\Domain\Events\InstrumentCleared;
use App\Modules\Treasury\Domain\Events\InstrumentDeposited;
use App\Modules\Treasury\Domain\Events\InstrumentTransferred;
```

- [ ] **Step 4: Add dispatch to deposit() method**

After `$instrument->update([...])` (line ~161) and before `$freshInstrument = ...`:

```php
        event(new InstrumentDeposited(
            instrumentId: $instrument->id,
            tenantId: $tenantId,
            companyId: $companyId,
            repositoryId: $validated['repository_id'],
            amount: $instrument->amount,
            depositedAt: now()->toIso8601String(),
        ));
```

- [ ] **Step 5: Add dispatch to clear() method**

After `$instrument->update([...])` (line ~194) and before `$freshInstrument = ...`:

```php
        event(new InstrumentCleared(
            instrumentId: $instrument->id,
            tenantId: $tenantId,
            companyId: $companyId,
            amount: $instrument->amount,
            clearedAt: now()->toIso8601String(),
        ));
```

- [ ] **Step 6: Add dispatch to bounce() method**

After `$instrument->update([...])` (line ~232) and before `$freshInstrument = ...`:

```php
        event(new InstrumentBounced(
            instrumentId: $instrument->id,
            tenantId: $tenantId,
            companyId: $companyId,
            amount: $instrument->amount,
            reason: $validated['reason'] ?? '',
            bouncedAt: now()->toIso8601String(),
        ));
```

- [ ] **Step 7: Add dispatch to transfer() method**

In `transfer()`, capture `$fromRepositoryId` BEFORE the update (since `update()` mutates the model). Replace lines ~267-269:

```php
        $fromRepositoryId = $instrument->repository_id ?? '';

        $instrument->update([
            'repository_id' => $validated['to_repository_id'],
        ]);

        event(new InstrumentTransferred(
            instrumentId: $instrument->id,
            tenantId: $tenantId,
            companyId: $companyId,
            fromRepositoryId: $fromRepositoryId,
            toRepositoryId: $validated['to_repository_id'],
            amount: $instrument->amount,
            transferredAt: now()->toIso8601String(),
        ));
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test --filter="test_deposit_instrument|test_clear_instrument|test_bounce_instrument|test_transfer_instrument"`
Expected: All 4 PASS

- [ ] **Step 9: Commit**

```bash
git add app/Modules/Treasury/Presentation/Controllers/PaymentInstrumentController.php tests/Feature/Treasury/TreasuryEventDispatchTest.php
git commit -m "feat(treasury): wire 4 instrument domain events in PaymentInstrumentController"
```

---

### Task 4: Wire ReconciliationCompleted in BankReconciliationService

**Files:**
- Modify: `app/Modules/Treasury/Application/Services/BankReconciliationService.php` — add import + dispatch in `completeReconciliation()`
- Modify: `tests/Feature/Treasury/TreasuryEventDispatchTest.php` — add test

**Note:** `completeReconciliation()` uses `DB::transaction()`, so events MUST use `DB::afterCommit()`. The `$matchedItems` variable is already available from line 171 — reuse it for computing `matchedCount` and `matchedTotal`.

- [ ] **Step 1: Write the failing test**

Add to `TreasuryEventDispatchTest.php`:

```php
use App\Modules\Treasury\Domain\BankReconciliation;
use App\Modules\Treasury\Domain\Enums\ReconciliationStatus;

public function test_complete_reconciliation_dispatches_reconciliation_completed_event(): void
{
    Event::fake([ReconciliationCompleted::class]);

    $repository = $this->createBankRepository();

    $reconciliation = BankReconciliation::create([
        'id' => Str::uuid()->toString(),
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'repository_id' => $repository->id,
        'statement_date' => now()->toDateString(),
        'opening_balance' => '0.000',
        'closing_balance' => '10000.000',
        'statement_balance' => '10000.000',
        'difference' => '0.000',
        'status' => ReconciliationStatus::InProgress,
        'created_by' => $this->user->id,
    ]);

    $service = app(\App\Modules\Treasury\Application\Services\BankReconciliationService::class);
    $service->completeReconciliation($reconciliation->id, $this->user->id);

    Event::assertDispatched(ReconciliationCompleted::class, function (ReconciliationCompleted $event) use ($reconciliation, $repository) {
        return $event->reconciliationId === $reconciliation->id
            && $event->repositoryId === $repository->id
            && $event->matchedCount === 0;
    });
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_complete_reconciliation_dispatches_reconciliation_completed_event`
Expected: FAIL

- [ ] **Step 3: Add dispatch to completeReconciliation()**

Add import:
```php
use App\Modules\Treasury\Domain\Events\ReconciliationCompleted;
```

In `completeReconciliation()`, inside the `DB::transaction()` closure, after the reconciliation update (line ~191) and before `return $freshReconciliation;`:

Reuse the existing `$matchedItems` variable from line 171:

```php
            $matchedCount = $matchedItems->count();
            /** @var numeric-string $matchedTotal */
            $matchedTotal = '0.000';
            foreach ($matchedItems as $item) {
                $matchedTotal = bcadd($matchedTotal, (string) ($item->amount ?? '0'), $this->scaleResolver->getScale());
            }

            DB::afterCommit(function () use ($reconciliation, $matchedCount, $matchedTotal): void {
                event(new ReconciliationCompleted(
                    reconciliationId: $reconciliation->id,
                    tenantId: $reconciliation->tenant_id,
                    companyId: $reconciliation->company_id,
                    repositoryId: $reconciliation->repository_id,
                    matchedCount: $matchedCount,
                    matchedTotal: $matchedTotal,
                    completedAt: now()->toIso8601String(),
                ));
            });
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_complete_reconciliation_dispatches_reconciliation_completed_event`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Treasury/Application/Services/BankReconciliationService.php tests/Feature/Treasury/TreasuryEventDispatchTest.php
git commit -m "feat(treasury): wire ReconciliationCompleted event in BankReconciliationService"
```

---

### Task 5: Wire RepositoryBalanceChanged in PaymentController::store()

**Files:**
- Modify: `app/Modules/Treasury/Presentation/Controllers/PaymentController.php` — add import + dispatch in `store()`
- Modify: `tests/Feature/Treasury/TreasuryEventDispatchTest.php` — add test

**Note:** `store()` uses `DB::transaction()`, so events MUST use `DB::afterCommit()`. The balance update block already exists — we add `$previousBalance` capture and event dispatch alongside it, NOT replacing existing code.

- [ ] **Step 1: Write the failing test**

Add to `TreasuryEventDispatchTest.php`:

```php
public function test_payment_store_dispatches_repository_balance_changed_event(): void
{
    Event::fake([RepositoryBalanceChanged::class]);

    $repository = $this->createBankRepository();

    $this->actingAs($this->user, 'sanctum')
        ->postJson('/api/v1/payments', [
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => '250.000',
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'repository_id' => $repository->id,
        ])
        ->assertCreated();

    Event::assertDispatched(RepositoryBalanceChanged::class, function (RepositoryBalanceChanged $event) use ($repository) {
        return $event->repositoryId === $repository->id
            && $event->previousBalance === '0.000'
            && $event->changeAmount === '250.000';
    });
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_payment_store_dispatches_repository_balance_changed_event`
Expected: FAIL

- [ ] **Step 3: Add dispatch to PaymentController::store()**

Add import:
```php
use App\Modules\Treasury\Domain\Events\RepositoryBalanceChanged;
```

In `store()`, modify the `if ($repository instanceof PaymentRepository)` block (line ~300). Capture previous balance before the update and dispatch event after:

```php
                if ($repository instanceof PaymentRepository) {
                    /** @var numeric-string $currentBalance */
                    $currentBalance = $repository->balance ?? '0.00';
                    $previousBalance = $currentBalance;
                    $repository->balance = bcadd($currentBalance, $paymentAmount, $this->scale());
                    $repository->save();

                    $newBalance = $repository->balance;
                    DB::afterCommit(function () use ($repository, $tenantId, $companyId, $previousBalance, $newBalance, $paymentAmount, $validated): void {
                        event(new RepositoryBalanceChanged(
                            repositoryId: $repository->id,
                            tenantId: $tenantId,
                            companyId: $companyId,
                            previousBalance: $previousBalance,
                            newBalance: $newBalance,
                            changeAmount: $paymentAmount,
                            currency: $validated['currency'] ?? 'TND',
                            changedAt: now()->toIso8601String(),
                        ));
                    });
                }
```

This replaces lines 300-306. The existing balance update logic is preserved — we just add `$previousBalance` capture and the `DB::afterCommit` event dispatch.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_payment_store_dispatches_repository_balance_changed_event`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Modules/Treasury/Presentation/Controllers/PaymentController.php tests/Feature/Treasury/TreasuryEventDispatchTest.php
git commit -m "feat(treasury): wire RepositoryBalanceChanged event in PaymentController"
```

---

### Task 6: Run full Treasury test suite and PHPStan

**Files:** None modified — verification only.

- [ ] **Step 1: Run all Treasury tests**

Run: `php artisan test --filter=Treasury`
Expected: All tests pass (existing + 8 new integration tests)

- [ ] **Step 2: Run PHPStan on modified files**

Run: `./vendor/bin/phpstan analyse app/Modules/Treasury/Domain/Services/PaymentRefundService.php app/Modules/Treasury/Presentation/Controllers/PaymentInstrumentController.php app/Modules/Treasury/Application/Services/BankReconciliationService.php app/Modules/Treasury/Presentation/Controllers/PaymentController.php --level=8`
Expected: No errors

- [ ] **Step 3: Run Pint on modified files**

Run: `./vendor/bin/pint app/Modules/Treasury/Domain/Services/PaymentRefundService.php app/Modules/Treasury/Presentation/Controllers/PaymentInstrumentController.php app/Modules/Treasury/Application/Services/BankReconciliationService.php app/Modules/Treasury/Presentation/Controllers/PaymentController.php`

- [ ] **Step 4: Final commit if Pint made changes**

```bash
git add -A && git commit -m "style(treasury): apply Pint formatting to event dispatch code"
```
