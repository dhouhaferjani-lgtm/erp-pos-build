<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $receipt_line_id
 * @property string $batch_allocation_id
 * @property int $batch_id
 * @property numeric-string $quantity_received
 * @property numeric-string $quantity_damaged
 * @property numeric-string $quantity_written_off
 * @property numeric-string $quantity_returned
 * @property string|null $in_movement_id
 * @property string|null $scrap_movement_id
 * @property string|null $return_movement_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StockTransferReceiptLine $receiptLine
 * @property-read StockTransferLineBatchAllocation $batchAllocation
 * @property-read Batch $batch
 */
class StockTransferReceiptLineLot extends Model
{
    use HasUuids;

    protected $table = 'stock_transfer_receipt_line_lots';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'receipt_line_id',
        'batch_allocation_id',
        'batch_id',
        'quantity_received',
        'quantity_damaged',
        'quantity_written_off',
        'quantity_returned',
        'in_movement_id',
        'scrap_movement_id',
        'return_movement_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'batch_id' => 'integer',
            'quantity_received' => 'decimal:4',
            'quantity_damaged' => 'decimal:4',
            'quantity_written_off' => 'decimal:4',
            'quantity_returned' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<StockTransferReceiptLine, $this> */
    public function receiptLine(): BelongsTo
    {
        return $this->belongsTo(StockTransferReceiptLine::class, 'receipt_line_id');
    }

    /** @return BelongsTo<StockTransferLineBatchAllocation, $this> */
    public function batchAllocation(): BelongsTo
    {
        return $this->belongsTo(StockTransferLineBatchAllocation::class, 'batch_allocation_id');
    }

    /** @return BelongsTo<Batch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }
}
