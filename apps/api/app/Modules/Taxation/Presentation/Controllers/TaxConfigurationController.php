<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Controllers;

use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Taxation\Presentation\Resources\TaxConfigurationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class TaxConfigurationController extends Controller
{
    /**
     * List all tax configurations
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = TaxConfiguration::query();

        // Filter by country if provided
        if ($request->has('country_code')) {
            $query->where('country_code', $request->input('country_code'));
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Filter by tax type
        if ($request->has('tax_type')) {
            $query->where('tax_type', $request->input('tax_type'));
        }

        $configurations = $query->orderBy('country_code')
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get();

        return TaxConfigurationResource::collection($configurations);
    }

    /**
     * Get a single tax configuration
     */
    public function show(string $id): TaxConfigurationResource
    {
        $configuration = TaxConfiguration::findOrFail($id);

        return new TaxConfigurationResource($configuration);
    }

    /**
     * Create a new tax configuration
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country_code' => ['required', 'string', 'size:2', 'exists:countries,code'],
            'tax_type' => ['required', 'string', 'in:PERCENTAGE,FIXED_AMOUNT'],
            'name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:20'],
            'percentage_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fixed_amount' => ['nullable', 'numeric', 'min:0'],
            'applies_to' => ['required', 'string', 'in:LINE_ITEMS,DOCUMENT_TOTAL'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

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
        $configuration = TaxConfiguration::findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:20'],
            'percentage_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fixed_amount' => ['nullable', 'numeric', 'min:0'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        $configuration->update($validated);

        return new TaxConfigurationResource($configuration);
    }

    /**
     * Delete a tax configuration
     */
    public function destroy(string $id): JsonResponse
    {
        $configuration = TaxConfiguration::findOrFail($id);
        $configuration->delete();

        return response()->json(null, 204);
    }
}
