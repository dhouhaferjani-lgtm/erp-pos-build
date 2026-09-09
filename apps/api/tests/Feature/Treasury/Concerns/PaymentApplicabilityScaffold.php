<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury\Concerns;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Application\Services\OpeningBalanceBatchService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Application\DTOs\CreatePOSAccountChargeDraftCommand;
use App\Modules\Document\Application\Services\ArApOpeningService;
use App\Modules\Document\Application\Services\POSAccountChargeDraftService;
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
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * The tenant/company/partner/treasury fixture shared by the C-0a0 entry-point
 * suites. Deliberately a trait and not a base class: both suites already extend
 * `Tests\TestCase`, and the alternative is copying ninety lines of scaffolding
 * into each file where the two copies then drift.
 */
trait PaymentApplicabilityScaffold
{
    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    private Partner $vendor;

    private PaymentMethod $paymentMethod;

    private PaymentRepository $repository;

    private function bootPaymentApplicabilityFixture(string $slugPrefix): void
    {
        $this->tenant = Tenant::create([
            'name' => 'C0a0 Tenant',
            'slug' => $slugPrefix.'-'.Str::lower(Str::random(6)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'C0a0 Company',
            'legal_name' => 'C0a0 Company SARL',
            'tax_id' => 'TAX-C0A0-'.Str::upper(Str::random(5)),
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'C0a0 User',
            'email' => $slugPrefix.'-'.Str::lower(Str::random(6)).'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo([
            'payments.create',
            // F-W2-14 residual (a): the AP branch of POST /payments now carries
            // its own gate (SupplierPaymentAuthorizer). These scaffolds settle
            // supplier invoices, so the fixture actor holds it; a cashier does
            // NOT, which is the point of the permission.
            'payments.pay-supplier',
            'payments.view',
            'accounts.view',
            'accounts.manage',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CUST-C0A0',
            'name' => 'Clinique Al Amal',
            'type' => PartnerType::Customer,
        ]);

        // W4-3 gate r2 F-1: `Both`, not `Supplier`.
        //
        // This partner is given BOTH an AP opening and a native customer `Invoice`
        // (`AutoAllocationSkipsRefusedDocumentsTest::nativePostedInvoice($this->vendor, …)`),
        // so as `Supplier` it was a customer-typed document owned by a supplier-only
        // partner — the exact shape W4-3's direction guard refuses, because that
        // inference is how a payment TO a supplier came to be recorded as money
        // ARRIVING (`Dr bank / Cr 411`, repository movement IN). The guard skipped
        // the native invoice on the auto sweep and nothing was collected.
        //
        // `Both` is what a partner that genuinely holds both sides is, it is what
        // these tests mean, and it leaves every assertion here about opening
        // PROVENANCE — which is what this scaffold exists to test — untouched.
        $this->vendor = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'SUP-C0A0',
            'name' => 'Fournisseur Sahel',
            'type' => PartnerType::Both,
        ]);

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

        $this->repository = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH-'.substr((string) Str::uuid(), 0, 8),
            'name' => 'Main Cash',
            'type' => RepositoryType::CashRegister,
            'balance' => '0.000',
            'gl_account_id' => Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Bank)->id,
            'account_id' => null,
            'is_active' => true,
        ]);
    }

    private function makeDocument(
        DocumentType $type,
        DocumentStatus $status,
        string $total,
        Partner $partner,
        string $numberPrefix,
    ): Document {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'type' => $type,
            'document_number' => $numberPrefix.'-'.Str::upper(Str::random(6)),
            'document_date' => now()->toDateString(),
            'status' => $status,
            'subtotal' => $total,
            'tax_amount' => '0.000',
            'total' => $total,
            'balance_due' => $total,
            'currency' => 'TND',
        ]);
    }

    private function makeUnallocatedDeposit(string $amount, Partner $partner): Payment
    {
        return Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => $amount,
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'origin' => PaymentOrigin::WebAdmin,
            'reference' => 'Deposit for C-0a0 test',
            'notes' => 'Advance payment/deposit [UNALLOCATED]',
        ]);
    }

    /**
     * Mint a historical AR/AP opening through the REAL importer, so the markers
     * under test (`is_historical`, the batch reference prefix, and the
     * `opening_balance_import_rows.row_type` AR/AP discriminator) are the ones
     * the importer actually writes.
     */
    /**
     * @param  numeric-string  $total
     */
    private function makeHistoricalOpening(OpeningBatchType $type, Partner $partner, string $total = '100.000'): Document
    {
        $batchService = app(OpeningBalanceBatchService::class);
        $batch = $batchService->createBatch(
            $this->company,
            $type,
            now(),
            'C0A0-'.$type->value.'-'.Str::upper(Str::random(4)),
            $this->user->id,
            'phpunit',
        );
        $batchService->addImportRows($batch, [[
            'partner_code' => (string) $partner->code,
            'external_invoice_number' => 'LEG-'.Str::upper(Str::random(5)),
            'document_date' => '2026-01-01',
            'due_date' => '2026-01-31',
            'total' => $total,
            'open_amount' => $total,
            'currency' => 'TND',
            'document_type' => 'invoice',
            'notes' => null,
        ]]);

        $service = app(ArApOpeningService::class);
        $service->validateBatch($batch->refresh());
        $service->postBatch($batch->refresh(), $this->user->id);

        /** @var Document $document */
        $document = Document::query()
            ->where('company_id', $this->company->id)
            ->where('partner_id', $partner->id)
            ->where('is_historical', true)
            ->latest('created_at')
            ->firstOrFail();

        return $document;
    }

    /**
     * @param  numeric-string  $total
     */
    private function makePosAccountChargeInvoice(DocumentStatus $status, string $total = '100.000'): Document
    {
        $document = app(POSAccountChargeDraftService::class)->createDraft(
            new CreatePOSAccountChargeDraftCommand(
                tenantId: $this->tenant->id,
                companyId: $this->company->id,
                partnerId: $this->customer->id,
                fiscalEventId: (string) Str::uuid(),
                accountChargeUuid: (string) Str::uuid(),
                businessDate: now()->toDateString(),
                currencyCode: 'TND',
                currencyScale: 3,
                subtotal: $total,
                vatTotal: '0.000',
                total: $total,
                transactionDiscountAmount: '0.000',
                lineItems: [[
                    'sku' => 'SKU-1',
                    'name' => 'Consultation',
                    'quantity' => '1.0000',
                    'unit_price' => $total,
                    'line_discount_amount' => '0.000',
                    'line_subtotal' => $total,
                    'line_vat' => '0.000',
                    'vat_rate' => '0.00',
                ]],
                payloadSnapshot: ['source' => 'c0a0-test'],
                dueDate: now()->addDays(30)->toDateString(),
            ),
        );

        Document::query()->whereKey($document->id)->update([
            'status' => $status,
            'balance_due' => $total,
        ]);

        return $document->refresh();
    }

    private function makeUnallocatedPayment(string $amount, Partner $partner): Payment
    {
        return Payment::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'payment_method_id' => $this->paymentMethod->id,
            'repository_id' => $this->repository->id,
            'amount' => $amount,
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'origin' => PaymentOrigin::WebAdmin,
            'reference' => 'Unallocated payment for C-0a0 test',
        ]);
    }
}
