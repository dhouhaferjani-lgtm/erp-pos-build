<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Inventory\Domain\StockMovement;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class EntryExitNoteController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $direction = $request->string('direction')->toString();
        $sourceType = $request->string('source_type')->toString();
        $locationId = $request->string('location_id')->toString();
        $dateFrom = $request->date('date_from');
        $dateTo = $request->date('date_to');
        $usedDefaultWindow = $dateFrom === null && $dateTo === null;
        if ($usedDefaultWindow) {
            $dateFrom = now()->subDays(30)->startOfDay();
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $page = max($request->integer('page', 1), 1);
        $directionSql = $this->directionSql('sm');
        $sourceTypeSql = "COALESCE(source_documents.type, sm.reference_type, 'manual')";

        $groupQuery = DB::table('stock_movements as sm')
            ->leftJoin('documents as source_documents', function ($join) use ($company): void {
                $join
                    ->on('source_documents.id', '=', 'sm.reference_id')
                    ->where('source_documents.tenant_id', '=', $company->tenant_id)
                    ->where('source_documents.company_id', '=', $company->id)
                    ->whereIn('sm.reference_type', [Document::class, 'Document']);
            })
            ->where('sm.tenant_id', $company->tenant_id)
            ->where('sm.company_id', $company->id)
            ->select([
                'sm.reference_type',
                'sm.reference_id',
                'sm.location_id',
            ])
            ->selectRaw("{$directionSql} as direction")
            ->selectRaw("{$sourceTypeSql} as source_type")
            ->selectRaw('MAX(sm.created_at) as latest_timestamp')
            ->groupBy([
                'sm.reference_type',
                'sm.reference_id',
                'sm.location_id',
            ])
            ->groupByRaw($directionSql)
            ->groupByRaw($sourceTypeSql)
            ->orderByDesc('latest_timestamp');

        if ($locationId !== '') {
            $groupQuery->where('sm.location_id', $locationId);
        }

        if ($dateFrom !== null) {
            $groupQuery->where('sm.created_at', '>=', $dateFrom->startOfDay());
        }

        if ($dateTo !== null) {
            $groupQuery->where('sm.created_at', '<=', $dateTo->endOfDay());
        }

        if ($direction !== '') {
            $this->applyDirectionFilter($groupQuery, 'sm', $direction);
        }

        if ($sourceType !== '') {
            $groupQuery->where(function ($query) use ($sourceType): void {
                $query
                    ->where('sm.reference_type', $sourceType)
                    ->orWhere(function ($documentQuery) use ($sourceType): void {
                        $documentQuery
                            ->whereIn('sm.reference_type', [Document::class, 'Document'])
                            ->where('source_documents.type', $sourceType);
                    });
            });
        }

        $paginator = $groupQuery->paginate($perPage, ['*'], 'page', $page);
        /** @var Collection<int, \stdClass> $groups */
        $groups = collect($paginator->items());
        $documentSourceTypes = $this->documentSourceTypes($groups);
        $notes = $groups->isEmpty()
            ? collect()
            : $this->movementsForGroups($groups, $company->tenant_id, $company->id, $dateFrom, $dateTo)
                ->groupBy(fn (StockMovement $movement): string => $this->groupKey($movement, $documentSourceTypes))
                ->map(fn (Collection $group): array => $this->formatNote($group, $documentSourceTypes))
                ->sortByDesc('timestamp')
                ->values();

        $meta = [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];

        if ($usedDefaultWindow) {
            $meta['default_date_window_days'] = 30;
        }

        return response()->json([
            'data' => $notes->all(),
            'meta' => $meta,
        ]);
    }

    /**
     * @param  Collection<int, \stdClass>  $groups
     * @return Collection<int, StockMovement>
     */
    private function movementsForGroups(
        Collection $groups,
        string $tenantId,
        string $companyId,
        ?CarbonInterface $dateFrom,
        ?CarbonInterface $dateTo,
    ): Collection {
        $query = StockMovement::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->with(['product.unitOfMeasure', 'location', 'user'])
            ->where(function ($outer) use ($groups): void {
                foreach ($groups as $group) {
                    $outer->orWhere(function ($inner) use ($group): void {
                        $this->applyDirectionFilter($inner, 'stock_movements', (string) $group->direction);
                        $inner->where('location_id', (string) $group->location_id);

                        $group->reference_type === null
                            ? $inner->whereNull('reference_type')
                            : $inner->where('reference_type', (string) $group->reference_type);

                        $group->reference_id === null
                            ? $inner->whereNull('reference_id')
                            : $inner->where('reference_id', (string) $group->reference_id);
                    });
                }
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($dateFrom !== null) {
            $query->where('created_at', '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $query->where('created_at', '<=', $dateTo);
        }

        /** @var Collection<int, StockMovement> */
        return $query->get();
    }

    /**
     * @param  Collection<int, \stdClass>  $groups
     * @return array<string, string>
     */
    private function documentSourceTypes(Collection $groups): array
    {
        $ids = $groups
            ->filter(fn (\stdClass $group): bool => in_array($group->reference_type, [Document::class, 'Document'], true) && $group->reference_id !== null)
            ->map(fn (\stdClass $group): string => (string) $group->reference_id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Document::query()
            ->whereIn('id', $ids->all())
            ->pluck('type', 'id')
            ->map(fn (mixed $type): string => is_string($type) ? $type : $type->value)
            ->all();
    }

    /**
     * @param  array<string, string>  $documentSourceTypes
     */
    private function groupKey(StockMovement $movement, array $documentSourceTypes): string
    {
        return implode('|', [
            $movement->directionForRow(),
            $this->sourceType($movement, $documentSourceTypes),
            $movement->reference_id ?? '',
            $movement->reference_type ?? '',
            $movement->location_id,
        ]);
    }

    /**
     * @param  Collection<int, StockMovement>  $group
     * @param  array<string, string>  $documentSourceTypes
     * @return array<string, mixed>
     */
    private function formatNote(Collection $group, array $documentSourceTypes): array
    {
        /** @var StockMovement $first */
        $first = $group->first();
        /** @var StockMovement $latest */
        $latest = $group->sortByDesc('created_at')->first();

        return [
            'id' => hash('sha256', $this->groupKey($first, $documentSourceTypes)),
            'direction' => $first->directionForRow(),
            'source_type' => $this->sourceType($first, $documentSourceTypes),
            'source_id' => $first->reference_id,
            'source_label' => $first->reference ?: $this->sourceType($first, $documentSourceTypes),
            'location' => [
                'id' => $first->location_id,
                'name' => $first->location->name,
            ],
            'actor' => [
                'id' => $latest->user_id,
                'name' => $latest->user?->name,
            ],
            'timestamp' => $latest->created_at?->toIso8601String(),
            'lines' => $group
                ->sortBy('id')
                ->values()
                ->map(fn (StockMovement $movement): array => [
                    'movement_id' => $movement->id,
                    'product' => [
                        'id' => $movement->product_id,
                        'name' => $movement->product->name,
                    ],
                    'quantity' => (string) $movement->quantity,
                    'quantity_decimals' => $movement->product->unitOfMeasure->decimal_places ?? 4,
                    'quantity_before' => (string) $movement->quantity_before,
                    'quantity_after' => (string) $movement->quantity_after,
                    'movement_type' => $movement->movement_type->value,
                    'reason' => $movement->reason?->value,
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<string, string>  $documentSourceTypes
     */
    private function sourceType(StockMovement $movement, array $documentSourceTypes): string
    {
        if ($movement->reference_type === Document::class || $movement->reference_type === 'Document') {
            if ($movement->reference_id === null) {
                return 'document';
            }

            return $documentSourceTypes[$movement->reference_id] ?? 'document';
        }

        return $movement->reference_type ?? 'manual';
    }

    private function directionSql(string $alias): string
    {
        return "CASE WHEN {$alias}.quantity_after > {$alias}.quantity_before THEN 'in' WHEN {$alias}.quantity_after < {$alias}.quantity_before THEN 'out' ELSE 'neutral' END";
    }

    /**
     * @param  EloquentBuilder<StockMovement>|QueryBuilder  $query
     */
    private function applyDirectionFilter(EloquentBuilder|QueryBuilder $query, string $alias, string $direction): void
    {
        if ($direction === 'in') {
            $query->whereRaw("{$alias}.quantity_after > {$alias}.quantity_before");

            return;
        }

        if ($direction === 'out') {
            $query->whereRaw("{$alias}.quantity_after < {$alias}.quantity_before");
        }
    }
}
