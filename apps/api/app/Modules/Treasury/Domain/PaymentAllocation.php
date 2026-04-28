<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain;

use App\Modules\Document\Domain\Document;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Payment allocation linking payments to documents.
 *
 * @property string $id
 * @property string|null $payment_id Nullable: tolerance-only writeoff allocations have no payment behind them.
 * @property string $document_id
 * @property numeric-string $amount
 * @property numeric-string|null $tolerance_writeoff
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Payment|null $payment
 * @property-read Document $document
 */
class PaymentAllocation extends Model
{
    use HasUuids;

    protected $table = 'payment_allocations';

    protected $fillable = [
        'payment_id',
        'document_id',
        'amount',
        'tolerance_writeoff',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'tolerance_writeoff' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
