<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * AST visitor for Architecture Gate A. Walks a single Presentation-tier
 * file and records bare `exists:` rules and unscoped `Rule::exists(...)`
 * builder calls for guarded tables. Honors the strict
 * `#[CrossTenantRoute(reason: "...")]` controller-method attribute and the
 * `@cross-tenant-by-design` 4-field annotation (class-level and method-level).
 *
 * Requires the AST to have parent pointers attached (via
 * {@see ParentConnectingVisitor}) so chained
 * `->where('tenant_id', ...)` filters can be detected as ancestors of
 * the `Rule::exists()` static call.
 */
final class ExistsRuleVisitor extends NodeVisitorAbstract
{
    /** @var list<array{line: int, table: string, form: string}> */
    public array $violations = [];

    /** @var list<string> */
    private array $guardedTables;

    private bool $classCrossTenantSkip = false;

    private bool $methodCrossTenantSkip = false;

    /**
     * @param  list<string>  $guardedTables
     */
    public function __construct(array $guardedTables)
    {
        $this->guardedTables = $guardedTables;
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof Class_) {
            $this->classCrossTenantSkip = $this->nodeHasValidCrossTenantAnnotation($node);
        }
        if ($node instanceof ClassMethod) {
            $this->methodCrossTenantSkip = $this->methodHasValidCrossTenantRouteAttribute($node)
                || $this->nodeHasValidCrossTenantAnnotation($node);
        }

        if ($this->shouldSkip()) {
            return null;
        }

        if ($node instanceof String_) {
            $this->checkInlineString($node);
        }

        if ($node instanceof StaticCall) {
            $this->checkRuleExistsBuilder($node);
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof ClassMethod) {
            $this->methodCrossTenantSkip = false;
        }
        if ($node instanceof Class_) {
            $this->classCrossTenantSkip = false;
        }

        return null;
    }

    private function shouldSkip(): bool
    {
        return $this->classCrossTenantSkip || $this->methodCrossTenantSkip;
    }

    private function checkInlineString(String_ $node): void
    {
        $value = $node->value;
        if (! str_starts_with($value, 'exists:')) {
            return;
        }
        $rest = substr($value, strlen('exists:'));
        $parts = explode(',', $rest, 3);
        $table = $parts[0];
        if ($table === '' || ! in_array($table, $this->guardedTables, true)) {
            return;
        }
        $this->violations[] = [
            'line' => $node->getStartLine(),
            'table' => $table,
            'form' => 'inline_string',
        ];
    }

    private function checkRuleExistsBuilder(StaticCall $node): void
    {
        if (! $node->name instanceof Identifier || $node->name->toString() !== 'exists') {
            return;
        }
        if (! $node->class instanceof Name) {
            return;
        }
        if ($node->class->getLast() !== 'Rule') {
            return;
        }
        $firstArg = $node->args[0] ?? null;
        if (! $firstArg instanceof Node\Arg || ! $firstArg->value instanceof String_) {
            return;
        }
        $table = $firstArg->value->value;
        if (! in_array($table, $this->guardedTables, true)) {
            return;
        }

        if ($this->wrappingChainContainsTenantOrCompanyScope($node)) {
            return;
        }

        $this->violations[] = [
            'line' => $node->getStartLine(),
            'table' => $table,
            'form' => 'rule_exists_builder',
        ];
    }

    /**
     * Walks UP from the StaticCall through MethodCall ancestors set by
     * ParentConnectingVisitor and returns true iff any ancestor is a
     * MethodCall whose first arg is the literal 'tenant_id' or 'company_id'.
     */
    private function wrappingChainContainsTenantOrCompanyScope(StaticCall $staticCall): bool
    {
        $cursor = $staticCall->getAttribute('parent');
        while ($cursor instanceof Node) {
            if ($cursor instanceof MethodCall
                && $cursor->name instanceof Identifier
                && $cursor->name->toString() === 'where'
            ) {
                $first = $cursor->args[0]->value ?? null;
                if ($first instanceof String_
                    && in_array($first->value, ['tenant_id', 'company_id'], true)) {
                    return true;
                }
            }
            $cursor = $cursor->getAttribute('parent');
        }

        return false;
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
