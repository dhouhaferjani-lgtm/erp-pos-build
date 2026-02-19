<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain;

use App\Modules\Identity\Domain\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $credit_note_id
 * @property string $invoice_id
 * @property numeric-string $amount
 * @property string|null $allocated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Document $creditNote
 * @property-read Document $invoice
 * @property-read User|null $allocatedBy
 */
class CreditNoteAllocation extends Model
{
    use HasUuids;

    protected $table = 'credit_note_allocations';

    protected $fillable = [
        'credit_note_id',
        'invoice_id',
        'amount',
        'allocated_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    /**
     * Get the credit note this allocation belongs to
     *
     * @return BelongsTo<Document, $this>
     */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'credit_note_id');
    }

    /**
     * Get the invoice this allocation reduces
     *
     * @return BelongsTo<Document, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'invoice_id');
    }

    /**
     * Get the user who allocated this credit note
     *
     * @return BelongsTo<User, $this>
     */
    public function allocatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }
}
