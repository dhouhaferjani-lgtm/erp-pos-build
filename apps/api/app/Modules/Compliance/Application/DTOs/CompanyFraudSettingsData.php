<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\DTOs;

use App\Modules\Compliance\Domain\CompanyFraudSettings;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Canonical DTO for the /api/v1/fraud-settings endpoint payload.
 *
 * Mirrors the snake_case shape the FE has consumed since the cash-counting
 * remediation. Generated TypeScript counterpart is emitted into
 * packages/shared/types/generated.d.ts and replaces the hand-written
 * `apps/web/src/features/compliance/types/fraud.ts#FraudSettings`
 * (Q2 deferred-item M5).
 *
 * `is_configured` is derived from whether a CompanyFraudSettings row
 * has been persisted for the company; it is exposed alongside the
 * settings columns so the FE can render the "not yet configured"
 * banner without a second request.
 */
#[TypeScript]
final class CompanyFraudSettingsData extends Data
{
    /**
     * @param  list<string>|null  $alert_emails
     */
    public function __construct(
        public readonly ?string $id,
        public readonly string $company_id,
        public readonly int $abandoned_draft_threshold,
        public readonly int $time_window_days,
        public readonly ?array $alert_emails,
        public readonly bool $alert_enabled,
        public readonly bool $auto_trigger_counting,
        public readonly bool $auto_restrict_access,
        public readonly string $cash_variance_over_soft,
        public readonly string $cash_variance_over_hard,
        public readonly string $cash_variance_under_soft,
        public readonly string $cash_variance_under_hard,
        public readonly bool $require_blind_cash_count,
        public readonly bool $require_manager_pin_above_hard,
        public readonly string $cash_variance_email_severity,
        public readonly ?string $created_at,
        public readonly ?string $updated_at,
        public readonly bool $is_configured,
    ) {}

    /**
     * Build the DTO from a persisted CompanyFraudSettings row.
     *
     * `is_configured` is always true for a persisted row.
     */
    public static function fromModel(CompanyFraudSettings $settings): self
    {
        /** @var list<string>|null $alertEmails */
        $alertEmails = $settings->alert_emails;

        return new self(
            id: $settings->id,
            company_id: $settings->company_id,
            abandoned_draft_threshold: (int) $settings->abandoned_draft_threshold,
            time_window_days: (int) $settings->time_window_days,
            alert_emails: $alertEmails,
            alert_enabled: (bool) $settings->alert_enabled,
            auto_trigger_counting: (bool) $settings->auto_trigger_counting,
            auto_restrict_access: (bool) $settings->auto_restrict_access,
            cash_variance_over_soft: (string) $settings->cash_variance_over_soft,
            cash_variance_over_hard: (string) $settings->cash_variance_over_hard,
            cash_variance_under_soft: (string) $settings->cash_variance_under_soft,
            cash_variance_under_hard: (string) $settings->cash_variance_under_hard,
            require_blind_cash_count: (bool) $settings->require_blind_cash_count,
            require_manager_pin_above_hard: (bool) $settings->require_manager_pin_above_hard,
            cash_variance_email_severity: (string) $settings->cash_variance_email_severity,
            created_at: $settings->created_at->toIso8601String(),
            updated_at: $settings->updated_at->toIso8601String(),
            is_configured: true,
        );
    }

    /**
     * Build the DTO from default values for a company that does not yet
     * have a persisted row. `is_configured` is false in this branch.
     */
    public static function fromDefaults(string $companyId): self
    {
        $defaults = CompanyFraudSettings::getDefaults();

        return new self(
            id: null,
            company_id: $companyId,
            abandoned_draft_threshold: (int) $defaults['abandoned_draft_threshold'],
            time_window_days: (int) $defaults['time_window_days'],
            alert_emails: null,
            alert_enabled: (bool) $defaults['alert_enabled'],
            auto_trigger_counting: (bool) $defaults['auto_trigger_counting'],
            auto_restrict_access: (bool) $defaults['auto_restrict_access'],
            cash_variance_over_soft: (string) $defaults['cash_variance_over_soft'],
            cash_variance_over_hard: (string) $defaults['cash_variance_over_hard'],
            cash_variance_under_soft: (string) $defaults['cash_variance_under_soft'],
            cash_variance_under_hard: (string) $defaults['cash_variance_under_hard'],
            require_blind_cash_count: (bool) $defaults['require_blind_cash_count'],
            require_manager_pin_above_hard: (bool) $defaults['require_manager_pin_above_hard'],
            cash_variance_email_severity: (string) $defaults['cash_variance_email_severity'],
            created_at: null,
            updated_at: null,
            is_configured: false,
        );
    }
}
