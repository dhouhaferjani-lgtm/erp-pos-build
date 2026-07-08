<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\DTOs\DiscountPolicyLineContext;
use App\Shared\DTOs\DiscountPolicySubject;

interface DiscountPolicySubjectProviderInterface
{
    /**
     * @param  array<string, DiscountPolicyLineContext>  $contexts
     * @return array<string, DiscountPolicySubject>
     */
    public function resolveMany(string $companyId, array $contexts): array;

    public function resolve(string $companyId, string $productId, ?string $variantId = null): DiscountPolicySubject;
}
