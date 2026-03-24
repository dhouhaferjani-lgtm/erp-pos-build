<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\Services\PaymentRefundService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * PaymentRefundTest - Regression tests for payment refund workflows
 *
 * Tests covered:
 * - Full payment refund reverses allocation
 * - Partial refund maintains remaining balance
 * - Refund creates negative payment record
 * - Cannot refund more than original amount
 * - Cannot refund already reversed payment
 * - Get refund history for a payment
 * - Reverse payment deletes allocations
 */
class PaymentRefundTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private PaymentMethod $cashMethod;

    private Partner $customer;

    private Document $invoice;

    private PaymentRefundService $refundService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['payments.view', 'payments.create', 'payments.allocate']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->cashMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
            'is_physical' => false,
            'is_active' => true,
        ]);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'ACME Corporation',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        $this->invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-2025-0001',
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '1000.00',
            'tax_amount' => '190.00',
            'total' => '1190.00',
            'balance_due' => '1190.00',
            'currency' => 'EUR',
        ]);

        $this->refundService = app(PaymentRefundService::class);
    }

    /**
     * Helper to create a payment with allocation
     */
    private function createPaymentWithAllocation(string $amount): Payment
    {
        $payment = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'payment_method_id' => $this->cashMethod->id,
            'amount' => $amount,
            'currency' => 'EUR',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'reference' => 'PMT-'.uniqid(),
            'created_by' => $this->user->id,
        ]);

        PaymentAllocation::create([
            'id' => Str::uuid()->toString(),
            'payment_id' => $payment->id,
            'document_id' => $this->invoice->id,
            'amount' => $amount,
        ]);

        return $payment;
    }

    public function test_full_payment_refund_creates_negative_payment(): void
    {
        $payment = $this->createPaymentWithAllocation('500.00');

        $refund = $this->refundService->refundPayment(
            $payment,
            'Customer requested refund',
            $this->user->id
        );

        // Refund should be a negative amount
        $this->assertEquals('-500.000', $refund->amount);
        $this->assertEquals(PaymentStatus::Completed, $refund->status);
        $this->assertStringContainsString('Refund for payment', $refund->reference);
        $this->assertStringContainsString('Refund: Customer requested refund', $refund->notes);

        // Original payment should be marked as reversed
        $payment->refresh();
        $this->assertEquals(PaymentStatus::Reversed, $payment->status);
    }

    public function test_full_refund_reverses_allocation(): void
    {
        $payment = $this->createPaymentWithAllocation('500.00');

        $refund = $this->refundService->refundPayment(
            $payment,
            'Reversal',
            $this->user->id
        );

        // Refund should have a negative allocation matching original
        $refundAllocations = $refund->allocations;
        $this->assertCount(1, $refundAllocations);
        $this->assertEquals('-500.0000', $refundAllocations->first()->amount);
        $this->assertEquals($this->invoice->id, $refundAllocations->first()->document_id);
    }

    public function test_partial_refund_maintains_remaining_balance(): void
    {
        $payment = $this->createPaymentWithAllocation('1000.00');

        $refund = $this->refundService->partialRefund(
            $payment,
            '300.00',
            'Partial product return',
            $this->user->id
        );

        // Refund should be negative partial amount
        $this->assertEquals('-300.000', $refund->amount);
        $this->assertEquals(PaymentStatus::Completed, $refund->status);

        // Original payment should NOT be marked as reversed (partial refund)
        $payment->refresh();
        $this->assertEquals(PaymentStatus::Completed, $payment->status);
        $this->assertStringContainsString('Partial refund of 300.00', $payment->notes);
    }

    public function test_cannot_refund_more_than_original_amount(): void
    {
        $payment = $this->createPaymentWithAllocation('500.00');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Refund amount cannot exceed original payment amount');

        $this->refundService->partialRefund(
            $payment,
            '600.00',
            'Excessive refund attempt',
            $this->user->id
        );
    }

    public function test_refund_is_idempotent_for_reversed_payment(): void
    {
        $payment = $this->createPaymentWithAllocation('500.00');

        // First refund succeeds
        $firstRefund = $this->refundService->refundPayment($payment, 'First refund', $this->user->id);

        // Second attempt should be idempotent - return the same refund
        $payment->refresh();
        $secondRefund = $this->refundService->refundPayment($payment, 'Second refund attempt', $this->user->id);

        // Should return the same refund payment
        $this->assertEquals($firstRefund->id, $secondRefund->id);
        $this->assertEquals('-500.000', $secondRefund->amount);
    }

    public function test_partial_refund_amount_must_be_positive(): void
    {
        $payment = $this->createPaymentWithAllocation('500.00');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Refund amount must be greater than zero');

        $this->refundService->partialRefund(
            $payment,
            '0.00',
            'Zero refund',
            $this->user->id
        );
    }

    public function test_can_refund_check(): void
    {
        $completedPayment = $this->createPaymentWithAllocation('500.00');

        $this->assertTrue($this->refundService->canRefund($completedPayment));

        // Refund it
        $this->refundService->refundPayment($completedPayment, 'Test', $this->user->id);
        $completedPayment->refresh();

        // Now it should not be refundable
        $this->assertFalse($this->refundService->canRefund($completedPayment));
    }

    public function test_get_refund_history(): void
    {
        $payment = $this->createPaymentWithAllocation('1000.00');

        // Make two partial refunds
        $this->refundService->partialRefund($payment, '200.00', 'First return', $this->user->id);
        $this->refundService->partialRefund($payment, '300.00', 'Second return', $this->user->id);

        $history = $this->refundService->getRefundHistory($payment);

        $this->assertEquals('1000.000', $history['original_amount']);
        $this->assertEquals('500.00', $history['total_refunded']);
        $this->assertEquals('500.00', $history['remaining_amount']);
        $this->assertFalse($history['is_fully_refunded']);
        $this->assertEquals(2, $history['refund_count']);
    }

    public function test_refund_history_shows_fully_refunded(): void
    {
        $payment = $this->createPaymentWithAllocation('500.00');

        // Full refund
        $this->refundService->refundPayment($payment, 'Full refund', $this->user->id);

        $history = $this->refundService->getRefundHistory($payment);

        $this->assertEquals('500.00', $history['total_refunded']);
        $this->assertEquals('0.00', $history['remaining_amount']);
        $this->assertTrue($history['is_fully_refunded']);
    }

    public function test_reverse_payment_deletes_allocations(): void
    {
        $payment = $this->createPaymentWithAllocation('500.00');

        // Verify allocation exists
        $this->assertCount(1, $payment->allocations);

        // Reverse the payment
        $this->refundService->reversePayment($payment, 'Data entry error', $this->user->id);

        // Payment should be reversed
        $payment->refresh();
        $this->assertEquals(PaymentStatus::Reversed, $payment->status);
        $this->assertStringContainsString('Reversed: Data entry error', $payment->notes);

        // Allocations should be deleted
        $this->assertCount(0, PaymentAllocation::where('payment_id', $payment->id)->get());
    }

    public function test_reverse_payment_is_idempotent(): void
    {
        $payment = $this->createPaymentWithAllocation('500.00');

        // First reversal succeeds
        $this->refundService->reversePayment($payment, 'First reversal', $this->user->id);

        $payment->refresh();
        $this->assertEquals(PaymentStatus::Reversed, $payment->status);

        // Second attempt should be idempotent - just return without error
        $this->refundService->reversePayment($payment, 'Second attempt', $this->user->id);

        // Payment should still be reversed (no change)
        $payment->refresh();
        $this->assertEquals(PaymentStatus::Reversed, $payment->status);
    }

    public function test_multiple_partial_refunds_accumulate(): void
    {
        $payment = $this->createPaymentWithAllocation('1000.00');

        // Multiple small refunds
        $this->refundService->partialRefund($payment, '100.00', 'Refund 1', $this->user->id);
        $this->refundService->partialRefund($payment, '150.00', 'Refund 2', $this->user->id);
        $this->refundService->partialRefund($payment, '200.00', 'Refund 3', $this->user->id);

        $history = $this->refundService->getRefundHistory($payment);

        $this->assertEquals('450.00', $history['total_refunded']);
        $this->assertEquals('550.00', $history['remaining_amount']);
        $this->assertEquals(3, $history['refund_count']);
    }
}
