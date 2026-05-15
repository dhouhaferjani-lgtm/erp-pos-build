<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Application\Services\HashChainIntegrityProvider;
use App\Shared\Contracts\Fiscal\FiscalIntegrityProvider;
use Tests\TestCase;

final class HashChainIntegrityProviderTest extends TestCase
{
    public function test_version_and_hash_and_verify(): void
    {
        $provider = new HashChainIntegrityProvider;
        $this->assertSame('hash-chain-integrity-v1', $provider->version());

        $bytes = '{"business_date":"2026-05-14"}';
        $hash = $provider->computeHash($bytes);

        $this->assertSame(hash('sha256', $bytes), $hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        $this->assertTrue($provider->verify($bytes, $hash));
        $this->assertFalse($provider->verify($bytes, str_repeat('0', 64)));
    }

    public function test_fiscal_service_provider_binds_integrity_provider(): void
    {
        $this->assertInstanceOf(
            HashChainIntegrityProvider::class,
            $this->app->make(FiscalIntegrityProvider::class),
        );
    }
}
