<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * POS-core printable/read projection for device-authored ACCOUNT_PAYMENT fiscal events.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $fiscal_event_id
 * @property string $account_payment_uuid
 * @property string $customer_id
 * @property string $customer_name
 * @property numeric-string $amount
 * @property string $currency_code
 * @property array<string, mixed> $payload_snapshot
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class AccountPaymentReceipt extends Model
{
    use HasUuids;

    /** @var string */
    protected $table = 'pos_account_payment_receipts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'fiscal_event_id',
        'account_payment_uuid',
        'customer_id',
        'customer_name',
        'amount',
        'currency_code',
        'payload_snapshot',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload_snapshot' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
