<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\DTOs\DiscountPolicyContext;
use App\Shared\DTOs\DiscountPolicyVerdict;

interface DiscountPolicyInterface
{
    public function resolve(DiscountPolicyContext $context): DiscountPolicyVerdict;

    /**
     * @param  array<string, DiscountPolicyContext>  $contexts
     * @return array<string, DiscountPolicyVerdict>
     */
    public function resolveMany(array $contexts): array;
}
