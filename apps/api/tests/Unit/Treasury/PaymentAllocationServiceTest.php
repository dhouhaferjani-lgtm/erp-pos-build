<?php

declare(strict_types=1);

namespace Tests\Unit\Treasury;

use App\Models\Country;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\ApplyPaymentAllocationCommand;
use App\Modules\Treasury\Application\Services\PaymentAllocationService;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class PaymentAllocationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentAllocationService $service;

    private Company $company;

    private Partner $partner;

    private Tenant $tenant;

    private PaymentMethod $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PaymentAllocationService::class);

        // Create tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        // Create country and settings
        $country = Country::create([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'currency_symbol' => 'د.ت',
        ]);

        CountryPaymentSettings::create([
            'country_code' => 'TN',
            'payment_tolerance_enabled' => true,
            'payment_tolerance_percentage' => '0.0050',
            'max_payment_tolerance_amount' => '0.100',
        ]);

        // Create company
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        // Create partner
        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Partner',
            'type' => PartnerType::Customer,
        ]);

        // Create payment method
        $this->paymentMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cash',
            'code' => 'CASH',
        ]);

        // Pin CompanyContext so PaymentAllocationService::previewAllocation
        // (Opus round-4 Finding 15 fix) and ::applyAllocation can resolve
        // tenantId via $this->companyContext->requireCompany()->tenant_id.
        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    /** @test */
    public function it_previews_fifo_allocation_for_exact_payment(): void
    {
        // Create two invoices
        $invoice1 = $this->createInvoice('INV-001', '100.0000', '2025-01-01');
        $invoice2 = $this->createInvoice('INV-002', '150.0000', '2025-01-02');

        // Payment of 250.00 (exact total)
        $preview = $this->service->previewAllocation(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            paymentAmount: '250.0000',
            allocationMethod: AllocationMethod::FIFO
        );

        $this->assertCount(2, $preview['allocations']);
        $this->assertEquals('100.0000', $preview['allocations'][0]['amount']);
        $this->assertEquals('150.0000', $preview['allocations'][1]['amount']);
        $this->assertEquals('250.0000', $preview['total_to_invoices']);
        $this->assertEquals('0.0000', $preview['excess_amount']);
        $this->assertNull($preview['excess_handling']);
    }

    /** @test */
    public function it_previews_fifo_allocation_for_partial_payment(): void
    {
        // Create two invoices
        $invoice1 = $this->createInvoice('INV-001', '100.0000', '2025-01-01');
        $invoice2 = $this->createInvoice('INV-002', '150.0000', '2025-01-02');

        // Payment of 120.00 (partial - covers first invoice + part of second)
        $preview = $this->service->previewAllocation(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            paymentAmount: '120.0000',
            allocationMethod: AllocationMethod::FIFO
        );

        $this->assertCount(2, $preview['allocations']);
        $this->assertEquals('100.0000', $preview['allocations'][0]['amount']);
        $this->assertEquals('20.0000', $preview['allocations'][1]['amount']);
        $this->assertEquals('120.0000', $preview['total_to_invoices']);
        $this->assertEquals('0.0000', $preview['excess_amount']);
    }

    /** @test */
    public function it_previews_due_date_allocation(): void
    {
        // Create invoices with different due dates
        $invoice1 = $this->createInvoice('INV-001', '150.0000', '2025-01-10'); // Due later
        $invoice2 = $this->createInvoice('INV-002', '100.0000', '2025-01-05'); // Due earlier

        // Payment of 120.00 - should prioritize invoice2 (earlier due date)
        $preview = $this->service->previewAllocation(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            paymentAmount: '120.0000',
            allocationMethod: AllocationMethod::DUE_DATE_PRIORITY
        );

        $this->assertCount(2, $preview['allocations']);
        // Should allocate to invoice2 first (earlier due date)
        $this->assertEquals($invoice2->id, $preview['allocations'][0]['document_id']);
        $this->assertEquals('100.0000', $preview['allocations'][0]['amount']);
        $this->assertEquals($invoice1->id, $preview['allocations'][1]['document_id']);
        $this->assertEquals('20.0000', $preview['allocations'][1]['amount']);
    }

    /** @test */
    public function it_handles_overpayment_within_tolerance(): void
    {
        // Invoice: 100.00, Payment: 100.05 (0.05 overpayment within tolerance)
        $invoice = $this->createInvoice('INV-001', '100.0000', '2025-01-01');

        $preview = $this->service->previewAllocation(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            paymentAmount: '100.0500',
            allocationMethod: AllocationMethod::FIFO
        );

        $this->assertCount(1, $preview['allocations']);
        $this->assertEquals('100.0000', $preview['allocations'][0]['amount']);
        $this->assertEquals('0.0500', $preview['allocations'][0]['tolerance_writeoff']);
        $this->assertEquals('100.0500', $preview['total_to_invoices']);
        $this->assertEquals('0.0000', $preview['excess_amount']);
        $this->assertEquals('tolerance_writeoff', $preview['excess_handling']);
    }

    /** @test */
    public function it_handles_underpayment_within_tolerance(): void
    {
        // Invoice: 100.00, Payment: 99.95 (0.05 underpayment within tolerance)
        $invoice = $this->createInvoice('INV-001', '100.0000', '2025-01-01');

        $preview = $this->service->previewAllocation(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            paymentAmount: '99.9500',
            allocationMethod: AllocationMethod::FIFO
        );

        $this->assertCount(1, $preview['allocations']);
        $this->assertEquals('99.9500', $preview['allocations'][0]['amount']);
        $this->assertEquals('0.0500', $preview['allocations'][0]['tolerance_writeoff']);
        $this->assertEquals('100.0000', $preview['total_to_invoices']);
        $this->assertEquals('0.0000', $preview['excess_amount']);
        $this->assertEquals('tolerance_writeoff', $preview['excess_handling']);
    }

    /** @test */
    public function it_creates_credit_balance_for_excess_payment(): void
    {
        // Invoice: 100.00, Payment: 150.00 (50.00 excess)
        $invoice = $this->createInvoice('INV-001', '100.0000', '2025-01-01');

        $preview = $this->service->previewAllocation(
            companyId: $this->company->id,
            partnerId: $this->partner->id,
            paymentAmount: '150.0000',
            allocationMethod: AllocationMethod::FIFO
        );

        $this->assertCount(1, $preview['allocations']);
        $this->assertEquals('100.0000', $preview['allocations'][0]['amount']);
        $this->assertEquals('100.0000', $preview['total_to_invoices']);
        $this->assertEquals('50.0000', $preview['excess_amount']);
        $this->assertEquals('credit_balance', $preview['excess_handling']);
    }

    /** @test */
    public function it_applies_allocation_and_creates_records(): void
    {
        $invoice = $this->createInvoice('INV-001', '100.0000', '2025-01-01');

        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => '100.0000',
            'currency' => 'TND',
            'payment_date' => '2025-01-15',
            'status' => 'completed',
            'payment_type' => 'document_payment',
        ]);

        $result = $this->service->applyAllocation(
            paymentId: $payment->id,
            allocationMethod: AllocationMethod::FIFO
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['allocations']);

        // Verify allocation was created in database
        $payment->refresh();
        $this->assertCount(1, $payment->allocations);
        $allocation = $payment->allocations()->firstOrFail();
        $this->assertEquals('100.0000', $allocation->amount);
        $this->assertEquals($invoice->id, $allocation->document_id);
    }

    /** @test */
    public function it_applies_allocation_from_command_uses_explicit_tenant_company_actor(): void
    {
        $invoice = $this->createInvoice('INV-001', '100.0000', '2025-01-01');

        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => '100.0000',
            'currency' => 'TND',
            'payment_date' => '2025-01-15',
            'status' => 'completed',
            'payment_type' => 'document_payment',
        ]);

        $result = $this->service->applyAllocationFromCommand(new ApplyPaymentAllocationCommand(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            paymentId: $payment->id,
            allocationMethod: AllocationMethod::FIFO,
            actorUserId: null,
            source: 'fiscal_event:ACCOUNT_PAYMENT',
        ));

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['allocations']);
        $this->assertEquals($invoice->id, $result['allocations'][0]['document_id']);
        $this->assertEquals('100.0000', $result['allocations'][0]['amount']);
    }

    /** @test */
    public function it_apply_allocation_from_command_rejects_cross_company_payment(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
        ]);
        $otherCompany = Company::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);
        $otherPartner = Partner::create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'name' => 'Other Partner',
            'type' => PartnerType::Customer,
        ]);
        $otherPayment = Payment::create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'partner_id' => $otherPartner->id,
            'amount' => '100.0000',
            'currency' => 'TND',
            'payment_date' => '2025-01-15',
            'status' => 'completed',
            'payment_type' => 'document_payment',
        ]);

        $this->expectException(ModelNotFoundException::class);

        $this->service->applyAllocationFromCommand(new ApplyPaymentAllocationCommand(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            paymentId: $otherPayment->id,
            allocationMethod: AllocationMethod::FIFO,
            actorUserId: null,
            source: 'fiscal_event:ACCOUNT_PAYMENT',
        ));
    }

    /** @test */
    public function it_does_not_use_auth_user_in_payment_allocation_service(): void
    {
        $source = file_get_contents(app_path('Modules/Treasury/Application/Services/PaymentAllocationService.php'));

        $this->assertNotFalse($source);
        $this->assertStringNotContainsString('Auth::user(', $source);
    }

    /** @test */
    public function it_resolves_command_actor_only_when_user_has_active_company_membership(): void
    {
        $actor = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        UserCompanyMembership::create([
            'user_id' => $actor->id,
            'company_id' => $otherCompany->id,
            'role' => MembershipRole::Cashier,
            'status' => MembershipStatus::Active,
        ]);

        $unscopedCommand = new ApplyPaymentAllocationCommand(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            paymentId: 'payment-id',
            allocationMethod: AllocationMethod::FIFO,
            actorUserId: $actor->id,
            source: 'fiscal_event:ACCOUNT_PAYMENT',
        );

        $this->assertNull($this->resolveCommandActorForTest($unscopedCommand));

        UserCompanyMembership::create([
            'user_id' => $actor->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Cashier,
            'status' => MembershipStatus::Active,
        ]);

        $scopedCommand = new ApplyPaymentAllocationCommand(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            paymentId: 'payment-id',
            allocationMethod: AllocationMethod::FIFO,
            actorUserId: $actor->id,
            source: 'fiscal_event:ACCOUNT_PAYMENT',
        );
        $resolved = $this->resolveCommandActorForTest($scopedCommand);

        $this->assertInstanceOf(User::class, $resolved);
        $this->assertSame($actor->id, $resolved->id);
    }

    private function createInvoice(string $number, string $total, string $dueDate): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => 'posted',
            'document_number' => $number,
            'document_date' => now(),
            'due_date' => $dueDate,
            'subtotal' => $total,
            'tax_amount' => '0.0000',
            'total' => $total,
            'currency' => 'TND',
        ]);
    }

    private function resolveCommandActorForTest(ApplyPaymentAllocationCommand $command): ?User
    {
        $method = new ReflectionMethod(PaymentAllocationService::class, 'resolveCommandActor');

        $result = $method->invoke($this->service, $command);

        return $result instanceof User ? $result : null;
    }
}
