<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property string $event_id
 * @property string $aggregate_type
 * @property string $tenant_id
 * @property Carbon|null $admin_delivered_at
 * @property Carbon|null $tenant_delivered_at
 * @property int $attempt_count
 * @property string|null $last_error
 */
final class ImpersonationAuditDelivery extends Model
{
    use CentralConnection;

    protected $primaryKey = 'event_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'event_id', 'aggregate_type', 'tenant_id', 'admin_delivered_at', 'tenant_delivered_at',
        'attempt_count', 'last_error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'admin_delivered_at' => 'datetime',
            'tenant_delivered_at' => 'datetime',
            'attempt_count' => 'integer',
        ];
    }
}
