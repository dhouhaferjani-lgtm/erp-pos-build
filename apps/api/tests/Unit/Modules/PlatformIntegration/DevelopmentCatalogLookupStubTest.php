<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\PlatformIntegration;

use App\Modules\PlatformIntegration\Domain\Services\BarcodeNormalizer;
use App\Modules\PlatformIntegration\Infrastructure\DevelopmentCatalogLookupResult;
use App\Modules\PlatformIntegration\Infrastructure\DevelopmentCatalogLookupStub;
use App\Shared\Enums\CatalogLookupOutcome;
use PHPUnit\Framework\TestCase;

final class DevelopmentCatalogLookupStubTest extends TestCase
{
    public function test_lookup_result_is_psr_4_autoloadable_without_loading_the_stub_first(): void
    {
        self::assertTrue(class_exists(DevelopmentCatalogLookupResult::class));
    }

    public function test_it_exposes_stable_found_and_not_found_local_outcomes(): void
    {
        $stub = new DevelopmentCatalogLookupStub(new BarcodeNormalizer);

        $found = $stub->lookup('5903407024073', 'pharmacy');
        $missing = $stub->lookup('6199106101538', 'pharmacy');

        self::assertSame(CatalogLookupOutcome::Found, $found->outcome());
        self::assertSame('11111111-1111-4111-8111-111111111111', $found->platformProductId());
        self::assertSame(CatalogLookupOutcome::NotFound, $missing->outcome());
        self::assertNull($missing->platformProductId());
        self::assertSame('Local catalogue fixture', $stub->lookupCatalogProduct('5903407024073', 'pharmacy')?->name);
        self::assertNull($stub->lookupCatalogProduct('6199106101538', 'pharmacy'));
        self::assertFalse($stub->isCircuitOpen());
    }
}
