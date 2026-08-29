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
use App\Modules\Import\Domain\ImportRow;
use App\Modules\Import\Services\ImportService;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Application\Services\UnitsProvisioningService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Lane Q-1 — an import job must not be executable twice.
 *
 * Covers the four holes of parent sweep finding #23:
 *  (a) POST /imports/{id}/execute has no *status* precondition of its own — a
 *      terminal job answers the generic "no valid rows" refusal, and a job whose
 *      status was clobbered back to Pending is accepted outright.
 *  (b) the controller writes `Pending` AFTER dispatching the worker, so a worker
 *      that already advanced (or finished) the job is overwritten back to a
 *      start-eligible status.
 *  (c) ProcessImportJob::processImport() writes `Importing` unconditionally — no
 *      claim, so a redelivered/duplicate job re-runs over the same rows.
 *  (d) ImportService::getValidRows() filters `is_valid` only, so any re-entry is
 *      replay-shaped rather than resume-shaped.
 */
final class ImportReExecutionGuardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Re-execution Tenant',
            'slug' => 'reexec-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Re-execution Company',
            'legal_name' => 'Re-execution Company LLC',
            'tax_id' => 'TAX-REEXEC',
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
            'name' => 'Re-execution User',
            'email' => 'reexec@example.com',
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
    }

    /**
     * Seed a Validated products job.
     *
     * `total_rows` is inflated past ImportController::ASYNC_THRESHOLD (100) so
     * execute() takes the QUEUE branch — the branch carrying the ordering bug —
     * while only a handful of real rows are written, keeping the test fast.
     * With QUEUE_CONNECTION=sync the dispatched worker runs inline, which makes
     * the "worker finished before the controller's status write" race
     * deterministic rather than timing-dependent.
     *
     * @param  list<string>  $skus
     */
    private function seedValidatedJob(array $skus, bool $forceAsyncBranch = true): ImportJob
    {
        $job = ImportJob::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'type' => ImportType::Products,
            'status' => ImportStatus::Validated,
            'original_filename' => 'products.csv',
            'file_path' => 'imports/products.csv',
            'total_rows' => $forceAsyncBranch ? 150 : count($skus),
            'processed_rows' => 0,
            'successful_rows' => count($skus),
            'failed_rows' => 0,
        ]);

        foreach ($skus as $i => $sku) {
            ImportRow::create([
                'import_job_id' => $job->id,
                'row_number' => $i + 1,
                'data' => [
                    'name' => 'Product '.$sku,
                    'sku' => $sku,
                    'type' => 'part',
                    'sale_price' => '10.00',
                    'purchase_price' => '5.00',
                ],
                'is_valid' => true,
            ]);
        }

        return $job;
    }

    public function test_queued_execute_does_not_clobber_the_workers_status_back_to_pending(): void
    {
        $job = $this->seedValidatedJob(['CLOB-1', 'CLOB-2']);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$job->id}/execute")
            ->assertStatus(202);

        $job->refresh();

        $this->assertSame(
            ImportStatus::Completed,
            $job->status,
            'The controller must not write Pending over the status a worker already advanced.'
        );
        $this->assertSame(2, Product::where('company_id', $this->company->id)->count());
    }

    public function test_a_finished_job_cannot_be_executed_again_and_no_row_is_re_applied(): void
    {
        $job = $this->seedValidatedJob(['REX-1', 'REX-2']);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$job->id}/execute")
            ->assertStatus(202);

        $this->assertSame(2, Product::where('company_id', $this->company->id)->count());

        // Replay witness. The row writers are upserts, so re-applying a row
        // rewrites identical values and leaves no timestamp trace — editing the
        // imported entity first makes a replay unmistakable: only a second
        // application of row 1 can put the file's name back.
        $this->renameImportedProduct('REX-1');
        $startedAtBefore = (string) DB::table('import_jobs')->where('id', $job->id)->value('started_at');

        $this->travel(5)->seconds();

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$job->id}/execute")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'IMPORT_NOT_EXECUTABLE');

        $this->assertProductWasNotReapplied('REX-1');
        $this->assertSame(
            $startedAtBefore,
            (string) DB::table('import_jobs')->where('id', $job->id)->value('started_at'),
            'A refused execute must not restart the job.'
        );
        $this->assertSame(2, Product::where('company_id', $this->company->id)->count());
    }

    public function test_a_job_clobbered_back_to_pending_is_not_replayed(): void
    {
        $job = $this->seedValidatedJob(['CLOBREX-1', 'CLOBREX-2']);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$job->id}/execute")
            ->assertStatus(202);

        $this->renameImportedProduct('CLOBREX-1');

        // Reproduce the exact residue the ordering bug leaves behind: a fully
        // imported job advertising a start-eligible status. Even here — where the
        // status column itself lies — re-entry must be resume-shaped, never a
        // replay of rows that already landed.
        DB::table('import_jobs')->where('id', $job->id)->update(['status' => ImportStatus::Pending->value]);

        $this->travel(5)->seconds();

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$job->id}/execute");

        $this->assertProductWasNotReapplied('CLOBREX-1');
        $this->assertSame(2, Product::where('company_id', $this->company->id)->count());
    }

    private function renameImportedProduct(string $sku): void
    {
        $updated = Product::where('company_id', $this->company->id)
            ->where('sku', $sku)
            ->update(['name' => 'Edited After Import']);

        $this->assertSame(1, $updated, 'Replay witness requires the first import to have created '.$sku);
    }

    private function assertProductWasNotReapplied(string $sku): void
    {
        $this->assertSame(
            'Edited After Import',
            Product::where('company_id', $this->company->id)->where('sku', $sku)->value('name'),
            'The import file was applied a second time — it overwrote the edit made after the first run.'
        );
    }

    public function test_worker_does_not_reprocess_a_job_another_worker_already_started(): void
    {
        $job = $this->seedValidatedJob(['CLAIM-1', 'CLAIM-2']);
        $job->update([
            'status' => ImportStatus::Importing,
            'claimed_at' => now(),
            'started_at' => now(),
            'worker_started_at' => now(),
        ]);

        (new ProcessImportJob($job->id, $this->company->id, $this->tenant->id))
            ->handle(
                $this->app->make(ImportService::class),
                $this->app->make(UnitsProvisioningService::class),
            );

        $this->assertSame(
            0,
            Product::where('company_id', $this->company->id)->count(),
            'A job already started by another worker must not be processed a second time.'
        );
        $this->assertSame(
            0,
            ImportRow::where('import_job_id', $job->id)->where('is_imported', true)->count()
        );
        $this->assertSame(
            ImportStatus::Importing->value,
            (string) DB::table('import_jobs')->where('id', $job->id)->value('status'),
            'The losing worker must leave the winner\'s status untouched.'
        );
    }

    public function test_get_valid_rows_excludes_already_imported_rows(): void
    {
        $job = $this->seedValidatedJob(['VR-1', 'VR-2', 'VR-3']);

        ImportRow::where('import_job_id', $job->id)
            ->where('row_number', 2)
            ->update(['is_imported' => true]);

        // An invalid row must stay excluded as before.
        ImportRow::where('import_job_id', $job->id)
            ->where('row_number', 3)
            ->update(['is_valid' => false]);

        $rows = $this->app->make(ImportService::class)->getValidRows($job->refresh());

        $this->assertSame([1], $rows->pluck('row_number')->all());
    }

    public function test_validated_job_still_completes_through_the_queue_branch(): void
    {
        $job = $this->seedValidatedJob(['HAPPY-1', 'HAPPY-2', 'HAPPY-3']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$job->id}/execute");

        $response->assertStatus(202);

        $job->refresh();
        $this->assertSame(ImportStatus::Completed, $job->status);
        $this->assertSame(3, $job->successful_rows);
        $this->assertSame(0, $job->failed_rows);
        $this->assertSame(3, Product::where('company_id', $this->company->id)->count());
        $this->assertSame(
            3,
            ImportRow::where('import_job_id', $job->id)->where('is_imported', true)->count()
        );
    }

    public function test_small_validated_job_still_completes_through_the_synchronous_branch(): void
    {
        $job = $this->seedValidatedJob(['SYNC-1', 'SYNC-2'], forceAsyncBranch: false);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/imports/{$job->id}/execute")
            ->assertOk();

        $job->refresh();
        $this->assertSame(ImportStatus::Completed, $job->status);
        $this->assertSame(2, Product::where('company_id', $this->company->id)->count());
    }
}
