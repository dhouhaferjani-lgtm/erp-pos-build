<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

use App\Modules\Accounting\Application\Services\OpeningBalanceBatchService;
use App\Modules\Accounting\Domain\Enums\OpeningBatchStatus;
use App\Modules\Accounting\Domain\Enums\OpeningBatchType;
use App\Modules\Accounting\Domain\Enums\OpeningImportRowStatus;
use App\Modules\Accounting\Domain\OpeningBalanceBatch;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Application\Services\ArApOpeningService;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Domain\ImportRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

final class PartiesBalancesPhase
{
    public function __construct(
        private readonly PartiesRowMapper $rowMapper,
        private readonly ArApOpeningService $arApOpeningService,
        private readonly OpeningBalanceBatchService $batchService,
    ) {}

    /**
     * @return list<array{row_id: string, code: string, detail: string, results: array<string, string>}>
     */
    public function run(ImportJob $job, string $companyId): array
    {
        $company = Company::query()
            ->where('tenant_id', $job->tenant_id)
            ->where('id', $companyId)
            ->firstOrFail();
        $defaultDate = $this->defaultBalanceDate($company);
        $balanceRows = [];

        $job->rows()
            ->where('is_imported', true)
            ->orderBy('row_number')
            ->get()
            ->each(function (ImportRow $row) use ($defaultDate, &$balanceRows): void {
                $partnerCode = (string) ($row->data['code'] ?? '');
                if ($partnerCode === '') {
                    return;
                }

                $payloads = $this->rowMapper->toBalancePayloads($row->data, $defaultDate, $partnerCode);
                if ($payloads['ar'] === null && $payloads['ap'] === null) {
                    return;
                }

                $balanceRows[] = [
                    'row' => $row,
                    'ar' => $payloads['ar'],
                    'ap' => $payloads['ap'],
                ];
            });

        if ($balanceRows === []) {
            return [];
        }

        if ($this->openingBalancesLocked($company->id)) {
            return $this->lockedWarnings($balanceRows);
        }

        return array_merge(
            $this->runSide($job, $company, $balanceRows, 'ar', OpeningBatchType::ArOpenItems),
            $this->runSide($job, $company, $balanceRows, 'ap', OpeningBatchType::ApOpenItems),
        );
    }

    /**
     * @param  list<array{row: ImportRow, ar: ?array<string, mixed>, ap: ?array<string, mixed>}>  $balanceRows
     * @return list<array{row_id: string, code: string, detail: string, results: array<string, string>}>
     */
    private function runSide(ImportJob $job, Company $company, array $balanceRows, string $side, OpeningBatchType $type): array
    {
        $sideRows = array_values(array_filter(
            $balanceRows,
            fn (array $entry): bool => $entry[$side] !== null
        ));

        if ($sideRows === []) {
            return [];
        }

        $resultKey = $side.'_balance';
        $label = $side === 'ar' ? 'AR' : 'AP';
        $batch = $this->findImportBatch($job, $type);

        if ($batch !== null && in_array($batch->status, [OpeningBatchStatus::Validated, OpeningBatchStatus::Locked], true)) {
            return $this->sideResults($sideRows, '', '', [$resultKey => 'ok']);
        }

        if ($batch !== null && $batch->status === OpeningBatchStatus::Draft) {
            $this->batchService->clearImportRows($batch);
        } else {
            if ($this->hasOtherUnlockedBatch($company->id, $type, null)) {
                return $this->sideResults(
                    $sideRows,
                    'balance_not_posted',
                    "an unlocked {$label} opening batch already exists - post or delete it, then re-run",
                    [$resultKey => 'error: batch_conflict'],
                );
            }

            $batch = $this->batchService->createBatch(
                $company,
                $type,
                Carbon::parse($this->defaultBalanceDate($company)),
                'IMPORT-'.substr($job->id, 0, 8).'-'.$label,
                $job->user_id,
                'unified-import',
            );
            $this->batchService->updateFileReference($batch, [
                'import_job_id' => $job->id,
                'source' => 'unified-import',
            ]);
        }

        $payloads = [];
        foreach ($sideRows as $entry) {
            $payload = $entry[$side];
            if (! is_array($payload)) {
                continue;
            }

            $payloads[] = array_merge($payload, ['currency' => $company->currency]);
        }

        $this->batchService->addImportRows($batch, $payloads);
        $this->arApOpeningService->validateBatch($batch->refresh());

        $warnings = $this->validationWarnings($batch->refresh(), $sideRows, $resultKey);
        $validCount = $batch->rows()->where('status', OpeningImportRowStatus::Valid)->count();
        if ($validCount === 0) {
            return $warnings;
        }

        try {
            $this->arApOpeningService->postBatch($batch->refresh(), $job->user_id);
        } catch (\Throwable $e) {
            return $this->sideResults($sideRows, 'balance_not_posted', $e->getMessage(), [$resultKey => 'error: post_failed']);
        }

        return array_merge($warnings, $this->sideResults($sideRows, '', '', [$resultKey => 'ok']));
    }

    private function defaultBalanceDate(Company $company): string
    {
        $candidate = CarbonImmutable::parse(sprintf('%d-%02d-01', (int) now()->year, $company->fiscal_year_start_month));
        if ($candidate->isAfter(CarbonImmutable::now())) {
            $candidate = $candidate->subYear();
        }

        return $candidate->toDateString();
    }

    private function findImportBatch(ImportJob $job, OpeningBatchType $type): ?OpeningBalanceBatch
    {
        return OpeningBalanceBatch::query()
            ->where('type', $type)
            ->get()
            ->first(fn (OpeningBalanceBatch $batch): bool => ($batch->import_file_reference['import_job_id'] ?? null) === $job->id);
    }

    private function hasOtherUnlockedBatch(string $companyId, OpeningBatchType $type, ?OpeningBalanceBatch $ownBatch): bool
    {
        $query = OpeningBalanceBatch::forCompany($companyId)
            ->ofType($type)
            ->unlocked();

        if ($ownBatch !== null) {
            $query->where('id', '!=', $ownBatch->id);
        }

        return $query->exists();
    }

    public function openingBalancesLocked(string $companyId): bool
    {
        return OpeningBalanceBatch::forCompany($companyId)
            ->whereIn('type', [OpeningBatchType::ArOpenItems, OpeningBatchType::ApOpenItems])
            ->where('status', OpeningBatchStatus::Locked)
            ->exists();
    }

    /**
     * @param  list<array{row: ImportRow, ar: ?array<string, mixed>, ap: ?array<string, mixed>}>  $balanceRows
     * @return list<array{row_id: string, code: string, detail: string, results: array<string, string>}>
     */
    private function lockedWarnings(array $balanceRows): array
    {
        $results = [];

        foreach ($balanceRows as $entry) {
            $sideResults = [];
            if ($entry['ar'] !== null) {
                $sideResults['ar_balance'] = 'error: opening_locked';
            }
            if ($entry['ap'] !== null) {
                $sideResults['ap_balance'] = 'error: opening_locked';
            }

            $results[] = [
                'row_id' => $entry['row']->id,
                'code' => 'balance_not_posted',
                'detail' => 'opening balances are locked',
                'results' => $sideResults,
            ];
        }

        return $results;
    }

    /**
     * @param  list<array{row: ImportRow, ar: ?array<string, mixed>, ap: ?array<string, mixed>}>  $sideRows
     * @param  array<string, string>  $results
     * @return list<array{row_id: string, code: string, detail: string, results: array<string, string>}>
     */
    private function sideResults(array $sideRows, string $code, string $detail, array $results): array
    {
        return array_map(
            fn (array $entry): array => [
                'row_id' => $entry['row']->id,
                'code' => $code,
                'detail' => $detail,
                'results' => $results,
            ],
            $sideRows
        );
    }

    /**
     * @param  list<array{row: ImportRow, ar: ?array<string, mixed>, ap: ?array<string, mixed>}>  $sideRows
     * @return list<array{row_id: string, code: string, detail: string, results: array<string, string>}>
     */
    private function validationWarnings(OpeningBalanceBatch $batch, array $sideRows, string $resultKey): array
    {
        $warnings = [];
        $batchRows = $batch->rows()->orderBy('row_number')->get()->values();

        foreach ($batchRows as $index => $batchRow) {
            if ($batchRow->status !== OpeningImportRowStatus::Invalid) {
                continue;
            }

            $sourceRow = $sideRows[$index]['row'];
            $detail = implode('; ', $batchRow->getErrorMessages());
            $warnings[] = [
                'row_id' => $sourceRow->id,
                'code' => 'balance_not_posted',
                'detail' => $detail,
                'results' => [$resultKey => 'error: validation_failed'],
            ];
            $this->batchService->skipImportRow($batchRow);
        }

        return $warnings;
    }
}
