<?php

declare(strict_types=1);

namespace App\Modules\Replenishment\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $client_request_uuid
 * @property string $request_id
 * @property Carbon $applied_at
 */
final class ReplenishmentCaptureReceipt extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'replenishment_capture_receipts';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'client_request_uuid',
        'request_id',
        'applied_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['applied_at' => 'immutable_datetime'];
    }
}
