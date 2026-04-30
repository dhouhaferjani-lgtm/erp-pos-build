<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\RefundDestination;
use App\Modules\POS\Domain\Enums\ReturnReason;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;

/**
 * Immutable value object carrying everything ExchangeService::processExchange() needs.
 *
 * The exchange flow creates TWO fiscal documents:
 *  1. A return receipt for $returnLines (the items being brought back).
 *  2. A sale receipt for $newSaleItems (the items being purchased instead).
 *
 * Net settlement:
 *  - Positive net (sale > return): customer owes money. $netPaymentTenders must be provided.
 *  - Negative net (return > sale): customer is owed money. Settled via $surplusDestination.
 *  - Zero net: even exchange. No payment or refund recorded.
 */
final readonly class ExchangeRequestInput
{
    /**
     * @param  Receipt  $original  The original receipt being (partially) returned
     * @param  array<int, array{line_id: string, quantity: string}>  $returnLines  Lines to return
     * @param  ReturnReason  $returnReason  Reason for the return half
     * @param  array<int, array{product_id?: string, composite_item_id?: string, quantity: string, unit_price: string}>  $newSaleItems  Items for the new sale half
     * @param  User  $cashier  Cashier processing the exchange
     * @param  Terminal  $terminal  Terminal on which the exchange occurs
     * @param  string  $exchangeRequestId  Client-supplied idempotency UUID
     * @param  RefundDestination  $surplusDestination  Where surplus goes when net is negative (Cash or StoreVoucher)
     * @param  array<int, array{payment_method_id: string, amount: numeric-string, repository_id: string}>  $netPaymentTenders  Required when net > 0 (customer pays)
     * @param  string|null  $partnerId  Optional customer / partner FK
     * @param  string|null  $authorizedByUserId  Manager UUID when an override fires
     * @param  string|null  $overrideReason  Manager override reason text
     * @param  string|null  $notes  Free-text notes
     */
    public function __construct(
        public readonly Receipt $original,
        public readonly array $returnLines,
        public readonly ReturnReason $returnReason,
        public readonly array $newSaleItems,
        public readonly User $cashier,
        public readonly Terminal $terminal,
        public readonly string $exchangeRequestId,
        public readonly RefundDestination $surplusDestination = RefundDestination::Cash,
        /** @var array<int, array{payment_method_id: string, amount: numeric-string, repository_id: string}> */
        public readonly array $netPaymentTenders = [],
        public readonly ?string $partnerId = null,
        public readonly ?string $authorizedByUserId = null,
        public readonly ?string $overrideReason = null,
        public readonly ?string $notes = null,
    ) {}
}
