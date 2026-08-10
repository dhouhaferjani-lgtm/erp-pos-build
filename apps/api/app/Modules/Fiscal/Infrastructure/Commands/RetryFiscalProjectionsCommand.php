<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Commands;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Application\Jobs\ApplyFiscalEventProjectionJob;
use App\Modules\Fiscal\Application\Services\FiscalEventProjectionRegistry;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\ProjectionStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEventProjectionRow;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Operator recovery for exhausted or dead-lettered fiscal projections.
 */
final class RetryFiscalProjectionsCommand extends TenantScopedCommand
{
    private const EXHAUSTED_ATTEMPTS = 5;

    /** @var list<string> the narrowing options an empty value must never widen */
    private const FILTER_OPTIONS = ['tenant', 'event-type', 'projector', 'event-id'];

    /** @var string */
    protected $signature = 'fiscal:retry-projections
        {--projector= : restrict to one fiscal_event_projections.projector_name}
        {--event-type= : restrict to one fiscal_events.event_type, such as ACCOUNT_CHARGE}
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

        // An explicitly-passed EMPTY filter must never be read as "no filter".
        // `stringOption()` maps '' to null for the whole fleet, so
        // `--tenant=$UNSET` from a shell script silently degraded a
        // single-tenant repair into a fleet-wide one and `--event-type=`
        // bypassed the enum validation below (2026-08-10 fiscal gate, finding
        // 3). Scoped to this command deliberately: `stringOption()`'s
        // permissive contract is shared by every other tenant-scoped command.
        foreach (self::FILTER_OPTIONS as $filterOption) {
            if ($this->option($filterOption) === '') {
                $this->error(sprintf(
                    '--%s was passed with an empty value; omit the option to widen the scope.',
                    $filterOption,
                ));

                return self::INVALID;
            }
        }

        $tenantFilter = $this->stringOption('tenant');
        $eventTypeOption = $this->stringOption('event-type');
        $eventType = $eventTypeOption === null ? null : FiscalEventType::tryFrom($eventTypeOption);
        if ($eventTypeOption !== null && $eventType === null) {
            $this->error('--event-type must be a known fiscal event type.');

            return self::INVALID;
        }

        // A typo used to exit 0 having done nothing, indistinguishable from
        // "already repaired" — and the documented recovery invocation passes
        // `--projector` (2026-08-10 fiscal gate, finding 2). The registry is
        // already injected, so validating both filters is free.
        $projector = $this->stringOption('projector');
        if ($projector !== null && $this->registry->byName($projector) === null) {
            $this->error('--projector must be a registered fiscal projector name.');

            return self::INVALID;
        }

        $eventId = $this->stringOption('event-id');
        if ($eventId !== null && ! Str::isUuid($eventId)) {
            $this->error('--event-id must be a UUID.');

            return self::INVALID;
        }

        $remaining = $limit;
        $matchedCount = 0;

        $resetCount = 0;
        $dispatchedCount = 0;
        $syncAppliedCount = 0;
        $failureCount = 0;

        // Narrowing happens in the DIRECTORY QUERY, not in the closure: a
        // `--tenant=X` run must not probe and initialize() every other tenant
        // first, or an unrelated tenant's outage poisons X's exit code (and
        // costs O(fleet) database opens). See
        // TenantScopedCommand::forEachTenantNarrowed()'s docblock; both sibling
        // fiscal commands were converted for the same reason.
        $exit = $this->forEachTenantNarrowed($tenantFilter, function (Tenant $tenant) use (
            $eventType,
            $projector,
            $eventId,
            $minAgeMinutes,
            &$remaining,
            &$matchedCount,
            &$resetCount,
            &$dispatchedCount,
            &$syncAppliedCount,
            &$failureCount,
        ): int {
            if ($remaining <= 0) {
                return self::SUCCESS;
            }

            $rows = $this->candidateRows($tenant->id, $remaining, $minAgeMinutes, $eventType, $projector, $eventId);
            if ($rows === []) {
                return self::SUCCESS;
            }

            $rowCount = count($rows);
            $matchedCount += $rowCount;
            $remaining -= $rowCount;

            if ($this->option('dry-run') === true) {
                foreach ($rows as $row) {
                    $this->line(sprintf(
                        'tenant=%s event=%s event_type=%s projector=%s status=%s attempts=%d last_error=%s',
                        $row['tenant_id'],
                        $row['event_id'],
                        $row['event_type'],
                        $row['projector_name'],
                        $row['projection_status'],
                        $row['attempts'],
                        $row['last_error'] ?? '',
                    ));
                }

                return self::SUCCESS;
            }

            foreach ($rows as $candidate) {
                $rowId = $candidate['id'];
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
                        // `--sync` runs the job OUTSIDE Horizon, so nothing else
                        // will ever call `failed()` for this attempt. Without
                        // this call the row is left exactly where
                        // `resetProjectionRow()` plus the job's own
                        // `advanceFailureAccounting()` put it — `(Pending,
                        // attempts=1)` — which matches NEITHER `candidateRows()`
                        // (DeadLettered OR Pending >= EXHAUSTED_ATTEMPTS) NOR the
                        // scheduled sweep NOR any queued job. A replay that fails
                        // again would silently disappear from every operator
                        // inventory (2026-08-10 fiscal gate, finding 1).
                        // `failed()` is idempotent and writes the durable
                        // terminal state: DeadLettered + dead_lettered_at +
                        // last_error + Log::critical.
                        $job->failed($e);

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
     * @return list<array{
     *     id: string,
     *     tenant_id: string,
     *     event_id: string,
     *     event_type: string,
     *     projector_name: string,
     *     projection_status: string,
     *     attempts: int,
     *     last_error: string|null
     * }>
     */
    private function candidateRows(
        string $tenantId,
        int $limit,
        int $minAgeMinutes,
        ?FiscalEventType $eventType,
        ?string $projector,
        ?string $eventId,
    ): array {
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
            ->orderBy('fiscal_event_projections.id')
            ->limit($limit);

        if ($eventType !== null) {
            $query->where('fiscal_events.event_type', $eventType->value);
        }

        if ($projector !== null) {
            $query->where('fiscal_event_projections.projector_name', $projector);
        }

        if ($eventId !== null) {
            $query->where('fiscal_event_projections.fiscal_event_id', $eventId);
        }

        $rows = [];
        foreach ($query
            ->get([
                'fiscal_event_projections.id',
                'fiscal_events.tenant_id',
                'fiscal_events.id as event_id',
                'fiscal_events.event_type',
                'fiscal_event_projections.projector_name',
                'fiscal_event_projections.projection_status',
                'fiscal_event_projections.attempts',
                'fiscal_event_projections.last_error',
            ]) as $row) {
            $rows[] = [
                'id' => (string) $row->id,
                'tenant_id' => (string) $row->tenant_id,
                'event_id' => (string) $row->event_id,
                'event_type' => (string) $row->event_type,
                'projector_name' => (string) $row->projector_name,
                'projection_status' => (string) $row->projection_status,
                'attempts' => (int) $row->attempts,
                'last_error' => $row->last_error === null ? null : (string) $row->last_error,
            ];
        }

        return $rows;
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
