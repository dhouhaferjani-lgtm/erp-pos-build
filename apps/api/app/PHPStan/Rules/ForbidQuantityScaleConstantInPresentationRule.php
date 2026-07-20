<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids `QuantityScale::round($value, QuantityScale::SCALE, …)` inside a
 * module's Presentation layer.
 *
 * Rounding a quantity to the canonical storage scale (`QuantityScale::SCALE`,
 * i.e. 4) for display is the same regression as a hardcoded `'…0000'` literal:
 * it ignores the product unit's precision. Presentation must format for the
 * unit — `QuantityScale::formatForUnit($value, $decimalPlaces, $roundingMethod)`
 * — or the display value must be computed in the Application layer instead.
 *
 * Scope: only files whose namespace matches
 * `App\Modules\<Module>\Presentation\…`. A dynamic scale argument (variable,
 * `$unit->decimal_places`, …) is not flagged — only the literal `SCALE`
 * constant.
 *
 * @implements Rule<StaticCall>
 */
final class ForbidQuantityScaleConstantInPresentationRule implements Rule
{
    private const MESSAGE = 'Fixed scale-4 quantity literal in Presentation layer — use QuantityScale::formatForUnit() or move to Application.';

    private const IDENTIFIER = 'precision.fixedScaleQuantityRound';

    private const CLASS_SHORT_NAME = 'QuantityScale';

    private const SCALE_CONSTANT = 'SCALE';

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->isInPresentationLayer($scope)) {
            return [];
        }

        if (! $this->isQuantityScaleRoundCall($node)) {
            return [];
        }

        $args = $node->getArgs();

        // round($value, $decimalPlaces, $method) — the scale is the 2nd argument.
        if (! isset($args[1]) || ! $this->isQuantityScaleScaleConstant($args[1]->value)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::MESSAGE)
                ->identifier(self::IDENTIFIER)
                ->build(),
        ];
    }

    private function isQuantityScaleRoundCall(StaticCall $node): bool
    {
        if (! $node->name instanceof Identifier || $node->name->toString() !== 'round') {
            return false;
        }

        return $this->isQuantityScaleName($node->class);
    }

    private function isQuantityScaleScaleConstant(Node $value): bool
    {
        if (! $value instanceof ClassConstFetch) {
            return false;
        }

        if (! $value->name instanceof Identifier || $value->name->toString() !== self::SCALE_CONSTANT) {
            return false;
        }

        return $this->isQuantityScaleName($value->class);
    }

    private function isQuantityScaleName(Node $class): bool
    {
        if (! $class instanceof Name) {
            return false;
        }

        return $class->getLast() === self::CLASS_SHORT_NAME;
    }

    private function isInPresentationLayer(Scope $scope): bool
    {
        $namespace = $scope->getNamespace();

        if ($namespace === null) {
            return false;
        }

        return preg_match('#App\\\\Modules\\\\.+\\\\Presentation\\\\#', $namespace) === 1;
    }
}
