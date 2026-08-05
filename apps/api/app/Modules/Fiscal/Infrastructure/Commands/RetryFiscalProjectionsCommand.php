<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Application\Jobs\ApplyFiscalEventProjectionJob;
use App\Modules\Fiscal\Application\Services\FiscalEventProjectionRegistry;
use App\Modules\Fiscal\Domain\Enums\ProjectionStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEventProjectionRow;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Operator recovery for exhausted or dead-lettered fiscal projections.
 */
final class RetryFiscalProjectionsCommand extends TenantScopedCommand
{
    private const EXHAUSTED_ATTEMPTS = 5;

    /** @var string */
    protected $signature = 'fiscal:retry-projections
        {--projector= : restrict to one fiscal_event_projections.projector_name}
        {--event-id= : restrict to one fiscal_events.id}
        {--tenant= : restrict tenant iteration to one tenant id}
        {--limit=100 : maximum rows to reset}
        {--min-age-minutes=16 : only retry rows whose projection state has been stable for at least this many minutes}
        {--dry-run : show matching rows without changing or dispatching anything}
        {--sync : execute each reset row inline via ApplyFiscalEventProjectionJob::handle()}';

    /** @var string */
    protected $description = 'Reset and retry exhausted or dead-lettered fiscal_event_projections rows.';

    public function __construct(
        CompanyContext $companyContext,
        private readonly DatabaseManager $database,
        private readonly FiscalEventProjectionRegistry $registry,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $limit = $this->integerOption('limit', minimum: 1);
        if ($limit === null) {
            $this->error('--limit must be a positive integer.');

            return self::FAILURE;
        }

        $minAgeMinutes = $this->integerOption('min-age-minutes', minimum: 0);
        if ($minAgeMinutes === null) {
            $this->error('--min-age-minutes must be a non-negative integer.');

            return self::FAILURE;
        }

        $tenantFilter = $this->stringOption('tenant');
        $remaining = $limit;
        $matchedCount = 0;

        $resetCount = 0;
        $dispatchedCount = 0;
        $syncAppliedCount = 0;
        $failureCount = 0;

        $exit = $this->forEachTenant(function (Tenant $tenant) use (
            $tenantFilter,
            $minAgeMinutes,
            &$remaining,
            &$matchedCount,
            &$resetCount,
            &$dispatchedCount,
            &$syncAppliedCount,
            &$failureCount,
        ): int {
            if ($tenantFilter !== null && $tenant->id !== $tenantFilter) {
                return self::SUCCESS;
            }

            if ($remaining <= 0) {
                return self::SUCCESS;
            }

            $rowIds = $this->candidateRowIds($tenant->id, $remaining, $minAgeMinutes);
            if ($rowIds === []) {
                return self::SUCCESS;
            }

            $rowCount = count($rowIds);
            $matchedCount += $rowCount;
            $remaining -= $rowCount;

            if ($this->option('dry-run') === true) {
                return self::SUCCESS;
            }

            foreach ($rowIds as $rowId) {
                $wasReset = $this->connection()->transaction(function () use ($rowId): bool {
                    $row = FiscalEventProjectionRow::query()
                        ->lockForUpdate()
                        ->find($rowId);

                    if (! $row instanceof FiscalEventProjectionRow || ! $this->isRetryable($row)) {
                        return false;
                    }

                    $this->resetProjectionRow($row);

                    return true;
                });

                if (! $wasReset) {
                    continue;
                }

                $resetCount++;

                if ($this->option('sync') === true) {
                    $job = new ApplyFiscalEventProjectionJob($rowId);

                    try {
                        $job->handle($this->connection(), $this->registry);
                        $syncAppliedCount++;
                    } catch (Throwable $e) {
                        $failureCount++;
                        $this->error(sprintf(
                            'Projection row %s threw during synchronous retry: %s',
                            $rowId,
                            $e->getMessage(),
                        ));
                    }

                    continue;
                }

                ApplyFiscalEventProjectionJob::dispatch($rowId);
                $dispatchedCount++;
            }

            return self::SUCCESS;
        });

        // An operator-targeted --tenant that was never reached (absent from the
        // directory, or skipped by forEachTenant()'s database probe) must not
        // exit SUCCESS having retried nothing.
        if (($unvisited = $this->failIfTenantFilterUnvisited($tenantFilter)) !== null) {
            return $unvisited;
        }

        if ($matchedCount === 0) {
            $this->info('No retryable fiscal projection rows matched.');

            return $exit;
        }

        if ($this->option('dry-run') === true) {
            $this->info(sprintf('Dry run: %d fiscal projection row(s) would be reset.', $matchedCount));

            return $exit;
        }

        $this->info(sprintf(
            'Reset %d fiscal projection row(s); dispatched %d; synchronously applied %d; %d failures.',
            $resetCount,
            $dispatchedCount,
            $syncAppliedCount,
            $failureCount,
        ));

        return $failureCount > 0 ? 2 : $exit;
    }

    /**
     * @return list<string>
     */
    private function candidateRowIds(string $tenantId, int $limit, int $minAgeMinutes): array
    {
        $cutoff = Carbon::now('UTC')->subMinutes($minAgeMinutes);

        $query = $this->connection()->table('fiscal_event_projections')
            ->join('fiscal_events', 'fiscal_events.id', '=', 'fiscal_event_projections.fiscal_event_id')
            ->where('fiscal_events.tenant_id', $tenantId)
            ->where(function (Builder $query): void {
                $query
                    ->where('fiscal_event_projections.projection_status', ProjectionStatus::DeadLettered->value)
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->where('fiscal_event_projections.projection_status', ProjectionStatus::Pending->value)
                            ->where('fiscal_event_projections.attempts', '>=', self::EXHAUSTED_ATTEMPTS);
                    });
            })
            ->where('fiscal_event_projections.updated_at', '<=', $cutoff->toDateTimeString())
            ->orderBy('fiscal_event_projections.updated_at')
            ->limit($limit);

        $projector = $this->stringOption('projector');
        if ($projector !== null) {
            $query->where('fiscal_event_projections.projector_name', $projector);
        }

        $eventId = $this->stringOption('event-id');
        if ($eventId !== null) {
            $query->where('fiscal_event_projections.fiscal_event_id', $eventId);
        }

        $rowIds = [];
        foreach ($query
            ->pluck('fiscal_event_projections.id')
            ->all() as $id) {
            $rowIds[] = (string) $id;
        }

        return $rowIds;
    }

    private function connection(): ConnectionInterface
    {
        return $this->database->connection();
    }

    private function isRetryable(FiscalEventProjectionRow $row): bool
    {
        return $row->projection_status === ProjectionStatus::DeadLettered
            || ($row->projection_status === ProjectionStatus::Pending
                && $row->attempts >= self::EXHAUSTED_ATTEMPTS);
    }

    private function resetProjectionRow(FiscalEventProjectionRow $row): void
    {
        $row->projection_status = ProjectionStatus::Pending;
        $row->attempts = 0;
        $row->last_error = null;
        $row->last_attempted_at = null;
        $row->applied_at = null;
        $row->dead_lettered_at = null;
        $row->save();
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function integerOption(string $name, int $minimum): ?int
    {
        $value = $this->option($name);
        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        $integer = (int) $value;

        return $integer >= $minimum ? $integer : null;
    }
}
