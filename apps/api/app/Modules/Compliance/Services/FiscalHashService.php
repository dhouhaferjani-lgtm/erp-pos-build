<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Services;

/**
 * Service for calculating and verifying fiscal hash chains.
 *
 * This service implements SHA-256 hash chains for compliance with fiscal
 * regulations (NF525, ZATCA, etc.). The hash chain ensures that:
 * - Documents cannot be modified after posting
 * - Documents cannot be deleted without detection
 * - Documents cannot be inserted into the middle of the chain
 *
 * Hash Format: SHA256(previous_hash + "|" + serialized_data)
 * - Genesis document: Uses company-specific seed for added entropy
 * - Chained documents: previous_hash is the hash of the preceding document
 *
 * Genesis Seed: Unlike ZATCA which uses a fixed SHA256("0") for genesis documents,
 * this implementation uses a unique 256-bit random seed per company. This adds
 * per-tenant entropy, making chain forgery significantly harder.
 */
final class FiscalHashService
{
    private const ALGORITHM = 'sha256';

    private const SEPARATOR = '|';

    /**
     * Calculate the hash for a document.
     *
     * @param  string  $input  The serialized document data
     * @param  string|null  $previousHash  The hash of the previous document (null for genesis)
     * @param  string|null  $genesisSeed  Company-specific seed for genesis document (used when previousHash is null)
     * @return string The calculated SHA-256 hash
     */
    public function calculateHash(string $input, ?string $previousHash, ?string $genesisSeed = null): string
    {
        // For genesis document: use company seed if provided, empty string otherwise
        // For chained documents: always use the previous hash
        $chainPrefix = $previousHash ?? $genesisSeed ?? '';

        $payload = $chainPrefix.self::SEPARATOR.$input;

        return hash(self::ALGORITHM, $payload);
    }

    /**
     * Serialize document data for hashing.
     *
     * The serialization format is: document_number|posted_at|total|currency
     *
     * @param  array{document_number: string, posted_at: string, total: string, currency: string}  $data
     */
    public function serializeForHashing(array $data): string
    {
        return implode(self::SEPARATOR, [
            $data['document_number'],
            $data['posted_at'],
            $data['total'],
            $data['currency'],
        ]);
    }

    /**
     * Verify a chain of documents.
     *
     * @param  array<int, array{input: string, hash: string, previous_hash: string|null}>  $documents
     * @param  string|null  $genesisSeed  Company-specific seed used for genesis document
     * @return bool True if the chain is valid
     */
    public function verifyChain(array $documents, ?string $genesisSeed = null): bool
    {
        $expectedPreviousHash = null;

        foreach ($documents as $document) {
            // Verify the previous_hash matches what we expect
            if ($document['previous_hash'] !== $expectedPreviousHash) {
                return false;
            }

            // Calculate what the hash should be
            // For genesis document, use the seed if provided
            $seedForCalculation = $expectedPreviousHash === null ? $genesisSeed : null;
            $calculatedHash = $this->calculateHash($document['input'], $document['previous_hash'], $seedForCalculation);

            // Verify the stored hash matches
            if ($calculatedHash !== $document['hash']) {
                return false;
            }

            // The current hash becomes the expected previous hash for the next document
            $expectedPreviousHash = $document['hash'];
        }

        return true;
    }

    /**
     * Verify a single document's hash.
     *
     * @param  string  $input  The serialized document data
     * @param  string|null  $previousHash  The previous document's hash
     * @param  string  $storedHash  The hash stored with the document
     * @return bool True if the hash is valid
     */
    public function verifyHash(string $input, ?string $previousHash, string $storedHash): bool
    {
        return $this->calculateHash($input, $previousHash) === $storedHash;
    }

    /**
     * Get the algorithm used for hashing.
     */
    public function getAlgorithm(): string
    {
        return self::ALGORITHM;
    }
}
