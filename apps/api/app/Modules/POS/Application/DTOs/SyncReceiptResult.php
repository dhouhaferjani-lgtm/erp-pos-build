<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use App\Modules\POS\Domain\Enums\SyncStatus;

/**
 * Result for a single receipt in a batch sync operation.
 */
final readonly class SyncReceiptResult
{
    public function __construct(
        public string $idempotencyKey,
        public SyncStatus $status,
        public ?string $receiptId,
        public ?string $serverFiscalHash,
        public ?string $error,
    ) {}

    /**
     * @return array{idempotency_key: string, status: string, receipt_id: string|null, server_fiscal_hash: string|null, error: string|null}
     */
    public function toArray(): array
    {
        return [
            'idempotency_key' => $this->idempotencyKey,
            'status' => $this->status->value,
            'receipt_id' => $this->receiptId,
            'server_fiscal_hash' => $this->serverFiscalHash,
            'error' => $this->error,
        ];
    }

    public static function synced(string $idempotencyKey, string $receiptId, string $fiscalHash): self
    {
        return new self(
            idempotencyKey: $idempotencyKey,
            status: SyncStatus::Synced,
            receiptId: $receiptId,
            serverFiscalHash: $fiscalHash,
            error: null,
        );
    }

    public static function duplicate(string $idempotencyKey, string $receiptId, string $fiscalHash): self
    {
        return new self(
            idempotencyKey: $idempotencyKey,
            status: SyncStatus::Duplicate,
            receiptId: $receiptId,
            serverFiscalHash: $fiscalHash,
            error: null,
        );
    }

    public static function failed(string $idempotencyKey, string $error): self
    {
        return new self(
            idempotencyKey: $idempotencyKey,
            status: SyncStatus::Failed,
            receiptId: null,
            serverFiscalHash: null,
            error: $error,
        );
    }

    public static function chainBroken(string $idempotencyKey): self
    {
        return new self(
            idempotencyKey: $idempotencyKey,
            status: SyncStatus::ChainBroken,
            receiptId: null,
            serverFiscalHash: null,
            error: 'Chain broken by earlier receipt failure',
        );
    }
}
