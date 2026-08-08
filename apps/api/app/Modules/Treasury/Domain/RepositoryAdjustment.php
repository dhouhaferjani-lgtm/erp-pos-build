<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementReasonCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The justifying DOCUMENT for one gated manual repository (cash) adjustment —
 * document-per-action remediation lane V3.
 *
 * `id` is the very UUID that `RepositoryAdjustmentController::store()` mints
 * inside its transaction and hands to BOTH the journal entry
 * (`journal_entries.source_type = 'repository_adjustment'`, `source_id = id`)
 * and the `MovementSourceType::Adjustment` repository movement
 * (`source_id = id`). Those two referents pointed at nothing before this model
 * existed; now they address this row.
 *
 * NO foreign key points from `repository_movements` at this table, by design.
 * `MovementSourceType::Adjustment` is OVERLOADED: the acquirer-fee service
 * (`Treasury/Application/Services/AcquirerFeeService.php:46,140` — referenced by
 * path, not imported, so this Domain model keeps no Application dependency)
 * records acquirer-fee movements under the SAME source type with a
 * `bank_statement_lines.id` as `source_id`, so
 * a `movements.source_id → repository_adjustments.id` FK would reject every
 * acquirer-fee movement. The polymorphism is accepted; de-overloading it with a
 * distinct `MovementSourceType::AcquirerFee` is a recorded program ticket and is
 * deliberately out of this lane's scope. Consequently, resolving a movement to
 * its adjustment document must always be conditional on the source id actually
 * existing here — never assumed.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $payment_repository_id
 * @property MovementDirection $direction
 * @property numeric-string $amount
 * @property string $currency ISO 4217 currency code (char(3))
 * @property MovementReasonCode $reason_code
 * @property string $reason_text
 * @property ?string $journal_entry_id
 * @property ?string $movement_id
 * @property ?string $pos_shift_id
 * @property ?string $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class RepositoryAdjustment extends Model
{
    use HasUuids;

    /**
     * Writes go through the single endpoint that owns this document
     * (RepositoryAdjustmentController::store, inside the GL+movement
     * transaction), which supplies `id` explicitly so the JE and the movement
     * can reference it before it is linked back.
     */
    protected $guarded = [];

    protected $casts = [
        'direction' => MovementDirection::class,
        'reason_code' => MovementReasonCode::class,
        'amount' => 'decimal:3',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * The ONLY columns a saved document may ever change, and only null → value:
     * the two linkage columns the authoring transaction backfills once the
     * journal entry and the movement it justifies exist.
     */
    private const LINKAGE_COLUMNS = ['journal_entry_id', 'movement_id'];

    /**
     * Model-level immutability guard (gate finding I5).
     *
     * This row justifies a POSTED journal entry and a committed, append-only
     * repository movement; letting any code path re-write its amount or delete
     * it would reintroduce exactly the "fact with no justifying document" this
     * lane exists to remove — and in a remediation program whose V1 lane was
     * "delete the GL-deleting command", an unguarded fiscal document is not
     * defensible. Mirrors {@see RepositoryMovement::booted()}, relaxed by the
     * one transition the authoring flow genuinely needs: the linkage backfill,
     * null → value, inside the same transaction that created the row.
     *
     * Driver-independent by design (the sibling's Postgres trigger gives no
     * protection on the sqlite test driver).
     */
    protected static function booted(): void
    {
        self::updating(function (self $adjustment): void {
            $dirty = array_keys($adjustment->getDirty());
            $illegal = array_diff($dirty, self::LINKAGE_COLUMNS);

            if ($illegal !== []) {
                throw new \LogicException(
                    'repository_adjustments are immutable once written; only '.
                    implode('/', self::LINKAGE_COLUMNS).' may be backfilled. Refused: '.
                    implode(', ', $illegal).'. Corrections are compensating adjustments.'
                );
            }

            foreach ($dirty as $column) {
                if ($adjustment->getOriginal($column) !== null) {
                    throw new \LogicException(
                        "repository_adjustments.{$column} is write-once (null → value); ".
                        'repointing a document at a different journal entry or movement is refused.'
                    );
                }
            }
        });

        self::deleting(function (): never {
            throw new \LogicException('repository_adjustments are permanent; they justify a posted journal entry and an append-only movement. Corrections are compensating adjustments.');
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

    /**
     * @return BelongsTo<RepositoryMovement, $this>
     */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(RepositoryMovement::class, 'movement_id');
    }
}
