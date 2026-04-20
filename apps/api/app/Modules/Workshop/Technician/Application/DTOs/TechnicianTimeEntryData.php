<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Application\DTOs;

use App\Modules\Workshop\Technician\Domain\Enums\TimeEntrySource;
use App\Modules\Workshop\Technician\Domain\Enums\TimeEntryType;
use App\Modules\Workshop\Technician\Domain\TechnicianTimeEntry;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class TechnicianTimeEntryData extends Data
{
    public function __construct(
        public string $id,
        public string $technician_profile_id,
        public string $company_id,
        public string $started_at,
        public ?string $ended_at,
        public ?int $duration_minutes,
        public TimeEntryType $entry_type,
        public ?string $work_order_id,
        public TimeEntrySource $source,
        public ?string $recorded_by_user_id,
        public ?string $notes,
    ) {}

    public static function fromModel(TechnicianTimeEntry $e): self
    {
        return new self(
            id: $e->id,
            technician_profile_id: $e->technician_profile_id,
            company_id: $e->company_id,
            started_at: $e->started_at->toIso8601String(),
            ended_at: $e->ended_at?->toIso8601String(),
            duration_minutes: $e->duration_minutes,
            entry_type: $e->entry_type,
            work_order_id: $e->work_order_id,
            source: $e->source,
            recorded_by_user_id: $e->recorded_by_user_id,
            notes: $e->notes,
        );
    }
}
