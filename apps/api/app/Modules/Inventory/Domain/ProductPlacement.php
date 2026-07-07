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
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A product's placement on a location node. One LIVE placement per
 * (product_id, location_id) — enforced by a partial unique index WHERE
 * deleted_at IS NULL; reassigning to another node moves the live row.
 * Unassign tombstones the row (SoftDeletes) so offline clients can converge
 * on deletions via the delta endpoint — never hard-delete in v1 (D10).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $product_id
 * @property string $location_id
 * @property string $node_id
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Product $product
 * @property-read Location $location
 * @property-read LocationNode $node
 */
class ProductPlacement extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'product_placements';

    protected $fillable = [
        'tenant_id',
        'product_id',
        'location_id',
        'node_id',
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
     * @return BelongsTo<LocationNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(LocationNode::class, 'node_id');
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
    public function scopeInNode(Builder $query, string $nodeId): Builder
    {
        return $query->where('node_id', $nodeId);
    }
}
