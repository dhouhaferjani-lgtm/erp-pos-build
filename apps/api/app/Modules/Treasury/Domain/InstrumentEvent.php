<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Treasury\Domain\Enums\InstrumentEventType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $instrument_id
 * @property InstrumentEventType $event_type
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $from_repository_id
 * @property string|null $to_repository_id
 * @property string|null $remittance_id
 * @property string|null $journal_entry_id
 * @property string|null $movement_id
 * @property array<string, string|array<string, array{old: string|null, new: string|null}>|null> $payload
 * @property CarbonImmutable $occurred_at
 * @property string|null $created_by
 * @property CarbonImmutable $created_at
 * @property-read PaymentInstrument $instrument
 * @property-read JournalEntry|null $journalEntry
 * @property-read RepositoryMovement|null $movement
 */
final class InstrumentEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'event_type' => InstrumentEventType::class,
        'payload' => 'array',
        'occurred_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];

    /**
     * @return BelongsTo<PaymentInstrument, $this>
     */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(PaymentInstrument::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<RepositoryMovement, $this>
     */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(RepositoryMovement::class);
    }
}
