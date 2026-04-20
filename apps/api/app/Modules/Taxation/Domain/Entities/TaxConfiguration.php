<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Entities;

use App\Modules\Taxation\Domain\Enums\StackingBehavior;
use App\Modules\Taxation\Domain\Enums\TaxApplicationLevel;
use App\Modules\Taxation\Domain\Enums\TaxType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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
 * @property Carbon $created_at
 * @property Carbon $updated_at
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
        'sequence_order',
        'stacks_on',
        'applicable_document_types',
        'is_stamp_duty',
        'is_recoverable',
        'metadata',
    ];

    protected $casts = [
        'tax_type' => TaxType::class,
        'applies_to' => TaxApplicationLevel::class,
        'stacks_on' => StackingBehavior::class,
        'percentage_rate' => 'decimal:4',
        'fixed_amount' => 'decimal:3',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'sequence_order' => 'integer',
        'applicable_document_types' => 'array',
        'is_stamp_duty' => 'boolean',
        'is_recoverable' => 'boolean',
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

    /**
     * Scope for filtering by document type
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForDocumentType(Builder $query, string $documentType): Builder
    {
        return $query->where(function (Builder $q) use ($documentType): void {
            $q->whereJsonContains('applicable_document_types', $documentType)
                ->orWhereJsonLength('applicable_document_types', 0);
        });
    }

    /**
     * Scope for ordering by sequence
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sequence_order', 'asc');
    }

    /**
     * Scope for active configurations only
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Check if this tax applies to a specific document type
     */
    public function appliesToDocumentType(string $documentType): bool
    {
        $types = $this->applicable_document_types ?? [];

        return empty($types) || in_array($documentType, $types, true);
    }

    /**
     * Calculate tax amount based on configuration
     *
     * @param  numeric-string  $base
     * @param  numeric-string|null  $previousTaxesTotal
     */
    public function calculateAmount(string $base, ?string $previousTaxesTotal = null): string
    {
        if ($this->tax_type === TaxType::FixedAmount) {
            return $this->fixed_amount ?? '0';
        }

        $calculationBase = $base;

        if ($this->stacks_on === StackingBehavior::TOTAL_INCLUDING_PREVIOUS && $previousTaxesTotal !== null) {
            $calculationBase = bcadd($calculationBase, $previousTaxesTotal, 3);
        }

        /** @var numeric-string $percentageRate */
        $percentageRate = $this->percentage_rate ?? '0';
        $rate = bcdiv($percentageRate, '100', 6);

        return bcmul($calculationBase, $rate, 3);
    }
}
