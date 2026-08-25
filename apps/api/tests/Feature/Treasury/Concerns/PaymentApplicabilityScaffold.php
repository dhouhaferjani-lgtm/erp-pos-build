<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury\Concerns;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
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

        $this->vendor = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'SUP-C0A0',
            'name' => 'Fournisseur Sahel',
            'type' => PartnerType::Supplier,
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
