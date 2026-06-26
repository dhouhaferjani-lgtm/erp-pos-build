<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Domain\Entities;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Idempotency ledger row for an accepted grouped (multi-lot) write-off.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $location_id
 * @property string $idempotency_key
 * @property string $reason
 * @property array<string, mixed> $result
 */
final class GroupedWriteOff extends Model
{
    use HasUuids;

    protected $table = 'grouped_write_offs';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'location_id',
        'idempotency_key',
        'reason',
        'result',
    ];

    protected $casts = [
        'result' => 'array',
    ];
}
