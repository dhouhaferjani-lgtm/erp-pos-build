<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Entities;

use App\Modules\Document\Domain\Document;
use App\Modules\Identity\Domain\User;
use App\Modules\Loyalty\Domain\Enums\TransactionType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $enrollment_id
 * @property TransactionType $transaction_type
 * @property numeric-string $amount
 * @property numeric-string $balance_before
 * @property numeric-string $balance_after
 * @property string|null $order_id
 * @property string|null $order_line_id
 * @property string|null $reward_id
 * @property string|null $earning_rule_id
 * @property string|null $description
 * @property array<string, mixed>|null $metadata
 * @property string|null $created_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property-read Enrollment $enrollment
 * @property-read Document|null $order
 * @property-read Reward|null $reward
 * @property-read EarningRule|null $earningRule
 * @property-read User|null $createdBy
 */
class Transaction extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    use HasUuids;

    protected $table = 'loyalty_transactions';

    public $timestamps = false; // Only created_at, no updated_at

    protected $fillable = [
        'enrollment_id',
        'transaction_type',
        'amount',
        'balance_before',
        'balance_after',
        'order_id',
        'order_line_id',
        'reward_id',
        'earning_rule_id',
        'description',
        'metadata',
        'created_by',
        'created_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transaction_type' => TransactionType::class,
            'amount' => 'decimal:3',
            'balance_before' => 'decimal:3',
            'balance_after' => 'decimal:3',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Get the enrollment for this transaction
     *
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_id');
    }

    /**
     * Get the order (document) for this transaction
     *
     * @return BelongsTo<Document, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'order_id');
    }

    /**
     * Get the reward for this transaction
     *
     * @return BelongsTo<Reward, $this>
     */
    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class, 'reward_id');
    }

    /**
     * Get the earning rule for this transaction
     *
     * @return BelongsTo<EarningRule, $this>
     */
    public function earningRule(): BelongsTo
    {
        return $this->belongsTo(EarningRule::class, 'earning_rule_id');
    }

    /**
     * Get the user who created this transaction (for manual adjustments)
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
