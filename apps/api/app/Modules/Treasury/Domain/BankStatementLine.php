<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain;

use App\Modules\Company\Domain\Location;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\StatementLineIgnoreReason;
use App\Modules\Treasury\Domain\Enums\StatementLineMatchStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $bank_statement_id
 * @property string $payment_repository_id
 * @property int $line_number
 * @property CarbonImmutable $value_date
 * @property CarbonImmutable|null $booking_date
 * @property MovementDirection $direction
 * @property numeric-string $amount
 * @property string|null $reference
 * @property string|null $bank_transaction_id
 * @property string $label
 * @property string|null $counterparty_hint
 * @property StatementLineMatchStatus $match_status
 * @property StatementLineIgnoreReason|null $ignore_reason
 * @property string|null $ignore_text
 * @property string|null $location_id
 * @property string $fingerprint
 */
final class BankStatementLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'line_number' => 'integer',
        'value_date' => 'immutable_date',
        'booking_date' => 'immutable_date',
        'direction' => MovementDirection::class,
        'amount' => 'decimal:3',
        'match_status' => StatementLineMatchStatus::class,
        'ignore_reason' => StatementLineIgnoreReason::class,
    ];

    /** @return BelongsTo<BankStatement, $this> */
    public function statement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class, 'bank_statement_id');
    }

    /** @return BelongsTo<PaymentRepository, $this> */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(PaymentRepository::class, 'payment_repository_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<BankStatementLineAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(BankStatementLineAllocation::class);
    }

    /** @return HasMany<BankStatementMatchExecution, $this> */
    public function executions(): HasMany
    {
        return $this->hasMany(BankStatementMatchExecution::class);
    }
}
