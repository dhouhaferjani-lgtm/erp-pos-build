<?php

declare(strict_types=1);

namespace Tests\Unit\CountryDefaults;

use App\Modules\CountryDefaults\Application\Services\CountryAccountingCapabilitiesService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CountryAccountingCapabilitiesServiceTest extends TestCase
{
    public function test_only_tunisia_is_stamp_duty_capable_after_normalization(): void
    {
        // Production break caught: a country is added/removed from v1, or trim/uppercase normalization drifts.
        $capabilities = new CountryAccountingCapabilitiesService;

        self::assertTrue($capabilities->supportsStampDuty(' TN '));
        self::assertTrue($capabilities->supportsStampDuty('tn'));
        self::assertFalse($capabilities->supportsStampDuty('FR'));
        self::assertFalse($capabilities->supportsStampDuty('zz'));
        self::assertFalse($capabilities->supportsStampDuty(''));
    }

    public function test_v1_version_and_full_capable_country_set_are_one_exact_contract(): void
    {
        // Production break caught: v1's complete capable-country set changes without this versioned contract being reviewed.
        $capabilities = new CountryAccountingCapabilitiesService;
        $reflection = new ReflectionClass($capabilities);

        self::assertSame(
            [
                'version' => 'v1',
                'stamp_duty_countries' => ['TN'],
            ],
            [
                'version' => $capabilities->version(),
                'stamp_duty_countries' => $reflection->getConstant('STAMP_DUTY_COUNTRIES'),
            ],
        );
    }
}
