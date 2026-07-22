<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\PHPStan\Rules\ForbidQuantityScaleConstantInPresentationRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<ForbidQuantityScaleConstantInPresentationRule>
 */
final class ForbidQuantityScaleConstantInPresentationRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new ForbidQuantityScaleConstantInPresentationRule;
    }

    public function test_flags_quantityscale_round_with_scale_constant_in_presentation_layer(): void
    {
        $message = 'Fixed scale-4 quantity literal in Presentation layer — use QuantityScale::formatForUnit() or move to Application.';

        $this->analyse(
            [__DIR__.'/Fixtures/PresentationScaleRound.php'],
            [
                [$message, 13],
            ],
        );
    }

    public function test_ignores_round_with_dynamic_scale_in_presentation_layer(): void
    {
        $this->analyse([__DIR__.'/Fixtures/CleanPresentationScaleRound.php'], []);
    }

    public function test_does_not_flag_round_outside_presentation_layer(): void
    {
        $this->analyse([__DIR__.'/Fixtures/ApplicationScaleRound.php'], []);
    }
}
