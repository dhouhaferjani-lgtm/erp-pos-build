<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Exceptions;

use DomainException;

/**
 * A return would exceed the source document's returnable quantity for a product.
 *
 * Plan CF T2 (fiscal gate round-1 finding I-8). The cap itself is not new — it
 * lived in `ReturnNoteController::assertWithinReturnableQuantities()` and refused
 * by throwing Laravel's `HttpResponseException` with a hand-built 422 body. Moving
 * the guard into `ReturnNoteService` (so the composite cancel flow and the
 * standalone `POST /return-notes` cannot drift apart) would have carried a
 * **Presentation** type into `Domain/Services/`, violating hexagonal rules 4/6 and
 * pushing the deptrac ratchet. The refusal is therefore typed here and rendered by
 * the dedicated entry in `bootstrap/app.php`, which reproduces the previous body
 * byte for byte — `error.code` plus the same five `details` keys — so no consumer
 * of the old envelope regresses.
 *
 * `details` is product-keyed on purpose. After CF-D7 the guided cancel modal has
 * no per-line UI at all, so the banner has to name the product and its
 * `remaining_returnable` for the refusal to be actionable at all.
 */
final class ReturnQuantityExceededException extends DomainException
{
    public const CODE_INVOICED = 'RETURN_EXCEEDS_INVOICED_QUANTITY';

    public const CODE_DELIVERED = 'RETURN_EXCEEDS_DELIVERED_QUANTITY';

    /**
     * @param  self::CODE_*  $refusalCode
     * @param  numeric-string  $requested
     * @param  numeric-string  $remainingReturnable
     * @param  numeric-string  $sourceQuantity
     * @param  numeric-string  $alreadyReturned
     */
    private function __construct(
        string $message,
        public readonly string $refusalCode,
        public readonly string $productId,
        public readonly string $requested,
        public readonly string $remainingReturnable,
        public readonly string $sourceQuantity,
        public readonly string $alreadyReturned,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  numeric-string  $requested
     * @param  numeric-string  $remainingReturnable
     * @param  numeric-string  $invoiced
     * @param  numeric-string  $alreadyReturned
     */
    public static function exceedsInvoiced(
        string $productId,
        string $requested,
        string $remainingReturnable,
        string $invoiced,
        string $alreadyReturned,
    ): self {
        return new self(
            'Return quantity exceeds the invoiced quantity available to return',
            self::CODE_INVOICED,
            $productId,
            $requested,
            $remainingReturnable,
            $invoiced,
            $alreadyReturned,
        );
    }

    /**
     * @param  numeric-string  $requested
     * @param  numeric-string  $remainingReturnable
     * @param  numeric-string  $delivered
     * @param  numeric-string  $alreadyReturned
     */
    public static function exceedsDelivered(
        string $productId,
        string $requested,
        string $remainingReturnable,
        string $delivered,
        string $alreadyReturned,
    ): self {
        return new self(
            'Return quantity exceeds the delivered quantity available to return',
            self::CODE_DELIVERED,
            $productId,
            $requested,
            $remainingReturnable,
            $delivered,
            $alreadyReturned,
        );
    }

    /**
     * The exact `details` payload the pre-T2 `HttpResponseException` body carried.
     *
     * @return array{product_id: string, requested: numeric-string, remaining_returnable: numeric-string, invoiced: numeric-string, already_returned: numeric-string}
     */
    public function details(): array
    {
        return [
            'product_id' => $this->productId,
            'requested' => $this->requested,
            'remaining_returnable' => $this->remainingReturnable,
            'invoiced' => $this->sourceQuantity,
            'already_returned' => $this->alreadyReturned,
        ];
    }
}
