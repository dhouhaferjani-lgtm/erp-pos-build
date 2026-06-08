<?php

declare(strict_types=1);

namespace Tests\Traits;

use App\Shared\Contracts\CurrencyScaleResolverInterface;
use PHPUnit\Framework\MockObject\MockObject;

trait WithCurrencyScale
{
    /** Default test scale is 3 (TND) to surface scale-2 regressions. */
    public const DEFAULT_TEST_SCALE = 3;

    /**
     * Create a CurrencyScaleResolverInterface mock that returns a fixed scale.
     *
     * @param  int  $scale  The decimal scale to return (default: 3 for TND)
     */
    protected function mockCurrencyScale(int $scale = self::DEFAULT_TEST_SCALE): CurrencyScaleResolverInterface&MockObject
    {
        $mock = $this->createMock(CurrencyScaleResolverInterface::class);
        $mock->method('getScale')->willReturn($scale);
        $mock->method('getScaleSafe')->willReturn($scale);

        return $mock;
    }
}
