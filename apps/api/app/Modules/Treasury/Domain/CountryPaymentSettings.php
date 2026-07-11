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
        'sales_discount_purpose',
        'instrument_alert_days',
    ];

    protected $casts = [
        'payment_tolerance_enabled' => 'boolean',
        'payment_tolerance_percentage' => 'string',
        'max_payment_tolerance_amount' => 'string',
        'cash_discount_enabled' => 'boolean',
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
