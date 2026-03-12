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
     * @param  string  $previousHash  Previous hash the client used for chain
     * @param  int  $hashSequence  Chain sequence number from client
     * @param  string|null  $transactionDiscountAmount  Optional transaction discount
     * @param  string|null  $transactionDiscountReason  Optional discount reason
     * @param  string|null  $tenderedAmount  Amount tendered by customer
     * @param  string|null  $changeDue  Change returned
     * @param  string  $paymentMethodId  Payment method UUID
     * @param  string  $paymentRepositoryId  Payment repository UUID
     * @param  string  $createdAt  ISO 8601 timestamp when receipt was created offline
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
    ) {}

    /**
     * Create from a validated request array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
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
        );
    }
}
