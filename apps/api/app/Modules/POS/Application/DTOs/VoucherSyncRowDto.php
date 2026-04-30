<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use App\Modules\Voucher\Domain\Voucher;

/**
 * Outbound row shape for GET /api/v1/pos/vouchers/sync.
 *
 * Mirrors the Tauri client's `LocalVoucher` interface
 * (apps/pos/src/lib/offline/voucherRepository.ts:57-74). All enum-typed
 * columns are serialized as PascalCase case-names (e.g. `Issued`,
 * `PartiallyRedeemed`, `Bearer`, `Refund`), NOT the database backing
 * values (`issued`, `partially_redeemed`, `bearer`, `refund`).
 *
 * Monetary fields are kept as numeric strings at the server's internal
 * precision (currency_scale + 2) — the Tauri schema stores them as TEXT
 * to avoid IEEE 754 coercion of multi-decimal currencies.
 */
final readonly class VoucherSyncRowDto
{
    /**
     * @param  numeric-string  $initialBalance
     * @param  numeric-string  $currentBalance
     */
    public function __construct(
        public string $id,
        public string $code,
        public string $initialBalance,
        public string $currentBalance,
        public string $currency,
        public string $status,
        public string $redemptionMode,
        public string $voucherKind,
        public string $source,
        public string $issuedAt,
        public ?string $expiresAt,
        public ?string $partnerId,
        public ?string $issuedToPartnerId,
        public ?string $redeemableAtTerminalId,
        public ?string $notes,
        public string $syncedAt,
    ) {}

    public static function fromVoucher(Voucher $voucher, string $syncedAt): self
    {
        return new self(
            id: $voucher->id,
            code: $voucher->code,
            initialBalance: $voucher->initial_balance,
            currentBalance: $voucher->current_balance,
            currency: $voucher->currency,
            // ->name yields PascalCase identifiers (e.g. 'PartiallyRedeemed')
            status: $voucher->status->name,
            redemptionMode: $voucher->redemption_mode->name,
            voucherKind: $voucher->voucher_kind->name,
            source: $voucher->source->name,
            issuedAt: $voucher->issued_at->toIso8601String(),
            expiresAt: $voucher->expires_at?->toIso8601String(),
            partnerId: $voucher->partner_id,
            issuedToPartnerId: $voucher->issued_to_partner_id,
            redeemableAtTerminalId: $voucher->redeemable_at_terminal_id,
            notes: $voucher->notes,
            syncedAt: $syncedAt,
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'initial_balance' => $this->initialBalance,
            'current_balance' => $this->currentBalance,
            'currency' => $this->currency,
            'status' => $this->status,
            'redemption_mode' => $this->redemptionMode,
            'voucher_kind' => $this->voucherKind,
            'source' => $this->source,
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
            'partner_id' => $this->partnerId,
            'issued_to_partner_id' => $this->issuedToPartnerId,
            'redeemable_at_terminal_id' => $this->redeemableAtTerminalId,
            'notes' => $this->notes,
            'synced_at' => $this->syncedAt,
        ];
    }
}
