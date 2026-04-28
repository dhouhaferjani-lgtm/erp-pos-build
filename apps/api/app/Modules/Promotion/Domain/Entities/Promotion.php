<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Domain\Entities;

use App\Modules\Company\Domain\Company;
use App\Modules\Promotion\Domain\Enums\DiscountAppliesTo;
use App\Modules\Promotion\Domain\Enums\DiscountType;
use App\Modules\Promotion\Domain\Enums\PromotionStatus;
use App\Modules\Promotion\Domain\Enums\PromotionType;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $name
 * @property string|null $description
 * @property PromotionType $type
 * @property PromotionStatus $status
 * @property int $priority
 * @property bool $is_exclusive
 * @property string $stacking_group
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property array<int>|null $days_of_week
 * @property string|null $time_from
 * @property string|null $time_until
 * @property array<string, mixed> $conditions
 * @property DiscountType $discount_type
 * @property string $discount_value
 * @property string|null $max_discount_amount
 * @property DiscountAppliesTo $applies_to
 * @property int|null $usage_limit
 * @property int $usage_count
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Tenant $tenant
 * @property-read Company|null $company
 * @property-read Collection<int, PromotionUsage> $usages
 */
class Promotion extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'name',
        'description',
        'type',
        'status',
        'priority',
        'is_exclusive',
        'stacking_group',
        'starts_at',
        'ends_at',
        'days_of_week',
        'time_from',
        'time_until',
        'conditions',
        'discount_type',
        'discount_value',
        'max_discount_amount',
        'applies_to',
        'usage_limit',
        'usage_count',
        'metadata',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'priority' => 0,
        'is_exclusive' => false,
        'stacking_group' => 'default',
        'usage_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PromotionType::class,
            'status' => PromotionStatus::class,
            'discount_type' => DiscountType::class,
            'applies_to' => DiscountAppliesTo::class,
            'priority' => 'integer',
            'is_exclusive' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'days_of_week' => 'array',
            'conditions' => 'array',
            'metadata' => 'array',
            'usage_limit' => 'integer',
            'usage_count' => 'integer',
        ];
    }

    // -- Relations --

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
     * @return HasMany<PromotionUsage, $this>
     */
    public function usages(): HasMany
    {
        return $this->hasMany(PromotionUsage::class);
    }

    // -- Domain Logic --

    /**
     * Check if this promotion is currently active (status + date/time window).
     */
    public function isCurrentlyActive(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        if ($this->status !== PromotionStatus::Active) {
            return false;
        }

        // Check date range — resolve from raw attributes to support both
        // Eloquent-casted Carbon and raw string values (e.g. in unit tests)
        $startsAt = $this->resolveDateAttribute('starts_at');
        $endsAt = $this->resolveDateAttribute('ends_at');

        if ($startsAt !== null && $now->lt($startsAt)) {
            return false;
        }
        if ($endsAt !== null && $now->gt($endsAt)) {
            return false;
        }

        // Check day-of-week restriction
        if ($this->days_of_week !== null && count($this->days_of_week) > 0) {
            $dayOfWeek = (int) $now->format('N'); // 1=Monday, 7=Sunday
            if (! in_array($dayOfWeek, $this->days_of_week, true)) {
                return false;
            }
        }

        // Check time window
        if ($this->time_from !== null && $this->time_until !== null) {
            $currentTime = $now->format('H:i');
            if ($currentTime < $this->time_from || $currentTime >= $this->time_until) {
                return false;
            }
        }

        // Check usage limit
        if ($this->usage_limit !== null && $this->usage_count >= $this->usage_limit) {
            return false;
        }

        return true;
    }

    /**
     * Resolve a date attribute as Carbon, handling both raw strings and casted values.
     */
    private function resolveDateAttribute(string $key): ?Carbon
    {
        $raw = $this->getAttributes()[$key] ?? null;
        if ($raw === null) {
            return null;
        }
        if ($raw instanceof Carbon) {
            return $raw;
        }

        return Carbon::parse($raw);
    }

    // -- Scopes --

    /**
     * @param  Builder<Promotion>  $query
     * @return Builder<Promotion>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where(function (Builder $q) use ($companyId) {
            $q->where('company_id', $companyId)
                ->orWhereNull('company_id');
        });
    }

    /**
     * @param  Builder<Promotion>  $query
     * @return Builder<Promotion>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', PromotionStatus::Active);
    }

    /**
     * @param  Builder<Promotion>  $query
     * @return Builder<Promotion>
     */
    public function scopeByPriority(Builder $query): Builder
    {
        return $query->orderBy('priority', 'desc')->orderBy('created_at');
    }
}
