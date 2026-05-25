<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain;

use App\Modules\POS\Domain\Enums\ApprovalScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $terminal_id
 * @property string $supervisor_user_id
 * @property string $cashier_user_id
 * @property ApprovalScope $approval_scope
 * @property string $target_event_type
 * @property string $target_reference_id
 * @property string $reason
 * @property Carbon $approved_at
 */
final class OperatorApproval extends Model
{
    use HasUuids;

    protected $table = 'operator_approvals';

    public $timestamps = true;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'terminal_id',
        'supervisor_user_id',
        'cashier_user_id',
        'approval_scope',
        'target_event_type',
        'target_reference_id',
        'reason',
        'approved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'approval_scope' => ApprovalScope::class,
            'approved_at' => 'datetime',
        ];
    }
}
