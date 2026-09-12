<?php

declare(strict_types=1);

namespace Tests\Unit\Import;

use App\Modules\Import\Domain\Enums\ImportStatus;
use App\Modules\Import\Domain\ImportJobOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The partial-completion rule used to exist three times — on the durable
 * terminal write, on the read model, and as raw SQL in the history filter.
 * These cases pin the one authority all three now call, including the SQL
 * rendering, so the stored status, the badge and the filter chips cannot
 * desynchronise.
 */
final class ImportJobOutcomeTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, ?string, bool}>
     */
    public static function predicateCases(): iterable
    {
        yield 'clean success' => [10, 0, null, false];
        yield 'committed rows plus failed rows' => [9, 1, null, true];
        yield 'committed rows plus a finalize error' => [9, 0, 'Finalize failed', true];
        yield 'committed rows plus both' => [9, 1, 'Finalize failed', true];
        yield 'nothing committed, rows failed' => [0, 5, null, false];
        yield 'nothing committed, finalize failed' => [0, 0, 'Finalize failed', false];
        yield 'skip-only run' => [0, 0, null, false];
    }

    #[DataProvider('predicateCases')]
    public function test_partial_completion_needs_committed_rows_and_a_failure(
        int $successful,
        int $failed,
        ?string $message,
        bool $expected,
    ): void {
        $this->assertSame($expected, ImportJobOutcome::isPartiallyCompleted($successful, $failed, $message));
    }

    public function test_effective_status_reclassifies_only_terminal_statuses(): void
    {
        $this->assertSame(
            ImportStatus::PartiallyCompleted,
            ImportJobOutcome::effectiveStatus(ImportStatus::Completed, 9, 1, null),
        );
        $this->assertSame(
            ImportStatus::PartiallyCompleted,
            ImportJobOutcome::effectiveStatus(ImportStatus::Failed, 2, 1, 'Finalize failed'),
        );
        $this->assertSame(
            ImportStatus::Completed,
            ImportJobOutcome::effectiveStatus(ImportStatus::Completed, 10, 0, null),
        );
        $this->assertSame(
            ImportStatus::Failed,
            ImportJobOutcome::effectiveStatus(ImportStatus::Failed, 0, 3, 'Boom'),
        );
        // An in-flight job is never reclassified, however its counters read.
        $this->assertSame(
            ImportStatus::Importing,
            ImportJobOutcome::effectiveStatus(ImportStatus::Importing, 9, 1, 'Boom'),
        );
    }

    public function test_sql_rendering_is_generated_from_the_same_rule(): void
    {
        [$sql, $bindings] = ImportJobOutcome::effectiveStatusExpression(ImportStatus::PartiallyCompleted);

        $terminal = array_values(array_map(
            static fn (ImportStatus $status): string => $status->value,
            array_filter(ImportStatus::cases(), static fn (ImportStatus $status): bool => $status->isTerminal()),
        ));

        // Every terminal case is bound — a case added to the enum cannot be
        // forgotten here, which is what a hand-written IN list allowed.
        $this->assertSame(
            [...$terminal, ImportStatus::PartiallyCompleted->value, ImportStatus::PartiallyCompleted->value],
            $bindings,
        );
        $this->assertSame(
            substr_count($sql, '?'),
            count($bindings),
        );
        $this->assertStringContainsString('successful_rows > 0', $sql);
        $this->assertStringContainsString('failed_rows > 0 OR error_message IS NOT NULL', $sql);
    }
}
