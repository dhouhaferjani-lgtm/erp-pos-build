<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Fiscal\Domain\DTOs\AccountChargePayload;
use App\Modules\Fiscal\Domain\DTOs\AccountPaymentPayload;
use App\Modules\Fiscal\Domain\DTOs\Canonical\AccountChargeBalanceSnapshotDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\AccountChargeCreditDecisionDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\AccountChargeCustomerDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\AccountChargeTermsDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\AccountChargeTotalsDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\AccountChargeView;
use App\Modules\Fiscal\Domain\DTOs\Canonical\AccountPaymentBalanceSnapshotDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\AccountPaymentCustomerDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\AccountPaymentPaymentDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\AccountPaymentStalenessDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\AccountPaymentView;
use App\Modules\Fiscal\Domain\DTOs\Canonical\BuyerDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\DepositReceiptCustomerDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\DepositReceiptPaymentDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\DepositReceiptView;
use App\Modules\Fiscal\Domain\DTOs\Canonical\LineItemDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\OriginalLineReferenceDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\OriginalReceiptReferenceDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\PaymentDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\SaleReceiptCanonicalView;
use App\Modules\Fiscal\Domain\DTOs\Canonical\SellerDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\VatBreakdownDTO;
use App\Modules\Fiscal\Domain\DTOs\Canonical\VoucherRedemptionDTO;
use App\Modules\Fiscal\Domain\DTOs\DepositReceiptPayload;
use App\Modules\Fiscal\Domain\DTOs\SaleReceiptPayload;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use InvalidArgumentException;

/**
 * Typed canonical-view reader over a verified `fiscal_events.payload`.
 *
 * Returns a `SaleReceiptCanonicalView` with parsed seller / buyer /
 * line_items / payments / vat_breakdown / original_receipt_reference /
 * vouchers_redeemed sub-DTOs.
 *
 * Pass 2A.PHP.1 ships the reader; consumers (PosCoreReceiptProjection,
 * Nf525DataProvider) migrate to it in Pass 2A.PHP.2. The reader does NOT
 * re-run validation — `OutboxIngestor` already gated the payload through
 * `StrictCanonicalParser` + `FiscalPayloadConstraintValidator` before it
 * was persisted. Callers receive an exception if (a) the event is not
 * SALE_RECEIPT or (b) the payload is structurally absent / wrong-shaped
 * (defense-in-depth — should never fire on a verified row).
 */
final class CanonicalPayloadReader
{
    /**
     * Build a typed canonical view for a SALE_RECEIPT fiscal event.
     *
     * @throws InvalidArgumentException when the event is not SALE_RECEIPT,
     *                                  the payload is null, or sub-objects
     *                                  fail the typed-DTO constructor
     *                                  (should not happen for a verified row).
     */
    public function forSaleReceipt(FiscalEvent $event): SaleReceiptCanonicalView
    {
        if ($event->event_type !== FiscalEventType::SALE_RECEIPT) {
            throw new InvalidArgumentException(sprintf(
                'CanonicalPayloadReader::forSaleReceipt called with event_type=%s; expected SALE_RECEIPT',
                $event->event_type->value,
            ));
        }
        $payloadArray = $event->payload;
        if ($payloadArray === null) {
            throw new InvalidArgumentException(sprintf(
                'CanonicalPayloadReader::forSaleReceipt called on fiscal_event_id=%s with NULL payload (parse_failure quarantine?)',
                $event->id,
            ));
        }

        $payload = SaleReceiptPayload::fromArray($payloadArray);

        $seller = SellerDTO::fromArray($payload->seller);
        $buyer = $payload->buyer === null ? null : BuyerDTO::fromArray($payload->buyer);
        $originalReceiptReference = $payload->originalReceiptReference === null
            ? null
            : OriginalReceiptReferenceDTO::fromArray($payload->originalReceiptReference);

        $lineItems = [];
        foreach ($payload->lineItems as $row) {
            $lineItems[] = LineItemDTO::fromArray($row);
        }

        $payments = [];
        foreach ($payload->payments as $row) {
            $payments[] = PaymentDTO::fromArray($row);
        }

        $vatBreakdown = [];
        foreach ($payload->vatBreakdown as $row) {
            $vatBreakdown[] = VatBreakdownDTO::fromArray($row);
        }

        $vouchers = [];
        foreach ($payload->vouchersRedeemed as $row) {
            $vouchers[] = VoucherRedemptionDTO::fromArray($row);
        }

        // v4 (refund/void chain, spec §3.3): null on v1/v2/v3 payloads —
        // `originalLineReferences` is the DTO's v4 marker (see
        // SaleReceiptPayload::fromArray()'s array_key_exists discriminator).
        $originalLineReferences = null;
        if ($payload->originalLineReferences !== null) {
            $originalLineReferences = [];
            foreach ($payload->originalLineReferences as $row) {
                // @phpstan-ignore-next-line argument.type — validated as list<object> by FiscalPayloadConstraintValidator
                $originalLineReferences[] = OriginalLineReferenceDTO::fromArray($row);
            }
        }

        return new SaleReceiptCanonicalView(
            payload: $payload,
            seller: $seller,
            buyer: $buyer,
            lineItems: $lineItems,
            payments: $payments,
            vatBreakdown: $vatBreakdown,
            originalReceiptReference: $originalReceiptReference,
            vouchersRedeemed: $vouchers,
            originalLineReferences: $originalLineReferences,
        );
    }

    public function forAccountPayment(FiscalEvent $event): AccountPaymentView
    {
        if ($event->event_type !== FiscalEventType::ACCOUNT_PAYMENT) {
            throw new InvalidArgumentException(sprintf(
                'CanonicalPayloadReader::forAccountPayment called with event_type=%s; expected ACCOUNT_PAYMENT',
                $event->event_type->value,
            ));
        }
        $payloadArray = $event->payload;
        if ($payloadArray === null) {
            throw new InvalidArgumentException(sprintf(
                'CanonicalPayloadReader::forAccountPayment called on fiscal_event_id=%s with NULL payload (parse_failure quarantine?)',
                $event->id,
            ));
        }

        $payload = AccountPaymentPayload::fromArray($payloadArray);

        return new AccountPaymentView(
            payload: $payload,
            customer: AccountPaymentCustomerDTO::fromArray($payload->customer),
            payment: AccountPaymentPaymentDTO::fromArray($payload->payment),
            localBalanceSnapshot: AccountPaymentBalanceSnapshotDTO::fromArray($payload->localBalanceSnapshot),
            staleness: AccountPaymentStalenessDTO::fromArray($payload->staleness),
            seller: SellerDTO::fromArray($payload->seller),
        );
    }

    public function forDepositReceipt(FiscalEvent $event): DepositReceiptView
    {
        if ($event->event_type !== FiscalEventType::DEPOSIT_RECEIPT) {
            throw new InvalidArgumentException(sprintf(
                'CanonicalPayloadReader::forDepositReceipt called with event_type=%s; expected DEPOSIT_RECEIPT',
                $event->event_type->value,
            ));
        }
        $payloadArray = $event->payload;
        if ($payloadArray === null) {
            throw new InvalidArgumentException(sprintf(
                'CanonicalPayloadReader::forDepositReceipt called on fiscal_event_id=%s with NULL payload (parse_failure quarantine?)',
                $event->id,
            ));
        }

        $payload = DepositReceiptPayload::fromArray($payloadArray);

        return new DepositReceiptView(
            payload: $payload,
            customer: DepositReceiptCustomerDTO::fromArray($payload->customer),
            payment: DepositReceiptPaymentDTO::fromArray($payload->payment),
        );
    }

    public function forAccountCharge(FiscalEvent $event): AccountChargeView
    {
        if ($event->event_type !== FiscalEventType::ACCOUNT_CHARGE) {
            throw new InvalidArgumentException(sprintf(
                'CanonicalPayloadReader::forAccountCharge called with event_type=%s; expected ACCOUNT_CHARGE',
                $event->event_type->value,
            ));
        }
        $payloadArray = $event->payload;
        if ($payloadArray === null) {
            throw new InvalidArgumentException(sprintf(
                'CanonicalPayloadReader::forAccountCharge called on fiscal_event_id=%s with NULL payload (parse_failure quarantine?)',
                $event->id,
            ));
        }

        $payload = AccountChargePayload::fromArray($payloadArray);

        return new AccountChargeView(
            payload: $payload,
            customer: AccountChargeCustomerDTO::fromArray($payload->customer),
            localBalanceSnapshot: AccountChargeBalanceSnapshotDTO::fromArray($payload->localBalanceSnapshot),
            creditDecision: AccountChargeCreditDecisionDTO::fromArray($payload->creditDecision),
            chargeTerms: AccountChargeTermsDTO::fromArray($payload->chargeTerms),
            totals: AccountChargeTotalsDTO::fromArray($payload->totals),
            seller: SellerDTO::fromArray($payload->seller),
            buyer: $payload->buyer === null ? null : BuyerDTO::fromArray($payload->buyer),
            lineItems: $payload->lineItems,
            vatBreakdown: $payload->vatBreakdown,
        );
    }
}
