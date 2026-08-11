<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
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
        $source = file_get_contents($scope->getFile());
        if ($source === false
            || ! str_contains($source, 'payment_repositories')
            || ! str_contains($source, 'lockForUpdate')) {
            return [];
        }

        if (! preg_match('/(?:stock_levels|inventory_countings|ProductCostLock)/', $source)) {
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
}
