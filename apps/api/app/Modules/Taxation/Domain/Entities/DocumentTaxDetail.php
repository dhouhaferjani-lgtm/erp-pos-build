<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Entities;

use App\Modules\Document\Domain\Document;
use App\Modules\Taxation\Domain\Enums\TaxType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $document_id
 * @property int $sequence_order
 * @property string|null $tax_code
 * @property TaxType $tax_type
 * @property string $tax_name
 * @property string|null $tax_rate
 * @property string|null $tax_fixed_amount
 * @property string|null $tax_base
 * @property string $tax_amount
 * @property bool $is_stamp_duty
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read Document $document
 */
class DocumentTaxDetail extends Model
{
    use HasUuids;

    protected $table = 'document_tax_details';

    // Immutable records - only created_at, no updated_at
    public const UPDATED_AT = null;

    protected $fillable = [
        'document_id',
        'sequence_order',
        'tax_code',
        'tax_type',
        'tax_name',
        'tax_rate',
        'tax_fixed_amount',
        'tax_base',
        'tax_amount',
        'is_stamp_duty',
    ];

    protected $casts = [
        'sequence_order' => 'integer',
        'tax_type' => TaxType::class,
        'tax_rate' => 'decimal:2',
        'tax_fixed_amount' => 'decimal:3',
        'tax_base' => 'decimal:3',
        'tax_amount' => 'decimal:3',
        'is_stamp_duty' => 'boolean',
        'created_at' => 'datetime',
    ];

    /**
     * Get the document this tax detail belongs to
     *
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Check if this is a percentage tax
     */
    public function isPercentageTax(): bool
    {
        return $this->tax_type === TaxType::Percentage;
    }

    /**
     * Check if this is a fixed amount tax
     */
    public function isFixedAmountTax(): bool
    {
        return $this->tax_type === TaxType::FixedAmount;
    }
}
