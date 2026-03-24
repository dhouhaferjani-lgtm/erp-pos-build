<?php

declare(strict_types=1);

namespace Tests\Traits;

use App\Shared\Contracts\CurrencyScaleResolverInterface;
use PHPUnit\Framework\MockObject\MockObject;

trait WithCurrencyScale
{
    /**
     * Create a CurrencyScaleResolverInterface mock that returns a fixed scale.
     *
     * @param  int  $scale  The decimal scale to return (default: 2 for EUR)
     */
    protected function mockCurrencyScale(int $scale = 2): CurrencyScaleResolverInterface&MockObject
    {
        $mock = $this->createMock(CurrencyScaleResolverInterface::class);
        $mock->method('getScale')->willReturn($scale);

        return $mock;
    }
}
