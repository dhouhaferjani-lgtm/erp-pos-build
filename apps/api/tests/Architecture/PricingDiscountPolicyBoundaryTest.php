<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class PricingDiscountPolicyBoundaryTest extends TestCase
{
    public function test_pricing_discount_policy_code_does_not_import_product_company_or_category_models(): void
    {
        $files = [
            dirname(__DIR__, 2).'/app/Modules/Pricing/Domain/Services/DiscountCapResolver.php',
            dirname(__DIR__, 2).'/app/Modules/Pricing/Domain/Services/DiscountPolicyService.php',
            dirname(__DIR__, 2).'/app/Modules/Pricing/Presentation/Controllers/DiscountPolicyController.php',
        ];

        foreach ($files as $file) {
            self::assertFileExists($file);
            $contents = (string) file_get_contents($file);

            self::assertStringNotContainsString('App\\Modules\\Product\\Domain\\Product', $contents, $file);
            self::assertStringNotContainsString('App\\Modules\\Product\\Domain\\Category', $contents, $file);
            self::assertStringNotContainsString('App\\Modules\\Company\\Domain\\Company', $contents, $file);
        }
    }
}
