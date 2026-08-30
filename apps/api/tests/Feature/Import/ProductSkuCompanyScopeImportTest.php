<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Application\Services\UnitsProvisioningService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ProductSkuCompanyScopeImportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $companyA;

    private Company $companyB;

    private Location $annexLocation;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Company SKU Import Tenant',
            'slug' => 'company-sku-import-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->companyA = $this->createCompany('Company A', 'SKU-A');
        $this->companyB = $this->createCompany('Company B', 'SKU-B');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Company SKU Importer',
            'email' => 'company-sku-import@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        foreach ([$this->companyA, $this->companyB] as $company) {
            UserCompanyMembership::create([
                'user_id' => $this->user->id,
                'company_id' => $company->id,
                'role' => 'admin',
            ]);

            $this->createLocation($company, 'MAIN', true);
            $this->createSystemAccounts($company);

            // Lane G-12: POST /api/v1/imports refuses product imports with 422
            // `units_not_seeded` unless the company has visible units. Each
            // sibling company needs its own provisioning pass.
            app(CompanyContext::class)->setCompanyId($company->id);
            app(UnitsProvisioningService::class)->provisionForCompany($company);
        }

        $this->annexLocation = $this->createLocation($this->companyA, 'ANNEX', false);
        Storage::fake('local');
    }

    public function test_the_same_product_file_imports_into_sibling_companies(): void
    {
        $lines = [
            'name,sku,type,quantity,location_code,purchase_price',
            'Shared SKU Product,SHARED-SKU,part,2.0000,MAIN,3.000',
        ];

        $this->runImport($this->companyA, $lines);
        $this->runImport($this->companyB, $lines);

        $products = Product::withoutGlobalScopes()
            ->where('tenant_id', $this->tenant->id)
            ->where('sku', 'SHARED-SKU')
            ->get();

        $this->assertCount(2, $products);
        $this->assertEqualsCanonicalizing(
            [$this->companyA->id, $this->companyB->id],
            $products->pluck('company_id')->all(),
        );
    }

    public function test_a_second_location_import_upserts_stock_without_creating_another_product(): void
    {
        $this->runImport($this->companyA, [
            'name,sku,type,quantity,location_code,purchase_price',
            'Multi-location Product,MULTI-LOC,part,2.0000,MAIN,3.000',
        ]);

        $product = Product::query()
            ->where('company_id', $this->companyA->id)
            ->where('sku', 'MULTI-LOC')
            ->firstOrFail();

        $this->runImport($this->companyA, [
            'name,sku,type,quantity,location_code,purchase_price',
            'Multi-location Product,MULTI-LOC,part,4.0000,ANNEX,3.000',
        ]);

        $this->assertSame(1, Product::query()->where('company_id', $this->companyA->id)->where('sku', 'MULTI-LOC')->count());
        $this->assertSame(2, StockLevel::query()->where('product_id', $product->id)->count());
        $this->assertSame(
            0,
            bccomp(
                '4.0000',
                StockLevel::query()
                    ->where('product_id', $product->id)
                    ->where('location_id', $this->annexLocation->id)
                    ->firstOrFail()
                    ->quantity,
                4,
            ),
        );
    }

    private function createCompany(string $name, string $taxId): Company
    {
        return Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'legal_name' => "{$name} LLC",
            'tax_id' => $taxId,
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
            'default_tax_rate' => '19.00',
        ]);
    }

    private function createLocation(Company $company, string $code, bool $isDefault): Location
    {
        return Location::create([
            'company_id' => $company->id,
            'name' => "{$company->name} {$code}",
            'code' => $code,
            'type' => 'warehouse',
            'is_default' => $isDefault,
            'is_active' => true,
        ]);
    }

    private function createSystemAccounts(Company $company): void
    {
        foreach ([
            ['3100', 'Inventory Asset', AccountType::Asset, SystemAccountPurpose::Inventory],
            ['3900', 'Opening Balance Equity', AccountType::Equity, SystemAccountPurpose::OpeningBalanceEquity],
        ] as [$code, $name, $type, $purpose]) {
            Account::create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $company->id,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'system_purpose' => $purpose,
                'is_active' => true,
                'is_system' => true,
            ]);
        }
    }

    /**
     * @param  list<string>  $lines
     */
    private function runImport(Company $company, array $lines): void
    {
        app(CompanyContext::class)->setCompanyId($company->id);

        $file = UploadedFile::fake()->createWithContent(
            'products-'.bin2hex(random_bytes(4)).'.csv',
            implode("\n", $lines),
        );

        $createResponse = $this->withHeader('X-Company-Id', $company->id)
            ->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', ['file' => $file, 'type' => 'products']);
        $createResponse->assertCreated();

        $jobId = $createResponse->json('data.id');
        $this->assertIsString($jobId);

        $this->withHeader('X-Company-Id', $company->id)
            ->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$jobId}/execute")
            ->assertOk()
            ->assertJsonPath('data.successful_rows', 1)
            ->assertJsonPath('data.failed_rows', 0);
    }
}
