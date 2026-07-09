<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Modules\Treasury\Application\Services\TreasuryMovementService;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Treasury Money-Movement Spine, Task 22 (cutover) — architecture gate.
 *
 * `payment_repositories.balance` is port-managed: the ONE and ONLY writer of a
 * PaymentRepository's cached balance is {@see TreasuryMovementService}
 * (spec §5). A pgsql trigger (migration
 * `*_forbid_direct_payment_repository_balance_writes`) enforces this at runtime;
 * this test enforces it at the source level so a rogue `$repo->balance = …`
 * assignment is caught in review/CI on ANY driver — before it ever reaches a
 * database.
 *
 * SCOPE — the Treasury module only. PaymentRepository is a Treasury-owned model;
 * module boundaries (CLAUDE.md rule 6) forbid other modules importing it, so the
 * only place a PaymentRepository `->balance` can be assigned is in-module. The
 * report tier's `$account->balance = …` / `$node->balance = …` writes elsewhere
 * are on Account / report-node objects, NOT PaymentRepository, and are correctly
 * out of scope by construction.
 */
final class TreasuryBalanceWritePortTest extends TestCase
{
    /**
     * The single file permitted to assign `->balance` inside the Treasury module.
     */
    private const ALLOWED_BASENAME = 'TreasuryMovementService.php';

    #[Test]
    public function only_the_movement_port_assigns_payment_repository_balance(): void
    {
        $violations = $this->scanTreasuryModule();

        $this->assertSame(
            [],
            $violations,
            "Direct `->balance = …` assignment(s) found outside the treasury movement port.\n"
            .'`payment_repositories.balance` is port-managed — route the write through '
            ."TreasuryMovementService::record()/transfer() (spec §5). Offenders:\n"
            .implode("\n", array_map(
                static fn (array $v): string => "  {$v['file']}:{$v['line']}",
                $violations,
            )),
        );
    }

    /**
     * @return list<array{file: string, line: int}>
     */
    private function scanTreasuryModule(): array
    {
        $base = base_path('app/Modules/Treasury');
        if (! is_dir($base)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        );

        $violations = [];

        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo || $entry->getExtension() !== 'php') {
                continue;
            }

            $path = $entry->getPathname();
            if ($entry->getBasename() === self::ALLOWED_BASENAME) {
                continue;
            }

            $lines = preg_split('/\R/', (string) file_get_contents($path)) ?: [];
            foreach ($lines as $index => $line) {
                if ($this->isBalanceAssignment($line)) {
                    $violations[] = [
                        'file' => $this->relativePath($path),
                        'line' => $index + 1,
                    ];
                }
            }
        }

        return $violations;
    }

    /**
     * True when the line contains a real `->balance = …` assignment (not a
     * comparison like `==`/`>=`, not `->balance_after`, and not a comment).
     */
    private function isBalanceAssignment(string $line): bool
    {
        $trimmed = ltrim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')) {
            return false;
        }

        // `->balance` on a word boundary (excludes `->balance_after`), optional
        // whitespace, a single `=` NOT followed by another `=` (excludes
        // `==`/`===`; `>=`/`<=`/`!=` never match `->balance =`).
        return preg_match('/->balance\b\s*=(?!=)/', $line) === 1;
    }

    private function relativePath(string $absolute): string
    {
        $root = base_path().'/';

        return str_starts_with($absolute, $root) ? substr($absolute, strlen($root)) : $absolute;
    }
}
