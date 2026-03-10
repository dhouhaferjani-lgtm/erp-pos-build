<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Treasury\Domain\CountryPaymentSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Country extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'native_name',
        'currency_code',
        'currency_symbol',
        'currency_decimal_places',
        'phone_prefix',
        'date_format',
        'default_locale',
        'default_timezone',
        'is_active',
        'tax_id_label',
        'tax_id_regex',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'currency_decimal_places' => 'integer',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the tax rates for the country.
     *
     * @return HasMany<CountryTaxRate, $this>
     */
    public function taxRates(): HasMany
    {
        return $this->hasMany(CountryTaxRate::class, 'country_code', 'code');
    }

    /**
     * Get the payment settings for the country.
     *
     * @return HasOne<CountryPaymentSettings, $this>
     */
    public function paymentSettings(): HasOne
    {
        return $this->hasOne(CountryPaymentSettings::class, 'country_code', 'code');
    }
}
