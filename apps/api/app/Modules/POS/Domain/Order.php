<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\Enums\ConsumptionMode;
use App\Modules\POS\Domain\Enums\OrderStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * POS Order Entity
 *
 * Represents an open order that can be edited before being finalized into a receipt.
 * Orders support kitchen workflow (send to kitchen, mark ready) and can hold
 * multiple lines that are incrementally added/modified/removed.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string|null $location_id
 * @property string $terminal_id
 * @property string $shift_id
 * @property string|null $table_id
 * @property string $order_number
 * @property OrderStatus $status
 * @property string $cashier_id
 * @property string $cashier_name
 * @property string|null $customer_name
 * @property string|null $customer_identifier
 * @property string|null $partner_id
 * @property numeric-string $subtotal
 * @property numeric-string $tax_amount
 * @property numeric-string $discount_amount
 * @property numeric-string $total
 * @property string $currency
 * @property ConsumptionMode|null $consumption_mode
 * @property string|null $notes
 * @property Carbon $opened_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $ready_at
 * @property Carbon|null $served_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $receipt_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Terminal $terminal
 * @property-read Shift $shift
 * @property-read User $cashier
 * @property-read Partner|null $partner
 * @property-read Table|null $table
 * @property-read Receipt|null $receipt
 * @property-read Collection<int, OrderLine> $lines
 *
 * @method static Builder<static> forTerminal(string $terminalId)
 * @method static Builder<static> forShift(string $shiftId)
 * @method static Builder<static> byStatus(OrderStatus $status)
 * @method static Builder<static> open()
 * @method static Builder<static> active()
 */
class Order extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'pos_orders';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'location_id',
        'terminal_id',
        'shift_id',
        'table_id',
        'order_number',
        'status',
        'cashier_id',
        'cashier_name',
        'customer_name',
        'customer_identifier',
        'partner_id',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total',
        'currency',
        'consumption_mode',
        'notes',
        'opened_at',
        'sent_at',
        'ready_at',
        'served_at',
        'closed_at',
        'cancelled_at',
        'receipt_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'consumption_mode' => ConsumptionMode::class,
            'subtotal' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'opened_at' => 'datetime',
            'sent_at' => 'datetime',
            'ready_at' => 'datetime',
            'served_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * @return BelongsTo<Table, $this>
     */
    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class, 'table_id');
    }

    /**
     * @return BelongsTo<Receipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'receipt_id');
    }

    /**
     * @return HasMany<OrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class, 'order_id')
            ->orderBy('line_number');
    }

    /**
     * Check if the order is currently open.
     */
    public function isOpen(): bool
    {
        return $this->status === OrderStatus::Open;
    }

    /**
     * Check if the order can be sent to the kitchen.
     */
    public function canBeSentToKitchen(): bool
    {
        return $this->status === OrderStatus::Open
            && $this->lines()->count() > 0;
    }

    /**
     * Check if the order can be closed (converted to receipt).
     */
    public function canBeClosed(): bool
    {
        return in_array($this->status, [
            OrderStatus::Open,
            OrderStatus::SentToKitchen,
            OrderStatus::Ready,
        ], true)
            && $this->lines()->count() > 0;
    }

    /**
     * Check if the order can be marked as served.
     */
    public function canBeServed(): bool
    {
        return $this->status === OrderStatus::Ready;
    }

    /**
     * Check if the order can be cancelled.
     */
    public function canBeCancelled(): bool
    {
        return in_array($this->status, [
            OrderStatus::Open,
            OrderStatus::SentToKitchen,
            OrderStatus::Ready,
        ], true);
    }

    /**
     * Scope to filter orders by terminal.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTerminal(Builder $query, string $terminalId): Builder
    {
        return $query->where('terminal_id', $terminalId);
    }

    /**
     * Scope to filter orders by shift.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForShift(Builder $query, string $shiftId): Builder
    {
        return $query->where('shift_id', $shiftId);
    }

    /**
     * Scope to filter orders by status.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByStatus(Builder $query, OrderStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter only open orders.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::Open);
    }

    /**
     * Scope to filter active orders (not closed or cancelled).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            OrderStatus::Closed->value,
            OrderStatus::Cancelled->value,
        ]);
    }
}
