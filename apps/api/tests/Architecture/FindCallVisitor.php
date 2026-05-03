<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeVisitorAbstract;

/**
 * AST visitor for Architecture Gate B — unscoped Eloquent find()/findOrFail()
 * calls on guarded models.
 *
 * Codex 2026-05-03 review fixes vs the prior version:
 *   - $scopedVariables is now stacked per method/function/closure scope so a
 *     scoped local in one method never bleeds into another method's lookups.
 *   - Recognizes local Eloquent scope methods (forCompany, forTenant, …)
 *     so Document::forCompany($id)->findOrFail($id) is correctly NOT flagged.
 *   - Honors the same strict @cross-tenant-by-design 4-field docblock skip
 *     used by Gate A (class-level + method-level).
 */
final class FindCallVisitor extends NodeVisitorAbstract
{
    /** @var list<array{line: int, model: string, method: string}> */
    public array $violations = [];

    /** @var list<string> */
    private array $guardedShortNames;

    /**
     * Approved local-scope method names. A chain that begins with one of
     * these (e.g. `Model::forCompany($id)`) is considered tenant-scoped.
     *
     * @var list<string>
     */
    private const SCOPE_METHODS = [
        'forCompany',
        'forTenant',
        'forCurrentCompany',
        'forCurrentTenant',
        'inCompany',
        'inTenant',
    ];

    /**
     * Variables in the current method scope known to carry a tenant/company
     * filter. Stack of per-method maps; the top is the active scope.
     *
     * @var list<array<string, true>>
     */
    private array $scopedVariablesStack = [[]];

    private bool $classCrossTenantSkip = false;

    /** @var list<bool> */
    private array $methodCrossTenantSkipStack = [false];

    /**
     * @param  list<string>  $guardedModels
     */
    public function __construct(array $guardedModels)
    {
        $this->guardedShortNames = $guardedModels;
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Class_) {
            $this->classCrossTenantSkip = $this->docblockHasValidCrossTenantAnnotation($node->getDocComment());
        }

        if ($this->isFunctionLikeBoundary($node)) {
            $this->scopedVariablesStack[] = [];
            $methodSkip = $node instanceof ClassMethod
                ? $this->docblockHasValidCrossTenantAnnotation($node->getDocComment())
                : false;
            $this->methodCrossTenantSkipStack[] = $methodSkip;
        }

        if ($this->shouldSkip()) {
            return null;
        }

        if ($node instanceof Assign && $node->var instanceof Variable && is_string($node->var->name)) {
            if ($this->expressionIsScoped($node->expr)) {
                $top = array_pop($this->scopedVariablesStack);
                $top ??= [];
                $top[$node->var->name] = true;
                $this->scopedVariablesStack[] = $top;
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

    public function leaveNode(Node $node): null
    {
        if ($this->isFunctionLikeBoundary($node)) {
            array_pop($this->scopedVariablesStack);
            array_pop($this->methodCrossTenantSkipStack);
            if ($this->scopedVariablesStack === []) {
                $this->scopedVariablesStack = [[]];
            }
            if ($this->methodCrossTenantSkipStack === []) {
                $this->methodCrossTenantSkipStack = [false];
            }
        }

        if ($node instanceof Class_) {
            $this->classCrossTenantSkip = false;
        }

        return null;
    }

    private function isFunctionLikeBoundary(Node $node): bool
    {
        return $node instanceof ClassMethod
            || $node instanceof Function_
            || $node instanceof Closure
            || $node instanceof ArrowFunction;
    }

    private function shouldSkip(): bool
    {
        if ($this->classCrossTenantSkip) {
            return true;
        }
        $top = end($this->methodCrossTenantSkipStack);

        return $top === true;
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

        if ($node->var instanceof Variable
            && is_string($node->var->name)
            && $this->variableIsScoped($node->var->name)
        ) {
            return;
        }

        if ($this->chainIsScoped($node->var)) {
            return;
        }

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

    private function expressionIsScoped(Node $expr): bool
    {
        return $this->chainIsScoped($expr);
    }

    private function chainIsScoped(Node $expr): bool
    {
        $cursor = $expr;
        while ($cursor instanceof MethodCall) {
            if ($cursor->name instanceof Identifier) {
                $name = $cursor->name->toString();
                if ($name === 'where') {
                    $first = $cursor->args[0]->value ?? null;
                    if ($first instanceof String_
                        && in_array($first->value, ['tenant_id', 'company_id'], true)) {
                        return true;
                    }
                }
                if (in_array($name, self::SCOPE_METHODS, true)) {
                    return true;
                }
            }
            $cursor = $cursor->var;
        }

        if ($cursor instanceof StaticCall && $cursor->name instanceof Identifier) {
            $name = $cursor->name->toString();
            if (in_array($name, self::SCOPE_METHODS, true)) {
                return true;
            }
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

    private function variableIsScoped(string $name): bool
    {
        $top = end($this->scopedVariablesStack);
        if (! is_array($top)) {
            return false;
        }

        return isset($top[$name]);
    }

    private function docblockHasValidCrossTenantAnnotation(?Doc $docComment): bool
    {
        if ($docComment === null) {
            return false;
        }
        $text = $docComment->getText();
        if (! str_contains($text, '@cross-tenant-by-design')) {
            return false;
        }
        if (preg_match('/Reason:\s*\S/', $text) !== 1) {
            return false;
        }
        if (preg_match('/Audit-id:\s*\S/', $text) !== 1) {
            return false;
        }
        if (preg_match('/Approved-by:\s*\S/', $text) !== 1) {
            return false;
        }
        if (preg_match('/Expires:\s*(\S+)/', $text, $match) !== 1) {
            return false;
        }
        $expires = trim($match[1]);
        if ($expires === 'never') {
            return true;
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $expires);
        if ($date === false) {
            return false;
        }
        $today = new \DateTimeImmutable('today');

        return $date >= $today;
    }
}
