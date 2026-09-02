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
            'source file' => ['GET', '/source-file'],
            'result workbook' => ['GET', '/result-workbook'],
            'discard' => ['DELETE', ''],
        ];
    }

    public function test_every_import_job_route_returns_the_standard_json_404_for_malformed_ids(): void
    {
        foreach (self::pinnedEndpoints() as $label => [$method, $suffix]) {
            $this->assertMalformedImportRouteReturnsStandardNotFound(
                $method,
                '/api/v1/imports/not-a-uuid'.$suffix,
                $label.' with a non-UUID id',
            );

            // GET /imports/ is the collection index, so an empty show id has no
            // distinct URI to exercise. Every suffix-bearing route still has a
            // concrete double-slash form matching the staging regression.
            if ($suffix !== '') {
                $this->assertMalformedImportRouteReturnsStandardNotFound(
                    $method,
                    '/api/v1/imports/'.$suffix,
                    $label.' with an empty id',
                );
            }
        }
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

    #[DataProvider('pinnedEndpoints')]
    public function test_every_job_surface_allows_an_unattributed_job_from_a_sibling_company(
        string $method,
        string $suffix,
    ): void {
        $status = $suffix === '/options' ? ImportStatus::Pending : ImportStatus::Completed;
        $job = $this->createJob(null, $status);
        $this->switchCompany($this->companyB);

        $response = $this->callEndpoint($method, '/api/v1/imports/'.$job->id.$suffix);

        $this->assertNotSame(409, $response->getStatusCode());
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

    public function test_index_filters_by_status(): void
    {
        $completed = $this->createJob($this->companyA->id, ImportStatus::Completed);
        $this->createJob($this->companyA->id, ImportStatus::Pending);

        $response = $this->getJson('/api/v1/imports?status=completed')->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $completed->id)
            ->assertJsonPath('data.0.status', ImportStatus::Completed->value);
    }

    public function test_index_filters_by_type(): void
    {
        $products = $this->createJob($this->companyA->id, type: ImportType::Products);
        $this->createJob($this->companyA->id, type: ImportType::Parties);

        $response = $this->getJson('/api/v1/imports?type=products')->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $products->id)
            ->assertJsonPath('data.0.type', ImportType::Products->value);
    }

    public function test_index_filters_by_filename_fragment(): void
    {
        $needle = $this->createJob($this->companyA->id, filename: 'august-supplier-import.csv');
        $this->createJob($this->companyA->id, filename: 'july-products.csv');

        $response = $this->getJson('/api/v1/imports?q=supplier')->assertOk();

        $response->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $needle->id)
            ->assertJsonPath('data.0.original_filename', 'august-supplier-import.csv');
    }

    public function test_index_paginates_twenty_five_jobs_across_two_pages_newest_first(): void
    {
        $jobIds = [];
        for ($index = 0; $index < 25; $index++) {
            $job = $this->createJob($this->companyA->id, filename: sprintf('job-%02d.csv', $index));
            DB::table('import_jobs')->where('id', $job->id)->update([
                'created_at' => now()->subMinutes(25 - $index),
            ]);
            $jobIds[] = $job->id;
        }

        $firstPage = $this->getJson('/api/v1/imports?page=1&per_page=15')->assertOk();
        $firstPage->assertJsonCount(15, 'data')
            ->assertJsonPath('data.0.id', $jobIds[24])
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 25);

        $secondPage = $this->getJson('/api/v1/imports?page=2&per_page=15')->assertOk();
        $secondPage->assertJsonCount(10, 'data')
            ->assertJsonPath('data.0.id', $jobIds[9])
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_index_refuses_per_page_above_one_hundred(): void
    {
        $this->getJson('/api/v1/imports?per_page=101')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['errors' => ['per_page']]]);
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

    public function test_upload_returns_a_coded_error_when_the_source_path_cannot_be_resolved(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'import-hash-');
        $this->assertIsString($path);
        file_put_contents($path, "name,type\nAcme,customer\n");

        $file = new class($path, 'parties.csv', 'text/csv', null, true) extends UploadedFile
        {
            public function getRealPath(): string|false
            {
                return false;
            }
        };

        try {
            $this->post('/api/v1/imports', [
                'file' => $file,
                'type' => ImportType::Parties->value,
            ], ['Accept' => 'application/json'])
                ->assertInternalServerError()
                ->assertJsonPath('error.code', 'IMPORT_SOURCE_HASH_FAILED');
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
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

    public function test_execute_atomically_adopts_an_unattributed_job_for_the_current_company(): void
    {
        Queue::fake();
        $job = $this->createJob(null, ImportStatus::Validated, 100);
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

        $job->refresh();
        $this->assertSame($this->companyA->id, $job->company_id);
        $this->assertSame(ImportStatus::Importing, $job->status);

        $this->switchCompany($this->companyB);
        $this->postJson('/api/v1/imports/'.$job->id.'/execute')
            ->assertConflict()
            ->assertJsonPath('error.code', 'IMPORT_COMPANY_MISMATCH');
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

    private function createJob(
        ?string $companyId,
        ImportStatus $status = ImportStatus::Completed,
        int $totalRows = 0,
        ImportType $type = ImportType::Parties,
        ?string $filename = null,
    ): ImportJob {
        return ImportJob::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $companyId,
            'user_id' => $this->user->id,
            'type' => $type,
            'status' => $status,
            'original_filename' => $filename ?? fake()->unique()->word().'.csv',
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
            'DELETE' => $this->deleteJson($uri),
            default => throw new \LogicException('Unsupported test endpoint method: '.$method),
        };
    }

    private function assertMalformedImportRouteReturnsStandardNotFound(
        string $method,
        string $uri,
        string $case,
    ): void {
        $response = $this->callEndpoint($method, $uri);

        $response->assertNotFound()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonStructure(['message']);
        $this->assertNotSame(
            'INTERNAL_ERROR',
            $response->json('error.code'),
            $case.' must never be rendered as a 500. Body: '.$response->getContent(),
        );
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
