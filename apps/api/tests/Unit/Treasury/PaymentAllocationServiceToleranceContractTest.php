<?php

declare(strict_types=1);

namespace Tests\Unit\Treasury;

use App\Models\Country;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Services\PaymentAllocationService;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Shared\Contracts\Treasury\DTOs\ToleranceCheckResult;
use App\Shared\Contracts\Treasury\Enums\ToleranceType;
use App\Shared\Contracts\Treasury\PaymentToleranceCheckerContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contract-binding regression for the PR #47 follow-up consumer migration.
 *
 * PaymentAllocationService historically called the deprecated company-keyed
 * PaymentToleranceService::checkTolerance(...). After migration it must
 * delegate qualification to the typed, country/currency-keyed
 * PaymentToleranceCheckerContract::check(...) — the same surface A1 (POS
 * receipt close) and A2 (B2B close-with-writeoff) already depend on.
 *
 * The spy below is bound to the container so that resolving
 * PaymentAllocationService via the IoC injects it, then
 * applyAllocation() must route the qualifier through the contract — not
 * the deprecated path. The test fails pre-migration (the service does not
 * depend on the contract, so the spy is never invoked).
 */
final class PaymentAllocationServiceToleranceContractTest extends TestCase
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
            'slug' => 'tenant-tol-contract',
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

        // GL accounts required by PaymentToleranceService::applyTolerance, which
        // remains the GL-posting surface for write-offs even after the qualifier
        // migrates to the contract.
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

        // Pin CompanyContext so PaymentAllocationService::previewAllocation
        // (Opus round-4 Finding 15 fix) and ::applyAllocation can resolve
        // tenantId via $this->companyContext->requireCompany()->tenant_id.
        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_payment_allocation_invokes_tolerance_contract_with_country_and_currency(): void
    {
        // Invoice 100.0000 TND, payment 99.9500 — 0.05 underpayment within FIFO tolerance.
        $invoice = $this->createInvoice('INV-CTR-001', '100.0000');

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

        $spy = new ToleranceCheckerAllocationSpy(
            new ToleranceCheckResult(
                qualifies: true,
                difference: '0.0500',
                type: ToleranceType::Underpayment,
                reason: null,
            ),
        );

        // Bind the spy as the contract implementation so the container injects
        // it into PaymentAllocationService when the service is resolved.
        $this->app->instance(PaymentToleranceCheckerContract::class, $spy);

        $service = $this->app->make(PaymentAllocationService::class);

        $service->applyAllocation(
            paymentId: $payment->id,
            allocationMethod: AllocationMethod::FIFO,
        );

        $this->assertGreaterThanOrEqual(
            1,
            count($spy->calls),
            'PaymentAllocationService must invoke PaymentToleranceCheckerContract at least once.',
        );

        $call = $spy->calls[0];
        $this->assertFalse(
            $call['strict'],
            'PaymentAllocationService rides the inclusive A1 / SmartPayment path (strict=false).',
        );
        $this->assertSame('TN', $call['countryCode'], 'Country must be resolved from the company.');
        $this->assertSame('TND', $call['currencyCode'], 'Currency must be resolved from the invoice.');
        $this->assertSame('100.0000', $call['invoiceTotal']);
        $this->assertSame('0.0500', $call['shortfall']);
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

/**
 * Inline spy for PaymentToleranceCheckerContract — captures invocation args
 * so the test can assert PaymentAllocationService passes country/currency
 * derived from the company + invoice and rides the strict=false path.
 *
 * Pattern mirrors ToleranceCheckerCloseSpy in CloseInvoiceWithToleranceServiceTest.
 */
final class ToleranceCheckerAllocationSpy implements PaymentToleranceCheckerContract
{
    /** @var list<array{shortfall:string,invoiceTotal:string,currencyCode:string,countryCode:string,strict:bool}> */
    public array $calls = [];

    public function __construct(private readonly ToleranceCheckResult $result) {}

    public function check(
        string $shortfall,
        string $invoiceTotal,
        string $currencyCode,
        string $countryCode,
        bool $strict = false,
    ): ToleranceCheckResult {
        $this->calls[] = [
            'shortfall' => $shortfall,
            'invoiceTotal' => $invoiceTotal,
            'currencyCode' => $currencyCode,
            'countryCode' => $countryCode,
            'strict' => $strict,
        ];

        return $this->result;
    }
}
