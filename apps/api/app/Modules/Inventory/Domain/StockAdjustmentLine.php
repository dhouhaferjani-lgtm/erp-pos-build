<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\Company\Domain\Company;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Product\Domain\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One (product, variant, lot) correction inside a stock-adjustment document.
 *
 * `delta_quantity` is SIGNED and its sign is bound to `reason_code` by a DB
 * CHECK (D6/D6a). `observed_before` is the authoring snapshot the staleness
 * guard compares against; `quantity_before` / `quantity_after` are what was
 * ACTUALLY posted, and are the permanent evidence of an acknowledged override.
 *
 * @property string $id
 * @property string $adjustment_id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $product_id
 * @property string|null $variant_id
 * @property int|null $batch_id
 * @property MovementReason $reason_code
 * @property numeric-string $delta_quantity
 * @property numeric-string $observed_before
 * @property numeric-string|null $quantity_before
 * @property numeric-string|null $quantity_after
 * @property string|null $movement_id
 * @property string|null $line_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StockAdjustment $adjustment
 * @property-read Company $company
 * @property-read Product $product
 * @property-read Batch|null $batch
 * @property-read StockMovement|null $movement
 */
class StockAdjustmentLine extends Model
{
    use HasUuids;

    protected $table = 'stock_adjustment_lines';

    /** @var list<string> */
    protected $fillable = [
        'adjustment_id',
        'tenant_id',
        'company_id',
        'product_id',
        'variant_id',
        'batch_id',
        'reason_code',
        'delta_quantity',
        'observed_before',
        'quantity_before',
        'quantity_after',
        'movement_id',
        'line_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason_code' => MovementReason::class,
            'delta_quantity' => 'decimal:4',
            'observed_before' => 'decimal:4',
            'quantity_before' => 'decimal:4',
            'quantity_after' => 'decimal:4',
            'batch_id' => 'integer',
        ];
    }

    /** @return BelongsTo<StockAdjustment, $this> */
    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'adjustment_id');
    }

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

    /** @return BelongsTo<Batch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    /** @return BelongsTo<StockMovement, $this> */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'movement_id');
    }
}
