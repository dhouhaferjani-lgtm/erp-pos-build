<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Domain\Contracts;

use App\Modules\Promotion\Domain\ValueObjects\CartContext;
use App\Modules\Promotion\Domain\ValueObjects\PromotionDiscount;

interface PromotionEvaluatorContract
{
    /**
     * Evaluate all active promotions against the current cart.
     *
     * @return array<int, PromotionDiscount>
     */
    public function evaluateCart(CartContext $cart): array;
}
