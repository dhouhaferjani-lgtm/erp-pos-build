<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * POS receipt line batch allocation.
 *
 * Tracks which batches were allocated to each receipt line during a POS sale.
 * Receipt lines stay as-is (one line per product for customer-facing receipt).
 * Batch allocation is tracked separately for internal traceability.
 *
 * @property string $id
 * @property string $receipt_id
 * @property string $receipt_line_id
 * @property int $batch_id
 * @property numeric-string $quantity
 * @property string $batch_number Snapshot at time of sale
 * @property Carbon $expiry_date Snapshot at time of sale
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReceiptLineBatchAllocation extends Model
{
    use HasUuids;

    protected $table = 'pos_receipt_line_batch_allocations';

    /** @var list<string> */
    protected $fillable = [
        'receipt_id',
        'receipt_line_id',
        'batch_id',
        'quantity',
        'batch_number',
        'expiry_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'batch_id' => 'integer',
            'quantity' => 'decimal:3',
            'expiry_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Receipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    /**
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }
}
