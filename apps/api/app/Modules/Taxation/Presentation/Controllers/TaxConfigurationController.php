<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Taxation\Application\Registries\CountryTaxConfigurationRegistry;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Taxation\Presentation\Resources\TaxConfigurationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TaxConfigurationController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly CountryTaxConfigurationRegistry $countryRegistry,
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
            'percentage_rate' => ['nullable', 'required_if:tax_type,PERCENTAGE', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'fixed_amount' => ['nullable', 'required_if:tax_type,FIXED_AMOUNT', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'applies_to' => ['required', 'string', 'in:LINE_ITEMS,DOCUMENT_TOTAL'],
            'sequence_order' => ['nullable', 'integer', 'min:1'],
            'stacks_on' => ['nullable', 'string', 'in:SUBTOTAL,TOTAL_INCLUDING_PREVIOUS'],
            'applicable_document_types' => ['nullable', 'array'],
            'applicable_document_types.*' => ['string'],
            'is_active' => ['boolean'],
            'is_recoverable' => ['boolean'],
            'is_stamp_duty' => ['boolean'],
            'is_default' => ['boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'metadata' => ['nullable', 'array'],
        ], [
            'percentage_rate.regex' => 'The percentage rate must have at most 2 decimal places.',
            'fixed_amount.regex' => 'The fixed amount must have at most 3 decimal places.',
        ]);

        $this->validateDocumentTotalPolicy(
            $company->country_code,
            $validated['applies_to'],
            (bool) ($validated['is_stamp_duty'] ?? false),
        );

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
            'tax_type' => ['sometimes', 'string', 'in:PERCENTAGE,FIXED_AMOUNT'],
            'name' => ['sometimes', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:50'],
            'percentage_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d+(\.\d{1,2})?$/'],
            'fixed_amount' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
            'applies_to' => ['sometimes', 'string', 'in:LINE_ITEMS,DOCUMENT_TOTAL'],
            'sequence_order' => ['nullable', 'integer', 'min:1'],
            'stacks_on' => ['nullable', 'string', 'in:SUBTOTAL,TOTAL_INCLUDING_PREVIOUS'],
            'applicable_document_types' => ['nullable', 'array'],
            'applicable_document_types.*' => ['string'],
            'is_active' => ['boolean'],
            'is_recoverable' => ['boolean'],
            'is_stamp_duty' => ['boolean'],
            'is_default' => ['boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'metadata' => ['nullable', 'array'],
        ], [
            'percentage_rate.regex' => 'The percentage rate must have at most 2 decimal places.',
            'fixed_amount.regex' => 'The fixed amount must have at most 3 decimal places.',
        ]);

        $this->validateDocumentTotalPolicy(
            $company->country_code,
            $validated['applies_to'] ?? $configuration->applies_to->value,
            (bool) ($validated['is_stamp_duty'] ?? $configuration->is_stamp_duty),
        );

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
     * Get the applicability tokens admins can tag a tax to.
     *
     * These MUST be the values TaxCalculationService keys on — the document's
     * `fiscal_category` (a NOT NULL column whose domain is the FiscalCategory
     * enum). Sourcing the list straight from the enum keeps the dropdown and the
     * matcher in lockstep; an ad-hoc list (e.g. "QUOTATION") would offer tokens
     * that silently never match a real document.
     */
    public function documentTypes(): JsonResponse
    {
        $documentTypes = array_map(
            fn (FiscalCategory $category): array => [
                'value' => $category->value,
                'label' => $category->label(),
            ],
            FiscalCategory::cases(),
        );

        return response()->json(['data' => $documentTypes]);
    }

    public function capabilities(): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        return response()->json([
            'data' => [
                'supports_stamp_duty' => $this->countryRegistry->supportsStampDuty($company->country_code),
            ],
        ]);
    }

    private function validateDocumentTotalPolicy(
        string $countryCode,
        string $appliesTo,
        bool $isStampDuty,
    ): void {
        if ($isStampDuty && ! $this->countryRegistry->supportsStampDuty($countryCode)) {
            throw ValidationException::withMessages([
                'is_stamp_duty' => ['Stamp duty is not supported for this company country.'],
            ]);
        }

        if ($appliesTo === 'DOCUMENT_TOTAL' && ! $isStampDuty) {
            throw ValidationException::withMessages([
                'applies_to' => ['DOCUMENT_TOTAL is reserved for supported stamp-duty configurations.'],
            ]);
        }
    }
}
