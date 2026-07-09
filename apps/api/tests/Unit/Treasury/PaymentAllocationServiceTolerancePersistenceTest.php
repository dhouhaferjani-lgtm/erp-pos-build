<?php

declare(strict_types=1);

namespace Tests\Unit\Treasury;

use App\Models\Country;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
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

    private User $user;

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

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Allocation User',
            'email' => 'allocation@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Accountant,
            'status' => MembershipStatus::Active,
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

        // Pin CompanyContext so PaymentAllocationService::previewAllocation
        // (Opus round-4 Finding 15 fix) and ::applyAllocation can resolve
        // tenantId via $this->companyContext->requireCompany()->tenant_id.
        app(CompanyContext::class)->setCompanyId($this->company->id);
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
            // Branch-caused red (audit N2, 2026-07-09): the closed-period guard
            // (GeneralLedgerService::postEntryNow, Wave B Task 8) rejects the
            // tolerance journal entry this test's underpayment triggers when
            // dated into a backdated, now-closed period. Use the current date
            // (same pattern as createInvoice()'s document_date => now()) so the
            // payment falls in the always-open current fiscal period.
            'payment_date' => now()->toDateString(),
            'status' => 'completed',
            'payment_type' => 'document_payment',
        ]);

        $service = $this->app->make(PaymentAllocationService::class);

        $this->actingAs($this->user);

        $result = $service->applyAllocation(
            paymentId: $payment->id,
            allocationMethod: AllocationMethod::FIFO,
        );

        $this->assertTrue($result['success']);

        $allocation = PaymentAllocation::where('payment_id', $payment->id)->firstOrFail();

        $this->assertSame($invoice->id, $allocation->document_id);
        $this->assertSame('99.950', $allocation->amount);
        // tolerance_writeoff column is decimal(15,4) — assert non-null and equal to 0.05.
        $this->assertNotNull($allocation->tolerance_writeoff);
        $this->assertSame(0, bccomp((string) $allocation->tolerance_writeoff, '0.0500', 4));

        $entry = JournalEntry::query()
            ->where('source_type', 'payment_tolerance')
            ->where('source_id', $invoice->id)
            ->firstOrFail();
        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertSame($this->user->id, $entry->posted_by);
        $this->assertNotNull($entry->posted_at);
        $this->assertNotNull($entry->fiscal_hash);
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
