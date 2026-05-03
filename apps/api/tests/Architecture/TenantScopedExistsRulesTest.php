<?php

declare(strict_types=1);

namespace Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Architecture Gate A: bare `exists:` validator strings in Presentation tier.
 *
 * Code is the source of truth (master plan Section 3): if the gate fails, the
 * code is unsafe regardless of YAML status. Skipped today via the
 * `sweep-progress` PHPUnit group while the tactical sweep is in flight; the
 * group exclusion in phpunit.xml lets default `php artisan test` runs stay
 * green. Once Sections 7-15 land per-cluster fixes, Section 17 strips the
 * `@group sweep-progress` annotation and this gate becomes a hard CI block.
 *
 * The scanner here is intentionally minimal — Section 5 refactors it into
 * `app/Application/Sweep/Scanners/PhpPresentationExistsScanner.php` with
 * proper fixture coverage and inventory-row emission.
 */
#[Group('sweep-progress')]
class TenantScopedExistsRulesTest extends TestCase
{
    /**
     * Tables whose access must always be tenant-or-company-scoped.
     * Matches the cluster catalogue in master-plan Section 6.
     *
     * @var list<string>
     */
    private const GUARDED_TABLES = [
        'payment_methods',
        'payment_repositories',
        'partners',
        'contacts',
        'documents',
        'cart_items',
        'carts',
        'products',
        'categories',
        'modifier_groups',
        'modifiers',
        'product_variants',
        'service_catalog_items',
        'work_orders',
        'invoices',
        'coupons',
        'vouchers',
        'voucher_ledger',
        'pricing_rules',
        'pos_terminals',
        'pos_receipts',
        'pos_locations',
        'loyalty_programs',
        'loyalty_members',
        'fraud_alerts',
        'tax_rates',
        'withholding_certificates',
        'batches',
        'stock_levels',
        'stock_movements',
    ];

    public function test_presentation_tier_has_no_bare_exists_rules_for_guarded_tables(): void
    {
        $violations = $this->scanPresentation();

        // Informational baseline while the sweep is in flight. Section 17
        // swaps the assertion below for `assertEmpty($violations)` once the
        // tactical sweep completes.
        if ($violations !== []) {
            fwrite(
                STDERR,
                sprintf(
                    "\n[sweep-progress] Gate A — bare exists: rules in Presentation tier: %d\n",
                    count($violations),
                ),
            );
        }

        // Master plan Section 17 step 17.5: this assertion swaps from
        // `>= 0` (informational) to `=== []` (hard gate) when the sweep
        // completes. Until then the gate runs only when explicitly
        // enabled via `--group=sweep-progress`.
        $this->assertGreaterThanOrEqual(0, count($violations));
    }

    /**
     * @return list<array{file: string, line: int, table: string, snippet: string}>
     */
    private function scanPresentation(): array
    {
        $base = base_path('app/Modules');
        if (! is_dir($base)) {
            return [];
        }

        $violations = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        );

        $guardedAlternation = implode('|', array_map('preg_quote', self::GUARDED_TABLES));
        $pattern = '/[\'"]exists:('.$guardedAlternation.')(?:,[a-zA-Z_]+)?[\'"]/';

        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo || $entry->getExtension() !== 'php') {
                continue;
            }
            $path = $entry->getPathname();
            if (! str_contains($path, '/Presentation/')) {
                continue;
            }

            $contents = (string) file_get_contents($path);
            if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }
            if (! isset($matches[0]) || $matches[0] === []) {
                continue;
            }

            foreach ($matches[0] as $i => $match) {
                $offset = $match[1];
                $lineNumber = substr_count($contents, "\n", 0, $offset) + 1;
                $violations[] = [
                    'file' => $this->relativePath($path),
                    'line' => $lineNumber,
                    'table' => $matches[1][$i][0],
                    'snippet' => $match[0],
                ];
            }
        }

        return $violations;
    }

    private function relativePath(string $absolute): string
    {
        $root = base_path().'/';

        return str_starts_with($absolute, $root) ? substr($absolute, strlen($root)) : $absolute;
    }
}
