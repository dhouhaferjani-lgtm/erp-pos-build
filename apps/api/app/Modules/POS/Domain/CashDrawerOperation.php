<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain;

use App\Modules\Identity\Domain\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * POS Cash Drawer Operation Entity
 *
 * Represents a single cash movement in the cash drawer during a shift.
 * Immutable after creation - provides complete forensic audit trail.
 *
 * @property string $id
 * @property string $shift_id
 * @property string $operation_type OPENING, SALE, REFUND, DEPOSIT, PAYOUT, CLOSING
 * @property numeric-string $amount Amount of cash movement
 * @property string $user_id User who performed the operation
 * @property string|null $reason Description of the operation
 * @property string|null $receipt_id Receipt reference (for SALE/REFUND)
 * @property Carbon $created_at Immutable timestamp
 * @property-read Shift $shift
 * @property-read User $user
 * @property-read Receipt|null $receipt
 *
 * @method static Builder<static> forShift(string $shiftId)
 * @method static Builder<static> ofType(string $type)
 */
class CashDrawerOperation extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'pos_cash_drawer_operations';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'shift_id',
        'operation_type',
        'amount',
        'user_id',
        'reason',
        'receipt_id',
        'approval_id',
        'approval_fiscal_event_id',
        'approval_scope',
        'approval_supervisor_user_id',
        'approval_target_hash',
        'created_at',
    ];

    /**
     * @var bool
     */
    public $timestamps = true;

    /**
     * Disable updated_at since this is an immutable audit record
     *
     * @var null
     */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Receipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'receipt_id');
    }

    /**
     * Check if operation adds cash to drawer
     */
    public function isAddition(): bool
    {
        return in_array($this->operation_type, ['OPENING', 'SALE']);
    }

    /**
     * Check if operation removes cash from drawer
     */
    public function isRemoval(): bool
    {
        return in_array($this->operation_type, ['REFUND', 'DEPOSIT', 'PAYOUT']);
    }

    /**
     * Check if operation is a safe deposit
     */
    public function isDeposit(): bool
    {
        return $this->operation_type === 'DEPOSIT';
    }

    /**
     * Check if operation is a payout
     */
    public function isPayout(): bool
    {
        return $this->operation_type === 'PAYOUT';
    }

    /**
     * Check if operation is linked to a receipt
     */
    public function hasReceipt(): bool
    {
        return $this->receipt_id !== null;
    }

    /**
     * Get signed amount (negative for removals)
     */
    public function getSignedAmount(int $scale = 3): string
    {
        if ($this->isRemoval()) {
            return bcmul($this->amount, '-1', $scale);
        }

        return $this->amount;
    }

    /**
     * Get formatted operation description
     */
    public function getDescription(): string
    {
        $descriptions = [
            'OPENING' => 'Opening Balance',
            'SALE' => 'Sale',
            'REFUND' => 'Refund',
            'DEPOSIT' => 'Safe Deposit',
            'PAYOUT' => 'Payout',
            'CLOSING' => 'Closing Balance',
        ];

        $desc = $descriptions[$this->operation_type] ?? $this->operation_type;

        if ($this->reason) {
            $desc .= " - {$this->reason}";
        }

        return $desc;
    }

    /**
     * Scope to filter operations by shift
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForShift(Builder $query, string $shiftId): Builder
    {
        return $query->where('shift_id', $shiftId);
    }

    /**
     * Scope to filter operations by type
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('operation_type', $type);
    }
}
