<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain;

use App\Modules\POS\Domain\Enums\ExchangeRequestStatus;
use App\Modules\Voucher\Domain\Voucher;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Idempotency record for the exchange flow.
 *
 * Stores the triple (return_receipt_id, sale_receipt_id, voucher_id?) so that
 * retries of the same exchange_request_id return the same triple without re-executing
 * the exchange logic. Partial-state failures are tracked via the status column and
 * failure_reason / failure_payload for debugging.
 *
 * @property string $id UUID primary key
 * @property string $tenant_id
 * @property string $company_id
 * @property string $exchange_request_id Client-supplied idempotency UUID
 * @property string $exchange_group_id The group UUID committed into both halves' v3 hash
 * @property ExchangeRequestStatus $status pending | completed | failed
 * @property string|null $return_receipt_id FK to pos_receipts (return half)
 * @property string|null $sale_receipt_id FK to pos_receipts (sale half)
 * @property string|null $voucher_id FK to vouchers (set when surplus→voucher)
 * @property string|null $failure_reason Short error description
 * @property array<string, mixed>|null $failure_payload Full exception context
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $completed_at
 * @property-read Receipt|null $returnReceipt
 * @property-read Receipt|null $saleReceipt
 * @property-read Voucher|null $voucher
 */
class ExchangeRequest extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'pos_exchange_requests';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'exchange_request_id',
        'exchange_group_id',
        'status',
        'return_receipt_id',
        'sale_receipt_id',
        'voucher_id',
        'failure_reason',
        'failure_payload',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ExchangeRequestStatus::class,
            'failure_payload' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * The return half of the exchange.
     *
     * @return BelongsTo<Receipt, $this>
     */
    public function returnReceipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'return_receipt_id');
    }

    /**
     * The sale half of the exchange.
     *
     * @return BelongsTo<Receipt, $this>
     */
    public function saleReceipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'sale_receipt_id');
    }

    /**
     * The surplus voucher, when net was negative and destination was voucher.
     *
     * @return BelongsTo<Voucher, $this>
     */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }
}
