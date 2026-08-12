<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use App\Modules\Inventory\Application\Services\InventoryGlPostingBuffer;
use App\Modules\Inventory\Application\Services\InventoryGlPostingService;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/** @implements Rule<MethodCall> */
final class InventoryGlPostingViaBufferOnly implements Rule
{
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->name instanceof Node\Identifier
            && str_starts_with($node->name->toString(), 'postFor')) {
            return [];
        }

        if (! $node->name instanceof Node\Identifier
            || ! str_starts_with($node->name->toString(), 'postFor')) {
            return [];
        }

        if (! (new ObjectType(InventoryGlPostingService::class))->isSuperTypeOf($scope->getType($node->var))->yes()) {
            return [];
        }

        if ($scope->getClassReflection()?->getName() === InventoryGlPostingBuffer::class
            && $scope->getFunctionName() === 'flushIfOutermost') {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'InventoryGlPostingService::postFor* may only be called from '
                .InventoryGlPostingBuffer::class.'::flushIfOutermost(). Writers must enqueue.'
            )
                ->identifier('inventory.glPosting.directCall')
                ->build(),
        ];
    }
}
