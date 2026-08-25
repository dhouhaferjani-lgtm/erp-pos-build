<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Accounting\Application\Services\OpeningBalanceBatchService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\OpeningBatchStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\OpeningImportRowStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Application\Services\ArApOpeningService;
use App\Modules\Document\Domain\Document;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ArApOpeningPostLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private User $unauthorizedUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-ob-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX-OB-123',
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
            'name' => 'OB Admin',
            'email' => 'ob-admin@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['accounts.view', 'accounts.manage']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->unauthorizedUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'No Perms User',
            'email' => 'no-perms@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->unauthorizedUser->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        // Create chart of accounts needed for GL opening balance
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '5100',
            'name' => 'Cash',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Cash,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '5120',
            'name' => 'Bank Account',
            'type' => AccountType::Asset,
            'is_active' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '3900',
            'name' => 'Opening Balance Equity',
            'type' => AccountType::Equity,
            'system_purpose' => SystemAccountPurpose::OpeningBalanceEquity,
            'is_active' => true,
            'is_system' => true,
        ]);

        // W4-4: an AR/AP opening now posts its own cutover entry against the
        // partner control account, so the fixture must carry the two control
        // purposes. On a real tenant these are seeded by provisioning
        // (ProvisioningRequiredPurposesV1); when they are genuinely absent
        // `Account::findByPurposeOrFail()` fails the whole batch closed with an
        // operator-facing message rather than posting a partial opening.
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '4110',
            'name' => 'Customer Receivable',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::CustomerReceivable,
            'is_active' => true,
            'is_system' => true,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '4010',
            'name' => 'Supplier Payable',
            'type' => AccountType::Liability,
            'system_purpose' => SystemAccountPurpose::SupplierPayable,
            'is_active' => true,
            'is_system' => true,
        ]);
    }

    public function test_post_batch_completes_and_marks_rows_posted(): void
    {
        $company = $this->company;
        $this->createCustomerPartner('CUST-001');

        $service = app(ArApOpeningService::class);
        $batch = $this->makeArBatch([[
            'partner_code' => 'CUST-001',
            'external_invoice_number' => 'LEG-1',
            'document_date' => '2026-01-01',
            'due_date' => '2026-01-01',
            'total' => '100.000',
            'open_amount' => '100.000',
            'currency' => $company->currency,
            'document_type' => 'invoice',
            'notes' => null,
        ]]);

        $service->validateBatch($batch->refresh());

        $result = $service->postBatch($batch->refresh(), $this->user->id);

        $this->assertSame(1, $result['documents_created']);
        $this->assertSame(OpeningBatchStatus::Validated, $batch->refresh()->status);
        $this->assertSame(1, $batch->rows()->where('status', OpeningImportRowStatus::Posted)->count());
        $this->assertSame(1, Document::where('company_id', $company->id)->where('is_historical', true)->count());
    }

    public function test_missing_currency_defaults_to_company_currency_and_foreign_currency_is_rejected(): void
    {
        $company = $this->company;
        $this->createCustomerPartner('CUST-CURRENCY');
        $this->createSupplierPartner('SUP-CURRENCY');

        $service = app(ArApOpeningService::class);

        $postBatch = $this->makeArBatch([[
            'partner_code' => 'CUST-CURRENCY',
            'external_invoice_number' => 'LEG-CURRENCY-1',
            'document_date' => '2026-01-01',
            'due_date' => '2026-01-01',
            'total' => '100.000',
            'open_amount' => '100.000',
            'document_type' => 'invoice',
            'notes' => null,
        ]], 'TEST-AR-CURRENCY-POST');

        $service->validateBatch($postBatch->refresh());
        $service->postBatch($postBatch->refresh(), $this->user->id);

        $document = Document::where('company_id', $company->id)
            ->where('is_historical', true)
            ->firstOrFail();

        $this->assertSame($company->currency, $document->currency);

        $validationBatch = $this->makeApBatch([
            [
                'partner_code' => 'SUP-CURRENCY',
                'external_invoice_number' => 'LEG-CURRENCY-2',
                'document_date' => '2026-01-01',
                'due_date' => '2026-01-01',
                'total' => '100.000',
                'open_amount' => '100.000',
                'document_type' => 'invoice',
                'notes' => null,
            ],
            [
                'partner_code' => 'SUP-CURRENCY',
                'external_invoice_number' => 'LEG-CURRENCY-3',
                'document_date' => '2026-01-01',
                'due_date' => '2026-01-01',
                'total' => '100.000',
                'open_amount' => '100.000',
                'currency' => 'USD',
                'document_type' => 'invoice',
                'notes' => null,
            ],
        ], 'TEST-AR-CURRENCY-VALIDATE');

        $service->validateBatch($validationBatch->refresh());

        $validRow = $validationBatch->rows()->where('row_number', 1)->firstOrFail();
        $invalidRow = $validationBatch->rows()->where('row_number', 2)->firstOrFail();

        $this->assertSame(OpeningImportRowStatus::Valid, $validRow->status);
        $this->assertSame(OpeningImportRowStatus::Invalid, $invalidRow->status);
        $this->assertSame(
            "Currency must match company currency ({$company->currency}).",
            $invalidRow->validation_errors['currency'][0] ?? null
        );
    }

    private function createCustomerPartner(string $code): Partner
    {
        return Partner::factory()->create([
            'tenant_id' => $this->company->tenant_id,
            'company_id' => $this->company->id,
            'code' => $code,
            'type' => 'customer',
        ]);
    }

    private function createSupplierPartner(string $code): Partner
    {
        return Partner::factory()->create([
            'tenant_id' => $this->company->tenant_id,
            'company_id' => $this->company->id,
            'code' => $code,
            'type' => 'supplier',
        ]);
    }

    /**
     * @param  list<array<string, string|null>>  $rows
     */
    private function makeArBatch(array $rows, string $name = 'TEST-AR'): OpeningBalanceBatch
    {
        $batchService = app(OpeningBalanceBatchService::class);
        $batch = $batchService->createBatch(
            $this->company,
            OpeningBatchType::ArOpenItems,
            now(),
            $name,
            $this->user->id,
            'phpunit'
        );
        $batchService->addImportRows($batch, $rows);

        return $batch;
    }

    /**
     * @param  list<array<string, string|null>>  $rows
     */
    private function makeApBatch(array $rows, string $name = 'TEST-AP'): OpeningBalanceBatch
    {
        $batchService = app(OpeningBalanceBatchService::class);
        $batch = $batchService->createBatch(
            $this->company,
            OpeningBatchType::ApOpenItems,
            now(),
            $name,
            $this->user->id,
            'phpunit'
        );
        $batchService->addImportRows($batch, $rows);

        return $batch;
    }
}
