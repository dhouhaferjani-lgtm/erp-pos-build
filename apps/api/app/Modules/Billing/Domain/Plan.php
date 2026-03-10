<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain;

use App\Modules\Billing\Domain\ValueObjects\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Subscription plan.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property array<string, mixed> $limits
 * @property string|null $price_monthly
 * @property string|null $price_yearly
 * @property string $currency
 * @property int $trial_days
 * @property bool $is_active
 * @property bool $is_public
 * @property int $display_order
 * @property string|null $stripe_monthly_price_id
 * @property string|null $stripe_yearly_price_id
 * @property \Carbon\Carbon $created_at
 */
final class Plan extends Model
{
    use HasUuids;

    protected $table = 'plans';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'description',
        'limits',
        'price_monthly',
        'price_yearly',
        'currency',
        'trial_days',
        'is_active',
        'is_public',
        'display_order',
        'stripe_monthly_price_id',
        'stripe_yearly_price_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'limits' => 'array',
            'price_monthly' => 'decimal:3',
            'price_yearly' => 'decimal:3',
            'trial_days' => 'integer',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<TenantSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(TenantSubscription::class);
    }

    /**
     * Get monthly price as Money value object.
     */
    public function getMonthlyPriceMoney(): ?Money
    {
        if ($this->price_monthly === null) {
            return null;
        }

        return new Money((float) $this->price_monthly, $this->currency);
    }

    /**
     * Get yearly price as Money value object.
     */
    public function getYearlyPriceMoney(): ?Money
    {
        if ($this->price_yearly === null) {
            return null;
        }

        return new Money((float) $this->price_yearly, $this->currency);
    }

    /**
     * Calculate yearly savings compared to monthly.
     */
    public function getYearlySavings(): ?float
    {
        if ($this->price_monthly === null || $this->price_yearly === null) {
            return null;
        }

        $yearlyIfMonthly = (float) $this->price_monthly * 12;
        $savings = $yearlyIfMonthly - (float) $this->price_yearly;

        return max(0, $savings);
    }

    /**
     * Calculate yearly savings percentage.
     */
    public function getYearlySavingsPercent(): ?float
    {
        $savings = $this->getYearlySavings();
        if ($savings === null || $this->price_monthly === null) {
            return null;
        }

        $yearlyIfMonthly = (float) $this->price_monthly * 12;
        if ($yearlyIfMonthly <= 0) {
            return null;
        }

        return round(($savings / $yearlyIfMonthly) * 100, 1);
    }

    /**
     * Get a specific limit value.
     */
    public function getLimit(string $key, mixed $default = null): mixed
    {
        return $this->limits[$key] ?? $default;
    }

    /**
     * Check if plan has a specific feature.
     */
    public function hasFeature(string $feature): bool
    {
        return $this->getLimit($feature, false) === true;
    }

    /**
     * Check if plan is free.
     */
    public function isFree(): bool
    {
        return ($this->price_monthly === null || (float) $this->price_monthly === 0.0)
            && ($this->price_yearly === null || (float) $this->price_yearly === 0.0);
    }

    /**
     * Get price for billing cycle.
     */
    public function getPriceForCycle(string $cycle): ?Money
    {
        return $cycle === 'yearly'
            ? $this->getYearlyPriceMoney()
            : $this->getMonthlyPriceMoney();
    }

    /**
     * Get Stripe price ID for billing cycle.
     */
    public function getStripePriceId(string $cycle): ?string
    {
        return $cycle === 'yearly'
            ? $this->stripe_yearly_price_id
            : $this->stripe_monthly_price_id;
    }

    /**
     * Scope to active plans only.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Plan>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Plan>
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to public plans only.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Plan>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Plan>
     */
    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }
}
