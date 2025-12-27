<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Services\ImportService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
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
        $this->assertCount(4, $rows);

        // Check validity status
        $validCount = collect($rows)->where('is_valid', true)->count();
        $invalidCount = collect($rows)->where('is_valid', false)->count();

        $this->assertEquals(2, $validCount);
        $this->assertEquals(2, $invalidCount);

        // Verify summary reflects validation
        $summary = $previewResponse->json('data.summary');
        $this->assertEquals(2, $summary['valid_rows']);
        $this->assertEquals(2, $summary['invalid_rows']);
    }

    public function test_preview_returns_404_for_nonexistent_job(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/imports/nonexistent-uuid/preview');

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
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
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

    public function test_preview_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/imports/some-job-id/preview');

        $response->assertUnauthorized();
    }
}
