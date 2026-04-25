import type Database from '@tauri-apps/plugin-sql';
import { queryAll, execute } from '@/lib/db';

export interface ZReportCountRow {
  id: string;
  z_report_id: string;
  payment_method_id: string;
  currency_code: string;
  expected_amount: string;
  actual_amount: string;
  variance_amount: string;
  variance_direction: 'over' | 'under' | 'balanced';
  transaction_count: number;
}

export async function insertZReportCounts(
  db: Database,
  rows: ZReportCountRow[],
): Promise<void> {
  for (const row of rows) {
    await execute(
      db,
      `INSERT INTO z_report_counts (
         id, z_report_id, payment_method_id, currency_code,
         expected_amount, actual_amount, variance_amount,
         variance_direction, transaction_count
       ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)`,
      [
        row.id,
        row.z_report_id,
        row.payment_method_id,
        row.currency_code,
        row.expected_amount,
        row.actual_amount,
        row.variance_amount,
        row.variance_direction,
        row.transaction_count,
      ],
    );
  }
}

export async function getZReportCountsByZReportId(
  db: Database,
  zReportId: string,
): Promise<ZReportCountRow[]> {
  return queryAll<ZReportCountRow>(
    db,
    `SELECT id, z_report_id, payment_method_id, currency_code,
            expected_amount, actual_amount, variance_amount,
            variance_direction, transaction_count
     FROM z_report_counts
     WHERE z_report_id = $1
     ORDER BY currency_code ASC, payment_method_id ASC`,
    [zReportId],
  );
}
