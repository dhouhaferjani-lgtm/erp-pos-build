<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $transfer_id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $product_id
 * @property string|null $variant_id
 * @property numeric-string $quantity
 * @property numeric-string|null $unit_cost_snapshot
 * @property numeric-string $allocated_transfer_cost
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StockTransfer $transfer
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Product $product
 * @property-read Collection<int, StockTransferLineBatchAllocation> $batchAllocations
 */
class StockTransferLine extends Model
{
    use HasUuids;

    protected $table = 'stock_transfer_lines';

    protected $fillable = [
        'transfer_id',
        'tenant_id',
        'company_id',
        'product_id',
        'variant_id',
        'quantity',
        'unit_cost_snapshot',
        'allocated_transfer_cost',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost_snapshot' => 'decimal:4',
            'allocated_transfer_cost' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<StockTransfer, $this>
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'transfer_id');
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
     * @return HasMany<StockTransferLineBatchAllocation, $this>
     */
    public function batchAllocations(): HasMany
    {
        return $this->hasMany(StockTransferLineBatchAllocation::class, 'stock_transfer_line_id');
    }
}
