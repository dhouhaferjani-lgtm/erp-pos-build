<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Media;

use App\Modules\Media\Domain\ValueObjects\PrivateIpRanges;
use Tests\TestCase;

final class PrivateIpRangesTest extends TestCase
{
    /** @dataProvider ips */
    public function test_classifies_ip(string $ip, bool $disallowed): void
    {
        $this->assertSame($disallowed, PrivateIpRanges::isDisallowed($ip));
    }

    /** @return array<string, array{string, bool}> */
    public static function ips(): array
    {
        return [
            'public v4' => ['41.226.11.20', false],
            'loopback' => ['127.0.0.1', true],
            'rfc1918 10' => ['10.1.2.3', true],
            'rfc1918 172' => ['172.16.5.5', true],
            'rfc1918 192' => ['192.168.0.1', true],
            'link-local' => ['169.254.0.1', true],
            'cgnat' => ['100.64.0.1', true],
            'v6 loopback' => ['::1', true],
            'v6 ula' => ['fd00::1', true],
            'v6 public' => ['2a00:1450:4001::1', false],
        ];
    }
}
