<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Domain\CompanyFraudSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller for managing company fraud detection settings.
 *
 * Allows admins to configure:
 * - Detection thresholds
 * - Alert preferences
 * - Auto-action triggers
 */
class FraudSettingsController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Get fraud detection settings for current company.
     *
     * @group Compliance
     * @subgroup Fraud Detection
     */
    public function show(): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $settings = CompanyFraudSettings::where('company_id', $companyId)->first();

        // Return defaults if not yet configured
        if ($settings === null) {
            return response()->json([
                'data' => array_merge(
                    CompanyFraudSettings::getDefaults(),
                    [
                        'company_id' => $companyId,
                        'is_configured' => false,
                    ]
                ),
            ]);
        }

        return response()->json([
            'data' => array_merge(
                $settings->toArray(),
                ['is_configured' => true]
            ),
        ]);
    }

    /**
     * Update fraud detection settings.
     *
     * @group Compliance
     * @subgroup Fraud Detection
     */
    public function update(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $validated = $request->validate([
            'abandoned_draft_threshold' => 'sometimes|integer|min:1|max:100',
            'time_window_days' => 'sometimes|integer|min:1|max:365',
            'alert_emails' => 'sometimes|array',
            'alert_emails.*' => 'email',
            'alert_enabled' => 'sometimes|boolean',
            'auto_trigger_counting' => 'sometimes|boolean',
            'auto_restrict_access' => 'sometimes|boolean',
        ]);

        $settings = CompanyFraudSettings::updateOrCreate(
            ['company_id' => $companyId],
            $validated
        );

        return response()->json([
            'data' => $settings,
            'message' => 'Fraud detection settings updated successfully',
        ]);
    }

    /**
     * Reset fraud detection settings to defaults.
     *
     * @group Compliance
     * @subgroup Fraud Detection
     */
    public function reset(): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $settings = CompanyFraudSettings::updateOrCreate(
            ['company_id' => $companyId],
            CompanyFraudSettings::getDefaults()
        );

        return response()->json([
            'data' => $settings,
            'message' => 'Fraud detection settings reset to defaults',
        ]);
    }
}
