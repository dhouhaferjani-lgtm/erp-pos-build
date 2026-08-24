<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

/**
 * A fulfilment lane refused to commit goods it does not have.
 *
 * Raised by the two lanes a sales document drives:
 *   - the RESERVATION lane, `StockReservationService::reserve()` (sales-order confirm);
 *   - the WAC SALE lane, `WeightedAverageCostService::recordSale()` (delivery-note
 *     confirm and the two invoice convenience endpoints).
 *
 * ── ABSENT ROW == QUANTITY 0 (campaign N-2) ──
 * A missing `stock_levels` row is NOT a missing resource: the product and the
 * location both exist, the tuple simply has no stock yet — which is the day-one
 * state of every product. Both lanes previously reached `firstOrFail()` there and
 * surfaced `ModelNotFoundException`, i.e. a raw **404** on a POST that was
 * refusing a business rule. The rule those lanes already apply to an EXISTING row
 * at quantity 0 is a refusal, so the absent row refuses identically — same
 * exception, same machine code, and (deliberately) no phantom row written by a
 * failed attempt.
 *
 * ── WHY \RuntimeException AND NOT \DomainException ──
 * The reservation refusal is ALREADY a `\RuntimeException`, and that is a pinned
 * contract: `tests/Feature/Marketplace/StockReservationTest.php:327` asserts
 * `expectException(\RuntimeException::class)` on the marketplace cart path, which
 * reaches `StockReservationService::reserve()`. Subclassing keeps that lane's
 * observable behaviour byte-identical while giving the document controllers a
 * precise type to translate.
 *
 * It deliberately does NOT ride on `\DomainException` either: both
 * `SalesOrderController::confirm()` and `DeliveryNoteController::confirm()` map
 * EVERY `\DomainException` to `INVALID_STATUS_TRANSITION`, and "you do not have
 * the goods" is not a status-transition error. A distinct type, caught FIRST, is
 * what makes an honest `INSUFFICIENT_STOCK` reachable by the operator.
 */
final class InsufficientStockForFulfilmentException extends \RuntimeException
{
    /**
     * Machine code surfaced to API clients as `error.code`.
     *
     * @see docs/conventions/01-API-RESPONSES.md
     */
    public const string ERROR_CODE = 'INSUFFICIENT_STOCK';

    /**
     * @param  numeric-string  $available  Free quantity at the tuple; '0.0000' when no row exists.
     * @param  numeric-string  $requested  Quantity the caller asked to commit.
     */
    public function __construct(
        public readonly string $productId,
        public readonly ?string $productName,
        public readonly string $locationId,
        public readonly ?string $locationName,
        public readonly string $available,
        public readonly string $requested,
    ) {
        parent::__construct(self::buildMessage(
            $productName ?? $productId,
            $locationName ?? $locationId,
            $available,
            $requested,
        ));
    }

    /**
     * The message opens with "Insufficient stock" on purpose: that prefix is the
     * operator-facing wording every other stock refusal in the codebase uses, and
     * `tests/Feature/Marketplace/StockReservationTest.php:328` pins it as a
     * substring on the reservation lane.
     */
    private static function buildMessage(
        string $product,
        string $location,
        string $available,
        string $requested,
    ): string {
        return "Insufficient stock for '{$product}' at '{$location}'. "
            ."Available: {$available}, Requested: {$requested}";
    }
}
