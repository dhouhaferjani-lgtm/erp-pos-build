<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain;

use App\Modules\Identity\Domain\User;
use App\Modules\Treasury\Domain\Enums\MatchActionType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $bank_statement_line_id
 * @property MatchActionType $action_type
 * @property string $action_key
 * @property string $semantic_digest
 * @property string|null $target_type
 * @property string|null $target_id
 * @property list<string> $produced_repository_movement_ids
 * @property string $executed_by
 * @property CarbonImmutable $executed_at
 */
final class BankStatementMatchExecution extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'action_type' => MatchActionType::class,
        'produced_repository_movement_ids' => 'array',
        'executed_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new \LogicException('Bank statement match executions are immutable.');
        });

        self::deleting(function (): never {
            throw new \LogicException('Bank statement match executions are immutable.');
        });
    }

    /** @return BelongsTo<BankStatementLine, $this> */
    public function line(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'bank_statement_line_id');
    }

    /** @return BelongsTo<User, $this> */
    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }
}
