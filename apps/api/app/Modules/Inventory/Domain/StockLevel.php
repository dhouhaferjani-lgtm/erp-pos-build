<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $product_id
 * @property string|null $variant_id
 * @property string $location_id
 * @property numeric-string $quantity
 * @property numeric-string $reserved
 * @property numeric-string|null $min_quantity
 * @property numeric-string|null $max_quantity
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Product $product
 * @property-read Location $location
 */
class StockLevel extends Model
{
    use HasUuids;

    protected $table = 'stock_levels';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'product_id',
        'variant_id',
        'location_id',
        'quantity',
        'reserved',
        'min_quantity',
        'max_quantity',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'reserved' => 'decimal:4',
            'min_quantity' => 'decimal:4',
            'max_quantity' => 'decimal:4',
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
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Get available quantity (total - reserved).
     *
     * @return numeric-string
     */
    public function getAvailableQuantity(): string
    {
        return bcsub($this->quantity, $this->reserved, 4);
    }

    /**
     * Check if stock is below minimum level.
     */
    public function isBelowMinimum(): bool
    {
        if ($this->min_quantity === null) {
            return false;
        }

        return bccomp($this->quantity, $this->min_quantity, 4) < 0;
    }

    /**
     * Scope to filter by tenant.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to filter by product.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForProduct(Builder $query, string $productId): Builder
    {
        return $query->where('product_id', $productId);
    }

    /**
     * Scope to filter by location.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAtLocation(Builder $query, string $locationId): Builder
    {
        return $query->where('location_id', $locationId);
    }

    /**
     * Scope to get items below minimum.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeBelowMinimum(Builder $query): Builder
    {
        return $query->whereNotNull('min_quantity')
            ->whereColumn('quantity', '<', 'min_quantity');
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
     * Get detailed breakdown of active reservations for this stock level.
     *
     * @return Collection<int, StockReservation>
     */
    public function getReservationBreakdown(): Collection
    {
        return StockReservation::where('product_id', $this->product_id)
            ->where('location_id', $this->location_id)
            ->active()
            ->with(['createdBy'])
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Recalculate and update the reserved quantity from active reservations.
     * Useful for fixing drift between reserved field and actual reservations.
     */
    public function recalculateReserved(): void
    {
        /** @var numeric-string $total */
        $total = (string) StockReservation::where('product_id', $this->product_id)
            ->where('location_id', $this->location_id)
            ->active()
            ->sum('quantity');

        $this->reserved = $total;
        $this->save();
    }
}
