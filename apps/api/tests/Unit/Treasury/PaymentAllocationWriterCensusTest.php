<?php

declare(strict_types=1);

namespace Tests\Unit\Treasury;

use PHPUnit\Framework\TestCase;

/**
 * C-0a0 — EXECUTABLE census: every production writer of `payment_allocations`
 * consults `DocumentAllocationClassifier` before it writes.
 *
 * The invariant this lane ships is not "the classifier is correct"; it is "the
 * classifier is UNAVOIDABLE". A correct policy object that one writer skips is
 * worth nothing, and skipping it is exactly what `PaymentController::store()`
 * did for supplier invoices (a `? null :` bypass) and what the four refund /
 * reversal writers did entirely. A grep-based census is the only test that
 * fails when a NEW writer is added tomorrow.
 *
 * The census is deliberately source-level rather than behavioural: a behavioural
 * test can only cover the paths someone remembered to write a test for.
 */
final class PaymentAllocationWriterCensusTest extends TestCase
{
    /**
     * `PaymentAllocation::create(` — the only shape any production writer uses.
     * Any raw-builder write is asserted absent separately below.
     */
    private const WRITE_PATTERN = '/PaymentAllocation::(create|insert|updateOrCreate|firstOrCreate)\s*\(/';

    private const CLASSIFIER_PATTERN = '/allocationClassifier->(classify|classifyOrNull|classifyReceivableSide|refusalReasonFor|isAllocatable|assertReversalAdmitted)\s*\(/';

    /** A method declaration at class-body indentation. */
    private const METHOD_PATTERN = '/^\s{4}(?:final\s+)?(?:public|protected|private)\s+(?:static\s+)?function\s/';

    /**
     * Every file that writes the table, with the count of write sites. Pinned so
     * a new writer FILE cannot be added without this census being updated
     * deliberately.
     *
     * @var array<string, int>
     */
    private const EXPECTED_WRITERS = [
        'app/Modules/Treasury/Application/Services/CloseInvoiceWithToleranceService.php' => 1,
        'app/Modules/Treasury/Application/Services/PaymentAllocationService.php' => 1,
        'app/Modules/Treasury/Domain/Services/MultiPaymentService.php' => 2,
        'app/Modules/Treasury/Domain/Services/PaymentRefundService.php' => 3,
        'app/Modules/Treasury/Domain/Services/VendorRefundService.php' => 1,
        'app/Modules/Treasury/Presentation/Controllers/PaymentController.php' => 4,
    ];

    public function test_every_production_writer_of_payment_allocations_is_classified_first(): void
    {
        $unguarded = [];
        $census = [];

        foreach ($this->productionPhpFiles() as $relativePath => $absolutePath) {
            $lines = file($absolutePath, FILE_IGNORE_NEW_LINES);
            self::assertIsArray($lines, $relativePath);

            foreach ($lines as $index => $line) {
                if (preg_match(self::WRITE_PATTERN, $line) !== 1) {
                    continue;
                }

                $census[$relativePath] = ($census[$relativePath] ?? 0) + 1;
                $lineNumber = $index + 1;

                if (! $this->classifierPrecedes($lines, $index)) {
                    $unguarded[] = "{$relativePath}:{$lineNumber}";
                }
            }
        }

        self::assertSame(
            [],
            $unguarded,
            'These payment_allocations writers reach a write without a DocumentAllocationClassifier '
            .'call earlier in the same method: '.implode(', ', $unguarded),
        );

        ksort($census);
        $expected = self::EXPECTED_WRITERS;
        ksort($expected);

        self::assertSame(
            $expected,
            $census,
            'The set of payment_allocations writers changed. Add the new writer to EXPECTED_WRITERS '
            .'only after proving it calls DocumentAllocationClassifier before its write.',
        );
    }

    /**
     * A raw-builder write bypasses the model AND therefore any classifier call
     * this census can see. There must be none.
     */
    public function test_no_production_code_writes_payment_allocations_through_the_query_builder(): void
    {
        $offenders = [];

        foreach ($this->productionPhpFiles() as $relativePath => $absolutePath) {
            $contents = (string) file_get_contents($absolutePath);

            if (preg_match('/table\(\s*[\'"]payment_allocations[\'"]\s*\)\s*->\s*(insert|updateOrInsert|upsert)/', $contents) === 1) {
                $offenders[] = $relativePath;
            }
        }

        self::assertSame([], $offenders, 'Raw-builder writes to payment_allocations: '.implode(', ', $offenders));
    }

    /**
     * Walk backwards from the write to the start of its enclosing method and
     * look for a classifier call in between.
     *
     * @param  list<string>  $lines
     */
    private function classifierPrecedes(array $lines, int $writeIndex): bool
    {
        for ($i = $writeIndex - 1; $i >= 0; $i--) {
            if (preg_match(self::CLASSIFIER_PATTERN, $lines[$i]) === 1) {
                return true;
            }

            if (preg_match(self::METHOD_PATTERN, $lines[$i]) === 1) {
                return false;
            }
        }

        return false;
    }

    /**
     * @return array<string, string> relative path => absolute path
     */
    private function productionPhpFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $appPath = $root.'/app';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appPath, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $absolute = $file->getPathname();
            $files[ltrim(str_replace($root, '', $absolute), '/')] = $absolute;
        }

        ksort($files);

        return $files;
    }
}
