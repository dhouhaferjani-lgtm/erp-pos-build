<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Application\Services\FraudSettingsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller for exposing company fraud settings to the POS terminal.
 *
 * GET /api/v1/pos/fraud-settings
 *
 * Returns the authenticated terminal's company fraud settings so the POS can
 * cache them in the local SQLite company_fraud_settings_cache table for offline use.
 *
 * No additional gate — any authenticated POS user may read these per-company settings.
 */
final class FraudSettingsPosController extends Controller
{
    public function __construct(
        private readonly FraudSettingsResolver $resolver,
        private readonly CompanyContext $companyContext,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $dto = $this->resolver->forCompany($companyId);

        return response()->json(['data' => $dto->toArray()]);
    }
}
