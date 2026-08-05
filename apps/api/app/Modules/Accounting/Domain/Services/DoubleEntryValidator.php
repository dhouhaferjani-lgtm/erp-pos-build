<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Services;

use App\Shared\Contracts\CurrencyScaleResolverInterface;

final class DoubleEntryValidator
{
    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    /**
     * Check if journal entry lines are balanced (total debits = total credits).
     *
     * @param  array<int, array{debit: string, credit: string}>  $lines
     */
    public function isBalanced(array $lines): bool
    {
        if (count($lines) < 2) {
            return false;
        }

        $totalDebits = '0.00';
        $totalCredits = '0.00';

        foreach ($lines as $line) {
            /** @var numeric-string $debit */
            $debit = $line['debit'];
            /** @var numeric-string $credit */
            $credit = $line['credit'];

            $totalDebits = bcadd($totalDebits, $debit, $this->scale());
            $totalCredits = bcadd($totalCredits, $credit, $this->scale());
        }

        return bccomp($totalDebits, $totalCredits, $this->scale()) === 0;
    }

    /**
     * Σdebits == Σcredits, and NOTHING else.
     *
     * {@see isBalanced()} additionally rejects `count($lines) < 2` — a sensible
     * rule for the manual journal-entry route, where a one-line entry is always a
     * user mistake, but an unadvertised second rejection for the document-sourced
     * auto-posting paths (gate finding I-5): a document with no lines produces
     * exactly one AR leg, and a zero-total document's single zero leg is
     * arithmetically balanced. Those paths call THIS method.
     *
     * The scale is passed in rather than resolved from `CompanyContext`, so the
     * caller can supply the ENTITY's currency scale (CLAUDE.md rule 19) and the
     * check works in a queued/console context with no bound company.
     *
     * @param  array<int, array{debit: string, credit: string}>  $lines
     */
    public function isSumBalanced(array $lines, int $scale): bool
    {
        $totalDebits = '0';
        $totalCredits = '0';

        foreach ($lines as $line) {
            /** @var numeric-string $debit */
            $debit = $line['debit'];
            /** @var numeric-string $credit */
            $credit = $line['credit'];

            $totalDebits = bcadd($totalDebits, $debit, $scale);
            $totalCredits = bcadd($totalCredits, $credit, $scale);
        }

        return bccomp($totalDebits, $totalCredits, $scale) === 0;
    }

    /**
     * Check if each journal entry line is valid.
     * A line must have either a debit or credit (not both, not neither).
     *
     * @param  array<int, array{debit: string, credit: string}>  $lines
     */
    public function hasValidLines(array $lines): bool
    {
        foreach ($lines as $line) {
            /** @var numeric-string $debit */
            $debit = $line['debit'];
            /** @var numeric-string $credit */
            $credit = $line['credit'];

            $hasDebit = bccomp($debit, '0.00', $this->scale()) > 0;
            $hasCredit = bccomp($credit, '0.00', $this->scale()) > 0;

            // Line must have exactly one of debit or credit (XOR)
            if ($hasDebit === $hasCredit) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate a complete journal entry (balanced and valid lines).
     *
     * @param  array<int, array{debit: string, credit: string}>  $lines
     */
    public function validate(array $lines): bool
    {
        return $this->isBalanced($lines) && $this->hasValidLines($lines);
    }
}
