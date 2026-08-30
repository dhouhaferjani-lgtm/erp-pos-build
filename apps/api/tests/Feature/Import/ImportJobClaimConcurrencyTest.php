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
use App\Modules\Import\Domain\Data\ImportCountersData;
use App\Modules\Import\Domain\Data\ImportErrorDetailData;
use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\Enums\ImportRowOutcome;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Domain\ImportRow;
use App\Modules\Import\Services\ImportJobClaimService;
use App\Modules\Import\Services\ImportService;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Uom\Application\Services\UnitsProvisioningService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\CompositeExpectation;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ImportJobClaimConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const CLAIM_CLEANUP_CONNECTION = 'pgsql_import_claim_cleanup';

    public function test_lifecycle_columns_required_by_claim_and_terminal_cas_exist(): void
    {
        foreach ([
            'error_code',
            'error_detail',
            'claimed_at',
            'worker_started_at',
            'source_purged_at',
            'skipped_rows',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('import_jobs', $column),
                "import_jobs.{$column} is required by lane G-6a",
            );
        }
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $expected
     */
    #[DataProvider('legacyErrorDetailShapes')]
    public function test_error_detail_hydration_is_version_tolerant_and_does_not_null_pad(
        array $stored,
        array $expected,
    ): void {
        $detail = ImportErrorDetailData::from($stored);

        $this->assertSame($expected, $detail->toArray());
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function legacyErrorDetailShapes(): array
    {
        return [
            'empty object' => [[], []],
            'unknown keys are ignored' => [['future_key' => 'future-value'], []],
            'missing keys stay omitted' => [['sku' => 'SKU-1'], ['sku' => 'SKU-1']],
            'explicit null remains explicit' => [['reason' => null], ['reason' => null]],
            'legacy scalar and list fields' => [
                ['supplied' => 'kgs', 'accepted' => ['kg', 'g'], 'held_quantity' => '2.5000'],
                ['supplied' => 'kgs', 'accepted' => ['kg', 'g'], 'held_quantity' => '2.5000'],
            ],
            'nested candidates hydrate' => [
                [
                    'candidates' => [[
                        'id' => '00000000-0000-4000-8000-000000000001',
                        'code' => 'kg',
                        'name' => 'Kilogram',
                        'category' => 'weight',
                        'tier' => 'system',
                    ]],
                    'candidate_skus' => ['SKU-A', 'SKU-B'],
                ],
                [
                    'candidates' => [[
                        'id' => '00000000-0000-4000-8000-000000000001',
                        'code' => 'kg',
                        'name' => 'Kilogram',
                        'category' => 'weight',
                        'tier' => 'system',
                    ]],
                    'candidate_skus' => ['SKU-A', 'SKU-B'],
                ],
            ],
        ];
    }

    public function test_import_job_casts_error_code_detail_and_lifecycle_timestamps(): void
    {
        $jobId = (string) Str::uuid();
        $tenantId = (string) Str::uuid();
        $now = now();

        DB::table('import_jobs')->insert([
            'id' => $jobId,
            'tenant_id' => $tenantId,
            'user_id' => (string) Str::uuid(),
            'type' => ImportType::Products->value,
            'status' => ImportStatus::Importing->value,
            'original_filename' => 'legacy.csv',
            'file_path' => "imports/{$tenantId}/legacy.csv",
            'error_code' => ImportErrorCode::WorkerLost->value,
            'error_detail' => json_encode(['filename' => 'legacy.csv', 'unknown' => true], JSON_THROW_ON_ERROR),
            'claimed_at' => $now,
            'worker_started_at' => $now,
            'source_purged_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $job = ImportJob::query()->findOrFail($jobId);

        $this->assertSame(ImportErrorCode::WorkerLost, $job->error_code);
        $this->assertInstanceOf(ImportErrorDetailData::class, $job->error_detail);
        $this->assertSame(['filename' => 'legacy.csv'], $job->error_detail->toArray());
        $this->assertSame($now->toDateTimeString(), $job->claimed_at?->toDateTimeString());
        $this->assertSame($now->toDateTimeString(), $job->worker_started_at?->toDateTimeString());
        $this->assertSame($now->toDateTimeString(), $job->source_purged_at?->toDateTimeString());

        $job->error_detail = ImportErrorDetailData::from(['reason' => 'worker disappeared']);
        $job->save();

        $this->assertSame(
            ['reason' => 'worker disappeared'],
            json_decode((string) DB::table('import_jobs')->where('id', $jobId)->value('error_detail'), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function test_claim_is_single_winner_and_release_restores_the_exact_prior_status(): void
    {
        $job = $this->createJob(ImportStatus::Validated);
        $claims = $this->app->make(ImportJobClaimService::class);

        $winner = $claims->claim($job);
        $loser = $claims->claim($job->refresh());

        $this->assertTrue($winner->won);
        $this->assertSame(ImportStatus::Validated, $winner->priorStatus);
        $this->assertFalse($loser->won);
        $this->assertSame(ImportStatus::Importing, $loser->priorStatus);

        $claimed = $job->refresh();
        $this->assertSame(ImportStatus::Importing, $claimed->status);
        $this->assertNotNull($claimed->claimed_at);
        $this->assertNotNull($claimed->started_at);

        $this->assertTrue($claims->release($claimed, $winner->priorStatus));
        $this->assertFalse($claims->release($claimed->refresh(), $winner->priorStatus));

        $released = $job->refresh();
        $this->assertSame(ImportStatus::Validated, $released->status);
        $this->assertNull($released->claimed_at);
        $this->assertNull($released->started_at);
    }

    public function test_worker_start_is_single_winner_and_blocks_release(): void
    {
        $job = $this->createJob(ImportStatus::Pending);
        $claims = $this->app->make(ImportJobClaimService::class);
        $claim = $claims->claim($job);

        $this->assertTrue($claims->markWorkerStarted($job->id, $job->tenant_id));
        $this->assertFalse($claims->markWorkerStarted($job->id, $job->tenant_id));
        $this->assertFalse($claims->release($job->refresh(), $claim->priorStatus));
        $this->assertNotNull($job->refresh()->worker_started_at);
        $this->assertSame(ImportStatus::Importing, $job->status);
    }

    public function test_losing_finalize_preserves_the_winners_status_counters_and_error_payload(): void
    {
        $logSpy = Log::spy();
        $job = $this->createJob();
        $claims = $this->app->make(ImportJobClaimService::class);
        $claims->claim($job);

        $this->assertTrue($claims->finalize(
            $job->refresh(),
            ImportStatus::Completed,
            new ImportCountersData(totalRows: 4, successfulRows: 2, skippedRows: 1, failedRows: 1),
            null,
            null,
        ));

        $this->assertFalse($claims->finalize(
            $job->refresh(),
            ImportStatus::Failed,
            new ImportCountersData(totalRows: 99, successfulRows: 0, skippedRows: 0, failedRows: 99),
            ImportErrorCode::WorkerLost,
            ImportErrorDetailData::from(['reason' => 'late reaper']),
            'late reaper',
        ));

        $winner = $job->refresh();
        $this->assertSame(ImportStatus::Completed, $winner->status);
        $this->assertSame(4, $winner->total_rows);
        $this->assertSame(2, $winner->successful_rows);
        $this->assertSame(1, $winner->skipped_rows);
        $this->assertSame(1, $winner->failed_rows);
        $this->assertNull($winner->error_code);
        $this->assertNull($winner->error_detail);
        $this->assertNull($winner->error_message);

        $logSpy->shouldHaveReceived('warning', [
            'import_jobs.terminal_write_lost',
            \Mockery::on(
                fn (array $context): bool => $context['id'] === $job->id
                    && $context['attempted_status'] === ImportStatus::Failed->value
                    && is_string($context['writer']),
            ),
        ]);
        $logSpy->shouldHaveReceived('warning', [
            'import_jobs.total_rows_mismatch',
            \Mockery::on(
                fn (array $context): bool => $context['id'] === $job->id
                    && $context['stored_total_rows'] === 0
                    && $context['recomputed_total_rows'] === 4,
            ),
        ]);
    }

    public function test_terminal_counters_are_recomputed_from_row_state_not_stale_job_aggregates(): void
    {
        $job = $this->createJob();
        $job->update(['total_rows' => 99, 'successful_rows' => 88, 'failed_rows' => 11]);

        foreach ([
            ['is_valid' => true, 'is_imported' => true, 'import_error' => null, 'outcome' => ImportRowOutcome::Imported],
            ['is_valid' => false, 'is_imported' => false, 'import_error' => null, 'outcome' => ImportRowOutcome::Failed],
            ['is_valid' => true, 'is_imported' => false, 'import_error' => 'writer failed', 'outcome' => ImportRowOutcome::Failed],
        ] as $index => $state) {
            ImportRow::create([
                'import_job_id' => $job->id,
                'row_number' => $index + 1,
                'data' => ['sku' => 'COUNT-'.($index + 1)],
                ...$state,
            ]);
        }

        $counters = ImportCountersData::fromJob($job);

        $this->assertSame(3, $counters->totalRows);
        $this->assertSame(1, $counters->successfulRows);
        $this->assertSame(0, $counters->skippedRows);
        $this->assertSame(2, $counters->failedRows);
    }

    public function test_execute_refuses_an_already_importing_job_with_conflict_code(): void
    {
        [$tenant, $company, $user] = $this->createAuthorizedContext();
        $job = ImportJob::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ImportType::Products,
            'status' => ImportStatus::Importing,
            'original_filename' => 'products.csv',
            'file_path' => "imports/{$tenant->id}/products.csv",
            'total_rows' => 1,
            'claimed_at' => now(),
            'started_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/imports/{$job->id}/execute")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IMPORT_ALREADY_STARTED');

        $this->assertSame(ImportStatus::Importing, $job->refresh()->status);
        $this->assertSame($company->id, app(CompanyContext::class)->requireCompanyId());
    }

    public function test_failed_job_is_one_shot_and_cannot_be_resumed_or_redispatched(): void
    {
        Queue::fake();
        [$tenant, , $user] = $this->createAuthorizedContext();
        $job = $this->createExecutableJob($tenant, $user, totalRows: 150, sku: 'FAILED-ONCE-1');
        $job->update([
            'status' => ImportStatus::Failed,
            'completed_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/imports/{$job->id}/execute")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'IMPORT_NOT_EXECUTABLE');

        $this->assertSame(ImportStatus::Failed, $job->refresh()->status);
        $this->assertNull($job->claimed_at);
        Queue::assertNothingPushed();
    }

    public function test_synchronous_execute_claims_marks_worker_started_and_finalizes_once(): void
    {
        [$tenant, $company, $user] = $this->createAuthorizedContext();
        $job = ImportJob::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ImportType::Products,
            'status' => ImportStatus::Validated,
            'original_filename' => 'products.csv',
            'file_path' => "imports/{$tenant->id}/products.csv",
            'total_rows' => 1,
            'successful_rows' => 1,
        ]);
        ImportRow::create([
            'import_job_id' => $job->id,
            'row_number' => 1,
            'data' => [
                'name' => 'Claimed Product',
                'sku' => 'CLAIM-SYNC-1',
                'type' => 'part',
                'sale_price' => '10.00',
                'purchase_price' => '5.00',
            ],
            'is_valid' => true,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/imports/{$job->id}/execute")
            ->assertOk();

        $terminal = $job->refresh();
        $this->assertSame(ImportStatus::Completed, $terminal->status);
        $this->assertNotNull($terminal->claimed_at);
        $this->assertNotNull($terminal->worker_started_at);
        $this->assertNotNull($terminal->completed_at);
        $this->assertSame(1, $terminal->successful_rows);
        $this->assertSame(0, $terminal->skipped_rows);
        $this->assertSame(0, $terminal->failed_rows);
        $this->assertSame($company->id, app(CompanyContext::class)->requireCompanyId());
    }

    public function test_paginated_query_is_tenant_scoped_newest_first_and_honors_page_size(): void
    {
        $tenantId = (string) Str::uuid();
        $otherTenantId = (string) Str::uuid();
        $oldest = $this->createJobForTenant($tenantId, now()->subMinutes(3));
        $middle = $this->createJobForTenant($tenantId, now()->subMinutes(2));
        $newest = $this->createJobForTenant($tenantId, now()->subMinute());
        $this->createJobForTenant($otherTenantId, now());

        $page = $this->app->make(ImportService::class)->paginateJobs(
            tenantId: $tenantId,
            page: 1,
            perPage: 2,
        );

        $this->assertSame([$newest->id, $middle->id], $page->getCollection()->pluck('id')->all());
        $this->assertSame(3, $page->total());
        $this->assertSame(2, $page->perPage());
        $this->assertSame(1, $page->currentPage());
        $this->assertNotContains($oldest->id, $page->getCollection()->pluck('id')->all());
    }

    public function test_delete_discards_non_running_job_rows_and_source_file(): void
    {
        Storage::fake('local');
        [$tenant, $company, $user] = $this->createAuthorizedContext();
        $job = ImportJob::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ImportType::Products,
            'status' => ImportStatus::Validated,
            'original_filename' => 'discard.csv',
            'file_path' => "imports/{$tenant->id}/discard.csv",
            'total_rows' => 1,
        ]);
        $row = ImportRow::create([
            'import_job_id' => $job->id,
            'row_number' => 1,
            'data' => ['sku' => 'DISCARD-1'],
            'is_valid' => true,
        ]);
        Storage::disk('local')->put($job->file_path, 'discard me');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/imports/{$job->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('import_jobs', ['id' => $job->id]);
        $this->assertDatabaseMissing('import_rows', ['id' => $row->id]);
        Storage::disk('local')->assertMissing($job->file_path);
        $this->assertSame($company->id, app(CompanyContext::class)->requireCompanyId());
    }

    public function test_delete_also_discards_pending_and_completed_jobs_without_posted_effects(): void
    {
        Storage::fake('local');
        [$tenant, , $user] = $this->createAuthorizedContext();

        foreach ([ImportStatus::Pending, ImportStatus::Completed] as $status) {
            $job = ImportJob::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'type' => ImportType::Products,
                'status' => $status,
                'original_filename' => $status->value.'.csv',
                'file_path' => "imports/{$tenant->id}/{$status->value}.csv",
                'total_rows' => 0,
            ]);
            Storage::disk('local')->put($job->file_path, $status->value);

            $this->actingAs($user, 'sanctum')
                ->deleteJson("/api/v1/imports/{$job->id}")
                ->assertNoContent();

            $this->assertDatabaseMissing('import_jobs', ['id' => $job->id]);
            Storage::disk('local')->assertMissing($job->file_path);
        }
    }

    public function test_delete_refuses_importing_job_without_removing_rows_or_file(): void
    {
        Storage::fake('local');
        [$tenant, , $user] = $this->createAuthorizedContext();
        $job = ImportJob::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ImportType::Products,
            'status' => ImportStatus::Importing,
            'original_filename' => 'running.csv',
            'file_path' => "imports/{$tenant->id}/running.csv",
            'total_rows' => 1,
            'claimed_at' => now(),
        ]);
        $row = ImportRow::create([
            'import_job_id' => $job->id,
            'row_number' => 1,
            'data' => ['sku' => 'RUNNING-1'],
            'is_valid' => true,
        ]);
        Storage::disk('local')->put($job->file_path, 'running');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/imports/{$job->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IMPORT_HAS_EFFECTS')
            ->assertJsonPath(
                'error.message',
                'This import cannot be discarded because it is running or has posted effects. Use the opening-balance reset or stock adjustment flows to correct posted data.',
            );

        $this->assertDatabaseHas('import_jobs', ['id' => $job->id]);
        $this->assertDatabaseHas('import_rows', ['id' => $row->id]);
        Storage::disk('local')->assertExists($job->file_path);
    }

    public function test_delete_refuses_completed_job_with_posted_effects(): void
    {
        Storage::fake('local');
        [$tenant, , $user] = $this->createAuthorizedContext();
        $job = ImportJob::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ImportType::OpeningBalances,
            'status' => ImportStatus::Completed,
            'original_filename' => 'posted-opening-balances.csv',
            'file_path' => "imports/{$tenant->id}/posted-opening-balances.csv",
            'total_rows' => 1,
            'successful_rows' => 1,
            'completed_at' => now(),
        ]);
        Storage::disk('local')->put($job->file_path, 'posted effects');

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/imports/{$job->id}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IMPORT_HAS_EFFECTS')
            ->assertJsonPath(
                'error.message',
                'This import cannot be discarded because it is running or has posted effects. Use the opening-balance reset or stock adjustment flows to correct posted data.',
            );

        $this->assertDatabaseHas('import_jobs', ['id' => $job->id]);
        Storage::disk('local')->assertExists($job->file_path);
    }

    public function test_delete_requires_imports_manage_and_hides_cross_tenant_jobs(): void
    {
        [$tenantA, $companyA, $adminA] = $this->createAuthorizedContext();
        $tenantB = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-'.Str::lower(Str::random(8)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $job = ImportJob::create([
            'tenant_id' => $tenantB->id,
            'user_id' => (string) Str::uuid(),
            'type' => ImportType::Products,
            'status' => ImportStatus::Pending,
            'original_filename' => 'other.csv',
            'file_path' => "imports/{$tenantB->id}/other.csv",
            'total_rows' => 0,
        ]);

        $unprivileged = User::create([
            'tenant_id' => $tenantA->id,
            'name' => 'Unprivileged User',
            'email' => 'unprivileged-'.Str::lower(Str::random(8)).'@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        UserCompanyMembership::create([
            'user_id' => $unprivileged->id,
            'company_id' => $companyA->id,
            'role' => 'viewer',
        ]);

        $this->actingAs($unprivileged, 'sanctum')
            ->deleteJson("/api/v1/imports/{$job->id}")
            ->assertForbidden();

        $this->actingAs($adminA, 'sanctum')
            ->deleteJson("/api/v1/imports/{$job->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('import_jobs', ['id' => $job->id]);
    }

    public function test_zero_valid_rows_releases_claim_to_exact_prior_status(): void
    {
        [$tenant, , $user] = $this->createAuthorizedContext();
        $job = ImportJob::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ImportType::Products,
            'status' => ImportStatus::Validated,
            'original_filename' => 'invalid.csv',
            'file_path' => "imports/{$tenant->id}/invalid.csv",
            'total_rows' => 1,
            'failed_rows' => 1,
        ]);
        ImportRow::create([
            'import_job_id' => $job->id,
            'row_number' => 1,
            'data' => ['sku' => 'INVALID'],
            'is_valid' => false,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/imports/{$job->id}/execute")
            ->assertStatus(422)
            ->assertJson([
                'error' => 'Import cannot be started. No valid rows to import.',
                'failed_rows' => 1,
                'valid_rows' => 0,
            ]);

        $released = $job->refresh();
        $this->assertSame(ImportStatus::Validated, $released->status);
        $this->assertNull($released->claimed_at);
        $this->assertNull($released->started_at);
        $this->assertNull($released->worker_started_at);
    }

    public function test_direct_execute_with_zero_valid_rows_releases_self_claim_to_exact_prior_status(): void
    {
        [$tenant, , $user] = $this->createAuthorizedContext();
        $job = ImportJob::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ImportType::Products,
            'status' => ImportStatus::Pending,
            'original_filename' => 'direct-invalid.csv',
            'file_path' => "imports/{$tenant->id}/direct-invalid.csv",
            'total_rows' => 1,
            'failed_rows' => 1,
        ]);
        ImportRow::create([
            'import_job_id' => $job->id,
            'row_number' => 1,
            'data' => ['sku' => 'DIRECT-INVALID'],
            'is_valid' => false,
        ]);

        try {
            $this->app->make(ImportService::class)->executeImport($job);
            self::fail('A direct import with zero valid rows must be refused.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Import cannot be started. No valid rows to import.', $exception->getMessage());
        }

        $released = $job->refresh();
        $this->assertSame(ImportStatus::Pending, $released->status);
        $this->assertNull($released->claimed_at);
        $this->assertNull($released->started_at);
        $this->assertNull($released->worker_started_at);
    }

    public function test_async_execute_claims_once_and_duplicate_request_gets_conflict(): void
    {
        Queue::fake();
        [$tenant, , $user] = $this->createAuthorizedContext();
        $job = $this->createExecutableJob($tenant, $user, totalRows: 150, sku: 'ASYNC-CLAIM-1');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/imports/{$job->id}/execute")
            ->assertStatus(202);
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/imports/{$job->id}/execute")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IMPORT_ALREADY_STARTED');

        $claimed = $job->refresh();
        $this->assertSame(ImportStatus::Importing, $claimed->status);
        $this->assertNotNull($claimed->claimed_at);
        $this->assertNull($claimed->worker_started_at);
    }

    public function test_dispatch_failure_leaves_first_clock_for_reaper(): void
    {
        [$tenant, , $user] = $this->createAuthorizedContext();
        $job = $this->createExecutableJob($tenant, $user, totalRows: 150, sku: 'DISPATCH-FAIL-1');
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $expectation = $dispatcher->shouldReceive('dispatch');
        if (! $expectation instanceof CompositeExpectation) {
            self::fail('Mockery must return a composite expectation.');
        }
        $expectation->__call('andThrow', [new \RuntimeException('queue unavailable')]);
        $this->app->instance(Dispatcher::class, $dispatcher);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/imports/{$job->id}/execute")
            ->assertStatus(500);

        $claimed = $job->refresh();
        $this->assertSame(ImportStatus::Importing, $claimed->status);
        $this->assertNotNull($claimed->claimed_at);
        $this->assertNull($claimed->worker_started_at);
    }

    public function test_worker_double_delivery_exits_without_reprocessing_rows(): void
    {
        [$tenant, $company, $user] = $this->createAuthorizedContext();
        $job = $this->createExecutableJob($tenant, $user, totalRows: 1, sku: 'DOUBLE-DELIVERY-1');
        $claims = $this->app->make(ImportJobClaimService::class);
        $this->assertTrue($claims->claim($job)->won);
        $worker = new ProcessImportJob($job->id, $company->id, $tenant->id);

        $worker->handle(
            $this->app->make(ImportService::class),
            $this->app->make(UnitsProvisioningService::class),
        );
        $worker->handle(
            $this->app->make(ImportService::class),
            $this->app->make(UnitsProvisioningService::class),
        );

        $this->assertSame(ImportStatus::Completed, $job->refresh()->status);
        $this->assertSame(1, Product::query()->where('company_id', $company->id)->where('sku', 'DOUBLE-DELIVERY-1')->count());
        $this->assertSame(1, ImportRow::query()->where('import_job_id', $job->id)->where('is_imported', true)->count());
    }

    public function test_pending_legacy_worker_delivery_claims_before_marking_worker_started(): void
    {
        [$tenant, $company, $user] = $this->createAuthorizedContext();
        $job = $this->createExecutableJob($tenant, $user, totalRows: 1, sku: 'LEGACY-PENDING-1');
        $job->update(['status' => ImportStatus::Pending]);

        (new ProcessImportJob($job->id, $company->id, $tenant->id))->handle(
            $this->app->make(ImportService::class),
            $this->app->make(UnitsProvisioningService::class),
        );

        $completed = $job->refresh();
        $this->assertSame(ImportStatus::Completed, $completed->status);
        $this->assertNotNull($completed->claimed_at);
        $this->assertNotNull($completed->worker_started_at);
        $this->assertSame(1, Product::query()->where('company_id', $company->id)->where('sku', 'LEGACY-PENDING-1')->count());
    }

    public function test_true_duplicate_delivery_with_started_worker_logs_and_does_not_process_rows(): void
    {
        $logSpy = Log::spy();
        [$tenant, $company, $user] = $this->createAuthorizedContext();
        $job = $this->createExecutableJob($tenant, $user, totalRows: 1, sku: 'STARTED-DUPLICATE-1');
        $job->update([
            'status' => ImportStatus::Importing,
            'claimed_at' => now(),
            'started_at' => now(),
            'worker_started_at' => now(),
        ]);

        (new ProcessImportJob($job->id, $company->id, $tenant->id))->handle(
            $this->app->make(ImportService::class),
            $this->app->make(UnitsProvisioningService::class),
        );

        $this->assertSame(ImportStatus::Importing, $job->refresh()->status);
        $this->assertSame(0, Product::query()->where('company_id', $company->id)->where('sku', 'STARTED-DUPLICATE-1')->count());
        $this->assertSame(0, ImportRow::query()->where('import_job_id', $job->id)->where('is_imported', true)->count());
        $logSpy->shouldHaveReceived('warning', [
            'ProcessImportJob: Duplicate delivery ignored',
            \Mockery::on(
                fn (array $context): bool => $context['id'] === $job->id
                    && $context['status'] === ImportStatus::Importing->value,
            ),
        ]);
    }

    public function test_new_import_artifact_routes_return_not_found_for_malformed_uuid(): void
    {
        [, , $user] = $this->createAuthorizedContext();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/imports/not-a-uuid/source-file')
            ->assertNotFound();

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/imports/not-a-uuid')
            ->assertNotFound();
    }

    public function test_two_real_postgresql_connections_have_exactly_one_claim_winner(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The two-connection import claim race requires PostgreSQL.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required for the two-connection import claim race.');
        }

        $connectionConfig = Config::get('database.connections.pgsql');
        self::assertIsArray($connectionConfig);
        Config::set('database.connections.'.self::CLAIM_CLEANUP_CONNECTION, $connectionConfig);

        $jobId = (string) Str::uuid();
        $tenantId = (string) Str::uuid();
        $now = now();
        DB::connection(self::CLAIM_CLEANUP_CONNECTION)->table('import_jobs')->insert([
            'id' => $jobId,
            'tenant_id' => $tenantId,
            'user_id' => (string) Str::uuid(),
            'type' => ImportType::Products->value,
            'status' => ImportStatus::Validated->value,
            'original_filename' => 'two-connection.csv',
            'file_path' => "imports/{$tenantId}/two-connection.csv",
            'total_rows' => 0,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'skipped_rows' => 0,
            'failed_rows' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::disconnect(self::CLAIM_CLEANUP_CONNECTION);

        // RefreshDatabase owns the default connection's outer transaction.
        // This test has written no fixtures through it, so close that empty
        // transaction before forking: a child must never inherit and terminate
        // the parent's PostgreSQL socket. Recreate the expected outer
        // transaction in finally for the trait's teardown callback.
        DB::commit();
        DB::disconnect();

        $firstResult = tempnam(sys_get_temp_dir(), 'import-claim-a-');
        $secondResult = tempnam(sys_get_temp_dir(), 'import-claim-b-');
        self::assertIsString($firstResult);
        self::assertIsString($secondResult);
        $first = $this->spawnClaimChild($jobId, $tenantId, $firstResult);
        $second = $this->spawnClaimChild($jobId, $tenantId, $secondResult);

        try {
            fwrite($first['socket'], '1');
            fwrite($second['socket'], '1');

            pcntl_waitpid($first['pid'], $firstStatus);
            pcntl_waitpid($second['pid'], $secondStatus);
            self::assertTrue(pcntl_wifexited($firstStatus));
            self::assertTrue(pcntl_wifexited($secondStatus));
            self::assertSame(0, pcntl_wexitstatus($firstStatus));
            self::assertSame(0, pcntl_wexitstatus($secondStatus));

            $payloads = [
                json_decode((string) file_get_contents($firstResult), true, 512, JSON_THROW_ON_ERROR),
                json_decode((string) file_get_contents($secondResult), true, 512, JSON_THROW_ON_ERROR),
            ];
            self::assertNotSame($payloads[0]['backend_pid'] ?? null, $payloads[1]['backend_pid'] ?? null);
            self::assertSame(1, count(array_filter($payloads, static fn (array $payload): bool => $payload['won'] === true)));
            self::assertSame(
                ImportStatus::Importing->value,
                DB::connection(self::CLAIM_CLEANUP_CONNECTION)->table('import_jobs')->where('id', $jobId)->value('status'),
            );
        } finally {
            fclose($first['socket']);
            fclose($second['socket']);
            if (is_file($firstResult)) {
                unlink($firstResult);
            }
            if (is_file($secondResult)) {
                unlink($secondResult);
            }
            DB::reconnect(self::CLAIM_CLEANUP_CONNECTION);
            DB::connection(self::CLAIM_CLEANUP_CONNECTION)->table('import_jobs')->where('id', $jobId)->delete();
            DB::purge(self::CLAIM_CLEANUP_CONNECTION);
            DB::purge();
            DB::reconnect();
            DB::beginTransaction();
        }
    }

    /**
     * @return array{pid: int, socket: resource}
     */
    private function spawnClaimChild(string $jobId, string $tenantId, string $resultFile): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);
        [$parentSocket, $childSocket] = $sockets;

        $pid = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            fclose($parentSocket);
            DB::disconnect();

            try {
                DB::reconnect();
                stream_set_timeout($childSocket, 8);
                if (fread($childSocket, 1) !== '1') {
                    throw new \RuntimeException('Claim child did not receive its bounded start signal.');
                }

                $backend = DB::selectOne('SELECT pg_backend_pid() AS pid');
                $job = ImportJob::query()
                    ->where('tenant_id', $tenantId)
                    ->where('id', $jobId)
                    ->firstOrFail();
                $claim = $this->app->make(ImportJobClaimService::class)->claim($job);
                $payload = [
                    'backend_pid' => $backend?->pid,
                    'won' => $claim->won,
                    'prior_status' => $claim->priorStatus->value,
                ];
            } catch (\Throwable $exception) {
                $payload = [
                    'backend_pid' => null,
                    'won' => false,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ];
            }

            file_put_contents($resultFile, json_encode($payload, JSON_THROW_ON_ERROR));
            fclose($childSocket);
            DB::disconnect();
            exit(0);
        }

        fclose($childSocket);

        return ['pid' => $pid, 'socket' => $parentSocket];
    }

    private function createJob(ImportStatus $status = ImportStatus::Validated): ImportJob
    {
        return ImportJob::create([
            'tenant_id' => (string) Str::uuid(),
            'user_id' => (string) Str::uuid(),
            'type' => ImportType::Products,
            'status' => $status,
            'original_filename' => 'products.csv',
            'file_path' => 'imports/'.Str::uuid().'/products.csv',
            'total_rows' => 0,
        ]);
    }

    private function createJobForTenant(string $tenantId, \DateTimeInterface $createdAt): ImportJob
    {
        $job = ImportJob::create([
            'tenant_id' => $tenantId,
            'user_id' => (string) Str::uuid(),
            'type' => ImportType::Products,
            'status' => ImportStatus::Validated,
            'original_filename' => 'products.csv',
            'file_path' => "imports/{$tenantId}/".Str::uuid().'.csv',
            'total_rows' => 0,
        ]);
        DB::table('import_jobs')->where('id', $job->id)->update([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $job->refresh();
    }

    private function createExecutableJob(Tenant $tenant, User $user, int $totalRows, string $sku): ImportJob
    {
        $job = ImportJob::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'type' => ImportType::Products,
            'status' => ImportStatus::Validated,
            'original_filename' => 'products.csv',
            'file_path' => "imports/{$tenant->id}/{$sku}.csv",
            'total_rows' => $totalRows,
            'successful_rows' => 1,
        ]);
        ImportRow::create([
            'import_job_id' => $job->id,
            'row_number' => 1,
            'data' => [
                'name' => "Product {$sku}",
                'sku' => $sku,
                'type' => 'part',
                'sale_price' => '10.00',
                'purchase_price' => '5.00',
            ],
            'is_valid' => true,
        ]);

        return $job;
    }

    /**
     * @return array{Tenant, Company, User}
     */
    private function createAuthorizedContext(): array
    {
        $tenant = Tenant::create([
            'name' => 'Claim Tenant',
            'slug' => 'claim-'.Str::lower(Str::random(8)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Claim Company',
            'legal_name' => 'Claim Company LLC',
            'tax_id' => 'CLAIM-'.Str::upper(Str::random(8)),
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Claim User',
            'email' => 'claim-'.Str::lower(Str::random(8)).'@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($company->id);
        app(UnitsProvisioningService::class)->provisionForCompany($company);

        return [$tenant, $company, $user];
    }
}
