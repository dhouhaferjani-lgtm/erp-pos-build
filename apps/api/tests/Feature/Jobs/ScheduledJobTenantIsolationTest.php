<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Import\Application\Jobs\ProcessImportJob;
use App\Modules\Import\Application\Jobs\ProcessProductImageImport;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Services\ImportService;
use App\Modules\Product\Application\Services\ProductImageImportService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Application\Services\UnitsProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Section 14 (api.scheduled-jobs cluster) — tenant-isolation regression
 * coverage for the 2 cat-(a) ShouldQueue jobs flagged by the triage at
 * docs/superpowers/audits/2026-05-07-api-scheduled-jobs-triage.md.
 *
 * Both jobs today carry NO tenant constructor anchor and execute unscoped
 * primary-key lookups (`ImportJob::find($this->importJobId)`,
 * `Company::find($this->companyId)`) inside `handle()`. The fix track:
 * introduce `App\Jobs\Concerns\BindsTenantContext` trait, add
 * `string $tenantId` constructor arg, and wrap the handle() body in
 * `$this->withTenantContext(fn () => ...)` so the worker rebinds
 * `CompanyContext` for the duration of execution AND the lookups gain
 * an explicit `tenant_id` predicate.
 *
 * Cross-references the inventory at:
 *   docs/superpowers/plans/tenant-isolation-sweep-inventory.yml
 *   (api.scheduled-jobs.001 .. api.scheduled-jobs.002)
 */
final class ScheduledJobTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private User $userA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = $this->makeTenant('scheduled-jobs-tenant-a');
        $this->tenantB = $this->makeTenant('scheduled-jobs-tenant-b');

        $this->companyA = Company::factory()->create(['tenant_id' => $this->tenantA->id]);
        // tenantB also seeds a Company implicitly via the import_jobs row
        // chain; the dedicated $companyB field was dropped because nothing
        // reads it (PHPStan property.onlyWritten).
        Company::factory()->create(['tenant_id' => $this->tenantB->id]);

        $this->userA = User::factory()->create(['tenant_id' => $this->tenantA->id]);
        $this->userB = User::factory()->create(['tenant_id' => $this->tenantB->id]);
    }

    // =========================================================================
    // ProcessImportJob (api.scheduled-jobs.001)
    // =========================================================================

    /**
     * After the cat-(a) wiring lands, ProcessImportJob's constructor must
     * carry a `string $tenantId` anchor (in addition to importJobId +
     * companyId) so the queue worker can rebind CompanyContext from the
     * job payload. Today the constructor is `(string $importJobId,
     * string $companyId)` — RED.
     *
     * Inventory: api.scheduled-jobs.001
     */
    public function test_process_import_job_constructor_carries_tenant_anchor(): void
    {
        $constructor = new ReflectionMethod(ProcessImportJob::class, '__construct');
        $params = $constructor->getParameters();

        $this->assertGreaterThanOrEqual(
            3,
            count($params),
            'ProcessImportJob::__construct must accept at least 3 params (importJobId, companyId, tenantId). '.
            'Today the queue worker has no way to rebind CompanyContext because the job payload carries no tenant anchor.',
        );

        $paramNames = array_map(static fn ($p) => strtolower($p->getName()), $params);
        $tenantParams = array_filter(
            $paramNames,
            static fn (string $n): bool => str_contains($n, 'tenant'),
        );
        $this->assertNotEmpty(
            $tenantParams,
            'ProcessImportJob::__construct must declare a tenantId-named parameter. '.
            'Got params: '.implode(', ', $paramNames),
        );
    }

    /**
     * Structural SQL-log invariant: every SELECT against import_jobs during
     * `handle()` must filter by tenant_id once the BindsTenantContext trait
     * is in place. Today `ImportJob::find($this->importJobId)` emits a
     * primary-key lookup with WHERE id = ? only — RED.
     *
     * The test seeds an ImportJob row in tenant-A and an unrelated row in
     * tenant-B, then dispatches the job synchronously with tenant-A
     * binding. Cross-tenant isolation requires that no SELECT in the run
     * could ever return a tenant-B row even by ID-collision accident.
     *
     * Inventory: api.scheduled-jobs.001
     */
    public function test_process_import_job_handle_only_reads_tenant_scoped_import_jobs(): void
    {
        $jobA = $this->seedImportJob($this->tenantA, $this->userA, ImportStatus::Validated);
        $jobB = $this->seedImportJob($this->tenantB, $this->userB, ImportStatus::Validated);

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            // Future shape: (importJobId, companyId, tenantId). Today's shape
            // is (importJobId, companyId) — instantiation will fail with a
            // TypeError until the cat-(a) fix lands. That IS the red signal.
            $instance = new ProcessImportJob($jobA->id, $this->companyA->id, $this->tenantA->id);
            $instance->handle(
                $this->app->make(ImportService::class),
                $this->app->make(UnitsProvisioningService::class),
            );
        } catch (\Throwable) {
            // Swallow — handle() may throw for unrelated reasons (no valid
            // rows etc.). We assert on SQL shape, not on terminal status.
        }

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $importJobsSelects = array_values(array_filter(
            $log,
            static fn (array $q): bool => str_contains($q['query'], 'import_jobs')
                && stripos($q['query'], 'select') === 0,
        ));

        $this->assertNotEmpty(
            $importJobsSelects,
            'Expected at least one SELECT against import_jobs during ProcessImportJob::handle().',
        );

        foreach ($importJobsSelects as $q) {
            $this->assertStringContainsString(
                'tenant_id',
                $q['query'],
                'Every import_jobs SELECT must filter by tenant_id once the cat-(a) fix is in place. '.
                'Query: '.$q['query'],
            );
        }

        // Defense-in-depth: verify tenant-B's row is intact (no mutation
        // could possibly have leaked across the binding).
        $this->assertSame(
            $jobB->status->value,
            $jobB->fresh()?->status->value,
            'Tenant-B import_jobs row must remain untouched after a tenant-A-bound job run.',
        );
    }

    // =========================================================================
    // ProcessProductImageImport (api.scheduled-jobs.002)
    // =========================================================================

    /**
     * The image job must carry both tenantId and companyId anchors because
     * queue workers have no CompanyContext.
     *
     * Inventory: api.scheduled-jobs.002
     */
    public function test_process_product_image_import_constructor_carries_tenant_anchor(): void
    {
        $constructor = new ReflectionMethod(ProcessProductImageImport::class, '__construct');
        $params = $constructor->getParameters();

        $this->assertGreaterThanOrEqual(
            4,
            count($params),
            'ProcessProductImageImport::__construct must accept importJobId, zipPath, tenantId, and companyId.',
        );

        $paramNames = array_map(static fn ($p) => strtolower($p->getName()), $params);
        $tenantParams = array_filter(
            $paramNames,
            static fn (string $n): bool => str_contains($n, 'tenant'),
        );
        $this->assertNotEmpty(
            $tenantParams,
            'ProcessProductImageImport::__construct must declare a tenantId-named parameter. '.
            'Got params: '.implode(', ', $paramNames),
        );
        $companyParams = array_filter(
            $paramNames,
            static fn (string $n): bool => str_contains($n, 'company'),
        );
        $this->assertNotEmpty(
            $companyParams,
            'ProcessProductImageImport::__construct must declare a companyId-named parameter. '.
            'Got params: '.implode(', ', $paramNames),
        );
    }

    /**
     * Structural SQL-log invariant for ProcessProductImageImport: every
     * SELECT against import_jobs during `handle()` must filter by tenant_id.
     * Today the lookup is unscoped — RED.
     *
     * The job's full path also touches `processZipImport`, which would
     * normally need a real ZIP file on disk. We pass a non-existent path so
     * the inner ZIP-extraction throws and the job's own try/catch terminates
     * cleanly; we only care about the import_jobs lookup shape, which fires
     * before the ZIP extraction.
     *
     * Inventory: api.scheduled-jobs.002
     */
    public function test_process_product_image_import_handle_only_reads_tenant_scoped_import_jobs(): void
    {
        $jobA = $this->seedImportJob($this->tenantA, $this->userA, ImportStatus::Pending, ImportType::ProductImages);
        $jobB = $this->seedImportJob($this->tenantB, $this->userB, ImportStatus::Pending, ImportType::ProductImages);

        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $instance = new ProcessProductImageImport(
                $jobA->id,
                'imports/non-existent-test.zip',
                $this->tenantA->id,
                $this->companyA->id,
            );
            $instance->handle(
                $this->app->make(ProductImageImportService::class),
                $this->app->make(ImportService::class),
            );
        } catch (\Throwable) {
            // Swallow — handle() catches its own exceptions and updates the
            // job to Failed. We assert on SQL shape, not on terminal status.
        }

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $importJobsSelects = array_values(array_filter(
            $log,
            static fn (array $q): bool => str_contains($q['query'], 'import_jobs')
                && stripos($q['query'], 'select') === 0,
        ));

        $this->assertNotEmpty(
            $importJobsSelects,
            'Expected at least one SELECT against import_jobs during ProcessProductImageImport::handle().',
        );

        foreach ($importJobsSelects as $q) {
            $this->assertStringContainsString(
                'tenant_id',
                $q['query'],
                'Every import_jobs SELECT must filter by tenant_id once the cat-(a) fix is in place. '.
                'Query: '.$q['query'],
            );
        }

        // Defense-in-depth: tenant-B's row must remain untouched.
        $this->assertSame(
            $jobB->status->value,
            $jobB->fresh()?->status->value,
            'Tenant-B import_jobs row must remain untouched after a tenant-A-bound job run.',
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => "Tenant {$slug}",
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    private function seedImportJob(
        Tenant $tenant,
        User $user,
        ImportStatus $status,
        ImportType $type = ImportType::Products,
    ): ImportJob {
        return ImportJob::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'company_id' => Company::query()->where('tenant_id', $tenant->id)->firstOrFail()->id,
            'user_id' => $user->id,
            'type' => $type->value,
            'status' => $status->value,
            'original_filename' => "test-{$tenant->slug}.csv",
            'file_path' => "imports/test-{$tenant->slug}.csv",
            'total_rows' => 0,
        ]);
    }
}
