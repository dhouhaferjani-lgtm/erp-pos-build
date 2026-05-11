<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentAdditionalCost;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DocumentAdditionalCostController extends Controller
{
    /**
     * List additional costs for a document
     */
    #[CrossTenantRoute(reason: 'KNOWN TENANT-ISOLATION GAP — Document Route Model Binding does NOT auto-scope by tenant: the Document model has only scopeForTenant($tenantId) (a named local scope, not a global scope), so binding `Document $document` resolves any document by id regardless of tenant. The route-group permission gate `can:documents.view` does not validate document-tenant alignment. Tracked for future api.document cluster fix; this attribute is the static-classification placeholder until the gap is closed by adding a global scope on Document or explicit tenant validation in the controller.')]
    public function index(Document $document): JsonResponse
    {
        $costs = $document->additionalCosts()->get();

        return response()->json([
            'data' => $costs,
        ]);
    }

    /**
     * Store a new additional cost
     */
    #[CrossTenantRoute(reason: 'KNOWN TENANT-ISOLATION GAP — Document Route Model Binding without tenant global scope (mirrors index shape). Tracked for future api.document cluster fix.')]
    public function store(Request $request, Document $document): JsonResponse
    {
        $validated = $request->validate([
            'cost_type' => 'required|string|in:transport,shipping,insurance,customs,handling,other',
            'description' => 'nullable|string|max:255',
            'amount' => 'required|numeric|min:0',
            'expense_document_id' => 'nullable|uuid|exists:documents,id',
        ]);

        $cost = DocumentAdditionalCost::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $document->id,
            'cost_type' => $validated['cost_type'],
            'description' => $validated['description'] ?? null,
            'amount' => $validated['amount'],
            'expense_document_id' => $validated['expense_document_id'] ?? null,
        ]);

        return response()->json([
            'data' => $cost,
        ], 201);
    }

    /**
     * Update an additional cost
     */
    #[CrossTenantRoute(reason: 'KNOWN TENANT-ISOLATION GAP — Document + DocumentAdditionalCost Route Model Bindings without tenant global scope (mirrors index shape). The controller does validate $cost->document_id === $document->id alignment, but $document itself can be from any tenant. Tracked for future api.document cluster fix.')]
    public function update(Request $request, Document $document, DocumentAdditionalCost $cost): JsonResponse
    {
        // Ensure cost belongs to document
        if ($cost->document_id !== $document->id) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_COST',
                    'message' => 'Cost does not belong to this document',
                ],
            ], 404);
        }

        $validated = $request->validate([
            'cost_type' => 'sometimes|required|string|in:transport,shipping,insurance,customs,handling,other',
            'description' => 'nullable|string|max:255',
            'amount' => 'sometimes|required|numeric|min:0',
            'expense_document_id' => 'nullable|uuid|exists:documents,id',
        ]);

        $cost->update($validated);

        return response()->json([
            'data' => $cost->fresh(),
        ]);
    }

    /**
     * Delete an additional cost
     */
    #[CrossTenantRoute(reason: 'KNOWN TENANT-ISOLATION GAP — Document + DocumentAdditionalCost Route Model Bindings without tenant global scope (mirrors update shape). Tracked for future api.document cluster fix.')]
    public function destroy(Document $document, DocumentAdditionalCost $cost): JsonResponse
    {
        // Ensure cost belongs to document
        if ($cost->document_id !== $document->id) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_COST',
                    'message' => 'Cost does not belong to this document',
                ],
            ], 404);
        }

        $cost->delete();

        return response()->json(null, 204);
    }

    /**
     * Get landed cost breakdown showing how additional costs are allocated to lines
     */
    #[CrossTenantRoute(reason: 'KNOWN TENANT-ISOLATION GAP — Document Route Model Binding without tenant global scope (mirrors index shape). Read-only allocation calculation; gap discloses cross-tenant document line totals. Tracked for future api.document cluster fix.')]
    public function landedCostBreakdown(Document $document): JsonResponse
    {
        $lines = $document->lines()->with('product')->get();
        $additionalCostsTotal = (float) $document->additionalCosts()->sum('amount');
        $subtotal = (float) $lines->sum('line_total');

        $allocations = [];

        foreach ($lines as $line) {
            $lineTotal = (float) $line->line_total;
            $quantity = (float) $line->quantity;
            $unitPrice = (float) $line->unit_price;

            // Calculate proportion of total
            $proportion = $subtotal > 0 ? $lineTotal / $subtotal : 0;

            // Calculate allocated costs
            $allocatedCosts = $additionalCostsTotal > 0 && $subtotal > 0
                ? round($additionalCostsTotal * $proportion, 2)
                : 0;

            // Calculate landed unit cost
            $landedUnitCost = $quantity > 0
                ? round(($lineTotal + $allocatedCosts) / $quantity, 2)
                : $unitPrice;

            $product = $line->product;
            $allocations[] = [
                'line_id' => $line->id,
                'product_name' => $product !== null ? $product->name : ($line->description ?? 'Unknown Product'),
                'description' => $line->description ?? '',
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'allocated_costs' => $allocatedCosts,
                'landed_unit_cost' => $landedUnitCost,
                'proportion' => $proportion,
            ];
        }

        return response()->json([
            'data' => [
                'document_id' => $document->id,
                'total_additional_costs' => $additionalCostsTotal,
                'allocations' => $allocations,
            ],
        ]);
    }
}
