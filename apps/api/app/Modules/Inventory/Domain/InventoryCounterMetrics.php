<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $company_id
 * @property string $user_id
 * @property int $total_counts
 * @property int $total_items_counted
 * @property int $matches_with_theoretical
 * @property int $matches_with_other_counter
 * @property int $disagreements_proven_wrong
 * @property int $disagreements_proven_right
 * @property numeric-string|null $avg_seconds_per_item
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company $company
 * @property-read User $user
 */
class InventoryCounterMetrics extends Model
{
    use HasUuids;

    protected $table = 'inventory_counter_metrics';

    protected $fillable = [
        'company_id',
        'user_id',
        'total_counts',
        'total_items_counted',
        'matches_with_theoretical',
        'matches_with_other_counter',
        'disagreements_proven_wrong',
        'disagreements_proven_right',
        'avg_seconds_per_item',
        'period_start',
        'period_end',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_counts' => 'integer',
            'total_items_counted' => 'integer',
            'matches_with_theoretical' => 'integer',
            'matches_with_other_counter' => 'integer',
            'disagreements_proven_wrong' => 'integer',
            'disagreements_proven_right' => 'integer',
            'avg_seconds_per_item' => 'decimal:2',
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to filter by company.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Scope to filter by user.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Calculate accuracy rate.
     */
    public function getAccuracyRate(): float
    {
        $total = $this->matches_with_theoretical + $this->disagreements_proven_wrong;
        if ($total === 0) {
            return 0.0;
        }

        return round(($this->matches_with_theoretical / $total) * 100, 2);
    }

    /**
     * Calculate reliability score (when disagreeing with others, how often was this counter right).
     */
    public function getReliabilityScore(): float
    {
        $total = $this->disagreements_proven_wrong + $this->disagreements_proven_right;
        if ($total === 0) {
            return 0.0;
        }

        return round(($this->disagreements_proven_right / $total) * 100, 2);
    }
}
