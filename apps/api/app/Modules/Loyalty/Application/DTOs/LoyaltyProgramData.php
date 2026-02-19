<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\DTOs;

use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class LoyaltyProgramData extends Data
{
    /**
     * @param  array<int, string>|null  $company_ids
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public string $id,
        public string $tenant_id,
        public ?array $company_ids,
        public string $name,
        public ProgramType $program_type,
        public ProgramStatus $status,
        public ?string $currency,
        public ?string $start_date,
        public ?string $end_date,
        public ?string $terms_and_conditions,
        public ?array $metadata,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(LoyaltyProgram $program): self
    {
        return new self(
            id: $program->id,
            tenant_id: $program->tenant_id,
            company_ids: $program->company_ids,
            name: $program->name,
            program_type: $program->program_type,
            status: $program->status,
            currency: $program->currency,
            start_date: $program->start_date?->toIso8601String(),
            end_date: $program->end_date?->toIso8601String(),
            terms_and_conditions: $program->terms_and_conditions,
            metadata: $program->metadata,
            created_at: $program->created_at?->toIso8601String() ?? '',
            updated_at: $program->updated_at?->toIso8601String(),
        );
    }
}
