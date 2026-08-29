<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Application\Jobs\ProcessImportJob;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Services\ImportService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Application\Services\UnitsProvisioningService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class ImportCompanyPinTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $companyA;

    private Company $companyB;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Pinned Imports Tenant',
            'slug' => 'pinned-imports-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->companyA = $this->createCompany('Company A', 'A');
        $this->companyB = $this->createCompany('Company B', 'B');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Import Operator',
            'email' => 'import-pin@example.com',
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
        }

        Storage::fake('local');
        $this->switchCompany($this->companyA);
    }

    /** @return array<string, array{string, string}> */
    public static function pinnedEndpoints(): array
    {
        return [
            'show' => ['GET', ''],
            'preview' => ['GET', '/preview'],
            'errors' => ['GET', '/errors'],
            'update options' => ['PATCH', '/options'],
            'error summary' => ['GET', '/error-summary'],
            'execute' => ['POST', '/execute'],
            'failed rows' => ['GET', '/failed-rows.csv'],
            'result workbook' => ['GET', '/result-workbook'],
        ];
    }

    #[DataProvider('pinnedEndpoints')]
    public function test_every_job_surface_refuses_a_sibling_company(string $method, string $suffix): void
    {
        $job = $this->createJob($this->companyA->id);
        $this->switchCompany($this->companyB);

        $response = $this->callEndpoint($method, '/api/v1/imports/'.$job->id.$suffix);

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'IMPORT_COMPANY_MISMATCH');
    }

    public function test_unattributed_job_is_readable_from_both_companies_and_same_company_is_unchanged(): void
    {
        $unattributed = $this->createJob(null);
        $pinned = $this->createJob($this->companyA->id);

        $this->getJson('/api/v1/imports/'.$unattributed->id)->assertOk();
        $this->getJson('/api/v1/imports/'.$pinned->id)->assertOk();

        $this->switchCompany($this->companyB);
        $this->getJson('/api/v1/imports/'.$unattributed->id)->assertOk();
    }

    public function test_index_lists_current_company_and_unattributed_jobs_only(): void
    {
        $jobA = $this->createJob($this->companyA->id);
        $jobB = $this->createJob($this->companyB->id);
        $legacy = $this->createJob(null);

        $responseA = $this->getJson('/api/v1/imports')->assertOk();
        $responseA->assertJsonCount(2, 'data');
        $expectedA = [
            $jobA->id => false,
            $legacy->id => true,
        ];
        ksort($expectedA);
        $this->assertSame($expectedA, $this->jobsByAttribution($responseA->json('data')));

        $this->switchCompany($this->companyB);
        $responseB = $this->getJson('/api/v1/imports')->assertOk();
        $responseB->assertJsonCount(2, 'data');
        $expectedB = [
            $jobB->id => false,
            $legacy->id => true,
        ];
        ksort($expectedB);
        $this->assertSame($expectedB, $this->jobsByAttribution($responseB->json('data')));
    }

    public function test_upload_stamps_company_and_hash_of_uploaded_bytes(): void
    {
        $contents = "name,type\nAcme,customer\n";

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/imports', [
                'file' => UploadedFile::fake()->createWithContent('parties.csv', $contents),
                'type' => ImportType::Parties->value,
            ])
            ->assertCreated();

        $job = ImportJob::query()->latest('created_at')->firstOrFail();
        $this->assertSame($this->companyA->id, $job->company_id);
        $this->assertSame(hash('sha256', $contents), $job->source_hash);
    }

    public function test_async_dispatch_keeps_job_company_and_tenant_arguments(): void
    {
        Queue::fake();
        $job = $this->createJob($this->companyA->id, ImportStatus::Validated, 100);
        $now = now();
        $rows = [];
        for ($row = 1; $row <= 100; $row++) {
            $rows[] = [
                'id' => fake()->uuid(),
                'import_job_id' => $job->id,
                'row_number' => $row,
                'data' => '{}',
                'is_valid' => true,
                'is_imported' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('import_rows')->insert($rows);

        $this->postJson('/api/v1/imports/'.$job->id.'/execute')->assertAccepted();

        Queue::assertPushed(ProcessImportJob::class, function (ProcessImportJob $queued) use ($job): bool {
            return $queued->importJobId === $job->id
                && $queued->companyId === $this->companyA->id
                && $queued->tenantId === $this->tenant->id;
        });
    }

    public function test_worker_rejects_a_company_id_from_a_different_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherCompany = Company::factory()->create(['tenant_id' => $otherTenant->id]);
        $job = $this->createJob($this->companyA->id, ImportStatus::Validated, 1);
        DB::table('import_rows')->insert([
            'id' => fake()->uuid(),
            'import_job_id' => $job->id,
            'row_number' => 1,
            'data' => '{}',
            'is_valid' => true,
            'is_imported' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (new ProcessImportJob($job->id, $otherCompany->id, $this->tenant->id))->handle(
            app(ImportService::class),
            app(UnitsProvisioningService::class),
        );

        $freshJob = ImportJob::query()->findOrFail($job->id);
        $this->assertSame(ImportStatus::Failed, $freshJob->status);
        $this->assertSame('Company not found', $freshJob->error_message);
    }

    private function createCompany(string $name, string $suffix): Company
    {
        return Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'legal_name' => $name.' LLC',
            'tax_id' => 'PIN-'.$suffix,
            'country_code' => 'TN',
            'locale' => 'fr_FR',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
    }

    private function createJob(?string $companyId, ImportStatus $status = ImportStatus::Completed, int $totalRows = 0): ImportJob
    {
        return ImportJob::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $companyId,
            'user_id' => $this->user->id,
            'type' => ImportType::Parties,
            'status' => $status,
            'original_filename' => fake()->unique()->word().'.csv',
            'file_path' => 'imports/fixture.csv',
            'total_rows' => $totalRows,
            'successful_rows' => $status === ImportStatus::Validated ? $totalRows : 0,
        ]);
    }

    private function switchCompany(Company $company): void
    {
        app(CompanyContext::class)->setCompanyId($company->id);
        $this->actingAs($this->user, 'sanctum');
        $this->withHeader('X-Company-Id', $company->id);
    }

    /** @return TestResponse<Response> */
    private function callEndpoint(string $method, string $uri): TestResponse
    {
        return match ($method) {
            'GET' => $this->getJson($uri),
            'POST' => $this->postJson($uri),
            'PATCH' => $this->patchJson($uri, ['options' => []]),
            default => throw new \LogicException('Unsupported test endpoint method: '.$method),
        };
    }

    /**
     * @param  list<array<string, bool|string|int|null>>  $jobs
     * @return array<string, bool>
     */
    private function jobsByAttribution(array $jobs): array
    {
        $result = [];
        foreach ($jobs as $job) {
            $result[(string) $job['id']] = (bool) $job['unattributed'];
        }
        ksort($result);

        return $result;
    }
}
