<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

use App\Modules\POS\Domain\Enums\PaymentInstrumentKind;
use DomainException;

/**
 * Thrown when a payment row whose `payment_method_code` requires an
 * instrument (store_voucher / restaurant_voucher / gift_card per the
 * {@see PaymentInstrumentKind} enum) is
 * persisted without both `instrument_type` and `instrument_serial`.
 *
 * Codex review B4 (2026-04-30): defense-in-depth guard. The request
 * validators (StoreReceiptPaymentsRequest, SyncReceiptsRequest) reject
 * the same shape with 422, but service-layer callers (programmatic,
 * test-only, future internal flows) bypass validation. Throwing this
 * domain exception preserves the fiscal-hash invariant: a v3 receipt
 * sealed with `method_code = store_voucher` and `instrument_serial = null`
 * binds the wrong fact into the chain — the legally meaningful event is
 * "voucher SV-XXXX paid", not "some voucher paid". Mapped to HTTP 422 by
 * the generic DomainException renderer in bootstrap/app.php.
 */
final class InstrumentRequiredException extends DomainException
{
    public static function forMethodCode(string $methodCode): self
    {
        return new self(
            sprintf(
                'Payment method "%s" requires both instrument_type and instrument_serial. '
                .'Leaving them null breaks the v3 fiscal-hash binding for instrument-bearing tenders.',
                $methodCode,
            )
        );
    }
}
