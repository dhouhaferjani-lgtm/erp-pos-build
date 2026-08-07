<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\Entities;

use App\Modules\SupportAccess\Domain\DTOs\GrantAuditDetailsData;
use App\Modules\SupportAccess\Domain\Enums\AuditOutcome;
use App\Modules\SupportAccess\Domain\Enums\SessionEventType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property string $id
 * @property string $grant_id
 * @property int $sequence
 * @property SessionEventType $event_type
 * @property AuditOutcome $outcome
 * @property string $tenant_id
 * @property string|null $subject_user_id
 * @property string|null $operator_id
 * @property string $actor_id
 * @property string $actor_type
 * @property GrantAuditDetailsData $details
 * @property string $previous_hash
 * @property string $hash
 * @property Carbon $occurred_at
 */
final class ImpersonationGrantEvent extends Model
{
    use CentralConnection;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'id', 'grant_id', 'sequence', 'event_type', 'outcome', 'tenant_id', 'subject_user_id',
        'operator_id', 'actor_id', 'actor_type', 'details', 'previous_hash', 'hash', 'occurred_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'event_type' => SessionEventType::class,
            'outcome' => AuditOutcome::class,
            'details' => GrantAuditDetailsData::class,
            'occurred_at' => 'datetime',
        ];
    }
}
