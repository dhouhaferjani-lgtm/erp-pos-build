<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use App\Shared\Domain\CurrencyScale;
use Illuminate\Database\Eloquent\Model;
use Larastan\Larastan\Properties\ModelCastHelper;
use PhpParser\Node;
use PhpParser\Node\Expr\Cast\Double as DoubleCast;
use PhpParser\Node\Expr\PropertyFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;

/**
 * Forbids `(float) $model->prop` where `prop` is declared as a `decimal:N`
 * Eloquent cast.
 *
 * Monetary and quantity columns are stored and round-tripped as fixed-scale
 * numeric strings (see {@see CurrencyScale}). Casting such a
 * property to `float` re-introduces IEEE 754 precision loss — the exact class
 * of bug the app-wide precision workstream is eradicating (e.g. 5.000 -> 4.999,
 * 0.1 + 0.2 -> 0.30000000000000004). Once a value is a float, every downstream
 * `number_format`, comparison and accumulation inherits the drift.
 *
 * Use bcmath helpers (`bcadd`, `bcmul`, `CurrencyScale::bcformat`, …) on the
 * numeric-string value instead of casting to float.
 *
 * The rule resolves the property's owning class via the static analyser scope
 * and asks Larastan's {@see ModelCastHelper} whether that property carries a
 * `decimal:*` cast — so it fires only on genuine decimal model properties and
 * never on plain float computations.
 *
 * @implements Rule<DoubleCast>
 */
final class ForbidFloatCastOnDecimalProperty implements Rule
{
    public function __construct(
        private readonly ModelCastHelper $modelCastHelper,
    ) {}

    public function getNodeType(): string
    {
        return DoubleCast::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        // PhpParser represents both (float) and (double) casts as Cast\Double.
        $expr = $node->expr;

        if (! $expr instanceof PropertyFetch) {
            return [];
        }

        if (! $expr->name instanceof Node\Identifier) {
            return [];
        }

        $propertyName = $expr->name->toString();

        $ownerType = $scope->getType($expr->var);

        foreach ($this->resolveClassReflections($ownerType) as $classReflection) {
            $cast = $this->modelCastHelper->getCastForProperty($classReflection, $propertyName);

            if ($cast === null) {
                continue;
            }

            if (! str_starts_with($cast, 'decimal')) {
                continue;
            }

            return [
                RuleErrorBuilder::message(sprintf(
                    "Casting decimal property %s::\$%s (cast '%s') to float re-introduces "
                    .'IEEE 754 precision loss. Keep it a numeric-string and use bcmath '
                    .'(bcadd/bcmul/CurrencyScale::bcformat) instead of (float).',
                    $classReflection->getName(),
                    $propertyName,
                    $cast,
                ))
                    ->identifier('precision.floatCastOnDecimalProperty')
                    ->build(),
            ];
        }

        return [];
    }

    /**
     * Resolve every concrete Eloquent model class behind the receiver type
     * (handles union types where at least one member is a model with the
     * property). Non-model classes are skipped — Larastan's ModelCastHelper
     * instantiates the class and calls getCasts(), which only exists on
     * Eloquent models and would otherwise crash the analysis.
     *
     * @return list<ClassReflection>
     */
    private function resolveClassReflections(Type $type): array
    {
        $reflections = [];

        // getObjectClassReflections() covers both a plain object type and each
        // member of a union, so it subsumes the single-object case.
        foreach ($type->getObjectClassReflections() as $classReflection) {
            if ($classReflection->is(Model::class)) {
                $reflections[] = $classReflection;
            }
        }

        return $reflections;
    }
}
