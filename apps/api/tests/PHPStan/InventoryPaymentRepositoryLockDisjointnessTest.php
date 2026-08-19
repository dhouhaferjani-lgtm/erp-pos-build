<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\PHPStan\Rules\InventoryPaymentRepositoryLockDisjointness;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<InventoryPaymentRepositoryLockDisjointness> */
final class InventoryPaymentRepositoryLockDisjointnessTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new InventoryPaymentRepositoryLockDisjointness;
    }

    public function test_flags_one_class_that_combines_inventory_and_payment_repository_locks(): void
    {
        $this->analyse(
            [__DIR__.'/Fixtures/InventoryPaymentRepositoryLockOverlap.php'],
            [[
                'A class that takes inventory locks may not also reference payment_repositories (I-2).',
                7,
            ]],
        );
    }

    public function test_ignores_disjoint_classes_and_comment_only_decoys(): void
    {
        $this->analyse([__DIR__.'/Fixtures/DisjointInventoryPaymentRepositoryLocks.php'], []);
    }
}
