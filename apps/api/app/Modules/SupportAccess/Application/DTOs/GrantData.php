<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Application\DTOs;

use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrant;
use App\Modules\SupportAccess\Domain\Enums\GrantStatus;
use App\Modules\SupportAccess\Domain\Enums\GrantType;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class GrantData extends Data
{
    public function __construct(
        public string $id,
        public string $tenant_id,
        public ?string $subject_user_id,
        public ?string $operator_id,
        public GrantType $type,
        public GrantStatus $status,
        public string $reason,
        public string $ticket_ref,
        public CarbonInterface $starts_at,
        public CarbonInterface $expires_at,
        public ?string $tenant_approved_by,
        public ?CarbonInterface $tenant_approved_at,
        public ?string $second_approved_by,
        public ?CarbonInterface $second_approved_at,
        public ?string $revoked_by,
        public ?CarbonInterface $revoked_at,
        public ?string $revocation_reason,
    ) {}

    public static function fromModel(ImpersonationGrant $grant): self
    {
        return new self(
            id: $grant->id,
            tenant_id: $grant->tenant_id,
            subject_user_id: $grant->subject_user_id,
            operator_id: $grant->operator_id,
            type: $grant->type,
            status: $grant->status,
            reason: $grant->reason,
            ticket_ref: $grant->ticket_ref,
            starts_at: $grant->starts_at,
            expires_at: $grant->expires_at,
            tenant_approved_by: $grant->tenant_approved_by,
            tenant_approved_at: $grant->tenant_approved_at,
            second_approved_by: $grant->second_approved_by,
            second_approved_at: $grant->second_approved_at,
            revoked_by: $grant->revoked_by,
            revoked_at: $grant->revoked_at,
            revocation_reason: $grant->revocation_reason,
        );
    }
}
