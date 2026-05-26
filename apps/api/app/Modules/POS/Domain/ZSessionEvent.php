<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * POS read model for canonical Z-session fiscal lifecycle events.
 *
 * The source of truth is `fiscal_events`; this table gives POS/NF525
 * projections a stable query surface for SESSION_OPEN, drawer movements,
 * SESSION_CLOSE, and X_REPORT.
 *
 * @property string $id
 * @property string $fiscal_event_id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $terminal_id
 * @property string|null $shift_id
 * @property string $session_id
 * @property string $event_type
 * @property string $chain_context
 * @property int $sequence_number
 * @property string $previous_hash
 * @property string $current_hash
 * @property Carbon $business_date
 * @property Carbon $event_time_device
 * @property array<string, mixed> $payload
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class ZSessionEvent extends Model
{
    /** @var string */
    protected $table = 'pos_z_session_events';

    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'id',
        'fiscal_event_id',
        'tenant_id',
        'company_id',
        'terminal_id',
        'shift_id',
        'session_id',
        'event_type',
        'chain_context',
        'sequence_number',
        'previous_hash',
        'current_hash',
        'business_date',
        'event_time_device',
        'payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence_number' => 'integer',
            'business_date' => 'date',
            'event_time_device' => 'datetime',
            'payload' => 'array',
        ];
    }
}
