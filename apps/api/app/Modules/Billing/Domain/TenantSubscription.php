<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain;

use App\Modules\Billing\Domain\Enums\SubscriptionStatus;
use App\Modules\Billing\Domain\ValueObjects\Money;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tenant subscription to a plan.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $plan_id
 * @property SubscriptionStatus $status
 * @property string $billing_cycle
 * @property string|null $price
 * @property string $currency
 * @property \Carbon\Carbon|null $trial_ends_at
 * @property \Carbon\Carbon|null $current_period_start
 * @property \Carbon\Carbon|null $current_period_end
 * @property \Carbon\Carbon|null $cancelled_at
 * @property \Carbon\Carbon|null $ends_at
 * @property string|null $stripe_subscription_id
 * @property string|null $stripe_customer_id
 * @property \Carbon\Carbon|null $last_payment_at
 * @property \Carbon\Carbon|null $next_payment_due
 * @property string|null $notes
 * @property array<string, mixed> $metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 */
final class TenantSubscription extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'tenant_subscriptions';

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'status',
        'billing_cycle',
        'price',
        'currency',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'cancelled_at',
        'ends_at',
        'stripe_subscription_id',
        'stripe_customer_id',
        'last_payment_at',
        'next_payment_due',
        'notes',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'price' => 'decimal:3',
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'date',
            'cancelled_at' => 'datetime',
            'ends_at' => 'datetime',
            'last_payment_at' => 'datetime',
            'next_payment_due' => 'datetime',
            'metadata' => 'array',
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
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'subscription_id');
    }

    /**
     * Get price as Money value object.
     */
    public function getPriceMoney(): ?Money
    {
        if ($this->price === null) {
            return null;
        }

        return new Money((float) $this->price, $this->currency ?? 'EUR');
    }

    /**
     * Check if subscription has access (is active).
     */
    public function hasAccess(): bool
    {
        return $this->status->hasAccess();
    }

    /**
     * Check if subscription is in trial period.
     */
    public function isOnTrial(): bool
    {
        return $this->status === SubscriptionStatus::Trial
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }

    /**
     * Check if subscription can be renewed.
     */
    public function canRenew(): bool
    {
        return $this->status->canRenew();
    }

    /**
     * Get days remaining in trial.
     */
    public function getTrialDaysRemaining(): int
    {
        if (! $this->isOnTrial()) {
            return 0;
        }

        return (int) now()->diffInDays($this->trial_ends_at, false);
    }

    /**
     * Check if subscription is past due.
     */
    public function isPastDue(): bool
    {
        return $this->status === SubscriptionStatus::PastDue;
    }

    /**
     * Mark subscription as active.
     */
    public function activate(): void
    {
        $this->update([
            'status' => SubscriptionStatus::Active,
            'trial_ends_at' => null,
        ]);
    }

    /**
     * Cancel subscription.
     */
    public function cancel(bool $immediately = false): void
    {
        if ($immediately) {
            $this->update([
                'status' => SubscriptionStatus::Cancelled,
                'cancelled_at' => now(),
                'ends_at' => now(),
            ]);
        } else {
            $this->update([
                'status' => SubscriptionStatus::Cancelling,
                'cancelled_at' => now(),
                'ends_at' => $this->current_period_end,
            ]);
        }
    }

    /**
     * Pause subscription.
     */
    public function pause(): void
    {
        $this->update([
            'status' => SubscriptionStatus::Paused,
        ]);
    }

    /**
     * Resume subscription.
     */
    public function resume(): void
    {
        if ($this->status === SubscriptionStatus::Paused) {
            $this->update([
                'status' => SubscriptionStatus::Active,
            ]);
        }
    }

    /**
     * Mark payment as received.
     */
    public function recordPayment(): void
    {
        $periodStart = now();
        $periodEnd = $this->billing_cycle === 'yearly'
            ? now()->addYear()
            : now()->addMonth();

        $this->update([
            'status' => SubscriptionStatus::Active,
            'last_payment_at' => now(),
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd,
            'next_payment_due' => $periodEnd,
        ]);
    }

    /**
     * Mark subscription as past due.
     */
    public function markAsPastDue(): void
    {
        $this->update([
            'status' => SubscriptionStatus::PastDue,
        ]);
    }

    /**
     * Mark subscription as unpaid.
     */
    public function markAsUnpaid(): void
    {
        $this->update([
            'status' => SubscriptionStatus::Unpaid,
        ]);
    }

    /**
     * Mark subscription as expired.
     */
    public function markAsExpired(): void
    {
        $this->update([
            'status' => SubscriptionStatus::Expired,
            'ends_at' => now(),
        ]);
    }
}
