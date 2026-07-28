<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentAdditionalCost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Document additional-cost management.
 *
 * dev-remediation/B.M2.3 — closed the five CrossTenantRoute annotations
 * (index, store, update, destroy, landedCostBreakdown) by replacing
 * Route Model Binding on Document / DocumentAdditionalCost with
 * CompanyContext-scoped resolution. Also tightened the
 * expense_document_id validation rule to a same-tenant exists check so
 * a cost cannot reference a foreign-tenant expense document.
 */
class DocumentAdditionalCostController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(string $document): JsonResponse
    {
        $documentModel = $this->resolveDocument($document);
        $costs = $documentModel->additionalCosts()->get();

        return response()->json([
            'data' => $costs,
        ]);
    }

    public function store(Request $request, string $document): JsonResponse
    {
        $documentModel = $this->resolveDocument($document);

        $validated = $request->validate([
            'cost_type' => 'required|string|in:transport,shipping,insurance,customs,handling,other',
            'description' => 'nullable|string|max:255',
            'amount' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'expense_document_id' => [
                'nullable',
                'uuid',
                Rule::exists('documents', 'id')
                    ->where(fn ($q) => $q
                        ->where('tenant_id', $documentModel->tenant_id)
                        ->where('company_id', $documentModel->company_id)
                    ),
            ],
        ], [
            'amount.regex' => 'The amount must have at most 3 decimal places.',
        ]);

        $cost = DocumentAdditionalCost::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $documentModel->id,
            'cost_type' => $validated['cost_type'],
            'description' => $validated['description'] ?? null,
            'amount' => $validated['amount'],
            'expense_document_id' => $validated['expense_document_id'] ?? null,
        ]);

        return response()->json([
            'data' => $cost,
        ], 201);
    }

    public function update(Request $request, string $document, string $cost): JsonResponse
    {
        $documentModel = $this->resolveDocument($document);
        $costModel = $this->resolveCost($documentModel, $cost);

        $validated = $request->validate([
            'cost_type' => 'sometimes|required|string|in:transport,shipping,insurance,customs,handling,other',
            'description' => 'nullable|string|max:255',
            'amount' => ['sometimes', 'required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'expense_document_id' => [
                'nullable',
                'uuid',
                Rule::exists('documents', 'id')
                    ->where(fn ($q) => $q
                        ->where('tenant_id', $documentModel->tenant_id)
                        ->where('company_id', $documentModel->company_id)
                    ),
            ],
        ], [
            'amount.regex' => 'The amount must have at most 3 decimal places.',
        ]);

        $costModel->update($validated);

        return response()->json([
            'data' => $costModel->fresh(),
        ]);
    }

    public function destroy(string $document, string $cost): JsonResponse
    {
        $documentModel = $this->resolveDocument($document);
        $costModel = $this->resolveCost($documentModel, $cost);

        $costModel->delete();

        return response()->json(null, 204);
    }

    public function landedCostBreakdown(string $document): JsonResponse
    {
        $documentModel = $this->resolveDocument($document);

        $lines = $documentModel->lines()->with('product.unitOfMeasure')->get();
        $additionalCostsTotal = (float) $documentModel->additionalCosts()->sum('amount');
        $subtotal = (float) $lines->sum('line_total');

        $allocations = [];

        foreach ($lines as $line) {
            $lineTotal = (float) $line->line_total;
            $quantity = (float) $line->quantity;
            $unitPrice = (float) $line->unit_price;

            $proportion = $subtotal > 0 ? $lineTotal / $subtotal : 0;

            $allocatedCosts = $additionalCostsTotal > 0 && $subtotal > 0
                ? round($additionalCostsTotal * $proportion, 2)
                : 0;

            $landedUnitCost = $quantity > 0
                ? round(($lineTotal + $allocatedCosts) / $quantity, 2)
                : $unitPrice;

            $product = $line->product;
            $quantityDecimals = $product?->unitOfMeasure?->decimal_places;
            $allocations[] = [
                'line_id' => $line->id,
                'product_name' => $product !== null ? $product->name : ($line->description ?? 'Unknown Product'),
                'description' => $line->description ?? '',
                'quantity' => $quantity,
                'quantity_decimals' => $quantityDecimals ?? 4,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'allocated_costs' => $allocatedCosts,
                'landed_unit_cost' => $landedUnitCost,
                'proportion' => $proportion,
            ];
        }

        return response()->json([
            'data' => [
                'document_id' => $documentModel->id,
                'total_additional_costs' => $additionalCostsTotal,
                'allocations' => $allocations,
            ],
        ]);
    }

    private function resolveDocument(string $documentId): Document
    {
        $company = $this->companyContext->requireCompany();

        $document = Document::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('id', $documentId)
            ->first();

        if ($document === null) {
            abort(404, 'Document not found');
        }

        return $document;
    }

    private function resolveCost(Document $document, string $costId): DocumentAdditionalCost
    {
        $cost = DocumentAdditionalCost::query()
            ->where('document_id', $document->id)
            ->where('id', $costId)
            ->first();

        if ($cost === null) {
            abort(404, 'Additional cost not found');
        }

        return $cost;
    }
}
