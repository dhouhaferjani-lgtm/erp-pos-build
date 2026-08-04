<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Infrastructure\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Notifications\CriticalBatchExpiryNotification;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

/**
 * Daily batch expiry check.
 *
 * Per tenant:
 * 1. Mark expired batches as expired (is_expired = true)
 * 2. Collect batches approaching expiry (30-day and 7-day windows)
 * 3. Notify company admins about the critical (7-day) batches
 * 4. Log a summary for the audit trail
 *
 * Scheduled daily at 01:30 (see routes/console.php). Replaces the deleted
 * `DailyExpiryCheck` queue job, which ran the sweep from CENTRAL context: under
 * database-per-tenant (staging/prod since 2026-06-19) the central database has
 * no `product_batches` table, so the job failed every night and retried to
 * MaxAttemptsExceeded.
 *
 * Tenant-isolation: cat-(a-per-tenant-iter). Per master plan §14 invariant 2,
 * schedulers MUST iterate explicitly per tenant; they MUST NOT issue cross-
 * tenant queries from the command body. Every batch query below additionally
 * carries an explicit `tenant_id` predicate: redundant under database-per-
 * tenant (the connection is already the tenant's own database), REQUIRED in
 * legacy row-level mode (`tenancy_resolver.db_per_tenant=false` — the test
 * suite and pre-flip compat), where `forEachTenant()` runs the closure N times
 * against the single shared database. Without the predicate each tenant's pass
 * would re-notify every other tenant's admins, N times per night.
 *
 * Spatie permission teams: the registrar's team id is tenant-blind cache state
 * (see memory project_spatie_permission_cache_tenant_blind), so the recipient
 * lookup sets the team id to the CLOSURE's tenant, forgets the cached
 * permissions, and restores the caller's team id in a `finally`.
 */
final class BatchExpiryDailyCheckCommand extends TenantScopedCommand
{
    private const int APPROACHING_DAYS = 30;

    private const int CRITICAL_DAYS = 7;

    /** @var string */
    protected $signature = 'batch-expiry:daily-check';

    /** @var string */
    protected $description = 'Mark expired product batches and alert company admins about batches expiring within 7 days';

    public function __construct(
        CompanyContext $companyContext,
        private readonly PermissionRegistrar $permissionRegistrar,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $totalExpired = 0;
        $totalCritical = 0;
        $totalApproaching = 0;

        $exit = $this->forEachTenant(function (Tenant $tenant) use (
            &$totalExpired,
            &$totalCritical,
            &$totalApproaching,
        ): int {
            $today = now()->startOfDay();

            $expiredCount = $this->markExpiredBatches($tenant->id, $today);
            $approachingExpiry = $this->getBatchesApproachingExpiry($tenant->id, $today, self::APPROACHING_DAYS);
            $criticalBatches = $this->getBatchesApproachingExpiry($tenant->id, $today, self::CRITICAL_DAYS);

            Log::info('Daily batch expiry check completed for tenant', [
                'tenant_id' => $tenant->id,
                'expired_marked' => $expiredCount,
                'approaching_expiry_30d' => $approachingExpiry->count(),
                'critical_7d' => $criticalBatches->count(),
            ]);

            if ($criticalBatches->isNotEmpty()) {
                $this->notifyCompaniesOfCriticalBatches($tenant, $criticalBatches);
            }

            $totalExpired += $expiredCount;
            $totalCritical += $criticalBatches->count();
            $totalApproaching += $approachingExpiry->count();

            return self::SUCCESS;
        });

        $this->info(sprintf(
            'Batch expiry check: %d batch(es) marked expired, %d critical (<=%dd), %d approaching (<=%dd).',
            $totalExpired,
            $totalCritical,
            self::CRITICAL_DAYS,
            $totalApproaching,
            self::APPROACHING_DAYS,
        ));

        return $exit;
    }

    /**
     * Mark this tenant's expired batches as expired.
     *
     * Updates batches where expiry_date < today and is_expired = false.
     *
     * @return int Number of batches marked as expired
     */
    private function markExpiredBatches(string $tenantId, Carbon $today): int
    {
        return DB::transaction(function () use ($tenantId, $today): int {
            $expiredBatches = Batch::where('tenant_id', $tenantId)
                ->where('expiry_date', '<', $today)
                ->where('is_expired', false)
                ->where('is_active', true)
                ->get();

            $count = 0;
            foreach ($expiredBatches as $batch) {
                $batch->update(['is_expired' => true]);

                Log::warning('Batch marked as expired', [
                    'tenant_id' => $tenantId,
                    'batch_id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'product_id' => $batch->product_id,
                    'company_id' => $batch->company_id,
                    'expiry_date' => $batch->expiry_date->toDateString(),
                ]);

                $count++;
            }

            return $count;
        });
    }

    /**
     * Get this tenant's batches approaching expiry within the threshold.
     *
     * @param  int  $daysThreshold  Number of days threshold
     * @return Collection<int, Batch>
     */
    private function getBatchesApproachingExpiry(string $tenantId, Carbon $today, int $daysThreshold): Collection
    {
        $thresholdDate = $today->copy()->addDays($daysThreshold);

        return Batch::where('tenant_id', $tenantId)
            ->whereBetween('expiry_date', [$today, $thresholdDate])
            ->where('is_active', true)
            ->where('is_recalled', false)
            ->where('is_expired', false)
            ->with(['product', 'company'])
            ->get()
            ->toBase();
    }

    /**
     * Notify company admins about batches in the critical expiry window.
     *
     * Groups batches by company and sends one notification per company.
     *
     * @param  Collection<int, Batch>  $criticalBatches
     */
    private function notifyCompaniesOfCriticalBatches(Tenant $tenant, Collection $criticalBatches): void
    {
        $batchesByCompany = $criticalBatches->groupBy('company_id');

        $originalTeamId = $this->permissionRegistrar->getPermissionsTeamId();

        try {
            foreach ($batchesByCompany as $companyId => $batches) {
                $firstBatch = $batches->first();
                if ($firstBatch === null) {
                    continue;
                }

                $company = $firstBatch->company;
                $companyId = (string) $companyId;

                Log::info('Critical batches found for company', [
                    'tenant_id' => $tenant->id,
                    'company_id' => $companyId,
                    'company_name' => $company->name ?? 'Unknown',
                    'batch_count' => $batches->count(),
                    'batches' => $batches->map(fn (Batch $batch): array => [
                        'batch_number' => $batch->batch_number,
                        'product_name' => $batch->product->name ?? 'Unknown',
                        'expiry_date' => $batch->expiry_date->toDateString(),
                        'days_until_expiry' => $batch->daysUntilExpiry(),
                    ])->toArray(),
                ]);

                // The team id MUST come from the closure's tenant, not from the
                // batch row: the row is only reachable because the closure is
                // already bound to that tenant, and trusting the row would
                // re-introduce a cross-tenant permission read.
                $this->permissionRegistrar->setPermissionsTeamId($tenant->id);
                $this->permissionRegistrar->forgetCachedPermissions();

                $admins = User::query()
                    ->where('tenant_id', $tenant->id)
                    ->whereHas('companyMemberships', function ($query) use ($companyId): void {
                        $query->whereRaw('company_id = ?', [$companyId])
                            ->whereRaw('status = ?', ['active']);
                    })
                    ->permission('batches.view')
                    ->get();

                if ($admins->isNotEmpty()) {
                    Notification::send($admins, new CriticalBatchExpiryNotification($batches));

                    Log::info('Sent critical batch expiry notifications', [
                        'tenant_id' => $tenant->id,
                        'company_id' => $companyId,
                        'admin_count' => $admins->count(),
                        'batch_count' => $batches->count(),
                    ]);
                }
            }
        } finally {
            $this->permissionRegistrar->setPermissionsTeamId($originalTeamId);
        }
    }
}
