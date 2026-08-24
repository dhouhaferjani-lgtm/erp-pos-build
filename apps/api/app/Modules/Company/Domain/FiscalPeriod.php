<?php

declare(strict_types=1);

namespace App\Modules\Company\Domain;

use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Identity\Domain\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FiscalPeriod model - monthly/quarterly periods within a fiscal year.
 *
 * Periods can be opened, closed, or locked. Closing a period prevents
 * new transactions from being posted to it.
 *
 * @property string $id UUID of the period
 * @property string $fiscal_year_id UUID of the fiscal year
 * @property string $company_id UUID of the company (denormalized)
 * @property string $name Display name (e.g., "January 2025")
 * @property int $period_number Period number within year (1-12 or 1-4)
 * @property Carbon $start_date Start date of period
 * @property Carbon $end_date End date of period
 * @property PeriodStatus $status Period status
 * @property Carbon|null $closed_at When the period was closed
 * @property string|null $closed_by UUID of user who closed (null for the nightly system lock)
 * @property Carbon|null $locked_at When the period was locked
 * @property string|null $locked_by UUID of user who locked (null for the nightly system lock)
 * @property Carbon|null $reopened_at When the period was last reopened (Closed -> Open)
 * @property string|null $reopened_by UUID of user who reopened
 * @property string|null $reopen_reason Operator justification supplied on the reopen
 * @property string|null $status_actor Actor label of the LAST status transition ('system:auto-lock' | 'user:<uuid>')
 * @property string|null $status_changed_from From-state of the LAST status transition
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read FiscalYear $fiscalYear
 * @property-read Company $company
 * @property-read User|null $closedBy
 * @property-read User|null $lockedBy
 * @property-read User|null $reopenedBy
 */
class FiscalPeriod extends Model
{
    use HasUuids;

    /**
     * The table associated with the model.
     */
    protected $table = 'fiscal_periods';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'fiscal_year_id',
        'company_id',
        'name',
        'period_number',
        'start_date',
        'end_date',
        'status',
        'closed_at',
        'closed_by',
        'locked_at',
        'locked_by',
        'reopened_at',
        'reopened_by',
        'reopen_reason',
        'status_actor',
        'status_changed_from',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_number' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => PeriodStatus::class,
            'closed_at' => 'datetime',
            'locked_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    /**
     * Get the fiscal year that owns this period.
     *
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /**
     * Get the company that owns this period.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the user who closed this period.
     *
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * Get the user who locked this period (null when the nightly scheduler did).
     *
     * @return BelongsTo<User, $this>
     */
    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /**
     * Get the user who last reopened this period.
     *
     * @return BelongsTo<User, $this>
     */
    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    /**
     * Check if this period is closed.
     */
    public function isClosed(): bool
    {
        return $this->status === PeriodStatus::Closed || $this->status === PeriodStatus::Locked;
    }

    /**
     * Check if this period is open for transactions.
     */
    public function isOpen(): bool
    {
        return $this->status === PeriodStatus::Open;
    }

    /**
     * Check if a date falls within this period.
     */
    public function containsDate(Carbon $date): bool
    {
        return $date->between($this->start_date, $this->end_date);
    }
}
