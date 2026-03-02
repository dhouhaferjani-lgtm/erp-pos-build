<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Application\Services;

use App\Modules\Promotion\Domain\Entities\Promotion;
use App\Modules\Promotion\Domain\Enums\PromotionStatus;

final class PromotionManagementService
{
    /**
     * Activate a promotion (Draft/Paused → Active).
     */
    public function activate(Promotion $promotion): Promotion
    {
        if (! in_array($promotion->status, [PromotionStatus::Draft, PromotionStatus::Paused], true)) {
            throw new \DomainException("Cannot activate a promotion with status '{$promotion->status->value}'.");
        }

        $promotion->update(['status' => PromotionStatus::Active]);

        return $promotion;
    }

    /**
     * Pause an active promotion (Active → Paused).
     */
    public function pause(Promotion $promotion): Promotion
    {
        if ($promotion->status !== PromotionStatus::Active) {
            throw new \DomainException("Cannot pause a promotion with status '{$promotion->status->value}'.");
        }

        $promotion->update(['status' => PromotionStatus::Paused]);

        return $promotion;
    }

    /**
     * Archive a promotion (any non-archived → Archived).
     */
    public function archive(Promotion $promotion): Promotion
    {
        if ($promotion->status === PromotionStatus::Archived) {
            throw new \DomainException('Promotion is already archived.');
        }

        $promotion->update(['status' => PromotionStatus::Archived]);

        return $promotion;
    }

    /**
     * Record a usage of this promotion (increment counter).
     */
    public function recordUsage(Promotion $promotion, string $receiptId, ?string $partnerId, string $discountAmount): void
    {
        $promotion->usages()->create([
            'receipt_id' => $receiptId,
            'partner_id' => $partnerId,
            'discount_amount' => $discountAmount,
            'used_at' => now(),
        ]);

        $promotion->increment('usage_count');
    }
}
