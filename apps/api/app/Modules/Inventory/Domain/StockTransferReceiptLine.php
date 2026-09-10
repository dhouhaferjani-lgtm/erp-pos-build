<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Modules\Inventory\Domain\Enums\TransferDiscrepancyReason;
use App\Modules\Product\Domain\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $receipt_id
 * @property string $transfer_line_id
 * @property string $product_id
 * @property string|null $variant_id
 * @property bool $is_lot_tracked
 * @property numeric-string $quantity_received
 * @property numeric-string $quantity_damaged
 * @property numeric-string $quantity_written_off
 * @property numeric-string $quantity_returned
 * @property numeric-string $quantity_sent_snapshot
 * @property TransferDiscrepancyReason|null $discrepancy_reason
 * @property string|null $discrepancy_note
 * @property string|null $in_movement_id
 * @property string|null $scrap_movement_id
 * @property string|null $return_movement_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StockTransferReceipt $receipt
 * @property-read StockTransferLine $transferLine
 * @property-read Product $product
 * @property-read Collection<int, StockTransferReceiptLineLot> $lots
 */
class StockTransferReceiptLine extends Model
{
    use HasUuids;

    protected $table = 'stock_transfer_receipt_lines';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'receipt_id',
        'transfer_line_id',
        'product_id',
        'variant_id',
        'is_lot_tracked',
        'quantity_received',
        'quantity_damaged',
        'quantity_written_off',
        'quantity_returned',
        'quantity_sent_snapshot',
        'discrepancy_reason',
        'discrepancy_note',
        'in_movement_id',
        'scrap_movement_id',
        'return_movement_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_lot_tracked' => 'boolean',
            'quantity_received' => 'decimal:4',
            'quantity_damaged' => 'decimal:4',
            'quantity_written_off' => 'decimal:4',
            'quantity_returned' => 'decimal:4',
            'quantity_sent_snapshot' => 'decimal:4',
            'discrepancy_reason' => TransferDiscrepancyReason::class,
        ];
    }

    /** @return BelongsTo<StockTransferReceipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(StockTransferReceipt::class, 'receipt_id');
    }

    /** @return BelongsTo<StockTransferLine, $this> */
    public function transferLine(): BelongsTo
    {
        return $this->belongsTo(StockTransferLine::class, 'transfer_line_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** @return HasMany<StockTransferReceiptLineLot, $this> */
    public function lots(): HasMany
    {
        return $this->hasMany(StockTransferReceiptLineLot::class, 'receipt_line_id');
    }
}
