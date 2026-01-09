<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Services;

use App\Modules\Compliance\Services\FiscalHashService;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;

/**
 * Service for calculating and verifying POS receipt hash chains (NF525 compliance).
 *
 * This service implements SHA-256 hash chains for POS receipts, ensuring:
 * - Receipts cannot be modified after creation
 * - Receipts cannot be deleted without detection
 * - Receipts cannot be inserted into the middle of the chain
 * - Each terminal maintains an independent hash chain
 *
 * Hash Input Format: receipt_number|timestamp|total|currency|vat_hash|payment_hash
 * - Genesis receipt: Uses terminal-specific 256-bit random seed
 * - Chained receipts: previous_hash is the hash of the preceding receipt
 *
 * Compliance: NF525 (France), extensible for other jurisdictions
 */
final class ReceiptHashService
{
    private const ALGORITHM = 'sha256';

    private const SEPARATOR = '|';

    public function __construct(
        private readonly FiscalHashService $fiscalHashService
    ) {}

    /**
     * Calculate fiscal hash for a POS receipt
     *
     * @param  Receipt  $receipt  The receipt to hash
     * @param  string|null  $previousHash  Previous receipt hash from terminal
     * @return string SHA-256 hash (64 characters hex)
     */
    public function calculateHash(Receipt $receipt, ?string $previousHash): string
    {
        $input = $this->serializeForHashing($receipt);

        return $this->fiscalHashService->calculateHash(
            input: $input,
            previousHash: $previousHash,
            genesisSeed: $receipt->terminal->genesis_seed
        );
    }

    /**
     * Serialize receipt data for hashing (NF525 compliant)
     *
     * Format: receipt_number|posted_at|total|currency|vat_hash|payment_hash
     *
     * @param  Receipt  $receipt  The receipt to serialize
     * @return string Pipe-separated serialized data
     */
    public function serializeForHashing(Receipt $receipt): string
    {
        return implode(self::SEPARATOR, [
            $receipt->receipt_number,
            $receipt->posted_at->toIso8601String(),
            $receipt->total,
            $receipt->currency,
            $receipt->vat_breakdown_hash,
            $receipt->payment_methods_hash,
        ]);
    }

    /**
     * Calculate hash for VAT breakdown (for vat_breakdown_hash column)
     *
     * Hashes the VAT details to ensure tax calculation integrity.
     *
     * @param  array<array{tax_rate: float|string, net_amount: float|string, vat_amount: float|string, gross_amount: float|string}>  $vatDetails
     * @return string SHA-256 hash of VAT breakdown
     */
    public function hashVATBreakdown(array $vatDetails): string
    {
        // Sort by tax rate for consistent hashing
        usort($vatDetails, fn ($a, $b) => bccomp((string) $a['tax_rate'], (string) $b['tax_rate'], 2));

        $breakdown = collect($vatDetails)
            ->map(fn ($detail) => implode(':', [
                $detail['tax_rate'],
                $detail['net_amount'],
                $detail['vat_amount'],
                $detail['gross_amount'],
            ]))
            ->join(';');

        return hash(self::ALGORITHM, $breakdown);
    }

    /**
     * Calculate hash for payment methods (for payment_methods_hash column)
     *
     * Hashes the payment breakdown to ensure payment integrity.
     *
     * @param  array<array{payment_type: string, amount: float|string}>  $payments
     * @return string SHA-256 hash of payment methods
     */
    public function hashPaymentMethods(array $payments): string
    {
        // Sort by payment type for consistent hashing
        usort($payments, fn ($a, $b) => strcmp($a['payment_type'], $b['payment_type']));

        $paymentString = collect($payments)
            ->map(fn ($payment) => implode(':', [
                $payment['payment_type'],
                $payment['amount'],
            ]))
            ->join(';');

        return hash(self::ALGORITHM, $paymentString);
    }

    /**
     * Verify hash chain integrity for a terminal
     *
     * Validates that all receipts in the terminal's chain are correctly hashed
     * and linked to each other.
     *
     * @param  Terminal  $terminal  The terminal to verify
     * @return bool True if chain is valid, false if broken
     */
    public function verifyTerminalChain(Terminal $terminal): bool
    {
        $receipts = Receipt::where('terminal_id', $terminal->id)
            ->where('is_voided', false)
            ->orderBy('chain_sequence')
            ->get();

        if ($receipts->isEmpty()) {
            return true; // Empty chain is valid
        }

        $previousHash = null;

        foreach ($receipts as $receipt) {
            // Verify previous_hash matches expected
            if ($receipt->previous_hash !== $previousHash) {
                return false;
            }

            // Calculate what the hash should be
            $expectedHash = $this->calculateHash($receipt, $previousHash);

            // Verify stored hash matches calculation
            if ($expectedHash !== $receipt->fiscal_hash) {
                return false;
            }

            // Current hash becomes previous for next receipt
            $previousHash = $receipt->fiscal_hash;
        }

        // Verify terminal's last_hash matches the final receipt
        if ($previousHash !== $terminal->last_hash) {
            return false;
        }

        return true;
    }

    /**
     * Verify a single receipt's hash
     *
     * @param  Receipt  $receipt  The receipt to verify
     * @return bool True if hash is valid
     */
    public function verifyHash(Receipt $receipt): bool
    {
        $input = $this->serializeForHashing($receipt);

        return $this->fiscalHashService->verifyHash(
            input: $input,
            previousHash: $receipt->previous_hash,
            storedHash: $receipt->fiscal_hash
        );
    }

    /**
     * Verify VAT breakdown hash
     *
     * @param  Receipt  $receipt  The receipt with VAT details
     * @return bool True if VAT hash matches stored value
     */
    public function verifyVATBreakdownHash(Receipt $receipt): bool
    {
        $vatDetails = $receipt->vatDetails()
            ->get()
            ->map(fn ($detail) => [
                'tax_rate' => $detail->tax_rate,
                'net_amount' => $detail->net_amount,
                'vat_amount' => $detail->vat_amount,
                'gross_amount' => $detail->gross_amount,
            ])
            ->toArray();

        $calculatedHash = $this->hashVATBreakdown($vatDetails);

        return $calculatedHash === $receipt->vat_breakdown_hash;
    }

    /**
     * Verify payment methods hash
     *
     * @param  Receipt  $receipt  The receipt with payment details
     * @return bool True if payment hash matches stored value
     */
    public function verifyPaymentMethodsHash(Receipt $receipt): bool
    {
        $payments = $receipt->payments()
            ->get()
            ->map(fn ($payment) => [
                'payment_type' => $payment->payment_type,
                'amount' => $payment->amount,
            ])
            ->toArray();

        $calculatedHash = $this->hashPaymentMethods($payments);

        return $calculatedHash === $receipt->payment_methods_hash;
    }

    /**
     * Get the algorithm used for hashing
     */
    public function getAlgorithm(): string
    {
        return self::ALGORITHM;
    }
}
