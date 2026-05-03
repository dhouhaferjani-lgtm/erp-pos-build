<?php

declare(strict_types=1);

namespace Tests\Architecture;

use FilesystemIterator;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
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
        'PaymentMethod',
        'PaymentRepository',
        'Partner',
        'Contact',
        'Document',
        'Cart',
        'CartItem',
        'Product',
        'Category',
        'ModifierGroup',
        'Modifier',
        'ProductVariant',
        'ServiceCatalogItem',
        'WorkOrder',
        'Coupon',
        'Voucher',
        'VoucherLedger',
        'PricingRule',
        'PosTerminal',
        'PosReceipt',
        'PosLocation',
        'LoyaltyProgram',
        'LoyaltyMember',
        'FraudAlert',
        'TaxRate',
        'WithholdingCertificate',
        'Batch',
        'StockLevel',
        'StockMovement',
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

/**
 * @internal
 */
final class FindCallVisitor extends NodeVisitorAbstract
{
    /** @var list<array{line: int, model: string, method: string}> */
    public array $violations = [];

    /** @var list<string> */
    private array $guardedShortNames;

    /**
     * Variables in the current method scope known to carry a tenant/company
     * scope filter (i.e. `$query = Model::query()->where('tenant_id', ...)`).
     *
     * @var array<string, true>
     */
    private array $scopedVariables = [];

    /**
     * @param  list<string>  $guardedModels
     */
    public function __construct(array $guardedModels)
    {
        $this->guardedShortNames = $guardedModels;
    }

    public function enterNode(Node $node): null
    {
        // Track `$x = $foo->where('tenant_id'|'company_id', ...)` so chained
        // `$x->find($id)` calls inherit the scope.
        if ($node instanceof Assign && $node->var instanceof Variable && is_string($node->var->name)) {
            if ($this->expressionContainsTenantScope($node->expr)) {
                $this->scopedVariables[$node->var->name] = true;
            }
        }

        if ($node instanceof StaticCall) {
            $this->checkStaticCall($node);
        }

        if ($node instanceof MethodCall) {
            $this->checkMethodCall($node);
        }

        return null;
    }

    private function checkStaticCall(StaticCall $node): void
    {
        if (! $node->name instanceof Identifier) {
            return;
        }
        $method = $node->name->toString();
        if ($method !== 'find' && $method !== 'findOrFail') {
            return;
        }
        if (! $node->class instanceof Name) {
            return;
        }
        $shortName = $node->class->getLast();
        if (! in_array($shortName, $this->guardedShortNames, true)) {
            return;
        }
        $this->violations[] = [
            'line' => $node->getStartLine(),
            'model' => $shortName,
            'method' => $method,
        ];
    }

    private function checkMethodCall(MethodCall $node): void
    {
        if (! $node->name instanceof Identifier) {
            return;
        }
        $method = $node->name->toString();
        if ($method !== 'find' && $method !== 'findOrFail') {
            return;
        }

        // `$scopedVar->find(...)` is OK if the variable was assigned a
        // scoped query earlier in the method.
        if ($node->var instanceof Variable
            && is_string($node->var->name)
            && isset($this->scopedVariables[$node->var->name])
        ) {
            return;
        }

        // `Model::query()->find(...)` chained directly: walk back through
        // ->where() chain to detect tenant/company scoping.
        if ($this->chainContainsTenantScope($node->var)) {
            return;
        }

        // Try to recover the originating model class for reporting.
        $model = $this->modelShortNameFromChain($node->var);
        if ($model === null || ! in_array($model, $this->guardedShortNames, true)) {
            return;
        }
        $this->violations[] = [
            'line' => $node->getStartLine(),
            'model' => $model,
            'method' => $method,
        ];
    }

    private function expressionContainsTenantScope(Node $expr): bool
    {
        return $this->chainContainsTenantScope($expr);
    }

    private function chainContainsTenantScope(Node $expr): bool
    {
        $cursor = $expr;
        while ($cursor instanceof MethodCall) {
            if ($cursor->name instanceof Identifier && $cursor->name->toString() === 'where') {
                $first = $cursor->args[0]->value ?? null;
                if ($first instanceof String_
                    && in_array($first->value, ['tenant_id', 'company_id'], true)) {
                    return true;
                }
            }
            $cursor = $cursor->var;
        }

        return false;
    }

    private function modelShortNameFromChain(Node $expr): ?string
    {
        $cursor = $expr;
        while ($cursor instanceof MethodCall) {
            $cursor = $cursor->var;
        }
        if ($cursor instanceof StaticCall && $cursor->class instanceof Name) {
            return $cursor->class->getLast();
        }

        return null;
    }
}
