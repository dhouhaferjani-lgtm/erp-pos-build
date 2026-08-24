<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use App\Shared\Domain\QuantityScale;

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
 * surfaced `ModelNotFoundException`. On sales-order confirm and on the two invoice
 * convenience endpoints that escaped as a raw **404**; on delivery-note confirm the
 * controller's pre-existing `catch (\RuntimeException)` arm swallowed it into a
 * misleading 422 `CONFIGURATION_ERROR` (`ModelNotFoundException` IS a
 * `\RuntimeException`). Neither answer was true. The rule those lanes already apply
 * to an EXISTING row at quantity 0 is a refusal, so the absent row refuses
 * identically — same exception, same machine code, and (deliberately) no phantom
 * row written by a failed attempt.
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
 *
 * ── EXCEPTION TEXT vs OPERATOR TEXT (gate r1 I-3 / M-3) ──
 * `getMessage()` is the DEVELOPER/log string and stays English by design — it is
 * what lands in logs and in `expectExceptionMessage` pins. The OPERATOR text is
 * built by the controllers from `translationKey()` + `translationReplacements()`,
 * so the 422 body is rendered in the requesting tenant's locale (house rule 11).
 * The quantities in `translationReplacements()` are rendered at the product unit's
 * own `decimal_places` via `QuantityScale::formatForUnit` (rule 19, display leg);
 * the raw scale-4 values stay on `$available` / `$requested` for machine consumers.
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
     * Translation key for the OPERATOR-facing message (the `documents` lang file).
     */
    public const string TRANSLATION_KEY = 'documents.stock.insufficient';

    /** Display-rendered `$available`, at the product unit's decimal places. */
    public readonly string $availableForDisplay;

    /** Display-rendered `$requested`, at the product unit's decimal places. */
    public readonly string $requestedForDisplay;

    /**
     * @param  numeric-string  $available  Free quantity at the tuple; '0.0000' when no row exists.
     * @param  numeric-string  $requested  Quantity the caller asked to commit.
     * @param  int|null  $quantityDecimals  The product unit's `decimal_places`; null falls back to the canonical scale.
     * @param  string|null  $roundingMethod  The product unit's `rounding_method` value; null falls back to half-up.
     */
    public function __construct(
        public readonly string $productId,
        public readonly ?string $productName,
        public readonly string $locationId,
        public readonly ?string $locationName,
        public readonly string $available,
        public readonly string $requested,
        ?int $quantityDecimals = null,
        ?string $roundingMethod = null,
    ) {
        $this->availableForDisplay = QuantityScale::formatForUnit($available, $quantityDecimals, $roundingMethod);
        $this->requestedForDisplay = QuantityScale::formatForUnit($requested, $quantityDecimals, $roundingMethod);

        parent::__construct(self::buildMessage(
            $productName ?? $productId,
            $locationName ?? $locationId,
            $available,
            $requested,
        ));
    }

    /**
     * Placeholders for `__(self::TRANSLATION_KEY, …)`.
     *
     * @return array<string, string>
     */
    public function translationReplacements(): array
    {
        return [
            'product' => $this->productName ?? $this->productId,
            'location' => $this->locationName ?? $this->locationId,
            'available' => $this->availableForDisplay,
            'requested' => $this->requestedForDisplay,
        ];
    }

    /**
     * The DEVELOPER/log message. It opens with "Insufficient stock" on purpose:
     * that prefix is what the pre-existing refusals used and
     * `tests/Feature/Marketplace/StockReservationTest.php:328` pins it as a
     * substring on the reservation lane. Quantities here stay at the canonical
     * scale — this string is for logs, never for the operator.
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
