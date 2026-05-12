<?php

declare(strict_types=1);

namespace App\Application\Sweep\Scanners;

use App\Application\Sweep\Visitors\ExistsRuleVisitor;
use FilesystemIterator;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Scanner emitting CallsiteRows for bare `exists:` validator rules in the
 * Presentation tier (master plan Section 5.1). Wraps the AST traversal that
 * Architecture Gate A uses ({@see ExistsRuleVisitor}); the scanner adds the
 * stable-key generation, cluster resolution, and CallsiteRow shaping the
 * inventory generator needs.
 *
 * The Gate A architecture test ({@see TenantScopedExistsRulesTest})
 * keeps using the same visitor to surface the violation count to CI without
 * loading the inventory plumbing — both consumers stay in sync because they
 * share one AST implementation.
 *
 * Symbol detection: the scanner walks up the AST from each violation node
 * to find the enclosing class/method and reports a fully-qualified symbol
 * like `Namespace\Cls::method`. Lines unbacked by a class/method (rare in
 * Presentation tier) report the bare class FQN.
 */
final class PhpPresentationExistsScanner implements Scanner
{
    /**
     * Default guarded tables — kept in sync with Gate A's GUARDED_TABLES
     * and the master plan Section 6 cluster catalogue. Tests may override.
     *
     * @var list<string>
     */
    private const DEFAULT_GUARDED_TABLES = [
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
        'journals', // Accounting cluster — added per Opus Treasury Finding 3
        'locations',
        'loyalty_members',
        'loyalty_programs',
        'loyalty_rewards',
        'modifier_groups',
        'modifiers',
        'partners',
        'payment_instruments', // Treasury cluster — added per Opus Treasury Findings 2 & 3
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
        'users', // Identity cluster — added per Opus Treasury Finding 3
        'voucher_ledger',
        'vouchers',
        'withholding_certificates',
        'work_orders',
    ];

    /** @var list<string> */
    private array $guardedTables;

    /**
     * @param  string  $scanRoot  Absolute path to start scanning from (e.g. base_path('app/Modules')).
     * @param  string  $repoRoot  Absolute path used to relativize file paths in CallsiteRow::$relativePath.
     * @param  list<string>|null  $guardedTables  Optional override (mostly for tests); defaults to DEFAULT_GUARDED_TABLES.
     */
    public function __construct(
        private readonly string $scanRoot,
        private readonly string $repoRoot,
        private readonly ClusterResolver $clusterResolver,
        ?array $guardedTables = null,
    ) {
        $this->guardedTables = $guardedTables ?? self::DEFAULT_GUARDED_TABLES;
    }

    public function name(): string
    {
        return 'php_presentation_exists';
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
            $visitor = new ExistsRuleVisitor($this->guardedTables);
            $traverser->addVisitor($visitor);
            $traverser->traverse($stmts);

            $classFqn = $this->resolveClassFqn($stmts);

            foreach ($visitor->violations as $violation) {
                $rows[] = $this->buildRow($path, $violation, $classFqn);
            }
        }

        return $rows;
    }

    /**
     * @param  array{line: int, table: string, form: string, enclosing_method: string, start_file_pos: int}  $violation
     */
    private function buildRow(string $absolutePath, array $violation, ?string $classFqn): CallsiteRow
    {
        $relativePath = $this->relativize($absolutePath);
        $clusterId = $this->clusterResolver->resolve($relativePath);
        $surface = 'api';
        $symbol = $this->buildSymbol($classFqn, $relativePath, $violation['enclosing_method']);
        $patternType = $violation['form'] === 'inline_string'
            ? 'bare_exists_validator'
            : 'rule_exists_builder_unscoped';

        // Per-statement fingerprint hashes (pattern_type, AST node form,
        // byte offset). Two violations on the same line at different file
        // positions still get distinct keys; same-position violations stay
        // stable across body refactors that don't move the violation node.
        $statementFingerprint = hash(
            'sha256',
            implode("\0", [
                $patternType,
                $violation['form'],
                (string) $violation['start_file_pos'],
            ]),
        );

        $stableKey = StableKey::fromScannerOutput([
            'surface' => $surface,
            'scanner' => $this->name(),
            'normalized_relative_path' => $relativePath,
            'symbol_fqn' => $symbol,
            'ast_node_kind' => $violation['form'],
            'model_or_table' => $violation['table'],
            'field_or_method' => $violation['enclosing_method'],
            'normalized_argument_name' => '',
            'statement_fingerprint' => $statementFingerprint,
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
            resource: $violation['table'],
            expectedScope: 'tenant_and_company',
            expectedFix: 'Replace bare exists with ScopedExists::tenantAndCompany or chain ->where(\'tenant_id\') / ->where(\'company_id\').',
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
     * Resolves a fully-qualified class name from the parsed AST. Walks the
     * top-level statements once, descending into a single Namespace_ node
     * if present. Returns null if the file has no class declaration.
     *
     * Laravel files conventionally have one namespace + one class. If a
     * file has more than one class the scanner picks the FIRST — which
     * matches the FormRequest convention this scanner targets.
     *
     * @param  array<int, Node>  $stmts
     */
    private function resolveClassFqn(array $stmts): ?string
    {
        $namespace = null;
        $stack = $stmts;
        while ($stack !== []) {
            $node = array_shift($stack);
            if ($node instanceof Namespace_) {
                $namespace = $node->name?->toString();
                $stack = array_merge($node->stmts, $stack);

                continue;
            }
            if ($node instanceof Class_) {
                $className = $node->name?->toString();
                if ($className === null) {
                    continue;
                }

                return $namespace !== null
                    ? $namespace.'\\'.$className
                    : $className;
            }
        }

        return null;
    }

    /**
     * Builds the canonical symbol identifier used in stable_key + the
     * inventory's `symbol` column. Includes class FQN when available so
     * a class rename (without file rename) is detected as a symbol move,
     * and includes the enclosing method so a method rename is visible.
     */
    private function buildSymbol(?string $classFqn, string $relativePath, string $enclosingMethod): string
    {
        $cls = $classFqn ?? basename($relativePath, '.php');

        return $cls.'::'.$enclosingMethod;
    }
}
