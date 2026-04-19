<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs;

use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\JournalEntry;
use Illuminate\Support\Carbon;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class JournalEntryData
{
    /**
     * @param  array<int, JournalLineData>  $lines
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $entryNumber,
        public Carbon $entryDate,
        public ?string $description,
        public JournalEntryStatus $status,
        public ?string $sourceType,
        public ?string $sourceId,
        public array $lines,
        public ?Carbon $createdAt,
        public ?Carbon $updatedAt,
    ) {}

    public static function fromModel(JournalEntry $entry): self
    {
        return new self(
            id: $entry->id,
            tenantId: $entry->tenant_id,
            entryNumber: $entry->entry_number,
            entryDate: $entry->entry_date,
            description: $entry->description,
            status: $entry->status,
            sourceType: $entry->source_type,
            sourceId: $entry->source_id,
            lines: $entry->lines->map(fn ($line) => JournalLineData::fromModel($line))->all(),
            createdAt: $entry->created_at,
            updatedAt: $entry->updated_at,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'entry_number' => $this->entryNumber,
            'entry_date' => $this->entryDate->toDateString(),
            'description' => $this->description,
            'status' => $this->status->value,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'lines' => array_map(fn ($line) => $line->toArray(), $this->lines),
            'created_at' => $this->createdAt?->toIso8601String(),
            'updated_at' => $this->updatedAt?->toIso8601String(),
        ];
    }
}
