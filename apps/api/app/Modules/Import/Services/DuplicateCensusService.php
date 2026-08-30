<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

use App\Modules\Import\Domain\Data\DuplicateCensusData;
use App\Modules\Import\Domain\Data\DuplicateRowDecisionData;
use App\Modules\Import\Domain\Enums\DuplicateBucket;
use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\Enums\ImportRowOutcome;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\Exceptions\CodedImportRowException;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Domain\ImportRow;
use App\Shared\Contracts\LocationServiceInterface;
use App\Shared\Contracts\ProductResolverInterface;
use App\Shared\DTOs\ProductIdentityInputData;
use App\Shared\DTOs\ProductIdentityResolutionData;
use App\Shared\Enums\ProductIdentityFailure;
use App\Shared\Enums\ProductIdentityMatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class DuplicateCensusService
{
    private const int CHUNK_SIZE = 500;

    public function __construct(
        private ProductResolverInterface $products,
        private LocationServiceInterface $locations,
    ) {}

    public function census(ImportJob $job, string $companyId): DuplicateCensusData
    {
        $counts = self::emptyCounts();
        $matchedByName = [];
        $seenPlacementKeys = [];

        if ($job->type === ImportType::Products) {
            $job->rows()
                ->where('is_valid', true)
                ->where('is_imported', false)
                ->where('outcome', ImportRowOutcome::Pending)
                ->reorder()
                ->chunkByIdDesc(
                    self::CHUNK_SIZE,
                    function (Collection $rows) use (
                        $job,
                        $companyId,
                        &$counts,
                        &$matchedByName,
                        &$seenPlacementKeys,
                    ): void {
                        $resolutions = $this->resolveChunk($job, $companyId, $rows);
                        $locationIds = $this->resolveChunkLocations($job, $companyId, $rows);
                        $updates = [];

                        /** @var ImportRow $row */
                        foreach ($rows as $row) {
                            $resolution = $resolutions[$row->id];
                            $bucket = $this->bucketForResolution($resolution);
                            $placementKey = $this->placementKey(
                                $row,
                                $resolution,
                                $locationIds[$row->id] ?? null,
                            );
                            if ($placementKey !== null) {
                                if (isset($seenPlacementKeys[$placementKey])) {
                                    $bucket = DuplicateBucket::InFile;
                                } else {
                                    $seenPlacementKeys[$placementKey] = true;
                                }
                            }

                            $counts[$bucket->value]++;
                            if ($bucket === DuplicateBucket::ExistingName) {
                                $matchedByName[] = $row->row_number;
                            }
                            $updates[] = [
                                'id' => $row->id,
                                'duplicate_bucket' => $bucket->value,
                            ];

                            if ($this->locationIsUnresolved($job, $row, $locationIds[$row->id] ?? null)) {
                                $this->addLocationWarning($row);
                            }
                        }

                        $this->persistBuckets($updates);
                    },
                    'row_number',
                );
        }

        sort($matchedByName);
        $census = new DuplicateCensusData($counts, $matchedByName);
        $job->update([
            'options' => array_merge(
                $job->options ?? [],
                ['duplicate_census' => $census->toStorage()],
            ),
        ]);

        return $census;
    }

    /**
     * Resolve the row's authoritative duplicate and within-file decision.
     * This method is called from inside the row transaction.
     */
    public function decide(ImportJob $job, ImportRow $row, string $companyId): DuplicateRowDecisionData
    {
        if ($job->type !== ImportType::Products) {
            return new DuplicateRowDecisionData(DuplicateBucket::New, null);
        }

        $resolution = $this->resolveProduct($job, $row, $companyId);
        $this->throwIfResolutionFailed($resolution);
        $bucket = $this->bucketForResolution($resolution);
        $locationId = $this->locationId($job, $row, $companyId);
        $locationUnresolved = $this->locationIsUnresolved($job, $row, $locationId);
        $placementKey = $this->placementKey($row, $resolution, $locationId);
        if ($placementKey === null) {
            return new DuplicateRowDecisionData($bucket, null, $locationUnresolved);
        }

        $winnerRowNumber = null;
        $job->rows()
            ->where('is_valid', true)
            ->where('is_imported', false)
            ->where('outcome', ImportRowOutcome::Pending)
            ->where('row_number', '>', $row->row_number)
            ->reorder()
            ->chunkById(
                self::CHUNK_SIZE,
                function (Collection $rows) use (
                    $job,
                    $companyId,
                    $placementKey,
                    &$winnerRowNumber,
                ): bool {
                    $resolutions = $this->resolveChunk($job, $companyId, $rows);
                    $locationIds = $this->resolveChunkLocations($job, $companyId, $rows);

                    /** @var ImportRow $candidate */
                    foreach ($rows as $candidate) {
                        $candidateKey = $this->placementKey(
                            $candidate,
                            $resolutions[$candidate->id],
                            $locationIds[$candidate->id] ?? null,
                        );
                        if ($candidateKey === $placementKey) {
                            $winnerRowNumber = max($winnerRowNumber ?? 0, $candidate->row_number);
                        }
                    }

                    return true;
                },
                'row_number',
            );

        return new DuplicateRowDecisionData(
            $winnerRowNumber === null ? $bucket : DuplicateBucket::InFile,
            $winnerRowNumber,
            $locationUnresolved,
        );
    }

    /**
     * @param  Collection<int, ImportRow>  $rows
     * @return array<string, ProductIdentityResolutionData>
     */
    private function resolveChunk(ImportJob $job, string $companyId, Collection $rows): array
    {
        $inputs = [];
        foreach ($rows as $row) {
            $data = $row->data;
            $inputs[] = new ProductIdentityInputData(
                $row->id,
                $this->nonBlank($data['sku'] ?? null),
                $this->nonBlank($data['barcode'] ?? null),
                (string) ($data['name'] ?? ''),
            );
        }

        return $this->products->resolveMany($job->tenant_id, $companyId, $inputs);
    }

    /**
     * @param  Collection<int, ImportRow>  $rows
     * @return array<string, string|null>
     */
    private function resolveChunkLocations(ImportJob $job, string $companyId, Collection $rows): array
    {
        $codesByRow = [];
        foreach ($rows as $row) {
            $codesByRow[$row->id] = $this->effectiveLocationCode($job, $row);
        }

        $idsByCode = $this->locations->findIdsByCodes(
            $companyId,
            array_values(array_filter($codesByRow, static fn (?string $code): bool => $code !== null)),
        );
        $idsByRow = [];
        foreach ($codesByRow as $rowId => $code) {
            $idsByRow[$rowId] = $code === null ? null : ($idsByCode[$code] ?? null);
        }

        return $idsByRow;
    }

    private function resolveProduct(ImportJob $job, ImportRow $row, string $companyId): ProductIdentityResolutionData
    {
        $data = $row->data;

        return $this->products->resolve(
            $job->tenant_id,
            $companyId,
            $this->nonBlank($data['sku'] ?? null),
            $this->nonBlank($data['barcode'] ?? null),
            (string) ($data['name'] ?? ''),
        );
    }

    private function locationId(ImportJob $job, ImportRow $row, string $companyId): ?string
    {
        $code = $this->effectiveLocationCode($job, $row);

        return $code === null ? null : $this->locations->findIdByCode($companyId, $code);
    }

    private function effectiveLocationCode(ImportJob $job, ImportRow $row): ?string
    {
        return $this->nonBlank($row->data['location_code'] ?? null)
            ?? $this->nonBlank($job->options['location_code'] ?? null);
    }

    private function locationIsUnresolved(ImportJob $job, ImportRow $row, ?string $locationId): bool
    {
        return $locationId === null && $this->effectiveLocationCode($job, $row) !== null;
    }

    private function addLocationWarning(ImportRow $row): void
    {
        $warnings = $row->warnings ?? [];
        foreach ($warnings as $warning) {
            if (($warning['code'] ?? null) === 'location_unresolved') {
                return;
            }
        }

        $warnings[] = [
            'code' => 'location_unresolved',
            'detail' => 'Location code could not be resolved.',
        ];
        $row->update(['warnings' => $warnings]);
    }

    private function bucketForResolution(ProductIdentityResolutionData $resolution): DuplicateBucket
    {
        return match ($resolution->matchedBy) {
            ProductIdentityMatch::Sku => DuplicateBucket::ExistingSku,
            ProductIdentityMatch::Barcode => DuplicateBucket::ExistingBarcode,
            ProductIdentityMatch::Name => DuplicateBucket::ExistingName,
            null => DuplicateBucket::New,
        };
    }

    private function throwIfResolutionFailed(ProductIdentityResolutionData $resolution): void
    {
        if ($resolution->failure === ProductIdentityFailure::SkuHeldByDeletedProduct) {
            throw new CodedImportRowException(
                ImportErrorCode::SkuHeldByDeletedProduct,
                sprintf(
                    'SKU %s is held by a soft-deleted product; purge the deleted record or choose a different SKU.',
                    $resolution->failureSku ?? '',
                ),
                $resolution->failureSku === null ? [] : ['sku' => $resolution->failureSku],
            );
        }
        if ($resolution->failure === ProductIdentityFailure::BarcodeAmbiguous) {
            throw new CodedImportRowException(
                ImportErrorCode::BarcodeAmbiguous,
                'Barcode matches more than one product in this company.',
                ['candidate_skus' => $resolution->candidateSkus],
            );
        }
    }

    private function placementKey(
        ImportRow $row,
        ProductIdentityResolutionData $resolution,
        ?string $locationId,
    ): ?string {
        if ($locationId === null || $resolution->isBarcodeAmbiguous()) {
            return null;
        }

        $data = $row->data;
        $identity = $resolution->productId;
        if ($identity === null) {
            $sku = $this->nonBlank($data['sku'] ?? null);
            $barcode = $this->nonBlank($data['barcode'] ?? null);
            $name = mb_strtolower(trim((string) ($data['name'] ?? '')));
            $identity = $sku !== null
                ? 'sku:'.$sku
                : ($barcode !== null ? 'barcode:'.$barcode : 'name:'.$name);
        }

        return $identity."\0".$locationId;
    }

    /** @return array<string, int> */
    private static function emptyCounts(): array
    {
        $counts = [];
        foreach (DuplicateBucket::cases() as $bucket) {
            $counts[$bucket->value] = 0;
        }

        return $counts;
    }

    /** @param list<array{id: string, duplicate_bucket: string}> $updates */
    private function persistBuckets(array $updates): void
    {
        if ($updates === []) {
            return;
        }

        $case = [];
        $bindings = [];
        $ids = [];
        foreach ($updates as $update) {
            $case[] = 'WHEN ? THEN ?';
            $bindings[] = $update['id'];
            $bindings[] = $update['duplicate_bucket'];
            $ids[] = $update['id'];
        }

        $bindings[] = now();
        array_push($bindings, ...$ids);
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        DB::update(
            'UPDATE import_rows SET duplicate_bucket = CASE id '.implode(' ', $case)
            .' END, updated_at = ? WHERE id IN ('.$placeholders.')',
            $bindings,
        );
    }

    private function nonBlank(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
