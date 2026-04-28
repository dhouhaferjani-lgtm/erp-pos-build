<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Services;

use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use App\Shared\Domain\CurrencyScale;

/**
 * Service for calculating and verifying Z report hash chains.
 *
 * Z reports are fiscally critical and must be hash chained for NF525 compliance.
 * Each Z report links to the previous Z report on the same terminal.
 */
final class ZReportHashService
{
    /**
     * Calculate fiscal hash for Z report
     *
     * Hash Input: previous_z_hash | z_number | terminal_id | generated_at | report_data
     *
     * Business Rules:
     * - First Z report: previousZHash is null (genesis)
     * - Subsequent Z reports: previousZHash is previous Z report's fiscal_hash
     * - Hash chain is per terminal
     * - Sequential z_number enforcement
     *
     * @param  ZReport  $zReport  The Z report to hash
     * @param  string|null  $previousZHash  Hash of previous Z report (null for first)
     * @return string SHA-256 hash (64 characters)
     */
    public function calculateHash(
        ZReport $zReport,
        ?string $previousZHash
    ): string {
        $data = $this->serializeForHashing($zReport);

        // Concatenate previous hash + current data
        $payload = ($previousZHash ?? 'GENESIS').'|'.$data;

        return hash('sha256', $payload);
    }

    /**
     * Serialize Z report data for hashing
     *
     * Format: z_number|terminal_id|generated_at|report_data_json
     *
     * @param  ZReport  $zReport  The Z report to serialize
     * @return string Serialized data string
     */
    private function serializeForHashing(ZReport $zReport): string
    {
        // Normalize monetary fields to scale 3 for schema_version >= 2 before hashing.
        // v1-shape payloads (no schema_version) pass through unchanged.
        $reportData = $this->normalizeForHash($zReport->report_data);

        // Convert report_data to deterministic JSON
        // Must match JavaScript's JSON.stringify() output — unescaped unicode and slashes
        $reportDataJson = json_encode($reportData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return sprintf(
            '%d|%s|%s|%s',
            $zReport->z_number,
            $zReport->terminal_id,
            $zReport->generated_at->toIso8601String(),
            $reportDataJson
        );
    }

    /**
     * Normalize all monetary fields in report_data to scale 3 for hash input.
     * Applies only when report_data.schema_version >= 2. v1-shape payloads pass through unchanged.
     *
     * Contract v1.1: all monetary normalizations use scale 3.
     *
     * @param  array<string, mixed>  $reportData
     * @return array<string, mixed>
     */
    public function normalizeForHash(array $reportData): array
    {
        $schemaVersion = (int) ($reportData['schema_version'] ?? 1);
        if ($schemaVersion < 2) {
            return $reportData;
        }

        $monetaryKeys = ['opening_cash', 'expected_cash', 'actual_cash', 'variance', 'gross_sales', 'net_sales', 'tax_amount'];
        foreach ($monetaryKeys as $key) {
            if (isset($reportData[$key]) && is_string($reportData[$key])) {
                $reportData[$key] = CurrencyScale::bcformat($reportData[$key], 3);
            }
        }

        if (isset($reportData['cash_counts']) && is_array($reportData['cash_counts'])) {
            $reportData['cash_counts'] = array_map(
                fn (array $row): array => array_merge($row, [
                    'expected_amount' => CurrencyScale::bcformat((string) $row['expected_amount'], 3),
                    'actual_amount' => CurrencyScale::bcformat((string) $row['actual_amount'], 3),
                    'variance_amount' => CurrencyScale::bcformat((string) $row['variance_amount'], 3),
                ]),
                $reportData['cash_counts'],
            );
        }

        if (isset($reportData['variance_summary']) && is_array($reportData['variance_summary'])
            && isset($reportData['variance_summary']['aggregate_amount'])) {
            $reportData['variance_summary']['aggregate_amount'] = CurrencyScale::bcformat(
                (string) $reportData['variance_summary']['aggregate_amount'], 3
            );
        }

        if (isset($reportData['tolerance_summary']) && is_array($reportData['tolerance_summary'])
            && isset($reportData['tolerance_summary']['totalAmount'])) {
            $reportData['tolerance_summary']['totalAmount'] = CurrencyScale::bcformat(
                (string) $reportData['tolerance_summary']['totalAmount'], 3
            );
        }

        if (isset($reportData['payment_methods']) && is_array($reportData['payment_methods'])) {
            $reportData['payment_methods'] = array_map(
                fn (array $row): array => isset($row['total_amount']) && is_string($row['total_amount'])
                    ? array_merge($row, ['total_amount' => CurrencyScale::bcformat($row['total_amount'], 3)])
                    : $row,
                $reportData['payment_methods'],
            );
        }

        return $reportData;
    }

    /**
     * Get previous Z report hash for a terminal
     *
     * Returns null if this is the first Z report for the terminal.
     *
     * @param  Terminal  $terminal  The terminal to get previous Z hash for
     * @return string|null Previous Z report fiscal_hash or null
     */
    public function getPreviousZHash(Terminal $terminal): ?string
    {
        $lastZReport = ZReport::where('terminal_id', $terminal->id)
            ->orderByDesc('z_number')
            ->first();

        return $lastZReport?->fiscal_hash;
    }

    /**
     * Get next Z number for a terminal
     *
     * Z numbers are sequential and never reset.
     *
     * @param  Terminal  $terminal  The terminal to get next Z number for
     * @return int Next Z number (1 for first Z report)
     */
    public function getNextZNumber(Terminal $terminal): int
    {
        $lastZReport = ZReport::where('terminal_id', $terminal->id)
            ->orderByDesc('z_number')
            ->first();

        return $lastZReport ? $lastZReport->z_number + 1 : 1;
    }

    /**
     * Verify Z report hash chain integrity for a terminal
     *
     * Recalculates all Z report hashes and verifies they match stored hashes.
     * Checks that previous_z_hash links form a valid chain.
     *
     * @param  Terminal  $terminal  The terminal to verify
     * @return bool True if chain is valid, false if broken
     */
    public function verifyZReportChain(Terminal $terminal): bool
    {
        $zReports = ZReport::where('terminal_id', $terminal->id)
            ->orderBy('z_number')
            ->get();

        if ($zReports->isEmpty()) {
            return true;  // No Z reports yet, chain is valid
        }

        $previousZHash = null;

        foreach ($zReports as $zReport) {
            // Check previous_z_hash linkage
            if ($zReport->previous_z_hash !== $previousZHash) {
                return false;  // Chain broken: previous_z_hash doesn't match
            }

            // Recalculate hash
            $expectedHash = $this->calculateHash($zReport, $previousZHash);

            if ($expectedHash !== $zReport->fiscal_hash) {
                return false;  // Chain broken: fiscal_hash mismatch
            }

            $previousZHash = $zReport->fiscal_hash;
        }

        return true;
    }

    /**
     * Verify a single Z report's hash
     *
     * @param  ZReport  $zReport  The Z report to verify
     * @return bool True if hash is valid
     */
    public function verifyZReportHash(ZReport $zReport): bool
    {
        $expectedHash = $this->calculateHash($zReport, $zReport->previous_z_hash);

        return $expectedHash === $zReport->fiscal_hash;
    }

    /**
     * Find chain break point
     *
     * Returns the Z report where the chain breaks, or null if chain is valid.
     *
     * @param  Terminal  $terminal  The terminal to check
     * @return ZReport|null The Z report where chain breaks, or null if valid
     */
    public function findChainBreak(Terminal $terminal): ?ZReport
    {
        $zReports = ZReport::where('terminal_id', $terminal->id)
            ->orderBy('z_number')
            ->get();

        if ($zReports->isEmpty()) {
            return null;
        }

        $previousZHash = null;

        foreach ($zReports as $zReport) {
            // Check previous_z_hash linkage
            if ($zReport->previous_z_hash !== $previousZHash) {
                return $zReport;  // Chain broken at this report
            }

            // Recalculate hash
            $expectedHash = $this->calculateHash($zReport, $previousZHash);

            if ($expectedHash !== $zReport->fiscal_hash) {
                return $zReport;  // Chain broken at this report
            }

            $previousZHash = $zReport->fiscal_hash;
        }

        return null;  // No break found
    }
}
