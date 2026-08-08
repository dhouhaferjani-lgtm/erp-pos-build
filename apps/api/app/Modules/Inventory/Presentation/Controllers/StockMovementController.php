<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationScopeResolver;
use App\Modules\Document\Domain\Document;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * READ-ONLY after DPA V7.
 *
 * The four raw WRITE endpoints (receive / issue / transfer / adjust) were
 * deleted in D2: they wrote unjustified signed stock deltas — no `reason`, no
 * document, and an absolute `new_quantity` that silently overwrote anything
 * committed between the browser read and the POST. Every write now goes through
 * a document (stock_adjustments, stock transfers, goods receipts, delivery
 * notes, batch write-offs). `index()` and `formatMovement()` are untouched, and
 * EntryExitNoteController still reads the same ledger.
 */
class StockMovementController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly LocationScopeResolver $locationScopeResolver,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $validated = $request->validate([
            'location_id' => ['sometimes', 'nullable', 'string', 'uuid'],
            'location_ids' => ['sometimes', 'array', 'list'],
            'location_ids.*' => ['string', 'uuid'],
        ]);

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

        if ($request->has('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        $query->whereIn('location_id', $locationIds);

        if ($request->has('movement_type')) {
            $query->where('movement_type', $request->input('movement_type'));
        }

        if (! $request->has('page')) {
            $movements = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'data' => $movements->map(fn (StockMovement $movement) => $this->formatMovement($movement))->values(),
            ]);
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $page = max($request->integer('page', 1), 1);
        $movements = $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $movements->getCollection()->map(fn (StockMovement $movement) => $this->formatMovement($movement))->values(),
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
     * @return array<string, mixed>
     */
    private function formatMovement(StockMovement $movement): array
    {
        $sourceDocument = $this->resolveSourceDocument($movement);

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

    private function resolveSourceDocument(StockMovement $movement): ?Document
    {
        if ($movement->reference_id === null) {
            return null;
        }

        if ($movement->reference_type !== 'Document' && $movement->reference_type !== Document::class) {
            return null;
        }

        return Document::query()
            ->where('tenant_id', $movement->tenant_id)
            ->where('company_id', $movement->company_id)
            ->find($movement->reference_id);
    }
}
