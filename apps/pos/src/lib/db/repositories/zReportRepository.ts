import type Database from '@tauri-apps/plugin-sql';
import { queryAll, queryOne, execute } from '@/lib/db';
import type { LocalZReport } from '@/lib/offline/types';

/** Raw SQLite row shape (JSON fields are strings; monetary fields are TEXT since v21) */
interface ZReportRow {
  id: string;
  terminal_id: string;
  shift_id: string;
  z_number: number;
  formatted_z_number: string;
  generated_at: string;
  fiscal_hash: string;
  previous_hash: string;
  hash_sequence: number;
  report_data: string;
  opening_cash: string;
  expected_cash: string;
  receipt_snapshots: string;
  grand_totals: string;
  synced: number;
  synced_at: string | null;
}

function rowToLocalZReport(row: ZReportRow): LocalZReport {
  return {
    id: row.id,
    terminal_id: row.terminal_id,
    shift_id: row.shift_id,
    z_number: row.z_number,
    formatted_z_number: row.formatted_z_number,
    generated_at: row.generated_at,
    fiscal_hash: row.fiscal_hash,
    previous_hash: row.previous_hash,
    hash_sequence: row.hash_sequence,
    report_data: JSON.parse(row.report_data),
    opening_cash: row.opening_cash,
    expected_cash: row.expected_cash,
    receipt_snapshots: JSON.parse(row.receipt_snapshots),
    grand_totals: JSON.parse(row.grand_totals),
    synced: row.synced === 1,
    synced_at: row.synced_at,
  };
}

export async function insertZReport(
  db: Database,
  report: LocalZReport,
): Promise<void> {
  await execute(
    db,
    `INSERT INTO z_reports (
      id, terminal_id, shift_id, z_number, formatted_z_number, generated_at,
      fiscal_hash, previous_hash, hash_sequence,
      report_data, opening_cash, expected_cash,
      receipt_snapshots, grand_totals, synced
    ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15)`,
    [
      report.id,
      report.terminal_id,
      report.shift_id,
      report.z_number,
      report.formatted_z_number,
      report.generated_at,
      report.fiscal_hash,
      report.previous_hash,
      report.hash_sequence,
      JSON.stringify(report.report_data),
      report.opening_cash,
      report.expected_cash,
      JSON.stringify(report.receipt_snapshots),
      JSON.stringify(report.grand_totals),
      report.synced ? 1 : 0,
    ]
  );
}

export async function getUnsyncedZReports(db: Database): Promise<LocalZReport[]> {
  const rows = await queryAll<ZReportRow>(
    db,
    'SELECT * FROM z_reports WHERE synced = 0 ORDER BY z_number ASC'
  );
  return rows.map(rowToLocalZReport);
}

export async function markZReportSynced(
  db: Database,
  id: string,
  syncedAt: string,
): Promise<void> {
  await execute(
    db,
    'UPDATE z_reports SET synced = 1, synced_at = $1 WHERE id = $2',
    [syncedAt, id]
  );
}

export async function getZReportByShift(
  db: Database,
  shiftId: string,
): Promise<LocalZReport | null> {
  const row = await queryOne<ZReportRow>(
    db,
    'SELECT * FROM z_reports WHERE shift_id = $1',
    [shiftId]
  );
  return row ? rowToLocalZReport(row) : null;
}

export async function getLatestZReport(
  db: Database,
  terminalId: string,
): Promise<LocalZReport | null> {
  const row = await queryOne<ZReportRow>(
    db,
    'SELECT * FROM z_reports WHERE terminal_id = $1 ORDER BY z_number DESC LIMIT 1',
    [terminalId]
  );
  return row ? rowToLocalZReport(row) : null;
}
