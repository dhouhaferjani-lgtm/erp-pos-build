<?php

declare(strict_types=1);

namespace App\Modules\Media\Infrastructure\Net;

use App\Modules\Media\Domain\Contracts\HostResolverInterface;

final class DnsHostResolver implements HostResolverInterface
{
    public function resolve(string $host): array
    {
        $ips = [];
        $v4 = gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $rec) {
            if (isset($rec['ipv6'])) {
                $ips[] = $rec['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
