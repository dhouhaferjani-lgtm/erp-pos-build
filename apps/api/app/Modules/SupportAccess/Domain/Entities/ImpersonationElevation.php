<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Domain\Entities;

use App\Modules\SupportAccess\Domain\Enums\ElevationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

final class ImpersonationElevation extends Model
{
    use CentralConnection;
    use HasUuids;

    protected $fillable = [
        'session_id', 'requested_by', 'approved_by', 'status', 'reason', 'requested_at',
        'approved_at', 'expires_at', 'rejected_at', 'rejection_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ElevationStatus::class,
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'expires_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }
}
