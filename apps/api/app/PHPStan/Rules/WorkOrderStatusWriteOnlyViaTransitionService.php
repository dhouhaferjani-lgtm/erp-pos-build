<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use App\Modules\Workshop\WorkOrder\Application\Services\WorkOrderTransitionService;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\PropertyFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\VerbosityLevel;

/**
 * Enforces that `WorkOrder::$status` is ONLY written from inside
 * {@see WorkOrderTransitionService}.
 *
 * The WorkOrder aggregate is an event-sourced, audit-critical state machine.
 * Scattered `$wo->status = ...` writes bypass the transition history, skip
 * side-effect orchestration (document generation, stock reservation), and
 * violate AutoERP Rule #8 (events immutable forever) — so any such write is
 * a CI-blocking error.
 *
 * The rule narrows the target type via exact FQCN equality
 * (`App\Modules\Workshop\WorkOrder\Domain\WorkOrder`) so siblings like
 * `WorkOrderLine`, `WorkOrderAssignment`, `WorkOrderStatusTransition` never
 * match even though they share the `WorkOrder` prefix.
 *
 * @implements Rule<Assign>
 */
final class WorkOrderStatusWriteOnlyViaTransitionService implements Rule
{
    private const ALLOWED_CLASS = 'App\\Modules\\Workshop\\WorkOrder\\Application\\Services\\WorkOrderTransitionService';

    private const AGGREGATE_FQCN = 'App\\Modules\\Workshop\\WorkOrder\\Domain\\WorkOrder';

    public function getNodeType(): string
    {
        return Assign::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->var instanceof PropertyFetch) {
            return [];
        }

        if (! $node->var->name instanceof Node\Identifier) {
            return [];
        }

        if ($node->var->name->toString() !== 'status') {
            return [];
        }

        $varType = $scope->getType($node->var->var);
        $typeString = $varType->describe(VerbosityLevel::typeOnly());

        // Exact FQCN equality — substring match would false-positive on
        // WorkOrderLine, WorkOrderAssignment, WorkOrderStatusTransition.
        if ($typeString !== self::AGGREGATE_FQCN) {
            return [];
        }

        $className = $scope->getClassReflection()?->getName();
        if ($className === self::ALLOWED_CLASS) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                'WorkOrder::$status may only be written inside '.self::ALLOWED_CLASS.'.'
                .' Use that service to change status (enforces status-machine + events + audit row).'
            )
                ->identifier('workshop.workOrderStatus.directWrite')
                ->build(),
        ];
    }
}
