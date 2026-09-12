<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Domain\QuantityScale;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $stock_transfer_line_id
 * @property string $tenant_id
 * @property string $company_id
 * @property int $batch_id
 * @property numeric-string $quantity
 * @property numeric-string $quantity_received
 * @property numeric-string $quantity_damaged
 * @property numeric-string $quantity_written_off
 * @property numeric-string $quantity_returned
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StockTransferLine $line
 * @property-read Batch $batch
 * @property-read Tenant $tenant
 * @property-read Company $company
 */
class StockTransferLineBatchAllocation extends Model
{
    use HasUuids;

    protected $table = 'stock_transfer_line_batch_allocations';

    protected $fillable = [
        'stock_transfer_line_id',
        'tenant_id',
        'company_id',
        'batch_id',
        'quantity',
        'quantity_received',
        'quantity_damaged',
        'quantity_written_off',
        'quantity_returned',

    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'batch_id' => 'integer',
            'quantity' => 'decimal:4',
            'quantity_received' => 'decimal:4',
            'quantity_damaged' => 'decimal:4',
            'quantity_written_off' => 'decimal:4',
            'quantity_returned' => 'decimal:4',

        ];
    }

    /**
     * @return BelongsTo<StockTransferLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(StockTransferLine::class, 'stock_transfer_line_id');
    }

    /**
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
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

    /** @return numeric-string */
    public function remainingQuantity(): string
    {
        $remaining = $this->quantity;
        foreach ([$this->quantity_received, $this->quantity_damaged, $this->quantity_written_off, $this->quantity_returned] as $accounted) {
            $remaining = bcsub($remaining, $accounted, QuantityScale::SCALE);
        }

        return $remaining;
    }
}
