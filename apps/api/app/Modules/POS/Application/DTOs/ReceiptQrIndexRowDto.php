<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

/**
 * Outbound row shape for GET /api/v1/pos/receipts/qr-index.
 *
 * Mirrors the Tauri client's `LocalReceiptQrIndexEntry` interface
 * (apps/pos/src/lib/offline/voucherRepository.ts:103-112). Used by the
 * scan dispatcher (Task 50) to resolve scanned QR tokens to receipts
 * without a network round-trip.
 *
 * `qrToken` is nullable — when no active receipt_qr signing key exists
 * for the tenant, the dispatcher falls through to receipt_number lookup.
 */
final readonly class ReceiptQrIndexRowDto
{
    /**
     * @param  numeric-string  $total
     */
    public function __construct(
        public string $receiptUuid,
        public ?string $qrToken,
        public string $receiptNumber,
        public string $terminalId,
        public string $postedAt,
        public string $total,
        public string $currency,
        public string $syncedAt,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'receipt_uuid' => $this->receiptUuid,
            'qr_token' => $this->qrToken,
            'receipt_number' => $this->receiptNumber,
            'terminal_id' => $this->terminalId,
            'posted_at' => $this->postedAt,
            'total' => $this->total,
            'currency' => $this->currency,
            'synced_at' => $this->syncedAt,
        ];
    }
}
