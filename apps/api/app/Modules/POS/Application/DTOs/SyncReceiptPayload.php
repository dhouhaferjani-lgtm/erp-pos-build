<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

/**
 * Data Transfer Object for a single offline receipt to sync.
 *
 * Represents the payload sent by the Tauri POS client for each
 * offline receipt that needs to be persisted server-side.
 */
final readonly class SyncReceiptPayload
{
    /**
     * @param  string  $idempotencyKey  Client-generated unique key for deduplication
     * @param  string  $receiptNumber  Receipt number generated offline
     * @param  string  $terminalId  Terminal UUID
     * @param  string  $operatorId  Cashier/operator user UUID
     * @param  array<int, array{product_id?: string, composite_item_id?: string, quantity: string, unit_price: string, modifiers?: array<int, array{modifier_id: string, modifier_group_id: string, price_adjustment: string}>, discount_amount?: string, discount_type?: string, discount_percent?: string, discount_reason?: string}>  $lines
     * @param  string  $subtotal  Net amount before tax
     * @param  string  $taxAmount  Total tax
     * @param  string  $discountAmount  Transaction-level discount
     * @param  string  $total  Gross total
     * @param  string  $currency  ISO currency code
     * @param  string  $offlineFiscalHash  Hash computed offline by the client
     * @param  string|null  $previousHash  Previous hash the client used for chain
     * @param  int  $hashSequence  Chain sequence number from client
     * @param  string|null  $transactionDiscountAmount  Optional transaction discount
     * @param  string|null  $transactionDiscountReason  Optional discount reason
     * @param  string|null  $tenderedAmount  Amount tendered by customer
     * @param  string|null  $changeDue  Change returned
     * @param  string  $paymentMethodId  Payment method UUID (legacy single-pay field)
     * @param  string  $paymentRepositoryId  Payment repository UUID (legacy single-pay field)
     * @param  string  $createdAt  ISO 8601 timestamp when receipt was created offline
     * @param  array<int, array{payment_method_id: string, repository_id: string, amount: string, card_last_four?: string|null, transaction_reference?: string|null, method_code?: string|null, instrument_type?: string|null, instrument_serial?: string|null}>  $payments  Per-payment entries (split-pay support).
     *                                                                                                                                                                                                                                                                       Codex review B3 (2026-04-30) added `method_code`, `instrument_type`, and `instrument_serial`
     *                                                                                                                                                                                                                                                                       so a voucher-bearing offline receipt round-trips through sync without the server
     *                                                                                                                                                                                                                                                                       recomputing a hash from null instrument fields. method_code is the snapshot the
     *                                                                                                                                                                                                                                                                       client hashed against; the server prefers it over a live PaymentMethod join.
     * @param  string|null  $consumptionMode  F&B consumption mode: SUR_PLACE or A_EMPORTER
     * @param  string|null  $tableId  F&B table UUID (dine-in only)
     * @param  int  $fiscalSchemaVersion  REQUIRED. Fiscal hash schema version this receipt
     *                                    was sealed under (2 = legacy, 3 = canonical-payload SHA-256). The server
     *                                    hard-rejects payloads whose declared version does not match the terminal's
     *                                    current `fiscal_schema_version` — there is no compatibility window.
     *                                    Codex review B1 (2026-04-30) removed the legacy default-to-2 fallback so a
     *                                    missing field surfaces as a 422 validation failure instead of silently
     *                                    downgrading a v3 payload.
     */
    public function __construct(
        public string $idempotencyKey,
        public string $receiptNumber,
        public string $terminalId,
        public string $operatorId,
        public array $lines,
        public string $subtotal,
        public string $taxAmount,
        public string $discountAmount,
        public string $total,
        public string $currency,
        public string $offlineFiscalHash,
        public ?string $previousHash,
        public int $hashSequence,
        public ?string $transactionDiscountAmount,
        public ?string $transactionDiscountReason,
        public ?string $tenderedAmount,
        public ?string $changeDue,
        public string $paymentMethodId,
        public string $paymentRepositoryId,
        public string $createdAt,
        /** @var array<int, array{payment_method_id: string, repository_id: string, amount: string, card_last_four?: string|null, transaction_reference?: string|null, method_code?: string|null, instrument_type?: string|null, instrument_serial?: string|null}> */
        public array $payments,
        public ?string $consumptionMode,
        public ?string $tableId,
        public int $fiscalSchemaVersion,
    ) {}

    /**
     * Create from a validated request array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<int, array<string, mixed>> $rawPayments */
        $rawPayments = $data['payments'] ?? [];
        // Codex review B3 (2026-04-30): preserve method_code, instrument_type, and
        // instrument_serial from the wire payload. The POS client computes the
        // offline v3 fiscal hash with these fields populated; if the server drops
        // them here, the recomputed hash will not match and the receipt is
        // rejected as a chain break. Defensive defaults to null so a stale
        // pre-B3 client (cash-only) still parses without exception.
        $payments = array_map(static fn (array $p): array => [
            'payment_method_id' => (string) $p['payment_method_id'],
            'repository_id' => (string) $p['repository_id'],
            'amount' => (string) $p['amount'],
            'card_last_four' => isset($p['card_last_four']) ? (string) $p['card_last_four'] : null,
            'transaction_reference' => isset($p['transaction_reference']) ? (string) $p['transaction_reference'] : null,
            'method_code' => isset($p['method_code']) ? (string) $p['method_code'] : null,
            'instrument_type' => isset($p['instrument_type']) ? (string) $p['instrument_type'] : null,
            'instrument_serial' => isset($p['instrument_serial']) ? (string) $p['instrument_serial'] : null,
        ], $rawPayments);

        // Codex review B1 (2026-04-30): `fiscal_schema_version` is REQUIRED.
        // The request validator (`SyncReceiptsRequest`) is the primary gate — if
        // a payload reaches `fromArray()` without this field, an upstream
        // path bypassed validation and we should fail fast rather than default
        // to v2 and silently downgrade a v3 receipt.
        if (! isset($data['fiscal_schema_version'])) {
            throw new \InvalidArgumentException(
                'SyncReceiptPayload.fromArray: `fiscal_schema_version` is required (expected 2 or 3).'
            );
        }
        $fiscalSchemaVersion = (int) $data['fiscal_schema_version'];
        if ($fiscalSchemaVersion !== 2 && $fiscalSchemaVersion !== 3) {
            throw new \InvalidArgumentException(
                "SyncReceiptPayload.fromArray: `fiscal_schema_version` must be 2 or 3, got {$fiscalSchemaVersion}."
            );
        }

        return new self(
            idempotencyKey: (string) $data['idempotency_key'],
            receiptNumber: (string) $data['receipt_number'],
            terminalId: (string) $data['terminal_id'],
            operatorId: (string) $data['operator_id'],
            lines: (array) $data['lines'],
            subtotal: (string) $data['subtotal'],
            taxAmount: (string) $data['tax_amount'],
            discountAmount: (string) $data['discount_amount'],
            total: (string) $data['total'],
            currency: (string) $data['currency'],
            offlineFiscalHash: (string) $data['offline_fiscal_hash'],
            previousHash: isset($data['previous_hash']) ? (string) $data['previous_hash'] : null,
            hashSequence: (int) $data['hash_sequence'],
            transactionDiscountAmount: isset($data['transaction_discount_amount']) ? (string) $data['transaction_discount_amount'] : null,
            transactionDiscountReason: isset($data['transaction_discount_reason']) ? (string) $data['transaction_discount_reason'] : null,
            tenderedAmount: isset($data['tendered_amount']) ? (string) $data['tendered_amount'] : null,
            changeDue: isset($data['change_due']) ? (string) $data['change_due'] : null,
            paymentMethodId: (string) $data['payment_method_id'],
            paymentRepositoryId: (string) $data['payment_repository_id'],
            createdAt: (string) $data['created_at'],
            payments: $payments,
            consumptionMode: isset($data['consumption_mode']) ? (string) $data['consumption_mode'] : null,
            tableId: isset($data['table_id']) ? (string) $data['table_id'] : null,
            fiscalSchemaVersion: $fiscalSchemaVersion,
        );
    }
}
