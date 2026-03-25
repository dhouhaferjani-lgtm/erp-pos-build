<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Entities;

use App\Modules\Taxation\Domain\Enums\VatDirection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $vat_period_id
 * @property VatDirection $direction
 * @property numeric-string $tax_rate
 * @property string|null $tax_configuration_id
 * @property numeric-string $base_amount
 * @property numeric-string $vat_amount
 * @property int $document_count
 * @property bool $is_recoverable
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read VatPeriod $period
 */
class VatPeriodBreakdown extends Model
{
    use HasUuids;

    protected $table = 'vat_period_breakdowns';

    // Immutable records - only created_at, no updated_at
    public const UPDATED_AT = null;

    protected $fillable = [
        'vat_period_id',
        'direction',
        'tax_rate',
        'tax_configuration_id',
        'base_amount',
        'vat_amount',
        'document_count',
        'is_recoverable',
    ];

    protected $casts = [
        'direction' => VatDirection::class,
        'tax_rate' => 'decimal:2',
        'base_amount' => 'decimal:3',
        'vat_amount' => 'decimal:3',
        'document_count' => 'integer',
        'is_recoverable' => 'boolean',
        'created_at' => 'datetime',
    ];

    /**
     * Get the VAT period this breakdown belongs to
     *
     * @return BelongsTo<VatPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(VatPeriod::class, 'vat_period_id');
    }
}
