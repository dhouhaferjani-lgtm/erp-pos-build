<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\HeldOrderStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * POS Held Order Entity
 *
 * Represents a parked/held cart that can be recalled later.
 * Held orders persist server-side and survive browser refresh/logout.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $terminal_id
 * @property string $shift_id
 * @property string $cashier_id
 * @property string|null $label
 * @property array<string, mixed> $cart_snapshot
 * @property HeldOrderStatus $status
 * @property Carbon $held_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $recalled_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Terminal $terminal
 * @property-read Shift $shift
 * @property-read User $cashier
 *
 * @method static Builder<static> forTerminal(string $terminalId)
 * @method static Builder<static> forShift(string $shiftId)
 * @method static Builder<static> held()
 * @method static Builder<static> expired()
 */
class HeldOrder extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'pos_held_orders';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'terminal_id',
        'shift_id',
        'cashier_id',
        'label',
        'cart_snapshot',
        'status',
        'held_at',
        'expires_at',
        'recalled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cart_snapshot' => 'array',
            'status' => HeldOrderStatus::class,
            'held_at' => 'datetime',
            'expires_at' => 'datetime',
            'recalled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Terminal, $this>
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class, 'terminal_id');
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
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /**
     * Check if this held order is currently held.
     */
    public function isHeld(): bool
    {
        return $this->status === HeldOrderStatus::Held;
    }

    /**
     * Check if this held order has been recalled.
     */
    public function isRecalled(): bool
    {
        return $this->status === HeldOrderStatus::Recalled;
    }

    /**
     * Check if this held order has expired.
     */
    public function isExpired(): bool
    {
        return $this->status === HeldOrderStatus::Expired;
    }

    /**
     * Check if this held order can be recalled.
     *
     * An order can be recalled only if it is in held status
     * and has not passed its expiry time.
     */
    public function canBeRecalled(): bool
    {
        if ($this->status !== HeldOrderStatus::Held) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Get the number of lines in the cart snapshot.
     */
    public function getLineCount(): int
    {
        $lines = $this->cart_snapshot['lines'] ?? [];

        return count($lines);
    }

    /**
     * Get the total from the cart snapshot.
     *
     * Calculates total as sum of (quantity * unit_price - discount_amount) for each line.
     */
    public function getTotal(): string
    {
        $lines = $this->cart_snapshot['lines'] ?? [];
        $total = '0.000';

        foreach ($lines as $line) {
            /** @var numeric-string $qty */
            $qty = (string) ($line['quantity'] ?? '0');
            /** @var numeric-string $price */
            $price = (string) ($line['unit_price'] ?? '0');
            $lineTotal = bcmul($qty, $price, 3);
            /** @var numeric-string $discount */
            $discount = (string) ($line['discount_amount'] ?? '0');
            $lineTotal = bcsub($lineTotal, $discount, 3);
            $total = bcadd($total, $lineTotal, 3);
        }

        return $total;
    }

    /**
     * Scope to filter held orders by terminal.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTerminal(Builder $query, string $terminalId): Builder
    {
        return $query->where('terminal_id', $terminalId);
    }

    /**
     * Scope to filter held orders by shift.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForShift(Builder $query, string $shiftId): Builder
    {
        return $query->where('shift_id', $shiftId);
    }

    /**
     * Scope to filter only held orders (active, not recalled or expired).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeHeld(Builder $query): Builder
    {
        return $query->where('status', HeldOrderStatus::Held);
    }

    /**
     * Scope to filter orders that have expired (past expiry and still held).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', HeldOrderStatus::Held)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now());
    }
}
