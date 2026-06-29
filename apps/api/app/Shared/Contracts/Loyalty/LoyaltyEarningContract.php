<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Loyalty;

/**
 * Public Loyalty earning surface for other modules (POS). The only sanctioned
 * way for POS to award points — no Loyalty model is imported by the consumer.
 */
interface LoyaltyEarningContract
{
    /**
     * Credit loyalty points for a completed sale. Best-effort and idempotent:
     * resolves the member, loops active enrollments, and swallows the
     * already-earned duplicate case. Never throws to the caller.
     */
    public function earnForSale(SaleEarnContext $context): void;
}
