<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Shared\Contracts\CurrencyScaleResolverInterface;

/**
 * Service for calculating and verifying fiscal hash chains for journal entries.
 *
 * This service implements SHA-256 hash chains for GL entries to ensure:
 * - Journal entries cannot be modified after posting
 * - Journal entries cannot be deleted without detection
 * - Journal entries cannot be inserted into the middle of the chain
 *
 * Hash Format: SHA256(previous_hash + "|" + serialized_data)
 */
final class GeneralLedgerHashService
{
    private const ALGORITHM = 'sha256';

    private const SEPARATOR = '|';

    public function __construct(
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    private function scale(): int
    {
        return $this->scaleResolver->getScale();
    }

    private function currencyCodeForCompany(string $companyId): string
    {
        $currency = Company::query()->whereKey($companyId)->value('currency');
        if (! is_string($currency) || $currency === '') {
            throw new \RuntimeException("Cannot resolve currency for company {$companyId}.");
        }

        return $currency;
    }

    /**
     * Calculate hash for a journal entry
     *
     * @param  JournalEntry  $entry  Entry to hash (must have lines loaded)
     * @param  string|null  $previousHash  Hash of previous entry (null for genesis)
     * @return string SHA-256 hash (64 characters)
     */
    public function calculateHash(JournalEntry $entry, ?string $previousHash, ?string $currencyCode = null): string
    {
        $serialized = $this->serializeForHashing($entry, $currencyCode);
        $chainPrefix = $previousHash ?? '';
        $payload = $chainPrefix.self::SEPARATOR.$serialized;

        return hash(self::ALGORITHM, $payload);
    }

    /**
     * Serialize journal entry data for hashing
     *
     * Format: entry_number|entry_date|company_id|total_debit|total_credit
     *
     * @param  JournalEntry  $entry  Entry to serialize
     * @return string Serialized data
     */
    public function serializeForHashing(JournalEntry $entry, ?string $currencyCode = null): string
    {
        // Calculate totals from lines using bcmath for precision
        $totalDebit = '0';
        $totalCredit = '0';
        $scale = $currencyCode !== null
            ? $this->scaleResolver->getScale($currencyCode)
            : $this->scale();

        foreach ($entry->lines as $line) {
            $totalDebit = bcadd($totalDebit, $line->debit, $scale);
            $totalCredit = bcadd($totalCredit, $line->credit, $scale);
        }

        return implode(self::SEPARATOR, [
            $entry->entry_number,
            $entry->entry_date->format('Y-m-d'),
            $entry->company_id,
            $totalDebit,
            $totalCredit,
        ]);
    }

    /**
     * Verify hash chain for a company
     *
     * @param  string  $companyId  Company UUID
     * @return bool True if chain is valid
     */
    public function verifyChain(string $companyId): bool
    {
        $currencyCode = $this->currencyCodeForCompany($companyId);

        $entries = JournalEntry::where('company_id', $companyId)
            ->whereNotNull('fiscal_hash')
            ->with('lines')
            ->orderBy('chain_sequence')
            ->get();

        if ($entries->isEmpty()) {
            return true; // Empty chain is valid
        }

        $expectedPreviousHash = null;
        $expectedSequence = 1;

        foreach ($entries as $entry) {
            // Verify sequence is continuous
            if ($entry->chain_sequence !== $expectedSequence) {
                return false; // Gap or duplicate sequence
            }

            // Verify previous_hash matches
            if ($entry->previous_hash !== $expectedPreviousHash) {
                return false; // Previous hash mismatch
            }

            // Recalculate and verify hash
            $calculatedHash = $this->calculateHash($entry, $entry->previous_hash, $currencyCode);
            if ($calculatedHash !== $entry->fiscal_hash) {
                return false; // Hash tampered
            }

            // Update expectations for next iteration
            $expectedPreviousHash = $entry->fiscal_hash;
            $expectedSequence++;
        }

        return true;
    }

    /**
     * Get the last hash in a company's chain
     *
     * @param  string  $companyId  Company UUID
     * @return string|null Hash of entry with highest chain_sequence, or null
     */
    public function getLastChainHash(string $companyId): ?string
    {
        return JournalEntry::where('company_id', $companyId)
            ->whereNotNull('fiscal_hash')
            ->orderBy('chain_sequence', 'desc')
            ->value('fiscal_hash');
    }
}
