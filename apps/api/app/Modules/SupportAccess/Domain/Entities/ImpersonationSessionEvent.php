<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\Entities;

use App\Modules\SupportAccess\Domain\DTOs\ImpersonationAuditDetailsData;
use App\Modules\SupportAccess\Domain\Enums\AuditOutcome;
use App\Modules\SupportAccess\Domain\Enums\SessionEventType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property string $id
 * @property string $session_id
 * @property int $sequence
 * @property SessionEventType $event_type
 * @property AuditOutcome $outcome
 * @property string $operator_id
 * @property string $subject_user_id
 * @property string $tenant_id
 * @property string|null $request_id
 * @property string|null $http_method
 * @property string|null $path
 * @property ImpersonationAuditDetailsData $details
 * @property string $previous_hash
 * @property string $hash
 * @property Carbon $occurred_at
 */
final class ImpersonationSessionEvent extends Model
{
    use CentralConnection;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'id', 'session_id', 'sequence', 'event_type', 'outcome', 'operator_id', 'subject_user_id',
        'tenant_id', 'request_id', 'http_method', 'path', 'details', 'previous_hash', 'hash',
        'occurred_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'event_type' => SessionEventType::class,
            'outcome' => AuditOutcome::class,
            'details' => ImpersonationAuditDetailsData::class,
            'occurred_at' => 'datetime',
        ];
    }
}
