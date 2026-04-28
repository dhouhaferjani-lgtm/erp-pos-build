<?php

declare(strict_types=1);

namespace Tests\Unit\Treasury;

use App\Models\Country;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\PaymentAllocationService;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PaymentAllocationServiceTolerancePersistenceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    private PaymentMethod $paymentMethod;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-tol',
            'domain' => 'test-tol',
        ]);

        Country::create([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'currency_symbol' => 'د.ت',
        ]);

        CountryPaymentSettings::create([
            'country_code' => 'TN',
            'payment_tolerance_enabled' => true,
            'payment_tolerance_percentage' => '0.0050',
            'max_payment_tolerance_amount' => '0.500',
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Partner',
            'type' => PartnerType::Customer,
        ]);

        $this->paymentMethod = PaymentMethod::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cash',
            'code' => 'CASH',
        ]);

        // GL accounts required by PaymentToleranceService::applyTolerance
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '411',
            'name' => 'Customers',
            'type' => 'asset',
            'system_purpose' => SystemAccountPurpose::CustomerReceivable,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '658',
            'name' => 'Payment Tolerance Expense',
            'type' => 'expense',
            'system_purpose' => SystemAccountPurpose::PaymentToleranceExpense,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '758',
            'name' => 'Payment Tolerance Income',
            'type' => 'revenue',
            'system_purpose' => SystemAccountPurpose::PaymentToleranceIncome,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function tolerance_writeoff_column_is_persisted_on_allocation_row_for_underpayment(): void
    {
        // Invoice 100.0000, payment 99.9500 — 0.05 underpayment within FIFO tolerance.
        $invoice = $this->createInvoice('INV-TOL-001', '100.0000');

        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'amount' => '99.9500',
            'currency' => 'TND',
            'payment_date' => '2025-01-15',
            'status' => 'completed',
            'payment_type' => 'document_payment',
        ]);

        $service = $this->app->make(PaymentAllocationService::class);

        $result = $service->applyAllocation(
            paymentId: $payment->id,
            allocationMethod: AllocationMethod::FIFO,
        );

        $this->assertTrue($result['success']);

        $allocation = PaymentAllocation::where('payment_id', $payment->id)->firstOrFail();

        $this->assertSame($invoice->id, $allocation->document_id);
        $this->assertSame('99.9500', $allocation->amount);
        // tolerance_writeoff column is decimal(15,4) — assert non-null and equal to 0.05.
        $this->assertNotNull($allocation->tolerance_writeoff);
        $this->assertSame(0, bccomp((string) $allocation->tolerance_writeoff, '0.0500', 4));
    }

    private function createInvoice(string $number, string $total): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => 'posted',
            'document_number' => $number,
            'document_date' => now(),
            'due_date' => now()->addDays(30),
            'subtotal' => $total,
            'tax_amount' => '0.0000',
            'total' => $total,
            'currency' => 'TND',
        ]);
    }
}
