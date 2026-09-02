<?php

declare(strict_types=1);

namespace App\Modules\Uom\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Domain\ImportRow;
use App\Modules\Product\Domain\Product;
use App\Modules\Uom\Application\DTOs\UnitTextMappingResultData;
use App\Modules\Uom\Application\DTOs\UnmappedUnitTextData;
use App\Modules\Uom\Domain\Entities\UnitTextMapping;
use App\Modules\Uom\Domain\Enums\UnitTextMappingErrorCode;
use App\Modules\Uom\Domain\Events\UnitTextMappingApplied;
use App\Modules\Uom\Domain\Exceptions\UnitTextMappingException;
use App\Shared\Contracts\UnitCatalogQueryInterface;
use App\Shared\DTOs\UnitCatalogEntryData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class UnitTextMappingService
{
    public function __construct(
        private UnitCatalogQueryInterface $unitCatalog,
    ) {}

    /** @return list<UnmappedUnitTextData> */
    public function unmapped(Company $company): array
    {
        /** @var array<string, array{source: string|null, products: int, imports: int, pendingImports: int}> $counts */
        $counts = [];

        $productGroups = Product::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->whereNull('unit_id')
            ->select('unit', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('unit')
            ->get();
        foreach ($productGroups as $group) {
            $source = is_string($group->unit) ? $group->unit : null;
            $key = $this->sourceKey($source);
            $counts[$key] = [
                'source' => $source,
                'products' => (int) $group->getAttribute('aggregate_count'),
                'imports' => 0,
                'pendingImports' => 0,
            ];
        }

        foreach ($this->validatedUnknownTextStats($company) as $source => $stats) {
            $key = $this->sourceKey($source);
            $counts[$key] ??= [
                'source' => $source,
                'products' => 0,
                'imports' => 0,
                'pendingImports' => 0,
            ];
            $counts[$key]['imports'] = $stats['rows'];
            $counts[$key]['pendingImports'] = $stats['jobs'];
        }

        $aliased = UnitTextMapping::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->pluck('source_text')
            ->all();
        foreach ($aliased as $source) {
            if (is_string($source)) {
                unset($counts[$this->sourceKey($source)]);
            }
        }

        $rows = array_values($counts);
        usort($rows, static function (array $left, array $right): int {
            if ($left['source'] === null) {
                return $right['source'] === null ? 0 : -1;
            }
            if ($right['source'] === null) {
                return 1;
            }

            return strcmp($left['source'], $right['source']);
        });

        return array_map(
            static fn (array $row): UnmappedUnitTextData => new UnmappedUnitTextData(
                sourceText: $row['source'],
                productCount: $row['products'],
                importRowCount: $row['imports'],
                pendingImportCount: $row['pendingImports'],
                totalCount: $row['products'] + $row['imports'],
            ),
            $rows,
        );
    }

    public function apply(Company $company, ?string $sourceText, string $targetUnitId): UnitTextMappingResultData
    {
        $target = $this->visibleTarget($company->id, $targetUnitId);
        if ($target === null) {
            throw new UnitTextMappingException(
                UnitTextMappingErrorCode::TargetNotVisible,
                'The target unit is not visible to this company.',
            );
        }
        if ($sourceText === null && $target->code !== 'pc') {
            throw new UnitTextMappingException(
                UnitTextMappingErrorCode::BlankRequiresPc,
                'Blank unit text can only be mapped to the visible pc unit.',
            );
        }

        return DB::transaction(function () use ($company, $sourceText, $target): UnitTextMappingResultData {
            $existing = $sourceText === null
                ? null
                : UnitTextMapping::query()
                    ->where('tenant_id', $company->tenant_id)
                    ->where('company_id', $company->id)
                    ->where('source_text', $sourceText)
                    ->lockForUpdate()
                    ->first();
            if ($existing !== null) {
                if ($existing->target_unit_id !== $target->id) {
                    throw new UnitTextMappingException(
                        UnitTextMappingErrorCode::Conflict,
                        'This source text already has an immutable company mapping.',
                        409,
                    );
                }

                return $this->result($sourceText, $target, 0, 0, false, true);
            }

            $products = Product::query()
                ->where('tenant_id', $company->tenant_id)
                ->where('company_id', $company->id)
                ->whereNull('unit_id')
                ->when(
                    $sourceText === null,
                    static fn (Builder $query): Builder => $query->whereNull('unit'),
                    static fn (Builder $query): Builder => $query->where('unit', $sourceText),
                );
            $productCount = (clone $products)->count();
            $importRowCount = $sourceText === null
                ? 0
                : ($this->validatedUnknownTextStats($company)[$sourceText]['rows'] ?? 0);
            if ($sourceText === null && $productCount === 0) {
                return $this->result($sourceText, $target, 0, 0, false, false);
            }

            $products->update([
                'unit_id' => $target->id,
                'unit' => $target->code,
            ]);

            $mappingId = (string) Str::uuid();
            $aliasStored = false;
            if ($sourceText !== null) {
                $mapping = UnitTextMapping::create([
                    'tenant_id' => $company->tenant_id,
                    'company_id' => $company->id,
                    'source_text' => $sourceText,
                    'target_unit_id' => $target->id,
                ]);
                $mappingId = $mapping->id;
                $aliasStored = true;
            }

            event(new UnitTextMappingApplied(
                mappingId: $mappingId,
                companyId: $company->id,
                sourceText: $sourceText,
                targetUnitId: $target->id,
                targetUnitCode: $target->code,
                productCount: $productCount,
                importRowCount: $importRowCount,
            ));

            return $this->result(
                $sourceText,
                $target,
                $productCount,
                $importRowCount,
                $productCount > 0 || $importRowCount > 0,
                $aliasStored,
            );
        });
    }

    /** @return array<string, array{rows: int, jobs: int}> */
    private function validatedUnknownTextStats(Company $company): array
    {
        $validatedProductJobs = ImportJob::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('type', ImportType::Products)
            ->where('status', ImportStatus::Validated)
            ->select('id');

        $rows = ImportRow::query()
            ->where('import_error_code', ImportErrorCode::UnitUnknown)
            ->whereIn('import_job_id', $validatedProductJobs)
            ->get(['import_job_id', 'data', 'import_error_detail']);

        /** @var array<string, array{rows: int, jobIds: array<string, true>}> $stats */
        $stats = [];
        foreach ($rows as $row) {
            $detail = $row->import_error_detail ?? [];
            $source = $detail['supplied'] ?? ($row->data['unit'] ?? null);
            if (! is_string($source) || $source === '') {
                continue;
            }
            $stats[$source] ??= ['rows' => 0, 'jobIds' => []];
            $stats[$source]['rows']++;
            $stats[$source]['jobIds'][$row->import_job_id] = true;
        }

        ksort($stats, SORT_STRING);

        return array_map(
            static fn (array $stat): array => [
                'rows' => $stat['rows'],
                'jobs' => count($stat['jobIds']),
            ],
            $stats,
        );
    }

    private function visibleTarget(string $companyId, string $targetUnitId): ?UnitCatalogEntryData
    {
        foreach ($this->unitCatalog->visibleUnits($companyId) as $unit) {
            if ($unit->id === $targetUnitId) {
                return $unit;
            }
        }

        return null;
    }

    private function sourceKey(?string $source): string
    {
        return $source === null ? "\0blank" : 'text:'.$source;
    }

    private function result(
        ?string $sourceText,
        UnitCatalogEntryData $target,
        int $productCount,
        int $importRowCount,
        bool $applied,
        bool $aliasStored,
    ): UnitTextMappingResultData {
        return new UnitTextMappingResultData(
            sourceText: $sourceText,
            targetUnitId: $target->id,
            targetUnitCode: $target->code,
            productCount: $productCount,
            importRowCount: $importRowCount,
            applied: $applied,
            aliasStored: $aliasStored,
        );
    }
}
