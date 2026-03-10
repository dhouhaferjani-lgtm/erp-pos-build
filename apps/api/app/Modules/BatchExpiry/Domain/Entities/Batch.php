<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Domain\Entities;

use App\Modules\BatchExpiry\Domain\Enums\ExpiryStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Batch extends Model
{
    use HasUuids;

    protected $table = 'product_batches';

    /**
     * Only auto-generate UUIDs for the `uuid` column, not the bigint `id` PK.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected $fillable = [
        'uuid',
        'tenant_id',
        'company_id',
        'product_id',
        'product_variant_id',
        'batch_number',
        'manufacturing_date',
        'expiry_date',
        'is_active',
        'is_expired',
        'is_recalled',
        'recall_reason',
        'recalled_at',
        'notes',
    ];

    protected $casts = [
        'manufacturing_date' => 'date',
        'expiry_date' => 'date',
        'is_active' => 'boolean',
        'is_expired' => 'boolean',
        'is_recalled' => 'boolean',
        'recalled_at' => 'datetime',
    ];

    /** @return BelongsTo<\App\Modules\Product\Domain\Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Product\Domain\Product::class);
    }

    /** @return BelongsTo<\App\Modules\Company\Domain\Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Company\Domain\Company::class);
    }

    /** @return HasMany<BatchStock, $this> */
    public function batchStock(): HasMany
    {
        return $this->hasMany(BatchStock::class, 'batch_id');
    }

    public function isExpired(): bool
    {
        return $this->expiry_date->isPast();
    }

    public function daysUntilExpiry(): int
    {
        return (int) now()->diffInDays($this->expiry_date, false);
    }

    public function expiryStatus(): ExpiryStatus
    {
        $days = $this->daysUntilExpiry();

        if ($days < 0) {
            return ExpiryStatus::EXPIRED;
        }
        if ($days <= 7) {
            return ExpiryStatus::CRITICAL;
        }
        if ($days <= 30) {
            return ExpiryStatus::WARNING;
        }
        if ($days <= 90) {
            return ExpiryStatus::APPROACHING;
        }

        return ExpiryStatus::OK;
    }

    public function canBeSold(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->is_recalled) {
            return false;
        }

        return $this->expiryStatus()->canSell();
    }

    public function recall(string $reason): void
    {
        $this->update([
            'is_recalled' => true,
            'recall_reason' => $reason,
            'recalled_at' => now(),
        ]);
    }

    public function getTotalQuantityAttribute(): float
    {
        return (float) $this->batchStock()->sum('quantity');
    }

    public function getAvailableQuantityAttribute(): float
    {
        return (float) $this->batchStock()->sum('available_quantity');
    }
}
