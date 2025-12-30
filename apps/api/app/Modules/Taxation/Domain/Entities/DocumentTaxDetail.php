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
 * @property TaxType $tax_type
 * @property string $tax_name
 * @property string|null $tax_base
 * @property string|null $tax_rate
 * @property string $tax_amount
 * @property bool $is_stamp_duty
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Document $document
 */
class DocumentTaxDetail extends Model
{
    use HasUuids;

    protected $table = 'document_tax_details';

    protected $fillable = [
        'document_id',
        'tax_type',
        'tax_name',
        'tax_base',
        'tax_rate',
        'tax_amount',
        'is_stamp_duty',
    ];

    protected $casts = [
        'tax_type' => TaxType::class,
        'is_stamp_duty' => 'boolean',
    ];

    /**
     * Get the document this tax detail belongs to
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
