<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Events;

final class ExpenseStatementSuggestionsRequested
{
    /** @var list<array{expense_id: string, amount: numeric-string, label: string, date: string, reference_matched: bool}> */
    private array $candidates = [];

    /** @param numeric-string $amount */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $repositoryId,
        public readonly string $currency,
        public readonly string $amount,
        public readonly string $windowStart,
        public readonly string $windowEnd,
        public readonly string $lineText,
    ) {}

    /** @param numeric-string $amount */
    public function addCandidate(
        string $expenseId,
        string $amount,
        string $label,
        string $date,
        bool $referenceMatched,
    ): void {
        $this->candidates[] = [
            'expense_id' => $expenseId,
            'amount' => $amount,
            'label' => $label,
            'date' => $date,
            'reference_matched' => $referenceMatched,
        ];
    }

    /** @return list<array{expense_id: string, amount: numeric-string, label: string, date: string, reference_matched: bool}> */
    public function candidates(): array
    {
        return $this->candidates;
    }
}
