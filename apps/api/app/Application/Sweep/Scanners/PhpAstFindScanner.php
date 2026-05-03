<?php

declare(strict_types=1);

namespace App\Application\Sweep\Scanners;

use App\Application\Sweep\Visitors\FindCallVisitor;
use FilesystemIterator;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Scanner emitting CallsiteRows for unscoped `Model::find()` /
 * `Model::findOrFail()` (and chained variants) on guarded Eloquent models
 * (master plan Section 5.2). Wraps the AST traversal that Architecture
 * Gate B uses ({@see FindCallVisitor}); the scanner adds the stable-key
 * generation, cluster resolution, and CallsiteRow shaping that the
 * inventory generator needs.
 */
final class PhpAstFindScanner implements Scanner
{
    /**
     * Default guarded models — kept in sync with Gate B's GUARDED_MODELS.
     *
     * @var list<string>
     */
    private const DEFAULT_GUARDED_MODELS = [
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

    /** @var list<string> */
    private array $guardedModels;

    /**
     * @param  list<string>|null  $guardedModels
     */
    public function __construct(
        private readonly string $scanRoot,
        private readonly string $repoRoot,
        private readonly ClusterResolver $clusterResolver,
        ?array $guardedModels = null,
    ) {
        $this->guardedModels = $guardedModels ?? self::DEFAULT_GUARDED_MODELS;
    }

    public function name(): string
    {
        return 'php_ast_find';
    }

    /**
     * @return list<CallsiteRow>
     */
    public function scan(): array
    {
        if (! is_dir($this->scanRoot)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->scanRoot, FilesystemIterator::SKIP_DOTS),
        );
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $rows = [];

        foreach ($iterator as $entry) {
            if (! $entry instanceof SplFileInfo || $entry->getExtension() !== 'php') {
                continue;
            }
            $path = $entry->getPathname();
            if (! $this->isApplicationTierPath($path)) {
                continue;
            }

            $code = (string) file_get_contents($path);
            $stmts = $parser->parse($code);
            if ($stmts === null) {
                continue;
            }

            $visitor = new FindCallVisitor($this->guardedModels);
            $traverser = new NodeTraverser;
            $traverser->addVisitor($visitor);
            $traverser->traverse($stmts);

            foreach ($visitor->violations as $violation) {
                $rows[] = $this->buildRow($path, $violation);
            }
        }

        return $rows;
    }

    private function isApplicationTierPath(string $absolutePath): bool
    {
        return str_contains($absolutePath, '/Application/')
            || str_contains($absolutePath, '/Domain/Services/')
            || str_contains($absolutePath, '/Presentation/Controllers/');
    }

    /**
     * @param  array{line: int, model: string, method: string}  $violation
     */
    private function buildRow(string $absolutePath, array $violation): CallsiteRow
    {
        $relativePath = $this->relativize($absolutePath);
        $clusterId = $this->clusterResolver->resolve($relativePath);
        $surface = 'api';
        $symbol = $this->fileSymbol($relativePath, $violation['line'], $violation['method']);
        $patternType = 'unscoped_eloquent_'.$violation['method'];

        $stableKey = StableKey::fromScannerOutput([
            'surface' => $surface,
            'scanner' => $this->name(),
            'normalized_relative_path' => $relativePath,
            'symbol_fqn' => $symbol,
            'ast_node_kind' => $violation['method'],
            'model_or_table' => $violation['model'],
            'field_or_method' => $violation['method'],
            'normalized_argument_name' => '',
            'statement_fingerprint' => $patternType,
        ]);

        return new CallsiteRow(
            stableKey: $stableKey,
            surface: $surface,
            clusterId: $clusterId,
            scanner: $this->name(),
            relativePath: $relativePath,
            line: $violation['line'],
            symbol: $symbol,
            patternType: $patternType,
            resource: $violation['model'],
            expectedScope: 'tenant_and_company',
            expectedFix: 'Replace bare '.$violation['method'].'() with a tenant-scoped chain (Model::query()->where(\'tenant_id\', ...)->find($id)) or a model scope method (forCompany/forTenant).',
            severity: 'high',
            fiscalPath: false,
            crossModule: false,
        );
    }

    private function relativize(string $absolutePath): string
    {
        $root = rtrim($this->repoRoot, '/').'/';
        if (str_starts_with($absolutePath, $root)) {
            return substr($absolutePath, strlen($root));
        }

        return $absolutePath;
    }

    /**
     * Best-effort symbol resolver — Gate B doesn't track the enclosing
     * class/method per-violation either; we synthesize a stable id from
     * the file basename + the line number so the stable_key remains
     * unique per-violation even when two violations live in the same file.
     */
    private function fileSymbol(string $relativePath, int $line, string $method): string
    {
        $basename = basename($relativePath, '.php');

        return $relativePath.':'.$basename.'::'.$method.':line-'.$line;
    }
}
