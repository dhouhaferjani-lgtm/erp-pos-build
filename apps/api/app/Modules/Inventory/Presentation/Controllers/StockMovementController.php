<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationScopeResolver;
use App\Modules\Document\Domain\Document;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Inventory\Presentation\Requests\ListStockMovementsRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;

/**
 * READ-ONLY after DPA V7.
 *
 * The four raw WRITE endpoints (receive / issue / transfer / adjust) were
 * deleted in D2: they wrote unjustified signed stock deltas — no `reason`, no
 * document, and an absolute `new_quantity` that silently overwrote anything
 * committed between the browser read and the POST. Every write now goes through
 * a document (stock_adjustments, stock transfers, goods receipts, delivery
 * notes, batch write-offs). EntryExitNoteController still reads the same ledger.
 *
 * Request hygiene S-2: `index()` is unconditionally bounded (default 25, max
 * 100), validates every accepted input through ListStockMovementsRequest,
 * filters `movement_type`/`reason`/`search` server-side, orders deterministically
 * by `created_at DESC, id DESC` so page boundaries neither duplicate nor omit a
 * row, and resolves source documents in ONE query per page instead of one per row.
 */
class StockMovementController extends Controller
{
    /**
     * Movement reference_type values that denote a Document linkage. The
     * pre-DPA rows carry the bare morph alias, newer ones the FQCN.
     */
    private const DOCUMENT_REFERENCE_TYPES = ['Document', Document::class];

    private const DEFAULT_PER_PAGE = 25;

    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly LocationScopeResolver $locationScopeResolver,
    ) {}

    public function index(ListStockMovementsRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $validated = $request->validated();

        /** @var User $user */
        $user = $request->user();
        $requestedLocationIds = array_key_exists('location_ids', $validated)
            ? array_values($validated['location_ids'])
            : (($validated['location_id'] ?? null) !== null ? [(string) $validated['location_id']] : []);
        $locationIds = $this->locationScopeResolver->resolve($user, $requestedLocationIds);

        // Both predicates required: tenant_id alone leaks same-tenant
        // cross-company movement history when a user with multi-company
        // membership selects company A but the query returns company B
        // rows. (api.inventory Codex round-1 Finding 1.)
        $query = StockMovement::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->with(['product.unitOfMeasure', 'location', 'user', 'reversalOf']);

        if (is_string($validated['product_id'] ?? null)) {
            $query->where('product_id', $validated['product_id']);
        }

        $query->whereIn('location_id', $locationIds);

        $search = $validated['search'] ?? null;
        if (is_string($search) && $search !== '') {
            // Portable across PostgreSQL and SQLite: LOWER() + a bound pattern
            // with an explicit ESCAPE character, so `%` and `_` typed by an
            // operator stay literal instead of becoming wildcards.
            $escapedSearch = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search));
            $pattern = '%'.$escapedSearch.'%';
            $query->where(static function (Builder $searchQuery) use ($pattern): void {
                $searchQuery->whereRaw("LOWER(stock_movements.reference) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereHas('product', static function (Builder $productQuery) use ($pattern): void {
                        $productQuery->whereRaw("LOWER(products.name) LIKE ? ESCAPE '!'", [$pattern])
                            ->orWhereRaw("LOWER(products.sku) LIKE ? ESCAPE '!'", [$pattern]);
                    });
            });
        }

        $movementType = $validated['movement_type'] ?? null;
        if ($movementType === 'transfer') {
            $query->whereIn('movement_type', [
                MovementType::TransferIn->value,
                MovementType::TransferOut->value,
            ]);
        } elseif (is_string($movementType)) {
            $query->where('movement_type', $movementType);
        }

        if (($validated['reason'] ?? null) === 'write_off') {
            $query->whereIn('reason', [
                MovementReason::WriteOff->value,
                MovementReason::Expiry->value,
                MovementReason::Damage->value,
            ]);
        } elseif (is_string($validated['reason'] ?? null)) {
            $query->where('reason', $validated['reason']);
        }

        $perPage = (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE);
        $page = (int) ($validated['page'] ?? 1);
        // `id` breaks created_at ties so page 2 cannot repeat or drop a row that
        // page 1 already returned.
        $movements = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $documentIds = $movements->getCollection()
            ->filter(static fn (StockMovement $movement): bool => $movement->reference_id !== null
                && in_array($movement->reference_type, self::DOCUMENT_REFERENCE_TYPES, true))
            ->pluck('reference_id')
            ->filter(static fn (mixed $id): bool => is_string($id))
            ->unique()
            ->values();

        /** @var Collection<string, Document> $sourceDocumentsById */
        $sourceDocumentsById = Document::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->whereIn('id', $documentIds)
            ->get()
            ->keyBy('id');

        return response()->json([
            'data' => $movements->getCollection()
                ->map(fn (StockMovement $movement): array => $this->formatMovement($movement, $sourceDocumentsById))
                ->values(),
            'meta' => [
                'current_page' => $movements->currentPage(),
                'last_page' => $movements->lastPage(),
                'per_page' => $movements->perPage(),
                'total' => $movements->total(),
                'from' => $movements->firstItem(),
                'to' => $movements->lastItem(),
            ],
        ]);
    }

    /**
     * @param  Collection<string, Document>  $sourceDocumentsById
     * @return array<string, mixed>
     */
    private function formatMovement(StockMovement $movement, Collection $sourceDocumentsById): array
    {
        $sourceDocument = $movement->reference_id !== null
            && in_array($movement->reference_type, self::DOCUMENT_REFERENCE_TYPES, true)
            ? $sourceDocumentsById->get($movement->reference_id)
            : null;

        return [
            'id' => $movement->id,
            'product_id' => $movement->product_id,
            'product_name' => $movement->product->name,
            'location_id' => $movement->location_id,
            'location_name' => $movement->location->name,
            'movement_type' => $movement->movement_type->value,
            // Expose the MovementReason value so the frontend can identify
            // write-offs (reason = 'write_off') vs other issues.
            'reason' => $movement->reason?->value,
            'quantity' => $movement->quantity,
            'quantity_decimals' => $movement->product->unitOfMeasure->decimal_places ?? 4,
            'quantity_before' => $movement->quantity_before,
            'quantity_after' => $movement->quantity_after,
            'reference' => $movement->reference,
            // The raw document-linkage morph type (StockMovementReferenceType and
            // the pre-DPA legacy conventions). Exposed so the client can gate
            // actions that are only valid for SOME write-offs — a POS return scrap
            // carries reason=write_off but is NOT reversible through
            // ReverseWriteOffService (DPA V10 gate C3).
            'reference_type' => $movement->reference_type,
            'source_document_id' => $sourceDocument?->id,
            'source_document_type' => $sourceDocument?->type->value,
            'notes' => $movement->notes,
            'user_id' => $movement->user_id,
            'user_name' => $movement->user?->name,
            // The UUID of the original movement that this row corrects, or null.
            'reverses_movement_id' => $movement->reverses_movement_id,
            // True when another movement has reversed THIS row (reversalOf loaded
            // in index(); uses relationLoaded() guard for single-item endpoints).
            'is_reversed' => $movement->relationLoaded('reversalOf')
                ? $movement->reversalOf !== null
                : false,
            'created_at' => $movement->created_at?->toIso8601String(),
        ];
    }
}
