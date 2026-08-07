<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\Entities;

use App\Modules\SupportAccess\Domain\Enums\SessionAccessLevel;
use App\Modules\SupportAccess\Domain\Enums\SessionEndReason;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

final class ImpersonationSession extends Model
{
    use CentralConnection;
    use HasUuids;

    protected $fillable = [
        'grant_id', 'personal_access_token_id', 'operator_id', 'subject_user_id', 'tenant_id',
        'access_level', 'started_at', 'expires_at', 'last_seen_at', 'write_elevated_at',
        'write_expires_at', 'write_approved_by', 'ended_at', 'end_reason', 'chain_sequence',
        'chain_previous_hash', 'chain_head_hash',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'personal_access_token_id' => 'integer',
            'access_level' => SessionAccessLevel::class,
            'end_reason' => SessionEndReason::class,
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'write_elevated_at' => 'datetime',
            'write_expires_at' => 'datetime',
            'ended_at' => 'datetime',
            'chain_sequence' => 'integer',
        ];
    }
}
