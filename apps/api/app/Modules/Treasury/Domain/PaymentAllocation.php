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
 * @property bool $booked_as_advance N-6: TRUE when this allocation was booked Cr 419 (customer advance) because the document had no posted receivable yet.
 * @property string|null $advance_journal_entry_id The 419 entry that booked it.
 * @property Carbon|null $advance_cleared_at Set when the advance was cleared to 411 — at invoice posting, or at order→invoice conversion. Guards against a double clear.
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
        'booked_as_advance',
        'advance_journal_entry_id',
        'advance_cleared_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'tolerance_writeoff' => 'decimal:4',
            'booked_as_advance' => 'boolean',
            'advance_cleared_at' => 'datetime',
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
