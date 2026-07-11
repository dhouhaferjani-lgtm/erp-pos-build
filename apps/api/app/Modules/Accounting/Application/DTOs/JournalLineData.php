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
        public string $accountCode,
        public string $accountName,
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
            // The JE detail page renders "Compte" from these two fields
            // (frontend has expected them since Phase 3.2 — see types.ts drift
            // note). The `account` relation must be eager-loaded by the caller
            // (`lines.account`) to avoid an N+1 per line.
            accountCode: $line->account->code,
            accountName: $line->account->name,
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
            'account_code' => $this->accountCode,
            'account_name' => $this->accountName,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'description' => $this->description,
            'line_order' => $this->lineOrder,
        ];
    }
}
