<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs;

use App\Modules\Accounting\Domain\JournalLine;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class JournalLineData
{
    public function __construct(
        public string $id,
        public string $journalEntryId,
        public string $accountId,
        public string $debit,
        public string $credit,
        public ?string $description,
        public int $lineOrder,
    ) {}

    public static function fromModel(JournalLine $line): self
    {
        return new self(
            id: $line->id,
            journalEntryId: $line->journal_entry_id,
            accountId: $line->account_id,
            debit: $line->debit,
            credit: $line->credit,
            description: $line->description,
            lineOrder: $line->line_order,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'journal_entry_id' => $this->journalEntryId,
            'account_id' => $this->accountId,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'description' => $this->description,
            'line_order' => $this->lineOrder,
        ];
    }
}
