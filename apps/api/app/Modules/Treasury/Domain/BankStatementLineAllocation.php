<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain;

use App\Modules\Identity\Domain\User;
use App\Modules\Treasury\Domain\Enums\StatementMatchType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $bank_statement_line_id
 * @property string $repository_movement_id
 * @property numeric-string $matched_amount
 * @property StatementMatchType $match_type
 * @property string $matched_by
 * @property CarbonImmutable $matched_at
 */
final class BankStatementLineAllocation extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'matched_amount' => 'decimal:3',
        'match_type' => StatementMatchType::class,
        'matched_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<BankStatementLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'bank_statement_line_id');
    }

    /** @return BelongsTo<RepositoryMovement, $this> */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(RepositoryMovement::class, 'repository_movement_id');
    }

    /** @return BelongsTo<User, $this> */
    public function matcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }
}
