/**
 * Client-side Z-report hash service — port of ZReportHashService.php
 *
 * Hash format must match server exactly for chain verification during sync.
 * Server format: previous_z_hash | z_number | terminal_id | generated_at | report_data_json
 */

import { sha256 } from '@/lib/fiscal/hashService';
import { bcformat } from '@/lib/decimal';
import type { ZReportData } from '@/lib/offline/types';

export interface ZReportHashInput {
  previousHash: string;
  zNumber: number;
  terminalId: string;
  generatedAt: string;
  reportData: ZReportData;
}

/**
 * Normalize all monetary fields in report_data to scale 3 for hash input.
 *
 * Port of ZReportHashService::normalizeForHash() in PHP.
 * Contract v1.1: all monetary normalizations use scale 3.
 *
 * Applies only when report_data.schema_version >= 2.
 * v1-shape payloads (no schema_version) pass through unchanged.
 *
 * The returned object is a deep clone — the input is never mutated.
 */
export function normalizeForHash(reportData: Record<string, unknown>): Record<string, unknown> {
  const schemaVersion = typeof reportData['schema_version'] === 'number'
    ? reportData['schema_version']
    : typeof reportData['schema_version'] === 'string'
      ? parseInt(reportData['schema_version'], 10)
      : 1;

  if (schemaVersion < 2) {
    return { ...reportData };
  }

  // Deep clone via JSON round-trip to avoid mutating the input object.
  // The clone is then mutated in place for the normalized fields.
  const result = JSON.parse(JSON.stringify(reportData)) as Record<string, unknown>;

  const monetaryKeys: string[] = [
    'opening_cash',
    'expected_cash',
    'actual_cash',
    'variance',
    'gross_sales',
    'net_sales',
    'tax_amount',
  ];

  for (const key of monetaryKeys) {
    if (typeof result[key] === 'string') {
      result[key] = bcformat(result[key] as string, 3);
    }
  }

  if (Array.isArray(result['cash_counts'])) {
    result['cash_counts'] = (result['cash_counts'] as Array<Record<string, unknown>>).map(
      (row) => ({
        ...row,
        expected_amount: bcformat(String(row['expected_amount']), 3),
        actual_amount: bcformat(String(row['actual_amount']), 3),
        variance_amount: bcformat(String(row['variance_amount']), 3),
      }),
    );
  }

  const varianceSummary = result['variance_summary'];
  if (
    varianceSummary !== null &&
    typeof varianceSummary === 'object' &&
    !Array.isArray(varianceSummary)
  ) {
    const vs = varianceSummary as Record<string, unknown>;
    if (typeof vs['aggregate_amount'] === 'string') {
      vs['aggregate_amount'] = bcformat(vs['aggregate_amount'], 3);
    }
  }

  const toleranceSummary = result['tolerance_summary'];
  if (
    toleranceSummary !== null &&
    typeof toleranceSummary === 'object' &&
    !Array.isArray(toleranceSummary)
  ) {
    const ts = toleranceSummary as Record<string, unknown>;
    if (typeof ts['totalAmount'] === 'string') {
      ts['totalAmount'] = bcformat(ts['totalAmount'], 3);
    }
  }

  // Additive (spec §4.3): mirrored EXACTLY by the `cash_rounding_summary` block
  // in ZReportHashService.php::normalizeForHash(). Per-key isset-style
  // normalization keeps a legacy report_data (no such key) byte-identical, so
  // v2-shape Zs re-hash unchanged forever — pinned by
  // zReportHashService.legacyStability.test.ts. Deliberately NOT tied to a
  // schema_version bump: bumping would re-normalize refunds_amount through the
  // server's schema≥3 key list and break parity.
  const cashRoundingSummary = result['cash_rounding_summary'];
  if (
    cashRoundingSummary !== null &&
    typeof cashRoundingSummary === 'object' &&
    !Array.isArray(cashRoundingSummary)
  ) {
    const crs = cashRoundingSummary as Record<string, unknown>;
    if (typeof crs['total_adjustment'] === 'string') {
      crs['total_adjustment'] = bcformat(crs['total_adjustment'], 3);
    }
  }

  if (Array.isArray(result['payment_methods'])) {
    result['payment_methods'] = (result['payment_methods'] as Array<Record<string, unknown>>).map(
      (row) =>
        typeof row['total_amount'] === 'string'
          ? { ...row, total_amount: bcformat(row['total_amount'], 3) }
          : row,
    );
  }

  return result;
}

/**
 * Compute fiscal hash for a Z-report.
 *
 * Mirrors ZReportHashService::calculateHash() on the server:
 * - Normalizes report_data monetary fields to scale 3 (schema_version >= 2 only)
 * - Serializes: z_number | terminal_id | generated_at | JSON(normalized_report_data)
 * - Chains: SHA256(previous_hash | serialized_data)
 * - First Z-report uses "GENESIS" as previous hash
 */
export async function computeZReportHash(input: ZReportHashInput): Promise<string> {
  const normalized = normalizeForHash(input.reportData as unknown as Record<string, unknown>);
  const reportDataJson = JSON.stringify(normalized);

  const serialized = [
    String(input.zNumber),
    input.terminalId,
    input.generatedAt,
    reportDataJson,
  ].join('|');

  const payload = `${input.previousHash}|${serialized}`;

  return sha256(payload);
}
