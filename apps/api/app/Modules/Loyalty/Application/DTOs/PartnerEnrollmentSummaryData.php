<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\DTOs;

use App\Modules\Loyalty\Domain\Entities\Enrollment;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One program enrollment row for a partner's loyalty card (boss-app + cashier
 * enrollment surface). Balances are strings end-to-end (rule 19).
 */
#[TypeScript]
class PartnerEnrollmentSummaryData extends Data
{
    public function __construct(
        public string $enrollment_id,
        public string $program_id,
        public string $program_name,
        public string $balance,
        public ?string $tier,
        public string $status,
    ) {}

    public static function fromModel(Enrollment $enrollment): self
    {
        return new self(
            enrollment_id: $enrollment->id,
            program_id: $enrollment->program_id,
            program_name: $enrollment->program->name,
            balance: (string) $enrollment->current_balance,
            tier: $enrollment->currentTier?->name,
            status: $enrollment->status->value,
        );
    }
}
