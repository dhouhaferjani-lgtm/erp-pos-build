<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\ReleaseReason;
use App\Modules\Inventory\Domain\Enums\ReservationSource;
use App\Modules\Product\Domain\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockReservation extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id',
        'product_id',
        'location_id',
        'batch_id',
        'quantity',
        'source_type',
        'source_id',
        'source_line_id',
        'expires_at',
        'expired_at',
        'released_at',
        'released_by',
        'release_reason',
        'priority',
        'notes',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'batch_id' => 'integer',
        'quantity' => 'decimal:4',
        'source_type' => ReservationSource::class,
        'release_reason' => ReleaseReason::class,
        'expires_at' => 'datetime',
        'expired_at' => 'datetime',
        'released_at' => 'datetime',
        'metadata' => 'array',
    ];

    // Relationships

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    /** @return BelongsTo<Batch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    // Scopes

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<StockReservation>  $query
     * @return \Illuminate\Database\Eloquent\Builder<StockReservation>
     */
    public function scopeActive($query)
    {
        return $query->whereNull('released_at');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<StockReservation>  $query
     * @return \Illuminate\Database\Eloquent\Builder<StockReservation>
     */
    public function scopeExpired($query)
    {
        return $query->active()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<StockReservation>  $query
     * @return \Illuminate\Database\Eloquent\Builder<StockReservation>
     */
    public function scopeForProduct($query, string $productId)
    {
        return $query->where('product_id', $productId);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<StockReservation>  $query
     * @return \Illuminate\Database\Eloquent\Builder<StockReservation>
     */
    public function scopeForLocation($query, string $locationId)
    {
        return $query->where('location_id', $locationId);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<StockReservation>  $query
     * @return \Illuminate\Database\Eloquent\Builder<StockReservation>
     */
    public function scopeForSource($query, ReservationSource $source, string $sourceId)
    {
        return $query->where('source_type', $source)
            ->where('source_id', $sourceId);
    }

    // Helpers

    public function isActive(): bool
    {
        return $this->released_at === null;
    }

    public function isExpired(): bool
    {
        return $this->isActive()
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    public function getTimeUntilExpiry(): ?int
    {
        if (! $this->isActive() || $this->expires_at === null) {
            return null;
        }

        return max(0, now()->diffInSeconds($this->expires_at, false));
    }

    public function getTotalValue(): float
    {
        return (float) $this->quantity * ($this->product?->cost_price ?? 0);
    }
}
