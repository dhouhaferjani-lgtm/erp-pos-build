<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\PHPStan\Rules\ForbidFixedScaleQuantityLiteralRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<ForbidFixedScaleQuantityLiteralRule>
 */
final class ForbidFixedScaleQuantityLiteralRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new ForbidFixedScaleQuantityLiteralRule;
    }

    public function test_flags_scale_four_literals_in_presentation_layer(): void
    {
        $message = 'Fixed scale-4 quantity literal in Presentation layer — use QuantityScale::formatForUnit() or move to Application.';

        $this->analyse(
            [__DIR__.'/Fixtures/PresentationQuantityLiteral.php'],
            [
                [$message, 15],
                [$message, 16],
            ],
        );
    }

    public function test_ignores_non_scale_four_literals_in_presentation_layer(): void
    {
        $this->analyse([__DIR__.'/Fixtures/CleanPresentationQuantityLiteral.php'], []);
    }

    public function test_does_not_flag_literals_outside_presentation_layer(): void
    {
        $this->analyse([__DIR__.'/Fixtures/ApplicationQuantityLiteral.php'], []);
    }
}
