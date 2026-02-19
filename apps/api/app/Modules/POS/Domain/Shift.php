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
 * POS Shift Entity
 *
 * Represents a cashier work session with cash drawer tracking.
 * Critical for cash management and end-of-day reporting.
 *
 * @property string $id
 * @property string $terminal_id
 * @property string $cashier_id
 * @property int $shift_number Sequential number (never resets)
 * @property numeric-string $opening_cash Cash declared at shift open
 * @property numeric-string|null $expected_cash Calculated expected cash at close
 * @property numeric-string|null $actual_cash Counted cash at close
 * @property numeric-string|null $variance Difference: actual - expected
 * @property string $status OPEN or CLOSED
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 * @property string|null $closed_by User who closed the shift
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Terminal $terminal
 * @property-read User $cashier
 * @property-read User|null $closedBy
 *
 * @method static Builder<static> open()
 * @method static Builder<static> closed()
 * @method static Builder<static> forTerminal(string $terminalId)
 * @method static Builder<static> forCashier(string $cashierId)
 */
class Shift extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'pos_shifts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'terminal_id',
        'cashier_id',
        'shift_number',
        'opening_cash',
        'expected_cash',
        'actual_cash',
        'variance',
        'status',
        'opened_at',
        'closed_at',
        'closed_by',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shift_number' => 'integer',
            'opening_cash' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'actual_cash' => 'decimal:2',
            'variance' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * Check if shift is open
     */
    public function isOpen(): bool
    {
        return $this->status === 'OPEN';
    }

    /**
     * Check if shift is closed
     */
    public function isClosed(): bool
    {
        return $this->status === 'CLOSED';
    }

    /**
     * Check if shift has variance (overage or shortage)
     */
    public function hasVariance(): bool
    {
        if ($this->variance === null) {
            return false;
        }

        return bccomp($this->variance, '0', 2) !== 0;
    }

    /**
     * Check if shift has overage (more cash than expected)
     */
    public function hasOverage(): bool
    {
        if ($this->variance === null) {
            return false;
        }

        return bccomp($this->variance, '0', 2) > 0;
    }

    /**
     * Check if shift has shortage (less cash than expected)
     */
    public function hasShortage(): bool
    {
        if ($this->variance === null) {
            return false;
        }

        return bccomp($this->variance, '0', 2) < 0;
    }

    /**
     * Get shift duration in seconds
     */
    public function getDurationSeconds(): ?int
    {
        if ($this->closed_at === null) {
            return (int) $this->opened_at->diffInSeconds(now());
        }

        return (int) $this->opened_at->diffInSeconds($this->closed_at);
    }

    /**
     * Get formatted shift duration
     */
    public function getFormattedDuration(): string
    {
        $seconds = $this->getDurationSeconds();
        if ($seconds === null) {
            return 'N/A';
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        return sprintf('%dh %dm', $hours, $minutes);
    }

    /**
     * Scope to filter only open shifts
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'OPEN');
    }

    /**
     * Scope to filter only closed shifts
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', 'CLOSED');
    }

    /**
     * Scope to filter shifts by terminal
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTerminal(Builder $query, string $terminalId): Builder
    {
        return $query->where('terminal_id', $terminalId);
    }

    /**
     * Scope to filter shifts by cashier
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCashier(Builder $query, string $cashierId): Builder
    {
        return $query->where('cashier_id', $cashierId);
    }
}
