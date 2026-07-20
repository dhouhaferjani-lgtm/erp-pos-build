<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids fixed scale-4 quantity string literals (e.g. `'1.0000'`, `'12.0000'`)
 * inside a module's Presentation layer.
 *
 * Quantities surfaced to humans must render at the product unit's precision
 * (`units.decimal_places`): pieces as whole numbers, kg to 3 places, etc. A
 * baked scale-4 string in a Resource/Controller emits the canonical storage
 * scale regardless of unit, which is wrong for anything but a scale-4 unit.
 * Compute the display value with `QuantityScale::formatForUnit()` (in the
 * Application layer) and pass it through, rather than hardcoding `'…0000'`.
 *
 * Scope: only files whose namespace matches
 * `App\Modules\<Module>\Presentation\…` — Application/Domain math is exempt.
 *
 * @implements Rule<String_>
 */
final class ForbidFixedScaleQuantityLiteralRule implements Rule
{
    private const MESSAGE = 'Fixed scale-4 quantity literal in Presentation layer — use QuantityScale::formatForUnit() or move to Application.';

    private const IDENTIFIER = 'precision.fixedScaleQuantityLiteral';

    public function getNodeType(): string
    {
        return String_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->isInPresentationLayer($scope)) {
            return [];
        }

        if (preg_match('/^\d+\.0{4}$/', $node->value) !== 1) {
            return [];
        }

        return [
            RuleErrorBuilder::message(self::MESSAGE)
                ->identifier(self::IDENTIFIER)
                ->build(),
        ];
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
