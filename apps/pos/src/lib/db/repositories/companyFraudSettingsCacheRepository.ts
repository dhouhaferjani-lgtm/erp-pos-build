import type Database from '@tauri-apps/plugin-sql';
import { queryAll, execute } from '@/lib/db';

export interface CompanyFraudSettingsCacheRow {
  company_id: string;
  cash_variance_over_soft: string;
  cash_variance_over_hard: string;
  cash_variance_under_soft: string;
  cash_variance_under_hard: string;
  require_blind_cash_count: boolean;
  require_manager_pin_above_hard: boolean;
  cash_variance_email_severity: 'none' | 'critical' | 'warning' | 'info';
  /**
   * Lane C M2 — how many refunds this terminal may author inside one shift
   * while it still holds unsynced fiscal events.
   */
  offline_refund_count_ceiling: number;
  /** Lane C M2 — the same bound as a cumulative payout value (canonical string). */
  offline_refund_value_ceiling: string;
  /**
   * Lane C M3 — a refund above this amount may only be authored while the
   * device is online, so the manager PIN is server-verified.
   */
  online_required_refund_threshold: string;
}

interface RawRow extends Omit<
  CompanyFraudSettingsCacheRow,
  'require_blind_cash_count' | 'require_manager_pin_above_hard'
> {
  require_blind_cash_count: number;
  require_manager_pin_above_hard: number;
}

export async function upsertCompanyFraudSettings(
  db: Database,
  row: CompanyFraudSettingsCacheRow,
): Promise<void> {
  await execute(
    db,
    `INSERT INTO company_fraud_settings_cache (
       company_id,
       cash_variance_over_soft, cash_variance_over_hard,
       cash_variance_under_soft, cash_variance_under_hard,
       require_blind_cash_count, require_manager_pin_above_hard,
       cash_variance_email_severity,
       offline_refund_count_ceiling, offline_refund_value_ceiling,
       online_required_refund_threshold, refreshed_at
     ) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, datetime('now'))
     ON CONFLICT(company_id) DO UPDATE SET
       cash_variance_over_soft        = excluded.cash_variance_over_soft,
       cash_variance_over_hard        = excluded.cash_variance_over_hard,
       cash_variance_under_soft       = excluded.cash_variance_under_soft,
       cash_variance_under_hard       = excluded.cash_variance_under_hard,
       require_blind_cash_count       = excluded.require_blind_cash_count,
       require_manager_pin_above_hard = excluded.require_manager_pin_above_hard,
       cash_variance_email_severity   = excluded.cash_variance_email_severity,
       offline_refund_count_ceiling   = excluded.offline_refund_count_ceiling,
       offline_refund_value_ceiling   = excluded.offline_refund_value_ceiling,
       online_required_refund_threshold = excluded.online_required_refund_threshold,
       refreshed_at                   = datetime('now')`,
    [
      row.company_id,
      row.cash_variance_over_soft,
      row.cash_variance_over_hard,
      row.cash_variance_under_soft,
      row.cash_variance_under_hard,
      row.require_blind_cash_count ? 1 : 0,
      row.require_manager_pin_above_hard ? 1 : 0,
      row.cash_variance_email_severity,
      row.offline_refund_count_ceiling,
      row.offline_refund_value_ceiling,
      row.online_required_refund_threshold,
    ],
  );
}

export async function getCompanyFraudSettings(
  db: Database,
  companyId: string,
): Promise<CompanyFraudSettingsCacheRow | null> {
  const rows = await queryAll<RawRow>(
    db,
    `SELECT company_id,
            cash_variance_over_soft, cash_variance_over_hard,
            cash_variance_under_soft, cash_variance_under_hard,
            require_blind_cash_count, require_manager_pin_above_hard,
            cash_variance_email_severity,
            offline_refund_count_ceiling, offline_refund_value_ceiling,
            online_required_refund_threshold
     FROM company_fraud_settings_cache
     WHERE company_id = $1`,
    [companyId],
  );
  if (rows.length === 0) return null;
  const r = rows[0]!;
  return {
    ...r,
    require_blind_cash_count: r.require_blind_cash_count === 1,
    require_manager_pin_above_hard: r.require_manager_pin_above_hard === 1,
  };
}
