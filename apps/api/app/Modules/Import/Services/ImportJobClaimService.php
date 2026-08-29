<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

use App\Modules\Import\Domain\Data\ClaimResult;
use App\Modules\Import\Domain\Data\ImportCountersData;
use App\Modules\Import\Domain\Data\ImportErrorDetailData;
use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\ImportJob;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

final class ImportJobClaimService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly LoggerInterface $logger,
    ) {}

    public function claim(ImportJob $job): ClaimResult
    {
        return $this->database->connection($job->getConnectionName())->transaction(
            function () use ($job): ClaimResult {
                $current = ImportJob::query()
                    ->where('tenant_id', $job->tenant_id)
                    ->where('id', $job->id)
                    ->lockForUpdate()
                    ->first(['status']);

                $priorStatus = $current === null ? $job->status : $current->status;

                if ($current === null || ! $priorStatus->canStartImport()) {
                    return new ClaimResult(false, $priorStatus);
                }

                $affected = ImportJob::query()
                    ->where('tenant_id', $job->tenant_id)
                    ->where('id', $job->id)
                    ->whereIn('status', [
                        ImportStatus::Pending->value,
                        ImportStatus::Validated->value,
                    ])
                    ->update([
                        'status' => ImportStatus::Importing->value,
                        'claimed_at' => now(),
                        'started_at' => now(),
                    ]);

                return new ClaimResult($affected === 1, $priorStatus);
            },
        );
    }

    public function release(ImportJob $job, ImportStatus $priorStatus): bool
    {
        if (! $priorStatus->canStartImport()) {
            return false;
        }

        return ImportJob::query()
            ->where('tenant_id', $job->tenant_id)
            ->where('id', $job->id)
            ->where('status', ImportStatus::Importing->value)
            ->whereNull('worker_started_at')
            ->update([
                'status' => $priorStatus->value,
                'claimed_at' => null,
                'started_at' => null,
            ]) === 1;
    }

    public function markWorkerStarted(string $jobId, string $tenantId): bool
    {
        return ImportJob::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $jobId)
            ->where('status', ImportStatus::Importing->value)
            ->whereNull('worker_started_at')
            ->update(['worker_started_at' => now()]) === 1;
    }

    public function finalize(
        ImportJob $job,
        ImportStatus $terminal,
        ImportCountersData $counters,
        ?ImportErrorCode $code,
        ?ImportErrorDetailData $detail,
        ?string $message = null,
    ): bool {
        if (! $terminal->isTerminal()) {
            throw new InvalidArgumentException('Import finalization requires a terminal status.');
        }

        $affected = self::terminalUpdate($job, $terminal, $counters, $code, $detail, $message);

        if ($affected === 1) {
            return true;
        }

        $this->logTerminalWriteLost($job, $terminal, self::class);

        return false;
    }

    /**
     * Laravel invokes a queued job's failed() hook directly and does not
     * dependency-inject hook parameters. Keep that fresh-worker path on the
     * same terminal CAS statement without resolving a service from app().
     */
    public static function finalizeFromFailedHandler(
        ImportJob $job,
        ImportStatus $terminal,
        ImportCountersData $counters,
        ?ImportErrorCode $code,
        ?ImportErrorDetailData $detail,
        ?string $message = null,
    ): bool {
        $affected = self::terminalUpdate($job, $terminal, $counters, $code, $detail, $message);

        if ($affected === 1) {
            return true;
        }

        Log::warning('import_jobs.terminal_write_lost', [
            'id' => $job->id,
            'attempted_status' => $terminal->value,
            'writer' => 'ProcessImportJob::failed',
        ]);

        return false;
    }

    private static function terminalUpdate(
        ImportJob $job,
        ImportStatus $terminal,
        ImportCountersData $counters,
        ?ImportErrorCode $code,
        ?ImportErrorDetailData $detail,
        ?string $message,
    ): int {
        if (! $terminal->isTerminal()) {
            throw new InvalidArgumentException('Import finalization requires a terminal status.');
        }

        if ($job->total_rows !== $counters->totalRows) {
            Log::warning('import_jobs.total_rows_mismatch', [
                'id' => $job->id,
                'stored_total_rows' => $job->total_rows,
                'recomputed_total_rows' => $counters->totalRows,
            ]);
        }

        return ImportJob::query()
            ->where('tenant_id', $job->tenant_id)
            ->where('id', $job->id)
            ->where('status', ImportStatus::Importing->value)
            ->update([
                'status' => $terminal->value,
                'successful_rows' => $counters->successfulRows,
                'skipped_rows' => $counters->skippedRows,
                'failed_rows' => $counters->failedRows,
                'total_rows' => $counters->totalRows,
                'error_code' => $code?->value,
                'error_detail' => $detail?->toJson(),
                'error_message' => $message,
                'completed_at' => now(),
            ]);
    }

    private function logTerminalWriteLost(ImportJob $job, ImportStatus $terminal, string $writer): void
    {
        $this->logger->warning('import_jobs.terminal_write_lost', [
            'id' => $job->id,
            'attempted_status' => $terminal->value,
            'writer' => $writer,
        ]);
    }
}
