<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\DTOs;

use App\Modules\Loyalty\Domain\Entities\Enrollment;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class EnrollmentData extends Data
{
    public function __construct(
        public string $id,
        public string $program_id,
        public ?string $program_name,
        public string $member_id,
        public string $current_balance,
        public string $lifetime_earned,
        public string $lifetime_redeemed,
        public ?string $current_tier_id,
        public ?string $tier_qualified_at,
        public string $status,
        public string $enrolled_at,
        public ?string $last_transaction_at,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(Enrollment $enrollment): self
    {
        return new self(
            id: $enrollment->id,
            program_id: $enrollment->program_id,
            program_name: $enrollment->relationLoaded('program') ? $enrollment->program->name : null,
            member_id: $enrollment->member_id,
            current_balance: (string) $enrollment->current_balance,
            lifetime_earned: (string) $enrollment->lifetime_earned,
            lifetime_redeemed: (string) $enrollment->lifetime_redeemed,
            current_tier_id: $enrollment->current_tier_id,
            tier_qualified_at: $enrollment->tier_qualified_at?->toIso8601String(),
            status: $enrollment->status->value,
            enrolled_at: $enrollment->enrolled_at->toIso8601String(),
            last_transaction_at: $enrollment->last_transaction_at?->toIso8601String(),
            created_at: $enrollment->created_at?->toIso8601String() ?? '',
            updated_at: $enrollment->updated_at?->toIso8601String(),
        );
    }
}
