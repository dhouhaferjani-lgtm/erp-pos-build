<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DocumentAdditionalCost - Additional costs for purchase orders (transport, insurance, etc.)
 *
 * @property string $id
 * @property string $document_id
 * @property string $cost_type (transport, shipping, insurance, customs, handling, other)
 * @property string|null $description
 * @property string $amount
 * @property string|null $expense_document_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Document $document
 * @property-read Document|null $expenseDocument
 */
class DocumentAdditionalCost extends Model
{
    use HasUuids;

    protected $table = 'document_additional_costs';

    protected $fillable = [
        'document_id',
        'cost_type',
        'description',
        'amount',
        'expense_document_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
        ];
    }

    /**
     * Get the document this cost belongs to
     *
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Get the expense document if linked
     *
     * @return BelongsTo<Document, $this>
     */
    public function expenseDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'expense_document_id');
    }

    /**
     * Check if this is a transport cost
     */
    public function isTransport(): bool
    {
        return $this->cost_type === 'transport';
    }

    /**
     * Check if this is a shipping cost
     */
    public function isShipping(): bool
    {
        return $this->cost_type === 'shipping';
    }

    /**
     * Check if this is an insurance cost
     */
    public function isInsurance(): bool
    {
        return $this->cost_type === 'insurance';
    }

    /**
     * Check if this is a customs cost
     */
    public function isCustoms(): bool
    {
        return $this->cost_type === 'customs';
    }

    /**
     * Check if this is a handling cost
     */
    public function isHandling(): bool
    {
        return $this->cost_type === 'handling';
    }
}
