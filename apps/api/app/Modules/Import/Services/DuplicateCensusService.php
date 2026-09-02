<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

use App\Modules\Import\Domain\Data\DuplicateCensusData;
use App\Modules\Import\Domain\Data\DuplicateRowDecisionData;
use App\Modules\Import\Domain\Enums\BarcodeGroupClassification;
use App\Modules\Import\Domain\Enums\DuplicateBucket;
use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\Enums\ImportRowOutcome;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\Enums\ImportWarningCode;
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
        $refused = [];
        $seenPlacementKeys = [];
        $barcodeGroups = $this->buildBarcodeGroups($job);
        foreach ($barcodeGroups['groups'] as $group) {
            if ($group['classification'] !== BarcodeGroupClassification::BarcodeIdentityConflict->value) {
                continue;
            }
            foreach ($group['row_numbers'] as $rowNumber) {
                $refused[] = [
                    'row_number' => $rowNumber,
                    'code' => ImportErrorCode::BarcodeIdentityConflict,
                ];
            }
        }

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
                        &$refused,
                        &$seenPlacementKeys,
                    ): void {
                        $resolutions = $this->resolveChunk($job, $companyId, $rows);
                        $locationIds = $this->resolveChunkLocations($job, $companyId, $rows);
                        $updates = [];

                        /** @var ImportRow $row */
                        foreach ($rows as $row) {
                            $resolution = $resolutions[$row->id];
                            $bucket = $this->bucketForResolution($resolution);
                            $refusalCode = $this->refusalCode($resolution);
                            $placementKey = $bucket === DuplicateBucket::Refused
                                ? null
                                : $this->placementKey(
                                    $job,
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
                            if ($refusalCode !== null) {
                                $refused[] = [
                                    'row_number' => $row->row_number,
                                    'code' => $refusalCode,
                                ];
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
        usort(
            $refused,
            static fn (array $left, array $right): int => $left['row_number'] <=> $right['row_number'],
        );
        $census = new DuplicateCensusData($counts, $matchedByName, $refused, $barcodeGroups);
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
        $placementKey = $this->placementKey($job, $row, $resolution, $locationId);
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
                            $job,
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
        if ($resolution->failure !== null) {
            return DuplicateBucket::Refused;
        }

        return match ($resolution->matchedBy) {
            ProductIdentityMatch::Sku => DuplicateBucket::ExistingSku,
            ProductIdentityMatch::Barcode => DuplicateBucket::ExistingBarcode,
            ProductIdentityMatch::Name => DuplicateBucket::ExistingName,
            null => DuplicateBucket::New,
        };
    }

    private function refusalCode(ProductIdentityResolutionData $resolution): ?ImportErrorCode
    {
        return match ($resolution->failure) {
            ProductIdentityFailure::SkuHeldByDeletedProduct => ImportErrorCode::SkuHeldByDeletedProduct,
            ProductIdentityFailure::BarcodeAmbiguous => ImportErrorCode::BarcodeAmbiguous,
            null => null,
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
        ImportJob $job,
        ImportRow $row,
        ProductIdentityResolutionData $resolution,
        ?string $locationId,
    ): ?string {
        if ($resolution->isBarcodeAmbiguous()
            || ($locationId === null && $this->effectiveLocationCode($job, $row) !== null)) {
            return null;
        }

        $data = $row->data;
        $identity = $resolution->productId;
        if ($identity === null) {
            // Mirror ProductResolver::resolveInput(): supplied SKU first, then
            // barcode, then normalized name only when both identifiers are blank.
            $sku = $this->nonBlank($data['sku'] ?? null);
            $barcode = $this->nonBlank($data['barcode'] ?? null);
            $name = mb_strtolower(trim((string) ($data['name'] ?? '')));
            $identity = $sku !== null
                ? 'sku:'.$sku
                : ($barcode !== null ? 'barcode:'.$barcode : 'name:'.$name);
        }

        return $identity."\0".($locationId ?? 'no-location');
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

    /**
     * Build the additive barcode key-space once. The existing placement key remains
     * authoritative for SKU/name/location winner selection.
     *
     * @return array{
     *   counts: array{multi_location_products: int, barcode_identity_conflict_groups: int, barcode_identity_conflict_rows: int},
     *   groups: list<array{barcode: string, classification: string, row_numbers: list<int>, location_codes: list<string>, differing_fields: list<string>}>,
     *   rows: array<int, int>
     * }
     */
    private function buildBarcodeGroups(ImportJob $job): array
    {
        /** @var array<string, list<array{row: ImportRow, data: array<string, mixed>, location_code: string|null}>> $byBarcode */
        $byBarcode = [];
        if ($job->type === ImportType::Products) {
            $job->rows()
                ->where('is_imported', false)
                ->orderBy('row_number')
                ->chunk(self::CHUNK_SIZE, function (Collection $rows) use ($job, &$byBarcode): void {
                    /** @var ImportRow $row */
                    foreach ($rows as $row) {
                        if (! $this->isBarcodeCensusEligible($row)) {
                            continue;
                        }

                        $barcode = $this->nonBlank($row->data['barcode'] ?? null);
                        if ($barcode === null) {
                            continue;
                        }
                        $byBarcode[$barcode][] = [
                            'row' => $row,
                            'data' => $row->data,
                            'location_code' => $this->effectiveLocationCode($job, $row),
                        ];
                    }
                });
        }

        $result = [
            'counts' => [
                'multi_location_products' => 0,
                'barcode_identity_conflict_groups' => 0,
                'barcode_identity_conflict_rows' => 0,
            ],
            'groups' => [],
            'rows' => [],
        ];
        foreach ($byBarcode as $barcode => $entries) {
            $barcode = (string) $barcode;
            if (count($entries) < 2) {
                continue;
            }
            $differingFields = $this->differingBarcodeIdentityFields($entries);
            $locationCodes = array_values(array_unique(array_filter(
                array_map(static fn (array $entry): ?string => $entry['location_code'], $entries),
                static fn (?string $code): bool => $code !== null,
            )));
            sort($locationCodes);
            $classification = null;
            if ($differingFields !== []) {
                $classification = BarcodeGroupClassification::BarcodeIdentityConflict;
                $result['counts']['barcode_identity_conflict_groups']++;
                $result['counts']['barcode_identity_conflict_rows'] += count($entries);
            } elseif (count($locationCodes) >= 2) {
                $classification = BarcodeGroupClassification::MultiLocation;
                $result['counts']['multi_location_products']++;
                $this->addWarningOnce(
                    $entries[0]['row'],
                    ImportWarningCode::MultiLocation,
                    sprintf(
                        'Product barcode %s appears on %d lines for different locations and will import as one product.',
                        $barcode,
                        count($entries),
                    ),
                );
            }
            if ($classification === null) {
                continue;
            }

            $rowNumbers = array_map(static fn (array $entry): int => $entry['row']->row_number, $entries);
            sort($rowNumbers);
            $groupIndex = count($result['groups']);
            $result['groups'][] = [
                'barcode' => $barcode,
                'classification' => $classification->value,
                'row_numbers' => $rowNumbers,
                'location_codes' => $locationCodes,
                'differing_fields' => $differingFields,
            ];
            foreach ($rowNumbers as $rowNumber) {
                $result['rows'][$rowNumber] = $groupIndex;
            }
        }

        return $result;
    }

    /**
     * Unit text mapping is a remediable boundary that must not hide barcode
     * conflicts from the upload preview. Any other validation error still
     * excludes the row from the barcode arithmetic.
     */
    private function isBarcodeCensusEligible(ImportRow $row): bool
    {
        if ($row->is_valid && $row->outcome === ImportRowOutcome::Pending) {
            return true;
        }

        $errors = $row->errors ?? [];
        if ($errors === []) {
            return false;
        }

        foreach (array_keys($errors) as $field) {
            if ($field !== 'unit') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array{row: ImportRow, data: array<string, mixed>, location_code: string|null}>  $entries
     * @return list<string>
     */
    private function differingBarcodeIdentityFields(array $entries): array
    {
        $fields = [];
        foreach ($entries as $entry) {
            foreach (array_keys($entry['data']) as $field) {
                if (! str_starts_with($field, '_')
                    && ! in_array($field, ['barcode', 'location_code', 'quantity', 'placement_path'], true)) {
                    $fields[$field] = true;
                }
            }
        }

        $differing = [];
        foreach (array_keys($fields) as $field) {
            $values = [];
            foreach ($entries as $entry) {
                $values[] = $this->comparableValue($entry['data'][$field] ?? null);
            }
            if (count(array_unique($values, SORT_STRING)) > 1) {
                $differing[] = $field;
            }
        }
        sort($differing);

        return $differing;
    }

    private function comparableValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function addWarningOnce(ImportRow $row, ImportWarningCode $code, string $detail): void
    {
        $warnings = $row->warnings ?? [];
        if (array_any(
            $warnings,
            static fn (array $warning): bool => ($warning['code'] ?? null) === $code->value,
        )) {
            return;
        }
        $warnings[] = ['code' => $code->value, 'detail' => $detail];
        $row->update(['warnings' => $warnings]);
    }
}
