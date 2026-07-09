<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

use RuntimeException;

final class DiscountPolicySubjectNotFoundException extends RuntimeException
{
    public function __construct(public readonly string $productId)
    {
        parent::__construct("Product {$productId} was not found for discount policy resolution.");
    }
}
