<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
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

final class ImportJobOptionsTest extends TestCase
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
            'slug' => 'test-import-options',
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
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'options@example.com',
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

    public function test_import_job_options_are_ingested_and_patchable_before_execution(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $this->partnersFile('partners-options.csv'),
                'type' => 'partners',
                'options' => ['price_authority' => 'ttc'],
            ]);

        $response->assertCreated();

        $jobId = $response->json('data.id');
        $job = ImportJob::where('id', $jobId)->first();
        $this->assertInstanceOf(ImportJob::class, $job);
        $this->assertSame(['price_authority' => 'ttc'], $job->options);
        $this->assertSame(['price_authority' => 'ttc'], $response->json('data.options'));

        $invalidResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => $this->partnersFile('partners-options-invalid.csv'),
                'type' => 'partners',
                'options' => ['price_authority' => 'bogus'],
            ]);

        $invalidResponse->assertStatus(422);

        $pendingJob = app(ImportService::class)->createJob(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: $this->user->id,
            type: ImportType::Products,
            filename: 'products.csv',
            filePath: 'imports/test/products.csv',
            totalRows: 0
        );
        $pendingJob->update(['options' => ['location_code' => 'MAIN']]);

        $patchResponse = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/imports/{$pendingJob->id}/options", [
                'options' => ['price_authority' => 'margin'],
            ]);

        $patchResponse->assertOk();
        $this->assertSame(
            ['location_code' => 'MAIN', 'price_authority' => 'margin'],
            $pendingJob->refresh()->options
        );

        $pendingJob->update(['status' => ImportStatus::Importing]);

        $conflictResponse = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/imports/{$pendingJob->id}/options", [
                'options' => ['price_authority' => 'ht'],
            ]);

        $conflictResponse->assertStatus(409);
        $conflictResponse->assertJsonPath('error.code', 'IMPORT_ALREADY_STARTED');
    }

    private function partnersFile(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "name,type,email\nAcme Corp,customer,acme@example.com"
        );
    }
}
