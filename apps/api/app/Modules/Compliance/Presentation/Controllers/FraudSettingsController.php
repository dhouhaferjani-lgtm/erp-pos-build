<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Application\DTOs\CompanyFraudSettingsData;
use App\Modules\Compliance\Domain\CompanyFraudSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Controller for managing company fraud detection settings.
 *
 * Allows admins to configure:
 * - Detection thresholds
 * - Alert preferences
 * - Auto-action triggers
 * - Cash-control thresholds and enforcement flags
 */
class FraudSettingsController extends Controller
{
    /** @var list<string> */
    private const CASH_CONTROL_KEYS = [
        'cash_variance_over_soft',
        'cash_variance_over_hard',
        'cash_variance_under_soft',
        'cash_variance_under_hard',
        'require_blind_cash_count',
        'require_manager_pin_above_hard',
        'cash_variance_email_severity',
    ];

    /**
     * Lane C M2/M3 — the refund-exposure ceilings.
     *
     * They live on `company_fraud_settings` but are NOT part of this
     * endpoint's surface: per the owner ruling (2026-08-01) they have no
     * admin UI this pass, are absent from `CompanyFraudSettingsData`, and
     * have no validation rules in {@see self::update()} (which writes only
     * `$validated`, so it is already unaffected).
     *
     * {@see self::reset()} was the one path that touched them, because
     * `CompanyFraudSettings::getDefaults()` legitimately carries them for
     * row CREATION. Resetting them here would silently WIDEN a ceiling a
     * tenant had tightened by direct DB write — on a screen that shows
     * nothing about refunds, with no UI trace and no audit of the widening —
     * and the device would pick the wider values up on its next
     * fraud-settings refresh.
     *
     * @var list<string>
     */
    private const REFUND_EXPOSURE_KEYS = [
        'offline_refund_count_ceiling',
        'offline_refund_value_ceiling',
        'online_required_refund_threshold',
    ];

    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Get fraud detection settings for current company.
     *
     * @group Compliance
     *
     * @subgroup Fraud Detection
     */
    public function show(): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $settings = CompanyFraudSettings::where('company_id', $companyId)->first();

        $dto = $settings === null
            ? CompanyFraudSettingsData::fromDefaults($companyId)
            : CompanyFraudSettingsData::fromModel($settings);

        return response()->json([
            'data' => $dto,
        ]);
    }

    /**
     * Update fraud detection settings.
     *
     * @group Compliance
     *
     * @subgroup Fraud Detection
     */
    public function update(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $touchesCashControls = count(array_intersect(self::CASH_CONTROL_KEYS, array_keys($request->all()))) > 0;

        if ($touchesCashControls) {
            Gate::authorize('pos.configure_cash_count');
        }

        $validated = $request->validate([
            'abandoned_draft_threshold' => 'sometimes|integer|min:1|max:100',
            'time_window_days' => 'sometimes|integer|min:1|max:365',
            'alert_emails' => 'sometimes|array',
            'alert_emails.*' => 'email',
            'alert_enabled' => 'sometimes|boolean',
            'auto_trigger_counting' => 'sometimes|boolean',
            'auto_restrict_access' => 'sometimes|boolean',
            'cash_variance_over_soft' => ['sometimes', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
            'cash_variance_over_hard' => ['sometimes', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
            'cash_variance_under_soft' => ['sometimes', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
            'cash_variance_under_hard' => ['sometimes', 'string', 'regex:/^\d+(\.\d{1,4})?$/'],
            'require_blind_cash_count' => 'sometimes|boolean',
            'require_manager_pin_above_hard' => 'sometimes|boolean',
            'cash_variance_email_severity' => ['sometimes', 'string', Rule::in(['none', 'info', 'warning', 'critical'])],
        ]);

        // Load persisted row so partial updates (only one half of a pair) are validated
        // against the stored counterpart. Falls back to defaults when no row exists yet.
        $persisted = CompanyFraudSettings::firstWhere('company_id', $companyId);
        $effective = $persisted?->toArray() ?? CompanyFraudSettings::getDefaults();

        $overSoft = $validated['cash_variance_over_soft'] ?? ($effective['cash_variance_over_soft'] ?? null);
        $overHard = $validated['cash_variance_over_hard'] ?? ($effective['cash_variance_over_hard'] ?? null);
        if ($overSoft !== null && $overHard !== null && $this->numericGte($overSoft, $overHard)) {
            throw ValidationException::withMessages([
                'cash_variance_over_soft' => 'over_soft must be strictly less than over_hard',
            ]);
        }

        $underSoft = $validated['cash_variance_under_soft'] ?? ($effective['cash_variance_under_soft'] ?? null);
        $underHard = $validated['cash_variance_under_hard'] ?? ($effective['cash_variance_under_hard'] ?? null);
        if ($underSoft !== null && $underHard !== null && $this->numericGte($underSoft, $underHard)) {
            throw ValidationException::withMessages([
                'cash_variance_under_soft' => 'under_soft must be strictly less than under_hard',
            ]);
        }

        $settings = CompanyFraudSettings::updateOrCreate(
            ['company_id' => $companyId],
            $validated
        );

        return response()->json([
            'data' => CompanyFraudSettingsData::fromModel($settings),
            'message' => 'Fraud detection settings updated successfully',
        ]);
    }

    /**
     * Compare two decimal strings using bcmath.
     *
     * Returns true when $a >= $b (i.e. the soft/hard constraint is violated).
     * Both values are validated as /^\d+(\.\d{1,4})?$/ before reaching this method,
     * so they are always numeric; the is_numeric check is purely to satisfy PHPStan.
     */
    private function numericGte(mixed $a, mixed $b): bool
    {
        $aStr = (string) $a;
        $bStr = (string) $b;

        if (! is_numeric($aStr) || ! is_numeric($bStr)) {
            return false;
        }

        /** @var numeric-string $aNum */
        $aNum = $aStr;
        /** @var numeric-string $bNum */
        $bNum = $bStr;

        return bccomp($aNum, $bNum, 4) >= 0;
    }

    /**
     * Reset fraud detection settings to defaults.
     *
     * Scoped to the settings this endpoint exposes. The refund-exposure
     * ceilings are excluded ({@see self::REFUND_EXPOSURE_KEYS}) — a reset
     * must not widen a ceiling that has no UI to show it. On a company with
     * no row yet, `updateOrCreate` still seeds them from the model's own
     * `$attributes`, which resolve to the same `DEFAULT_*` constants.
     *
     * @group Compliance
     *
     * @subgroup Fraud Detection
     */
    public function reset(): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        Gate::authorize('pos.configure_cash_count');

        $settings = CompanyFraudSettings::updateOrCreate(
            ['company_id' => $companyId],
            Arr::except(CompanyFraudSettings::getDefaults(), self::REFUND_EXPOSURE_KEYS)
        );

        return response()->json([
            'data' => CompanyFraudSettingsData::fromModel($settings),
            'message' => 'Fraud detection settings reset to defaults',
        ]);
    }
}
