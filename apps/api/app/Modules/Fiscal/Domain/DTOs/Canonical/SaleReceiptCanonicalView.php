<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs\Canonical;

use App\Modules\Fiscal\Domain\DTOs\SaleReceiptPayload;

/**
 * Typed canonical view over a parsed SALE_RECEIPT payload.
 *
 * Wraps the flat `SaleReceiptPayload` DTO and exposes typed
 * accessors over its nested sub-objects (seller / buyer /
 * line_items[] / payments[] / vat_breakdown[] / original_receipt_reference /
 * vouchers_redeemed[]).
 *
 * Built via `CanonicalPayloadReader::forSaleReceipt(FiscalEvent)` —
 * never instantiated directly by consumers. Returns are arrays of
 * typed DTOs (per Candidate C-v3 §3 / synthesis v5).
 *
 * Pass 2A.PHP.1 ships the type; consumers (PosCoreReceiptProjection,
 * Nf525DataProvider) migrate to it in Pass 2A.PHP.2. Pass 2A.PHP.1
 * exercises it via `FiscalPayloadConstraintValidatorTest` (F-15 large
 * receipt acceptance test).
 */
final readonly class SaleReceiptCanonicalView
{
    /**
     * @param  list<LineItemDTO>  $lineItems
     * @param  list<PaymentDTO>  $payments
     * @param  list<VatBreakdownDTO>  $vatBreakdown
     * @param  list<VoucherRedemptionDTO>  $vouchersRedeemed
     */
    public function __construct(
        public SaleReceiptPayload $payload,
        public SellerDTO $seller,
        public ?BuyerDTO $buyer,
        public array $lineItems,
        public array $payments,
        public array $vatBreakdown,
        public ?OriginalReceiptReferenceDTO $originalReceiptReference,
        public array $vouchersRedeemed,
    ) {}

    /** @return list<LineItemDTO> */
    public function lineItems(): array
    {
        return $this->lineItems;
    }

    /** @return list<PaymentDTO> */
    public function payments(): array
    {
        return $this->payments;
    }

    /** @return list<VatBreakdownDTO> */
    public function vatBreakdown(): array
    {
        return $this->vatBreakdown;
    }

    /** @return list<VoucherRedemptionDTO> */
    public function vouchersRedeemed(): array
    {
        return $this->vouchersRedeemed;
    }

    public function seller(): SellerDTO
    {
        return $this->seller;
    }

    public function buyer(): ?BuyerDTO
    {
        return $this->buyer;
    }

    public function originalReceiptReference(): ?OriginalReceiptReferenceDTO
    {
        return $this->originalReceiptReference;
    }

    /**
     * Signed cash-rounding adjustment, or canonical '0' on a v1/v2 payload.
     * Callers normalize to their own storage scale — this accessor is
     * deliberately scale-free.
     */
    public function cashRoundingAdjustmentOrZero(): string
    {
        return $this->payload->cashRoundingAdjustment ?? '0';
    }

    /** Applied rounding denomination, or canonical '0' on a v1/v2 payload. */
    public function cashRoundingDenominationOrZero(): string
    {
        return $this->payload->cashRoundingDenomination ?? '0';
    }
}
