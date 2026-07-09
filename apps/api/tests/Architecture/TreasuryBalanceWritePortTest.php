<?php

declare(strict_types=1);

namespace Tests\Architecture;

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
 * PaymentRepository's cached balance is TreasuryMovementService (spec §5). A
 * pgsql trigger (migration `*_forbid_direct_payment_repository_balance_writes`)
 * enforces this at runtime; this test enforces it at the source level so a rogue
 * balance write is caught in review/CI on ANY driver — before it ever reaches a
 * database.
 *
 * Two scans (cutover-hardening Fix 3 — broadened from the original `->balance =`
 * / Treasury-only check):
 *
 *   Phase A — the Treasury module (recursive, minus TreasuryMovementService.php):
 *     inside Treasury a model `balance` write is always a PaymentRepository write
 *     (module boundaries, CLAUDE.md rule 6, keep the model in-module). Flags every
 *     write shape: `->balance = …`, `increment('balance')`/`decrement('balance')`,
 *     and an `update([…])`/`forceFill([…])`/`forceCreate([…])` carrying a `balance`
 *     key. Read-only response arrays (`'balance' => $repo->balance`), validation
 *     arrays, the model cast/`$attributes`, and `last_reconciled_balance` writes
 *     are correctly NOT matched.
 *
 *   Phase B — ALL of app/: a raw `payment_repositories` balance write (query
 *     builder `table('payment_repositories')->…->update([… 'balance' …])`/
 *     `increment('balance')`, or raw `UPDATE payment_repositories SET … balance`).
 *     These can target the table from ANY module, so the scan is app-wide. The
 *     report tier's `$account->balance = …` / `$node->balance = …` model writes
 *     are on Account / report-node objects (NOT payment_repositories) and are out
 *     of scope by construction.
 *
 * The trigger migration and the seeders live under database/ (outside app/) and
 * are not scanned; the seeders create repositories at balance 0 and establish any
 * opening balance through the port, and the runtime trigger guards them regardless.
 */
final class TreasuryBalanceWritePortTest extends TestCase
{
    /**
     * The single file permitted to write a PaymentRepository balance.
     */
    private const ALLOWED_BASENAME = 'TreasuryMovementService.php';

    #[Test]
    public function only_the_movement_port_writes_payment_repository_balance(): void
    {
        $violations = array_merge(
            $this->scanTreasuryModule(),
            $this->scanRawPaymentRepositoryWrites(),
        );

        $this->assertSame(
            [],
            $violations,
            "Direct `payment_repositories.balance` write(s) found outside the treasury movement port.\n"
            .'`balance` is port-managed — route the write through '
            ."TreasuryMovementService::record()/transfer() (spec §5). Offenders:\n"
            .implode("\n", array_map(
                static fn (array $v): string => "  {$v['file']}:{$v['line']}  [{$v['rule']}]",
                $violations,
            )),
        );
    }

    /**
     * Phase A: any balance-write shape inside app/Modules/Treasury (minus the port).
     *
     * @return list<array{file: string, line: int, rule: string}>
     */
    private function scanTreasuryModule(): array
    {
        $base = base_path('app/Modules/Treasury');
        if (! is_dir($base)) {
            return [];
        }

        $violations = [];

        foreach ($this->phpFiles($base) as $path) {
            if (basename($path) === self::ALLOWED_BASENAME) {
                continue;
            }

            $content = (string) file_get_contents($path);
            $relative = $this->relativePath($path);

            // 1. `->balance = …` assignment (not `==`/`>=`, not `->balance_after`,
            //    not on a comment line).
            foreach ($this->matchLines($content, '/->balance\b\s*=(?!=)/') as $line) {
                if (! $this->isCommentLine($content, $line)) {
                    $violations[] = ['file' => $relative, 'line' => $line, 'rule' => '->balance ='];
                }
            }

            // 2. increment('balance') / decrement('balance').
            foreach ($this->matchLines($content, '/->(?:increment|decrement)\(\s*[\'"]balance[\'"]/') as $line) {
                $violations[] = ['file' => $relative, 'line' => $line, 'rule' => 'increment/decrement(balance)'];
            }

            // 3. update([…])/forceFill([…])/forceCreate([…]) carrying a `balance`
            //    KEY. The quoted-key match (`'balance' =>`) excludes response/read
            //    arrays and `last_reconciled_balance`. `[^\]]*` spans a multi-line
            //    array up to its first `]`.
            foreach ($this->matchLines($content, '/(?:->|::)(?:update|forceFill|forceCreate)\(\s*\[[^\]]*[\'"]balance[\'"]\s*=>/') as $line) {
                $violations[] = ['file' => $relative, 'line' => $line, 'rule' => 'update/forceFill/forceCreate([balance])'];
            }
        }

        return $violations;
    }

    /**
     * Phase B: raw `payment_repositories` balance writes anywhere under app/.
     *
     * @return list<array{file: string, line: int, rule: string}>
     */
    private function scanRawPaymentRepositoryWrites(): array
    {
        $base = base_path('app');
        if (! is_dir($base)) {
            return [];
        }

        $violations = [];

        foreach ($this->phpFiles($base) as $path) {
            if (basename($path) === self::ALLOWED_BASENAME) {
                continue;
            }

            $content = (string) file_get_contents($path);
            $relative = $this->relativePath($path);

            // Query-builder chain on payment_repositories that reaches a balance
            // update/increment before the statement terminator.
            $qbPattern = '/table\(\s*[\'"]payment_repositories[\'"]\s*\)'
                .'(?:(?!;).)*'
                .'->\s*(?:update\((?:(?!;).)*[\'"]balance[\'"]|increment\(\s*[\'"]balance[\'"]|decrement\(\s*[\'"]balance[\'"])/s';
            foreach ($this->matchLines($content, $qbPattern) as $line) {
                $violations[] = ['file' => $relative, 'line' => $line, 'rule' => 'raw payment_repositories balance write'];
            }

            // Raw SQL `UPDATE payment_repositories SET … balance`.
            foreach ($this->matchLines($content, '/update\s+payment_repositories\s+set(?:(?!;).)*balance/is') as $line) {
                $violations[] = ['file' => $relative, 'line' => $line, 'rule' => 'raw SQL UPDATE payment_repositories … balance'];
            }
        }

        return $violations;
    }

    /**
     * @return list<string> absolute paths of every .php file under $base
     */
    private function phpFiles(string $base): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        );

        $paths = [];
        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && $entry->getExtension() === 'php') {
                $paths[] = $entry->getPathname();
            }
        }

        return $paths;
    }

    /**
     * Return the 1-based line numbers where $pattern matches $content.
     *
     * @return list<int>
     */
    private function matchLines(string $content, string $pattern): array
    {
        if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        $lines = [];
        foreach ($matches[0] as $match) {
            $offset = (int) $match[1];
            $lines[] = substr_count($content, "\n", 0, $offset) + 1;
        }

        return $lines;
    }

    /**
     * True when the given 1-based line in $content is a comment (`//`, `*`, `/*`,
     * `#`) — the model-assignment scan documents replaced legacy writes in prose.
     */
    private function isCommentLine(string $content, int $line): bool
    {
        $rows = preg_split('/\R/', $content) ?: [];
        $row = ltrim($rows[$line - 1] ?? '');

        return $row === ''
            || str_starts_with($row, '//')
            || str_starts_with($row, '*')
            || str_starts_with($row, '/*')
            || str_starts_with($row, '#');
    }

    private function relativePath(string $absolute): string
    {
        $root = base_path().'/';

        return str_starts_with($absolute, $root) ? substr($absolute, strlen($root)) : $absolute;
    }
}
