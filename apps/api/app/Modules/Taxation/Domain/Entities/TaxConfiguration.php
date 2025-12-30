<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Entities;

use App\Modules\Taxation\Domain\Enums\TaxApplicationLevel;
use App\Modules\Taxation\Domain\Enums\TaxType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $country_code
 * @property TaxType $tax_type
 * @property string $name
 * @property string|null $code
 * @property string|null $percentage_rate
 * @property string|null $fixed_amount
 * @property TaxApplicationLevel $applies_to
 * @property bool $is_default
 * @property bool $is_active
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class TaxConfiguration extends Model
{
    use HasUuids;

    protected $table = 'tax_configurations';

    protected $fillable = [
        'country_code',
        'tax_type',
        'name',
        'code',
        'percentage_rate',
        'fixed_amount',
        'applies_to',
        'is_default',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'tax_type' => TaxType::class,
        'applies_to' => TaxApplicationLevel::class,
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * Get the tax value based on type
     */
    public function getTaxValue(): string
    {
        return match ($this->tax_type) {
            TaxType::Percentage => $this->percentage_rate ?? '0.00',
            TaxType::FixedAmount => $this->fixed_amount ?? '0.000',
        };
    }

    /**
     * Check if this is a percentage tax
     */
    public function isPercentage(): bool
    {
        return $this->tax_type === TaxType::Percentage;
    }

    /**
     * Check if this is a fixed amount tax
     */
    public function isFixedAmount(): bool
    {
        return $this->tax_type === TaxType::FixedAmount;
    }

    /**
     * Check if this applies to line items
     */
    public function appliesToLineItems(): bool
    {
        return $this->applies_to === TaxApplicationLevel::LineItems;
    }

    /**
     * Check if this applies to document total
     */
    public function appliesToDocumentTotal(): bool
    {
        return $this->applies_to === TaxApplicationLevel::DocumentTotal;
    }
}
