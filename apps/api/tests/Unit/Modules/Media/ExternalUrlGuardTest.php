<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Media;

use App\Modules\Media\Domain\ValueObjects\ExternalUrlGuard;
use InvalidArgumentException;
use Tests\TestCase;

final class ExternalUrlGuardTest extends TestCase
{
    public function test_accepts_normal_https_url(): void
    {
        $this->expectNotToPerformAssertions();
        ExternalUrlGuard::assertHttpsHostAllowed('https://pharma-shop.tn/img/serum.jpg');
    }

    /** @dataProvider allowedPortUrls */
    public function test_accepts_urls_on_standard_https_port(string $url): void
    {
        $this->expectNotToPerformAssertions();
        ExternalUrlGuard::assertHttpsHostAllowed($url);
    }

    /** @return array<string, array{string}> */
    public static function allowedPortUrls(): array
    {
        return [
            'explicit 443' => ['https://pharma-shop.tn:443/x.jpg'],
            'no explicit port' => ['https://pharma-shop.tn/x.jpg'],
        ];
    }

    /** @dataProvider blockedUrls */
    public function test_rejects_unsafe_urls(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        ExternalUrlGuard::assertHttpsHostAllowed($url);
    }

    /** @return array<string, array{string}> */
    public static function blockedUrls(): array
    {
        return [
            'http scheme' => ['http://pharma-shop.tn/x.jpg'],
            'localhost' => ['https://localhost/x.jpg'],
            'loopback v4' => ['https://127.0.0.1/x.jpg'],
            'loopback v6' => ['https://[::1]/x.jpg'],
            'rfc1918 10' => ['https://10.0.0.5/x.jpg'],
            'rfc1918 192' => ['https://192.168.1.10/x.jpg'],
            'link-local' => ['https://169.254.1.1/x.jpg'],
            'non-443 https port' => ['https://pharma-shop.tn:8443/x.jpg'],
            'no host' => ['https:///x.jpg'],
            'over-length' => ['https://a.tn/'.str_repeat('a', 2100)],
        ];
    }
}
