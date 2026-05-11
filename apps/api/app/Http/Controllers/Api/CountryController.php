<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Http\JsonResponse;

class CountryController extends Controller
{
    /**
     * Get all active countries
     */
    #[CrossTenantRoute(reason: 'Public reference data: lists active countries with their default VAT rates from the platform-level countries catalog (App\\Models\\Country); mounted public on /v1/countries with no auth — needed by signup forms and tenant-onboarding before any tenant context exists.')]
    public function index(): JsonResponse
    {
        $countries = Country::where('is_active', true)
            ->with('taxRates')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $countries,
        ]);
    }

    /**
     * Get a single country by code
     */
    #[CrossTenantRoute(reason: 'Public reference data: reads a single country by ISO code from the platform-level Country catalog with tax rates eager-loaded; no auth, no tenant — used by signup and onboarding forms before any tenant context exists.')]
    public function show(string $code): JsonResponse
    {
        $country = Country::with('taxRates')
            ->findOrFail($code);

        return response()->json([
            'data' => $country,
        ]);
    }
}
