<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Taxation\Presentation\Resources\TaxConfigurationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class TaxConfigurationController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * List all tax configurations for current company's country
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $company = $this->companyContext->requireCompany();

        $query = TaxConfiguration::query()
            ->where('country_code', $company->country_code);

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Filter by tax type
        if ($request->has('tax_type')) {
            $query->where('tax_type', $request->input('tax_type'));
        }

        $configurations = $query->ordered()->get();

        return TaxConfigurationResource::collection($configurations);
    }

    /**
     * Get a single tax configuration
     */
    public function show(string $id): TaxConfigurationResource
    {
        // api.taxation.008: tax_configurations is a country-scoped global
        // reference table (no tenant_id / company_id columns; the table FK
        // points to countries.code). The cluster invariant for global-
        // reference tables is `country_code` scoping per the master-plan
        // kickoff brief — annotate as
        // structurally_protected_by_country_scoped_reference rather than
        // forcing a tenant_id predicate that would break FK semantics.
        $company = $this->companyContext->requireCompany();
        $configuration = TaxConfiguration::where('country_code', $company->country_code)
            ->findOrFail($id);

        return new TaxConfigurationResource($configuration);
    }

    /**
     * Create a new tax configuration
     */
    public function store(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $validated = $request->validate([
            'tax_type' => ['required', 'string', 'in:PERCENTAGE,FIXED_AMOUNT'],
            'name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:50'],
            'percentage_rate' => ['nullable', 'required_if:tax_type,PERCENTAGE', 'numeric', 'min:0', 'max:100'],
            'fixed_amount' => ['nullable', 'required_if:tax_type,FIXED_AMOUNT', 'numeric', 'min:0'],
            'applies_to' => ['required', 'string', 'in:LINE_ITEMS,DOCUMENT_TOTAL'],
            'sequence_order' => ['nullable', 'integer', 'min:1'],
            'stacks_on' => ['nullable', 'string', 'in:SUBTOTAL,TOTAL_INCLUDING_PREVIOUS'],
            'applicable_document_types' => ['nullable', 'array'],
            'applicable_document_types.*' => ['string'],
            'is_active' => ['boolean'],
            'is_recoverable' => ['boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        // Auto-generate code if not provided
        if (empty($validated['code'])) {
            $validated['code'] = strtoupper(str_replace(' ', '_', $validated['name']));
        }

        // Set country code from current company
        $validated['country_code'] = $company->country_code;

        // Set default sequence order if not provided
        if (! isset($validated['sequence_order'])) {
            $maxSequence = TaxConfiguration::where('country_code', $company->country_code)
                ->max('sequence_order') ?? 0;
            $validated['sequence_order'] = $maxSequence + 1;
        }

        // Set default stacks_on if not provided
        if (! isset($validated['stacks_on'])) {
            $validated['stacks_on'] = 'SUBTOTAL';
        }

        $configuration = TaxConfiguration::create($validated);

        return response()->json([
            'data' => new TaxConfigurationResource($configuration),
        ], 201);
    }

    /**
     * Update a tax configuration
     */
    public function update(Request $request, string $id): TaxConfigurationResource
    {
        // api.taxation.009: country-scoped (see show() comment).
        $company = $this->companyContext->requireCompany();
        $configuration = TaxConfiguration::where('country_code', $company->country_code)
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:50'],
            'percentage_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fixed_amount' => ['nullable', 'numeric', 'min:0'],
            'sequence_order' => ['nullable', 'integer', 'min:1'],
            'stacks_on' => ['nullable', 'string', 'in:SUBTOTAL,TOTAL_INCLUDING_PREVIOUS'],
            'applicable_document_types' => ['nullable', 'array'],
            'applicable_document_types.*' => ['string'],
            'is_active' => ['boolean'],
            'is_recoverable' => ['boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        $configuration->update($validated);

        return new TaxConfigurationResource($configuration);
    }

    /**
     * Soft delete (deactivate) a tax configuration
     */
    public function destroy(string $id): JsonResponse
    {
        // api.taxation.010: country-scoped (see show() comment).
        $company = $this->companyContext->requireCompany();
        $configuration = TaxConfiguration::where('country_code', $company->country_code)
            ->findOrFail($id);

        // Don't hard delete, just deactivate
        $configuration->update(['is_active' => false]);

        return response()->json(null, 204);
    }

    /**
     * Reorder tax configurations
     */
    public function reorder(Request $request): JsonResponse
    {
        // api.taxation.007: country-scoped exists validator. Same rationale
        // as show/update/destroy — tax_configurations is a country-scoped
        // global reference, structurally_protected_by_country_scoped_reference.
        $company = $this->companyContext->requireCompany();

        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*.id' => [
                'required',
                'string',
                Rule::exists('tax_configurations', 'id')
                    ->where('country_code', $company->country_code),
            ],
            'order.*.sequence_order' => ['required', 'integer', 'min:1'],
        ]);

        foreach ($validated['order'] as $item) {
            TaxConfiguration::where('country_code', $company->country_code)
                ->where('id', $item['id'])
                ->update(['sequence_order' => $item['sequence_order']]);
        }

        return response()->json(['message' => 'Tax configurations reordered successfully']);
    }

    /**
     * Get available document types for dropdown
     */
    public function documentTypes(): JsonResponse
    {
        // Get document types from the DocumentType enum
        $documentTypes = [
            ['value' => 'QUOTATION', 'label' => 'Quotation'],
            ['value' => 'SALES_ORDER', 'label' => 'Sales Order'],
            ['value' => 'DELIVERY_NOTE', 'label' => 'Delivery Note'],
            ['value' => 'TAX_INVOICE', 'label' => 'Tax Invoice'],
            ['value' => 'FISCAL_RECEIPT', 'label' => 'Fiscal Receipt'],
            ['value' => 'CREDIT_NOTE', 'label' => 'Credit Note'],
            ['value' => 'PURCHASE_ORDER', 'label' => 'Purchase Order'],
            ['value' => 'PURCHASE_INVOICE', 'label' => 'Purchase Invoice'],
        ];

        return response()->json(['data' => $documentTypes]);
    }
}
