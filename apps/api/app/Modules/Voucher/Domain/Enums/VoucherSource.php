<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Enums;

/**
 * Discriminates how a voucher was born.
 *
 * All sources share one entity and one redemption pipeline.
 * The back-office surfaces them in one source-filterable list.
 *
 * Wiring schedule:
 *   Phase 1:   Refund, ExchangeSurplus, Goodwill
 *   Phase 1.5: LoyaltyCredit (called by Loyalty module when RewardType::Credit redeems)
 *   Phase 2+:  GiftCardPurchase, Promotional
 */
enum VoucherSource: string
{
    case Refund = 'refund';
    case ExchangeSurplus = 'exchange_surplus';
    case Goodwill = 'goodwill';
    case LoyaltyCredit = 'loyalty_credit';
    case GiftCardPurchase = 'gift_card_purchase';
    case Promotional = 'promotional';
}
