<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Modules\Tenant\Domain\Tenant;
use App\Services\CompanyConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API controller for company configuration.
 *
 * Provides endpoints for retrieving effective configuration
 * based on tenant vertical and enabled extras.
 */
class CompanyConfigController
{
    public function __construct(
        private readonly CompanyConfigService $configService
    ) {}

    /**
     * Get effective configuration for the current user's tenant.
     *
     * Returns the vertical, default modules, enabled extras, and
     * all enabled modules for the authenticated user's tenant.
     * Also includes company settings (currency, locale) from the user's primary company.
     *
     * This endpoint is used by the frontend to determine which
     * modules are available and render dynamic navigation.
     */
    public function show(Request $request): JsonResponse
    {
        // Get authenticated user (guaranteed by auth:sanctum middleware)
        $requestUser = $request->user();

        if (! $requestUser instanceof \App\Modules\Identity\Domain\User) {
            abort(401, 'User must be authenticated');
        }

        /** @var \App\Modules\Identity\Domain\User $user */
        $user = $requestUser;

        // Get tenant from user (refresh to ensure fresh data)
        $tenant = $user->tenant()->first();

        if (! $tenant instanceof Tenant) {
            abort(500, 'Tenant not found for authenticated user');
        }

        // Get effective configuration for this tenant
        $config = $this->configService->getConfigForTenant($tenant);

        // Get user's primary company for currency and locale
        $primaryMembership = $user->companyMemberships()
            ->where('is_primary', true)
            ->where('status', 'active')
            ->with('company')
            ->first();

        $company = $primaryMembership?->company;
        $currency = $company->currency ?? 'USD';
        $locale = $company->locale ?? 'en';
        $countryCode = $company->country_code ?? null;

        // Return configuration as array
        return response()->json([
            'data' => [
                'vertical' => $config->vertical->value,
                'default_modules' => $config->defaultModules,
                'enabled_extras' => $config->enabledExtras,
                'compatible_extras' => $config->compatibleExtras,
                'all_enabled_modules' => $config->allEnabledModules,
                'currency' => $currency,
                'locale' => $locale,
                'country_code' => $countryCode,
                'receipt_visibility' => [
                    'show_vat_breakdown' => (bool) ($company?->receipt_show_vat_breakdown ?? true),
                    'show_fiscal_info' => (bool) ($company?->receipt_show_fiscal_info ?? true),
                    'show_payment_details' => (bool) ($company?->receipt_show_payment_details ?? true),
                    'show_customer' => (bool) ($company?->receipt_show_customer ?? true),
                ],
            ],
        ]);
    }
}
