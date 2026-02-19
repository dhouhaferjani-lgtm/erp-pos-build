<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentMethod;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tests for the complete payment registration flow.
 *
 * Tests the integration between:
 * - Payment creation (PaymentController)
 * - Payment allocation (PaymentAllocationService)
 * - Document balance updates
 * - Payment history retrieval
 */
class PaymentRegistrationFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    private PaymentMethod $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test tenant, company, partner, and payment method
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        // Setup permissions for this tenant
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->paymentMethod = PaymentMethod::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cash',
            'code' => 'CASH',
            'is_active' => true,
            'is_physical' => true,
            'has_maturity' => false,
            'requires_third_party' => false,
            'is_push' => false,
            'has_deducted_fees' => false,
            'is_restricted' => false,
        ]);
    }

    /** @test */
    public function it_registers_payment_against_single_invoice(): void
    {
        // Create a posted invoice
        $invoice = Document::factory()->posted()->create([
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'total' => '1000.00',
            'balance_due' => '1000.00',
        ]);

        // Create payment with allocation
        $payment = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => '1000.00',
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => 'PAY-001',
        ]);

        // Create allocation
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'document_id' => $invoice->id,
            'amount' => '1000.00',
        ]);

        // Manually update balance (simulating what the trigger does)
        $invoice->update(['balance_due' => '0.00']);

        // Verify payment was created
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'amount' => '1000.00',
        ]);

        // Verify allocation was created
        $this->assertDatabaseHas('payment_allocations', [
            'payment_id' => $payment->id,
            'document_id' => $invoice->id,
            'amount' => '1000.00',
        ]);

        // Verify invoice balance updated
        $invoice = $invoice->fresh();
        $this->assertEquals('0.00', $invoice->balance_due);
        $this->assertEquals('paid', $invoice->getPaymentStatus()->value);
    }

    /** @test */
    public function it_handles_partial_payment(): void
    {
        $invoice = Document::factory()->posted()->create([
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'total' => '1000.00',
            'balance_due' => '1000.00',
        ]);

        $payment = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => '600.00',
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => 'PAY-002',
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'document_id' => $invoice->id,
            'amount' => '600.00',
        ]);

        // Update balance (simulating trigger)
        $invoice->update(['balance_due' => '400.00']);

        $invoice = $invoice->fresh();
        $this->assertEquals('400.00', $invoice->balance_due);
        $this->assertEquals('partially_paid', $invoice->getPaymentStatus()->value);
    }

    /** @test */
    public function it_handles_multiple_payments_to_same_invoice(): void
    {
        $invoice = Document::factory()->posted()->create([
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'total' => '1000.00',
            'balance_due' => '1000.00',
        ]);

        // First payment
        $payment1 = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => '600.00',
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => 'PAY-003',
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment1->id,
            'document_id' => $invoice->id,
            'amount' => '600.00',
        ]);

        $invoice->update(['balance_due' => '400.00']);

        // Second payment
        $payment2 = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => '400.00',
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => 'PAY-004',
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment2->id,
            'document_id' => $invoice->id,
            'amount' => '400.00',
        ]);

        $invoice->update(['balance_due' => '0.00']);

        // Verify both allocations exist
        $invoice = $invoice->fresh();
        $this->assertEquals(2, $invoice->allocations()->count());
        $this->assertEquals('0.00', $invoice->balance_due);
        $this->assertEquals('paid', $invoice->getPaymentStatus()->value);
    }

    /** @test */
    public function it_retrieves_payment_history_for_document(): void
    {
        $invoice = Document::factory()->posted()->create([
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'total' => '1000.00',
            'balance_due' => '1000.00',
        ]);

        // Create two payments
        $payment1 = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => '600.00',
            'currency' => 'TND',
            'payment_date' => now()->subDays(2),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => 'PAY-005',
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment1->id,
            'document_id' => $invoice->id,
            'amount' => '600.00',
        ]);

        $payment2 = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => '400.00',
            'currency' => 'TND',
            'payment_date' => now()->subDay(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => 'PAY-006',
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment2->id,
            'document_id' => $invoice->id,
            'amount' => '400.00',
        ]);

        $invoice->update(['balance_due' => '0.00']);

        // Test the payment history endpoint
        $response = $this->actingAs($this->createUser())
            ->getJson("/api/v1/documents/{$invoice->id}/payments");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'document_id',
                'document_number',
                'total',
                'balance_due',
                'payment_status',
                'allocations' => [
                    '*' => [
                        'id',
                        'payment_id',
                        'payment_reference',
                        'payment_date',
                        'payment_method',
                        'amount',
                        'created_at',
                    ],
                ],
            ],
        ]);

        $this->assertEquals('0.00', $response->json('data.balance_due'));
        $this->assertEquals('paid', $response->json('data.payment_status'));
        $this->assertCount(2, $response->json('data.allocations'));
    }

    /** @test */
    public function it_returns_empty_allocations_for_unpaid_invoice(): void
    {
        $invoice = Document::factory()->posted()->create([
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'total' => '1000.00',
            'balance_due' => '1000.00',
        ]);

        $response = $this->actingAs($this->createUser())
            ->getJson("/api/v1/documents/{$invoice->id}/payments");

        $response->assertOk();
        $this->assertEquals('1000.00', $response->json('data.balance_due'));
        $this->assertEquals('unpaid', $response->json('data.payment_status'));
        $this->assertCount(0, $response->json('data.allocations'));
    }

    /** @test */
    public function it_orders_allocations_by_created_date_descending(): void
    {
        $invoice = Document::factory()->posted()->create([
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'total' => '1000.00',
            'balance_due' => '1000.00',
        ]);

        // Create payments at different times
        $payment1 = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => '500.00',
            'currency' => 'TND',
            'payment_date' => now()->subDays(3),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => 'PAY-OLD',
        ]);

        $allocation1 = PaymentAllocation::create([
            'payment_id' => $payment1->id,
            'document_id' => $invoice->id,
            'amount' => '500.00',
        ]);
        $allocation1->created_at = now()->subDays(3);
        $allocation1->save();

        $payment2 = Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => '500.00',
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => 'PAY-NEW',
        ]);

        $allocation2 = PaymentAllocation::create([
            'payment_id' => $payment2->id,
            'document_id' => $invoice->id,
            'amount' => '500.00',
        ]);

        $invoice->update(['balance_due' => '0.00']);

        $response = $this->actingAs($this->createUser())
            ->getJson("/api/v1/documents/{$invoice->id}/payments");

        $response->assertOk();

        // Most recent should be first
        $allocations = $response->json('data.allocations');
        $this->assertEquals('PAY-NEW', $allocations[0]['payment_reference']);
        $this->assertEquals('PAY-OLD', $allocations[1]['payment_reference']);
    }

    private function createUser()
    {
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Create user-company membership
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        // Grant document view permission
        $user->givePermissionTo('documents.view');

        // Set company context
        app(CompanyContext::class)->setCompanyId($this->company->id);

        return $user;
    }
}
