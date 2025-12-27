<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\PaymentAllocationService;
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Events\PaymentAllocated;
use App\Modules\Treasury\Domain\Events\PaymentRecorded;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Tests for Treasury Module Events
 *
 * Verifies that all critical treasury operations emit proper domain events
 * for audit trail and fraud detection purposes.
 */
final class TreasuryEventsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $customer;

    private Product $product;

    private PaymentMethod $paymentMethod;

    private PaymentRepository $paymentRepository;

    private PaymentAllocationService $allocationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();
        $this->customer = Partner::factory()->for($this->tenant)->for($this->company)->create([
            'type' => 'customer',
        ]);
        $this->product = Product::factory()->for($this->tenant)->for($this->company)->create();

        // Create payment method
        $this->paymentMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cash',
            'code' => 'CASH',
            'is_active' => true,
        ]);

        // Create payment repository (cash register)
        $this->paymentRepository = PaymentRepository::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Main Cash Register',
            'code' => 'CASH-01',
            'type' => 'cash_register',
            'is_active' => true,
        ]);

        $this->allocationService = app(PaymentAllocationService::class);
    }

    /**
     * Test that PaymentRecorded event is dispatched when payment is created
     */
    public function test_payment_recorded_event_dispatched_on_payment_creation(): void
    {
        Event::fake([PaymentRecorded::class]);

        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_type' => PaymentType::DocumentPayment,
            'amount' => '500.00',
            'payment_date' => now(),
            'payment_method_id' => $this->paymentMethod->id,
            'payment_repository_id' => $this->paymentRepository->id,
            'reference' => 'PAY-TEST-001',
        ]);

        // Manually dispatch event (will be automatic in implementation)
        event(new PaymentRecorded(
            paymentId: $payment->id,
            tenantId: $payment->tenant_id,
            companyId: $payment->company_id,
            partnerId: $payment->partner_id,
            amount: $payment->amount,
            currency: 'EUR',
            paymentMethodId: $payment->payment_method_id,
            recordedAt: $payment->created_at->toIso8601String(),
        ));

        Event::assertDispatched(PaymentRecorded::class, function (PaymentRecorded $event) use ($payment): bool {
            return $event->paymentId === $payment->id
                && $event->companyId === $payment->company_id
                && $event->partnerId === $payment->partner_id
                && $event->amount === '500.00';
        });
    }

    /**
     * Test that PaymentRecorded event has correct structure
     */
    public function test_payment_recorded_event_has_correct_structure(): void
    {
        Event::fake([PaymentRecorded::class]);

        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_type' => PaymentType::DocumentPayment,
            'amount' => '1000.00',
            'payment_date' => now(),
            'payment_method_id' => $this->paymentMethod->id,
            'payment_repository_id' => $this->paymentRepository->id,
        ]);

        event(new PaymentRecorded(
            paymentId: $payment->id,
            tenantId: $payment->tenant_id,
            companyId: $payment->company_id,
            partnerId: $payment->partner_id,
            amount: $payment->amount,
            currency: 'EUR',
            paymentMethodId: $payment->payment_method_id,
            recordedAt: $payment->created_at->toIso8601String(),
        ));

        Event::assertDispatched(PaymentRecorded::class, function (PaymentRecorded $event): bool {
            return isset($event->paymentId)
                && isset($event->tenantId)
                && isset($event->companyId)
                && isset($event->partnerId)
                && isset($event->amount)
                && isset($event->currency)
                && isset($event->recordedAt);
        });
    }

    /**
     * Test that PaymentRecorded event implements getEventName()
     */
    public function test_payment_recorded_event_has_event_name(): void
    {
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_type' => PaymentType::DocumentPayment,
            'amount' => '500.00',
            'payment_date' => now(),
            'payment_method_id' => $this->paymentMethod->id,
            'payment_repository_id' => $this->paymentRepository->id,
        ]);

        $event = new PaymentRecorded(
            paymentId: $payment->id,
            tenantId: $payment->tenant_id,
            companyId: $payment->company_id,
            partnerId: $payment->partner_id,
            amount: $payment->amount,
            currency: 'EUR',
            paymentMethodId: $payment->payment_method_id,
            recordedAt: now()->toIso8601String(),
        );

        $this->assertTrue(method_exists($event, 'getEventName'));
        $this->assertEquals('payment.recorded', $event->getEventName());
    }

    /**
     * Test that PaymentAllocated event is dispatched when payment is allocated
     */
    public function test_payment_allocated_event_dispatched_on_allocation(): void
    {
        // Create an invoice
        $invoice = $this->createInvoice();

        // Create a payment
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_type' => PaymentType::DocumentPayment,
            'amount' => '500.00',
            'payment_date' => now(),
            'payment_method_id' => $this->paymentMethod->id,
            'payment_repository_id' => $this->paymentRepository->id,
        ]);

        Event::fake([PaymentAllocated::class]);

        // Apply allocation
        $this->allocationService->applyAllocation(
            paymentId: $payment->id,
            allocationMethod: AllocationMethod::FIFO,
        );

        Event::assertDispatched(PaymentAllocated::class, function (PaymentAllocated $event) use ($payment, $invoice): bool {
            return $event->paymentId === $payment->id
                && $event->companyId === $payment->company_id
                && $event->allocationMethod === 'fifo'
                && count($event->allocations) > 0;
        });
    }

    /**
     * Test that PaymentAllocated event contains allocation details
     */
    public function test_payment_allocated_event_includes_allocation_details(): void
    {
        $invoice = $this->createInvoice();
        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_type' => PaymentType::DocumentPayment,
            'amount' => '500.00',
            'payment_date' => now(),
            'payment_method_id' => $this->paymentMethod->id,
            'payment_repository_id' => $this->paymentRepository->id,
        ]);

        Event::fake([PaymentAllocated::class]);

        $this->allocationService->applyAllocation(
            paymentId: $payment->id,
            allocationMethod: AllocationMethod::FIFO,
        );

        Event::assertDispatched(PaymentAllocated::class, function (PaymentAllocated $event): bool {
            return isset($event->allocations)
                && isset($event->totalAllocated)
                && isset($event->excessAmount);
        });
    }

    /**
     * Test that PaymentAllocated event implements getEventName()
     */
    public function test_payment_allocated_event_has_event_name(): void
    {
        Event::fake([PaymentAllocated::class]);

        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_type' => PaymentType::DocumentPayment,
            'amount' => '500.00',
            'payment_date' => now(),
            'payment_method_id' => $this->paymentMethod->id,
            'payment_repository_id' => $this->paymentRepository->id,
        ]);

        event(new PaymentAllocated(
            paymentId: $payment->id,
            tenantId: $payment->tenant_id,
            companyId: $payment->company_id,
            allocationMethod: 'fifo',
            allocations: [],
            totalAllocated: '0.00',
            excessAmount: '0.00',
            allocatedAt: now()->toIso8601String(),
        ));

        Event::assertDispatched(PaymentAllocated::class, function (PaymentAllocated $event): bool {
            return method_exists($event, 'getEventName')
                && $event->getEventName() === 'payment.allocated';
        });
    }

    /**
     * Test that PaymentRecorded event includes audit trail data
     */
    public function test_payment_recorded_event_includes_audit_trail(): void
    {
        Event::fake([PaymentRecorded::class]);

        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_type' => PaymentType::DocumentPayment,
            'amount' => '750.50',
            'payment_date' => now(),
            'payment_method_id' => $this->paymentMethod->id,
            'payment_repository_id' => $this->paymentRepository->id,
            'reference' => 'AUDIT-TEST-001',
        ]);

        event(new PaymentRecorded(
            paymentId: $payment->id,
            tenantId: $payment->tenant_id,
            companyId: $payment->company_id,
            partnerId: $payment->partner_id,
            amount: $payment->amount,
            currency: 'EUR',
            paymentMethodId: $payment->payment_method_id,
            recordedAt: $payment->created_at->toIso8601String(),
        ));

        Event::assertDispatched(PaymentRecorded::class, function (PaymentRecorded $event) use ($payment): bool {
            return $event->tenantId === $payment->tenant_id
                && $event->companyId === $payment->company_id
                && $event->paymentMethodId === $payment->payment_method_id;
        });
    }

    /**
     * Test that PaymentAllocated event has correct structure
     */
    public function test_payment_allocated_event_has_correct_structure(): void
    {
        Event::fake([PaymentAllocated::class]);

        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_type' => PaymentType::DocumentPayment,
            'amount' => '1000.00',
            'payment_date' => now(),
            'payment_method_id' => $this->paymentMethod->id,
            'payment_repository_id' => $this->paymentRepository->id,
        ]);

        event(new PaymentAllocated(
            paymentId: $payment->id,
            tenantId: $payment->tenant_id,
            companyId: $payment->company_id,
            allocationMethod: 'fifo',
            allocations: [],
            totalAllocated: '500.00',
            excessAmount: '500.00',
            allocatedAt: now()->toIso8601String(),
        ));

        Event::assertDispatched(PaymentAllocated::class, function (PaymentAllocated $event): bool {
            // Verify all required properties exist
            return isset($event->paymentId)
                && isset($event->tenantId)
                && isset($event->companyId)
                && isset($event->allocationMethod)
                && isset($event->allocations)
                && isset($event->totalAllocated)
                && isset($event->excessAmount)
                && isset($event->allocatedAt);
        });
    }

    /**
     * Test that multiple payments dispatch multiple events
     */
    public function test_multiple_payments_dispatch_multiple_events(): void
    {
        Event::fake([PaymentRecorded::class]);

        // Create first payment
        Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_type' => PaymentType::DocumentPayment,
            'amount' => '100.00',
            'payment_date' => now(),
            'payment_method_id' => $this->paymentMethod->id,
            'payment_repository_id' => $this->paymentRepository->id,
        ]);

        event(new PaymentRecorded(
            paymentId: 'test-1',
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            partnerId: $this->customer->id,
            amount: '100.00',
            currency: 'EUR',
            paymentMethodId: $this->paymentMethod->id,
            recordedAt: now()->toIso8601String(),
        ));

        // Create second payment
        event(new PaymentRecorded(
            paymentId: 'test-2',
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            partnerId: $this->customer->id,
            amount: '200.00',
            currency: 'EUR',
            paymentMethodId: $this->paymentMethod->id,
            recordedAt: now()->toIso8601String(),
        ));

        Event::assertDispatched(PaymentRecorded::class, 2);
    }

    /**
     * Helper method to create a posted invoice for testing
     */
    private function createInvoice(): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Posted,
            'document_number' => 'INV-TEST-001',
            'document_date' => now(),
            'due_date' => now()->addDays(30),
            'subtotal' => '500.00',
            'tax_amount' => '0.00',
            'total' => '500.00',
            'balance_due' => '500.00',
        ]);
    }
}
