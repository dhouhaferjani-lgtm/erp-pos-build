<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain;

use App\Modules\Document\Domain\Enums\RefundMethod;
use App\Modules\Document\Domain\Enums\ReturnCondition;
use App\Modules\Document\Domain\Enums\ReturnReason;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $document_id
 * @property ReturnReason $return_reason
 * @property ReturnCondition|null $return_condition
 * @property RefundMethod|null $refund_method
 * @property string|null $source_delivery_note_id
 * @property string|null $source_invoice_id
 * @property string|null $linked_credit_note_id
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Document $document
 * @property-read Document|null $sourceDeliveryNote
 * @property-read Document|null $sourceInvoice
 * @property-read Document|null $linkedCreditNote
 */
class ReturnNoteMetadata extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'return_note_metadata';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_id',
        'return_reason',
        'return_condition',
        'refund_method',
        'source_delivery_note_id',
        'source_invoice_id',
        'linked_credit_note_id',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'return_reason' => ReturnReason::class,
            'return_condition' => ReturnCondition::class,
            'refund_method' => RefundMethod::class,
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function sourceDeliveryNote(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'source_delivery_note_id');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function sourceInvoice(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'source_invoice_id');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function linkedCreditNote(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'linked_credit_note_id');
    }
}
