<?php

declare(strict_types=1);

namespace Tests\Architecture;

use FilesystemIterator;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Architecture Gate A: bare `exists:` validator rules in the Presentation tier.
 *
 * Code is the source of truth (master plan Section 3): if the gate flags a
 * call site, the validator can satisfy a foreign-key check with a row from a
 * different tenant. Two surface forms are scanned:
 *
 *   1. Inline string rule:  'exists:payment_methods,id'
 *   2. Builder rule:        Rule::exists('payment_methods', 'id')
 *
 * The builder form is flagged only when the wrapping `->where(...)` chain
 * lacks a `where('tenant_id', ...)` or `where('company_id', ...)` filter.
 * Already-scoped builder rules pass.
 *
 * A class or method whose docblock carries the strict
 * `@cross-tenant-by-design` 4-field annotation (Reason / Audit-id /
 * Approved-by / Expires) is skipped. Expired annotations are NOT skipped —
 * they force periodic re-justification per master plan Section 9.
 *
 * Marked `@group sweep-progress`; default-excluded via phpunit.xml.
 * Section 17 strips the group annotation and the assertion swaps from
 * `>= 0` (informational) to `=== []` (hard gate).
 */
#[Group('sweep-progress')]
class TenantScopedExistsRulesTest extends TestCase
{
    /**
     * Tables whose validation must be tenant-or-company-scoped.
     * Drawn from the cluster catalogue in master plan Section 6, plus the
     * additions Codex 2026-05-03 review identified as missing.
     *
     * @var list<string>
     */
    private const GUARDED_TABLES = [
        'accounts',
        'batches',
        'cart_items',
        'carts',
        'categories',
        'contacts',
        'coupons',
        'documents',
        'expense_categories',
        'fraud_alerts',
        'invoices',
        'locations',
        'loyalty_members',
        'loyalty_programs',
        'loyalty_rewards',
        'modifier_groups',
        'modifiers',
        'partners',
        'payment_methods',
        'payment_repositories',
        'payments',
        'pos_locations',
        'pos_receipts',
        'pos_terminals',
        'pricing_rules',
        'product_variants',
        'products',
        'service_catalog_items',
        'services',
        'stock_levels',
        'stock_movements',
        'tax_configurations',
        'tax_rates',
        'voucher_ledger',
        'vouchers',
        'withholding_certificates',
        'work_orders',
    ];

    public function test_presentation_tier_has_no_unscoped_exists_rules_for_guarded_tables(): void
    {
        $violations = $this->scanPresentation();

        if ($violations !== []) {
            fwrite(
                STDERR,
                sprintf(
                    "\n[sweep-progress] Gate A — unscoped exists rules in Presentation tier: %d\n",
                    count($violations),
                ),
            );
        }

        // Master plan Section 17 step 17.1 swaps the assertion below from
        // `>= 0` (informational) to `=== []` (hard gate).
        $this->assertGreaterThanOrEqual(0, count($violations));
    }

    /**
     * @return list<array{file: string, line: int, table: string, form: string}>
     */
    private function scanPresentation(): array
    {
        $base = base_path('app/Modules');
        if (! is_dir($base)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        );

        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $violations = [];

        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo || $entry->getExtension() !== 'php') {
                continue;
            }
            $path = $entry->getPathname();
            if (! str_contains($path, '/Presentation/')) {
                continue;
            }

            $code = (string) file_get_contents($path);
            $stmts = $parser->parse($code);
            if ($stmts === null) {
                continue;
            }

            $traverser = new NodeTraverser;
            $traverser->addVisitor(new ParentConnectingVisitor);
            $visitor = new ExistsRuleVisitor(self::GUARDED_TABLES);
            $traverser->addVisitor($visitor);
            $traverser->traverse($stmts);

            foreach ($visitor->violations as $v) {
                $violations[] = [
                    'file' => $this->relativePath($path),
                    'line' => $v['line'],
                    'table' => $v['table'],
                    'form' => $v['form'],
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
