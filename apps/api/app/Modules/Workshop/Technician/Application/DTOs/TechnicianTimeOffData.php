<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Application\DTOs;

use App\Modules\Workshop\Technician\Domain\Enums\TimeOffReason;
use App\Modules\Workshop\Technician\Domain\TechnicianTimeOff;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class TechnicianTimeOffData extends Data
{
    public function __construct(
        public string $id,
        public string $technician_profile_id,
        public string $starts_at,
        public string $ends_at,
        public TimeOffReason $reason_code,
        public bool $is_full_day,
        public bool $is_approved,
        public ?string $approved_by_user_id,
        public ?string $notes,
    ) {}

    public static function fromModel(TechnicianTimeOff $t): self
    {
        return new self(
            id: $t->id,
            technician_profile_id: $t->technician_profile_id,
            starts_at: $t->starts_at->toIso8601String(),
            ends_at: $t->ends_at->toIso8601String(),
            reason_code: $t->reason_code,
            is_full_day: $t->is_full_day,
            is_approved: $t->is_approved,
            approved_by_user_id: $t->approved_by_user_id,
            notes: $t->notes,
        );
    }
}
