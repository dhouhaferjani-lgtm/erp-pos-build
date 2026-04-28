<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain;

use App\Modules\Company\Domain\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
// NOTE: The Company import above is retained only for the Eloquent BelongsTo
// relationship declaration. Cross-module logic (vertical checks, ensureForCompany)
// lives in CompanyFraudSettingsService in the Application layer.
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Company-specific fraud detection configuration.
 *
 * Each company can configure:
 * - Detection thresholds (abandoned drafts, time windows)
 * - Alert preferences (emails, notifications)
 * - Auto-actions (inventory counting, access restriction)
 *
 * @property string $id
 * @property string $company_id
 * @property int $abandoned_draft_threshold
 * @property int $time_window_days
 * @property array<string>|null $alert_emails
 * @property bool $alert_enabled
 * @property bool $auto_trigger_counting
 * @property bool $auto_restrict_access
 * @property string $cash_variance_over_soft
 * @property string $cash_variance_over_hard
 * @property string $cash_variance_under_soft
 * @property string $cash_variance_under_hard
 * @property bool $require_blind_cash_count
 * @property bool $require_manager_pin_above_hard
 * @property string $cash_variance_email_severity
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Company $company
 */
class CompanyFraudSettings extends Model
{
    use HasUuids;

    protected $table = 'company_fraud_settings';

    /** @var array<string, mixed> */
    protected $attributes = [
        'cash_variance_over_soft' => '1.0000',
        'cash_variance_over_hard' => '20.0000',
        'cash_variance_under_soft' => '1.0000',
        'cash_variance_under_hard' => '20.0000',
        'require_blind_cash_count' => false,
        'require_manager_pin_above_hard' => true,
        'cash_variance_email_severity' => 'none',
    ];

    protected $fillable = [
        'company_id',
        'abandoned_draft_threshold',
        'time_window_days',
        'alert_emails',
        'alert_enabled',
        'auto_trigger_counting',
        'auto_restrict_access',
        'cash_variance_over_soft',
        'cash_variance_over_hard',
        'cash_variance_under_soft',
        'cash_variance_under_hard',
        'require_blind_cash_count',
        'require_manager_pin_above_hard',
        'cash_variance_email_severity',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'alert_emails' => 'array',
            'alert_enabled' => 'boolean',
            'auto_trigger_counting' => 'boolean',
            'auto_restrict_access' => 'boolean',
            'abandoned_draft_threshold' => 'integer',
            'time_window_days' => 'integer',
            'cash_variance_over_soft' => 'decimal:4',
            'cash_variance_over_hard' => 'decimal:4',
            'cash_variance_under_soft' => 'decimal:4',
            'cash_variance_under_hard' => 'decimal:4',
            'require_blind_cash_count' => 'boolean',
            'require_manager_pin_above_hard' => 'boolean',
        ];
    }

    /**
     * Get the company that owns these fraud settings.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the default fraud detection settings.
     *
     * @return array{abandoned_draft_threshold: int, time_window_days: int, alert_enabled: bool, auto_trigger_counting: bool, auto_restrict_access: bool, cash_variance_over_soft: string, cash_variance_over_hard: string, cash_variance_under_soft: string, cash_variance_under_hard: string, require_blind_cash_count: bool, require_manager_pin_above_hard: bool, cash_variance_email_severity: string}
     */
    public static function getDefaults(): array
    {
        return [
            'abandoned_draft_threshold' => 5,
            'time_window_days' => 30,
            'alert_enabled' => true,
            'auto_trigger_counting' => true,
            'auto_restrict_access' => false,
            'cash_variance_over_soft' => '1.0000',
            'cash_variance_over_hard' => '20.0000',
            'cash_variance_under_soft' => '1.0000',
            'cash_variance_under_hard' => '20.0000',
            'require_blind_cash_count' => false,
            'require_manager_pin_above_hard' => true,
            'cash_variance_email_severity' => 'none',
        ];
    }

    /**
     * Return creation defaults tuned for a specific vertical.
     *
     * Otospex (automotive) companies get blind cash counting enabled by
     * default. IziPOS (retail / all others) get it disabled. Threshold
     * amounts are identical across verticals and currencies (stored at
     * scale 4 to accommodate both EUR and TND).
     *
     * @return array{abandoned_draft_threshold: int, time_window_days: int, alert_enabled: bool, auto_trigger_counting: bool, auto_restrict_access: bool, cash_variance_over_soft: string, cash_variance_over_hard: string, cash_variance_under_soft: string, cash_variance_under_hard: string, require_blind_cash_count: bool, require_manager_pin_above_hard: bool, cash_variance_email_severity: string}
     */
    public static function defaultsForVertical(bool $isAutomotive): array
    {
        $defaults = self::getDefaults();
        $defaults['require_blind_cash_count'] = $isAutomotive;

        return $defaults;
    }
}
