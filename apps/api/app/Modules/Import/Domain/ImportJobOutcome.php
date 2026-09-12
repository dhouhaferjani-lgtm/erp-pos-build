<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain;

use App\Modules\Import\Domain\Enums\ImportStatus;

/**
 * The single authority for "this terminal import is partially completed".
 *
 * An import that committed rows AND either rejected rows or failed to finalize is
 * neither a clean success nor a clean failure: the operator has data in the system
 * and work left to do. That rule is read by three surfaces — the durable terminal
 * write, the detail/list read model, and the history status filter (which must also
 * reclassify jobs stored before ImportStatus::PartiallyCompleted existed). Writing
 * it three times let them agree today and desynchronise on the next edit, so it is
 * expressed exactly once here, SQL rendering included.
 *
 * Pure and stateless by design: no dependencies to inject, and every caller —
 * including the static failed() hook path — can reach it.
 */
final class ImportJobOutcome
{
    public static function isPartiallyCompleted(int $successfulRows, int $failedRows, ?string $errorMessage): bool
    {
        return $successfulRows > 0 && ($failedRows > 0 || $errorMessage !== null);
    }

    /**
     * The status an operator should be shown, given what is stored.
     *
     * Non-terminal jobs are never reclassified: an import still running may hold
     * any counters at all.
     */
    public static function effectiveStatus(
        ImportStatus $stored,
        int $successfulRows,
        int $failedRows,
        ?string $errorMessage,
    ): ImportStatus {
        if (! $stored->isTerminal()) {
            return $stored;
        }

        return self::isPartiallyCompleted($successfulRows, $failedRows, $errorMessage)
            ? ImportStatus::PartiallyCompleted
            : $stored;
    }

    /**
     * The same rule as a SQL predicate: effective status = the supplied status.
     *
     * The terminal list is generated from the enum, so a new terminal case cannot
     * be forgotten here the way a hand-written IN list allowed.
     *
     * @return array{string, list<string>} the raw expression and its bindings
     */
    public static function effectiveStatusExpression(ImportStatus $status): array
    {
        $terminal = array_values(array_map(
            static fn (ImportStatus $case): string => $case->value,
            array_filter(ImportStatus::cases(), static fn (ImportStatus $case): bool => $case->isTerminal()),
        ));

        $sql = sprintf(
            'CASE WHEN status IN (%s) AND successful_rows > 0 AND (failed_rows > 0 OR error_message IS NOT NULL) THEN ? ELSE status END = ?',
            implode(', ', array_fill(0, count($terminal), '?')),
        );

        return [$sql, [...$terminal, ImportStatus::PartiallyCompleted->value, $status->value]];
    }
}
