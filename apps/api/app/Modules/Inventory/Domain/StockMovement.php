<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $product_id
 * @property string $location_id
 * @property MovementType $movement_type
 * @property MovementReason|null $reason
 * @property numeric-string $quantity
 * @property numeric-string $quantity_before
 * @property numeric-string $quantity_after
 * @property numeric-string|null $unit_cost
 * @property numeric-string|null $total_cost
 * @property numeric-string|null $avg_cost_before
 * @property numeric-string|null $avg_cost_after
 * @property string|null $reference
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property string|null $notes
 * @property string|null $user_id
 * @property bool $is_historical
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Product $product
 * @property-read Location $location
 * @property-read User|null $user
 */
class StockMovement extends Model
{
    use HasUuids;

    protected $table = 'stock_movements';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'product_id',
        'location_id',
        'movement_type',
        'reason',
        'quantity',
        'quantity_before',
        'quantity_after',
        'unit_cost',
        'total_cost',
        'avg_cost_before',
        'avg_cost_after',
        'reference',
        'reference_type',
        'reference_id',
        'notes',
        'user_id',
        'is_historical',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'movement_type' => MovementType::class,
            'reason' => MovementReason::class,
            'quantity' => 'decimal:2',
            'quantity_before' => 'decimal:2',
            'quantity_after' => 'decimal:2',
            'unit_cost' => 'decimal:3',
            'total_cost' => 'decimal:3',
            'avg_cost_before' => 'decimal:3',
            'avg_cost_after' => 'decimal:3',
            'is_historical' => 'boolean',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
     * Scope to filter by movement type.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfType(Builder $query, MovementType $type): Builder
    {
        return $query->where('movement_type', $type);
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
}
