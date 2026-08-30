<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\Enums\DuplicateBucket;
use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Services\ImportService;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Application\Services\UnitsProvisioningService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ImportPreviewTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

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
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(UnitsProvisioningService::class)->provisionForCompany($this->company);

        Storage::fake('local');
    }

    public function test_can_get_import_preview(): void
    {
        // Create a file with 6 rows to test the limit
        $file = UploadedFile::fake()->createWithContent(
            'partners.csv',
            "name,type,email,phone\n".
            "Acme Corp,customer,acme@example.com,+1234567890\n".
            "Supplier Inc,supplier,supplier@example.com,+0987654321\n".
            "Both Company,both,both@example.com,+1122334455\n".
            "Another Corp,customer,another@example.com,+5544332211\n".
            "Fifth Partner,supplier,fifth@example.com,+6677889900\n".
            'Sixth Partner,customer,sixth@example.com,+9988776655'
        );

        // Create import job
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $file,
                'type' => 'partners',
            ]);

        $response->assertCreated();
        $jobId = $response->json('data.id');

        // Get preview
        $previewResponse = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/imports/{$jobId}/preview");

        $previewResponse->assertOk();
        $previewResponse->assertJsonMissingPath('data.duplicates');

        // Verify response structure
        $previewResponse->assertJsonStructure([
            'data' => [
                'headers',
                'rows' => [
                    '*' => [
                        'row_number',
                        'data',
                        'is_valid',
                        'errors',
                    ],
                ],
                'summary' => [
                    'total_rows',
                    'valid_rows',
                    'invalid_rows',
                ],
            ],
        ]);

        // Verify only 4 rows are returned (PREVIEW_ROW_LIMIT)
        $rows = $previewResponse->json('data.rows');
        $this->assertCount(4, $rows);

        // Verify headers are correct
        $headers = $previewResponse->json('data.headers');
        $this->assertEquals(['name', 'type', 'email', 'phone'], $headers);

        // Verify summary shows total rows
        $summary = $previewResponse->json('data.summary');
        $this->assertEquals(6, $summary['total_rows']);
    }

    public function test_preview_shows_valid_and_invalid_rows(): void
    {
        // Create a file with valid and invalid rows
        $file = UploadedFile::fake()->createWithContent(
            'partners.csv',
            "name,type,email\n".
            "Valid Corp,customer,valid@example.com\n".
            ",customer,missing-name@example.com\n".  // Invalid: missing name
            "Another Valid,supplier,another@example.com\n".
            'Invalid Type,invalid_type,invalid@example.com'  // Invalid: wrong type
        );

        // Create import job
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $file,
                'type' => 'partners',
            ]);

        $response->assertCreated();
        $jobId = $response->json('data.id');

        // Get preview
        $previewResponse = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/imports/{$jobId}/preview");

        $previewResponse->assertOk();

        $rows = $previewResponse->json('data.rows');
        $this->assertIsArray($rows);
        $this->assertCount(4, $rows);

        $validCount = 0;
        $invalidCount = 0;
        foreach ($rows as $row) {
            $this->assertIsArray($row);
            if (($row['is_valid'] ?? false) === true) {
                $validCount++;
            } else {
                $invalidCount++;
            }
        }

        $this->assertEquals(2, $validCount);
        $this->assertEquals(2, $invalidCount);

        // Verify summary reflects validation
        $summary = $previewResponse->json('data.summary');
        $this->assertEquals(2, $summary['valid_rows']);
        $this->assertEquals(2, $summary['invalid_rows']);
    }

    public function test_product_preview_exposes_each_refusal_as_advisory_detail(): void
    {
        $deleted = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'DELETED-SKU',
        ]);
        $deleted->delete();
        $file = UploadedFile::fake()->createWithContent(
            'products.csv',
            "name,sku\nReplacement,DELETED-SKU",
        );

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $file,
                'type' => ImportType::Products->value,
            ]);

        $response->assertCreated();
        $preview = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/imports/'.$response->json('data.id').'/preview');

        $preview->assertOk()
            ->assertJsonPath('data.duplicates.counts.'.DuplicateBucket::Refused->value, 1)
            ->assertJsonPath('data.duplicates.refused.0.row_number', 1)
            ->assertJsonPath('data.duplicates.refused.0.code', ImportErrorCode::SkuHeldByDeletedProduct->value)
            ->assertJsonPath('data.rows.0.duplicate_bucket', DuplicateBucket::Refused->value)
            ->assertJsonPath('data.rows.0.duplicate_advisory.row_number', 1)
            ->assertJsonPath(
                'data.rows.0.duplicate_advisory.code',
                ImportErrorCode::SkuHeldByDeletedProduct->value,
            );
    }

    public function test_preview_returns_404_for_nonexistent_job(): void
    {
        // Valid-but-nonexistent UUID: the id column is uuid, so a malformed
        // string would raise a PostgreSQL cast error instead of a 404.
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/imports/00000000-0000-0000-0000-000000000000/preview');

        $response->assertNotFound();
    }

    public function test_preview_returns_404_for_other_tenant_job(): void
    {
        // Create another tenant and job
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $otherCompany = Company::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Company',
            'legal_name' => 'Other Company LLC',
            'tax_id' => 'TAX456',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
        ]);

        $otherUser = User::create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        /** @var ImportService $importService */
        $importService = app(ImportService::class);

        $job = $importService->createJob(
            tenantId: $otherTenant->id,
            userId: $otherUser->id,
            type: ImportType::Partners,
            filename: 'other.csv',
            filePath: 'imports/other.csv',
            totalRows: 1
        );

        // Try to access from different tenant
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/imports/{$job->id}/preview");

        $response->assertNotFound();
    }

    public function test_preview_with_less_than_limit_rows(): void
    {
        // Create a file with only 2 rows
        $file = UploadedFile::fake()->createWithContent(
            'partners.csv',
            "name,type,email\n".
            "Acme Corp,customer,acme@example.com\n".
            'Supplier Inc,supplier,supplier@example.com'
        );

        // Create import job
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $file,
                'type' => 'partners',
            ]);

        $response->assertCreated();
        $jobId = $response->json('data.id');

        // Get preview
        $previewResponse = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/imports/{$jobId}/preview");

        $previewResponse->assertOk();

        // Should return all 2 rows since it's less than the limit
        $rows = $previewResponse->json('data.rows');
        $this->assertCount(2, $rows);

        $summary = $previewResponse->json('data.summary');
        $this->assertEquals(2, $summary['total_rows']);
    }

    public function test_preview_populates_mapped_data_rows_from_display_header_csvs(): void
    {
        $productFile = $this->uploadedFixture('products-display-headers.csv');

        $productResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $productFile,
                'type' => 'products',
                'column_mapping' => json_encode([
                    'Product Name' => 'name',
                    'SKU' => 'sku',
                    'Type' => 'type',
                    'Sale Price' => 'sale_price',
                    'Tax Rate' => 'tax_rate',
                ], JSON_THROW_ON_ERROR),
            ]);

        $productResponse->assertCreated();

        $productPreview = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/imports/{$productResponse->json('data.id')}/preview");

        $productPreview->assertOk();
        $productPreview->assertJsonPath('data.rows.0.data.name', 'Panadol 500mg');
        $productPreview->assertJsonPath('data.rows.0.data.sku', 'MED-001');
        $productPreview->assertJsonPath('data.rows.0.data.type', 'part');
        $productPreview->assertJsonPath('data.rows.0.is_valid', true);
        $productPreview->assertJsonPath('data.summary.valid_rows', 1);

        $partnerFile = $this->uploadedFixture('partners-display-headers.csv');

        $partnerResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $partnerFile,
                'type' => 'partners',
                'column_mapping' => json_encode([
                    'Partner Name' => 'name',
                    'Partner Type' => 'type',
                    'Email' => 'email',
                    'Phone' => 'phone',
                ], JSON_THROW_ON_ERROR),
            ]);

        $partnerResponse->assertCreated();

        $partnerPreview = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/imports/{$partnerResponse->json('data.id')}/preview");

        $partnerPreview->assertOk();
        $partnerPreview->assertJsonPath('data.rows.0.data.name', 'Clinique El Manar');
        $partnerPreview->assertJsonPath('data.rows.0.data.type', 'customer');
        $partnerPreview->assertJsonPath('data.rows.0.data.email', 'contact@elmanar.example');
        $partnerPreview->assertJsonPath('data.rows.0.is_valid', true);
        $partnerPreview->assertJsonPath('data.summary.valid_rows', 1);
    }

    public function test_preview_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/imports/some-job-id/preview');

        $response->assertUnauthorized();
    }

    private function uploadedFixture(string $name): UploadedFile
    {
        $path = __DIR__.'/../../Fixtures/Import/'.$name;
        if (! is_file($path)) {
            throw new RuntimeException("Missing import fixture [{$name}].");
        }

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }
}
