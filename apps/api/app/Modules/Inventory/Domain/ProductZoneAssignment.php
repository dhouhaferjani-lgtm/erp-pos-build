<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Modules\Company\Domain\Location;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A product's placement inside a zone at a given location. Unique per
 * (product_id, location_id): a product can occupy only one zone per
 * location, so reassigning to a different zone moves it (upsert), it
 * never duplicates.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $product_id
 * @property string $location_id
 * @property string $zone_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Product $product
 * @property-read Location $location
 * @property-read LocationZone $zone
 */
class ProductZoneAssignment extends Model
{
    use HasUuids;

    protected $table = 'product_zone_assignments';

    protected $fillable = [
        'tenant_id',
        'product_id',
        'location_id',
        'zone_id',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
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
     * @return BelongsTo<LocationZone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(LocationZone::class, 'zone_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInZone(Builder $query, string $zoneId): Builder
    {
        return $query->where('zone_id', $zoneId);
    }
}
