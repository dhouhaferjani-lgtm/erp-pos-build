<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReapStuckImportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unstarted_claim_older_than_ninety_minutes_is_reaped_as_worker_lost(): void
    {
        $tenant = $this->createTenant('reap-old-claim');
        $job = $this->createJob($tenant, [
            'claimed_at' => now()->subMinutes(91),
            'worker_started_at' => null,
        ]);

        $this->assertSame(0, Artisan::call('imports:reap-stuck'));

        $reaped = $job->refresh();
        $this->assertSame(ImportStatus::Failed, $reaped->status);
        $this->assertSame(ImportErrorCode::WorkerLost, $reaped->error_code);
        $this->assertNotNull($reaped->completed_at);
    }

    public function test_claim_younger_than_ninety_minutes_is_untouched(): void
    {
        $tenant = $this->createTenant('reap-young-claim');
        $job = $this->createJob($tenant, ['claimed_at' => now()->subMinutes(89)]);

        Artisan::call('imports:reap-stuck');

        $this->assertSame(ImportStatus::Importing, $job->refresh()->status);
        $this->assertNull($job->error_code);
    }

    public function test_recent_worker_clock_wins_over_an_old_claim_clock(): void
    {
        $tenant = $this->createTenant('reap-late-start');
        $job = $this->createJob($tenant, [
            'claimed_at' => now()->subMinutes(91),
            'worker_started_at' => now()->subMinutes(2),
        ]);

        Artisan::call('imports:reap-stuck');

        $this->assertSame(ImportStatus::Importing, $job->refresh()->status);
        $this->assertNull($job->error_code);
    }

    public function test_started_worker_older_than_ninety_minutes_is_reaped(): void
    {
        $tenant = $this->createTenant('reap-old-worker');
        $job = $this->createJob($tenant, [
            'claimed_at' => now()->subMinutes(120),
            'worker_started_at' => now()->subMinutes(91),
        ]);

        Artisan::call('imports:reap-stuck');

        $this->assertSame(ImportStatus::Failed, $job->refresh()->status);
        $this->assertSame(ImportErrorCode::WorkerLost, $job->error_code);
    }

    public function test_sweep_is_tenant_scoped_and_never_touches_non_importing_statuses(): void
    {
        $tenantA = $this->createTenant('reap-tenant-a');
        $tenantB = $this->createTenant('reap-tenant-b');
        $old = ['claimed_at' => now()->subMinutes(91), 'worker_started_at' => null];
        $staleA = $this->createJob($tenantA, $old);
        $staleB = $this->createJob($tenantB, $old);
        $orphan = $this->createJobForTenantId((string) Str::uuid(), $old);

        $protected = [];
        foreach ([ImportStatus::Pending, ImportStatus::Completed, ImportStatus::Failed] as $status) {
            $protected[] = $this->createJob($tenantA, [...$old, 'status' => $status]);
        }

        Artisan::call('imports:reap-stuck');

        $this->assertSame(ImportStatus::Failed, $staleA->refresh()->status);
        $this->assertSame(ImportStatus::Failed, $staleB->refresh()->status);
        $this->assertSame(ImportStatus::Importing, $orphan->refresh()->status);
        foreach ($protected as $index => $job) {
            $this->assertSame(
                [ImportStatus::Pending, ImportStatus::Completed, ImportStatus::Failed][$index],
                $job->refresh()->status,
            );
        }
    }

    public function test_worker_terminal_write_wins_atomically_over_reaper_and_preserves_counters(): void
    {
        $logSpy = Log::spy();
        $tenant = $this->createTenant('reap-terminal-race');
        $job = $this->createJob($tenant, [
            'claimed_at' => now()->subMinutes(91),
            'worker_started_at' => null,
            'total_rows' => 5,
        ]);

        $won = false;
        ImportJob::retrieved(function (ImportJob $retrieved) use ($job, &$won): void {
            if ($won || $retrieved->id !== $job->id) {
                return;
            }
            $won = true;
            DB::table('import_jobs')
                ->where('id', $job->id)
                ->where('tenant_id', $job->tenant_id)
                ->where('status', ImportStatus::Importing->value)
                ->update([
                    'status' => ImportStatus::Completed->value,
                    'successful_rows' => 4,
                    'skipped_rows' => 1,
                    'failed_rows' => 0,
                    'completed_at' => now(),
                ]);
        });

        Artisan::call('imports:reap-stuck');

        $terminal = $job->refresh();
        $this->assertSame(ImportStatus::Completed, $terminal->status);
        $this->assertSame(4, $terminal->successful_rows);
        $this->assertSame(1, $terminal->skipped_rows);
        $this->assertSame(0, $terminal->failed_rows);
        $this->assertNull($terminal->error_code);

        $logSpy->shouldHaveReceived('warning', [
            'import_jobs.terminal_write_lost',
            \Mockery::on(
                fn (array $context): bool => $context['id'] === $job->id
                    && $context['attempted_status'] === ImportStatus::Failed->value,
            ),
        ]);
    }

    public function test_sweep_reaps_every_candidate_across_more_than_two_chunks(): void
    {
        $tenant = $this->createTenant('reap-keyset-pages');
        $now = now();
        $rows = [];

        for ($index = 0; $index < 2001; $index++) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'user_id' => (string) Str::uuid(),
                'type' => ImportType::Products->value,
                'status' => ImportStatus::Importing->value,
                'original_filename' => "reap-{$index}.csv",
                'file_path' => "imports/{$tenant->id}/reap-{$index}.csv",
                'total_rows' => 0,
                'processed_rows' => 0,
                'successful_rows' => 0,
                'skipped_rows' => 0,
                'failed_rows' => 0,
                'claimed_at' => $now->copy()->subMinutes(91),
                'started_at' => $now->copy()->subMinutes(91),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('import_jobs')->insert($chunk);
        }

        $this->assertSame(0, Artisan::call('imports:reap-stuck'));
        $this->assertSame(
            2001,
            ImportJob::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', ImportStatus::Failed->value)
                ->where('error_code', ImportErrorCode::WorkerLost->value)
                ->count(),
        );
    }

    private function createTenant(string $slug): Tenant
    {
        return Tenant::create([
            'name' => str_replace('-', ' ', $slug),
            'slug' => $slug,
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createJob(Tenant $tenant, array $overrides = []): ImportJob
    {
        return $this->createJobForTenantId($tenant->id, $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createJobForTenantId(string $tenantId, array $overrides = []): ImportJob
    {
        return ImportJob::create([
            'tenant_id' => $tenantId,
            'user_id' => (string) Str::uuid(),
            'type' => ImportType::Products,
            'status' => ImportStatus::Importing,
            'original_filename' => 'products.csv',
            'file_path' => "imports/{$tenantId}/products.csv",
            'total_rows' => 0,
            'claimed_at' => now(),
            'started_at' => now(),
            ...$overrides,
        ]);
    }
}
