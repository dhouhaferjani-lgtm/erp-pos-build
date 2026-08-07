<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\Entities;

use App\Modules\SupportAccess\Domain\Enums\GrantStatus;
use App\Modules\SupportAccess\Domain\Enums\GrantType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $subject_user_id
 * @property string|null $operator_id
 * @property GrantType $type
 * @property GrantStatus $status
 * @property string $reason
 * @property string $ticket_ref
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $expires_at
 * @property string|null $tenant_approved_by
 * @property CarbonImmutable|null $tenant_approved_at
 * @property string|null $second_approved_by
 * @property CarbonImmutable|null $second_approved_at
 * @property string|null $rejected_by
 * @property CarbonImmutable|null $rejected_at
 * @property string|null $rejection_reason
 * @property string|null $revoked_by
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revocation_reason
 */
final class ImpersonationGrant extends Model
{
    use CentralConnection;
    use HasUuids;

    protected $fillable = [
        'tenant_id', 'subject_user_id', 'operator_id', 'type', 'status', 'reason', 'ticket_ref',
        'requested_at', 'starts_at', 'expires_at', 'tenant_approved_by', 'tenant_approved_at',
        'second_approved_by', 'second_approved_at', 'rejected_by', 'rejected_at',
        'rejection_reason', 'revoked_by', 'revoked_at', 'revocation_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => GrantType::class,
            'status' => GrantStatus::class,
            'requested_at' => 'immutable_datetime',
            'starts_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'tenant_approved_at' => 'immutable_datetime',
            'second_approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
