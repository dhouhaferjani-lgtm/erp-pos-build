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
 * POS Grandtotal Event Entity
 *
 * Represents a fiscal grand total closing (daily/monthly/yearly).
 * Critical for NF525 certification - separate hash chains per event type.
 * Tracks both period totals (reset) and perpetual totals (never reset).
 *
 * @property string $id
 * @property string $terminal_id
 * @property string $event_type DAILY, MONTHLY, or YEARLY
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property array<string, mixed> $period_totals JSONB: Totals for this period (reset)
 * @property array<string, mixed> $perpetual_totals JSONB: Cumulative totals (never reset)
 * @property string $fiscal_hash SHA-256 hash of this event
 * @property string|null $previous_hash Hash of previous event of same type
 * @property int $sequence_number Sequential number per event_type
 * @property string $generated_by User who generated the event
 * @property Carbon $generated_at
 * @property-read Terminal $terminal
 * @property-read User $generatedBy
 *
 * @method static Builder<static> forTerminal(string $terminalId)
 * @method static Builder<static> ofType(string $type)
 * @method static Builder<static> daily()
 * @method static Builder<static> monthly()
 * @method static Builder<static> yearly()
 */
class GrandtotalEvent extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'pos_grandtotal_events';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'terminal_id',
        'event_type',
        'period_start',
        'period_end',
        'period_totals',
        'perpetual_totals',
        'fiscal_hash',
        'previous_hash',
        'sequence_number',
        'generated_by',
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
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'period_totals' => 'array',
            'perpetual_totals' => 'array',
            'sequence_number' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * Check if this is a daily grand total
     */
    public function isDaily(): bool
    {
        return $this->event_type === 'DAILY';
    }

    /**
     * Check if this is a monthly grand total
     */
    public function isMonthly(): bool
    {
        return $this->event_type === 'MONTHLY';
    }

    /**
     * Check if this is a yearly grand total
     */
    public function isYearly(): bool
    {
        return $this->event_type === 'YEARLY';
    }

    /**
     * Check if this is the first event of its type
     */
    public function isFirstOfType(): bool
    {
        return $this->previous_hash === null;
    }

    /**
     * Get formatted event display (e.g., "DAILY #42")
     */
    public function getFormattedEventName(): string
    {
        return "{$this->event_type} #{$this->sequence_number}";
    }

    /**
     * Get period gross sales from period totals
     */
    public function getPeriodGrossSales(): string
    {
        return $this->period_totals['gross_sales'] ?? '0.00';
    }

    /**
     * Get period tax amount from period totals
     */
    public function getPeriodTaxAmount(): string
    {
        return $this->period_totals['tax_amount'] ?? '0.00';
    }

    /**
     * Get perpetual gross sales (lifetime)
     */
    public function getPerpetualGrossSales(): string
    {
        return $this->perpetual_totals['lifetime_sales'] ?? '0.00';
    }

    /**
     * Get perpetual tax amount (lifetime)
     */
    public function getPerpetualTaxAmount(): string
    {
        return $this->perpetual_totals['lifetime_tax'] ?? '0.00';
    }

    /**
     * Get period duration in days
     */
    public function getPeriodDurationDays(): int
    {
        return (int) $this->period_start->diffInDays($this->period_end);
    }

    /**
     * Scope to filter events by terminal
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTerminal(Builder $query, string $terminalId): Builder
    {
        return $query->where('terminal_id', $terminalId);
    }

    /**
     * Scope to filter events by type
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('event_type', $type);
    }

    /**
     * Scope to filter only daily events
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDaily(Builder $query): Builder
    {
        return $query->where('event_type', 'DAILY');
    }

    /**
     * Scope to filter only monthly events
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeMonthly(Builder $query): Builder
    {
        return $query->where('event_type', 'MONTHLY');
    }

    /**
     * Scope to filter only yearly events
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeYearly(Builder $query): Builder
    {
        return $query->where('event_type', 'YEARLY');
    }
}
