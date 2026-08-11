<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/** @implements Rule<Class_> */
final class InventoryPaymentRepositoryLockDisjointness implements Rule
{
    public function getNodeType(): string
    {
        return Class_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $finder = new NodeFinder;
        $nodes = $finder->find($node->stmts, static fn (Node $candidate): bool => ! $candidate instanceof Class_);

        $referencesPaymentRepositories = $this->containsString($nodes, 'payment_repositories');
        $takesRowLock = $this->containsMethodCall($nodes, 'lockForUpdate');
        if (! $referencesPaymentRepositories || ! $takesRowLock) {
            return [];
        }

        $referencesInventoryLock = $this->containsString($nodes, 'stock_levels')
            || $this->containsString($nodes, 'inventory_countings')
            || $this->containsName($nodes, 'ProductCostLock');
        if (! $referencesInventoryLock) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'A class that takes inventory locks may not also reference payment_repositories (I-2).'
            )
                ->identifier('inventory.paymentRepository.lockClassOverlap')
                ->build(),
        ];
    }

    /** @param list<Node> $nodes */
    private function containsString(array $nodes, string $value): bool
    {
        foreach ($nodes as $candidate) {
            if ($candidate instanceof String_ && $candidate->value === $value) {
                return true;
            }
        }

        return false;
    }

    /** @param list<Node> $nodes */
    private function containsMethodCall(array $nodes, string $method): bool
    {
        foreach ($nodes as $candidate) {
            if ($candidate instanceof MethodCall
                && $candidate->name instanceof Node\Identifier
                && $candidate->name->name === $method) {
                return true;
            }
        }

        return false;
    }

    /** @param list<Node> $nodes */
    private function containsName(array $nodes, string $shortName): bool
    {
        foreach ($nodes as $candidate) {
            if ($candidate instanceof Name && $candidate->getLast() === $shortName) {
                return true;
            }
        }

        return false;
    }
}
