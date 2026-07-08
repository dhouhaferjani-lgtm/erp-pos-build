<?php

declare(strict_types=1);

namespace App\Modules\Media\Domain\Contracts;

interface HostResolverInterface
{
    /** @return array<int, string> resolved IPv4/IPv6 addresses; empty if none */
    public function resolve(string $host): array;
}
