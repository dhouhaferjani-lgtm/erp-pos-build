<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain;

use App\Modules\Company\Domain\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Company $company
 */
class CompanyFraudSettings extends Model
{
    use HasUuids;

    protected $table = 'company_fraud_settings';

    protected $fillable = [
        'company_id',
        'abandoned_draft_threshold',
        'time_window_days',
        'alert_emails',
        'alert_enabled',
        'auto_trigger_counting',
        'auto_restrict_access',
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
     * @return array{abandoned_draft_threshold: int, time_window_days: int, alert_enabled: bool, auto_trigger_counting: bool, auto_restrict_access: bool}
     */
    public static function getDefaults(): array
    {
        return [
            'abandoned_draft_threshold' => 5,
            'time_window_days' => 30,
            'alert_enabled' => true,
            'auto_trigger_counting' => true,
            'auto_restrict_access' => false,
        ];
    }
}
