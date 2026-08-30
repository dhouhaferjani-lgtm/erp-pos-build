<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

interface CoalescingAttributeMergerInterface
{
    /**
     * @param  array<string, bool|int|string|null>  $existing
     * @param  array<string, bool|int|string|null>  $incoming
     * @param  list<string>  $provided
     * @return array<string, bool|int|string|null>
     */
    public function merge(array $existing, array $incoming, array $provided): array;
}
