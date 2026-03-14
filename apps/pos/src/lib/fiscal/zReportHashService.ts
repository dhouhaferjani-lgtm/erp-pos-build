/**
 * Client-side Z-report hash service — port of ZReportHashService.php
 *
 * Hash format must match server exactly for chain verification during sync.
 * Server format: previous_z_hash | z_number | terminal_id | generated_at | report_data_json
 */

import { sha256 } from '@/lib/fiscal/hashService';
import type { ZReportData } from '@/lib/offline/types';

export interface ZReportHashInput {
  previousHash: string;
  zNumber: number;
  terminalId: string;
  generatedAt: string;
  reportData: ZReportData;
}

/**
 * Compute fiscal hash for a Z-report.
 *
 * Mirrors ZReportHashService::calculateHash() on the server:
 * - Serializes: z_number | terminal_id | generated_at | JSON(report_data)
 * - Chains: SHA256(previous_hash | serialized_data)
 * - First Z-report uses "GENESIS" as previous hash
 */
export async function computeZReportHash(input: ZReportHashInput): Promise<string> {
  const reportDataJson = JSON.stringify(input.reportData);

  const serialized = [
    String(input.zNumber),
    input.terminalId,
    input.generatedAt,
    reportDataJson,
  ].join('|');

  const payload = `${input.previousHash}|${serialized}`;

  return sha256(payload);
}
