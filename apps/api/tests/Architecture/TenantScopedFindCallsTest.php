<?php

declare(strict_types=1);

namespace Tests\Architecture;

use FilesystemIterator;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Architecture Gate B: tenant-scoped Eloquent lookups in Application tier.
 *
 * Flags `Model::find()` / `Model::findOrFail()` / `Model::query()->find()`
 * when the model class is one of the guarded tenant-scoped resources AND
 * the call site lacks an obvious tenant/company `where()` filter in the
 * same query chain.
 *
 * Same source-of-truth contract as Gate A: code is truth, YAML is metadata.
 * Marked `@group sweep-progress` so the gate is informational while the
 * tactical sweep lands per-cluster fixes; Section 17 strips the group and
 * the gate becomes a hard CI block.
 *
 * Section 5 will move this AST traversal into a proper Scanner class
 * (`app/Application/Sweep/Scanners/PhpAstFindScanner.php`) that emits
 * inventory rows. This commit gives Sections 7+ a place to verify
 * per-cluster fixes against.
 */
#[Group('sweep-progress')]
class TenantScopedFindCallsTest extends TestCase
{
    /**
     * Eloquent model short-names whose lookups must always be
     * tenant-or-company-scoped. Matches the cluster catalogue in
     * master plan Section 6 — keeps the surface narrow so non-scoped
     * helper models (Tenant, SuperAdmin, etc.) stay out of scope.
     *
     * @var list<string>
     */
    private const GUARDED_MODELS = [
        'Account',
        'Batch',
        'Cart',
        'CartItem',
        'Category',
        'Contact',
        'Coupon',
        'Document',
        'ExpenseCategory',
        'FraudAlert',
        'Invoice',
        'Location',
        'LoyaltyMember',
        'LoyaltyProgram',
        'LoyaltyReward',
        'Modifier',
        'ModifierGroup',
        'Partner',
        'Payment',
        'PaymentMethod',
        'PaymentRepository',
        'PosLocation',
        'PosReceipt',
        'PosTerminal',
        'PricingRule',
        'Product',
        'ProductVariant',
        'Service',
        'ServiceCatalogItem',
        'StockLevel',
        'StockMovement',
        'TaxConfiguration',
        'TaxRate',
        'Voucher',
        'VoucherLedger',
        'WithholdingCertificate',
        'WorkOrder',
    ];

    public function test_application_tier_find_calls_are_tenant_scoped(): void
    {
        $violations = $this->scanApplication();

        if ($violations !== []) {
            fwrite(
                STDERR,
                sprintf(
                    "\n[sweep-progress] Gate B — unscoped find()/findOrFail() on guarded models: %d\n",
                    count($violations),
                ),
            );
        }

        // Master plan Section 17 step 17.1 swaps the assertion below from
        // `>= 0` (informational) to `=== []` (hard gate) once Sections 7-15
        // land per-cluster fixes.
        $this->assertGreaterThanOrEqual(0, count($violations));
    }

    /**
     * @return list<array{file: string, line: int, model: string, method: string}>
     */
    private function scanApplication(): array
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
            if (! $this->isApplicationTierFile($path)) {
                continue;
            }

            $code = (string) file_get_contents($path);
            $stmts = $parser->parse($code);
            if ($stmts === null) {
                continue;
            }

            $visitor = new FindCallVisitor(self::GUARDED_MODELS);
            $traverser = new NodeTraverser;
            $traverser->addVisitor($visitor);
            $traverser->traverse($stmts);

            foreach ($visitor->violations as $v) {
                $violations[] = [
                    'file' => $this->relativePath($path),
                    'line' => $v['line'],
                    'model' => $v['model'],
                    'method' => $v['method'],
                ];
            }
        }

        return $violations;
    }

    private function isApplicationTierFile(string $path): bool
    {
        return str_contains($path, '/Application/')
            || str_contains($path, '/Domain/Services/')
            || str_contains($path, '/Presentation/Controllers/');
    }

    private function relativePath(string $absolute): string
    {
        $root = base_path().'/';

        return str_starts_with($absolute, $root) ? substr($absolute, strlen($root)) : $absolute;
    }
}
