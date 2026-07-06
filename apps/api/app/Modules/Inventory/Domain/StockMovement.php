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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $product_id
 * @property string|null $variant_id
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
 * @property string|null $reverses_movement_id
 * @property Carbon|null $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Product $product
 * @property-read Location $location
 * @property-read User|null $user
 * @property-read StockMovement|null $reversesMovement
 * @property-read StockMovement|null $reversalOf
 */
class StockMovement extends Model
{
    use HasUuids;

    protected $table = 'stock_movements';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'product_id',
        'variant_id',
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
        'reverses_movement_id',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'movement_type' => MovementType::class,
            'reason' => MovementReason::class,
            'quantity' => 'decimal:4',
            'quantity_before' => 'decimal:4',
            'quantity_after' => 'decimal:4',
            // WAC cost ledger carried at higher internal precision (6 dp) at rest;
            // rounded HALF-UP to the currency scale only at the GL/COGS posting
            // (and display) boundary. See the scale-6 widening migration.
            'unit_cost' => 'decimal:6',
            'total_cost' => 'decimal:6',
            'avg_cost_before' => 'decimal:6',
            'avg_cost_after' => 'decimal:6',
            'is_historical' => 'boolean',
            // Event time (device time for POS paths, now() otherwise). Drives the
            // live-inventory-counting replay; backfilled from created_at.
            'occurred_at' => 'datetime',
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
     * The original movement that this row reverses/corrects.
     * NULL when this movement is not itself a reversal.
     *
     * @return BelongsTo<StockMovement, $this>
     */
    public function reversesMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'reverses_movement_id');
    }

    /**
     * The reversal movement that undoes this row, if one exists.
     * NULL when this movement has not yet been reversed.
     *
     * @return HasOne<StockMovement, $this>
     */
    public function reversalOf(): HasOne
    {
        return $this->hasOne(StockMovement::class, 'reverses_movement_id');
    }

    /**
     * Row-level stock direction, derived from the signed
     * quantity_before -> quantity_after delta. Use this instead of
     * MovementType::isInbound() (ambiguous for adjustments) or the `quantity`
     * magnitude (whose sign convention is inconsistent across writers). 'flat'
     * covers zero-delta rows such as WAC cost adjustments (before == after).
     *
     * @return 'in'|'out'|'flat'
     */
    public function directionForRow(): string
    {
        $cmp = bccomp((string) $this->quantity_after, (string) $this->quantity_before, 4);

        return match (true) {
            $cmp > 0 => 'in',
            $cmp < 0 => 'out',
            default => 'flat',
        };
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
