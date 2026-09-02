<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Modules\Identity\Domain\User;
use App\Modules\Procurement\Application\PurchaseBonusGate;
use App\Modules\SmartPrompts\Domain\Enums\SmartPromptsVariant;
use App\Modules\Tenant\Domain\Tenant;
use App\Services\CompanyConfigService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
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
        private readonly CompanyConfigService $configService,
        private readonly PurchaseBonusGate $purchaseBonusGate,
        private readonly ConfigRepository $configuration,
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

        if (! $requestUser instanceof User) {
            abort(401, 'User must be authenticated');
        }

        /** @var User $user */
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
        $platformApiKey = $this->configuration->get('services.platform.api_key');
        $platformImportEnrichmentAvailable = is_string($platformApiKey)
            && trim($platformApiKey) !== ''
            && $tenant->vertical->platformVertical() !== null;

        // Return configuration as array
        return response()->json([
            'data' => [
                'vertical' => $config->vertical->value,
                'default_modules' => $config->defaultModules,
                'enabled_extras' => $config->enabledExtras,
                'compatible_extras' => $config->compatibleExtras,
                'all_enabled_modules' => $config->allEnabledModules,
                'purchase_bonus_enabled' => $company !== null && $this->purchaseBonusGate->enabledFor($company),
                'platform_import_enrichment_available' => $platformImportEnrichmentAvailable,
                'currency' => $currency,
                'locale' => $locale,
                'country_code' => $countryCode,
                'receipt_visibility' => [
                    'show_vat_breakdown' => (bool) ($company?->receipt_show_vat_breakdown ?? true),
                    'show_fiscal_info' => (bool) ($company?->receipt_show_fiscal_info ?? true),
                    'show_payment_details' => (bool) ($company?->receipt_show_payment_details ?? true),
                    'show_customer' => (bool) ($company?->receipt_show_customer ?? true),
                ],
                'smart_prompts_enabled' => (bool) $company?->smart_prompts_enabled,
                'allow_cross_location_stock_view' => (bool) $company?->allow_cross_location_stock_view,
                'line_designation_override_enabled' => (bool) $company?->line_designation_override_enabled,
                'smart_prompts_variant' => $company?->getAttribute('smart_prompts_variant') instanceof SmartPromptsVariant
                    ? $company->getAttribute('smart_prompts_variant')->value
                    : 'off',
            ],
        ]);
    }
}
