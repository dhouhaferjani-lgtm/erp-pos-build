<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\DTOs;

use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class LoyaltyMemberData extends Data
{
    public function __construct(
        public string $id,
        public string $tenant_id,
        public ?string $customer_id,
        public string $phone,
        public ?string $email,
        public ?string $first_name,
        public ?string $last_name,
        public ?string $date_of_birth,
        public string $status,
        public string $enrollment_date,
        public ?string $external_id,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(LoyaltyMember $member): self
    {
        return new self(
            id: $member->id,
            tenant_id: $member->tenant_id,
            customer_id: $member->customer_id,
            phone: $member->phone,
            email: $member->email,
            first_name: $member->first_name,
            last_name: $member->last_name,
            date_of_birth: $member->date_of_birth?->toDateString(),
            status: $member->status,
            enrollment_date: $member->enrollment_date->toIso8601String(),
            external_id: $member->external_id,
            created_at: $member->created_at?->toIso8601String() ?? '',
            updated_at: $member->updated_at?->toIso8601String(),
        );
    }
}
