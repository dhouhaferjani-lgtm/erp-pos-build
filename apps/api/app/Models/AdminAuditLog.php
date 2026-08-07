<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property string $id
 * @property string $super_admin_id
 * @property string|null $tenant_id
 * @property string $action
 * @property string|null $entity_type
 * @property string|null $entity_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $notes
 * @property string|null $impersonator_id
 * @property string|null $impersonation_session_id
 * @property string|null $impersonation_event_id
 * @property int|null $impersonation_sequence
 * @property string|null $impersonation_previous_hash
 * @property string|null $impersonation_hash
 */
class AdminAuditLog extends Model
{
    // Central table — admin audit log must land centrally even if logged from tenant context.
    use CentralConnection;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'super_admin_id',
        'tenant_id',
        'action',
        'entity_type',
        'entity_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'notes',
        'impersonator_id',
        'impersonation_session_id',
        'impersonation_event_id',
        'impersonation_sequence',
        'impersonation_previous_hash',
        'impersonation_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
            'impersonation_sequence' => 'integer',
        ];
    }

    /** @return BelongsTo<SuperAdmin, $this> */
    public function superAdmin(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
