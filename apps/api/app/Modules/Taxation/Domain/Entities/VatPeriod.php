<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Entities;

use App\Modules\Taxation\Domain\Enums\VatPeriodStatus;
use App\Modules\Taxation\Domain\Enums\VatPeriodType;
use Database\Factories\VatPeriodFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $company_id
 * @property string $country_code
 * @property VatPeriodType $period_type
 * @property string $label
 * @property \Illuminate\Support\Carbon $period_start
 * @property \Illuminate\Support\Carbon $period_end
 * @property VatPeriodStatus $status
 * @property string|null $total_output_vat
 * @property string|null $total_input_vat
 * @property string|null $net_vat
 * @property numeric-string $credit_brought_forward
 * @property numeric-string $credit_carried_forward
 * @property numeric-string $amount_payable
 * @property array<string, mixed>|null $special_items
 * @property array<string, mixed>|null $declaration_data
 * @property \Illuminate\Support\Carbon|null $closed_at
 * @property string|null $closed_by
 * @property \Illuminate\Support\Carbon|null $filed_at
 * @property string|null $filed_by
 * @property string|null $filing_reference
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, VatPeriodBreakdown> $breakdowns
 */
class VatPeriod extends Model
{
    /** @use HasFactory<VatPeriodFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'vat_periods';

    protected $fillable = [
        'company_id',
        'country_code',
        'period_type',
        'label',
        'period_start',
        'period_end',
        'status',
        'total_output_vat',
        'total_input_vat',
        'net_vat',
        'credit_brought_forward',
        'credit_carried_forward',
        'amount_payable',
        'special_items',
        'declaration_data',
        'closed_at',
        'closed_by',
        'filed_at',
        'filed_by',
        'filing_reference',
        'notes',
    ];

    protected $casts = [
        'period_type' => VatPeriodType::class,
        'status' => VatPeriodStatus::class,
        'period_start' => 'date',
        'period_end' => 'date',
        'total_output_vat' => 'decimal:3',
        'total_input_vat' => 'decimal:3',
        'net_vat' => 'decimal:3',
        'credit_brought_forward' => 'decimal:3',
        'credit_carried_forward' => 'decimal:3',
        'amount_payable' => 'decimal:3',
        'special_items' => 'array',
        'declaration_data' => 'array',
        'closed_at' => 'datetime',
        'filed_at' => 'datetime',
    ];

    protected static function newFactory(): VatPeriodFactory
    {
        return VatPeriodFactory::new();
    }

    /**
     * Get the breakdowns for this VAT period
     *
     * @return HasMany<VatPeriodBreakdown, $this>
     */
    public function breakdowns(): HasMany
    {
        return $this->hasMany(VatPeriodBreakdown::class);
    }

    /**
     * Scope: filter by company
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeForCompany(\Illuminate\Database\Eloquent\Builder $query, string $companyId): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Scope: filter by year (period_start falls within the year)
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeForYear(\Illuminate\Database\Eloquent\Builder $query, int $year): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereYear('period_start', $year);
    }

    /**
     * Check if the period is open
     */
    public function isOpen(): bool
    {
        return $this->status === VatPeriodStatus::Open;
    }

    /**
     * Check if the period is closed
     */
    public function isClosed(): bool
    {
        return $this->status === VatPeriodStatus::Closed;
    }

    /**
     * Check if the period is filed
     */
    public function isFiled(): bool
    {
        return $this->status === VatPeriodStatus::Filed;
    }
}
