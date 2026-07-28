<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain;

use App\Models\Country;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CountryPaymentSettings extends Model
{
    use HasUuids;

    protected $table = 'country_payment_settings';

    protected $fillable = [
        'country_code',
        'payment_tolerance_enabled',
        'payment_tolerance_percentage',
        'max_payment_tolerance_amount',
        'underpayment_writeoff_purpose',
        'overpayment_writeoff_purpose',
        'realized_fx_gain_purpose',
        'realized_fx_loss_purpose',
        'cash_discount_enabled',
        'cash_rounding_enabled',
        'cash_rounding_denomination',
        'pos_tolerance_enabled',
        'sales_discount_purpose',
        'instrument_alert_days',
    ];

    /**
     * `cash_rounding_denomination` is cast to `string`, never to a float or a
     * `decimal:` cast (spec §4.2 string-fidelity contract): a float cast would
     * re-serialize `0.0500` as `0.05` and every signed receipt that carries the
     * denomination in its hashed bytes would quarantine on re-verification.
     */
    protected $casts = [
        'payment_tolerance_enabled' => 'boolean',
        'payment_tolerance_percentage' => 'string',
        'max_payment_tolerance_amount' => 'string',
        'cash_discount_enabled' => 'boolean',
        'cash_rounding_enabled' => 'boolean',
        'cash_rounding_denomination' => 'string',
        'pos_tolerance_enabled' => 'boolean',
        'instrument_alert_days' => 'integer',
    ];

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_code', 'code');
    }
}
