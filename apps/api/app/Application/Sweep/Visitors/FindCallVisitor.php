<?php

declare(strict_types=1);

namespace App\Application\Sweep\Visitors;

use PhpParser\Node;
use PhpParser\Node\Attribute;
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
 *   - Honors the `#[CrossTenantRoute(reason: "...")]` controller-method
 *     attribute and the same strict @cross-tenant-by-design 4-field
 *     annotation used by Gate A (class-level + method-level).
 */
final class FindCallVisitor extends NodeVisitorAbstract
{
    /**
     * Sentinel symbol used when a violation occurs outside any function-like
     * scope (rare in Application tier, but handled defensively).
     */
    public const FILE_SCOPE_SYMBOL = '<file-scope>';

    /** @var list<array{line: int, model: string, method: string, enclosing_method: string, start_file_pos: int}> */
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
     * Stack of enclosing function-like names; the top is the active scope.
     * Pushed/popped in lock-step with $scopedVariablesStack so violations
     * carry the correct enclosing-method symbol.
     *
     * @var list<string>
     */
    private array $enclosingMethodStack = [];

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
            $this->classCrossTenantSkip = $this->nodeHasValidCrossTenantAnnotation($node);
        }

        if ($this->isFunctionLikeBoundary($node)) {
            $this->scopedVariablesStack[] = [];
            $methodSkip = $node instanceof ClassMethod
                ? $this->methodHasValidCrossTenantRouteAttribute($node)
                    || $this->nodeHasValidCrossTenantAnnotation($node)
                : false;
            $this->methodCrossTenantSkipStack[] = $methodSkip;
            $this->enclosingMethodStack[] = $this->nameForFunctionLike($node);
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
            array_pop($this->enclosingMethodStack);
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

    private function nameForFunctionLike(Node $node): string
    {
        if ($node instanceof ClassMethod) {
            return $node->name->toString();
        }
        if ($node instanceof Function_) {
            return $node->name->toString();
        }

        // Closure / ArrowFunction.
        return '<closure@'.$node->getStartFilePos().'>';
    }

    private function currentEnclosingMethod(): string
    {
        $top = end($this->enclosingMethodStack);

        return is_string($top) ? $top : self::FILE_SCOPE_SYMBOL;
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
            'enclosing_method' => $this->currentEnclosingMethod(),
            'start_file_pos' => $node->getStartFilePos(),
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
            'enclosing_method' => $this->currentEnclosingMethod(),
            'start_file_pos' => $node->getStartFilePos(),
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

    private function methodHasValidCrossTenantRouteAttribute(ClassMethod $method): bool
    {
        foreach ($method->getAttrGroups() as $group) {
            foreach ($group->attrs as $attribute) {
                if (! $this->isCrossTenantRouteAttribute($attribute)) {
                    continue;
                }
                if ($this->attributeHasNonBlankReason($attribute)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isCrossTenantRouteAttribute(Attribute $attribute): bool
    {
        return $attribute->name->getLast() === 'CrossTenantRoute';
    }

    private function attributeHasNonBlankReason(Attribute $attribute): bool
    {
        foreach ($attribute->args as $index => $arg) {
            $isReasonArg = $index === 0
                || ($arg->name instanceof Identifier && $arg->name->toString() === 'reason');
            if (! $isReasonArg || ! $arg->value instanceof String_) {
                continue;
            }
            if (trim($arg->value->value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function nodeHasValidCrossTenantAnnotation(Node $node): bool
    {
        $comments = $node->getComments();
        if ($comments === []) {
            return false;
        }
        $text = implode("\n", array_map(static fn ($comment): string => $comment->getText(), $comments));
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
