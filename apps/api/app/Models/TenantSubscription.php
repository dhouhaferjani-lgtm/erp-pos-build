<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSubscription extends Model
{
    use HasUuids;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'plan_id',
        'status',
        'trial_ends_at',
        'current_period_end',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_end' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the tenant that owns the subscription.
     *
     * @return BelongsTo<\App\Modules\Tenant\Domain\Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Tenant\Domain\Tenant::class);
    }

    /**
     * Get the plan for the subscription.
     *
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Check if subscription is on trial.
     */
    public function isOnTrial(): bool
    {
        if ($this->status !== 'trial' || $this->trial_ends_at === null) {
            return false;
        }

        /** @var \Illuminate\Support\Carbon $trialEndsAt */
        $trialEndsAt = $this->trial_ends_at;

        return $trialEndsAt->isFuture();
    }

    /**
     * Check if subscription is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if subscription is expired.
     */
    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }

    /**
     * Get days remaining in trial.
     */
    public function trialDaysRemaining(): int
    {
        if (! $this->isOnTrial() || $this->trial_ends_at === null) {
            return 0;
        }

        return (int) max(0, now()->diffInDays($this->trial_ends_at, false));
    }
}
