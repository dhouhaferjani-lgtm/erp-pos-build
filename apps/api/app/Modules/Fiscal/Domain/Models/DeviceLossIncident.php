<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Models;

use App\Modules\Fiscal\Domain\Enums\DeviceLossIncidentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Device-loss incident register row (spec §12).
 *
 * Spec §12: "Device authority is not survivable without off-device
 * conservation." This register is the operator-facing audit trail for every
 * terminal-loss event so the recovery workflow has both a count of unsynced
 * fiscal events at the moment of the loss and a lifecycle status the recovery
 * service advances. The conservation control itself lives on the device
 * (`OffDeviceDurabilityService` exposing the off-device durability paths);
 * this table is the SERVER-SIDE incident ledger.
 *
 * Per the Task 9 / Task 10 boundary-discipline standing pattern, the
 * `recovery_status` lifecycle column is NOT mass-assignable: state transitions
 * (`reported → recovering → resolved | unrecoverable`) belong to the recovery
 * service, never to external request payloads. The DB default ('reported')
 * seeds the initial value at insert time; only explicit `setAttribute()` /
 * `forceFill()` from auditable service code can change it afterward.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $terminal_id
 * @property Carbon $reported_at
 * @property string|null $reported_by
 * @property string $reason
 * @property int $unsynced_count_at_incident
 * @property Carbon|null $last_synced_event_at
 * @property DeviceLossIncidentStatus $recovery_status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class DeviceLossIncident extends Model
{
    /** @var string */
    protected $table = 'device_loss_incidents';

    /** @var string */
    protected $keyType = 'string';

    /** @var bool */
    public $incrementing = false;

    /**
     * Mutable row — the recovery workflow advances `recovery_status` over time,
     * so standard Eloquent `created_at` / `updated_at` track lifecycle changes
     * for the operator browse.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * Insert-time identity + incident-capture fields only.
     *
     * `recovery_status` is intentionally OMITTED from `$fillable`: the DB
     * default ('reported') seeds it at insert time, and post-insert
     * transitions go through `setAttribute()` / `forceFill()` from the
     * recovery service (Task 9 / Task 10 boundary-discipline pattern). This
     * forecloses request-payload-driven lifecycle skips.
     *
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'tenant_id',
        'company_id',
        'terminal_id',
        'reported_at',
        'reported_by',
        'reason',
        'unsynced_count_at_incident',
        'last_synced_event_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reported_at' => 'datetime',
            'last_synced_event_at' => 'datetime',
            'unsynced_count_at_incident' => 'integer',
            'recovery_status' => DeviceLossIncidentStatus::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
