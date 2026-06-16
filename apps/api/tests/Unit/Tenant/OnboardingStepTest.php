<?php

declare(strict_types=1);

namespace Tests\Unit\Tenant;

use App\Modules\Tenant\Domain\Enums\OnboardingStep;
use PHPUnit\Framework\TestCase;

final class OnboardingStepTest extends TestCase
{
    public function test_product_options_step_is_optional_with_catalog_path(): void
    {
        $this->assertSame('product_options', OnboardingStep::ProductOptions->value);
        $this->assertFalse(OnboardingStep::ProductOptions->isRequired());
        $this->assertSame('/catalog/attributes', OnboardingStep::ProductOptions->settingsPath());
        $this->assertSame('Set up product options', OnboardingStep::ProductOptions->label());
    }
}
