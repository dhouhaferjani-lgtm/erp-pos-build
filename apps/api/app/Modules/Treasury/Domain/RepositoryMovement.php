<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementReasonCode;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $payment_repository_id
 * @property MovementDirection $direction
 * @property numeric-string $amount
 * @property numeric-string $balance_after
 * @property int $ordinal
 * @property MovementSourceType $source_type
 * @property string $source_id
 * @property ?string $journal_entry_id
 * @property string $idempotency_key
 */
final class RepositoryMovement extends Model
{
    use HasUuids;

    public $timestamps = false; // append-only: created_at set by DB default, no updated_at

    protected $guarded = []; // writes go ONLY through TreasuryMovementService::record (which uses insert())

    protected $casts = [
        'direction' => MovementDirection::class,
        'source_type' => MovementSourceType::class,
        'reason_code' => MovementReasonCode::class,
        'amount' => 'decimal:3',
        'balance_after' => 'decimal:3',
        'ordinal' => 'integer',
        'recorded_while_frozen' => 'boolean',
        'occurred_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<PaymentRepository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(PaymentRepository::class, 'payment_repository_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
