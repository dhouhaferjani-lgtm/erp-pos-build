<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use App\Modules\Voucher\Domain\VoucherLedger;

/**
 * Outbound row shape for GET /api/v1/pos/voucher-ledger/sync.
 *
 * Mirrors the Tauri client's `LocalVoucherLedgerEntry` interface
 * (apps/pos/src/lib/offline/voucherRepository.ts:82-95). Server-pulled
 * rows are always reported as `sync_status = 'synced'` from the local
 * mirror's perspective, with `sync_error = null` and `synced_at` set
 * to `occurred_at` so the row is treated as already round-tripped.
 *
 * The `event` field is serialized as the PascalCase enum case-name
 * (e.g. `Redeemed`, `PartiallyRedeemed`), NOT the snake_case backing
 * value (`redeemed`, `partially_redeemed`).
 */
final readonly class VoucherLedgerSyncRowDto
{
    /**
     * @param  numeric-string  $amount
     */
    public function __construct(
        public string $id,
        public string $voucherId,
        public string $event,
        public string $amount,
        public string $currency,
        public ?string $receiptId,
        public ?string $terminalId,
        public string $userId,
        public string $occurredAt,
        public string $syncStatus,
        public ?string $syncError,
        public ?string $syncedAt,
    ) {}

    public static function fromLedger(VoucherLedger $ledger): self
    {
        $occurredAt = $ledger->occurred_at->toIso8601String();

        return new self(
            id: $ledger->id,
            voucherId: $ledger->voucher_id,
            // ->name yields PascalCase identifiers (e.g. 'PartiallyRedeemed')
            event: $ledger->event->name,
            amount: $ledger->amount,
            currency: $ledger->currency,
            receiptId: $ledger->receipt_id,
            terminalId: $ledger->terminal_id,
            userId: $ledger->user_id,
            occurredAt: $occurredAt,
            syncStatus: 'synced',
            syncError: null,
            syncedAt: $occurredAt,
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'voucher_id' => $this->voucherId,
            'event' => $this->event,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'receipt_id' => $this->receiptId,
            'terminal_id' => $this->terminalId,
            'user_id' => $this->userId,
            'occurred_at' => $this->occurredAt,
            'sync_status' => $this->syncStatus,
            'sync_error' => $this->syncError,
            'synced_at' => $this->syncedAt,
        ];
    }
}
