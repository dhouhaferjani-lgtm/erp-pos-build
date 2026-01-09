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
 * POS X Report Entity
 *
 * Represents a mid-shift snapshot report.
 * Non-destructive - can be generated multiple times during a shift.
 * Not fiscally critical (no hash chain required).
 *
 * @property string $id
 * @property string $terminal_id
 * @property string|null $shift_id
 * @property string $generated_by User who generated the report
 * @property array $snapshot_data JSONB snapshot of shift totals
 * @property Carbon $generated_at
 * @property-read Terminal $terminal
 * @property-read Shift|null $shift
 * @property-read User $generatedBy
 *
 * @method static Builder<static> forTerminal(string $terminalId)
 * @method static Builder<static> forShift(string $shiftId)
 */
class XReport extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'pos_x_reports';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'terminal_id',
        'shift_id',
        'generated_by',
        'snapshot_data',
        'generated_at',
    ];

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot_data' => 'array',
            'generated_at' => 'datetime',
        ];
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
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * Get sales count from snapshot
     */
    public function getSalesCount(): int
    {
        return $this->snapshot_data['sales_count'] ?? 0;
    }

    /**
     * Get gross sales from snapshot
     */
    public function getGrossSales(): string
    {
        return $this->snapshot_data['gross_sales'] ?? '0.00';
    }

    /**
     * Get net sales from snapshot
     */
    public function getNetSales(): string
    {
        return $this->snapshot_data['net_sales'] ?? '0.00';
    }

    /**
     * Get tax amount from snapshot
     */
    public function getTaxAmount(): string
    {
        return $this->snapshot_data['tax_amount'] ?? '0.00';
    }

    /**
     * Get refunds count from snapshot
     */
    public function getRefundsCount(): int
    {
        return $this->snapshot_data['refunds_count'] ?? 0;
    }

    /**
     * Get refunds amount from snapshot
     */
    public function getRefundsAmount(): string
    {
        return $this->snapshot_data['refunds_amount'] ?? '0.00';
    }

    /**
     * Get voids count from snapshot
     */
    public function getVoidsCount(): int
    {
        return $this->snapshot_data['voids_count'] ?? 0;
    }

    /**
     * Get VAT breakdown from snapshot
     *
     * @return array<array{rate: string, net: string, vat: string, gross: string}>
     */
    public function getVatBreakdown(): array
    {
        return $this->snapshot_data['vat_breakdown'] ?? [];
    }

    /**
     * Get payment methods breakdown from snapshot
     *
     * @return array<array{type: string, count: int, amount: string}>
     */
    public function getPaymentMethods(): array
    {
        return $this->snapshot_data['payment_methods'] ?? [];
    }

    /**
     * Scope to filter reports by terminal
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTerminal(Builder $query, string $terminalId): Builder
    {
        return $query->where('terminal_id', $terminalId);
    }

    /**
     * Scope to filter reports by shift
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForShift(Builder $query, string $shiftId): Builder
    {
        return $query->where('shift_id', $shiftId);
    }
}
