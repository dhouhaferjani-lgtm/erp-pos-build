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
