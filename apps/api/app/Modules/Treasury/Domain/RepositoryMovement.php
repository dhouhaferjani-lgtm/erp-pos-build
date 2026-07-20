<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementReasonCode;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $payment_repository_id
 * @property MovementDirection $direction
 * @property numeric-string $amount
 * @property string $currency ISO 4217 currency code (char(3))
 * @property numeric-string $balance_after
 * @property int $ordinal
 * @property MovementSourceType $source_type
 * @property string $source_id
 * @property ?string $journal_entry_id
 * @property string $idempotency_key
 * @property ?string $transfer_group_id
 * @property ?string $reverses_movement_id
 * @property ?MovementReasonCode $reason_code
 * @property CarbonImmutable $occurred_at
 * @property ?string $created_by
 * @property bool $recorded_while_frozen
 * @property bool $recorded_behind_checkpoint
 * @property ?string $notes
 * @property CarbonImmutable $created_at
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
        'recorded_behind_checkpoint' => 'boolean',
        'occurred_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];

    /**
     * Model-level append-only guard (spec §4 "model guards" layer).
     *
     * The Postgres trigger (Task 3) enforces this at the DB level, but that
     * trigger is pgsql-only DDL and gives no protection on sqlite (test
     * driver) or for any in-process code path that might bypass raw SQL.
     * Block update/delete here so the guard holds everywhere Eloquent is
     * used, regardless of driver. Inserts are NOT guarded: the port's sole
     * write path (TreasuryMovementService::record(), via insert()) must
     * keep working.
     */
    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new \LogicException('repository_movements are append-only; corrections are compensating movements');
        });

        self::deleting(function (): never {
            throw new \LogicException('repository_movements are append-only; corrections are compensating movements');
        });
    }

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
