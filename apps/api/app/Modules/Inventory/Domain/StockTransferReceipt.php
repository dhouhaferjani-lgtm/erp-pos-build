<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\TransferCloseDisposition;
use App\Modules\Inventory\Domain\Enums\TransferReceiptKind;
use App\Modules\Inventory\Domain\Enums\TransferReceiptStatus;
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
 * @property string $transfer_id
 * @property string $receipt_number
 * @property TransferReceiptKind $kind
 * @property TransferCloseDisposition|null $disposition
 * @property TransferReceiptStatus $status
 * @property int $sequence
 * @property bool $is_blind
 * @property bool $has_discrepancy
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property string $received_by_user_id
 * @property Carbon $received_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StockTransfer $transfer
 * @property-read User|null $receivedBy
 * @property-read Collection<int, StockTransferReceiptLine> $lines
 */
class StockTransferReceipt extends Model
{
    use HasUuids;

    protected $table = 'stock_transfer_receipts';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'transfer_id',
        'receipt_number',
        'kind',
        'disposition',
        'status',
        'sequence',
        'is_blind',
        'has_discrepancy',
        'idempotency_key',
        'payload_hash',
        'received_by_user_id',
        'received_at',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => TransferReceiptKind::class,
            'disposition' => TransferCloseDisposition::class,
            'status' => TransferReceiptStatus::class,
            'sequence' => 'integer',
            'is_blind' => 'boolean',
            'has_discrepancy' => 'boolean',
            'received_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StockTransfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'transfer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    /** @return HasMany<StockTransferReceiptLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferReceiptLine::class, 'receipt_id');
    }
}
