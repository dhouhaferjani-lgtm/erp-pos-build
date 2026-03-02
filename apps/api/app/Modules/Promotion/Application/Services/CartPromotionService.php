<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Application\Services;

use App\Modules\Promotion\Domain\Contracts\PromotionEvaluatorContract;
use App\Modules\Promotion\Domain\Entities\Promotion;
use App\Modules\Promotion\Domain\Services\PromotionEvaluationService;
use App\Modules\Promotion\Domain\ValueObjects\CartContext;
use App\Modules\Promotion\Domain\ValueObjects\PromotionDiscount;
use Illuminate\Support\Carbon;

final class CartPromotionService implements PromotionEvaluatorContract
{
    public function __construct(
        private readonly PromotionEvaluationService $evaluationService,
    ) {}

    /**
     * @return array<int, PromotionDiscount>
     */
    public function evaluateCart(CartContext $cart): array
    {
        $now = Carbon::parse($cart->appliedAt);

        // Load all potentially active promotions for this company
        $promotions = Promotion::query()
            ->forCompany($cart->companyId)
            ->where('tenant_id', $cart->tenantId)
            ->active()
            ->byPriority()
            ->get();

        $allDiscounts = [];

        foreach ($promotions as $promotion) {
            if (! $promotion->isCurrentlyActive($now)) {
                continue;
            }

            $discounts = $this->evaluationService->evaluate($promotion, $cart);
            foreach ($discounts as $discount) {
                $allDiscounts[] = $discount;
            }
        }

        return $allDiscounts;
    }
}
