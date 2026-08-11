<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
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

        $referencesPaymentRepositories = $this->containsTableReference($nodes, 'payment_repositories');
        $takesRowLock = $this->containsMethodCall($nodes, 'lockForUpdate');
        if (! $referencesPaymentRepositories || ! $takesRowLock) {
            return [];
        }

        $referencesInventoryLock = $this->containsTableReference($nodes, 'stock_levels')
            || $this->containsTableReference($nodes, 'inventory_countings')
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

    /** @param array<Node> $nodes */
    private function containsTableReference(array $nodes, string $table): bool
    {
        foreach ($nodes as $candidate) {
            if (! $candidate instanceof MethodCall && ! $candidate instanceof StaticCall) {
                continue;
            }
            if (! $candidate->name instanceof Node\Identifier
                || ! in_array($candidate->name->name, ['table', 'from', 'join', 'leftJoin', 'rightJoin'], true)) {
                continue;
            }

            $argument = $candidate->args[0]->value ?? null;
            if ($argument instanceof String_ && $argument->value === $table) {
                return true;
            }
        }

        return false;
    }

    /** @param array<Node> $nodes */
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

    /** @param array<Node> $nodes */
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
