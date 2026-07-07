<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Domain\Enums\LocationNodeType;
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
 * A node in the per-location placement hierarchy (zone/aisle/rack/shelf/bin/
 * section), arbitrary depth via parent_id. Nodes are LABELS ONLY — stock
 * quantity stays at (product, location[, variant]) grain; nodes never carry
 * their own quantity (see docs/superpowers/specs/
 * 2026-07-07-location-placement-hierarchy-design.md).
 *
 * `path` is the materialized ancestor code chain ('A1/R2/B7'), server-
 * authoritative; `depth` is 0 at top level. Soft-deletes are tombstones for
 * offline sync — never hard-delete in v1 (D10).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $location_id
 * @property string|null $parent_id
 * @property LocationNodeType $node_type
 * @property string $name
 * @property string $code
 * @property string $path
 * @property int $depth
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Location $location
 * @property-read LocationNode|null $parent
 * @property-read Collection<int, LocationNode> $children
 * @property-read Collection<int, ProductPlacement> $productPlacements
 */
class LocationNode extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'location_nodes';

    protected $fillable = [
        'tenant_id',
        'location_id',
        'parent_id',
        'node_type',
        'name',
        'code',
        'path',
        'depth',
        'sort_order',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'node_type' => LocationNodeType::class,
            'depth' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
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
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<LocationNode, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<LocationNode, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<ProductPlacement, $this>
     */
    public function productPlacements(): HasMany
    {
        return $this->hasMany(ProductPlacement::class, 'node_id');
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
    public function scopeAtLocation(Builder $query, string $locationId): Builder
    {
        return $query->where('location_id', $locationId);
    }

    /**
     * Canonical subtree filter: the node itself (path = :p) plus every
     * descendant (path LIKE :p || '/%'). The explicit '=' arm avoids the
     * 'A1' vs 'A10' bare-prefix collision; codes ban LIKE wildcards (D11)
     * so no binding escape is needed.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSubtreeOf(Builder $query, string $path): Builder
    {
        return $query->where(function (Builder $inner) use ($path): void {
            $inner->where('path', $path)->orWhere('path', 'like', $path.'/%');
        });
    }
}
