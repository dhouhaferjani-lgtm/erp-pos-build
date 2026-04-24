<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use App\Modules\POS\Domain\Enums\SyncStatus;

/**
 * Result for a single receipt in a batch sync operation.
 *
 * Carries the terminal's authoritative hash state AFTER the sync so the
 * offline POS client can reconcile its local `terminal_state` row
 * without an additional /pos/terminals pull (BG4).
 */
final readonly class SyncReceiptResult
{
    public function __construct(
        public string $idempotencyKey,
        public SyncStatus $status,
        public ?string $receiptId,
        public ?string $serverFiscalHash,
        public ?string $error,
        public ?string $terminalLastHash = null,
        public ?int $terminalHashSequence = null,
    ) {}

    /**
     * @return array{
     *   idempotency_key: string,
     *   status: string,
     *   receipt_id: string|null,
     *   server_fiscal_hash: string|null,
     *   error: string|null,
     *   terminal_last_hash: string|null,
     *   terminal_hash_sequence: int|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'idempotency_key' => $this->idempotencyKey,
            'status' => $this->status->value,
            'receipt_id' => $this->receiptId,
            'server_fiscal_hash' => $this->serverFiscalHash,
            'error' => $this->error,
            'terminal_last_hash' => $this->terminalLastHash,
            'terminal_hash_sequence' => $this->terminalHashSequence,
        ];
    }

    public static function synced(
        string $idempotencyKey,
        string $receiptId,
        string $fiscalHash,
        ?string $terminalLastHash = null,
        ?int $terminalHashSequence = null,
    ): self {
        return new self(
            idempotencyKey: $idempotencyKey,
            status: SyncStatus::Synced,
            receiptId: $receiptId,
            serverFiscalHash: $fiscalHash,
            error: null,
            terminalLastHash: $terminalLastHash,
            terminalHashSequence: $terminalHashSequence,
        );
    }

    public static function duplicate(
        string $idempotencyKey,
        string $receiptId,
        string $fiscalHash,
        ?string $terminalLastHash = null,
        ?int $terminalHashSequence = null,
    ): self {
        return new self(
            idempotencyKey: $idempotencyKey,
            status: SyncStatus::Duplicate,
            receiptId: $receiptId,
            serverFiscalHash: $fiscalHash,
            error: null,
            terminalLastHash: $terminalLastHash,
            terminalHashSequence: $terminalHashSequence,
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
