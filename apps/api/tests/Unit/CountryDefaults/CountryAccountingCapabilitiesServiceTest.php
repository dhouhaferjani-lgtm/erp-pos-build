<?php

declare(strict_types=1);

namespace Tests\Unit\CountryDefaults;

use App\Modules\CountryDefaults\Application\Services\CountryAccountingCapabilitiesService;
use PHPUnit\Framework\TestCase;

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

    public function test_capability_version_is_the_stable_v1_scalar(): void
    {
        // Production break caught: the capability set changes without the certification version changing with it.
        self::assertSame('v1', (new CountryAccountingCapabilitiesService)->version());
    }
}
