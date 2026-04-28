<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Domain\Entities;

use App\Modules\Inventory\Domain\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Inventory batch movement entity.
 *
 * Links batch-level stock movements to the aggregate stock_movements table.
 * This provides batch-level granularity for products that require batch tracking.
 *
 * @property int $id
 * @property string $tenant_id UUID of the tenant
 * @property int $batch_id Foreign key to product_batches
 * @property string $movement_id UUID Foreign key to stock_movements
 * @property numeric-string $quantity Quantity moved (positive or negative)
 * @property Carbon $created_at
 * @property-read Batch $batch
 * @property-read StockMovement $movement
 */
class BatchMovement extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'inventory_batch_movements';

    /**
     * Indicates if the model should be timestamped.
     * Only created_at is used (no updated_at).
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'batch_id',
        'movement_id',
        'quantity',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'batch_id' => 'integer',
            'quantity' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the batch this movement belongs to.
     *
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /**
     * Get the stock movement this batch movement is linked to.
     *
     * @return BelongsTo<StockMovement, $this>
     */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }
}
